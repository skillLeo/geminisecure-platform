<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Shift;
use App\Support\SiteTime;
use Carbon\CarbonInterface;

/**
 * The two rules M30 adds to the roster (§10), in one place, so the server enforces them for the classic roster and the
 * new schedule alike and the schedule's panel says exactly what the server will say:
 *
 *   ONE SHIFT AT A TIME. An officer on a shift still to come or under way cannot be put on another whose window
 *   overlaps it. Back to back is not an overlap: a watch ending at 6 PM and the next starting at 6 PM is one day.
 *
 *   NOT ON LEAVE. An officer with approved leave covering a day the shift touches, on the estate's calendar, cannot be
 *   put on it.
 */
final class ShiftConflicts
{
    /** The officer's shift that overlaps this window, if any. `$except` is the shift being changed, never its own clash. */
    public function clash(Guard $guard, CarbonInterface $from, CarbonInterface $to, ?int $except = null): ?Shift
    {
        return Shift::query()
            ->with('post')
            ->where('guard_id', $guard->id)
            ->when($except !== null, static fn ($q) => $q->whereKeyNot($except))
            ->whereNotIn('status', Shift::SETTLED)
            ->where('rostered_start', '<', $to)
            ->where('rostered_end', '>', $from)
            ->orderBy('rostered_start')
            ->first();
    }

    /** The officer's approved leave covering any day this window touches at the estate, if any. */
    public function leave(Guard $guard, CarbonInterface $from, CarbonInterface $to, string $tenantId): ?GuardRequest
    {
        $first = SiteTime::local($from, $tenantId)->toDateString();
        // The last day the shift is worked on: a shift ending at midnight does not touch the day after.
        $last = SiteTime::local($to->copy()->subSecond(), $tenantId)->toDateString();

        return GuardRequest::query()
            ->where('guard_id', $guard->id)
            ->where('kind', GuardRequest::LEAVE)
            ->where('status', 'approved')
            ->whereDate('starts_on', '<=', $last)
            ->whereDate('ends_on', '>=', $first)
            ->orderBy('starts_on')
            ->first();
    }

    /** "Main Gate, Wed 7 Oct, 6:00 AM to 6:00 PM JA" — a clashing shift, where and when, on its estate's clock. */
    public static function describe(Shift $shift): string
    {
        return $shift->postLabel().', '.self::when($shift);
    }

    /** "Wed 7 Oct, 6:00 AM to 6:00 PM JA"; "Tue 6 Oct, 6:00 PM to 6:00 AM Wed JA" for one that ends the next day. */
    public static function when(Shift $shift): string
    {
        $tenant = (string) $shift->tenant_id;
        $from = SiteTime::local($shift->rostered_start, $tenant);
        $to = SiteTime::local($shift->rostered_end, $tenant);

        return $from->format('D j M, g:i A').' to '.$to->format('g:i A').($to->isSameDay($from) ? '' : ' '.$to->format('D')).SiteTime::label($to);
    }
}
