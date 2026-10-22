<?php

declare(strict_types=1);

namespace App\Support;

/**
 * THE ONE PLACE THAT READS THE DEMONSTRATION SWITCH (M16 §1.2).
 *
 * Every guard that must behave differently on a demonstration server than on the
 * live one asks HERE, so "is this a demonstration?" is one fact with one answer,
 * not a `config('demo.enabled')` scattered across a dozen files that some future
 * change flips in eleven of them. See `config/demo.php` for what each switch does.
 *
 * The default is the launch default: a plain production `.env` with neither key
 * set is a live system with no demonstration surface at all.
 */
final class DemoMode
{
    /** The master switch. Off ⇒ no demonstration scaffolding is reachable by anyone. */
    public static function enabled(): bool
    {
        return (bool) config('demo.enabled', false);
    }

    /** Whether an app-store review is live. Off ⇒ the reviewer bypasses grant nothing. */
    public static function reviewWindowOpen(): bool
    {
        return (bool) config('demo.review', false);
    }

    /**
     * Whether the app-store reviewer's bypasses are available at all.
     *
     * A demonstration server always allows them (the reviewer signs in to the demo
     * estate); a live server allows them only while a review window is open. With
     * both off — the launch default — `ReviewerMode::isReviewer()` answers false for
     * every account, so a stray `reviewer` row left in the database grants nothing.
     */
    public static function reviewerAllowed(): bool
    {
        return self::enabled() || self::reviewWindowOpen();
    }

    // `communityAdvertised()` went with the /apps page it answered for (M24, D-223): no public page advertises anything now.
}
