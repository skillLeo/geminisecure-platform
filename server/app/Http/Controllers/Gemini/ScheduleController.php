<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\LeaveCover;
use App\Services\Gemini\Schedule;
use App\Services\Gemini\ShiftClaims;
use App\Services\Gemini\WeekCopy;
use App\Support\NewScreenWords;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The Schedule (M30 §4) — one client's week of shifts, at `/guards/schedule`, beside the classic roster.
 *
 * Its writes on one shift (adding, changing, moving and removing it) are in `ShiftPanelController`; this is the read,
 * and copying last week into the week on screen (§4.6), which is said before it is done.
 */
class ScheduleController extends Controller
{
    public function show(Request $request, Schedule $schedule): Response
    {
        $client = $request->query('client');
        $week = $request->query('week');

        return inertia('Gemini/Operations/Schedule', [
            ...$schedule->board($request->user(), is_string($client) && $client !== '' ? $client : null, is_string($week) ? $week : null),
            'canWrite' => $request->user()->can('gemini.guard_workforce.update'),
            'writeBlockedReason' => 'Adding or changing a shift needs Guard workforce update access. You are able to read the schedule.',
            // Leave is decided where Dispatch → Requests decides it (dispatch update), and taking a guard off is the
            // workforce's: the leave panel acts only for a role that may do both.
            'canDecideLeave' => self::mayDecideLeave($request),
            // A guard's ask for an open shift (M35 §1): the Roster tab opens this screen now, so it is answered here as
            // on the classic Roster, by the same routes.
            'claimsWaiting' => app(ShiftClaims::class)->waiting($request->user()),
        ]);
    }

    /**
     * Decide one leave request from the Schedule (M30 §8): approve, taking him off the shifts in those days if the
     * person chose to, or leaving them; or decline, with the reason the guard is told.
     */
    public function decideLeave(Request $request, int $guardRequest, LeaveCover $cover): RedirectResponse
    {
        abort_unless(self::mayDecideLeave($request), 403);

        $in = $request->validate([
            'decision' => ['required', 'in:approved,denied'],
            'note' => ['nullable', 'string', 'max:500'],
            'take_off' => ['nullable', 'boolean'],
        ]);

        try {
            $off = $cover->decide($request->user(), $guardRequest, (string) $in['decision'], $in['note'] ?? null, (bool) ($in['take_off'] ?? false));
        } catch (DomainException $refused) {
            return back()->withErrors(['leave' => NewScreenWords::say($refused->getMessage())]);
        }

        $said = match (true) {
            $in['decision'] === 'denied' => 'The leave is declined, and the guard is told why.',
            $off > 0 => 'The leave is approved. '.($off === 1 ? 'Its shift needs a guard now.' : $off.' shifts need a guard now.'),
            default => 'The leave is approved. Their shifts are as they were.',
        };

        return back()->with('success', $said);
    }

    private static function mayDecideLeave(Request $request): bool
    {
        return $request->user()->can('gemini.guard_workforce.update') && $request->user()->can('gemini.dispatch.update');
    }

    /** What "Copy last week into this week" would do, for the confirm panel. Nothing is written. */
    public function copyPlan(Request $request, WeekCopy $copier): JsonResponse
    {
        ['client' => $client, 'week' => $week] = $request->validate([
            'client' => ['required', 'string', 'max:64'],
            'week' => ['required', 'string', 'max:10'],
        ]);

        try {
            return response()->json($copier->plan($request->user(), $client, $week));
        } catch (DomainException $refused) {
            return response()->json(['message' => NewScreenWords::say($refused->getMessage())], 422);
        }
    }

    /** Copy it, exactly as the plan the person saw (its fingerprint) said. */
    public function copy(Request $request, WeekCopy $copier): RedirectResponse
    {
        ['client' => $client, 'week' => $week, 'fingerprint' => $fingerprint] = $request->validate([
            'client' => ['required', 'string', 'max:64'],
            'week' => ['required', 'string', 'max:10'],
            'fingerprint' => ['required', 'string', 'size:64'],
        ]);

        try {
            $made = $copier->copy($request->user(), $client, $week, $fingerprint);
        } catch (DomainException $refused) {
            return back()->withErrors(['copy' => NewScreenWords::say($refused->getMessage())]);
        }

        $count = count($made);

        return back()->with('success', ($count === 1 ? '1 shift' : $count.' shifts').' copied from last week.');
    }
}
