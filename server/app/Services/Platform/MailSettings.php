<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\PlatformMailSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Seeded\SeededRecipients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Where the platform sends mail from, and the one place that decides it.
 *
 * THE DATABASE OVERRIDES `.env`, AND ONLY WHEN IT HAS SOMETHING TO SAY. `apply()` runs on every
 * request through `AppServiceProvider`; with no row saved it does nothing at all, so a server
 * configured the old way keeps working exactly as before. That ordering matters for recovery:
 * if somebody saves a relay that cannot be reached, the fix is to correct the row — and if the
 * row itself is the problem, deleting it falls back to `.env` rather than leaving the platform
 * with no mail at all.
 *
 * WHY THIS EXISTS. Until 23 September 2026 the platform ran on `MAIL_MAILER=log`. The consoles
 * survived that; the phones did not — a resident signs in with a six-digit code posted to their
 * mailbox, so the Resident App could not be signed into by anybody who was not on the VM. The
 * relay that fixed it was a developer's own account, and replacing it with the client's must be
 * a form somebody fills in rather than an SSH session, a deploy and a secret pasted into a shell.
 */
final class MailSettings
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** The saved settings, or null when the platform is still running on `.env`. */
    public function current(): ?PlatformMailSetting
    {
        return PlatformMailSetting::query()->orderBy('id')->first();
    }

    /**
     * Put the saved settings in front of `.env` for this request.
     *
     * Called from `AppServiceProvider::boot()`. It must never throw: this runs before the
     * router, so an exception here is a white screen on every page rather than a broken mail
     * send, and a missing table during `migrate` would take the migration with it.
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
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $settings->host,
            'mail.mailers.smtp.port' => $settings->port,
            'mail.mailers.smtp.scheme' => $settings->scheme,
            'mail.mailers.smtp.username' => $settings->username,
            'mail.mailers.smtp.password' => $settings->password,
            'mail.from.address' => $settings->from_address,
            'mail.from.name' => $settings->from_name,

            // A relay is configured, so the excuse for an undeliverable mailer goes with it.
            'mail.undeliverable_acknowledged' => false,
        ]);
    }

    /**
     * What the screen is given.
     *
     * THE PASSWORD IS NOT IN IT, and never is. The interface reports whether one is set and how
     * long ago it changed; it does not return the value, not once, not to the Director.
     *
     * @return array<string, mixed>
     */
    public function shape(): array
    {
        $settings = $this->current();

        if ($settings === null) {
            return [
                'configured' => false,
                'host' => (string) config('mail.mailers.smtp.host'),
                'port' => (int) config('mail.mailers.smtp.port'),
                'scheme' => config('mail.mailers.smtp.scheme'),
                'username' => (string) config('mail.mailers.smtp.username'),
                'from_address' => (string) config('mail.from.address'),
                'from_name' => (string) config('mail.from.name'),
                'password_set' => (bool) config('mail.mailers.smtp.password'),
                'mailer' => (string) config('mail.default'),
                'updated_by' => null,
                'updated_at' => null,
                'verified_at' => null,
                'source' => 'Server configuration file. Saving here takes over.',
            ];
        }

        return [
            'configured' => true,
            'host' => $settings->host,
            'port' => $settings->port,
            'scheme' => $settings->scheme,
            'username' => $settings->username,
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'password_set' => $settings->password !== null && $settings->password !== '',
            'mailer' => 'smtp',
            'updated_by' => $settings->editor?->name,
            'updated_at' => $settings->updated_at?->toIso8601String(),
            'verified_at' => $settings->verified_at?->toIso8601String(),
            'source' => 'Saved here, overriding the server configuration file.',
        ];
    }

    /**
     * Save, and record WHICH fields moved — never their values.
     *
     * A blank password means "leave the one already stored alone", which is what makes the
     * field write-only: the screen shows dots, posts nothing, and the stored secret survives an
     * edit to the from-name.
     *
     * @param  array<string, mixed>  $input
     */
    public function save(array $input): PlatformMailSetting
    {
        $settings = $this->current() ?? new PlatformMailSetting;

        $changed = [];

        foreach (['host', 'port', 'scheme', 'username', 'from_address', 'from_name'] as $field) {
            if (array_key_exists($field, $input) && (string) $settings->getAttribute($field) !== (string) $input[$field]) {
                $changed[] = $field;
                $settings->setAttribute($field, $input[$field]);
            }
        }

        if (($input['password'] ?? '') !== '') {
            $changed[] = 'password';
            $settings->password = (string) $input['password'];
        }

        if ($changed !== []) {
            // A changed relay is an unproven one until somebody sends a test through it.
            $settings->verified_at = null;
        }

        $actor = Auth::user();
        $settings->updated_by = $actor instanceof User ? $actor->id : null;
        $settings->save();

        /*
         * THE FIELD NAMES, NOT THE VALUES. An audit entry recording the host and username of a
         * mail relay is a small leak; one recording the password is a large one, and `before`
         * and `after` are stored as JSON that the audit screen renders. Naming what moved is
         * enough to answer "who repointed our mail, and when".
         */
        if ($changed !== []) {
            $this->audit->record(
                action: 'platform.mail_settings_changed',
                entityType: 'platform_mail_setting',
                entityId: (string) $settings->id,
                after: ['fields' => $changed],
            );
        }

        return $settings;
    }

    /**
     * Send one message and say plainly what happened.
     *
     * The outcome is translated because the alternative is showing an operator
     * `Expected response code "250" but got code "535"` and expecting them to know that means
     * the password is wrong. Every branch names the next action.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendTest(string $to): array
    {
        $this->apply();

        try {
            [, $dropped] = SeededRecipients::whileWatching(static fn () => Mail::raw(
                "This is a test message from GeminiSecure.\n\n".
                'If you are reading it, the platform can send mail: sign-in codes, invitations and '.
                "password resets will reach the people they are addressed to.\n\n".
                // The server's own clock, and it says so (M19A §4.2): this mail belongs to no estate.
                'Sent '.Carbon::now()->utc()->format('D, d M Y H:i').' UTC from '.config('mail.mailers.smtp.host').'.',
                function ($message) use ($to): void {
                    $message->to($to)->subject('GeminiSecure — test message');
                }
            ));
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->explain($e)];
        }

        // Stopped by the sending layer (M35 §5), which raises nothing: not sent, and the relay proved nothing.
        if ($dropped !== []) {
            return ['ok' => false, 'message' => 'Not sent: '.$to.' belongs only to a person the sample data invented, not a real one, so the platform does not email it. Send the test to an address of your own.'];
        }

        $settings = $this->current();

        if ($settings !== null) {
            $settings->forceFill(['verified_at' => Carbon::now()])->save();
        }

        return ['ok' => true, 'message' => 'Sent to '.$to.'. If it does not arrive within a minute, check the spam folder and the from-address.'];
    }

    /**
     * A relay's refusal, in words an operator can act on. Public for the resident invites, which say the same thing.
     *
     * GMAIL FIRST (M25 §4). The client's stopgap relay is a free Gmail account, and Gmail answers two things in its
     * own words. Its daily cap (about 500 a day for a free account) comes back as `550 5.4.5 Daily user sending limit
     * exceeded`, which the plain `550` branch below would have called a refused from-address and sent the reader after
     * the wrong fix. And it takes no ordinary password from another program: `534 5.7.9 Application-specific password
     * required`, or `535 5.7.8 … BadCredentials`, both of which mean the same next step.
     */
    public function explain(Throwable $e): string
    {
        $raw = $e->getMessage();
        $gmail = stripos($raw, 'gsmtp') !== false || stripos($raw, 'support.google.com') !== false || stripos($raw, 'smtp.gmail.com') !== false;

        return match (true) {
            str_contains($raw, '5.4.5'), stripos($raw, 'sending limit') !== false, stripos($raw, 'sending quota') !== false => 'The relay\'s daily sending limit is reached, so nothing more goes out through it today. A free gmail.com mailbox sends about 500 emails a day; mail through it starts again within 24 hours. A mailbox on the company\'s own domain is allowed far more.',
            $gmail && (str_contains($raw, '534') || str_contains($raw, '535') || str_contains($raw, '5.7.9') || str_contains($raw, '5.7.8')) => 'Gmail refused the sign-in. It accepts only an app password from another program: turn on 2-Step Verification for that Google account, create a 16-character app password, and enter it here as the password.',
            str_contains($raw, '535'), stripos($raw, 'authenticat') !== false => 'The relay refused the username or password. Check both, and whether this mailbox needs an app-specific password.',
            str_contains($raw, '534'), str_contains($raw, '530') => 'The relay wants a different kind of authentication — often a second factor, or an app password rather than the account password.',
            // A timeout first: "could not be established … (Connection timed out)" is a timeout, and says more than "nothing answered".
            stripos($raw, 'timed out') !== false, stripos($raw, 'timeout') !== false => 'The relay did not answer in time. If the port is 465 the scheme must be `smtps`; port 587 takes no scheme at all. From a cloud server the relay\'s IPv6 address may be unreachable — `php artisan mail:probe` shows which address and port answer.',
            stripos($raw, 'connection could not be established') !== false, stripos($raw, 'connection refused') !== false => 'Nothing answered at that host and port. Check the address, and try port 587 if 465 is blocked.',
            stripos($raw, 'certificate') !== false, stripos($raw, 'ssl') !== false => 'The TLS handshake failed. Port 465 needs the `smtps` scheme; port 587 negotiates after connecting and needs none.',
            stripos($raw, 'sender') !== false, str_contains($raw, '550'), str_contains($raw, '553') => 'The relay would not accept that from-address. Most relays only send from a mailbox they host.',
            default => 'The relay refused the message: '.str($raw)->limit(160)->value(),
        };
    }
}
