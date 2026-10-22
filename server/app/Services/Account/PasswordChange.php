<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\Console;
use App\Models\User;
use App\Notifications\AccountPasswordCode;
use App\Rules\NotAnObviousPassword;
use App\Services\Audit\AuditLogger;
use App\Support\CentralQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * CHANGING ONE'S OWN PASSWORD (M32 F9): the old password AND a code emailed to the account's own address — both, so
 * neither a password read over a shoulder nor a mailbox left open changes it alone. The new one is ten characters at
 * least, not one of the obvious (`NotAnObviousPassword`) and not the old one. Every other session of the account ends
 * (`AccountSessions::signOutOthers`), this one stays, and the person is told. Audited.
 *
 * The code is kept as an HMAC only (keyed by the application key, the account and the purpose), works once for ten
 * minutes with five tries, and one is sent a minute at most — `ContactVerification`'s rules for its own code.
 */
final class PasswordChange
{
    public const PURPOSE = 'password';

    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    private const TABLE = 'account_codes';

    public function __construct(private readonly AuditLogger $audit, private readonly AccountSessions $sessions) {}

    /** Email a code to the account's own address; refused within a minute of the last. */
    public function sendCode(User $user): void
    {
        $db = DB::connection('mysql');
        $last = $db->table(self::TABLE)->where('user_id', $user->id)->where('purpose', self::PURPOSE)->orderByDesc('id')->value('sent_at');

        if ($last !== null && Carbon::parse((string) $last, 'UTC')->addSeconds(self::RESEND_SECONDS)->isFuture()) {
            throw ValidationException::withMessages(['code' => 'A code was sent less than a minute ago. Check your inbox, or ask again in a minute.']);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $db->transaction(function () use ($db, $user, $code): void {
            // A new code retires every older one: only the last email's code works.
            $db->table(self::TABLE)->where('user_id', $user->id)->where('purpose', self::PURPOSE)->whereNull('consumed_at')->update(['consumed_at' => Carbon::now()]);

            $db->table(self::TABLE)->insert([
                'user_id' => $user->id,
                'purpose' => self::PURPOSE,
                'code_hash' => $this->hashOf($user, $code),
                'expires_at' => Carbon::now()->addMinutes(self::TTL_MINUTES),
                'attempts' => 0,
                'sent_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        });

        Notification::route('mail', (string) $user->email)->notify(CentralQueue::pin(new AccountPasswordCode(
            code: $code,
            name: (string) $user->name,
            console: $user->console === Console::Gemini ? 'Gemini console' : 'estate console',
            minutes: self::TTL_MINUTES,
        )));

        $this->audit->record(action: 'account.password_code_sent', entityType: 'User', entityId: (string) $user->id, actor: $user);
    }

    /**
     * Change it: the old password, the code, the new one twice. Each refusal is against its own field, and nothing
     * changes on any of them. Returns how many other sessions were signed out.
     *
     * @param  array<string, mixed>  $input
     */
    public function change(User $user, array $input, Request $request): int
    {
        $data = Validator::make($input, [
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:12'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10), new NotAnObviousPassword($user)],
        ], [
            'current_password.required' => 'Enter your current password.',
            'code.required' => 'Enter the code from the email. Press "Email me a code" if it has not come.',
            'password.required' => 'Choose a new password.',
            'password.confirmed' => 'The two new passwords are not the same.',
            'password.min' => 'A password is ten characters at least.',
        ])->validate();

        if (! Hash::check((string) $data['current_password'], (string) $user->password)) {
            $this->audit->record(action: 'account.password_change_refused', entityType: 'User', entityId: (string) $user->id, after: ['why' => 'current_password'], actor: $user);

            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }

        if (Hash::check((string) $data['password'], (string) $user->password)) {
            throw ValidationException::withMessages(['password' => 'That is the password you have now. Choose a new one.']);
        }

        $this->consumeCode($user, (string) $data['code']);

        $user->forceFill(['password' => (string) $data['password'], 'password_changed_at' => Carbon::now()])->save();

        $signedOut = $this->sessions->signOutOthers($user, $request, why: 'password_changed');

        $this->audit->record(action: 'account.password_changed', entityType: 'User', entityId: (string) $user->id, after: ['other_sessions_signed_out' => $signedOut], actor: $user);

        return $signedOut;
    }

    /** The account's live code, checked and spent; a wrong one counts a try, and the fifth wrong one retires it. */
    private function consumeCode(User $user, string $typed): void
    {
        $db = DB::connection('mysql');
        $row = $db->table(self::TABLE)->where('user_id', $user->id)->where('purpose', self::PURPOSE)->whereNull('consumed_at')->orderByDesc('id')->first();

        if ($row === null || Carbon::parse((string) $row->expires_at, 'UTC')->isPast()) {
            throw ValidationException::withMessages(['code' => 'That code has expired, or none was sent. Press "Email me a code" for a new one.']);
        }

        if (! hash_equals((string) $row->code_hash, $this->hashOf($user, (string) preg_replace('/\D+/', '', $typed)))) {
            $attempts = (int) $row->attempts + 1;
            $db->table(self::TABLE)->where('id', $row->id)->update([
                'attempts' => $attempts,
                'consumed_at' => $attempts >= self::MAX_ATTEMPTS ? Carbon::now() : null,
                'updated_at' => Carbon::now(),
            ]);
            $this->audit->record(action: 'account.password_change_refused', entityType: 'User', entityId: (string) $user->id, after: ['why' => 'code', 'attempts' => $attempts], actor: $user);

            throw ValidationException::withMessages(['code' => $attempts >= self::MAX_ATTEMPTS
                ? 'That code is not right, and it has been tried five times, so it no longer works. Press "Email me a code" for a new one.'
                : 'That code is not right. Check the email and enter the six digits it gives.']);
        }

        $db->table(self::TABLE)->where('id', $row->id)->update(['consumed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    private function hashOf(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.self::PURPOSE.'|'.$code, (string) config('app.key'));
    }
}
