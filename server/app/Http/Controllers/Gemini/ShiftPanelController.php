<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\Gemini\Roster;
use App\Services\Gemini\ShiftPlanner;
use App\Support\NewScreenWords;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Schedule's shift panel (M30 §5): asking what the server would say about a shift as the form changes, adding it —
 * with its guard, and repeated if asked — in one act, and, opened on a shift, changing it, taking its guard off, or
 * removing it. Reading one shift is the workforce's `view`; every write is `update`, as the roster's own writes are.
 * A shift outside the viewer's estates is not found, in the same words as one that does not exist.
 */
class ShiftPanelController extends Controller
{
    /** What the server would say about the shift the panel describes: the rule lines and the guards, free first. */
    public function check(Request $request, ShiftPlanner $planner): JsonResponse
    {
        return response()->json($planner->check($request->user(), $this->input($request)));
    }

    /**
     * Add the shift, or every shift of its repeat. The panel closes on success; a refusal comes back in its words, the
     * new screens' words where the classic roster's would say "duty" (NewScreenWords).
     */
    public function store(Request $request, ShiftPlanner $planner): RedirectResponse
    {
        try {
            $made = $planner->create($request->user(), $this->input($request));
        } catch (DomainException $refused) {
            return back()->withErrors(['panel' => NewScreenWords::say($refused->getMessage())]);
        }

        $count = count($made);

        return back()->with('success', $count === 1 ? 'Shift added.' : $count.' shifts added.');
    }

    /** One shift as the panel opens on it: its form, what can still change, its note and its history. */
    public function show(Request $request, Shift $shift, ShiftPlanner $planner): JsonResponse
    {
        $this->reach($request, $shift);

        return response()->json($planner->view($request->user(), $shift));
    }

    /** Change one shift: its gate, day, times, duty and guard, with the reason when a guard comes off it. */
    public function update(Request $request, Shift $shift, ShiftPlanner $planner): RedirectResponse
    {
        $this->reach($request, $shift);

        try {
            $planner->change($request->user(), $shift, $this->input($request));
        } catch (DomainException $refused) {
            return back()->withErrors(['panel' => NewScreenWords::say($refused->getMessage())]);
        }

        return back()->with('success', 'Shift changed.');
    }

    /** Take the guard off one shift, with the reason; it needs a guard again, and his phone is told. */
    public function takeOff(Request $request, Shift $shift, Roster $roster): RedirectResponse
    {
        $this->reach($request, $shift);

        try {
            $guard = $roster->takeOff($shift, $this->reason($request), $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['panel' => NewScreenWords::say($refused->getMessage())]);
        }

        return back()->with('success', $guard->full_name.' was taken off the shift. It needs a guard.');
    }

    /** Remove one shift that has not started, with the reason. */
    public function remove(Request $request, Shift $shift, Roster $roster): RedirectResponse
    {
        $this->reach($request, $shift);

        try {
            $roster->removeShift($shift, $this->reason($request), $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['panel' => NewScreenWords::say($refused->getMessage())]);
        }

        return back()->with('success', 'Shift removed.');
    }

    /** A shift at an estate the viewer does not reach is answered as one that does not exist. */
    private function reach(Request $request, Shift $shift): void
    {
        abort_unless($request->user()->canAccessEstate((string) $shift->tenant_id), 404);
    }

    private function reason(Request $request): string
    {
        return (string) $request->validate(['reason' => ['nullable', 'string', 'max:190']])['reason'];
    }

    /**
     * Shapes only; a field left empty is the planner's to answer in words ("Choose a date."), so the panel shows one
     * sentence for it rather than a validation failure.
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return $request->validate([
            'post_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'string', 'max:10'],
            'starts' => ['nullable', 'string', 'max:5'],
            'ends' => ['nullable', 'string', 'max:5'],
            'duty' => ['nullable', 'string', 'max:16'],
            'guard_id' => ['nullable', 'integer'],
            'repeat' => ['nullable', 'string', 'in:once,daily,days'],
            'until' => ['nullable', 'string', 'max:10'],
            'days' => ['nullable', 'array', 'max:7'],
            'days.*' => ['integer', 'between:1,7'],
            'shift_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);
    }
}
