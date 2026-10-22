<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Services\ResidentApp\ResidentSignIn;
use App\Support\DemonstrationAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BREAK GLASS: THE SERVER OPERATOR MARKS ONE ACCOUNT'S ALERT ADDRESS PROVEN (M10 §1.4).
 *
 *     php artisan accounts:verify-contact reid@example.com \
 *         --reason="Mailbox rejects our relay; owner confirmed the address by phone to J. Brown" --confirm
 *
 * WHY THIS EXISTS. The contact gate has, deliberately, no override in either console:
 * no Director, Super Admin or committee member can wave an account past it, because a
 * screen that could would be a screen that turns "an unverified address is never used
 * for alerting" into a checkbox. But a real mailbox can fail to take the code — a
 * relay that bounces, a filter that eats it — and the gate would then lock a real
 * President or Head of Security out of their own console with no way back. This is
 * the way back, and it is the SERVER OPERATOR's: somebody with a shell on the box, who
 * is already trusted with everything the gate protects.
 *
 * WHAT IT MARKS, AND NOTHING ELSE. The account's CURRENT contact address — the one the
 * owner typed on the verification page — or, when they never typed one, the address
 * the account signs in with. It never takes an address as an argument to prove: the
 * argument FINDS the account (by its sign-in address), so the operator cannot mark a
 * mailbox the owner never gave. Both fields go in one save, the shape `User::booted()`
 * accepts as a verification; any code still waiting for the account is retired, since
 * there is nothing left for it to prove.
 *
 * WHAT IT REFUSES, in a sentence each, changing nothing:
 *   - no `--reason` of at least ten characters — the audit entry must say why;
 *   - an address that belongs to no console account, or to a guard's record or a
 *     Resident App account, which have no alert address and no gate;
 *   - a demonstration account — `accounts:verify-demonstration` pre-verifies those,
 *     and they must never be told apart from a real one by which command touched them;
 *   - no `--confirm`, after saying exactly what it WOULD mark, so the operator can see
 *     the right account is about to be changed before it is.
 *
 * AUDITED AS THE SERVER OPERATOR, with the address masked and the reason. Written as an
 * `AuditEntry` directly, as `ClientOnboarding` does, because the actor is not a signed-in
 * user: `AuditLogger` would file it under "System", and a mark somebody chose to make is
 * not something the system did.
 *
 * NEVER A FULL ADDRESS OR PHONE NUMBER, on the screen or in the log. Every address it
 * prints is `ResidentSignIn::mask()`ed, and the reason is stored with any address or
 * phone number in it masked or withheld — the audit log is read for years by people who
 * need to know that this happened and why, not whose mailbox it was.
 */
class AccountsVerifyContact extends Command
{
    /** How the audit log names who did this. */
    public const ACTOR = 'server operator';

    /** The shortest reason accepted — enough to say why, not "ok". */
    public const MIN_REASON = 10;

    public const ACTION = 'user.contact_verified_by_operator';

    protected $signature = 'accounts:verify-contact
        {email : the address the console account SIGNS IN with — it finds the account, it is not the address proven}
        {--confirm : required: mark the address proven although nobody entered a one-time code}
        {--reason= : why, in at least ten characters; written to the audit log}';

    protected $description = 'Break glass: mark one console account\'s current alert address as proven, when its mailbox cannot take the code (M10 §1.4)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $reason = trim((string) $this->option('reason'));

        if (mb_strlen($reason) < self::MIN_REASON) {
            $this->error('Give a --reason of at least '.self::MIN_REASON.' characters. It is written to the audit log, and an address marked proven without anybody entering a code must say why. Nothing was changed.');

            return self::FAILURE;
        }

        $user = $email === '' ? null : User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error($this->notAConsoleAccount($email).' Nothing was changed.');

            return self::FAILURE;
        }

        /*
         * READ RAW, so a row whose console is neither of the two is refused here rather
         * than thrown on by the enum cast. Every console account is one or the other.
         */
        $console = (string) $user->getRawOriginal('console');

        if (! in_array($console, [Console::Gemini->value, Console::Estate->value], true)) {
            $this->error('Account #'.$user->getKey().' is not a console account. Only an account that signs in to a console has an alert address. Nothing was changed.');

            return self::FAILURE;
        }

        $address = $this->addressToProve($user);

        if (DemonstrationAccounts::isDemonstrationAddress((string) $user->email) || DemonstrationAccounts::isDemonstrationAddress($address)) {
            $this->error(ResidentSignIn::mask((string) $user->email).' is a demonstration account. `php artisan accounts:verify-demonstration` pre-verifies those; this command is for a real person\'s account. Nothing was changed.');

            return self::FAILURE;
        }

        $who = sprintf('account #%d (%s, %s)', $user->getKey(), $user->name, $user->roles->first()->label ?? 'no role');
        $masked = ResidentSignIn::mask($address);

        if ($user->hasVerifiedContact() && $user->contact_email === $address) {
            $this->info($masked.' is already the proven alert address of '.$who.'. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('confirm')) {
            $this->warn('This would mark '.$masked.' as the proven alert address of '.$who.', although nobody entered a code sent to it.');
            $this->error('Pass --confirm to do it. Nothing was changed.');

            return self::FAILURE;
        }

        $before = [
            'address' => $user->contact_email === null || $user->contact_email === '' ? null : ResidentSignIn::mask((string) $user->contact_email),
            'verified' => $user->hasVerifiedContact(),
        ];

        DB::connection('mysql')->transaction(function () use ($user, $address, $masked, $before, $reason): void {
            $now = Carbon::now();

            // The address and the moment in ONE save: `User::booted()` keeps it as a verification.
            $user->forceFill([
                'contact_email' => $address,
                'contact_email_verified_at' => $now,
            ])->save();

            // Nothing is left for a waiting code to prove.
            DB::connection('mysql')->table('contact_verification_codes')
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now, 'updated_at' => $now]);

            AuditEntry::create([
                'tenant_id' => $this->estateOf($user),
                'actor_id' => null,
                'actor_name' => self::ACTOR,
                'actor_role' => 'Command line (accounts:verify-contact)',
                'action' => self::ACTION,
                'entity_type' => 'User',
                'entity_id' => (string) $user->getKey(),
                'before' => $before,
                'after' => [
                    'address' => $masked,
                    'verified' => true,
                    'via' => 'accounts:verify-contact, by the server operator, without a one-time code',
                    'reason' => self::scrub($reason),
                ],
            ]);
        });

        $this->info('Marked '.$masked.' as the proven alert address of '.$who.'.');
        $this->line('  Alerts for this account now go to that address, and its console opens without the code.');
        $this->line('  Audited as the '.self::ACTOR.', with the reason given.');

        return self::SUCCESS;
    }

    /** The contact address the owner typed, or the sign-in address when they typed none. */
    private function addressToProve(User $user): string
    {
        $contact = mb_strtolower(trim((string) $user->contact_email));

        return $contact !== '' ? $contact : mb_strtolower(trim((string) $user->email));
    }

    /** Why this address finds no console account, said without printing it. */
    private function notAConsoleAccount(string $email): string
    {
        $masked = $email === '' ? 'An empty address' : ResidentSignIn::mask($email);

        if ($email !== '' && Guard::query()->where('email', $email)->exists()) {
            return $masked.' belongs to an officer\'s record, not a console account. Only a console account has an alert address.';
        }

        if ($email !== '' && ResidentAccount::query()->where('channel', 'email')->where('destination', $email)->exists()) {
            return $masked.' belongs to a Resident App account, not a console account. Only a console account has an alert address.';
        }

        return $masked.' is not the sign-in address of any console account. Give the address the account signs in with.';
    }

    /** The estate an estate account belongs to, so the entry is filed under it; null for Gemini staff. */
    private function estateOf(User $user): ?string
    {
        if ($user->getRawOriginal('console') !== Console::Estate->value) {
            return null;
        }

        $tenant = EstateAssignment::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->orderBy('id')
            ->value('tenant_id');

        return $tenant === null ? null : (string) $tenant;
    }

    /**
     * The reason as typed, with any address in it masked and any phone number withheld —
     * so the operator's own words cannot put a whole mailbox or number into the log.
     */
    public static function scrub(string $reason): string
    {
        $reason = (string) preg_replace_callback(
            '/[^\s@<>()"\',;]+@[^\s@<>()"\',;]+\.[^\s@<>()"\',;]+/u',
            static fn (array $match): string => ResidentSignIn::mask($match[0]),
            $reason,
        );

        $reason = (string) preg_replace_callback(
            '/\+?\d[\d\s().-]{5,}\d/',
            static fn (array $match): string => strlen((string) preg_replace('/\D/', '', $match[0])) >= 7 ? '[number withheld]' : $match[0],
            $reason,
        );

        return mb_substr($reason, 0, 500);
    }
}
