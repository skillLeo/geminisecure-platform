<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * WHICH CONSOLE ACCOUNTS ARE DEMONSTRATION ACCOUNTS, AND THE ONE WAY TO PRE-VERIFY
 * THEM (M10 §1.4).
 *
 * WHY THIS EXISTS. The contact gate stops every estate-console account and every
 * Gemini role a panic reaches at first sign-in until it has proven an alert
 * address by a one-time code. The work order is explicit that this must not stop
 * the client's team in the middle of a demonstration: "pre-verify the seeded
 * demonstration accounts so the client's team is not stopped at the door." A
 * demonstration account's address is one the seeder INVENTED — nobody can receive
 * a code at `everton-president@phoenixparkvillage1.demo.geminisl.com` — so gating
 * it would not be a check, it would be a locked door.
 *
 * HOW ONE IS RECOGNISED, AND WHY BY ADDRESS. Every seeder and command that creates
 * a demonstration account gives it an address on a domain no real person holds, and
 * each is listed here beside the code that writes it:
 *
 *   *.demo.geminisl.com   `phoenix:demo-seed`, and `estate:add-committee-account`
 *                         whenever it is handed an address on the same domain — the
 *                         convention for adding one more demonstration committee
 *                         member to an estate that is already seeded.
 *   *.test                `DemoDataSeeder` (director@ / security@geminisecurity.test,
 *                         president@ / treasurer@ / manager@<estate>.test) and the
 *                         `gate:console` probe (gate-probe@geminisecurity.test).
 *                         `.test` is reserved by RFC 2606 and can never receive
 *                         mail, so nothing on it can be a real person's inbox.
 *   quicklogin.local      the local-only `dev/login/{role}` bypass.
 *
 * The address is the seeders' own convention and the only mark they all share —
 * none of them flags the row any other way — so this reads the same fact they
 * write, and `accounts:verify-demonstration` can find exactly the accounts they
 * made on a server where the seeders ran months ago.
 *
 * WHAT IT NEVER MATCHES: any address on a real domain. An account created by an
 * invitation, by client onboarding or by `platform:first-director` belongs to a
 * person, and a person proves their own address at the gate like everybody else.
 */
final class DemonstrationAccounts
{
    /** `phoenix:demo-seed`'s own domain, under every estate key. */
    public const DEMO_DOMAIN_SUFFIX = '.demo.geminisl.com';

    /**
     * THE SAME DOMAIN WITH NO ESTATE KEY IN FRONT, and it is not hypothetical.
     *
     * M10 §2's estate Head of Security was added to Phoenix Park on production by
     * `estate:add-committee-account` as `clifton-head-of-security@demo.geminisl.com` — the
     * bare domain, not a subdomain of it. Matching only the suffix above missed him, so
     * the one account §2 exists to demonstrate would have met the contact gate at its
     * next sign-in with a mailbox nobody can open. Found by reading production after the
     * deploy, not by a test: the seeders never write this form.
     */
    public const DEMO_DOMAIN = 'demo.geminisl.com';

    /** RFC 2606's reserved top-level domain: `DemoDataSeeder` and the gate probe. */
    public const RESERVED_TLD = '.test';

    /** The local quick-login bypass's accounts (`QuickLoginController::DEMO_DOMAIN`). */
    public const QUICK_LOGIN_DOMAIN = 'quicklogin.local';

    /** Is this sign-in address one a seeder invented? */
    public static function isDemonstrationAddress(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        $domain = substr($email, $at + 1);

        return str_ends_with($domain, self::DEMO_DOMAIN_SUFFIX)
            || $domain === self::DEMO_DOMAIN
            || str_ends_with($domain, self::RESERVED_TLD)
            || $domain === self::QUICK_LOGIN_DOMAIN;
    }

    /**
     * Every demonstration account, by the same rule, in SQL.
     *
     * @return Builder<User>
     */
    public static function query(): Builder
    {
        return User::query()->where(static function (Builder $query): void {
            $query->where('email', 'like', '%'.self::DEMO_DOMAIN_SUFFIX)
                ->orWhere('email', 'like', '%@'.self::DEMO_DOMAIN)
                ->orWhere('email', 'like', '%'.self::RESERVED_TLD)
                ->orWhere('email', 'like', '%@'.self::QUICK_LOGIN_DOMAIN);
        });
    }

    /**
     * Mark one demonstration account's sign-in address as its proven alert address.
     *
     * REFUSES ANYTHING ELSE, so a caller cannot use this to wave a real person past
     * the gate: a real address is proven by the person who reads it, or not at all.
     *
     * `contact_email = email` and the timestamp in ONE save, which is the shape
     * `User::booted()` accepts as a verification rather than clearing it as a change.
     *
     * @return bool whether anything was written
     */
    public static function verify(User $user): bool
    {
        if (! self::isDemonstrationAddress((string) $user->email)) {
            return false;
        }

        $address = mb_strtolower(trim((string) $user->email));

        if ($user->contact_email === $address && $user->contact_email_verified_at !== null) {
            return false;
        }

        $user->forceFill([
            'contact_email' => $address,
            'contact_email_verified_at' => Carbon::now(),
        ])->save();

        return true;
    }
}
