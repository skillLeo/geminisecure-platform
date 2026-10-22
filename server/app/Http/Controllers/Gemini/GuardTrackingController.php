<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Models\Shift;
use App\Services\Tracking\GuardTrackRecord;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * THE TWO TRACKING SCREENS FROM A GUARD'S PAGE (Hassam, 12 October 2026), read only.
 *
 *   location sharing   the officer's answers to the location policy: the words, the button pressed, and when
 *   track              the positions held about them, shift by shift, as their own record on the Guard App reads them
 *
 * WHO SEES WHICH IS NOT DECIDED HERE. Each inherits, unchanged, the permission of the view that already shows the same
 * data (Hassam, 12 October, on another new view: "it inherits the existing permission exactly … We add nobody and we
 * remove nobody"): the answers are the guard page's own (`gemini.guard_workforce.view`), which already tells its readers
 * "position not shared"; the places are the live map's (`gemini.dispatch.view`), the only view today of where a guard is.
 * Both are refused, not found, for a guard outside the viewer's estates, as the guard page is; and the track lists only
 * the shifts at estates the viewer can reach.
 */
final class GuardTrackingController extends Controller
{
    public function consent(Request $request, Guard $guard, GuardTrackRecord $record): Response
    {
        $this->reachOrFail($request, $guard);

        return inertia('Gemini/Guards/LocationSharing', [
            'guard' => ['id' => (int) $guard->id, 'name' => (string) $guard->full_name],
            // Level 3: the track's link only for a role that holds the live map's permission.
            'canSeeTrack' => Gate::allows('gemini.dispatch.view'),
            ...$record->consentRecord($guard),
        ]);
    }

    public function track(Request $request, Guard $guard, GuardTrackRecord $record): Response
    {
        $this->reachOrFail($request, $guard);

        $user = $request->user();
        $shifts = $record->shiftsHeld($guard, static fn (string $tenant): bool => $user->canAccessEstate($tenant));

        // The shift asked for when it is one of this officer's, at an estate the viewer reaches; else the newest.
        $asked = (int) $request->query('shift', '0');
        $chosen = collect($shifts)->firstWhere('id', $asked) ?? ($shifts[0] ?? null);
        $shift = $chosen === null ? null : Shift::query()->with('post')->find($chosen['id']);
        $held = $shift === null ? null : $record->shiftRecord($shift);

        // Every reading of the shift, a page at a time, in the order it was taken (M32 F5).
        $table = TableQuery::from($request, ['taken' => 'captured_at'], 'taken', 'asc', 50);
        $page = $held === null ? null : $table->pageOf($held['positions']);

        return inertia('Gemini/Guards/Track', [
            'guard' => ['id' => (int) $guard->id, 'name' => (string) $guard->full_name],
            'shifts' => $shifts,
            'shift' => $held['shift'] ?? null,
            'retention_days' => (int) config('tracking.retention_days', 90),
            'rows' => $page === null ? [] : array_values($page->items()),
            'table' => $page === null ? $table->meta($table->pageOf([])) : $table->meta($page),
            // The whole shift on the map, every reading, whichever page of the table is open.
            'pins' => array_map(static fn (array $p): array => [
                'id' => 'reading-'.$p['id'],
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'title' => $p['taken'],
                'detail' => $p['why'].($p['accuracy_metres'] === null ? '' : ' · within '.$p['accuracy_metres'].' m'),
                'tone' => 'neutral',
            ], $held['positions'] ?? []),
        ]);
    }

    /** 404 rather than 403 for a guard posted where the viewer cannot see, as the guard page answers (GuardController). */
    private function reachOrFail(Request $request, Guard $guard): void
    {
        abort_unless($guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id), 404);
    }
}
