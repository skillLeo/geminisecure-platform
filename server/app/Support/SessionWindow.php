<?php

declare(strict_types=1);

namespace App\Support;

use App\Api\ApiError;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;

/**
 * THE IDLE WINDOW — how long a handset may sit untouched before it has to sign in again.
 *
 * The client's ruling on Q-M42 was four words: "Automatically logs him out within 30
 * minutes." Two readings of that sentence were possible and only one of them is safe.
 *
 *   ABSOLUTE   thirty minutes from the moment the token was issued. A guard on a
 *              twelve-hour watch is signed out twenty-four times, each time needing a
 *              one-time enrolment code from an office that is shut at 2 a.m. That is
 *              not what was asked for and it would take the duress button away from a
 *              lone officer twice an hour.
 *
 *   SLIDING    thirty minutes from the LAST request the token made. Somebody using the
 *              app is never interrupted; a phone nobody has touched for half an hour —
 *              on a desk, in a drawer, in a taxi, sold — is dead. THIS IS WHAT IS BUILT.
 *              (DECISIONS D-M126; the hazard that remains is Q-M46.)
 *
 * WHAT MARKS "TOUCHED". Sanctum writes `personal_access_tokens.last_used_at` after every
 * authenticated request it lets through, and has always done so. So the window costs no
 * extra column, no extra write and no migration — it is a comparison against a timestamp
 * the server was already keeping. A token that has never been used has no `last_used_at`,
 * so its issue time stands in: a token minted and never presented dies on the same clock.
 *
 * A GUARD ON SHIFT STAYS SIGNED IN FOR THE SHIFT (M22 §1.3, found on the emulator). Measured
 * from the last request alone, a guard's phone that sent nothing for half an hour — no signal
 * on a perimeter walk, a send stuck on a dead connection — was signed out in the middle of the
 * watch: every position it had queued was refused, on-duty sharing stopped, and nothing told
 * anybody. So for a guard's token, the time ON DUTY counts as touched: while a shift of theirs is
 * under way (clocked in, not out — `ShiftAutoClose` ends one nobody closed) the window cannot
 * close, and after the clock-out it runs from the clock-out. A phone that is lost or handed on
 * mid-shift is stopped the way it always could be: the office ends its access, which deletes the
 * token at once. (DECISIONS D-206; it narrows the Q-M42 ruling for guards on duty only.)
 *
 * This class holds the DECISION and nothing else — no database, no request, no Sanctum.
 * `AppServiceProvider` wires it to Sanctum's `authenticateAccessTokensUsing` hook, and
 * `SessionExpiryTest` tests the arithmetic here directly.
 */
final class SessionWindow
{
    /** The client's number. `config/api.php` may override it; this is what ships. */
    public const DEFAULT_MINUTES = 30;

    /** The refusal a handset is given when its window has closed. Distinct from `unauthenticated` on purpose. */
    public const ERROR_CODE = 'session_expired';

    /** How many minutes of doing nothing end a session. */
    public static function minutes(): int
    {
        $configured = (int) config('api.session_idle_minutes', self::DEFAULT_MINUTES);

        /*
         * Zero or a negative number would mean "expire every token on every request",
         * which is a configuration mistake that would read as a total outage. A window
         * has to be a positive number of minutes; anything else falls back to the ruling.
         */
        return $configured > 0 ? $configured : self::DEFAULT_MINUTES;
    }

    /**
     * When this token was last spoken with: its last use, or — on a token that has never
     * been presented — the moment it was issued.
     */
    public static function lastTouched(?CarbonInterface $lastUsedAt, ?CarbonInterface $createdAt, ?CarbonInterface $onDutyUntil = null): ?CarbonInterface
    {
        $touched = $lastUsedAt ?? $createdAt;

        // A guard's time on duty counts as touched (M22 §1.3): the later of the two stands.
        if ($onDutyUntil !== null && ($touched === null || $onDutyUntil->greaterThan($touched))) {
            return $onDutyUntil;
        }

        return $touched;
    }

    /**
     * Has the window closed?
     *
     * Exactly `minutes` of silence is expired — "a phone left untouched for thirty minutes
     * has to sign in again" reads the boundary that way, and a boundary has to fall somewhere.
     *
     * A token with NEITHER timestamp is treated as still open, and that is deliberate. Every
     * token this platform issues is stamped by `createToken`, so a row with no `created_at`
     * is a damaged record rather than evidence of idleness — and refusing a guard at a gate
     * over a damaged row is the worse of the two mistakes. Such a token still has to pass
     * every other check: its abilities, its app, and a guard who is still on active duty.
     */
    public static function expired(?CarbonInterface $lastTouched, CarbonInterface $now, ?int $minutes = null): bool
    {
        if ($lastTouched === null) {
            return false;
        }

        return $lastTouched->copy()->addMinutes($minutes ?? self::minutes())->lessThanOrEqualTo($now);
    }

    /** When a token last touched at this moment stops working. */
    public static function closesAt(CarbonInterface $lastTouched, ?int $minutes = null): CarbonInterface
    {
        return $lastTouched->copy()->addMinutes($minutes ?? self::minutes());
    }

    /**
     * The refusal, in words a person holding the phone can act on.
     *
     * It is shown verbatim: the Guard App puts the server's own sentence on the duress
     * screen when a raise is refused, so this sentence is read at the worst possible
     * moment and has to say what happened AND what is not lost.
     */
    public static function refusal(?int $minutes = null): ApiError
    {
        $window = $minutes ?? self::minutes();

        return new ApiError(401, self::ERROR_CODE, sprintf(
            'Your session timed out after %d minutes with nothing sent from this phone. Sign in again to carry on — nothing you saved on this phone has been lost.',
            $window,
        ));
    }

    /**
     * The hook Sanctum calls while it is deciding whether a bearer token authenticates.
     *
     * `$stillValid` is everything Sanctum itself checked (the token exists, any `expires_at`
     * it carries, the provider). This only ever NARROWS that, never widens it.
     *
     * It THROWS rather than returning false, and that is the whole point of the change: a
     * plain false is rendered as `401 unauthenticated`, which the apps already read as "the
     * office revoked this handset" and answer with "ask for a new enrolment code". A session
     * that merely timed out is a different fact and needs a different sentence, so it gets
     * its own code and the apps branch on it.
     *
     * The token row is NOT deleted here. Two reasons, both deliberate:
     *   - every later request with the same token gets the SAME answer, instead of the first
     *     one saying "timed out" and the rest saying "unknown token";
     *   - the phone's PUSH address is tied to this token's id (`device_push_tokens`), and the
     *     sender drops addresses whose token is gone. Deleting on a timeout would silence a
     *     guard's handset for the site's panic and duress alerts, which is exactly backwards.
     *     A deliberate sign-out DOES delete it — there the phone is being handed back.
     */
    /**
     * `$onDuty` answers, for a guard's token, the latest moment its guard was on duty (now, while a shift is under way;
     * the clock-out after one) or null. It is asked only when the window has otherwise closed, so an app in use pays
     * nothing for it.
     *
     * @param  (Closure(): ?CarbonInterface)|null  $onDuty
     */
    public static function stillOpen(?CarbonInterface $lastUsedAt, ?CarbonInterface $createdAt, bool $stillValid, ?Closure $onDuty = null): bool
    {
        if (! $stillValid) {
            return false;
        }

        $now = Carbon::now();

        if (! self::expired(self::lastTouched($lastUsedAt, $createdAt), $now)) {
            return true;
        }

        if ($onDuty !== null && ! self::expired(self::lastTouched($lastUsedAt, $createdAt, $onDuty()), $now)) {
            return true;
        }

        throw self::refusal();
    }
}
