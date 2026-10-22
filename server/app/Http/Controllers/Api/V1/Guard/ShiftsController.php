<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Models\Shift;
use App\Services\Dispatch\ClockInFence;
use App\Services\Reviewer\ReviewerMode;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The guard's roster, pre-flight, breaks and open shifts (13 D2).
 *
 * Boards guard-app-01 (clock-in, dashboard), -05 (my hours), -06 (open shifts),
 * -10 (clock-in denied, flagged). Clocking on and off is `ShiftController`.
 *
 * INVARIANT 2. A shift carries a post, a site and times. No rate, no pay: hours
 * are reported as hours, and what they are worth is the payslip's.
 */
class ShiftsController extends Controller
{
    /** How early a guard may clock on before the rostered start. */
    private const EARLIEST_MINUTES = 60;

    /** The longest range `GET /shifts/me` answers, in days. */
    private const MAX_RANGE_DAYS = 31;

    /** How far ahead open shifts are offered. */
    private const OPEN_DAYS_AHEAD = 14;

    /** Rest a guard should have between shifts, for the claim warning. */
    private const REST_HOURS = 11;

    public function current(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();
        $now = Carbon::now();

        $shift = Shift::query()->with(['post', 'estate'])
            ->where('guard_id', $guard->id)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->orderByDesc('actual_start')
            ->first()
            ?? Shift::query()->with(['post', 'estate'])
                ->where('guard_id', $guard->id)
                ->whereNull('actual_start')
                ->where('rostered_end', '>=', $now)
                ->orderBy('rostered_start')
                ->first();

        if ($shift === null) {
            return response()->json(['shift' => null]);
        }

        $previousNote = Shift::query()
            ->where('post_id', $shift->post_id)
            ->where('id', '!=', $shift->id)
            ->whereNotNull('actual_end')
            ->whereNotNull('handover_note')
            ->orderByDesc('actual_end')
            ->value('handover_note');

        $openBreak = DB::connection('mysql')->table('shift_breaks')->where('shift_id', $shift->id)->whereNull('ended_at')->first();

        /*
         * WHO IS ON WITH YOU TODAY: each officer ONCE, on a shift that is running NOW (M21 §1.2, found on the
         * emulator). "Clocked in and not clocked out" alone listed an officer once per shift they had ever
         * forgotten to clock out of — the same name five times, and a guard from three nights ago as on duty.
         * A shift whose rostered end is more than two hours gone, or that began more than twenty hours ago, is
         * not "today" whatever its row says; the office closes it (Operations → On duty).
         */
        $colleagues = Shift::query()->with(['officer', 'post'])
            ->where('tenant_id', $shift->tenant_id)
            ->where('guard_id', '!=', $guard->id)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->where('actual_start', '>=', $now->copy()->subHours(20))
            ->where('rostered_end', '>=', $now->copy()->subHours(2))
            ->orderByDesc('actual_start')
            ->get()
            ->unique('guard_id')
            ->sortBy(static fn (Shift $s): string => sprintf('%010d', (int) $s->post_id).'|'.$s->officer?->full_name)
            ->map(static fn (Shift $s): array => ['name' => (string) $s->officer?->full_name, 'post' => (string) $s->post?->label()])
            ->values()
            ->all();

        return response()->json([
            'shift' => [
                ...$this->shape($shift),
                'on_break' => $openBreak !== null,
                'break_minutes' => $this->breakMinutes($shift),
                'previous_handover_note' => $previousNote,
                'orders_to_acknowledge' => $this->ordersToAcknowledge($guard),
                'colleagues' => $colleagues,
                // Cached with the shift, so a clock-in with no signal is judged against the fence (M12 §1.4).
                'fence' => $this->fenceShape($shift, ClockInFence::fenceFor($shift)),
                'supervisor_phone' => $this->supervisorPhone((string) $shift->tenant_id),
            ],
        ]);
    }

    public function mine(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        /*
         * `from` and `to` are CALENDAR DATES AT THE GUARD'S SITE (M4 §3.1): the range runs from
         * the first moment of `from` to the last moment of `to` on that site's clock, as two UTC
         * instants — a night shift starting 19:00 on the last day belongs to the range.
         */
        $from = SiteTime::dayBounds((string) $data['from'], $context->tenantId)[0];
        $to = SiteTime::dayBounds((string) $data['to'], $context->tenantId)[1];

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw ApiError::unprocessable('range_too_long', 'Ask for at most '.self::MAX_RANGE_DAYS.' days at a time.');
        }

        $shifts = Shift::query()->with(['post', 'estate'])
            ->where('guard_id', $guard->id)
            ->whereBetween('rostered_start', [$from, $to])
            ->orderBy('rostered_start')
            ->get();

        /*
         * EVERY SHIFT'S BREAKS IN ONE READ (M11 A2). A month of watches asked `shift_breaks`
         * once per shift to take its breaks off its hours; the same rows are now read together
         * and handed to each shift.
         */
        $breaks = $shifts->isEmpty()
            ? collect()
            : DB::connection('mysql')->table('shift_breaks')->whereIn('shift_id', $shifts->modelKeys())->get()->groupBy('shift_id');

        $items = $shifts->map(fn (Shift $s): array => [
            ...$this->shape($s),
            'worked_hours' => $this->workedHours($s, $breaks->get($s->id, collect())),
            'night_hours' => $this->nightHours($s),
        ])->all();

        return response()->json([
            'from' => substr(trim((string) $data['from']), 0, 10),
            'to' => substr(trim((string) $data['to']), 0, 10),
            'timezone' => SiteTime::zone($context->tenantId),
            'items' => $items,
            'totals' => [
                'worked_hours' => round(array_sum(array_column($items, 'worked_hours')), 2),
                'night_hours' => round(array_sum(array_column($items, 'night_hours')), 2),
                'shifts' => count($items),
            ],
        ]);
    }

    /**
     * Pre-flight before clock-in — boards guard-app-01, -10.
     *
     * ADVISORY, AND THE APP ACTS ON IT. `allowed: false` is what draws "Move
     * closer to clock in". The clock-in endpoint still records rather than
     * refuses, so a handset whose GPS is wrong can never leave a post reading as
     * unmanned; what it records is the distance and the flag, for a supervisor.
     */
    public function preflight(Request $request, int $shift, DeviceContext $context, ReviewerMode $reviewer): JsonResponse
    {
        $guard = $context->guardOrFail();
        $record = $this->theirs($shift, $guard);

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'mock_location' => ['nullable', 'boolean'],
            'device_uid' => ['nullable', 'string', 'max:120'],
        ]);

        $now = Carbon::now();
        $checks = [];

        $windowOpen = $now->greaterThanOrEqualTo($record->rostered_start->copy()->subMinutes(self::EARLIEST_MINUTES))
            && $now->lessThanOrEqualTo($record->rostered_end);
        $checks[] = ['key' => 'window', 'passed' => $windowOpen, 'detail' => $windowOpen
            ? 'Within the clock-in window.'
            : 'Clock-in opens '.self::EARLIEST_MINUTES.' minutes before the shift and closes when it ends.'];

        $deviceMatches = ($data['device_uid'] ?? null) === null || $guard->device_uid === null || $guard->device_uid === $data['device_uid'];
        $checks[] = ['key' => 'device', 'passed' => $deviceMatches, 'detail' => $deviceMatches
            ? 'This is the handset bound to you.'
            : 'This handset is not the one bound to you. Ask the office to rebind it.'];

        $post = $record->post;
        $fence = ClockInFence::fenceFor($record);

        /*
         * REVIEWER MODE (M5 §3): the geofence is satisfied from the SITE's surveyed
         * coordinates instead of the handset's, and each use is written to the audit log.
         *
         * An App Store or Play reviewer is thousands of miles from the gate and always will
         * be, so the honest thing is to stand the check at the post rather than to switch it
         * off: the same arithmetic runs, on the post's own position, and everything else on
         * this screen — the window, the binding, the mock-location flag — is judged exactly as
         * it is for anybody else. The substitution is IMPOSSIBLE for an account without the
         * flag: what follows reads the handset's `latitude`/`longitude` and nothing else.
         */
        $isReviewer = ReviewerMode::isReviewer($guard);

        if ($isReviewer && $fence !== null) {
            $data['latitude'] = $fence['latitude'];
            $data['longitude'] = $fence['longitude'];
            $data['accuracy_m'] = 0;
            $reviewer->record(ReviewerMode::GEOFENCE, $guard, ['shift' => $record->id, 'post' => $post->name ?? null]);
        }

        /*
         * THE GEOFENCE, BY THE ONE RULE (M12 §1, ClockInFence): the rostered post's fence, else the estate's, else none;
         * the fix a circle, not a point; a position that could not be established said to be exactly that.
         */
        $verdict = ClockInFence::judge(
            $fence,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            isset($data['accuracy_m']) ? (int) $data['accuracy_m'] : null,
        );
        $checks[] = ['key' => 'geofence', 'passed' => $verdict['allowed'], 'detail' => ClockInFence::sentence($verdict, $post?->label() ?? 'your post')];
        $distance = $verdict['distance_m'];
        // As before M12 for an app already in the field: no position is not within; a poor fix and no fence are not known.
        $within = match ($verdict['outcome']) {
            ClockInFence::INSIDE, ClockInFence::INSIDE_MARGIN => true,
            ClockInFence::OUTSIDE, ClockInFence::NO_POSITION => false,
            default => null,
        };

        $mock = (bool) ($data['mock_location'] ?? false);
        $checks[] = ['key' => 'location_integrity', 'passed' => ! $mock, 'detail' => $mock
            ? 'This device\'s location settings appear altered. You can still clock in; it will be flagged for review.'
            : 'No mock location detected.'];

        $blocking = array_filter($checks, static fn (array $c): bool => ! $c['passed'] && $c['key'] !== 'location_integrity');

        /*
         * The BIOMETRIC bypass (M5 §3), issued by the server and logged every time it is.
         *
         * A review handset has no fingerprint or face enrolled, and M4 §3.4 sends a phone
         * that cannot confirm anybody to the supervisor override — a PIN the reviewer has no
         * way to obtain. So for a flagged account, and only for one, the server says the
         * phone's own PASSCODE is an acceptable confirmation. An ordinary handset is told
         * `false` and stays on the override path, which is the whole point of the ruling.
         */
        if ($isReviewer) {
            $reviewer->record(ReviewerMode::PASSCODE_IDENTITY, $guard, ['shift' => $record->id]);
        }

        return response()->json([
            'shift_id' => $record->id,
            'allowed' => $blocking === [],
            'flagged_for_review' => $mock,
            'distance_m' => $distance,
            'radius_m' => $verdict['radius_m'],
            'within_geofence' => $within,
            'reviewer' => $isReviewer,
            'passcode_identity' => $isReviewer,
            'checks' => $checks,

            // M12 §1: the whole judgement, so the refusal screen can name the post, the distance and the way to go.
            'outcome' => $verdict['outcome'],
            'fence' => $this->fenceShape($record, $fence),
            'beyond_m' => $verdict['beyond_m'],
            'accuracy_m' => $verdict['accuracy_m'],
            'margin_m' => $verdict['margin_m'],
            'bearing_deg' => $verdict['bearing_deg'],
            'direction' => $verdict['direction'],
            'override_reason' => $verdict['override_reason'],
            'supervisor_phone' => $this->supervisorPhone((string) $record->tenant_id),
        ]);
    }

    /**
     * The fence a clock-in at this shift is judged against, as the phone caches it for a clock-in with no signal
     * (M12 §1.4), with the thresholds the rule uses — so the phone's judgement and the server's are the same sums.
     *
     * @param  array{source: string, name: string, latitude: float, longitude: float, radius_m: int}|null  $fence
     * @return array<string, mixed>
     */
    private function fenceShape(Shift $shift, ?array $fence): array
    {
        return [
            'source' => $fence['source'] ?? ClockInFence::SOURCE_NONE,
            'name' => $fence['name'] ?? ($shift->post?->label() ?? ''),
            'latitude' => $fence['latitude'] ?? null,
            'longitude' => $fence['longitude'] ?? null,
            'radius_m' => $fence['radius_m'] ?? null,
            'good_accuracy_m' => ClockInFence::GOOD_ACCURACY_M,
            'poor_accuracy_m' => ClockInFence::POOR_ACCURACY_M,
            'max_margin_m' => ClockInFence::MAX_MARGIN_M,
            'margin_per_accuracy' => ClockInFence::MARGIN_PER_ACCURACY,
        ];
    }

    /** The number a guard at the gate calls for a supervisor — set per estate in Sites & geofences, or null. */
    private function supervisorPhone(string $tenantId): ?string
    {
        $phone = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $tenantId)->value('supervisor_phone');

        return is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    }

    public function breakStart(int $shift, DeviceContext $context): JsonResponse
    {
        $record = $this->theirs($shift, $context->guardOrFail());

        if ($record->actual_start === null || $record->actual_end !== null) {
            throw ApiError::conflict('shift_not_on_duty', 'A break is taken during a shift you are clocked on to.');
        }

        $open = DB::connection('mysql')->table('shift_breaks')->where('shift_id', $record->id)->whereNull('ended_at')->first();

        $id = $open->id ?? DB::connection('mysql')->table('shift_breaks')->insertGetId([
            'shift_id' => $record->id,
            'started_at' => $context->serverTime,
            'device_started_at' => $context->deviceTime,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json($this->breakShape((int) $id), $open === null ? 201 : 200);
    }

    public function breakEnd(int $shift, DeviceContext $context): JsonResponse
    {
        $record = $this->theirs($shift, $context->guardOrFail());

        $open = DB::connection('mysql')->table('shift_breaks')->where('shift_id', $record->id)->whereNull('ended_at')->orderByDesc('id')->first();

        if ($open === null) {
            throw ApiError::conflict('no_break_open', 'There is no break open on this shift to end.');
        }

        DB::connection('mysql')->table('shift_breaks')->where('id', $open->id)->update([
            'ended_at' => $context->serverTime,
            'device_ended_at' => $context->deviceTime,
            'updated_at' => Carbon::now(),
        ]);

        return response()->json($this->breakShape((int) $open->id));
    }

    public function open(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();
        $now = Carbon::now();

        $mine = Shift::query()->where('guard_id', $guard->id)
            ->where('rostered_end', '>=', $now->copy()->subDay())
            ->get(['rostered_start', 'rostered_end']);

        $claims = DB::connection('mysql')->table('shift_claims')->where('guard_id', $guard->id)->pluck('status', 'shift_id');

        $items = Shift::query()->with(['post', 'estate'])
            ->whereNull('guard_id')
            ->where('tenant_id', $context->tenantId)
            ->whereBetween('rostered_start', [$now, $now->copy()->addDays(self::OPEN_DAYS_AHEAD)])
            ->orderBy('rostered_start')
            ->get()
            ->map(function (Shift $s) use ($mine, $claims): array {
                /*
                 * THE REST BETWEEN TWO SHIFTS IS THE GAP FROM THE FIRST'S END TO THE SECOND'S
                 * START, and when they overlap there is none (bug hunt B064). This took the
                 * distance end-to-start each way and threw the sign away, so two shifts over
                 * the same 07:00–19:00 read as twelve hours apart — and an open shift lying
                 * exactly on top of the guard's own, or containing it, warned of nothing.
                 */
                $restWarning = $mine->contains(static function (Shift $m) use ($s): bool {
                    if ($s->rostered_start->lessThan($m->rostered_end) && $m->rostered_start->lessThan($s->rostered_end)) {
                        return true;
                    }

                    $gapSeconds = $s->rostered_end->lessThanOrEqualTo($m->rostered_start)
                        ? $m->rostered_start->getTimestamp() - $s->rostered_end->getTimestamp()
                        : $s->rostered_start->getTimestamp() - $m->rostered_end->getTimestamp();

                    return $gapSeconds < self::REST_HOURS * 3600;
                });

                return [
                    'id' => $s->id,
                    // The post as the estate names it, and "· patrol" for a patrol shift (M26, M27).
                    'post' => ['id' => $s->post_id, 'name' => $s->postLabel()],
                    'site' => $this->site($s),
                    'rostered_start' => SiteTime::iso($s->rostered_start),
                    'rostered_end' => SiteTime::iso($s->rostered_end),
                    'hours' => round($s->rostered_start->diffInMinutes($s->rostered_end) / 60, 2),
                    'claim_status' => $claims[$s->id] ?? null,
                    'rest_warning' => $restWarning,
                ];
            })
            ->all();

        return response()->json(['items' => $items]);
    }

    public function claim(int $shift, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $open = Shift::query()->whereKey($shift)->where('tenant_id', $context->tenantId)->first();

        if ($open === null) {
            throw ApiError::notFound('not_found', 'No such open shift at your site.');
        }

        if ($open->guard_id !== null) {
            throw ApiError::conflict('shift_not_open', 'This shift has already been filled.');
        }

        if ($open->rostered_start->isPast()) {
            throw ApiError::conflict('shift_started', 'This shift has already started.');
        }

        $existing = DB::connection('mysql')->table('shift_claims')->where('shift_id', $open->id)->where('guard_id', $guard->id)->first();

        $id = $existing->id ?? DB::connection('mysql')->table('shift_claims')->insertGetId([
            'shift_id' => $open->id,
            'guard_id' => $guard->id,
            'status' => 'pending',
            'claimed_at' => $context->serverTime,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $claim = DB::connection('mysql')->table('shift_claims')->where('id', $id)->first();

        return response()->json([
            'claim_id' => (int) $claim->id,
            'shift_id' => (int) $claim->shift_id,
            'status' => (string) $claim->status,
            'claimed_at' => Carbon::parse((string) $claim->claimed_at)->toIso8601String(),
            'requires_approval' => true,
        ], $existing === null ? 201 : 200);
    }

    private function theirs(int $shiftId, Guard $guard): Shift
    {
        // The post is not eager-loaded (M11 A2): only the pre-flight reads it, and it reads it
        // itself; a break started or ended does not need the post at all.
        $shift = Shift::query()->find($shiftId);

        /*
         * ANOTHER ESTATE'S SHIFT IS NOT A SHIFT. `shifts` is a central table, so a bare
         * `find()` reaches every estate on the platform, and answering 403 for one that
         * exists while answering 404 for one that does not told a handset which shift ids are
         * real ANYWHERE — a roster is enumerable one number at a time from a single estate's
         * gate. The tenant is the token's (`Guard::tenant_id`), never the request's.
         *
         * `403 not_your_shift` stays exactly as documented for a shift at the guard's OWN
         * site rostered to a colleague: that is dispatch telling them whose shift it is, not
         * one community learning about another.
         */
        if ($shift === null || (string) $shift->tenant_id !== (string) $guard->tenant_id) {
            throw ApiError::notFound('not_found', 'No such shift.');
        }

        if ($shift->guard_id !== $guard->id) {
            throw ApiError::forbidden('not_your_shift', 'This shift is rostered to another guard.');
        }

        return $shift;
    }

    /** @return array<string, mixed> */
    private function shape(Shift $s): array
    {
        return [
            'id' => $s->id,
            'status' => (string) $s->status,
            // "Back Gate (Gate 5, Phase 5 Gate)"; "Main Gate · patrol" — the post the guard clocks in at (M26, M27).
            'post' => ['id' => $s->post_id, 'name' => $s->postLabel()],
            'site' => $this->site($s),
            'rostered_start' => SiteTime::iso($s->rostered_start),
            'rostered_end' => SiteTime::iso($s->rostered_end),
            'actual_start' => SiteTime::iso($s->actual_start),
            'actual_end' => SiteTime::iso($s->actual_end),
        ];
    }

    /**
     * The site a shift is at, WITH ITS ZONE (M4 §3.1). Every time in this API is a UTC instant;
     * `timezone` is the IANA zone the app shows them in — this shift's site, not the handset's.
     *
     * @return array{id: string, name: string, timezone: string}
     */
    private function site(Shift $s): array
    {
        return [
            'id' => (string) $s->tenant_id,
            'name' => (string) ($s->estate->name ?? $s->tenant_id),
            'timezone' => SiteTime::zone((string) $s->tenant_id),
        ];
    }

    /** @return array<string, mixed> */
    private function breakShape(int $id): array
    {
        $b = DB::connection('mysql')->table('shift_breaks')->where('id', $id)->first();

        return [
            'break_id' => (int) $b->id,
            'shift_id' => (int) $b->shift_id,
            'started_at' => Carbon::parse((string) $b->started_at)->toIso8601String(),
            'ended_at' => $b->ended_at === null ? null : Carbon::parse((string) $b->ended_at)->toIso8601String(),
        ];
    }

    /**
     * The minutes of break taken on this shift.
     *
     * `$breaks` is this shift's own `shift_breaks` rows when the caller has already read them
     * for a whole list of shifts (M11 A2); left out, they are read here, as before.
     *
     * @param  Collection<int, object>|null  $breaks
     */
    private function breakMinutes(Shift $shift, ?Collection $breaks = null): int
    {
        $breaks ??= DB::connection('mysql')->table('shift_breaks')->where('shift_id', $shift->id)->get();

        return (int) $breaks
            ->sum(static fn (object $b): int => (int) Carbon::parse((string) $b->started_at)->diffInMinutes($b->ended_at === null ? Carbon::now() : Carbon::parse((string) $b->ended_at)));
    }

    /** @param  Collection<int, object>|null  $breaks  see `breakMinutes()` */
    private function workedHours(Shift $s, ?Collection $breaks = null): float
    {
        if ($s->actual_start === null) {
            return 0.0;
        }

        $end = $s->actual_end ?? Carbon::now();

        return round(max(0, $s->actual_start->diffInMinutes($end) - $this->breakMinutes($s, $breaks)) / 60, 2);
    }

    /** Hours worked between 22:00 and 06:00 — reported, not priced. */
    private function nightHours(Shift $s): float
    {
        if ($s->actual_start === null) {
            return 0.0;
        }

        // 22:00–06:00 is a rule ON THE SITE'S CLOCK — the zone of the estate the shift is at, by
        // name, never an offset (M4 §3.1). The server keeps UTC, where that window is elsewhere.
        $cursor = SiteTime::local($s->actual_start, (string) $s->tenant_id)->startOfMinute();
        $end = ($s->actual_end ?? Carbon::now())->copy();

        $minutes = self::nightMinutesCounted($cursor, $end) ?? self::nightMinutesWalked($cursor->copy(), $end);

        return round($minutes / 60, 2);
    }

    /**
     * THE NIGHT MINUTES BY ARITHMETIC, where the site's clock keeps one offset throughout (M11 A2).
     *
     * `nightMinutesWalked()` is the rule: from `$from` (a whole minute on the site's clock) one
     * minute at a time while before `$end`, counting each minute whose hour on that clock is 22
     * to 23 or 0 to 5. That is 720 steps of date arithmetic for a twelve-hour watch, and `GET
     * /shifts/me` over a month of them spent three hundred milliseconds doing nothing else — past
     * the whole endpoint's p95 target on its own.
     *
     * While the zone's offset does not change, every step is exactly sixty seconds on both the
     * wall and the instant, so the steps are minutes `M0 … M0+n-1` of the site's own minute
     * count and the night ones are the minutes of each day below 06:00 or from 22:00 — counted
     * here in closed form over whole days and the two partial ones. Where the zone changes its
     * offset inside the interval (a daylight-saving change), or the zone cannot say, this answers
     * null and the walk runs instead, so the answer is the walk's in every case.
     */
    private static function nightMinutesCounted(Carbon $from, Carbon $end): ?int
    {
        $startMicro = $from->getTimestamp() * 1_000_000 + (int) $from->format('u');
        $endMicro = $end->getTimestamp() * 1_000_000 + (int) $end->format('u');

        if ($endMicro <= $startMicro) {
            return 0;
        }

        $transitions = $from->getTimezone()->getTransitions($from->getTimestamp(), $end->getTimestamp() + 1);

        // Exactly one entry: the offset in force at `$from`, and no change of it before `$end`.
        // (PHP answers false where the zone cannot say; `isset` reads that as no entry, too.)
        if (! isset($transitions[0]) || isset($transitions[1])) {
            return null;
        }

        $local = $from->getTimestamp() + (int) $transitions[0]['offset'];

        // The walk starts on a whole minute of the site's clock; anything else is not this case.
        if ($local < 0 || $local % 60 !== 0 || (int) $from->format('u') !== 0) {
            return null;
        }

        // Steps k = 0 … n-1 run while `from + 60k < end`, to the microsecond.
        $steps = intdiv($endMicro - $startMicro + 60_000_000 - 1, 60_000_000);
        $firstMinute = intdiv($local, 60);

        return self::nightMinutesBefore($firstMinute + $steps) - self::nightMinutesBefore($firstMinute);
    }

    /** How many of the site-clock minutes 0 … $minute-1 fall between 22:00 and 06:00. */
    private static function nightMinutesBefore(int $minute): int
    {
        $rest = $minute % 1440;

        return intdiv($minute, 1440) * 480 + min($rest, 360) + max(0, $rest - 1320);
    }

    /** The rule itself, a minute at a time — see `nightMinutesCounted()`. */
    private static function nightMinutesWalked(Carbon $cursor, Carbon $end): int
    {
        $minutes = 0;

        while ($cursor->lessThan($end)) {
            $hour = (int) $cursor->format('G');
            $minutes += ($hour >= 22 || $hour < 6) ? 1 : 0;
            $cursor->addMinute();
        }

        return $minutes;
    }

    private function ordersToAcknowledge(Guard $guard): int
    {
        if ($guard->post_id === null) {
            return 0;
        }

        $sets = DB::connection('mysql')->table('standing_order_sets')->where('post_id', $guard->post_id)->get(['id', 'version']);

        if ($sets->isEmpty()) {
            return 0;
        }

        /*
         * The guard's acknowledgements of THESE sets, read once (M11 A2) where each set asked on
         * its own. A set still counts when no acknowledgement names both it and its current
         * version — the same test, over rows in hand.
         */
        $acknowledged = DB::connection('mysql')->table('standing_order_acknowledgements')
            ->whereIn('standing_order_set_id', $sets->pluck('id')->all())
            ->where('guard_id', $guard->id)
            ->get(['standing_order_set_id', 'version'])
            ->map(static fn (object $ack): string => $ack->standing_order_set_id.':'.$ack->version)
            ->flip();

        return $sets->filter(static fn (object $set): bool => ! $acknowledged->has($set->id.':'.$set->version))->count();
    }
}
