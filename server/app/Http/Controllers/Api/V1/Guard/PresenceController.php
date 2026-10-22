<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\CheckpointScan;
use App\Models\PatrolCheckpoint;
use App\Models\Shift;
use App\Support\Geo;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alertness responses, on-post activity and the guard's own summary (13 D2).
 *
 * LOCATION IS USED, NOT KEPT. A presence ping may carry coordinates so the server
 * can say whether the guard is within the post's geofence; the coordinates are
 * used for that one comparison and not stored. What is stored is `within_geofence`
 * — the fact dispatch needs — and never a track of where a person walked.
 */
class PresenceController extends Controller
{
    public function respond(Request $request, int $check, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        // THE TAP IS THE WHOLE ANSWER. No score, no image, no reading of any kind is accepted here,
        // so none can be stored - whatever else a request carries is ignored (Legal review pack §7.10).
        $row = DB::connection('mysql')->table('alertness_checks')->where('id', $check)->where('guard_id', $guard->id)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No such alertness check for you.');
        }

        if ($row->outcome === 'passed') {
            return response()->json($this->checkShape($check));
        }

        if ($row->outcome !== 'pending' || ($row->respond_by !== null && Carbon::parse((string) $row->respond_by)->isPast())) {
            DB::connection('mysql')->table('alertness_checks')->where('id', $check)->where('outcome', 'pending')->update(['outcome' => 'missed']);

            throw ApiError::conflict('check_expired', 'This check closed before your response arrived, and is recorded as missed. Dispatch can see why if you tell them.');
        }

        DB::connection('mysql')->table('alertness_checks')->where('id', $check)->update([
            'outcome' => 'passed',
            'responded_at' => $context->serverTime,
            'device_time' => $context->deviceTime,
            'clock_skewed' => $context->clockSkewed(),
        ]);

        return response()->json($this->checkShape($check));
    }

    public function activity(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'state' => ['required', 'string', 'in:on_post,patrolling,on_break,away'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'battery_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $shift = Shift::query()->with('post')->where('guard_id', $guard->id)->whereNotNull('actual_start')->whereNull('actual_end')->latest('actual_start')->first();
        $post = $shift?->post;
        $within = null;

        if ($post !== null && $post->latitude !== null && $post->longitude !== null && isset($data['latitude'], $data['longitude'])) {
            $within = Geo::metresBetween((float) $post->latitude, (float) $post->longitude, (float) $data['latitude'], (float) $data['longitude']) <= $post->geofence_radius_m + (int) ($data['accuracy_m'] ?? 0);
        }

        $id = DB::connection('mysql')->table('guard_presence_pings')->insertGetId([
            'guard_id' => $guard->id,
            'shift_id' => $shift?->id,
            'state' => $data['state'],
            'accuracy_m' => $data['accuracy_m'] ?? null,
            'battery_pct' => $data['battery_pct'] ?? null,
            'within_geofence' => $within,
            'device_time' => $context->deviceTime,
            'server_time' => $context->serverTime,
            'is_simulated' => $request->boolean('simulated'),
        ]);

        $pending = DB::connection('mysql')->table('alertness_checks')->where('guard_id', $guard->id)->where('outcome', 'pending')->orderBy('id')->first();

        return response()->json([
            'ping_id' => $id,
            'shift_id' => $shift?->id,
            'state' => $data['state'],
            'within_geofence' => $within,
            'pending_alertness_check' => $pending === null ? null : $this->checkShape((int) $pending->id),
        ], 201);
    }

    public function summary(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        // "Today" began at midnight ON THE SITE'S CLOCK, not UTC's (M4 §3.1). Bound into a query it is that UTC moment.
        $today = SiteTime::today($context->tenantId);

        $shift = Shift::query()->where('guard_id', $guard->id)
            ->where(fn ($q) => $q->where('rostered_start', '>=', $today)->orWhere(fn ($q2) => $q2->whereNotNull('actual_start')->whereNull('actual_end')))
            ->orderBy('rostered_start')
            ->first();

        $onPost = 0;
        $breaks = 0;

        if ($shift?->actual_start !== null) {
            $onPost = (int) $shift->actual_start->diffInMinutes($shift->actual_end ?? Carbon::now());
            $breaks = (int) DB::connection('mysql')->table('shift_breaks')->where('shift_id', $shift->id)->get()
                ->sum(static fn (object $b): int => (int) Carbon::parse((string) $b->started_at)->diffInMinutes($b->ended_at === null ? Carbon::now() : Carbon::parse((string) $b->ended_at)));
        }

        $checks = DB::connection('mysql')->table('alertness_checks')->where('guard_id', $guard->id)->where('issued_at', '>=', $today)->pluck('outcome');

        // The same tour the Patrol tab walks (M21 §1.1): the guard's route when it has checkpoints, else the site's.
        $tour = PatrolCheckpoint::tourFor($guard->id, $context->tenantId);

        return response()->json([
            'date' => $today->toDateString(),
            'shift_id' => $shift?->id,
            'on_post_minutes' => max(0, $onPost - $breaks),
            'break_minutes' => $breaks,
            // Today's by when each checkpoint was REACHED, as the tour counts them (D-192).
            'checkpoints_scanned' => DB::connection('mysql')->table('checkpoint_scans')->where('guard_id', $guard->id)->whereIn('patrol_checkpoint_id', $tour)->whereRaw(CheckpointScan::REACHED_AT_SQL.' >= ?', [$today])->distinct()->count('patrol_checkpoint_id'),
            'checkpoints_total' => count($tour),
            'incidents_filed' => DB::connection('mysql')->table('security_incidents')->where('guard_id', $guard->id)->where('occurred_at', '>=', $today)->count(),
            'alertness_passed' => $checks->filter(static fn ($o) => $o === 'passed')->count(),
            'alertness_missed' => $checks->filter(static fn ($o) => $o === 'missed')->count(),
            'last_activity_at' => ($last = DB::connection('mysql')->table('guard_presence_pings')->where('guard_id', $guard->id)->max('server_time')) === null ? null : Carbon::parse((string) $last)->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function checkShape(int $id): array
    {
        $c = DB::connection('mysql')->table('alertness_checks')->where('id', $id)->first();

        return [
            'check_id' => (int) $c->id,
            'outcome' => (string) $c->outcome,
            'issued_at' => $c->issued_at === null ? null : Carbon::parse((string) $c->issued_at)->toIso8601String(),
            'respond_by' => $c->respond_by === null ? null : Carbon::parse((string) $c->respond_by)->toIso8601String(),
            'responded_at' => $c->responded_at === null ? null : Carbon::parse((string) $c->responded_at)->toIso8601String(),
        ];
    }
}
