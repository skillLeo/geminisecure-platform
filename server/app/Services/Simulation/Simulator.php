<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Models\DuressAlert;
use App\Models\GateEvent;
use App\Models\Post;
use App\Models\Shift;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The event simulator behind `/simulator` — Part C of the web deliverable.
 *
 * WHAT IT IS FOR. Roughly forty web screens draw data the Guard App produces,
 * and the Guard App does not exist. This fires the events a handset would —
 * alerts, gate decisions, clock-ins — through the same /api/v1 endpoints the
 * app will call, so a reviewer can watch the dispatch queue fill, the live map
 * change and the coverage board turn green without a phone in the room. Every
 * row it causes is flagged `is_simulated`, and the source badge on each of
 * those screens says so.
 *
 * IT NEVER INSERTS A ROW. Every event goes through `Handset::post()` — the
 * kernel, the middleware, the ability, the validation — and a row the endpoint
 * refuses is a row that does not exist. That is the point: nothing built
 * against the simulator has to be rewritten when the real app arrives, because
 * the simulator IS a client of the real API.
 *
 * TWO MODES. Manual fires one chosen event, so a reviewer can put a specific
 * panic on a specific estate and watch it land. Ambient fires a burst drawn
 * from a FIXED SEED: the same seed always produces the same sequence of events
 * with the same idempotency keys, so pressing the button twice with seed 7 does
 * not double the queue — the endpoints return the rows they already made. A
 * different seed is a different night.
 *
 * WHAT IT REPORTS. Each event's HTTP status, in the words the endpoint answered
 * with. A 201 is a row the endpoint made; a 200 on an alert or a gate event is
 * the idempotent replay of one it had already made; anything else is the
 * endpoint refusing, and the refusal is shown rather than swallowed — a
 * simulator that reported "fired" over a 422 would be lying about the API.
 */
final class Simulator
{
    /** The kinds a handset can raise, as `AlertController` validates them. */
    public const ALERT_KINDS = ['panic', 'duress', 'medical', 'intrusion', 'fire'];

    /** Raised by a guard's own handset rather than a resident's phone. */
    private const GUARD_KINDS = ['duress', 'intrusion'];

    private const RESIDENTS = ['Andrea Fletcher', 'Devon Grant', 'Kayla Morrison', 'Rohan Peart'];

    /**
     * What arrives at a gated community, in roughly the proportions it does —
     * the same mix `simulate:gate` uses, because board 07's Visitor passes bar
     * is computed from the ratio of platform-issued passes to guard decisions.
     *
     * @var list<array{category: string, basis: string, verdict: string, reason?: string}>
     */
    public const ARRIVALS = [
        ['category' => 'Visitor', 'basis' => 'QR pass', 'verdict' => 'admit'],
        ['category' => 'Resident vehicle', 'basis' => 'Tag read', 'verdict' => 'admit'],
        ['category' => 'Delivery', 'basis' => 'Pre-approved', 'verdict' => 'admit'],
        ['category' => 'Visitor', 'basis' => 'QR pass', 'verdict' => 'admit'],
        ['category' => 'Contractor', 'basis' => 'Pre-approved', 'verdict' => 'admit'],
        ['category' => 'Visitor', 'basis' => 'Guard decision', 'verdict' => 'admit'],
        ['category' => 'Delivery', 'basis' => 'Guard decision', 'verdict' => 'deny'],
        ['category' => 'Contractor', 'basis' => 'Guard decision', 'verdict' => 'override',
            'reason' => 'Emergency plumbing call-out, authorised by the Property Manager'],
    ];

    private const SUBJECTS = [
        'Andrea Fletcher', 'D. Grant', 'Kayla Morrison', 'R. Peart', 'Sonia Campbell',
        'Island Courier Ltd', 'AquaTech Pool Services', 'PP 4821', 'PT 9034', 'Gate2 Contractor Ltd',
    ];

    /** The most an ambient burst may fire in one press. */
    public const AMBIENT_MAX = 50;

    public function __construct(private readonly Handset $handset) {}

    /* ------------------------------------------------------------------ */
    /* manual */
    /* ------------------------------------------------------------------ */

    /**
     * Raise one alert at one estate.
     *
     * @return array<string, mixed> one result row
     */
    public function alert(Tenant $estate, string $kind, bool $offline = false, ?string $key = null): array
    {
        return $this->asHandset($estate, function (string $tenantId) use ($estate, $kind, $offline, $key): array {
            $isGuardAlert = in_array($kind, self::GUARD_KINDS, true);
            $guard = $this->handset->guard();

            $response = $this->handset->post('/api/v1/alerts', [
                'tenant_id' => $tenantId,
                'kind' => $kind,
                'guard_id' => $isGuardAlert ? $guard?->id : null,
                'raised_by_name' => $isGuardAlert ? null : self::RESIDENTS[crc32($key ?? $kind) % count(self::RESIDENTS)],
                'unit_reference' => $isGuardAlert ? null : 'PP-'.(1 + crc32($key ?? $kind) % 4).'A',
                'captured_offline' => $offline,

                // An offline capture happened before it reached the server, so its
                // device time is nine minutes back. That is a QUEUED act, not a
                // wrong clock (bug hunt B066): the console shows it captured offline,
                // and `clock_skewed` stays false, as it would for a real handset.
                'device_time' => ($offline ? now()->subMinutes(9) : now())->toIso8601String(),

                /*
                 * A manual press is a NEW event each time — a reviewer pressing
                 * "raise a panic" twice wants two panics — so its key is fresh.
                 * Ambient passes a seeded key so its bursts replay instead.
                 */
                'idempotency_key' => $key ?? 'sim-web-manual-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(3)),
                'simulated' => true,
            ]);

            return $this->row('alert', ucfirst($kind).' at '.$estate->name, $response, 201);
        });
    }

    /**
     * Record one arrival at one estate's gate.
     *
     * @param  array{category: string, basis: string, verdict: string, reason?: string}  $arrival
     * @return array<string, mixed>
     */
    public function gateEvent(Tenant $estate, array $arrival, ?string $subject = null, ?string $key = null): array
    {
        return $this->asHandset($estate, function (string $tenantId) use ($estate, $arrival, $subject, $key): array {
            $guard = $this->handset->guard();
            $post = Post::where('tenant_id', $tenantId)->where('is_active', true)->orderBy('id')->first();

            $response = $this->handset->post('/api/v1/gate-events', [
                'tenant_id' => $tenantId,
                'verdict' => $arrival['verdict'],
                'category' => $arrival['category'],
                'subject' => $subject ?? self::SUBJECTS[crc32($key ?? $arrival['category']) % count(self::SUBJECTS)],
                'basis' => $arrival['basis'],
                'reason' => $arrival['reason'] ?? null,
                'guard_id' => $guard?->id,
                'post_id' => $post?->id,
                'device_time' => now()->toIso8601String(),
                'idempotency_key' => $key ?? 'sim-web-manual-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(3)),
                'simulated' => true,
            ]);

            return $this->row(
                'gate',
                ucfirst($arrival['verdict']).' — '.$arrival['category'].' on '.strtolower($arrival['basis']).' at '.$estate->name,
                $response,
                201,
            );
        });
    }

    /**
     * Clock today's roster on and yesterday's off, at one estate.
     *
     * What makes the coverage board say a post is MANNED and the live map say
     * a guard is at it. Every shift rostered to have started by now and not yet
     * clocked in is clocked in; every one whose rostered end has passed and is
     * still on duty is clocked out.
     *
     * @return list<array<string, mixed>>
     */
    public function changeShifts(Tenant $estate): array
    {
        $rows = [];
        $tenantId = (string) $estate->getTenantKey();

        /*
         * EACH SHIFT BY ITS OWN GUARD'S HANDSET (13 D1). A token clocks its own
         * guard's shifts, so the simulator borrows each rostered guard's handset
         * in turn — exactly as each guard's own phone would send it.
         */
        $starting = Shift::where('tenant_id', $tenantId)
            ->whereNull('actual_start')
            ->whereNotNull('guard_id')
            ->where('rostered_start', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($starting as $shift) {
            $rows[] = $this->asGuard($shift, 'Clock in — shift #'.$shift->id, "/api/v1/shifts/{$shift->id}/clock-in", [
                'method' => 'app',
                'geofence_distance_m' => 8 + ($shift->id % 30),
                'mock_location' => false,
                'simulated' => true,
            ]);
        }

        $ending = Shift::where('tenant_id', $tenantId)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->whereNotNull('guard_id')
            ->where('rostered_end', '<=', now())
            ->orderBy('id')
            ->get();

        foreach ($ending as $shift) {
            $rows[] = $this->asGuard($shift, 'Clock out — shift #'.$shift->id, "/api/v1/shifts/{$shift->id}/clock-out", []);
        }

        if ($rows === []) {
            $rows[] = [
                'kind' => 'shift',
                'label' => 'Nothing to change at '.$estate->name,
                'status' => null,
                'outcome' => 'idle',
                'detail' => 'Every shift due to have started has, and none due to have ended is still on duty.',
            ];
        }

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* ambient */
    /* ------------------------------------------------------------------ */

    /**
     * A burst of events drawn from a fixed seed.
     *
     * DETERMINISTIC ON PURPOSE. `mt_srand($seed)` fixes the sequence, and every
     * event's idempotency key carries the seed and its index — so the same seed
     * fires the same events with the same keys, and the endpoints answer the
     * second press with the rows they made on the first. A reviewer can hand a
     * seed to somebody else and both consoles will show the same night.
     *
     * One alert in every four events, because a panic is rarer than an arrival
     * and a queue that filled as fast as the gate log would teach nobody what
     * a busy night looks like.
     *
     * THE ENDPOINTS ANSWER 201 TO A REPLAY AS WELL, because to a handset a
     * retried alert IS its alert. So whether a burst made new rows is read
     * from the tables afterwards — counted, never written — and reported as
     * "new" against "replayed", which is the one figure that proves the seed
     * is doing its job.
     *
     * @param  Collection<int, Tenant>  $estates
     * @return array{rows: list<array<string, mixed>>, new: int, replayed: int}
     */
    public function ambient(Collection $estates, int $seed, int $count): array
    {
        $count = max(1, min(self::AMBIENT_MAX, $count));
        $rows = [];

        $before = DuressAlert::query()->count() + GateEvent::query()->count();

        mt_srand($seed);

        // Drawn up front, so the estate/kind sequence is fixed by the seed
        // alone and not by how many guards each estate happened to have.
        $plan = [];

        for ($i = 0; $i < $count; $i++) {
            $plan[] = [
                'estate' => mt_rand(0, $estates->count() - 1),
                'alert' => mt_rand(0, 3) === 0,
                'pick' => mt_rand(0, 1000),
            ];
        }

        foreach ($plan as $i => $step) {
            $estate = $estates->values()[$step['estate']];
            $key = sprintf('sim-web-%d-%d', $seed, $i);

            $rows[] = $step['alert']
                ? $this->alert($estate, self::ALERT_KINDS[$step['pick'] % count(self::ALERT_KINDS)], false, $key)
                : $this->gateEvent($estate, self::ARRIVALS[$step['pick'] % count(self::ARRIVALS)], null, $key);
        }

        $new = DuressAlert::query()->count() + GateEvent::query()->count() - $before;
        $accepted = count(array_filter($rows, static fn (array $row): bool => $row['outcome'] === 'created'));

        return [
            'rows' => $rows,
            'new' => $new,
            'replayed' => max(0, $accepted - $new),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* support */
    /* ------------------------------------------------------------------ */

    /**
     * Borrow a handset at this estate, do the work, hand it back — always.
     *
     * @param  callable(string): array<string, mixed>  $work
     * @return array<string, mixed>
     */
    private function asHandset(Tenant $estate, callable $work): array
    {
        $tenantId = (string) $estate->getTenantKey();

        if (! $this->handset->borrow($tenantId)) {
            return [
                'kind' => 'handset',
                'label' => $estate->name,
                'status' => null,
                'outcome' => 'refused',
                'detail' => 'No active guard at this estate to borrow a handset from. An event needs a device to raise it.',
            ];
        }

        try {
            return $work($tenantId);
        } finally {
            $this->handset->return();
        }
    }

    /**
     * One shift change, sent from the handset of the guard it is rostered to.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function asGuard(Shift $shift, string $label, string $path, array $payload): array
    {
        $guard = $shift->officer;

        if ($guard === null || ! $this->handset->borrowFrom($guard)) {
            return [
                'kind' => 'shift',
                'label' => $label,
                'status' => null,
                'outcome' => 'refused',
                'detail' => 'The guard rostered to this shift is not on active duty, so no handset of theirs can clock it.',
            ];
        }

        try {
            return $this->row('shift', $label, $this->handset->post($path, $payload), 200);
        } finally {
            $this->handset->return();
        }
    }

    /**
     * One result row, in the endpoint's own words.
     *
     * @param  array{status: int, body: array<string, mixed>}  $response
     * @return array<string, mixed>
     */
    private function row(string $kind, string $label, array $response, int $created): array
    {
        $status = $response['status'];
        $body = $response['body'];

        $outcome = match (true) {
            $status === $created && $kind !== 'shift' => 'created',
            $status === 200 || $status === $created => 'ok',
            default => 'refused',
        };

        $detail = match (true) {
            $outcome === 'refused' => (string) ($body['error']['message'] ?? $body['message'] ?? 'Refused by the endpoint.'),
            $kind === 'alert' => 'Alert #'.($body['id'] ?? '?').' · '.($body['status'] ?? '')
                .(($body['clock_skewed'] ?? false) ? ' · device clock skewed' : ''),
            $kind === 'gate' => 'Gate event #'.($body['id'] ?? '?')
                .(($body['clock_skewed'] ?? false) ? ' · device clock skewed' : ''),
            default => 'Shift '.($body['status'] ?? '').' at '.(string) ($body['actual_start'] ?? $body['actual_end'] ?? ''),
        };

        return [
            'kind' => $kind,
            'label' => $label,
            'status' => $status,
            'outcome' => $outcome,
            'detail' => $detail,
            'at' => Carbon::now()->format('H:i:s'),
        ];
    }
}
