<?php

declare(strict_types=1);

/*
 * ON-DUTY GUARD TRACKING (M11 §3; D-174).
 *
 * `enabled` is the feature flag, on by default, and it covers the Guard App only: the Resident App has no such
 * capability and reads a position only at the moment of a panic. Switched off, the Guard App is told so, asks for
 * nothing, and starts nothing; the endpoint refuses every position.
 *
 * `policy_version` names the consent text the Guard App shows (apps/guard/src/features/tracking). Change the
 * words there and this with them: every officer is asked again, and nothing is recorded for one who has not
 * answered the new version.
 */
return [
    'enabled' => (bool) env('GUARD_TRACKING_ENABLED', true),

    /*
     * NO ESTATE CONSOLE SEES AN OFFICER'S POSITION UNTIL THIS IS LIFTED (M57 §9.1, Hassam, 9 October 2026: "Do not put
     * individual guard positions on the estate console until I have confirmed it with Omar"). A guard's position is
     * the security company's employment data (master reference §4.3, RISK-07): the estate sees "guards on site: N".
     * While held, the per-contract switch (`subscriptions.estate_sees_guard_positions`) changes nothing; the Gemini
     * console, which owns the data, is unaffected.
     *
     * LIFTED ON LIVE, 12 October 2026, at the client's instruction (Hassam): the client asked for it directly and
     * repeatedly and owns both companies in this arrangement. Only Phoenix Park's contract switch is on; a written
     * confirmation from Gemini is still required before any estate that is not the client's own sees guard positions.
     * The default here stays held, so any other server starts with no estate seeing a guard.
     */
    'estate_positions_held' => (bool) env('GUARD_POSITIONS_HELD_FROM_ESTATES', true),

    // 2026-10-10: the lead sentence rewritten in the words the app stores check for (M17 §3.2) — every officer is asked again.
    'policy_version' => '2026-10-10',

    // Bounded retention, and the purge job that makes it true (gs:guard-positions-purge, nightly).
    'retention_days' => (int) env('GUARD_POSITION_RETENTION_DAYS', 90),

    // Older than this, a pin is drawn stale: greyed, with its time.
    'stale_after_seconds' => 300,

    /*
     * NO SIGNAL (Hassam, 12 Oct 2026; GUARD_TRACKING.md 5.5): nothing has ARRIVED from a guard on duty for this long, so
     * the map says "No signal" and the dispatcher is alerted. Per estate in Governance's site tracking settings once they
     * land; this is the default, read through GuardFreshness::tolerances() and nowhere else.
     *
     * NEVER SHORTER THAN THE SLOWEST READING INTERVAL PLUS TWO MINUTES (Payroll's rule, approved by Hassam, 12 Oct): a
     * healthy phone standing still or low on battery must never read as No signal. 180 + 120 = 300 today; move this with
     * `sampling` below (TrackingDefaultsKeepTheirWordTest holds it).
     */
    'no_signal_after_seconds' => (int) env('GUARD_NO_SIGNAL_AFTER_SECONDS', 300),

    /*
     * A PHONE'S QUEUE AS AN OPERATIONAL PROBLEM (GUARD_TRACKING.md section 9): the dispatcher's map warns once the phone
     * says it holds this many readings unsent, or its oldest unsent reading is this old, or it had to thin any.
     */
    'queue_warn_after' => 200,
    'queue_warn_age_seconds' => 1800,

    /*
     * A GAP IN WHAT WAS RECORDED (M22 §1.1). Longer than this between two positions TAKEN on a shift the officer agreed
     * to share — or since the last one — is a gap: shown on the shift's record and on the dispatcher's alertness board,
     * and told to the officer's phone once (`tracking:watch`). Judged on capture times, so a phone that was offline and
     * sends its queue later has no gap: those positions were taken. Well over the slowest sampling (three minutes).
     */
    'gap_after_seconds' => (int) env('GUARD_TRACKING_GAP_AFTER_SECONDS', 600),

    // How far a phone's corrected capture time may sit outside the shift before the fix is refused.
    'shift_tolerance_seconds' => 120,

    'max_batch' => 200,

    /*
     * How the phone samples (M11 §3.1), sent to it so it can be tuned without an app release. Every 45 to 90 seconds
     * moving, the limits the consent words 2026-10-12 give the officer (Hassam, 12 Oct: the consent bounds the setting,
     * never the other way round); slower when standing still and below 20% battery. The named moments (clock-in, every
     * checkpoint and gate scan, a duress, clock-out) are taken as well, whatever the interval.
     */
    'sampling' => [
        'interval_s' => 45,
        'distance_m' => 25,
        'stationary_interval_s' => 180,
        'low_battery_pct' => 20,
        'low_battery_interval_s' => 180,
    ],
];
