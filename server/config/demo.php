<?php

declare(strict_types=1);

return [
    /*
     * THE ONE DEMONSTRATION SWITCH (M16 §1.2).
     *
     * OFF is the launch posture, and it is the default here — you opt IN to a
     * demonstration, you never opt out of one. When it is off, the server itself
     * (not the interface) refuses everything that exists only for a demonstration:
     *
     *   - no demonstration console or resident account can sign in — the seeded
     *     `.demo.geminisl.com` / `.test` addresses are refused at the door
     *     (App\Support\DemonstrationAccounts, EndSessionOfInactiveAccount, ResidentSignIn);
     *   - the test-card checkout does not exist (App\Services\Estate\DemoCardPayments);
     *   - the demonstration estate is not offered and its reviewer bypasses grant
     *     nothing (App\Services\Reviewer\ReviewerMode);
     *   - "Explore a demo community" is hidden (config/legal.php `demo_community`).
     *
     * A demonstration geofence is a per-post radius, not a switch, so it is caught
     * as data, not here (see DEMO_INVENTORY.md and SiteGeography's radius bound).
     */
    'enabled' => (bool) env('DEMO_MODE', false),

    /*
     * THE APP-STORE REVIEW WINDOW (M16 §1.2, §7; M5 §3).
     *
     * OFF normally, even on production, and off by default. Turned ON only for the
     * days a Google Play or Apple App Store review is live, and it re-enables ONLY
     * the single reviewer-flagged account into the single demonstration estate —
     * nothing else. A reviewer must be able to USE the product (Guideline 2.1) with
     * a static code, a site-derived geofence and a passcode identity; a real user
     * never can, because with this off `ReviewerMode::isReviewer()` answers false
     * however an account's `reviewer` column reads. Close the window when the review
     * clears (RUNBOOK.md → "App-store review window").
     *
     * Demonstration mode implies an open window, so a demo server needs only DEMO_MODE.
     */
    'review' => (bool) env('REVIEW_MODE', false),
];
