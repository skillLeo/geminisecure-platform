<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Live\Events\GuardPositionLive;
use App\Live\LiveBus;
use App\Models\Guard;
use App\Models\Shift;
use App\Services\Audit\AuditLogger;
use App\Support\Geo;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WHERE AN OFFICER IS WHILE ON DUTY, AND ONLY THEN (M11 §3; D-174).
 *
 * The Guard App keeps a foreground service running from clock-in to clock-out ("On duty · location shared with
 * control room") and sends what it sampled here, in batches, with each fix's own capture time — a fix queued
 * offline for forty minutes arrives forty minutes old and is drawn that way. THIS SERVER, not the phone, decides
 * what is kept:
 *
 *   - the feature is on (`config('tracking.enabled')`), or nothing is;
 *   - the officer has consented to the CURRENT policy, or nothing is (a refusal is an HR matter, recorded here and
 *     shown in the control room as "position not shared" — never a crash, never a work-around);
 *   - the shift is this officer's, at this estate, and OPEN when the batch arrives: clocked in and not yet clocked
 *     out (Hassam, 12 October 2026; D-232). A batch for a shift that is not open is refused whole and nothing in it
 *     is stored, a fix taken during the shift and sent after the clock-out included. A privacy rule, not a check on
 *     the input: it is what keeps the company from holding an officer's movements on a day off, whatever a phone
 *     does. So "clock out and it stops" holds on the server even for a phone that did not stop;
 *   - each fix was taken after the clock-in, on the server's clock: the phone's time is corrected by the offset
 *     between its clock and the server's on this request, and a fix from before the shift is REFUSED, not stored;
 *   - a fix the phone resends (the same `id`) is stored once.
 *
 * `record()` is the one writer of `guard_positions`. An endpoint that takes positions hands them to it, with the
 * shift the phone named or `Shift::openFor()`, and the open shift is asked for here, never left to the endpoint.
 *
 * Kept for `config('tracking.retention_days')`, then deleted by `purge()` (gs:guard-positions-purge, nightly).
 */
class GuardTracking
{
    public const SOURCES = ['interval', 'movement', 'clock_in', 'checkpoint', 'gate', 'duress', 'clock_out'];

    /** Where a reading sent to `POST presence/positions` came from (GUARD_TRACKING.md 5.1). */
    public const READING_SOURCES = ['foreground', 'background', 'significant_change', 'geofence'];

    /**
     * A phone's clock this far from the server's, or a reading this far ahead of it, is stored and FLAGGED for a
     * supervisor (`clock_ahead`, `clock_behind`): never refused and never corrected (Hassam, 12 Oct 2026).
     */
    public const CLOCK_FLAG_AFTER_SECONDS = 120;

    public const GRANTED = 'granted';

    public const REFUSED = 'refused';

    /**
     * A fix further than this outside the estate's boundary is refused as impossible (M57.5 §4.5: the Security map drew
     * an officer in Pakistan). Generous on purpose: an officer sent along the perimeter road, or after somebody, is
     * kilometres short of it; a phone on another continent, or set to the wrong place, is not.
     */
    public const IMPOSSIBLE_OUTSIDE_M = 5_000;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * An estate's boundary from its site plan ([latitude, longitude] points), or null when none is recorded.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    public static function boundaryOf(string $tenantId): ?array
    {
        $raw = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $tenantId)->value('boundary');
        $points = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($points) && count($points) >= 3 ? array_values($points) : null;
    }

    public static function enabled(): bool
    {
        return (bool) config('tracking.enabled', true);
    }

    public static function policyVersion(): string
    {
        return (string) config('tracking.policy_version');
    }

    /**
     * What the Guard App needs to decide whether to ask and whether to start: the flag, the policy it must show,
     * this officer's answer to it, and how to sample.
     *
     * @return array<string, mixed>
     */
    public function status(Guard $guard): array
    {
        $consent = $this->consentOf((int) $guard->id);

        // HOW THIS PHONE SAMPLES IS ITS ESTATE'S (Hassam, 12 Oct 2026): the estate of the officer's shift, its own choice
        // or the platform's default, from the one reader (SiteTrackingSettings). The same keys as ever.
        $estate = $this->samplingEstateOf($guard);
        $sampling = $estate === null ? SiteTrackingSettings::defaults() : SiteTrackingSettings::for($estate);

        return [
            'enabled' => self::enabled(),
            'policy_version' => self::policyVersion(),
            'consent' => $consent === null ? null : [
                'decision' => $consent['decision'],
                'policy_version' => self::policyVersion(),
                'decided_at' => SiteTime::iso($consent['decided_at']),
            ],
            'retention_days' => (int) config('tracking.retention_days', 90),
            'sampling' => [
                // `interval_s` is the moving interval, as the Guard App has always read it.
                'interval_s' => $sampling['moving_interval_s'],
                'distance_m' => (int) config('tracking.sampling.distance_m', 25),
                'stationary_interval_s' => $sampling['stationary_interval_s'],
                'low_battery_pct' => $sampling['low_battery_pct'],
                'low_battery_interval_s' => $sampling['low_battery_interval_s'],
            ],
        ];
    }

    /**
     * The estate whose sampling this officer's phone follows: the shift they are clocked in to, else their next rostered
     * shift starting within a day, else none (the platform's defaults). A day, not "today", so no calendar is compared
     * across the server's clock and the estate's.
     */
    private function samplingEstateOf(Guard $guard): ?string
    {
        $inProgress = Shift::query()
            ->where('guard_id', $guard->id)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->orderByDesc('actual_start')
            ->value('tenant_id');

        if ($inProgress !== null) {
            return (string) $inProgress;
        }

        $next = Shift::query()
            ->where('guard_id', $guard->id)
            ->where('status', Shift::ROSTERED)
            ->whereNull('actual_start')
            ->whereBetween('rostered_start', [now(), now()->addDay()])
            ->orderBy('rostered_start')
            ->value('tenant_id');

        return $next === null ? null : (string) $next;
    }

    /**
     * The officer's answer to the current policy: the latest decision for THIS version, or null when they have not
     * answered it (an answer to an older version does not count — the words changed).
     *
     * @return array{decision: string, decided_at: Carbon}|null
     */
    public function consentOf(int $guardId): ?array
    {
        $row = DB::connection('mysql')->table('guard_tracking_consents')
            ->where('guard_id', $guardId)->where('policy_version', self::policyVersion())
            ->orderByDesc('id')->first(['decision', 'decided_at']);

        return $row === null ? null : ['decision' => (string) $row->decision, 'decided_at' => Carbon::parse((string) $row->decided_at)];
    }

    /**
     * Every consent answer for the current policy, for many officers in one read (the live map).
     *
     * @param  list<int>  $guardIds
     * @return array<int, string> guard id => granted|refused
     */
    public function consentsOf(array $guardIds): array
    {
        if ($guardIds === []) {
            return [];
        }

        $latest = DB::connection('mysql')->table('guard_tracking_consents')
            ->whereIn('guard_id', $guardIds)->where('policy_version', self::policyVersion())
            ->groupBy('guard_id')->selectRaw('MAX(id) as id');

        /** @var array<int, string> $decisions */
        $decisions = DB::connection('mysql')->table('guard_tracking_consents')->whereIn('id', $latest)
            ->pluck('decision', 'guard_id')->mapWithKeys(static fn ($d, $g): array => [(int) $g => (string) $d])->all();

        return $decisions;
    }

    /** Record the officer's answer to the current policy. Append-only: a changed mind is a new row. */
    public function decide(Guard $guard, string $decision, string $policyVersion, DeviceContext $context): void
    {
        if (! in_array($decision, [self::GRANTED, self::REFUSED], true)) {
            throw ApiError::unprocessable('invalid_decision', 'Answer `granted` or `refused`.');
        }

        if ($policyVersion !== self::policyVersion()) {
            // The phone showed words the server no longer asks about: it must show the current ones first.
            throw ApiError::conflict('policy_changed', 'The location policy has changed since this phone showed it. Read the new one and answer again.');
        }

        DB::connection('mysql')->transaction(function () use ($guard, $decision, $context): void {
            DB::connection('mysql')->table('guard_tracking_consents')->insert([
                'guard_id' => $guard->id,
                'policy_version' => self::policyVersion(),
                'decision' => $decision,
                'decided_at' => $context->serverTime,
                'device_time' => $context->deviceTime,
            ]);

            $this->audit->record(
                action: $decision === self::GRANTED ? 'guard.location_sharing_granted' : 'guard.location_sharing_refused',
                entityType: 'Guard',
                entityId: (string) $guard->id,
                after: ['policy_version' => self::policyVersion(), 'decision' => $decision],
                tenantId: $context->tenantId,
            );
        });
    }

    /**
     * Keep what the phone sampled on this shift, and say which fixes were kept and which were refused and why: every
     * one of them refused, and nothing stored, while the shift is not open (D-232).
     *
     * @param  list<array<string, mixed>>  $positions  validated by the controller
     * @return array{accepted: list<string>, refused: list<array{id: string, code: string, message: string}>}
     */
    public function record(Guard $guard, Shift $shift, array $positions, DeviceContext $context, bool $simulated = false): array
    {
        if (! self::enabled()) {
            throw ApiError::forbidden('tracking_off', 'Location sharing is switched off for this service. Nothing was recorded.');
        }

        if (($this->consentOf((int) $guard->id)['decision'] ?? null) !== self::GRANTED) {
            throw ApiError::forbidden('consent_required', 'This officer has not agreed to the current location policy, so no position is recorded. The control room shows "position not shared".');
        }

        if ((string) $shift->tenant_id !== $context->tenantId) {
            throw ApiError::notFound('not_found', 'No such shift.');
        }

        if ((int) $shift->guard_id !== (int) $guard->id) {
            throw ApiError::forbidden('not_your_shift', 'This shift is rostered to another guard. A handset shares its own guard\'s position.');
        }

        /*
         * NO OPEN SHIFT, NOTHING KEPT (D-232). The shift must be open as the batch arrives (`Shift::isUnderway()`): not
         * clocked in, or clocked out, and every fix is refused, a fix taken during the shift included. Refused in the
         * answer, not with an error: the Guard App drops a refused fix and never sends it again, where an error it does
         * not know has it send the same batch for ever.
         */
        $start = $shift->actual_start;

        if ($start === null || $shift->actual_end !== null) {
            return ['accepted' => [], 'refused' => $this->noOpenShift($positions, clockedOut: $start !== null)];
        }

        $now = $context->serverTime;
        $tolerance = (int) config('tracking.shift_tolerance_seconds', 120);

        // The phone's clock against the server's, on this request: how far its capture times must be moved.
        $offset = $context->deviceTime === null ? 0 : (int) $now->getTimestamp() - (int) $context->deviceTime->getTimestamp();

        $accepted = [];
        $refused = [];
        $rows = [];

        // The estate's own boundary, from its site plan (M57.5 §4.5): a fix kilometres outside it is not from this shift.
        $boundary = self::boundaryOf((string) $shift->tenant_id);

        foreach ($positions as $position) {
            $id = (string) $position['id'];
            $device = Carbon::parse((string) $position['captured_at']);
            $captured = $device->copy()->addSeconds($offset)->utc();
            $outside = $boundary === null ? 0.0 : Geo::metresOutside((float) $position['latitude'], (float) $position['longitude'], $boundary);

            // The shift is open, so it has no clock-out yet: a fix can fall only before its clock-in, or in the future.
            $why = match (true) {
                $captured->lt($start->copy()->subSeconds($tolerance)) => ['outside_shift', 'Taken before this shift\'s clock-in. A position is recorded only between clock-in and clock-out.'],
                $captured->gt($now->copy()->addSeconds($tolerance)) => ['in_future', 'Its time is later than the server\'s clock, so it cannot be placed.'],
                $outside > self::IMPOSSIBLE_OUTSIDE_M => ['off_estate', sprintf('Taken %s km outside the estate\'s boundary, so it cannot be from a shift here. It was not recorded.', number_format($outside / 1000, $outside >= 10_000 ? 0 : 1))],
                default => null,
            };

            if ($why !== null) {
                $refused[] = ['id' => $id, 'code' => $why[0], 'message' => $why[1]];

                continue;
            }

            $accepted[] = $id;
            $rows[] = [
                'guard_id' => $guard->id,
                'shift_id' => $shift->id,
                'tenant_id' => (string) $shift->tenant_id,
                'client_id' => Str::limit($id, 64, ''),
                'latitude' => round((float) $position['latitude'], 7),
                'longitude' => round((float) $position['longitude'], 7),
                'accuracy_m' => isset($position['accuracy_m']) ? min(65535, (int) $position['accuracy_m']) : null,
                'source' => (string) $position['source'],
                'battery_pct' => isset($position['battery_pct']) ? (int) $position['battery_pct'] : null,
                'captured_at' => $captured,
                'device_captured_at' => $device->copy()->utc(),
                'received_at' => $now,
                'is_simulated' => $simulated,
            ];
        }

        if ($rows !== [] && ! $this->keep($rows, $guard, $shift, $simulated)) {
            return ['accepted' => [], 'refused' => $this->noOpenShift($positions, clockedOut: true)];
        }

        return ['accepted' => $accepted, 'refused' => $refused];
    }

    /**
     * THE ONE WRITER OF `guard_positions` (D-232): both addresses a phone sends positions to, `record()` and
     * `recordBatch()`, hand their rows here, and nothing else in the application writes the table.
     *
     * The shift is asked again under its own row lock, from the database and not the model the batch was read with: a
     * clock-out that landed while the batch was being read is not slipped past, and one that waits on this lock finds the
     * batch kept while the shift was still open. A resent reading (the same client id) is kept once, and the rest of the
     * batch still lands. Something kept is announced on the live channels (a reference, never the position), and the
     * shift's No signal alert, if one is open, clears itself (GuardSignal).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return bool false when the shift was no longer open, so nothing was kept
     */
    private function keep(array $rows, Guard $guard, Shift $shift, bool $simulated): bool
    {
        return DB::connection('mysql')->transaction(function () use ($rows, $guard, $shift, $simulated): bool {
            $open = DB::connection('mysql')->table('shifts')->where('id', $shift->id)
                ->whereNotNull('actual_start')->whereNull('actual_end')->lockForUpdate()->value('id');

            if ($open === null) {
                return false;
            }

            $stored = DB::connection('mysql')->table('guard_positions')->insertOrIgnore($rows);

            if ($stored > 0) {
                LiveBus::record(GuardPositionLive::moved((int) $guard->id, (string) $shift->tenant_id, $simulated));
                app(GuardSignal::class)->arrived((int) $shift->id);
            }

            return true;
        });
    }

    /**
     * WHAT THE PHONE SENT TO `POST presence/positions` (Hassam, 12 Oct 2026; GUARD_TRACKING.md 5.1), answered reading by
     * reading: `accepted`, `duplicate` (already stored: stored once, never twice) or `rejected` with its reason.
     *
     *   - NO OPEN SHIFT, NOTHING STORED. The guard's shift at this estate must be clocked in and not out when the batch
     *     ARRIVES, or every reading is rejected (`no_open_shift`). A privacy rule, not a validation rule: a position off
     *     shift is nobody's business. So a phone sends its queue before the clock-out.
     *   - Taken before the clock-in (judged on the phone's time moved by the batch's measured clock difference, which
     *     is used for this judgement only and never written over the reading): `before_shift`.
     *   - More than 5 km outside the estate's boundary (M57.5 §4.5): `impossible_place`.
     *   - A phone clock more than two minutes off the server's, or a reading timed more than two minutes ahead of it:
     *     STORED, with `clock_flag` for a supervisor, and `captured_at` exactly as the phone sent it.
     *
     * With the batch, what the phone says about its own queue (`backlog`, `thinned`), kept per shift for the dispatcher
     * while the shift is open (`reportQueue`).
     *
     * @param  list<array<string, mixed>>  $positions  validated by the controller
     * @param  array{backlog: array<string, mixed>|null, thinned: array<string, mixed>|null}|null  $queue
     * @return array{shift_id: int|null, results: list<array<string, string>>}
     */
    public function recordBatch(Guard $guard, array $positions, DeviceContext $context, bool $simulated = false, ?array $queue = null): array
    {
        if (! self::enabled()) {
            throw ApiError::forbidden('tracking_off', 'Location sharing is switched off for this service. Nothing was recorded.');
        }

        if (($this->consentOf((int) $guard->id)['decision'] ?? null) !== self::GRANTED) {
            throw ApiError::forbidden('consent_required', 'This officer has not agreed to the current location policy, so no position is recorded. The control room shows "position not shared".');
        }

        $now = $context->serverTime;
        $key = static fn (array $p): string => Str::limit((string) $p['idempotency_key'], 64, '');

        $shift = Shift::query()->where('guard_id', $guard->id)->where('tenant_id', $context->tenantId)
            ->whereNotNull('actual_start')->whereNull('actual_end')->where('actual_start', '<=', $now)
            ->latest('actual_start')->first();

        if ($shift === null) {
            return ['shift_id' => null, 'results' => array_map(static fn (array $p): array => [
                'idempotency_key' => $key($p),
                'result' => 'rejected',
                'reason' => 'no_open_shift',
                'message' => 'You are not clocked in, so this position was not recorded. A position is kept only between clock-in and clock-out.',
            ], $positions)];
        }

        // The phone's clock against the server's, on this request: a measurement kept beside each reading, never applied.
        $offset = $context->deviceTime === null ? null : (int) $now->getTimestamp() - (int) $context->deviceTime->getTimestamp();
        $clockIn = $shift->actual_start->copy()->subSeconds((int) config('tracking.shift_tolerance_seconds', 120));
        $boundary = self::boundaryOf((string) $shift->tenant_id);
        $known = array_flip(DB::connection('mysql')->table('guard_positions')->whereIn('client_id', array_map($key, $positions))->pluck('client_id')->all());

        $results = [];
        $rows = [];

        foreach ($positions as $position) {
            $id = $key($position);

            if (isset($known[$id])) {
                $results[] = ['idempotency_key' => $id, 'result' => 'duplicate'];

                continue;
            }

            $known[$id] = true;
            $device = Carbon::parse((string) $position['captured_at'])->utc();
            $onServerClock = $offset === null ? $device->copy() : $device->copy()->addSeconds($offset);
            $lat = (float) $position['lat'];
            $lng = (float) $position['lng'];
            $outside = $boundary === null ? 0.0 : Geo::metresOutside($lat, $lng, $boundary);

            $why = match (true) {
                $onServerClock->lt($clockIn) => ['before_shift', 'Taken before your clock-in, so it was not recorded. A position is kept only between clock-in and clock-out.'],
                $outside > self::IMPOSSIBLE_OUTSIDE_M => ['impossible_place', sprintf('Taken %s km outside the estate\'s boundary, so it cannot be from your shift here. It was not recorded.', number_format($outside / 1000, $outside >= 10_000 ? 0 : 1))],
                default => null,
            };

            if ($why !== null) {
                $results[] = ['idempotency_key' => $id, 'result' => 'rejected', 'reason' => $why[0], 'message' => $why[1]];

                continue;
            }

            $flag = match (true) {
                $device->gt($now->copy()->addSeconds(self::CLOCK_FLAG_AFTER_SECONDS)) => 'clock_ahead',
                $offset !== null && $offset < -self::CLOCK_FLAG_AFTER_SECONDS => 'clock_ahead',
                $offset !== null && $offset > self::CLOCK_FLAG_AFTER_SECONDS => 'clock_behind',
                default => null,
            };

            $results[] = ['idempotency_key' => $id, 'result' => 'accepted', ...($flag === null ? [] : ['flag' => $flag])];
            $rows[] = [
                'guard_id' => $guard->id,
                'shift_id' => $shift->id,
                'tenant_id' => (string) $shift->tenant_id,
                'client_id' => $id,
                'latitude' => round($lat, 7),
                'longitude' => round($lng, 7),
                'accuracy_m' => isset($position['accuracy_metres']) ? min(65535, (int) $position['accuracy_metres']) : null,
                'source' => (string) $position['source'],
                'battery_pct' => isset($position['battery_percent']) ? (int) $position['battery_percent'] : null,
                'is_moving' => isset($position['is_moving']) ? (bool) $position['is_moving'] : null,
                'captured_at' => $device,
                'device_captured_at' => $device,
                'clock_offset_s' => $offset,
                'clock_flag' => $flag,
                'received_at' => $now,
                'is_simulated' => $simulated,
            ];
        }

        // Through the one writer, under the shift's lock. Clocked out while this batch was read: nothing of it is kept.
        if ($rows !== [] && ! $this->keep($rows, $guard, $shift, $simulated)) {
            return ['shift_id' => null, 'results' => array_map(static fn (array $result): array => $result['result'] !== 'accepted' ? $result : [
                'idempotency_key' => $result['idempotency_key'],
                'result' => 'rejected',
                'reason' => 'no_open_shift',
                'message' => 'Your shift was clocked out as this arrived, so this position was not recorded. A position is kept only while its shift is open.',
            ], $results)];
        }

        if ($queue !== null) {
            $this->reportQueue($guard, $shift, $queue, $now);
        }

        return ['shift_id' => (int) $shift->id, 'results' => $results];
    }

    /**
     * WHAT THE PHONE HOLDS UNSENT (GUARD_TRACKING.md section 9; the Guard App's `backlog` and `thinned`): one row per
     * shift, written over with the newest batch that says, and only for a shift that is open. A phone sitting on a long
     * queue, or one that had to thin it, is an operational problem: the dispatcher's map reads it through GuardPins.
     *
     * @param  array{backlog: array<string, mixed>|null, thinned: array<string, mixed>|null}  $queue
     */
    private function reportQueue(Guard $guard, Shift $shift, array $queue, Carbon $now): void
    {
        $backlog = $queue['backlog'] ?? null;
        $thinned = $queue['thinned'] ?? null;

        if ($backlog === null && $thinned === null) {
            return;
        }

        $at = static fn (mixed $value): ?Carbon => is_string($value) && $value !== '' ? Carbon::parse($value)->utc() : null;

        DB::connection('mysql')->table('guard_queue_reports')->upsert([[
            'shift_id' => (int) $shift->id,
            'guard_id' => (int) $guard->id,
            'tenant_id' => (string) $shift->tenant_id,
            'waiting' => max(0, (int) ($backlog['waiting'] ?? 0)),
            'oldest_captured_at' => $at($backlog['oldest_captured_at'] ?? null),
            'thinned_count' => max(0, (int) ($thinned['count'] ?? 0)),
            'thinned_from' => $at($thinned['from'] ?? null),
            'thinned_to' => $at($thinned['to'] ?? null),
            'reported_at' => $now,
        ]], ['shift_id'], ['guard_id', 'tenant_id', 'waiting', 'oldest_captured_at', 'thinned_count', 'thinned_from', 'thinned_to', 'reported_at']);
    }

    /**
     * WHAT IS RECORDED ABOUT THIS GUARD (GUARD_TRACKING.md 5.2): today's shift (the open one, or the last one ended today
     * on the estate's clock) and every reading stored for it. Nobody is tracked without being able to see it.
     *
     * @return array{shift: array{id: int, clocked_in_at: string|null, clocked_out_at: string|null}|null, retention_days: int, positions: list<array<string, mixed>>}
     */
    public function ownRecord(Guard $guard, DeviceContext $context): array
    {
        $shift = Shift::query()->where('guard_id', $guard->id)->where('tenant_id', $context->tenantId)->whereNotNull('actual_start')
            ->where(static fn ($q) => $q->whereNull('actual_end')->orWhere('actual_end', '>=', SiteTime::today($context->tenantId)))
            ->orderByRaw('actual_end IS NULL DESC')->latest('actual_start')->first();

        $retention = (int) config('tracking.retention_days', 90);

        if ($shift === null) {
            return ['shift' => null, 'retention_days' => $retention, 'positions' => []];
        }

        $positions = DB::connection('mysql')->table('guard_positions')->where('shift_id', $shift->id)->where('guard_id', $guard->id)
            ->orderBy('captured_at')->orderBy('id')
            ->get(['captured_at', 'latitude', 'longitude', 'accuracy_m', 'source', 'clock_flag'])
            ->map(static fn (object $p): array => [
                'captured_at' => SiteTime::iso(Carbon::parse((string) $p->captured_at, 'UTC')),
                'lat' => (float) $p->latitude,
                'lng' => (float) $p->longitude,
                'accuracy_metres' => $p->accuracy_m === null ? null : (int) $p->accuracy_m,
                'source' => (string) $p->source,
                'flag' => $p->clock_flag,
            ])->all();

        return [
            'shift' => ['id' => (int) $shift->id, 'clocked_in_at' => SiteTime::iso($shift->actual_start), 'clocked_out_at' => SiteTime::iso($shift->actual_end)],
            'retention_days' => $retention,
            'positions' => $positions,
        ];
    }

    /**
     * The newest reading TAKEN on each of these shifts, with when the newest one ARRIVED (`arrived_at`), in one read:
     * what both maps draw and what the No signal watch judges.
     *
     * @param  list<int>  $shiftIds
     * @return array<int, object> shift id => the reading
     */
    public static function newestReadings(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        $newest = DB::connection('mysql')->table('guard_positions')->whereIn('shift_id', $shiftIds)
            ->groupBy('shift_id')->selectRaw('shift_id, MAX(captured_at) AS taken, MAX(received_at) AS arrived_at');

        $rows = DB::connection('mysql')->table('guard_positions as p')
            ->joinSub($newest, 'n', static fn ($join) => $join->on('n.shift_id', '=', 'p.shift_id')->on('n.taken', '=', 'p.captured_at'))
            ->orderBy('p.id')
            ->get(['p.id', 'p.shift_id', 'p.latitude', 'p.longitude', 'p.accuracy_m', 'p.source', 'p.battery_pct', 'p.is_moving', 'p.clock_flag', 'p.captured_at', 'p.received_at', 'n.arrived_at']);

        // Two readings taken in the same second: the later stored one stands.
        return $rows->keyBy('shift_id')->all();
    }

    /**
     * Every fix of a batch, refused because its shift is not open (D-232): not clocked in yet, or clocked out.
     *
     * @param  list<array<string, mixed>>  $positions
     * @return list<array{id: string, code: string, message: string}>
     */
    private function noOpenShift(array $positions, bool $clockedOut): array
    {
        $message = $clockedOut
            ? 'This shift has been clocked out, so nothing in this batch was recorded, not even a position taken during the shift. A position is kept only while its shift is open.'
            : 'This shift has not been clocked in, so nothing in this batch was recorded. A position is kept only while its shift is open.';

        return array_map(
            static fn (array $position): array => ['id' => (string) $position['id'], 'code' => 'no_open_shift', 'message' => $message],
            $positions,
        );
    }

    /**
     * The newest fix of each officer on these shifts — the live map's pins.
     *
     * @param  list<int>  $shiftIds
     * @return array<int, object{guard_id: int, shift_id: int, latitude: string, longitude: string, accuracy_m: int|null, source: string, captured_at: string}> shift id => fix
     */
    public function latestOn(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        $newest = DB::connection('mysql')->table('guard_positions')->whereIn('shift_id', $shiftIds)
            ->groupBy('shift_id')->selectRaw('MAX(id) as id');

        /** @var array<int, object{guard_id: int, shift_id: int, latitude: string, longitude: string, accuracy_m: int|null, source: string, captured_at: string}> $rows */
        $rows = DB::connection('mysql')->table('guard_positions')->whereIn('id', $newest)
            ->get(['guard_id', 'shift_id', 'latitude', 'longitude', 'accuracy_m', 'source', 'captured_at'])
            ->keyBy('shift_id')->all();

        return $rows;
    }

    /**
     * WHEN each of these shifts' newest fix was taken — never where (M22 §1.1). The alertness board asks whether
     * positions are still being recorded, and nothing more.
     *
     * @param  list<int>  $shiftIds
     * @return array<int, string> shift id => captured_at (UTC)
     */
    public function newestTakenOn(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        /** @var array<int, string> $times */
        $times = DB::connection('mysql')->table('guard_positions')->whereIn('shift_id', $shiftIds)
            ->groupBy('shift_id')->selectRaw('shift_id, MAX(captured_at) as taken')->pluck('taken', 'shift_id')
            ->mapWithKeys(static fn ($taken, $shift): array => [(int) $shift => (string) $taken])->all();

        return $times;
    }

    /** Delete every fix older than the retention window. Returns how many went. */
    public function purge(?Carbon $now = null): int
    {
        $cutoff = ($now ?? Carbon::now())->copy()->subDays(max(1, (int) config('tracking.retention_days', 90)));
        $deleted = 0;

        do {
            $ids = DB::connection('mysql')->table('guard_positions')->where('captured_at', '<', $cutoff)->orderBy('id')->limit(5_000)->pluck('id')->all();

            if ($ids !== []) {
                $deleted += DB::connection('mysql')->table('guard_positions')->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === 5_000);

        // The notices of a gap (M22 §1.1) go with the positions they were about.
        DB::connection('mysql')->table('tracking_gap_notices')->where('noticed_at', '<', $cutoff)->delete();

        return $deleted;
    }
}
