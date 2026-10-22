<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SpeaksAsAHandset;
use App\Models\Post;
use App\Models\Shift;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Mobile event simulator: a shift's worth of gate traffic.
 *
 *   php artisan simulate:gate --count=20
 *   php artisan simulate:gate --shift-change
 *
 * Fires real events through the REAL /api/v1 endpoints the Guard App will call,
 * exactly as `simulate:alerts` does. Nothing here writes to a table directly, so
 * the endpoints, their validation, their idempotency and their clock handling
 * are exercised rather than bypassed — and nothing built against the simulator
 * has to be rewritten when the app arrives.
 *
 * WHY THE MIX IS WHAT IT IS. Roughly three in four arrivals come in on something
 * the platform issued and one in four on the guard's own judgement, because that
 * ratio is the figure `AdoptionRollup` turns into board 07's "Visitor passes"
 * bar. A simulator that admitted everybody on a QR pass would draw a perfect
 * client and teach nobody anything; one that admitted nobody on a pass would
 * make every client look like they had never adopted it. The point of the
 * screen is the middle, so the middle is what this generates.
 *
 * Every row it creates is flagged `is_simulated`, so the source badge on the web
 * screens can say plainly that the data is not real.
 */
class SimulateGateTraffic extends Command
{
    use SpeaksAsAHandset;

    protected $signature = 'simulate:gate
        {--count=20 : how many gate events to record}
        {--shift-change : clock the current roster on and the previous one off}';

    protected $description = 'Record simulated gate traffic and shift changes through the real /api/v1 endpoints';

    /** What arrives at a gated community, in roughly the proportions it does. */
    private const ARRIVALS = [
        ['category' => 'Visitor', 'basis' => 'QR pass', 'verdict' => 'admit'],
        ['category' => 'Resident vehicle', 'basis' => 'Tag read', 'verdict' => 'admit'],
        ['category' => 'Delivery', 'basis' => 'Pre-approved', 'verdict' => 'admit'],
        ['category' => 'Visitor', 'basis' => 'QR pass', 'verdict' => 'admit'],
        ['category' => 'Contractor', 'basis' => 'Pre-approved', 'verdict' => 'admit'],
        ['category' => 'Visitor', 'basis' => 'Guard decision', 'verdict' => 'admit'],
        ['category' => 'Delivery', 'basis' => 'Guard decision', 'verdict' => 'deny'],

        /*
         * One override in the set, and it carries its reason. The endpoint
         * refuses an override without one, so this also proves that rule is
         * reachable rather than only asserted in a test.
         */
        ['category' => 'Contractor', 'basis' => 'Guard decision', 'verdict' => 'override',
            'reason' => 'Emergency plumbing call-out, authorised by the Property Manager'],
    ];

    private const SUBJECTS = [
        'Andrea Fletcher', 'D. Grant', 'Kayla Morrison', 'R. Peart', 'Sonia Campbell',
        'Island Courier Ltd', 'AquaTech Pool Services', 'PP 4821', 'PT 9034', 'Gate2 Contractor Ltd',
    ];

    public function handle(): int
    {
        $estates = Tenant::estates();

        if ($estates->isEmpty()) {
            $this->error('No estates provisioned. Run estate:provision first.');

            return self::FAILURE;
        }

        $recorded = 0;

        /*
         * One borrowed handset per estate, and handed back before moving on.
         *
         * A token is scoped to a guard, and a guard belongs to one estate — so
         * speaking for two estates means enrolling twice, which is exactly what
         * two real handsets would do. `finally`, because a run that fails part
         * way must not leave a working credential behind.
         */
        foreach ($estates as $estate) {
            $key = (string) $estate->getTenantKey();

            if (! $this->borrowHandset($key)) {
                $this->line("  <fg=yellow>skip</> {$key}: no active guard to borrow a handset from");

                continue;
            }

            try {
                $recorded += $this->recordArrivals($estate);

                if ($this->option('shift-change')) {
                    $recorded += $this->changeShifts($estate);
                }
            } finally {
                $this->returnHandset();
            }
        }

        $this->info("Recorded {$recorded} event(s) through /api/v1.");

        return self::SUCCESS;
    }

    private function recordArrivals(Tenant $estate): int
    {
        $count = max(1, (int) $this->option('count'));
        $key = (string) $estate->getTenantKey();
        $recorded = 0;

        for ($i = 0; $i < $count; $i++) {
            $arrival = self::ARRIVALS[$i % count(self::ARRIVALS)];

            /*
             * The guard whose handset is speaking, not a random one. A gate
             * event names who made the decision, and attributing it to somebody
             * whose phone did not send it would make the log wrong in exactly
             * the way a log must not be.
             */
            $guard = $this->handsetGuard();
            $post = Post::where('tenant_id', $key)->where('is_active', true)->inRandomOrder()->first();

            $payload = [
                'tenant_id' => $key,
                'verdict' => $arrival['verdict'],
                'category' => $arrival['category'],
                'subject' => self::SUBJECTS[$i % count(self::SUBJECTS)],
                'basis' => $arrival['basis'],
                'reason' => $arrival['reason'] ?? null,
                'guard_id' => $guard?->id,
                'post_id' => $post?->id,
                'device_time' => now()->toIso8601String(),

                /*
                 * Stable, so re-running is idempotent rather than inflating the
                 * very adoption figure this exists to make readable — and
                 * varied by estate and index so two estates' traffic does not
                 * collide on one key.
                 */
                'idempotency_key' => sprintf('sim-gate-%s-%d', $key, $i),
                'simulated' => true,
            ];

            if ($this->post('/api/v1/gate-events', $payload, 201)) {
                $recorded++;
            }
        }

        return $recorded;
    }

    /**
     * Clock today's roster on, and yesterday's off.
     *
     * The half of the simulator that makes the dispatch map say a post is
     * MANNED. Coverage reads `shifts.actual_start`, so a roster nobody has
     * clocked into shows every post rostered and none worked — which is what
     * the live map showed before these endpoints existed.
     */
    private function changeShifts(Tenant $estate): int
    {
        $key = (string) $estate->getTenantKey();
        $changed = 0;

        // On: anything rostered to have started by now and not yet clocked in.
        $starting = Shift::where('tenant_id', $key)
            ->whereNull('actual_start')
            ->where('rostered_start', '<=', now())
            ->get();

        /*
         * EACH SHIFT FROM ITS OWN GUARD'S HANDSET (13 D1): a token clocks its own
         * guard's shifts. The estate's borrowed handset is handed back first; the
         * caller's `finally` returns whichever is borrowed last.
         */
        $this->returnHandset();

        foreach ($starting as $shift) {
            if ($shift->officer === null || ! $this->borrowHandsetFrom($shift->officer)) {
                continue;
            }

            try {
                if ($this->post("/api/v1/shifts/{$shift->id}/clock-in", [
                    'method' => 'app',

                    /*
                     * A plausible few metres from the post. Stored and never
                     * enforced here — see `/preflight` — so this builds the
                     * history a rule is written against.
                     */
                    'geofence_distance_m' => 8 + ($shift->id % 30),
                    'mock_location' => false,
                    'simulated' => true,
                ], 200)) {
                    $changed++;
                }
            } finally {
                $this->returnHandset();
            }
        }

        // Off: anything whose rostered end has passed and that is still on duty.
        $ending = Shift::where('tenant_id', $key)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->where('rostered_end', '<=', now())
            ->get();

        foreach ($ending as $shift) {
            if ($shift->officer === null || ! $this->borrowHandsetFrom($shift->officer)) {
                continue;
            }

            try {
                if ($this->post("/api/v1/shifts/{$shift->id}/clock-out", [], 200)) {
                    $changed++;
                }
            } finally {
                $this->returnHandset();
            }
        }

        return $changed;
    }

    /**
     * Fire one request through the real HTTP kernel.
     *
     * Through the kernel rather than by calling the controller, so middleware,
     * validation and route binding are all exercised — the same reason
     * `simulate:alerts` does it this way.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload, int $expect): bool
    {
        $response = $this->callApi($path, $payload);

        if ($response['status'] === $expect) {
            $this->line("  <fg=green>{$response['status']}</> {$path}");

            return true;
        }

        $this->line("  <fg=red>{$response['status']}</> {$path}: ".str($response['body'])->limit(160));

        return false;
    }
}
