<?php

namespace App\Providers;

use App\Listeners\ReapplyCentralGrantsAfterMigration;
use App\Live\RecordObserver;
use App\Mail\ResilientSmtpTransport;
use App\Models\Guard;
use App\Models\PersonalAccessToken;
use App\Models\Shift;
use App\Models\User;
use App\Services\Account\AccountSessions;
use App\Services\Platform\MailFailures;
use App\Services\Platform\MailLog;
use App\Services\Platform\MailSettings;
use App\Services\Platform\SmsSettings;
use App\Services\ResidentApp\LogSmsGateway;
use App\Services\ResidentApp\ScreenedSmsGateway;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\TwilioSmsGateway;
use App\Support\MailReadiness;
use App\Support\Performance\QueryMeter;
use App\Support\Seeded\MarkedSeedCommand;
use App\Support\Seeded\Retirements;
use App\Support\Seeded\SeededRecipients;
use App\Support\Seeded\Seeding;
use App\Support\SessionWindow;
use App\Support\SiteTime;
use App\Support\UtcMySqlConnection;
use Carbon\CarbonInterface;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Connection;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * THE ONE PLACE A TEXT-MESSAGE PROVIDER IS CHOSEN (13 D3, Q-M09).
         *
         * One setting — `services.sms.driver` — and one `match` arm per provider, each of
         * which is a key of `PlatformSmsSetting::DRIVERS`. The setting comes from the
         * console's SMS screen when one has been saved there (`SmsSettings::apply()`), and
         * from `SMS_DRIVER` in `.env` when nothing has. `log` resolves to `LogSmsGateway`,
         * which writes the message down, delivers it to nobody, and SAYS SO in its answer.
         *
         * A DEFAULT THAT RECORDS, NEVER ONE THAT THROWS. An unknown driver name falls here
         * too: a typo in an environment file must not be able to take the panic path down,
         * and a message written down is strictly better than one nobody can account for.
         * That is why `PlatformSmsSetting::canSend()` refuses a name it has no arm for —
         * the screen must not read "connected" over a driver that lands here.
         * `ResidentSignIn::smsAvailable()` separately refuses the log driver in production
         * for SIGN-IN CODES, because a code in a log file is a credential and an emergency
         * message is not.
         *
         * THE SAVED PROVIDER IS RE-READ ON EVERY RESOLUTION, not only at boot. A queue
         * worker boots once and then runs for days, and the panic texts are sent from one:
         * reading the settings only in `boot()` would leave every worker texting through
         * whatever was configured when it started — so connecting Twilio in the console
         * would change nothing on the panic path until somebody restarted the queue, which
         * is precisely the deploy this screen exists to avoid. One query per resolution,
         * and `apply()` swallows its own failures.
         *
         * AND EVERY DRIVER IS SCREENED (M35 §5): a number only a person a seeder invented
         * holds is never handed to the provider. Here, where the driver is made, so no
         * caller and no future provider can be without it.
         */
        $this->app->bind(SmsGateway::class, static function (): SmsGateway {
            app(SmsSettings::class)->apply();

            $driver = match ((string) config('services.sms.driver')) {
                TwilioSmsGateway::DRIVER => new TwilioSmsGateway(
                    (string) config('services.sms.account_sid'),
                    (string) config('services.sms.token'),
                    (string) config('services.sms.from'),
                ),
                default => new LogSmsGateway,
            };

            return new ScreenedSmsGateway($driver, $driver instanceof TwilioSmsGateway ? TwilioSmsGateway::DRIVER : LogSmsGateway::DRIVER);
        });

        // One time rule (M4 §3.1): a moment bound into any MySQL query — platform or estate — is bound in UTC.
        Connection::resolverFor('mysql', static fn ($pdo, string $database, string $prefix, array $config): UtcMySqlConnection => new UtcMySqlConnection($pdo, $database, $prefix, $config));

        // The request's query count (M11 A2). One per application, so the middleware and the
        // listener below are looking at the same tally.
        $this->app->singleton(QueryMeter::class);

        // `db:seed` marks every row its seeders write (M33 §5.2): Laravel's command with the mark, in its place.
        $this->app->extend(SeedCommand::class, static fn (SeedCommand $command, $app): SeedCommand => new MarkedSeedCommand($app['db']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // M11 §2 (D-175): every save of a record a screen shows tells those screens — one observer, many models.
        foreach (RecordObserver::MODELS as $model) {
            $model::observe(RecordObserver::class);
        }

        // M19A §7: a Gemini reader's "my time" lasts for their request, never into the next thing this process does.
        $this->app->terminating(static fn () => SiteTime::viewAs(null));

        /*
         * The saved relay goes in FRONT of `.env`, and before the check below reads it — a
         * platform whose mail is configured in the console is not an undeliverable one, and
         * checking first would refuse to boot over a setting that has been superseded.
         *
         * `apply()` does nothing when no relay has been saved, so a server configured the old
         * way is unaffected, and it swallows its own errors: this runs before the router, so an
         * exception here is a white screen on every page rather than a broken mail send.
         */
        app(MailSettings::class)->apply();

        /*
         * EVERY SMTP SEND IS TRIED AGAIN BEFORE IT GIVES UP, AND A GIVE-UP IS RECORDED (M12 §5.1). The `smtp` transport
         * itself, so no caller can forget it: a sign-in code, an invitation, a reset, a panic's email. Whatever relay
         * the settings above name is the one it uses — the mailer's configuration is read when the transport is built.
         */
        Mail::extend('smtp', static fn (array $config): ResilientSmtpTransport => new ResilientSmtpTransport(
            $config,
            static function (...$failure): void {
                app(MailFailures::class)->record(...$failure);
            },
        ));

        // The same for the text-message provider (M10 §1), and for the same reasons: nothing
        // saved means `.env` stands, and a failure here must never take a page down with it.
        app(SmsSettings::class)->apply();

        $this->refuseUndeliverableMailInProduction();

        // Handset tokens live in the platform database, whatever estate is open (13 D1).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->closeIdleSessions();

        /*
         * The central database re-grants itself after a migration, the way every
         * estate already did through `DatabaseMigrated`. Until this existed,
         * `gs_platform` was the one database whose grants depended on somebody
         * remembering `grants:append-only` — and nothing caught them forgetting,
         * because the suite connects as the schema owner. See the listener.
         */
        Event::listen(MigrationsEnded::class, ReapplyCentralGrantsAfterMigration::class);

        // Every email the mail server accepted, on the Gemini console's "Recent emails" (M18G §7).
        Event::listen(MessageSent::class, static fn (MessageSent $event) => MailLog::sent($event));

        // …and none to a person a seeder invented (M35 §5): every email, on its way to the mail server, with no switch.
        Event::listen(MessageSending::class, static fn (MessageSending $event): ?bool => SeededRecipients::screenMail($event));

        /*
         * A CONSOLE SIGN-IN STARTS A SESSION MY ACCOUNT CAN LIST AND SIGN OUT (M32 F9). The web guard only: the apps sign
         * in with tokens, not sessions. Written into the session, not the database: the session's id is regenerated
         * straight after a sign-in, so its row is written on the next request (`KeepAccountSession`).
         */
        Event::listen(Login::class, static function (Login $event): void {
            $request = request();

            if ($event->guard === 'web' && $event->user instanceof User && $request->hasSession()) {
                app(AccountSessions::class)->signedIn($request->session(), $event->user);
            }
        });

        $this->countQueriesPerRequest();

        $this->markAndScopeSampleData();
    }

    /**
     * THE COMMANDS THAT KEEP THE DATABASE, RATHER THAN SHOW IT, SEE EVERY ROW (M33 §5.4, KI-25).
     *
     * Migrations, grants and the gates, through the console's own start and finish — which is how the server runs them.
     * (The seeding commands mark their rows, and the retirement and the purge see every row, on the commands themselves:
     * `SeedsMarkedRows`, `SeesEveryRow`. Laravel sends these events only outside the unit tests.) A queued job starts
     * clean whatever a command before it left behind, since a command that throws never sends its finish — not on the
     * `sync` queue, whose jobs run inside the command that dispatched them.
     */
    private function markAndScopeSampleData(): void
    {
        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if (Retirements::blindCommand((string) $event->command)) {
                Retirements::beginBlind();
            }
        });

        Event::listen(CommandFinished::class, static function (CommandFinished $event): void {
            if (Retirements::blindCommand((string) $event->command)) {
                Retirements::endBlind();
            }
        });

        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                Seeding::reset();
                Retirements::resetBlind();
            }
        });
    }

    /**
     * EVERY STATEMENT, ON EVERY CONNECTION, COUNTED AGAINST THE REQUEST THAT RAN IT (M11 A2).
     *
     * `QueryExecuted` is dispatched by every connection through the one event dispatcher —
     * the platform's, each estate's as tenancy opens it, and the schema owner's — so one
     * listener sees them all. The meter ignores anything outside a request bracket, and
     * `EnforceQueryBudget` is what opens and closes the bracket and compares the count with
     * `config/perf.php`.
     *
     * REGISTERED ONLY IN LOCAL AND TESTING. Production never pays for the listener, and the
     * middleware separately refuses to count there whatever the configuration says.
     */
    private function countQueriesPerRequest(): void
    {
        if (! $this->app->environment(['local', 'testing'])) {
            return;
        }

        $meter = $this->app->make(QueryMeter::class);

        Event::listen(QueryExecuted::class, static fn (QueryExecuted $query) => $meter->record($query));
    }

    /**
     * A HANDSET NOBODY HAS TOUCHED FOR THIRTY MINUTES IS SIGNED OUT (M6, Q-M42).
     *
     * The client's ruling, verbatim: "Automatically logs him out within 30 minutes."
     * Built as a SLIDING idle window — every authenticated request renews it — because an
     * absolute thirty minutes would sign a guard out twenty-four times in one watch. The
     * reasoning, and the hazard that remains, are in `App\Support\SessionWindow`.
     *
     * WHY THE HOOK AND NOT `config('sanctum.expiration')`. Sanctum's own setting measures
     * from `created_at` and is therefore absolute; there is no sliding option. This hook is
     * Sanctum's documented seam for exactly this, it runs before `last_used_at` is written
     * (so it reads the PREVIOUS request's stamp, which is what the window is about), and it
     * leaves every other check Sanctum makes untouched.
     *
     * `Sanctum::actingAs()` does not come through here — it sets the guard's user directly —
     * so the hundreds of tests that act as a guard or a resident are unaffected. Tests that
     * present a REAL bearer token do come through here, which is the point.
     */
    private function closeIdleSessions(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            static fn ($accessToken, bool $isValid): bool => SessionWindow::stillOpen(
                $accessToken->last_used_at ?? null,
                $accessToken->created_at ?? null,
                $isValid,
                // A guard on shift stays signed in for the shift (M22 §1.3); asked only once the idle window has closed.
                ($accessToken->tokenable_type ?? null) === Guard::class
                    ? static fn (): ?CarbonInterface => Shift::onDutyUntil((int) $accessToken->tokenable_id)
                    : null,
            ),
        );
    }

    /**
     * Production does not boot on a mailer that delivers nowhere (13 B7).
     *
     * Checked once, at boot, from the configured values — which turns "nobody
     * got their invitation" from a month-long mystery into an error on the
     * first request after a bad deploy. See `MailReadiness`.
     */
    private function refuseUndeliverableMailInProduction(): void
    {
        $mailer = config('mail.default');

        $problem = MailReadiness::problem(
            (string) $this->app->environment(),
            is_string($mailer) ? $mailer : null,
            is_string($host = config('mail.mailers.smtp.host')) ? $host : null,
            is_string($from = config('mail.from.address')) ? $from : null,
            (bool) config('mail.undeliverable_acknowledged', false),
        );

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
    }
}
