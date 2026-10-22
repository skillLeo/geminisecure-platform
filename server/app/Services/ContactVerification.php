<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Notifications\ContactVerificationCode;
use App\Services\Audit\AuditLogger;
use App\Services\ResidentApp\ResidentSignIn;
use App\Support\CentralQueue;
use App\Support\EstatePhone;
use App\Support\JamaicanNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * PROVING THE ADDRESS AN ALERT IS SENT TO — M10 §1.4.
 *
 * "The alert email is useless if nobody has entered an address." Every account the
 * contact gate applies to (`User::isSubjectToContactGate()`) supplies an address and
 * proves it with a one-time code before its console opens, and `User::alertEmail()`
 * hands out nothing that has not been through here.
 *
 * THE CODE — the same rules the Resident App's sign-in code has kept since 13 D3, so
 * the platform has one idea of what a one-time code is, not two:
 *
 *   SIX DIGITS, `random_int`, never a pattern.
 *   STORED ONLY AS AN HMAC keyed by the application key, the user and the address it
 *     was sent to. A copy of the table proves nothing, and a code sent to one
 *     address cannot be presented against another.
 *   TEN MINUTES, stated on the screen and in the mail.
 *   FIVE TRIES PER CODE, counted by an atomic conditional UPDATE rather than a row
 *     lock: two wrong guesses racing each other both land, and neither can slip in
 *     as a sixth. (The table is mutable, so a lock would be permitted; it is simply
 *     not needed.)
 *   ONE LIVE CODE PER ACCOUNT. Sending a new one retires the old, so a code in an
 *     old email stops working the moment a new one is asked for.
 *   ONE CODE A MINUTE, ENFORCED HERE. The screen draws the countdown; the refusal is
 *     the server's, measured from `sent_at`, so reloading the page, a second tab or
 *     a hand-built POST cannot shorten it. Changing the address does not reset it
 *     either — otherwise the cooldown would be one keystroke away from useless as a
 *     brake on mailing a stranger.
 *
 * WHAT A SEND DOES TO THE ACCOUNT. The address is written to `contact_email` at
 * once, unverified. If it differs from the proven one, `User::booted()` clears the
 * proof in the same save — the literal rule "changing the contact email clears
 * verification" — and `alertEmail()` answers null until the new address is proven.
 * The phone is stored alongside, optional, as typed, and only once it can be read
 * as a diallable number, so it is ready for SMS the day a provider is connected.
 *
 * REFUSALS ARE VALIDATION ERRORS keyed on the field they are about — `email`,
 * `resend`, `verification_code` — each a plain sentence that says what to do next.
 * The code field is `verification_code`, never `code`, so that it can be added to
 * the exception handler's `dontFlash` list without also discarding the chart of
 * accounts' `code` field on a failed form.
 */
final class ContactVerification
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const CODE_LENGTH = 6;

    private const TABLE = 'contact_verification_codes';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Store the address (and the optional phone) and mail a code to the address.
     *
     * @param  string  $console  how the mail names the console the code is for
     */
    public function send(User $user, string $email, ?string $phone, string $console): void
    {
        $email = mb_strtolower(trim($email));
        $now = Carbon::now();

        $wait = $this->resendIn($user, $now);

        if ($wait > 0) {
            throw ValidationException::withMessages([
                'resend' => sprintf(
                    'A code was sent less than a minute ago. You can ask for another in %d %s — '
                    .'one code a minute, so a mistyped address is never flooded.',
                    $wait,
                    $wait === 1 ? 'second' : 'seconds',
                ),
            ]);
        }

        $this->storeContact($user, $email, $phone);

        $code = str_pad((string) random_int(0, 10 ** self::CODE_LENGTH - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        DB::connection('mysql')->transaction(function () use ($user, $email, $code, $now): void {
            /*
             * RETIRED, NOT DELETED. `consumed_at` marks a code that can no longer
             * be used, for either reason — spent, or replaced — which is the only
             * fact the verify step needs. Kept rows are also what the cooldown and
             * the screen's "sent at" are read from.
             */
            DB::connection('mysql')->table(self::TABLE)
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);

            DB::connection('mysql')->table(self::TABLE)->insert([
                'user_id' => $user->getKey(),
                'email' => $email,
                'code_hash' => $this->hash($user, $email, $code),
                'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
                'attempts' => 0,
                'sent_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        /*
         * On demand, to the address being proven, on the ordinary queue and pinned to
         * the central connection — see `ContactVerificationCode`. The plaintext goes
         * into the notification and nowhere else: it is not logged, not audited, not
         * flashed and not returned.
         */
        Notification::route('mail', $email)->notify(CentralQueue::pin(new ContactVerificationCode(
            code: $code,
            name: (string) $user->name,
            console: $console,
            minutes: self::TTL_MINUTES,
        )));
    }

    /**
     * Store only the optional phone, leaving the proven address alone.
     *
     * A number is not an address a code proves, so changing it costs nobody their
     * email verification — a verified account can add or correct a phone without
     * being sent back through the gate.
     */
    public function savePhone(User $user, ?string $phone): void
    {
        $user->forceFill(['contact_phone' => $this->readablePhone($phone)])->save();
    }

    /** Prove the code; the account's address becomes its alert address. */
    public function verify(User $user, string $typed): void
    {
        $code = (string) preg_replace('/\s+/', '', $typed);

        if (! preg_match('/^\d{'.self::CODE_LENGTH.'}$/', $code)) {
            // Not counted as a try: nothing was compared.
            throw ValidationException::withMessages([
                'verification_code' => 'Enter the six digits from the email, with nothing else.',
            ]);
        }

        $row = $this->latestOpen($user);
        $now = Carbon::now();

        if ($row === null) {
            throw ValidationException::withMessages([
                'verification_code' => 'There is no code waiting for this account. Send a code first.',
            ]);
        }

        if ((string) $row->email !== (string) $user->contact_email) {
            throw ValidationException::withMessages([
                'verification_code' => 'That code was sent to a different address. Send a new code to the address above.',
            ]);
        }

        if (Carbon::parse((string) $row->expires_at)->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages([
                'verification_code' => 'That code has expired. A code lasts '.self::TTL_MINUTES.' minutes. Send a new one.',
            ]);
        }

        /*
         * CLAIM THE TRY BEFORE COMPARING, atomically. A conditional UPDATE that
         * only succeeds while fewer than five tries have been spent is the whole
         * limit: two guesses in flight at once each claim their own try, and the
         * sixth finds nothing left to claim.
         */
        $claimed = DB::connection('mysql')->table(self::TABLE)
            ->where('id', $row->id)
            ->whereNull('consumed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);

        if ($claimed === 0) {
            throw ValidationException::withMessages([
                'verification_code' => 'Five wrong codes have been entered, so this code no longer works. Send a new code.',
            ]);
        }

        if (! hash_equals((string) $row->code_hash, $this->hash($user, (string) $row->email, $code))) {
            $left = self::MAX_ATTEMPTS - ((int) $row->attempts + 1);

            throw ValidationException::withMessages([
                'verification_code' => $left > 0
                    ? sprintf('That code is not right. %d %s left for this code.', $left, $left === 1 ? 'try' : 'tries')
                    : 'That code is not right, and that was the fifth try, so this code no longer works. Send a new code.',
            ]);
        }

        DB::connection('mysql')->table(self::TABLE)
            ->where('id', $row->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => $now, 'updated_at' => $now]);

        /*
         * The proven address and the moment, in ONE save — the shape `User::booted()`
         * accepts as a verification rather than clearing as a change of address.
         */
        $user->forceFill([
            'contact_email' => (string) $row->email,
            'contact_email_verified_at' => $now,
        ])->save();

        /*
         * AUDITED, WITH THE ADDRESS MASKED. The append-only log is kept for years and
         * is read by people who have no need of somebody's full mailbox; that the
         * alert address was proven, and when, is what an auditor asks.
         */
        $this->audit->record(
            action: 'user.contact_verified',
            entityType: 'User',
            entityId: (string) $user->getKey(),
            after: ['address' => ResidentSignIn::mask((string) $row->email), 'via' => 'one-time code'],
        );
    }

    /**
     * The newest code this account was sent, as the screen draws it — or null.
     *
     * @return array{sent_to: string, sent_at: string, expires_at: string, expires_in: int, resend_in: int, attempts_left: int, state: string}|null
     */
    public function pending(User $user): ?array
    {
        $row = DB::connection('mysql')->table(self::TABLE)
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $now = Carbon::now();
        $expires = Carbon::parse((string) $row->expires_at);
        $attemptsLeft = max(0, self::MAX_ATTEMPTS - (int) $row->attempts);

        /*
         * ONE WORD FOR WHAT THE CODE IS NOW, so the screen says the true thing rather
         * than inferring it: a used code is "used", not "expired", and a code sent to
         * an address that has since been changed is "replaced".
         */
        $state = match (true) {
            $row->consumed_at !== null && $user->hasVerifiedContact() && (string) $row->email === (string) $user->contact_email => 'used',
            $row->consumed_at !== null => 'replaced',
            (string) $row->email !== (string) $user->contact_email => 'replaced',
            $attemptsLeft === 0 => 'locked',
            $expires->lessThanOrEqualTo($now) => 'expired',
            default => 'waiting',
        };

        return [
            'sent_to' => (string) $row->email,
            'sent_at' => Carbon::parse((string) $row->sent_at)->toIso8601String(),
            'expires_at' => $expires->toIso8601String(),
            'expires_in' => max(0, (int) $now->diffInSeconds($expires, false)),
            'resend_in' => $this->resendIn($user, $now),
            'attempts_left' => $attemptsLeft,
            'state' => $state,
        ];
    }

    /** Seconds until this account may be sent another code; 0 when it may now. */
    public function resendIn(User $user, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $sentAt = DB::connection('mysql')->table(self::TABLE)
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->value('sent_at');

        if ($sentAt === null) {
            return 0;
        }

        $ready = Carbon::parse((string) $sentAt)->addSeconds(self::RESEND_SECONDS);

        return $ready->greaterThan($now) ? (int) ceil($now->diffInSeconds($ready, false)) : 0;
    }

    /**
     * The address and phone, written together.
     *
     * The address goes in unverified. `User::booted()` clears the proof if it
     * differs from the proven one, and leaves it if it is the same address sent a
     * fresh code.
     */
    private function storeContact(User $user, string $email, ?string $phone): void
    {
        $user->forceFill([
            'contact_email' => $email,
            'contact_phone' => $this->readablePhone($phone),
        ])->save();
    }

    /**
     * The phone in its one stored form ("+1 876 555 0141", M18C §6), or a refusal in words.
     *
     * It was kept as typed, for `JamaicanNumber`'s reason; the client has since asked for one normalised form for
     * every person's number, and `JamaicanNumber::normalise` is that form — still readable by a person, and
     * diallable as it stands. Required now (M18C §2): the controller refuses a blank before it gets here.
     */
    private function readablePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $normal = JamaicanNumber::normalise($phone);

        if ($normal === null) {
            // The estate's own example (M32 F7), or the platform's home in the Gemini console.
            throw ValidationException::withMessages(['phone' => EstatePhone::forEstate()['invalid']]);
        }

        return $normal;
    }

    /** The newest code this account has that has not been used or retired. */
    private function latestOpen(User $user): ?object
    {
        return DB::connection('mysql')->table(self::TABLE)
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();
    }

    private function hash(User $user, string $email, string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key').'|'.$user->getKey().'|'.$email);
    }
}
