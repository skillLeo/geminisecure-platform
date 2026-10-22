<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\RequestInbox;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LEAVE, AND THE SHIFTS IT LEAVES WITHOUT A GUARD (M30 §8).
 *
 * Until now a guard's leave was approved in Dispatch → Requests and the roster did not change: he stayed on every
 * shift in those days, and nobody was told the gates would be empty. On the Schedule, approving a guard's leave says
 * first which of his shifts fall in those days, and the person approving chooses, every time, between taking him off
 * them (each then "Needs a guard", with the reason on it) and leaving them as they are. Nothing is taken off by itself:
 * the rule from the master reference, automation suggests and a human decides.
 *
 * The decision itself is RequestInbox's, the same one Dispatch → Requests makes (locked, audited, refused if somebody
 * decided first); each shift is taken off through `Roster::takeOff`, which tells his phone. All in one transaction.
 */
final class LeaveCover
{
    public function __construct(private readonly RequestInbox $inbox, private readonly Roster $roster) {}

    /**
     * The leave waiting for a decision for this client's guards, each with the shifts he holds in those days.
     *
     * @return list<array{id: int, guard: array{id: int, name: string}, subject: string, reason: string|null, starts_on: string, ends_on: string, days: string, shifts: list<array{id: int, text: string}>}>
     */
    public function waiting(string $client): array
    {
        $requests = GuardRequest::query()
            ->where('tenant_id', $client)
            ->where('kind', GuardRequest::LEAVE)
            ->where('status', 'pending')
            ->whereNotNull('starts_on')->whereNotNull('ends_on')
            ->whereDate('ends_on', '>=', SiteTime::today($client))
            ->orderBy('starts_on')
            ->limit(20)
            ->get();

        if ($requests->isEmpty()) {
            return [];
        }

        $guardIds = $requests->pluck('guard_id')->unique()->values()->all();
        $guards = Guard::query()->whereKey($guardIds)->get()->keyBy('id');

        // Every request's shifts in one read, over the span of them all; each request then takes its own days.
        [$spanFrom] = SiteTime::dayBounds((string) $requests->min(static fn (GuardRequest $r): string => (string) $r->starts_on?->toDateString()), $client);
        [, $spanTo] = SiteTime::dayBounds((string) $requests->max(static fn (GuardRequest $r): string => (string) $r->ends_on?->toDateString()), $client);
        $held = self::upcoming()->whereIn('guard_id', $guardIds)->where('rostered_start', '<', $spanTo)->where('rostered_end', '>', $spanFrom)->get()->groupBy('guard_id');

        $out = [];
        foreach ($requests as $request) {
            $guard = $guards->get($request->guard_id);
            if (! $guard instanceof Guard || $request->starts_on === null || $request->ends_on === null) {
                continue;
            }

            [$from] = SiteTime::dayBounds($request->starts_on->toDateString(), $client);
            [, $to] = SiteTime::dayBounds($request->ends_on->toDateString(), $client);
            $his = $held->get($guard->id, collect())->filter(static fn (Shift $s): bool => $s->rostered_start->lessThan($to) && $s->rostered_end->greaterThan($from))->values();

            $out[] = [
                'id' => (int) $request->id,
                'guard' => ['id' => (int) $guard->id, 'name' => (string) $guard->full_name],
                'subject' => (string) $request->subject,
                'reason' => $request->reason,
                'starts_on' => $request->starts_on->toDateString(),
                'ends_on' => $request->ends_on->toDateString(),
                'days' => $request->starts_on->format('D j M').($request->starts_on->isSameDay($request->ends_on) ? '' : ' to '.$request->ends_on->format('D j M')),
                'shifts' => $his->map(static fn (Shift $s): array => ['id' => (int) $s->id, 'text' => self::line($s)])->all(),
            ];
        }

        return $out;
    }

    /**
     * The shifts a guard holds that leave would leave without a guard: rostered, not clocked in, not yet started. A
     * shift already started is the guard's to finish, or a supervisor's to end.
     *
     * @return Builder<Shift>
     */
    private static function upcoming(): Builder
    {
        return Shift::query()->with('post')
            ->where('status', Shift::ROSTERED)
            ->whereNull('actual_start')
            ->where('rostered_start', '>', Carbon::now())
            ->orderBy('rostered_start');
    }

    /**
     * Decide one leave request; on approval, take him off those shifts if the person deciding chose to.
     *
     * @return int how many shifts he was taken off
     */
    public function decide(User $by, int $requestId, string $decision, ?string $note, bool $takeOff): int
    {
        $request = GuardRequest::query()->whereKey($requestId)->where('kind', GuardRequest::LEAVE)->first();

        if (! $request instanceof GuardRequest || ! $by->canAccessEstate((string) $request->tenant_id)) {
            throw new DomainException('That request no longer exists.');
        }

        $guard = Guard::query()->find($request->guard_id);
        $shifts = $guard instanceof Guard && $decision === 'approved' && $takeOff ? $this->shiftsIn($guard, $request) : [];
        $tenant = (string) $request->tenant_id;

        return DB::connection('mysql')->transaction(function () use ($by, $requestId, $decision, $note, $shifts, $guard, $tenant): int {
            try {
                $this->inbox->decide($by, $requestId, ['decision' => $decision, 'note' => $note]);
            } catch (RuntimeException $refused) {
                throw new DomainException($refused->getMessage());
            }

            // "Kemar Blake was taken off on 3 Oct because their leave was approved." (§8.3, with no pronoun assumed).
            $why = ($guard->full_name ?? 'The guard').' was taken off on '.SiteTime::local(Carbon::now(), $tenant)->format('j M').' because their leave was approved.';

            foreach ($shifts as $shift) {
                $this->roster->takeOff($shift, $why, $by);
            }

            return count($shifts);
        });
    }

    /**
     * The shifts the guard holds that touch the leave's days at the estate (`upcoming()`): what approving it would
     * leave without a guard.
     *
     * @return list<Shift>
     */
    private function shiftsIn(Guard $guard, GuardRequest $request): array
    {
        $tenant = (string) $request->tenant_id;
        [$from] = SiteTime::dayBounds((string) $request->starts_on?->toDateString(), $tenant);
        [, $to] = SiteTime::dayBounds((string) $request->ends_on?->toDateString(), $tenant);

        return self::upcoming()
            ->where('guard_id', $guard->id)
            ->where('rostered_start', '<', $to)
            ->where('rostered_end', '>', $from)
            ->get()
            ->all();
    }

    /** "Back Gate · Mon 5 Oct · 9:00 AM – 5:00 PM JA", as §8 draws a shift in the approval panel. */
    private static function line(Shift $shift): string
    {
        $tenant = (string) $shift->tenant_id;
        $start = SiteTime::local($shift->rostered_start, $tenant);
        $end = SiteTime::local($shift->rostered_end, $tenant);

        return $shift->postLabel().' · '.$start->format('D j M').' · '.$start->format('g:i A').' – '.$end->format('g:i A').($end->isSameDay($start) ? '' : ' '.$end->format('D')).SiteTime::label($end);
    }
}
