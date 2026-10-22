<?php

declare(strict_types=1);

namespace App\Services\Reviewer;

use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\DemoMode;

/**
 * REVIEWER MODE (M5 §3) — the ONE place that says what an app-store reviewer's account may
 * walk past, and the one place that writes it down.
 *
 * WHY IT EXISTS. Both apps are login-walled with no public sign-up. A reviewer at Apple or
 * Google downloads the binary and can do nothing: there is no enrolment code, the handset is
 * not bound to anybody, the geofence is a gate in Jamaica they are nowhere near, and the
 * biometric enrolment is somebody else's face. That is a certain rejection under Guideline
 * 2.1, and no amount of screenshots answers it — they have to be able to USE the product.
 *
 * WHY IT IS A SERVER FLAG AND NOT A BUILD FLAG. The binary a reviewer tests must be the
 * binary everybody else installs. A debug build, a `__DEV__` branch or a compile-time switch
 * would mean the thing under review is not the thing that ships, and it would leave a way
 * round the four gates sitting in the production app waiting to be found. So the bypasses
 * live on `guards.reviewer` / `resident_accounts.reviewer`: a column on ONE account, granted
 * deliberately, revoked by clearing it, and every use recorded in the audit log.
 *
 * THE FOUR BYPASSES, AND NOTHING ELSE:
 *
 *   GEOFENCE          satisfied from the SITE's surveyed coordinates instead of the handset's
 *                     (`shifts.preflight`). The reviewer is not at the gate and never will be.
 *   DEVICE BINDING    the first handset to present the static code is bound at once, with no
 *                     supervisor rebind to approve — and re-presenting it rebinds again, so a
 *                     second reviewer on a second phone is not locked out by the first.
 *   BIOMETRIC         the server tells the handset it may accept the phone's PASSCODE instead
 *                     of a fingerprint or a face, so a review device with no Face ID enrolled
 *                     is not sent to a supervisor override it can never obtain.
 *   SIGN-IN CODE      STATIC and never expiring, for the Guard App's enrolment and the
 *                     Resident App's one-time code alike. A reviewer cannot run an artisan
 *                     command, and a code that expires makes the review notes wrong the day
 *                     after they are written.
 *
 * WHAT IT IS NOT. It is NOT an exception to tenant isolation. The reviewer's accounts live
 * inside the demonstration estate and are resolved from their token exactly like anybody
 * else's, so they reach that estate's database and no other's. `ReviewerModeTest` walks the
 * real estate's ids with the reviewer's token and proves each one answers exactly as an id
 * nobody has.
 */
final class ReviewerMode
{
    /* -----------------------------------------------------------------
     | The credentials the review notes quote
     |------------------------------------------------------------------ */

    /** The demonstration estate's subdomain — also its tenant id and database suffix. */
    public const ESTATE = 'demoestate';

    public const ESTATE_NAME = 'Seaview Demo Estate (demonstration data)';

    public const RECEIPT_PREFIX = 'DEMO';

    /** The reviewer's Guard App account. */
    public const GUARD_NUMBER = 'GS-DEMO-1';

    public const GUARD_NAME = 'Review Officer (demonstration account)';

    /**
     * The Guard App enrolment code, STATIC and never expiring.
     *
     * Grouped for reading aloud, like every other enrolment code, and shaped so the
     * characters a reviewer types cannot be confused (no O/0, I/1, L). It is a public
     * credential to one estate of invented data, and it is printed in
     * `STORE_REVIEW_NOTES.md` on purpose; only its hash is ever stored.
     */
    public const GUARD_CODE = 'DEMO-2026';

    /** The reviewer's Resident App account. */
    public const RESIDENT_EMAIL = 'reviewer@demo.geminisecure.app';

    public const RESIDENT_NAME = 'Review Resident (demonstration account)';

    /** The Resident App sign-in code, STATIC and never expiring. Six digits, like a real one. */
    public const RESIDENT_CODE = '246810';

    /* -----------------------------------------------------------------
     | The bypasses, by the name they are audited under
     |------------------------------------------------------------------ */

    public const GEOFENCE = 'geofence_from_site';

    public const DEVICE_BINDING = 'device_auto_enrolled';

    public const PASSCODE_IDENTITY = 'passcode_identity_allowed';

    public const STATIC_CODE = 'static_code_accepted';

    public const DEMO_SESSION = 'demo_session_opened';

    /**
     * A reviewer walked the account-deletion screen to the end, and the account was KEPT (M5 §4).
     *
     * Both stores check that deletion is reachable in the app, so a reviewer taps it — and on the
     * demonstration account the ordinary answer would de-identify the very row the review notes tell
     * the NEXT reviewer to sign in as, and take "Explore a demo community" off the sign-in screen
     * with it. `DeleteResidentAccount` refuses it, says so in plain words, and records this.
     */
    public const DELETION_DECLINED = 'account_deletion_declined';

    public function __construct(private readonly AuditLogger $audit) {}

    /* -----------------------------------------------------------------
     | Asking
     |------------------------------------------------------------------ */

    /**
     * Whether this principal's reviewer bypasses are live right now.
     *
     * TWO THINGS AT ONCE: the account carries the flag, AND this server has a review
     * window open (`DemoMode::reviewerAllowed()` — always on a demonstration server,
     * on a live one only while `REVIEW_MODE` is set for a review, M16 §1.2/§7). The
     * window is checked HERE, at the single chokepoint every reviewer path runs
     * through — the resident static code, the guard enrolment code, the geofence and
     * passcode bypasses all ask `isReviewer`/`codeMatches` first — so closing the
     * window (the launch default) makes a stray `reviewer` row grant nothing, with no
     * other file to change.
     */
    public static function isReviewer(Guard|ResidentAccount|null $principal): bool
    {
        return $principal !== null && (bool) $principal->reviewer && DemoMode::reviewerAllowed();
    }

    /**
     * Whether the code presented is this reviewer account's static one.
     *
     * A non-reviewer answers false whatever it presents, even if its `reviewer_code_hash`
     * column somehow held a value — the flag is what grants the bypass, and the hash alone
     * never does.
     */
    public static function codeMatches(Guard|ResidentAccount|null $principal, string $code): bool
    {
        if (! self::isReviewer($principal) || ! is_string($principal->reviewer_code_hash) || $principal->reviewer_code_hash === '') {
            return false;
        }

        return hash_equals($principal->reviewer_code_hash, self::hash($code));
    }

    /** How a static code is stored: the hash, never the code. */
    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    /**
     * The demonstration estate, if this platform has one taking sign-ins.
     *
     * Answers null unless a review window is open (`DemoMode::reviewerAllowed()`), so on
     * a launched system with the window closed there is no demonstration estate to
     * resolve a reviewer into, even if the row still exists (M16 §1.2).
     */
    public static function estate(): ?Tenant
    {
        if (! DemoMode::reviewerAllowed()) {
            return null;
        }

        $estate = Tenant::query()->where('demo', true)->where('status', 'active')->orderBy('id')->first();

        return $estate instanceof Tenant ? $estate : null;
    }

    /** The reviewer's Resident App account inside the demonstration estate. */
    public static function residentOf(Tenant $estate): ?ResidentAccount
    {
        $account = ResidentAccount::query()
            ->where('tenant_id', $estate->getTenantKey())
            ->where('reviewer', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();

        return $account instanceof ResidentAccount ? $account : null;
    }

    /* -----------------------------------------------------------------
     | Writing it down
     |------------------------------------------------------------------ */

    /**
     * Record one use of one bypass.
     *
     * EVERY USE, not the first. M5 §3 asks for a log entry each time, and "each time" is what
     * makes the log answerable: a reviewer session is a handful of entries in one afternoon,
     * and anything else under `reviewer.*` is somebody using an account that should have been
     * revoked. `audit_log` is append-only, so nothing can tidy them away afterwards.
     *
     * @param  array<string, mixed>  $detail
     */
    public function record(string $bypass, Guard|ResidentAccount $principal, array $detail = []): void
    {
        $this->audit->record(
            action: 'reviewer.'.$bypass,
            entityType: $principal instanceof Guard ? 'Guard' : 'ResidentAccount',
            entityId: (string) $principal->getKey(),
            after: [
                'account' => $principal instanceof Guard ? $principal->employee_number : $principal->destination,
                ...$detail,
            ],
            tenantId: (string) $principal->tenant_id,
        );
    }
}
