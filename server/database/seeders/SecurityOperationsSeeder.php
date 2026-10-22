<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\SecurityIncident;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\AdoptionRollup;
use App\Support\SiteTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A day at the gates, and the incident log behind it — board screens 26 and 27.
 *
 * TWO SCREENS THAT CANNOT BE REVIEWED EMPTY. The live activity feed reads as a
 * broken screen when it is blank rather than as a quiet night, and an incident
 * log with nothing in it cannot demonstrate the one distinction it exists to
 * draw — an incident somebody closed against one nobody did.
 *
 * THE DAY IS SEEDED WHOLE, NOT AS THE SEVEN ROWS THE BOARD DRAWS. The board's
 * KPI cards report sixty-three admits and four denials, and its feed shows the
 * seven most recent events; those two facts can only both be true if the day
 * behind them is real. So a real day is written — sixty-seven decisions spread
 * across the hours the gates were open — and the feed shows whatever the most
 * recent seven of it happen to be, merged with the patrol scans and shift
 * starts the dispatch seed already wrote.
 *
 * Everything here is flagged is_simulated. Nothing in this file came from a
 * handset, and the console says so rather than presenting a demonstration as a
 * record of a night that happened.
 */
class SecurityOperationsSeeder extends Seeder
{
    /** What the board's KPI cards report for the day. */
    private const ADMITS_TODAY = 63;

    private const DENIALS_TODAY = 4;

    /**
     * The three the board names, newest first: [verdict, subject, basis,
     * category, minutes ago, estate].
     *
     * Pinned to the two gate desks the board draws them at, and placed in the
     * last half hour so they are what the feed opens on.
     *
     * @var list<array{0: string, 1: string, 2: string|null, 3: string, 4: int, 5: string}>
     */
    private const DEPICTED = [
        ['admit', 'Marcia James admitted', 'QR pass · Lot 47', 'guest', 4, 'phoenixpark'],
        ['admit', 'Contractor van admitted', 'pre-approved', 'contractor', 9, 'oceanview'],
        ['deny', 'Unidentified visitor denied', 'no unit confirmed', 'guest', 21, 'phoenixpark'],
    ];

    /**
     * The rest of the day's admissions, cycled in order.
     *
     * A fixed list rather than a random one: a seed that produces different
     * text on every run makes a fidelity diff impossible to read, and the
     * difference between two runs then looks like a regression.
     *
     * @var list<array{0: string, 1: string|null, 2: string}>
     */
    private const ADMISSIONS = [
        ['Visitor admitted', 'QR pass · Lot 12', 'guest'],
        ['Grocery delivery admitted', 'QR pass · Lot 8', 'delivery'],
        ['Resident vehicle admitted', 'tag read', 'resident'],
        ['Visitor admitted', 'manual code · Lot 31', 'guest'],
        ['Landscaping crew admitted', 'pre-approved', 'contractor'],
        ['Resident vehicle admitted', 'tag read', 'resident'],
        ['Courier admitted', 'QR pass · Lot 22', 'delivery'],
        ['Visitor admitted', 'resident confirmed by phone', 'guest'],
        ['Pool service admitted', 'pre-approved', 'contractor'],
        ['Resident vehicle admitted', 'tag read', 'resident'],
        ['Visitor admitted', 'QR pass · Lot 5', 'guest'],
        ['Gas delivery admitted', 'pre-approved', 'delivery'],
    ];

    /**
     * The remaining denials. Each says what was missing, because a denial the
     * guard cannot explain is one the resident will argue with.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const DENIALS = [
        ['Delivery denied', 'no pass on file', 'delivery'],
        ['Visitor denied', 'resident not reachable', 'guest'],
        ['Contractor denied', 'outside approved hours', 'contractor'],
    ];

    /**
     * The incident log, oldest last — board screen 27.
     *
     * [days ago is not used: these carry real dates, because an incident log is
     * read against the month it happened in.]
     *
     * @var list<array{estate: string, employee: string, kind: string, severity: string, status: string, on: string, detail: string, resolution: string|null}>
     */
    private const INCIDENTS = [
        [
            'estate' => 'phoenixpark',
            'employee' => 'GS-1049',
            'kind' => 'Compliance — licence expiry',
            'severity' => 'med',
            'status' => SecurityIncident::OPEN,
            'on' => '2026-08-30',
            'detail' => 'PSRA licence lapsed while assigned to Service Gate. Guard stood down from post pending renewal; the gate is currently uncovered.',

            /*
             * Open, and therefore no resolution. The two move together: an
             * incident marked resolved with nothing recorded about what was
             * done reads the same as an open one to whoever comes to it later.
             */
            'resolution' => null,
        ],
        [
            'estate' => 'oceanview',
            'employee' => 'GS-1071',
            'kind' => 'Attempted unauthorized access',
            'severity' => 'low',
            'status' => SecurityIncident::RESOLVED,
            'on' => '2026-07-03',
            'detail' => 'Individual attempted to follow a resident vehicle through the barrier at Main Gate.',
            'resolution' => 'Challenged and escorted off the property by the guard on post. No entry gained; resident notified the same evening.',
        ],
        [
            'estate' => 'phoenixpark',
            'employee' => 'GS-1041',
            'kind' => 'Equipment fault — barrier arm sensor',
            'severity' => 'low',
            'status' => SecurityIncident::RESOLVED,
            'on' => '2026-05-19',
            'detail' => 'Barrier arm at Main Gate failed to lower after three consecutive vehicles.',
            'resolution' => 'Sensor realigned by the contractor on May 21 and confirmed working by the guard on post.',
        ],
    ];

    public function run(): void
    {
        if (Tenant::estates()->isEmpty()) {
            $this->command->warn('No estates provisioned; skipping security operations seed.');

            return;
        }

        $this->recordTheDayAtTheGates();
        $this->recordIncidents();

        /*
         * Last, and it has to be last: the roll-up counts the shifts, the
         * devices and the day's admissions that everything above just wrote.
         * Run before them it would report a quieter platform than the one the
         * seed leaves behind.
         */
        app(AdoptionRollup::class)->refresh();
    }

    /* --------------------------------------------------------- gate events */

    private function recordTheDayAtTheGates(): void
    {
        $desks = $this->gateDesks();

        if ($desks === []) {
            $this->command->warn('No staffed gate posts; skipping gate activity seed.');

            return;
        }

        foreach (self::DEPICTED as $i => [$verdict, $subject, $basis, $category, $minutesAgo, $subdomain]) {
            $desk = $this->deskAt($desks, $subdomain);

            if ($desk === null) {
                continue;
            }

            $this->writeEvent('seed-gate-depicted-'.$i, $desk, $verdict, $subject, $basis, $category, now()->subMinutes($minutesAgo));
        }

        $this->fillTheDay($desks);
    }

    /**
     * The rest of the day's traffic, spread across the hours the gates worked.
     *
     * Evenly spaced rather than clustered. A real gate is busier at some hours
     * than others, but a seed that bunched sixty admits into two hours to keep
     * the feed's top rows tidy would be data shaped to a picture rather than a
     * day — and the picture is not the thing being built.
     *
     * @param  list<array{guard: Guard, tenant: string, post: int, post_name: string}>  $desks
     */
    private function fillTheDay(array $desks): void
    {
        $bulk = (self::ADMITS_TODAY - 2) + (self::DENIALS_TODAY - 1);

        /*
         * Everything lands today and before the three the board names, because
         * the KPI cards count today and the feed is ordered by time. Both
         * bounds are clamped so the seed still works when it is run at half
         * past midnight, when there is no six-in-the-morning to start from.
         */
        $latest = now()->subMinutes(30);
        $earliest = Carbon::today()->addHours(6);

        if ($earliest->greaterThanOrEqualTo($latest)) {
            $earliest = Carbon::today();
        }

        if ($earliest->greaterThanOrEqualTo($latest)) {
            $latest = now();
        }

        $span = max(1, (int) $earliest->diffInSeconds($latest));

        for ($i = 0; $i < $bulk; $i++) {
            /*
             * Three arrivals at a gate, then three at the next, rather than
             * strict alternation. Alternating would pair each desk with the
             * same half of the admission list every time — every visitor at one
             * gate on a QR pass, every visitor at the other on a manual code —
             * and the pass take-up figure would then measure the loop counter
             * rather than the estate.
             */
            $desk = $desks[intdiv($i, 3) % count($desks)];
            $at = $earliest->copy()->addSeconds(intdiv($span * $i, $bulk));

            /*
             * The denials spaced through the day rather than in a run. Four
             * refusals in ten minutes is a different night from four across a
             * day, and the second is the one being described.
             */
            $denialSlot = intdiv($bulk, count(self::DENIALS) + 1);
            $isDenial = $denialSlot > 0 && $i > 0 && $i % $denialSlot === 0
                && intdiv($i, $denialSlot) <= count(self::DENIALS);

            if ($isDenial) {
                [$subject, $basis, $category] = self::DENIALS[intdiv($i, $denialSlot) - 1];
                $this->writeEvent('seed-gate-deny-'.$i, $desk, 'deny', $subject, $basis, $category, $at);

                continue;
            }

            [$subject, $basis, $category] = self::ADMISSIONS[$i % count(self::ADMISSIONS)];
            $this->writeEvent('seed-gate-admit-'.$i, $desk, 'admit', $subject, $basis, $category, $at);
        }
    }

    /**
     * One seeded gate decision.
     *
     * INSERT OR NOTHING — NEVER AN UPDATE (Q-M43, D-M127). `gate_events` is now
     * append-only at the database: the application user holds no UPDATE or DELETE
     * on it and BEFORE UPDATE / BEFORE DELETE triggers refuse the write even for
     * the schema owner. This used to be `updateOrCreate`, which rewrote yesterday's
     * seeded events with today's hours every time the seeder ran — and against the
     * locked table that is not a slow seeder, it is a fatal one.
     *
     * So the key carries the DAY it was seeded and the write is `firstOrCreate`.
     * Seeding twice on one day is a no-op; seeding on a new day writes that day's
     * traffic and leaves the previous day's standing, which is what a gate log
     * does. Nothing is ever edited and nothing is ever removed.
     *
     * @param  array{guard: Guard, tenant: string, post: int, post_name: string}  $desk
     */
    private function writeEvent(
        string $key,
        array $desk,
        string $verdict,
        string $subject,
        ?string $basis,
        string $category,
        Carbon $at,
    ): void {
        GateEvent::firstOrCreate(
            ['idempotency_key' => $key.'-'.Carbon::today()->format('Y-m-d')],
            [
                'tenant_id' => $desk['tenant'],
                'guard_id' => $desk['guard']->id,
                'guard_name' => $desk['guard']->full_name,
                'post_id' => $desk['post'],
                'post_name' => $desk['post_name'],
                'verdict' => $verdict,
                'category' => $category,
                'subject' => $subject,
                'basis' => $basis,
                'occurred_at' => $at,
                'device_time' => $at,
                'is_simulated' => true,
            ],
        );
    }

    /**
     * The gate desks somebody is actually standing.
     *
     * A guard whose licence has lapsed is not one: Devon Palmer is stood down
     * and Phoenix Park's Service Gate is uncovered, which is the state the
     * coverage board reports. Writing admissions under his name would put a
     * guard on a post that two other screens say is empty.
     *
     * @return list<array{guard: Guard, tenant: string, post: int, post_name: string}>
     */
    private function gateDesks(): array
    {
        return Guard::query()
            ->where('status', 'active')
            ->whereNotNull('post_id')
            ->with('post')
            ->get()
            ->filter(static fn (Guard $guard): bool => $guard->post?->type === 'gate'
                && $guard->licenceState() !== 'expired')
            ->map(static fn (Guard $guard): array => [
                'guard' => $guard,
                'tenant' => (string) $guard->tenant_id,
                'post' => (int) $guard->post_id,
                'post_name' => (string) $guard->post?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{guard: Guard, tenant: string, post: int, post_name: string}>  $desks
     * @return array{guard: Guard, tenant: string, post: int, post_name: string}|null
     */
    private function deskAt(array $desks, string $subdomain): ?array
    {
        foreach ($desks as $desk) {
            if ($desk['tenant'] === $subdomain) {
                return $desk;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------- incidents */

    private function recordIncidents(): void
    {
        $logger = User::query()->where('email', 'director@geminisecurity.test')->first();

        foreach (self::INCIDENTS as $row) {
            $estate = Tenant::find($row['estate']);

            if ($estate === null) {
                continue;
            }

            $guard = Guard::query()->where('employee_number', $row['employee'])->first();
            /*
             * 9:40 IN THE MORNING AT THE ESTATE (M4 §3.1), not 9:40 on the server's UTC clock —
             * `occurred_at` is office-typed (`SiteDateTime`), so the hour is built in the estate's
             * own zone and stored as the moment it means. Read as UTC digits it drew 4:40 AM.
             */
            $occurred = Carbon::parse($row['on'], SiteTime::zone((string) $estate->getTenantKey()))->setTime(9, 40);

            SecurityIncident::updateOrCreate(
                [
                    'tenant_id' => $estate->getTenantKey(),
                    'kind' => $row['kind'],
                    'occurred_at' => $occurred,
                ],
                [
                    'guard_id' => $guard?->id,
                    'guard_name' => $guard?->full_name,
                    'detail' => $row['detail'],
                    'severity' => $row['severity'],
                    'status' => $row['status'],
                    'resolution' => $row['resolution'],

                    // Closed two days after the event, which is what writing up
                    // an incident actually takes. Null while it is still open.
                    'closed_at' => $row['resolution'] === null ? null : $occurred->copy()->addDays(2),
                    'logged_by' => $logger?->id,
                    'logged_by_name' => $logger?->name,
                ],
            );
        }
    }
}
