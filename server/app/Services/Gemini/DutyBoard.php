<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\Platform\OperatorEstates;
use App\Support\SiteTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHO IS ON DUTY, WHO IS NEXT, WHO IS AWAY — one board for every admin (the client's report C4, 26 Sep: "admin can
 * see which guard is on duty now, which is on leave, and what time [their shift] finishes").
 *
 * The coverage board answers "is this POST manned"; this answers the same question about PEOPLE, from the same
 * shift rows, in five lists:
 *
 *   on duty now          clocked in and not out: the post, when they clocked in, when the shift ends, on a break
 *   not clocked in       inside a rostered window with no clock-in yet — late, or not coming
 *   starting soon        rostered to start in the next twelve hours
 *   open shifts          the next twenty-four hours with nobody on them
 *   away today           on leave (an approved leave request covering today, or the record's status), or suspended
 *
 * Every time is on its estate's clock and named where that is not Gemini's own. Scoped like every Guard workforce
 * screen: a role over assigned sites sees those sites, and the demonstration estate is left out (M5 §3). No pay,
 * no position, no phone number: this is who and when.
 */
class DutyBoard
{
    /** How far ahead "starting soon" looks. */
    public const SOON_HOURS = 12;

    /** How far ahead an open shift counts as a gap to fill now. */
    public const OPEN_HOURS = 24;

    /** @return array{now: string, onDuty: list<array<string, mixed>>, notClockedIn: list<array<string, mixed>>, startingSoon: list<array<string, mixed>>, open: list<array<string, mixed>>, away: list<array<string, mixed>>} */
    public function for(User $viewer): array
    {
        $now = Carbon::now();

        $shifts = fn (): Builder => Shift::query()
            ->with(['officer', 'post', 'estate'])
            ->whereNotIn('status', Shift::SETTLED)
            ->when($viewer->widestScope() === AccessScope::AssignedSites, static fn ($q) => $q->whereIn('tenant_id', $viewer->accessibleEstateIds()))
            ->tap(static fn ($q) => OperatorEstates::exclude($q, 'shifts.tenant_id'));

        $onDuty = $shifts()->whereNotNull('actual_start')->whereNull('actual_end')->orderBy('rostered_end')->limit(200)->get();

        $notClockedIn = $shifts()->whereNotNull('guard_id')->whereNull('actual_start')
            ->where('rostered_start', '<=', $now)->where('rostered_end', '>', $now)
            ->orderBy('rostered_start')->limit(200)->get();

        $soon = $shifts()->whereNotNull('guard_id')->whereNull('actual_start')
            ->where('rostered_start', '>', $now)->where('rostered_start', '<=', $now->copy()->addHours(self::SOON_HOURS))
            ->orderBy('rostered_start')->limit(200)->get();

        $open = $shifts()->whereNull('guard_id')
            ->where('rostered_end', '>', $now)->where('rostered_start', '<=', $now->copy()->addHours(self::OPEN_HOURS))
            ->orderBy('rostered_start')->limit(200)->get();

        $onBreak = DB::connection('mysql')->table('shift_breaks')
            ->whereIn('shift_id', $onDuty->modelKeys())->whereNull('ended_at')->pluck('shift_id')->map(static fn ($id): int => (int) $id)->all();

        return [
            'now' => SiteTime::now()->format('g:i A, D j M').SiteTime::label(SiteTime::now()),
            'onDuty' => $onDuty->map(fn (Shift $s): array => [
                ...$this->row($s),
                'since' => SiteTime::fmt(SiteTime::local($s->actual_start, (string) $s->tenant_id), 'g:i A'),
                'ends' => $this->at($s->rostered_end, (string) $s->tenant_id).SiteTime::label(SiteTime::local($s->rostered_end, (string) $s->tenant_id)),
                'on_break' => in_array((int) $s->id, $onBreak, true),
                'overdue' => $s->rostered_end->lessThan($now),
            ])->values()->all(),
            'notClockedIn' => $notClockedIn->map(fn (Shift $s): array => [
                ...$this->row($s),
                'late_by' => $now->diffForHumans($s->rostered_start, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]),
            ])->values()->all(),
            'startingSoon' => $soon->map(fn (Shift $s): array => [
                ...$this->row($s),
                'in' => $now->diffForHumans($s->rostered_start, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]),
            ])->values()->all(),
            'open' => $open->map(fn (Shift $s): array => $this->row($s))->values()->all(),
            'away' => $this->away($viewer),
        ];
    }

    /** @return array{id: int, guard: string|null, guard_id: int|null, post: string, estate: string, window: string} */
    private function row(Shift $s): array
    {
        $tenant = (string) $s->tenant_id;

        return [
            'id' => (int) $s->id,
            'guard' => $s->officer?->full_name,
            'guard_id' => $s->guard_id === null ? null : (int) $s->guard_id,
            // "Main Gate · patrol" for a patrol shift, "Back Gate (Gate 5, Phase 5 Gate)" for a gate (M26, M27).
            'post' => $s->postLabel(),
            'estate' => (string) ($s->estate->name ?? $tenant),
            'window' => $this->at($s->rostered_start, $tenant).' – '.SiteTime::local($s->rostered_end, $tenant)->format('g:i A').SiteTime::label(SiteTime::local($s->rostered_end, $tenant)),
        ];
    }

    private function at(Carbon $instant, string $tenant): string
    {
        $local = SiteTime::local($instant, $tenant);

        return ($local->isSameDay(SiteTime::now($tenant)) ? 'Today' : $local->format('D j M')).', '.$local->format('g:i A');
    }

    /**
     * Officers away today: an APPROVED leave request whose dates cover today, or a record marked on leave or suspended.
     *
     * @return list<array{guard_id: int, guard: string, estate: string, why: string}>
     */
    private function away(User $viewer): array
    {
        $today = SiteTime::today()->toDateString();

        $guards = Guard::query()
            ->with('estate')
            ->when($viewer->widestScope() === AccessScope::AssignedSites, static fn ($q) => $q->whereIn('tenant_id', $viewer->accessibleEstateIds()))
            ->tap(static fn ($q) => OperatorEstates::exclude($q, 'guards.tenant_id'))
            ->where(static function ($q) use ($today): void {
                $q->whereIn('status', ['on_leave', 'suspended'])
                    ->orWhereIn('id', GuardRequest::query()
                        ->select('guard_id')
                        ->where('kind', GuardRequest::LEAVE)
                        ->where('status', 'approved')
                        ->whereDate('starts_on', '<=', $today)
                        ->whereDate('ends_on', '>=', $today));
            })
            ->orderBy('full_name')
            ->limit(200)
            ->get();

        $leave = GuardRequest::query()
            ->whereIn('guard_id', $guards->modelKeys())
            ->where('kind', GuardRequest::LEAVE)
            ->where('status', 'approved')
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->get()
            ->keyBy('guard_id');

        return $guards->map(static function (Guard $g) use ($leave): array {
            $request = $leave->get($g->id);

            return [
                'guard_id' => (int) $g->id,
                'guard' => (string) $g->full_name,
                'estate' => (string) ($g->estate->name ?? 'Not posted'),
                'why' => match (true) {
                    $request !== null => 'On leave until '.$request->ends_on->format('D j M').($request->subject ? ' · '.$request->subject : ''),
                    $g->status === 'suspended' => 'Suspended from duty',
                    default => 'Marked on leave',
                },
            ];
        })->values()->all();
    }
}
