<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\PlatformSmsSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Support\JamaicanNumber;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Which provider the platform texts through, and the one place that decides it.
 *
 * WHY THIS EXISTS — M10 §1. There is no SMS gateway on this platform. The change
 * set wants both Heads of Security notified "at the same time" by e-mail AND
 * text, and keeps the household's nominated emergency contacts on SMS; none of
 * that can happen until somebody buys an account with a provider.
 *
 * The code path is already built. `AppServiceProvider` binds `SmsGateway` from
 * `config('services.sms.driver')` with one match arm per provider, `PanicSms`
 * already counts the site's numbers and queues a job, and `alert_sms_dispatches`
 * already records every attempt. All of it is waiting on credentials. So the
 * credentials go in a table the Director can edit, and connecting Twilio or a
 * local Jamaican provider becomes a form rather than a deploy — which is exactly
 * what the mail settings already do, and this is deliberately their shape.
 *
 * THE DATABASE OVERRIDES `.env`, AND ONLY WHEN IT HAS SOMETHING TO SAY. `apply()`
 * runs on every request and on every resolution of the gateway; with no row
 * saved it does nothing, so a server configured the old way keeps working. That
 * ordering matters for recovery: if somebody saves a provider that cannot be
 * reached, the fix is to correct the row — and if the row itself is the problem,
 * deleting it falls back to `.env`.
 *
 * `canSend()` IS THE ONE QUESTION EVERY SCREEN SHOULD ASK. A settings row that
 * merely exists proves nothing: the `log` driver writes a message down and
 * delivers it to nobody. Until this returns true, every surface that offers SMS
 * must keep saying so — which is the audit M10 §1 asks for, and the reason the
 * estate's notification settings now disable their SMS column with a reason
 * rather than accepting a switch that does nothing. It answers for the settings
 * IN FORCE — the saved row, or `.env` when there is none — because that is what
 * the gateway binding will actually use.
 */
final class SmsSettings
{
    /**
     * A Twilio account SID: `AC` and thirty-two hexadecimal characters.
     *
     * Checked on save because the two identifiers people paste by mistake — an API
     * key (`SK…`) or a messaging service (`MG…`) — are the same length, look just
     * as official, and fail the test send with a 401 that names neither.
     */
    private const TWILIO_ACCOUNT_SID = '/^AC[0-9a-fA-F]{32}$/';

    public function __construct(private readonly AuditLogger $audit) {}

    /** The saved settings, or null when nothing has been configured. */
    public function current(): ?PlatformSmsSetting
    {
        return PlatformSmsSetting::query()->orderBy('id')->first();
    }

    /**
     * Can the platform actually send a text right now?
     *
     * Never throws. It is called from screens and from the panic path, and a
     * missing table during a migration must not take either with it.
     */
    public function canSend(): bool
    {
        try {
            return $this->inForce()->canSend();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Put the saved provider in front of `.env` for this request.
     *
     * Called from `AppServiceProvider::boot()`, and again each time the gateway is
     * resolved so a long-running queue worker texts through what is saved NOW. It
     * must never throw: this runs before the router, so an exception here is a
     * white screen on every page.
     */
    public function apply(): void
    {
        try {
            $settings = $this->current();
        } catch (Throwable) {
            return;
        }

        if ($settings === null) {
            return;
        }

        config([
            'services.sms.driver' => $settings->driver,
            'services.sms.account_sid' => $settings->account_sid,
            'services.sms.token' => $settings->token,
            'services.sms.from' => $settings->from,
        ]);
    }

    /**
     * What the settings screen draws. NEVER the token.
     *
     * @return array<string, mixed>
     */
    public function shape(): array
    {
        $saved = $this->current();
        $settings = $saved ?? $this->fromConfig();

        $driver = (string) ($settings->driver ?: PlatformSmsSetting::LOG);
        $canSend = $settings->canSend();

        return [
            'configured' => $saved !== null,
            'driver' => $driver,

            /*
             * A driver name `.env` holds that the platform has no class for is shown
             * as what it is. The binding sends it to the log driver, and a select box
             * silently showing the first option would say something different.
             */
            'driver_label' => PlatformSmsSetting::DRIVERS[$driver]
                ?? '"'.$driver.'" — not a provider this platform can send through, so messages are written to the log and delivered to nobody',

            'account_sid' => $settings->account_sid,
            'from' => $settings->from,

            /*
             * Whether a token is set, never the token. The same rule the mail
             * settings follow: a secret the platform must present to a provider
             * is encrypted at rest and does not leave the server, so the screen
             * is told only that there is one.
             */
            'has_token' => (string) ($settings->token ?? '') !== '',

            'can_send' => $canSend,
            'missing' => $settings->missing(),
            'verified_at' => $saved?->verified_at?->toIso8601String(),
            'updated_by' => $saved?->editor?->name,
            'updated_at' => $saved?->updated_at?->toIso8601String(),
            'source' => $saved === null
                ? 'Server configuration file. Saving here takes over.'
                : 'Saved here, overriding the server configuration file.',

            /*
             * The sentence every screen shows while this is unconfigured. Said
             * once here so the console, the estate's notification settings and
             * the panic screens cannot word it three ways.
             */
            'note' => $canSend
                ? null
                : 'No SMS provider is connected, so the platform cannot send a text message. '
                    .'Alerts that would go by SMS go by email only, and nothing anywhere claims otherwise.',
        ];
    }

    /**
     * Save the provider, and record WHICH fields moved — never their values.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(array $input, ?User $by = null): PlatformSmsSetting
    {
        $driver = trim((string) ($input['driver'] ?? PlatformSmsSetting::LOG));

        if ($driver === '') {
            throw new DomainException('Choose a provider, or leave it on "None" while there is none.');
        }

        /*
         * A NAME WITH NO GATEWAY BEHIND IT IS REFUSED, not saved. The binding would
         * send it to the log driver, and the row would then say one thing while
         * the platform did another. See `PlatformSmsSetting::DRIVERS`.
         */
        if (! array_key_exists($driver, PlatformSmsSetting::DRIVERS)) {
            throw new DomainException(sprintf(
                '"%s" is not a provider this platform can send through. Choose one of: %s.',
                $driver,
                implode(', ', array_map(static fn (string $label): string => explode(' — ', $label)[0], PlatformSmsSetting::DRIVERS)),
            ));
        }

        $settings = $this->current() ?? new PlatformSmsSetting;

        $before = ['driver' => $settings->driver];
        $changed = [];

        if ((string) $settings->driver !== $driver) {
            $changed[] = 'driver';
            $settings->driver = $driver;
        }

        // Only a field that was sent is touched, the same rule the mail settings keep.
        foreach (['account_sid', 'from'] as $field) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = $this->blankToNull($input[$field]);

            if ((string) $settings->getAttribute($field) !== (string) $value) {
                $changed[] = $field;
                $settings->setAttribute($field, $value);
            }
        }

        /*
         * A BLANK TOKEN LEAVES THE SAVED ONE ALONE. The field is write-only —
         * the screen never receives the token, so it cannot send it back — and
         * treating an empty box as "clear it" would wipe the credential every
         * time somebody changed the sender ID.
         */
        $token = trim((string) ($input['token'] ?? ''));

        if ($token !== '') {
            $changed[] = 'token';
            $settings->token = $token;
        }

        if ($driver === PlatformSmsSetting::TWILIO) {
            $this->refuseIncompleteTwilio($settings);
        }

        // Changed settings are unproven settings, until somebody sends a test through them.
        if ($changed !== []) {
            $settings->verified_at = null;
        }

        $settings->updated_by = $by?->getKey() ?? Auth::id();
        $settings->save();

        /*
         * AUDITED BY FIELD NAME ONLY. Which fields changed, never their values —
         * the rule `MailSettings` keeps, and for its reason: `before` and `after`
         * are stored as JSON the audit screen renders, and an entry carrying an
         * API token would put the secret in the one table the platform is least
         * willing to edit. The DRIVER is the exception, because it is not a
         * credential and "who switched the platform from None to Twilio, and
         * when" is exactly the question this entry exists to answer.
         */
        if ($changed !== []) {
            $this->audit->record(
                action: 'platform.sms_settings_changed',
                entityType: 'platform_sms_setting',
                entityId: (string) $settings->id,
                before: $before,
                after: ['driver' => $settings->driver, 'fields' => $changed],
            );
        }

        return $settings;
    }

    /**
     * Send one message to prove the settings work.
     *
     * IT REFUSES ON THE `log` DRIVER rather than reporting success. A test that
     * "passed" because the message was written to a log file would be the most
     * misleading button on the platform: somebody would read it as proof that
     * texts are being delivered.
     *
     * IT USES THE SAVED SETTINGS, not what is in the form. That is the only
     * thing a pass can honestly vouch for, and the screen says so.
     *
     * @return string what to tell the operator
     */
    public function sendTest(string $to, ?User $by = null): string
    {
        $settings = $this->current();

        if ($settings === null || ! $settings->canSend()) {
            throw new DomainException(
                'No SMS provider is connected yet. Choose a provider, enter its credentials and a sender '
                .'number, then send a test — while this is on "log" a message is written down and delivered '
                .'to nobody, and a test that passed would prove nothing.'
            );
        }

        /*
         * The number is read the way `JamaicanNumber` reads every number on this
         * platform: ten digits gain the +1 a Jamaican phone would add, and nothing
         * else is guessed. A number it cannot read is refused before anything is
         * sent, rather than handed to the provider to refuse at a cost.
         */
        $number = JamaicanNumber::e164($to);

        if ($number === null) {
            throw new DomainException(
                'That is not a number the platform can dial. Write a Jamaican number as 876 555 0147, '
                .'or any other with its + and country code.'
            );
        }

        $this->apply();

        $provider = $this->label((string) $settings->driver);

        /*
         * The gateway ANSWERS rather than throwing for an ordinary refusal, and
         * only `SENT` is a delivery. `RECORDED` means the message was written
         * down and delivered to nobody, which is what the log driver does — and
         * treating that as a pass would make this the most misleading button on
         * the platform. It is refused above, and refused again here in case the
         * driver changed under us between the two.
         *
         * A genuine fault is caught and reported as its class name, never its
         * message: an exception can quote the request, and the request carries
         * the number.
         */
        try {
            $result = app(SmsGateway::class)->send(
                $number,
                'GeminiSecure test message. If you received this, the platform can send alerts by SMS.',
            );
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            $result = SmsResult::failed((string) $settings->driver, class_basename($e));
        }

        if (! $result->delivered()) {
            /*
             * A 401 PROVES THE SAVED CREDENTIALS WRONG, so a "last proved" date from
             * before would now be a claim the platform knows to be false. Any other
             * refusal — a bad test number, a trial account — says nothing about the
             * credentials, and a date earned earlier stands.
             */
            if (str_starts_with((string) $result->error, 'http_401')) {
                $settings->forceFill(['verified_at' => null])->save();
            }

            $this->audit->record(
                action: 'platform.sms_test_refused',
                entityType: 'platform_sms_setting',
                entityId: (string) $settings->id,
                after: [
                    'driver' => $result->driver,
                    'status' => $result->status,
                    'error' => $result->error,
                    'to' => JamaicanNumber::mask($number),
                ],
            );

            throw new DomainException($this->explain($result, $provider));
        }

        $settings->forceFill(['verified_at' => Carbon::now()])->save();

        $this->audit->record(
            action: 'platform.sms_test_sent',
            entityType: 'platform_sms_setting',
            entityId: (string) $settings->id,
            after: [
                'driver' => $result->driver,
                'reference' => $result->reference,
                'to' => JamaicanNumber::mask($number),
            ],
        );

        return sprintf(
            '%s accepted the test message for %s%s. If it does not arrive within a minute, %s has it and the '
            .'platform does not — its message log will say why.',
            $provider,
            JamaicanNumber::mask($number),
            $result->reference === null ? '' : ' (reference '.$result->reference.')',
            $provider,
        );
    }

    /**
     * A provider's refusal, in words an operator can act on — and its own code
     * beside them, so the words can be checked against the provider's docs.
     *
     * Translated for the reason `MailSettings::explain()` gives: "http_401:20003"
     * means "the token is wrong" to somebody who has read Twilio's error table,
     * and to nobody else.
     */
    private function explain(SmsResult $result, string $provider): string
    {
        $error = (string) $result->error;
        [$http, $code] = array_pad(explode(':', $error, 2), 2, null);
        $status = str_starts_with((string) $http, 'http_') ? substr((string) $http, 5) : null;

        $reason = match (true) {
            $result->status === SmsResult::RECORDED => 'The message was written down and delivered to nobody, which is what "None" does.',
            $error === 'unreachable' => $provider.' did not answer within a few seconds. Check that this server can reach api.twilio.com, then try again.',
            $error === 'not_configured' => 'The saved settings are missing the account SID, the auth token or the sender.',
            $code === '20003', $status === '401' => $provider.' refused the account SID or the auth token. Copy both again from the front page of the Twilio console — the auth token, not an API key.',
            $code === '20404', $status === '404' => $provider.' has no account with that SID. Check it against the Twilio console; it starts with AC.',
            $code === '21211', $code === '21614' => $provider.' says the number the test was sent to is not a mobile number it can text.',
            in_array($code, ['21212', '21606', '21659', '21660'], true) => $provider.' will not send from that sender. It must be a number bought on this account, or a sender ID registered on it.',
            $code === '21608' => 'This is a trial account, which can only text numbers verified on it. Verify this number in the Twilio console, or upgrade the account.',
            $code === '21408' => $provider.' is not permitted to text that country on this account. Turn Jamaica on under Messaging, Geo permissions, in the Twilio console.',
            $code === '21610' => 'That number has replied STOP to this sender, so '.$provider.' will not text it.',
            $code === '20429', $status === '429' => $provider.' is limiting how fast this account can send. Wait a minute and try again.',
            default => $provider.' did not accept the message.',
        };

        $detail = match (true) {
            $code !== null && $status !== null => sprintf('%s error %s, HTTP %s', $provider, $code, $status),
            $status !== null => 'HTTP '.$status,
            default => $result->status.($error === '' ? '' : ', '.$error),
        };

        return $reason.' Nothing was delivered, so the settings are left unverified. ('.$detail.')';
    }

    /**
     * Twilio cannot send without all three, so it is not saved without them.
     *
     * The token may be the one already saved — that is what write-only means — but
     * a first save to Twilio has none, and a Twilio row with no token is a row the
     * screen would have to spend its whole width explaining.
     */
    private function refuseIncompleteTwilio(PlatformSmsSetting $settings): void
    {
        $sid = (string) $settings->account_sid;

        if ($sid === '') {
            throw new DomainException('Twilio needs the account SID. It is on the front page of the Twilio console and starts with AC.');
        }

        if (preg_match(self::TWILIO_ACCOUNT_SID, $sid) !== 1) {
            throw new DomainException(
                'That is not a Twilio account SID. An account SID starts with AC and is 34 characters long — '
                .'an API key starts with SK and a messaging service with MG, and neither works here.'
            );
        }

        if ((string) $settings->token === '') {
            throw new DomainException('Twilio needs the auth token, from the front page of the Twilio console.');
        }

        if ((string) $settings->from === '') {
            throw new DomainException('Twilio needs a sender: a number bought on the account, such as +1 876 555 0100, or a registered sender ID.');
        }
    }

    /**
     * The settings `.env` describes, as an unsaved row, for when nothing is saved.
     *
     * Never persisted. It exists so `canSend()` and `shape()` answer for what the
     * gateway binding will actually use, rather than reading "not connected" on a
     * server that was configured the old way and texts perfectly well.
     */
    private function fromConfig(): PlatformSmsSetting
    {
        return (new PlatformSmsSetting)->forceFill([
            'driver' => (string) config('services.sms.driver'),
            'account_sid' => $this->blankToNull(config('services.sms.account_sid')),
            'token' => $this->blankToNull(config('services.sms.token')),
            'from' => $this->blankToNull(config('services.sms.from')),
        ]);
    }

    /** The saved row, or `.env` when there is none. */
    private function inForce(): PlatformSmsSetting
    {
        return $this->current() ?? $this->fromConfig();
    }

    /** The provider's name as the screen says it: "Twilio", never "twilio". */
    private function label(string $driver): string
    {
        return explode(' — ', PlatformSmsSetting::DRIVERS[$driver] ?? $driver)[0];
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
