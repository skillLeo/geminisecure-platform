<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\Console;
use App\Models\User;
use App\Notifications\AccountEmailChanged;
use App\Notifications\AccountEmailCode;
use App\Rules\ReceivesMail;
use App\Services\Audit\AuditLogger;
use App\Support\CentralQueue;
use App\Support\DemonstrationAccounts;
use App\Support\SiteTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * CHANGING THE ADDRESS ONE SIGNS IN WITH (M34 §1), the way a password is changed: the CURRENT PASSWORD, and a code
 * emailed to the NEW address, so the account never comes to depend on an address nobody has shown can receive mail.
 * Found on production, 6 October: the only Director signed in as an address that takes no mail, and nothing anywhere
 * could change it — the page said "Ask a Director", and the Director had no screen for it either. A forgotten password
 * would have sent its reset link nowhere.
 *
 *   ONE   the new address and the current password. A wrong password is refused and audited; an address that is the
 *         same one, a demonstration address or another account's is refused; then a six-digit code goes to the NEW
 *         address, and the address waits in this session for the code.
 *   TWO   the code. It is kept as an HMAC bound to the account AND to the address it was sent to, works once for ten
 *         minutes with five tries, and one is sent a minute at most — `PasswordChange`'s rules for its own code.
 *
 * Then the address changes, the change is in the audit log with both addresses, and the OLD address is told (the new
 * one masked), so a change somebody else made does not go unnoticed. Every session stays signed in: nothing about the
 * password changed, and a person locked out of their other browsers by their own new address would be worse off.
 *
 * A DEMONSTRATION ACCOUNT KEEPS ITS ADDRESS. The demonstration finds its accounts by their invented addresses
 * (`DemonstrationAccounts`), and anybody who knows a demonstration password could otherwise move the account to a
 * mailbox of their own.
 */
final class SignInEmailChange
{
    public const PURPOSE = 'email';

    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    /** The new address a code was sent to, waiting in this session for the code. */
    public const PENDING = 'account.sign_in_email_pending';

    private const TABLE = 'account_codes';

    private const TAKEN = 'That address already belongs to another GeminiSecure account, so it cannot be yours as well. Use another, or ask that account\'s owner.';

    public function __construct(private readonly AuditLogger $audit) {}

    /** Why this account's sign-in address is not changed from My account, or null when it may be. */
    public static function lockedBecause(User $user): ?string
    {
        return DemonstrationAccounts::isDemonstrationAddress((string) $user->email)
            ? 'This is a demonstration account. The demonstration signs it in by this address, so it is not changed here.'
            : null;
    }

    /**
     * Step one: check the password and the address, and email the new address a code. Returns the address, as it will
     * be kept: trimmed and in lower case.
     *
     * @param  array<string, mixed>  $input
     */
    public function sendCode(User $user, array $input, Request $request): string
    {
        $this->refuseIfLocked($user);

        $data = Validator::make($input, [
            'new_email' => ['required', 'string', 'email:rfc', 'max:190', new ReceivesMail],
            'current_password' => ['required', 'string'],
        ], [
            'new_email.required' => 'Enter the new address you want to sign in with.',
            'new_email.email' => 'That is not an email address. Check it and try again.',
            'new_email.max' => 'That address is too long for an email address.',
            'current_password.required' => 'Enter your current password.',
        ])->validate();

        if (! Hash::check((string) $data['current_password'], (string) $user->password)) {
            $this->refused($user, ['why' => 'current_password']);

            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        $address = self::normalise((string) $data['new_email']);
        $this->refuseAddress($user, $address);

        $db = DB::connection('mysql');
        $last = $db->table(self::TABLE)->where('user_id', $user->id)->where('purpose', self::PURPOSE)->orderByDesc('id')->value('sent_at');

        if ($last !== null && Carbon::parse((string) $last, 'UTC')->addSeconds(self::RESEND_SECONDS)->isFuture()) {
            throw ValidationException::withMessages(['new_email' => 'A code was sent less than a minute ago. Check that inbox, or ask again in a minute.']);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $db->transaction(function () use ($db, $user, $address, $code): void {
            // A new code retires every older one: only the last email's code works, and only for its own address.
            $db->table(self::TABLE)->where('user_id', $user->id)->where('purpose', self::PURPOSE)->whereNull('consumed_at')->update(['consumed_at' => Carbon::now()]);

            $db->table(self::TABLE)->insert([
                'user_id' => $user->id,
                'purpose' => self::PURPOSE,
                'code_hash' => $this->hashOf($user, $address, $code),
                'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
                'attempts' => 0,
                'sent_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });

        Notification::route('mail', $address)->notify(CentralQueue::pin(new AccountEmailCode(
            code: $code,
            name: (string) $user->name,
            console: self::consoleName($user),
            minutes: self::TTL_MINUTES,
        )));

        $request->session()->put(self::PENDING, $address);

        $this->audit->record(action: 'account.sign_in_email_code_sent', entityType: 'User', entityId: (string) $user->id, after: ['to' => $address], actor: $user);

        return $address;
    }

    /**
     * Step two: the code from the email the new address was sent. The account signs in with the new address from now
     * on; the old one is told. Returns the old address.
     *
     * @param  array<string, mixed>  $input
     */
    public function change(User $user, array $input, Request $request): string
    {
        $this->refuseIfLocked($user);

        $address = $request->session()->get(self::PENDING);

        if (! is_string($address) || $address === '') {
            throw ValidationException::withMessages(['code' => 'Start again: enter the new address and your password, and we will email that address a code.']);
        }

        $code = (string) Validator::make($input, ['code' => ['required', 'string', 'max:12']], [
            'code.required' => 'Enter the code from the email sent to '.$address.'.',
        ])->validate()['code'];

        // Asked again: the address may have been taken by another account in the ten minutes since.
        $this->refuseAddress($user, $address);
        $this->consumeCode($user, $address, $code);

        $old = (string) $user->email;

        try {
            $user->forceFill(['email' => $address, 'email_verified_at' => Carbon::now()])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => self::TAKEN]);
        }

        $request->session()->forget(self::PENDING);

        $this->audit->record(action: 'account.sign_in_email_changed', entityType: 'User', entityId: (string) $user->id, before: ['email' => $old], after: ['email' => $address], actor: $user);

        Notification::route('mail', $old)->notify(CentralQueue::pin(new AccountEmailChanged(
            name: (string) $user->name,
            console: self::consoleName($user),
            newAddress: self::mask($address),
            when: SiteTime::stamp(Carbon::now(), tenant()?->getTenantKey(), 'l, j F Y \a\t g:i A'),
            support: (string) config('legal.support_email'),
        )));

        return $old;
    }

    /** "ge•••@gmail.com": enough for the owner of the old address to recognise their own new one, and no more. */
    public static function mask(string $address): string
    {
        $at = strrpos($address, '@');

        if ($at === false) {
            return mb_substr($address, 0, 2).'•••';
        }

        return mb_substr(substr($address, 0, $at), 0, 2).'•••'.substr($address, $at);
    }

    private static function normalise(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    private static function consoleName(User $user): string
    {
        return $user->console === Console::Gemini ? 'Gemini console' : 'estate console';
    }

    private function refuseIfLocked(User $user): void
    {
        $locked = self::lockedBecause($user);

        if ($locked !== null) {
            throw ValidationException::withMessages(['new_email' => $locked]);
        }
    }

    /** An address this account may move to: not its own already, not an invented one, and nobody else's. */
    private function refuseAddress(User $user, string $address): void
    {
        if ($address === self::normalise((string) $user->email)) {
            throw ValidationException::withMessages(['new_email' => 'That is the address you sign in with now. Enter the new one.']);
        }

        if (DemonstrationAccounts::isDemonstrationAddress($address)) {
            throw ValidationException::withMessages(['new_email' => 'That is a demonstration address, and nobody can read its mail. Use an address you can open.']);
        }

        if (User::query()->where('email', $address)->whereKeyNot($user->id)->exists()) {
            $this->refused($user, ['why' => 'taken']);

            throw ValidationException::withMessages(['new_email' => self::TAKEN]);
        }
    }

    /** The live code for this account and this address, checked and spent; a wrong one counts a try, the fifth retires it. */
    private function consumeCode(User $user, string $address, string $typed): void
    {
        $db = DB::connection('mysql');
        $row = $db->table(self::TABLE)->where('user_id', $user->id)->where('purpose', self::PURPOSE)->whereNull('consumed_at')->orderByDesc('id')->first();

        if ($row === null || Carbon::parse((string) $row->expires_at, 'UTC')->isPast()) {
            throw ValidationException::withMessages(['code' => 'That code has expired, or none was sent. Enter the new address and your password again for a new one.']);
        }

        if (! hash_equals((string) $row->code_hash, $this->hashOf($user, $address, (string) preg_replace('/\D+/', '', $typed)))) {
            $attempts = (int) $row->attempts + 1;
            $db->table(self::TABLE)->where('id', $row->id)->update([
                'attempts' => $attempts,
                'consumed_at' => $attempts >= self::MAX_ATTEMPTS ? Carbon::now() : null,
                'updated_at' => Carbon::now(),
            ]);
            $this->refused($user, ['why' => 'code', 'attempts' => $attempts]);

            throw ValidationException::withMessages(['code' => $attempts >= self::MAX_ATTEMPTS
                ? 'That code is not right, and it has been tried five times, so it no longer works. Enter the new address and your password again for a new one.'
                : 'That code is not right. Check the email sent to '.$address.' and enter the six digits it gives.']);
        }

        $db->table(self::TABLE)->where('id', $row->id)->update(['consumed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /** @param  array<string, mixed>  $why */
    private function refused(User $user, array $why): void
    {
        $this->audit->record(action: 'account.sign_in_email_change_refused', entityType: 'User', entityId: (string) $user->id, after: $why, actor: $user);
    }

    private function hashOf(User $user, string $address, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.self::PURPOSE.'|'.$address.'|'.$code, (string) config('app.key'));
    }
}
