<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Console;
use App\Models\DuressAlertDelivery;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Role;
use App\Models\StatutoryRateVersion;
use App\Models\User;
use App\Support\PlatformPricing;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * THE CENTRAL HALF OF A PLATFORM AT VOLUME (M11 A2) — what the Gemini console reads.
 *
 * `ScaleFixture` is one estate the size Phoenix Park really is. The Gemini console reads
 * none of that estate's database: it reads gs_platform, where the clients, the guards,
 * their shifts, the alerts, the gate log, the invoices and the payroll live. This builds
 * that half, around the scale estate and eleven more clients, at a volume where a list
 * that fires one query per row cannot hide:
 *
 *   12 clients, each on a plan, with six months of invoices and six priced overrides
 *   60 officers (5 a client) on 24 posts, each with 22 shifts
 *   96 alerts, 2,550 gate events, 120 incidents, 240 requests (half of them vacation),
 *   1,000 audit entries
 *   3 payroll runs (two approved, one awaiting approval), a payslip for every officer on each
 *
 * `moreOfTheirOwn()` then gives ONE officer more of everything that is theirs alone, for the
 * Guard App's lists: a second build adds colleagues, not rows to that officer's own reports.
 *
 * PRODUCTION'S CENTRAL ROW COUNTS COULD NOT BE READ (production access was refused to
 * this work), so these are chosen to sit well above the development database's own
 * (115 gate events, 106 shifts, 394 audit entries on 24 September 2026). The budget tests
 * do not depend on the exact figures: they assert that a page's query count does not grow
 * with its rows.
 *
 * INSIDE THE CALLER'S TRANSACTION, ALWAYS. Every row here is central and would otherwise
 * be committed into a database every other test file reads — a count of active guards
 * somewhere else would move by sixty. `build()` refuses to run outside one.
 */
final class ScaleVolume
{
    public const OTHER_CLIENTS = 11;

    public const GUARDS_PER_CLIENT = 5;

    public const POSTS_PER_CLIENT = 2;

    public const SHIFT_DAYS_BACK = 14;

    public const SHIFT_DAYS_AHEAD = 7;

    public const GATE_EVENTS_AT_SCALE_ESTATE = 2000;

    public const GATE_EVENTS_ELSEWHERE = 50;

    public const ALERTS_PER_CLIENT = 8;

    public const INCIDENTS_PER_CLIENT = 10;

    public const REQUESTS_PER_GUARD = 4;

    public const AUDIT_ENTRIES = 1000;

    public const INVOICE_MONTHS = 6;

    public const PAYROLL_RUNS = 3;

    /**
     * Build it, and hand back the ids the budget tests open pages by.
     *
     * `$factor` multiplies every per-client and per-guard count, so a test can build the
     * platform twice as large and prove a page's query count stayed where it was.
     *
     * @return array<string, mixed>
     */
    public static function build(int $factor = 1): array
    {
        if (DB::connection('mysql')->transactionLevel() === 0) {
            throw new \RuntimeException('ScaleVolume writes central rows and must run inside a transaction the test rolls back.');
        }

        $estate = ScaleFixture::platform();
        $now = Carbon::now();
        $suffix = (string) random_int(100000, 999999);
        $db = DB::connection('mysql');

        // The TAJ cards the payroll runs below are dated against. Seeded inside the caller's
        // transaction, so they leave with it.
        if (! $db->table('statutory_rate_versions')->exists()) {
            Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);
        }

        $planId = (int) $db->table('plans')->insertGetId([
            'key' => 'm11scale'.$suffix,
            'name' => 'Scale'.$suffix,
            'price_per_unit_minor' => 340_00,
            'currency' => 'JMD',
            'min_units' => 10,
            'is_active' => true,
            'sort' => 90,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /* ── clients ─────────────────────────────────────────────────── */

        $clients = [ScaleFixture::ESTATE];

        for ($i = 1; $i <= self::OTHER_CLIENTS * $factor; $i++) {
            $id = 'm11vol'.$suffix.'c'.$i;
            $clients[] = $id;

            $db->table('tenants')->insert([
                'id' => $id,
                'name' => 'Volume Estate '.$suffix.' '.$i,
                'status' => $i % 6 === 0 ? 'onboarding' : ($i % 7 === 0 ? 'dunning' : 'active'),
                'demo' => 0,
                'parish' => ['St. Andrew', 'St. Catherine', 'Manchester'][$i % 3],
                'address_line' => 'Road '.$i,
                'provisioned_at' => $now->copy()->subDays(30 + $i),
                'created_at' => $now,
                'updated_at' => $now,
                'data' => '{}',
            ]);
        }

        $subscriptions = [];

        foreach ($clients as $i => $tenant) {
            $status = (string) $db->table('tenants')->where('id', $tenant)->value('status');

            // One subscription a client — the column is unique — so a second build over the
            // scale estate keeps the one the first build gave it.
            $existing = $db->table('subscriptions')->where('tenant_id', $tenant)->value('id');

            if ($existing !== null) {
                $subscriptions[$tenant] = (int) $existing;

                continue;
            }

            $subscriptions[$tenant] = (int) $db->table('subscriptions')->insertGetId([
                'tenant_id' => $tenant,
                'plan_id' => $planId,
                'unit_count' => $tenant === ScaleFixture::ESTATE ? ScaleFixture::LOTS : 100 + $i * 10,
                'contracted_guards' => self::GUARDS_PER_CLIENT,
                'status' => $status === 'onboarding' ? 'onboarding' : $status,
                'started_on' => $status === 'onboarding' ? null : $now->copy()->subYear()->toDateString(),
                'renews_on' => $now->copy()->addYear()->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /* ── the line-item board: guarding, and overrides tied to the catalogue ── */

        // The per-guard add-on, at board 42's rate — the same figure `PlatformPricing` falls back
        // to, so no revenue anywhere moves; what it adds is the guarding line on every client's
        // price panel and invoice preview, which prices each rate on its own.
        if (! $db->table('platform_rates')->where('key', PlatformPricing::GUARD_RATE_KEY)->exists()) {
            $db->table('platform_rates')->insert([
                'key' => PlatformPricing::GUARD_RATE_KEY, 'label' => 'Security provider — per guard', 'applies_to' => 'Applies on top of any tier',
                'amount_minor' => 4_500_00, 'currency' => 'JMD', 'basis' => 'guard', 'is_active' => true, 'sort' => 10,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Three features: one this build's plan alone includes, one a second plan includes too,
        // and one no plan includes — the three sentences an override row can say about its tier.
        $higherPlanId = (int) $db->table('plans')->insertGetId([
            'key' => 'm11scaleplus'.$suffix, 'name' => 'Scale Plus'.$suffix, 'price_per_unit_minor' => 420_00, 'currency' => 'JMD',
            'min_units' => 10, 'is_active' => true, 'sort' => 91, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $features = [];
        foreach ([1 => [$planId], 2 => [$planId, $higherPlanId], 3 => []] as $k => $includedIn) {
            $features[$k] = (int) $db->table('package_features')->insertGetId([
                'key' => 'm11feature'.$suffix.'-'.$k, 'label' => 'Volume feature '.$k, 'kind' => 'toggle', 'is_core' => false, 'sort' => 90 + $k,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ([$planId, $higherPlanId] as $plan) {
                $db->table('plan_features')->insert([
                    'plan_id' => $plan, 'package_feature_id' => $features[$k], 'included' => in_array($plan, $includedIn, true),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        // Six overrides a client, and the scale estate's six again with every build, so a price
        // panel that asked something once per override asks twice as much of the second build.
        $lineItems = [];
        foreach ($clients as $tenant) {
            foreach ([
                ['addition', $features[1], 'Volume add-on', $now->copy()->subDays(40), null],
                ['addition', $features[2], 'Volume upgrade', $now->copy()->subDays(20), null],
                ['removal', $features[2], 'Volume removal', $now->copy()->subDays(20), 'Removed at the client\'s request'],
                ['removal', $features[3], 'Volume unbundled', $now->copy()->subDays(10), null],
                ['addition', null, 'Migration support', $now->copy()->subDays(5), 'Agreed at renewal'],
                ['addition', $features[1], 'Volume add-on from next month', $now->copy()->addDays(20), null],
            ] as [$type, $feature, $description, $from, $reason]) {
                $lineItems[] = [
                    'tenant_id' => $tenant, 'package_feature_id' => $feature, 'type' => $type, 'description' => $description.' '.$suffix,
                    'reason' => $reason, 'amount_minor' => 2_500_00, 'currency' => 'JMD', 'recurrence' => 'monthly',
                    'effective_from' => $from->toDateString(), 'effective_to' => null, 'created_by_user_id' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        $db->table('subscription_line_items')->insert($lineItems);

        /* ── invoices ────────────────────────────────────────────────── */

        $invoiceIds = [];

        foreach ($clients as $tenant) {
            for ($m = self::INVOICE_MONTHS; $m >= 1; $m--) {
                $start = $now->copy()->subMonths($m)->startOfMonth();
                $invoiceIds[] = $id = (int) $db->table('invoices')->insertGetId([
                    'tenant_id' => $tenant,
                    'subscription_id' => $subscriptions[$tenant],
                    'reference' => 'M11-'.$suffix.'-'.$tenant.'-'.$m,
                    'period' => $start->format('M Y'),
                    'period_start' => $start->toDateString(),
                    'period_end' => $start->copy()->endOfMonth()->toDateString(),
                    'subtotal_minor' => 70_000_00,
                    'total_minor' => 70_000_00,
                    'currency' => 'JMD',
                    'due_on' => $start->copy()->addMonth()->addDays(14)->toDateString(),
                    'status' => $m > 2 ? 'paid' : 'issued',
                    'paid_on' => $m > 2 ? $start->copy()->addMonth()->addDays(3)->toDateString() : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $db->table('invoice_lines')->insert([
                    ['invoice_id' => $id, 'description' => 'Platform — units', 'quantity' => 100, 'unit_price_minor' => 340_00, 'total_minor' => 34_000_00, 'currency' => 'JMD', 'created_at' => $now, 'updated_at' => $now],
                    ['invoice_id' => $id, 'description' => 'Security add-on — guards', 'quantity' => 8, 'unit_price_minor' => 4_500_00, 'total_minor' => 36_000_00, 'currency' => 'JMD', 'created_at' => $now, 'updated_at' => $now],
                ]);
            }
        }

        /* ── posts and officers ──────────────────────────────────────── */

        $posts = [];
        $guards = [];
        $guardRows = [];

        foreach ($clients as $c => $tenant) {
            for ($p = 1; $p <= self::POSTS_PER_CLIENT; $p++) {
                $posts[$tenant][] = (int) $db->table('posts')->insertGetId([
                    'tenant_id' => $tenant,
                    'name' => ($p === 1 ? 'Main Gate ' : 'Patrol ').$suffix.'-'.$c,
                    'type' => $p === 1 ? 'gate' : 'patrol',
                    'is_active' => true,
                    'latitude' => 18.0243,
                    'longitude' => -76.7896,
                    'geofence_radius_m' => 80,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // And a relief post nobody is posted to or rostered on: every screen that
            // explains an empty post has one to explain, at every client.
            $db->table('posts')->insert([
                'tenant_id' => $tenant,
                'name' => 'Relief '.$suffix.'-'.$c,
                'type' => 'relief',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            for ($g = 1; $g <= self::GUARDS_PER_CLIENT * $factor; $g++) {
                $n = count($guardRows) + 1;
                $status = match (true) {
                    $g === 4 => 'licence_expired',
                    $g === 5 && $c % 2 === 0 => 'on_leave',
                    default => 'active',
                };

                $guardRows[] = [
                    'full_name' => 'Officer '.$suffix.' '.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                    'employee_number' => 'GS-M11-'.$suffix.'-'.$n,
                    'psra_number' => 'PSRA-M11-'.$suffix.'-'.$n,
                    'psra_expires_on' => $status === 'licence_expired' ? $now->copy()->subDays(10 + $n)->toDateString() : $now->copy()->addDays(20 + $n * 7)->toDateString(),
                    'employment_type' => $g % 3 === 0 ? 'part_time' : 'full_time',
                    'standard_rate_minor' => 1_050_00,
                    'standard_rate_currency' => 'JMD',
                    'status' => $status,
                    'reviewer' => false,
                    'phone' => '876-555-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                    'hired_on' => $now->copy()->subDays(40 + $n)->toDateString(),
                    'tenant_id' => $tenant,
                    'post_id' => $posts[$tenant][($g - 1) % self::POSTS_PER_CLIENT],
                    'leave_entitlement_days' => 14,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($guardRows, 200) as $chunk) {
            $db->table('guards')->insert($chunk);
        }

        foreach ($db->table('guards')->where('employee_number', 'like', 'GS-M11-'.$suffix.'-%')->orderBy('id')->get(['id', 'tenant_id', 'post_id', 'status']) as $row) {
            $guards[(string) $row->tenant_id][] = ['id' => (int) $row->id, 'post_id' => (int) $row->post_id, 'status' => (string) $row->status];
        }

        // Taken on and not yet posted anywhere — a real state the workforce screens spend rows on.
        for ($u = 1; $u <= 5 * $factor; $u++) {
            $db->table('guards')->insert([
                'full_name' => 'Unposted '.$suffix.' '.$u,
                'employee_number' => 'GS-M11U-'.$suffix.'-'.$u,
                'psra_number' => 'PSRA-M11U-'.$suffix.'-'.$u,
                'psra_expires_on' => $now->copy()->addMonths(6)->toDateString(),
                'employment_type' => 'full_time',
                'status' => 'active',
                'reviewer' => false,
                'hired_on' => $now->copy()->subDays($u)->toDateString(),
                'tenant_id' => null,
                'post_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /* ── shifts: a fortnight behind, today's watch, a week ahead ─── */

        $shiftRows = [];
        $onDutyShifts = [];

        foreach ($guards as $tenant => $officers) {
            foreach ($officers as $i => $officer) {
                for ($d = self::SHIFT_DAYS_BACK; $d >= 1; $d--) {
                    $start = $now->copy()->subDays($d)->setTime(6, 0);
                    $shiftRows[] = [
                        'tenant_id' => $tenant, 'guard_id' => $officer['id'], 'post_id' => $officer['post_id'],
                        'rostered_start' => $start, 'rostered_end' => $start->copy()->addHours(12),
                        'actual_start' => $start->copy()->subMinutes(4), 'actual_end' => $start->copy()->addHours(12)->addMinutes(3),
                        'start_method' => 'app', 'status' => 'completed', 'is_simulated' => false,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }

                // Two on duty now at every client; the rest rostered later today.
                $start = $now->copy()->subHours(3);
                $shiftRows[] = [
                    'tenant_id' => $tenant, 'guard_id' => $officer['id'], 'post_id' => $officer['post_id'],
                    'rostered_start' => $start, 'rostered_end' => $start->copy()->addHours(12),
                    'actual_start' => $i < 2 && $officer['status'] === 'active' ? $start->copy()->addMinutes(2) : null, 'actual_end' => null,
                    'start_method' => $i < 2 ? 'app' : null, 'status' => $i < 2 && $officer['status'] === 'active' ? 'on_duty' : 'rostered', 'is_simulated' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ];

                for ($d = 1; $d <= self::SHIFT_DAYS_AHEAD; $d++) {
                    $start = $now->copy()->addDays($d)->setTime(6, 0);
                    $shiftRows[] = [
                        'tenant_id' => $tenant, 'guard_id' => $officer['id'], 'post_id' => $officer['post_id'],
                        'rostered_start' => $start, 'rostered_end' => $start->copy()->addHours(12),
                        'actual_start' => null, 'actual_end' => null,
                        'start_method' => null, 'status' => 'rostered', 'is_simulated' => false,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }

            // And an open post nobody has taken, so the roster and the Guard App's open list have one.
            $start = $now->copy()->addDays(2)->setTime(18, 0);
            $shiftRows[] = [
                'tenant_id' => $tenant, 'guard_id' => null, 'post_id' => $posts[$tenant][0],
                'rostered_start' => $start, 'rostered_end' => $start->copy()->addHours(12),
                'actual_start' => null, 'actual_end' => null,
                'start_method' => null, 'status' => 'open', 'is_simulated' => false,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($shiftRows, 500) as $chunk) {
            $db->table('shifts')->insert($chunk);
        }

        $onDutyShifts = $db->table('shifts')->whereIn('tenant_id', $clients)->where('status', 'on_duty')->pluck('id', 'guard_id')->all();

        /* ── alertness checks and patrol rounds ──────────────────────── */

        $checkRows = [];
        foreach ($onDutyShifts as $guardId => $shiftId) {
            for ($k = 1; $k <= 5; $k++) {
                $at = $now->copy()->subMinutes(30 * $k);
                $checkRows[] = [
                    'guard_id' => $guardId, 'shift_id' => $shiftId, 'outcome' => $k === 1 ? 'pending' : 'passed',
                    'issued_at' => $at, 'respond_by' => $at->copy()->addMinutes(5), 'responded_at' => $k === 1 ? null : $at->copy()->addMinutes(1),
                    'device_time' => $at, 'server_time' => $at, 'is_simulated' => false, 'clock_skewed' => false,
                ];
            }
        }
        foreach (array_chunk($checkRows, 500) as $chunk) {
            $db->table('alertness_checks')->insert($chunk);
        }

        $checkpoints = [];
        foreach (array_slice($clients, 0, 3) as $tenant) {
            for ($k = 1; $k <= 9; $k++) {
                $checkpoints[$tenant][] = (int) $db->table('patrol_checkpoints')->insertGetId([
                    'tenant_id' => $tenant, 'post_id' => $posts[$tenant][1], 'label' => 'Checkpoint '.$k,
                    'code' => 'M11'.$suffix.'-'.substr(md5($tenant), 0, 6).'-'.$k, 'sequence' => $k, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $scanRows = [];
        foreach ($checkpoints as $tenant => $points) {
            $officer = $guards[$tenant][1]['id'];
            for ($d = 0; $d < self::SHIFT_DAYS_BACK; $d++) {
                foreach ($points as $k => $point) {
                    $at = $now->copy()->subDays($d)->subHours(2)->addMinutes(6 * $k);
                    $scanRows[] = ['patrol_checkpoint_id' => $point, 'guard_id' => $officer, 'device_time' => $at, 'server_time' => $at, 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false];
                }
            }
        }
        foreach (array_chunk($scanRows, 500) as $chunk) {
            $db->table('checkpoint_scans')->insert($chunk);
        }

        /* ── the gate log ────────────────────────────────────────────── */

        $gateRows = [];
        foreach ($clients as $tenant) {
            $count = ($tenant === ScaleFixture::ESTATE ? self::GATE_EVENTS_AT_SCALE_ESTATE : self::GATE_EVENTS_ELSEWHERE) * $factor;
            $officer = $guards[$tenant][0];

            for ($e = 0; $e < $count; $e++) {
                $at = $now->copy()->subMinutes((int) floor($e * (self::SHIFT_DAYS_BACK * 24 * 60) / max(1, $count)));
                $gateRows[] = [
                    'tenant_id' => $tenant, 'guard_id' => $officer['id'], 'guard_name' => 'Officer', 'post_id' => $officer['post_id'], 'post_name' => 'Main Gate',
                    'verdict' => $e % 9 === 0 ? 'denied' : 'admitted', 'category' => ['single', 'delivery', 'contractor', 'walk_up'][$e % 4],
                    'subject' => 'Visitor '.$e, 'basis' => 'Pass verified · Lot '.(($e % 1999) + 1), 'occurred_at' => $at, 'device_time' => $at,
                    'idempotency_key' => 'm11-'.$suffix.'-'.$tenant.'-'.$e, 'is_simulated' => false, 'created_at' => $at, 'updated_at' => $at,
                ];
            }
        }
        foreach (array_chunk($gateRows, 500) as $chunk) {
            $db->table('gate_events')->insert($chunk);
        }

        /* ── alerts, incidents, requests, messages ───────────────────── */

        $alertIds = [];
        foreach ($clients as $c => $tenant) {
            for ($a = 1; $a <= self::ALERTS_PER_CLIENT; $a++) {
                $open = $a <= 3;
                $at = $now->copy()->subHours($a * 5 + $c);
                $alertIds[$tenant][] = (int) $db->table('duress_alerts')->insertGetId([
                    'tenant_id' => $tenant, 'kind' => ['panic', 'duress', 'medical', 'fire'][$a % 4], 'mode' => 'audible',
                    'guard_id' => $a % 4 === 1 ? $guards[$tenant][0]['id'] : null,
                    'raised_by_name' => 'Lot '.($a * 7).' household', 'unit_reference' => 'Lot '.($a * 7),
                    'status' => $open ? ($a === 1 ? 'open' : 'acknowledged') : 'resolved',
                    'device_time' => $at, 'server_time' => $at, 'clock_skewed' => false, 'captured_offline' => false,
                    'idempotency_key' => 'm11-alert-'.$suffix.'-'.$tenant.'-'.$a,
                    'acknowledged_at' => $a === 1 ? null : $at->copy()->addSeconds(40), 'resolved_at' => $open ? null : $at->copy()->addMinutes(15),
                    // One on its way at every client: an officer dispatched and en route.
                    'dispatched_at' => $a === 2 ? $at->copy()->addMinute() : null,
                    'responding_guard_id' => $a === 2 ? $guards[$tenant][1]['id'] : null,
                    'en_route_at' => $a === 2 ? $at->copy()->addMinutes(2) : null,
                    'resolution_note' => $open ? null : 'Attended. Nothing found.', 'is_simulated' => false, 'is_drill' => false,
                    'alerted_handsets' => 2, 'alerted_via' => 'on_duty', 'escalated_contacts' => 0, 'sms_recipients' => 0, 'sms_delivered' => 0,
                    'created_at' => $at, 'updated_at' => $at,
                ]);
            }
        }

        $deliveryRows = [];
        foreach ($alertIds[ScaleFixture::ESTATE] as $alertId) {
            foreach (array_keys(DuressAlertDelivery::DESTINATIONS) as $k => $destination) {
                $deliveryRows[] = ['duress_alert_id' => $alertId, 'tenant_id' => ScaleFixture::ESTATE, 'destination' => $destination, 'channel' => 'push', 'recipients' => 2, 'outcome' => DuressAlertDelivery::SENT, 'elapsed_ms' => 10 + $k * 12, 'note' => null, 'created_at' => $now];
            }
        }
        $db->table('duress_alert_deliveries')->insert($deliveryRows);

        $incidentIds = [];
        $requestRows = [];
        $messageRows = [];
        foreach ($clients as $tenant) {
            for ($k = 1; $k <= self::INCIDENTS_PER_CLIENT * $factor; $k++) {
                $at = $now->copy()->subHours($k * 9);
                $officer = $guards[$tenant][$k % count($guards[$tenant])];
                $incidentIds[] = (int) $db->table('security_incidents')->insertGetId([
                    'tenant_id' => $tenant, 'guard_id' => $officer['id'], 'guard_name' => 'Officer', 'kind' => ['suspicious', 'property', 'noise', 'access'][$k % 4],
                    'detail' => 'Incident '.$k.' at '.$tenant, 'severity' => ['low', 'med', 'high'][$k % 3], 'status' => $k % 3 === 0 ? 'closed' : 'open',
                    'occurred_at' => $at, 'resolution' => $k % 3 === 0 ? 'Closed.' : null, 'closed_at' => $k % 3 === 0 ? $at->copy()->addHours(2) : null,
                    'logged_by_name' => 'Officer', 'is_simulated' => false, 'created_at' => $at, 'updated_at' => $at,
                ]);
            }

            foreach ($guards[$tenant] as $officer) {
                for ($r = 1; $r <= self::REQUESTS_PER_GUARD; $r++) {
                    $pending = $r <= 2;
                    // Leave is VACATION, the kind that comes off an entitlement: a pending one the
                    // inbox has to work a balance out for, and one already approved this year that
                    // the balance is worked out from.
                    $leave = $r % 2 === 0;
                    $starts = $r === 4 ? $now->copy()->startOfYear()->addDays(20) : $now->copy()->addDays(10 + $r);
                    $requestRows[] = [
                        'tenant_id' => $tenant, 'guard_id' => $officer['id'], 'kind' => $leave ? 'leave' : 'equipment',
                        'subject' => $leave ? 'Vacation' : 'Request '.$r, 'quantity' => $leave ? null : 1,
                        'starts_on' => $leave ? $starts->toDateString() : null,
                        'ends_on' => $leave ? $starts->copy()->addDays(2)->toDateString() : null,
                        'reason' => 'Volume', 'status' => $pending ? 'pending' : ($r === 3 ? 'denied' : 'approved'),
                        'decided_at' => $pending ? null : $now->copy()->subDays($r), 'decision_note' => $pending ? null : 'Noted.',
                        'is_simulated' => false, 'created_at' => $now->copy()->subDays(5 + $r), 'updated_at' => $now,
                    ];
                }

                for ($m = 1; $m <= 2; $m++) {
                    $messageRows[] = ['tenant_id' => $tenant, 'direction' => $m === 1 ? 'outbound' : 'inbound', 'guard_id' => $officer['id'], 'body' => 'Message '.$m, 'sent_at' => $now->copy()->subHours($m * 3), 'created_at' => $now, 'updated_at' => $now];
                }
            }

            $messageRows[] = ['tenant_id' => $tenant, 'direction' => 'broadcast', 'guard_id' => null, 'body' => 'All posts, '.$tenant, 'sent_at' => $now->copy()->subDay(), 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($requestRows, 500) as $chunk) {
            $db->table('guard_requests')->insert($chunk);
        }
        foreach (array_chunk($messageRows, 500) as $chunk) {
            $db->table('dispatch_messages')->insert($chunk);
        }

        /* ── standing orders ─────────────────────────────────────────── */

        $setIds = [];
        foreach ($clients as $c => $tenant) {
            foreach (['gate', 'patrol'] as $k => $category) {
                $setIds[] = $setId = (int) $db->table('standing_order_sets')->insertGetId([
                    'title' => ucfirst($category).' orders '.$suffix.'-'.$c, 'category' => $category, 'summary' => 'How the post is worked.',
                    'tenant_id' => $tenant, 'post_id' => $posts[$tenant][$k], 'version' => 2, 'body' => 'Orders.',
                    'effective_on' => $now->copy()->subDays(20)->toDateString(), 'reviewed_on' => $now->copy()->subDays(20)->toDateString(),
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                foreach ([1, 2] as $v) {
                    $db->table('standing_order_versions')->insert(['standing_order_set_id' => $setId, 'version' => $v, 'title' => 'Orders v'.$v, 'summary' => 'Summary', 'body' => 'Body', 'effective_on' => $now->copy()->subDays(40 - $v * 10)->toDateString(), 'change_note' => 'Revised.', 'published_by_name' => 'Operations', 'created_at' => $now, 'updated_at' => $now]);
                }

                $acks = [];
                foreach ($guards[$tenant] as $g => $officer) {
                    if ($g % 2 === 0) {
                        $acks[] = ['standing_order_set_id' => $setId, 'guard_id' => $officer['id'], 'version' => 2, 'acknowledged_at' => $now->copy()->subDays(3), 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                $db->table('standing_order_acknowledgements')->insert($acks);
            }
        }

        /* ── payroll ─────────────────────────────────────────────────── */

        $runIds = [];
        $allGuards = collect($guards)->flatten(1)->all();
        for ($r = self::PAYROLL_RUNS; $r >= 1; $r--) {
            $end = $now->copy()->subMonths($r - 1)->startOfMonth()->subDay();
            $rates = StatutoryRateVersion::forPayDate($end);

            $runIds[] = $runId = (int) $db->table('payroll_runs')->insertGetId([
                'reference' => 'M11-'.$suffix.'-'.$end->format('Y-m'), 'period_label' => $end->format('F Y'),
                'period_start' => $end->copy()->startOfMonth()->toDateString(), 'period_end' => $end->toDateString(), 'periods_per_year' => 12,
                'statutory_rate_version_id' => $rates->id,
                'status' => $r === 1 ? 'calculated' : 'approved', 'currency' => 'JMD',
                'gross_minor' => count($allGuards) * 200_000_00, 'net_minor' => count($allGuards) * 160_000_00,
                'approved_at' => $r === 1 ? null : $end->copy()->addDays(2), 'created_at' => $now, 'updated_at' => $now,
            ]);

            $slips = [];
            foreach ($allGuards as $officer) {
                $tenant = collect($guards)->search(fn (array $list): bool => in_array($officer, $list, true));
                $slips[] = ['payroll_run_id' => $runId, 'guard_id' => $officer['id'], 'tenant_id' => $tenant, 'regular_hours' => 176, 'overtime_hours' => 8,
                    'gross_minor' => 200_000_00, 'nis_minor' => 6_000_00, 'nht_minor' => 4_000_00, 'education_tax_minor' => 4_500_00, 'paye_minor' => 25_500_00,
                    'net_minor' => 160_000_00, 'currency' => 'JMD', 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($slips, 500) as $chunk) {
                $db->table('payslips')->insert($chunk);
            }
        }

        /* ── people ──────────────────────────────────────────────────── */

        $director = self::geminiUser(Role::DIRECTOR);

        $committee = [];
        foreach ([Role::PRESIDENT, Role::TREASURER, Role::SECRETARY, Role::PROPERTY_MANAGER, Role::COMMUNITY_SUPER_ADMIN] as $roleName) {
            foreach (array_slice($clients, 0, 4) as $tenant) {
                $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
                $user->syncRoles([$roleName]);
                EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => $tenant, 'role_id' => Role::query()->where('name', $roleName)->value('id'), 'is_active' => true]);
                $committee[] = $user->id;
            }
        }

        $messages = [];
        foreach (array_slice($clients, 0, 4) as $tenant) {
            for ($k = 1; $k <= 5; $k++) {
                $messages[] = ['tenant_id' => $tenant, 'recipient_name' => 'President', 'recipient_role' => 'estate.president', 'category' => 'operations', 'subject' => 'Note '.$k, 'body' => 'Body', 'sent_by' => $director->id, 'sent_by_name' => $director->name, 'sent_at' => $now->copy()->subDays($k), 'created_at' => $now, 'updated_at' => $now];
            }
        }
        $db->table('client_messages')->insert($messages);

        $auditRows = [];
        for ($k = 0; $k < self::AUDIT_ENTRIES * $factor; $k++) {
            $auditRows[] = [
                'tenant_id' => $clients[$k % count($clients)], 'actor_id' => $director->id, 'actor_name' => $director->name, 'actor_role' => Role::DIRECTOR,
                'action' => ['guard.suspended', 'shift.posted', 'invoice.resent', 'role.updated', 'client.message_sent'][$k % 5],
                'entity_type' => 'Guard', 'entity_id' => (string) ($k + 1), 'before' => json_encode(['status' => 'active']), 'after' => json_encode(['status' => 'suspended']),
                'ip' => '127.0.0.1', 'user_agent' => 'phpunit', 'created_at' => $now->copy()->subMinutes($k * 7),
            ];
        }
        foreach (array_chunk($auditRows, 500) as $chunk) {
            $db->table('audit_log')->insert($chunk);
        }

        $scaleGuards = $guards[ScaleFixture::ESTATE];

        return [
            'suffix' => $suffix,
            'plan_id' => $planId,
            'clients' => $clients,
            'director' => $director,
            'guard_ids' => array_column(collect($guards)->flatten(1)->all(), 'id'),
            'scale_guard_ids' => array_column($scaleGuards, 'id'),
            'scale_on_duty_guard' => Guard::query()->findOrFail($scaleGuards[0]['id']),
            // Raised by the scale estate's first officer (every fourth alert is an officer's duress).
            'alert_id' => $alertIds[ScaleFixture::ESTATE][0],
            // Raised by a household, dispatched, and en route: an alert an officer is sent TO.
            'household_alert_id' => $alertIds[ScaleFixture::ESTATE][1],
            'incident_id' => $incidentIds[0],
            'set_id' => $setIds[0],
            'run_id' => $runIds[array_key_last($runIds)],
            'approved_run_id' => $runIds[0],
            'invoice_id' => $invoiceIds[array_key_last($invoiceIds)],
            'counts' => [
                'clients' => count($clients),
                'guards' => count($guardRows),
                'shifts' => count($shiftRows),
                'gate_events' => count($gateRows),
                'alerts' => array_sum(array_map('count', $alertIds)),
                'incidents' => count($incidentIds),
                'requests' => count($requestRows),
                'audit_entries' => count($auditRows),
                'invoices' => count($invoiceIds),
                'payslips' => count($allGuards) * self::PAYROLL_RUNS,
            ],
        ];
    }

    /**
     * More of what is ONE officer's own — incidents with their photographs, requests, messages,
     * night watches with breaks — so a Guard App list that asked once per row asks more of it.
     *
     * A second `build()` adds colleagues at new clients, which leaves this officer's own lists
     * exactly as long as they were; this is what makes them longer. Inside the caller's
     * transaction, like everything here.
     */
    public static function moreOfTheirOwn(Guard $guard, int $count = 12): void
    {
        if (DB::connection('mysql')->transactionLevel() === 0) {
            throw new \RuntimeException('ScaleVolume writes central rows and must run inside a transaction the test rolls back.');
        }

        $db = DB::connection('mysql');
        $now = Carbon::now();
        $tenant = (string) $guard->tenant_id;
        $suffix = (string) random_int(100000, 999999);

        for ($k = 1; $k <= $count; $k++) {
            $at = $now->copy()->subHours($k * 7);

            // A night watch a week or two back, with a break, inside the range `shifts/me` is asked for.
            $start = $now->copy()->subDays(1 + ($k % 13))->setTime(19, 0);
            $shiftId = (int) $db->table('shifts')->insertGetId([
                'tenant_id' => $tenant, 'guard_id' => $guard->id, 'post_id' => $guard->post_id,
                'rostered_start' => $start, 'rostered_end' => $start->copy()->addHours(12),
                'actual_start' => $start->copy()->addMinutes(3), 'actual_end' => $start->copy()->addHours(12)->addMinutes(6),
                'start_method' => 'app', 'status' => 'completed', 'is_simulated' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('shift_breaks')->insert([
                'shift_id' => $shiftId, 'started_at' => $start->copy()->addHours(5), 'ended_at' => $start->copy()->addHours(5)->addMinutes(30),
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $incident = (int) $db->table('security_incidents')->insertGetId([
                'tenant_id' => $tenant, 'guard_id' => $guard->id, 'guard_name' => (string) $guard->full_name, 'kind' => ['suspicious', 'property', 'noise'][$k % 3],
                'detail' => 'Reported from the post, number '.$k.'.', 'severity' => ['low', 'med', 'high'][$k % 3], 'status' => 'open',
                'occurred_at' => $at, 'location' => 'Checkpoint '.$k, 'shift_id' => $shiftId,
                'logged_by_name' => $guard->full_name.' (Guard App)', 'is_simulated' => false, 'created_at' => $at, 'updated_at' => $at,
            ]);

            // Photographs on every other report, one or two of them.
            for ($p = 1; $k % 2 === 0 && $p <= 1 + ($k % 4 === 0 ? 1 : 0); $p++) {
                $db->table('incident_media')->insert([
                    'security_incident_id' => $incident, 'guard_id' => $guard->id, 'path' => 'incident-media/'.$tenant.'/'.$incident.'/'.$suffix.$p.'.jpg',
                    'filename' => 'photo-'.$p.'.jpg', 'content_type' => 'image/jpeg', 'bytes' => 1000 + $p, 'sha256' => hash('sha256', $suffix.$incident.$p),
                    'created_at' => $at, 'updated_at' => $at,
                ]);
            }

            $leave = $k % 2 === 0;
            $db->table('guard_requests')->insert([
                'tenant_id' => $tenant, 'guard_id' => $guard->id, 'kind' => $leave ? 'leave' : 'equipment', 'subject' => $leave ? 'Vacation' : 'Torch '.$k,
                'quantity' => $leave ? null : 1, 'starts_on' => $leave ? $now->copy()->addDays(30 + $k)->toDateString() : null,
                'ends_on' => $leave ? $now->copy()->addDays(31 + $k)->toDateString() : null, 'reason' => 'Volume', 'status' => $k % 3 === 0 ? 'approved' : 'pending',
                'decided_at' => $k % 3 === 0 ? $now->copy()->subDays($k) : null, 'is_simulated' => false,
                'created_at' => $now->copy()->subDays($k), 'updated_at' => $now,
            ]);

            $db->table('dispatch_messages')->insert([
                'tenant_id' => $tenant, 'direction' => $k % 2 === 0 ? 'outbound' : 'inbound', 'guard_id' => $guard->id,
                'body' => 'Message '.$k.' for the post.', 'sent_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
        }
    }

    /** A Gemini console user holding exactly one role, contact-verified by the factory. */
    public static function geminiUser(string $roleName): User
    {
        $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
        $user->syncRoles([$roleName]);

        return $user->fresh();
    }
}
