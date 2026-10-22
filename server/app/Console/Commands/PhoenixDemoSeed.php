<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StatutoryRateVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Passes\VisitorPasses;
use App\Services\Payroll\PayrollCalculator;
use App\Support\DemonstrationAccounts;
use App\Support\SampleData;
use App\Support\Seeded\SeedsMarkedRows;
use App\Support\SiteTime;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * FILL PHOENIX PARK VILLAGE 1 WITH A DEMONSTRATION THAT CAN BE TAKEN OUT AGAIN (M8 §4.3).
 *
 *     php artisan phoenix:demo-seed --confirm
 *
 * The client presents to Phoenix Park's management committee, and what he was shown before was the
 * STORE-REVIEW estate: a deliberately read-only tenant wearing a banner that says so. Every action
 * he tried was refused by design, which reads exactly like a broken product. Meanwhile his own
 * estate — provisioned by M7 and correct in every way — was empty, because M7 §11 rule 7 says real
 * estates get no test data.
 *
 * This is the answer to both: Phoenix Park becomes fully populated and fully functional, with no
 * banner and nothing restricted, and every row it gains is recorded in `sample_data_ranges` so
 * `phoenix:demo-purge` can take the invented history back out before real residents arrive.
 *
 * WHAT IT DOES NOT DO:
 *   - it never touches the store-review estate, and refuses outright if pointed at one;
 *   - it invents no fact about Phoenix Park it could get wrong quietly. Where a real value is
 *     unknown — the gate's surveyed position, the amenities, the fee — a provisional one is used and
 *     listed in `QUESTIONS.md` under PPV-01 for the client to correct in one pass;
 *   - it prints no password. Accounts it creates are written to a credentials FILE, and the path is
 *     all that reaches the terminal (M8 §1 rule 6).
 *
 * IDEMPOTENT. Run it twice and the estate does not double: the estate's own records go through the
 * finance seeder, which updates rather than adds, and everything central here is keyed on a
 * reference this command owns. Each run records its OWN id ranges (B096): a re-seed never stretches
 * the first run's range over the days between them, and on the platform's shared tables only this
 * estate's demonstration rows are recorded — `SampleData::ownedRows()` lists the tables this
 * command writes there, and a new one written here has to be added to it.
 */
class PhoenixDemoSeed extends Command
{
    // Every row it writes carries the seeded mark (M33 §5.2), as well as falling in the ranges it records.
    use SeedsMarkedRows;

    protected $signature = 'phoenix:demo-seed
        {--confirm : yes, fill this real estate with sample data}
        {--estate=phoenixpark : the estate to fill}
        {--credentials= : where to write the accounts it creates (default: storage/app/private/phoenix-demo-credentials.txt)}';

    protected $description = 'Fill Phoenix Park Village 1 with removable demonstration content (M8 §4.3)';

    /** Twelve-hour watches, back to back, for a fortnight — so the app always has one to clock into. */
    private const WATCH_HOURS = 12;

    private const ROSTER_WATCHES = 28;

    /** The overtime multiple: Jamaica's Minimum Wage Act, time and a half. */
    private const OVERTIME_BP = 15_000;

    /** @var list<array{number: string, name: string, phone: string, rate: int}> */
    private const OFFICERS = [
        ['number' => 'GS-PPV-01', 'name' => 'Andre Campbell', 'phone' => '876-555-0141', 'rate' => 1_050_00],
        ['number' => 'GS-PPV-02', 'name' => 'Simone Grant', 'phone' => '876-555-0142', 'rate' => 1_050_00],
        ['number' => 'GS-PPV-03', 'name' => 'Oral Bennett', 'phone' => '876-555-0143', 'rate' => 1_125_00],
    ];

    /** One account per role the estate console actually has. "Accountant" is a Gemini role, not an estate one. */
    private const COMMITTEE = [
        'estate.community_super_admin' => 'Dahlia Reid',
        'estate.president' => 'Everton Salmon',
        'estate.vice_president' => 'Marcia Bennett',
        'estate.secretary' => 'Kayla Morrison',
        'estate.treasurer' => 'Norman Case',
        'estate.property_manager' => 'Trevor Wright',
        'estate.admin_assistant' => 'Shanice Palmer',

        /*
         * The estate's OWN Head of Security (M10 §2) — the community's, not
         * Gemini's. Gemini's Head of Security is Devon Clarke on the company
         * console; this is the committee member responsible for security at
         * Phoenix Park, and the two are different people in different
         * organisations. Seeded so the role can be demonstrated.
         */
        'estate.head_of_security' => 'Clifton Rowe',
    ];

    /** The features the committee will be shown. Recorded in DEPLOYMENT.md. */
    private const FEATURES = ['resident_core', 'visitor_passes', 'amenity_booking', 'evoting', 'gated_meetings', 'guard_app'];

    /**
     * PHOENIX PARK VILLAGE 1'S OWN SIZE. FIVE NUMBERS. THIS IS THE ONLY PLACE THEY LIVE.
     *
     * The client, 23 September 2026: five phases named exactly "Phase 1" … "Phase 5", lots numbered
     * 1 to 1999 plus one more, 2,000 in total. The per-phase breakdown is still coming (PPV-10), so
     * the 1,999 numbered lots are split as evenly as 1,999 divides: 400, 400, 400, 400, 399. The
     * 2,000th lot makes the fifth phase 400 too.
     *
     * WHEN THE REAL BREAKDOWN ARRIVES, change the second column and re-seed. Nothing else in this
     * command, in the estate seeder or in the app knows how big a phase is: the Guard App reads the
     * phases from the API, the console counts the units, and the ballot roll follows the households.
     *
     * The THIRD column is how many of that phase's lots have a household on them, and it is
     * deliberately not 2,000. Inventing two thousand families would be inventing two thousand
     * people; 441 households across all five phases is a demonstration, and the remaining 1,559 lots
     * stand vacant, which is both realistic and true. Those five figures are the boards' own
     * occupancy (90, 98, 86, 89, 78), which is what the arrears fit is solved against.
     *
     * @var list<array{0: string, 1: int, 2: int}>
     */
    private const PHASES = [
        ['Phase 1', 400, 90],
        ['Phase 2', 400, 98],
        ['Phase 3', 400, 86],
        ['Phase 4', 400, 89],
        ['Phase 5', 399, 78],
    ];

    /**
     * THE 2,000th LOT, WHOSE NAME NOBODY HAS TOLD US YET (QUESTIONS PPV-09).
     *
     * The client says there are 2,000 lots and that 1,999 of them are numbered 1 to 1999. The last
     * one is something else — `Lot 2000`, or a subdivision such as `Lot 1999A`, or a cottage with a
     * name. It is seeded as the odd one out ON PURPOSE: the only lot on the estate whose reference
     * is not a plain number, vacant, and named in the seeder's own closing report, so that nobody
     * mistakes it for a confirmed fact. Correcting it is this one constant and a re-seed.
     */
    private const EXTRA_LOT = 'Lot 1999A';

    /** Which phase the extra lot is provisionally placed in — the last one, where the fill ends. */
    private const EXTRA_LOT_PHASE = 'Phase 5';

    public function handle(PayrollCalculator $payroll): int
    {
        $key = (string) $this->option('estate');

        if (! $this->option('confirm')) {
            $this->error("Refused: this writes invented history into {$key}, a real estate. Re-run with --confirm if that is what you mean.");

            return self::FAILURE;
        }

        $estate = Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error("There is no estate `{$key}` on this platform. Provision it first: php artisan estate:provision {$key} \"Phoenix Park Village 1\" --status=active");

            return self::FAILURE;
        }

        if ((bool) $estate->getAttribute('demo') === true) {
            $this->error("Refused: `{$key}` is the store-review estate. That one is seeded by `demo:seed` and must keep its banner (M8 §1 rule 4).");

            return self::FAILURE;
        }

        $this->info("Filling {$estate->name} ({$key}) with demonstration content.");

        $this->activate($estate);
        $this->features($estate);

        /*
         * THE HIGH-WATER MARKS, BEFORE ANYTHING IS WRITTEN. Everything above these lines in the
         * estate's own tables is what this run added. In the platform's tables it is what EVERY
         * estate added while this ran, so only this estate's demonstration rows among it are
         * recorded (`SampleData::record`), and only those are what `phoenix:demo-purge` takes out.
         */
        $beforeCentral = SampleData::highWater('mysql');
        $beforeTenant = $estate->run(fn (): array => SampleData::highWater('tenant'));

        /*
         * The estate's own database: units, households, residents, dues, amenities and bookings,
         * maintenance, notices, meetings and minutes, and the elections — through the seeder the
         * console's own demonstration data has always come from, so nothing here is a second
         * opinion about what an estate looks like.
         *
         * ONLY INTO AN EMPTY ESTATE, and that is a property of the seeder rather than a choice made
         * here: it writes a booking, cancels it, and then moves a deposit onto it, so a second pass
         * over its own output stops at "that booking was cancelled, there is nothing to secure" —
         * correctly. An estate that already has units is therefore left alone and said so, and the
         * way to fill it again is `phoenix:demo-purge` first, which is the cycle M8 §4.4 asks to be
         * proved anyway. Everything central below IS idempotent and is refreshed on every run.
         */
        $alreadyPopulated = $estate->run(fn (): int => (int) DB::connection('tenant')->table('units')->count()) > 0;

        if ($alreadyPopulated) {
            $this->warn('  the estate already has units — its own records are left exactly as they are.');
            $this->warn('  to rebuild them: php artisan phoenix:demo-purge --confirm --estate='.$key.'   then run this again.');
        } else {
            /*
             * PHOENIX PARK'S OWN SIZE, NOT THE BOARD'S.
             *
             * The estate seeder is also the Estate Console's demonstration data and the test
             * fixture, both of which are the board's 450-unit estate and must stay that way. So its
             * phase table is set to this estate's for the duration of the call and put back in a
             * `finally` — if this throws, the next caller still gets the 450 it expects.
             */
            EstateFinanceSeeder::$phases = self::PHASES;

            try {
                $estate->run(function (): void {
                    Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);
                });
            } finally {
                EstateFinanceSeeder::$phases = null;
            }

            $estate->run(fn () => $this->extraLot());

            $lots = array_sum(array_column(self::PHASES, 1)) + 1;
            $homes = array_sum(array_column(self::PHASES, 2));
            $this->line("  the estate: {$lots} lots across ".count(self::PHASES).' phases, '.number_format($homes).' with households on them and the rest standing vacant');
            $this->line('  and on them: dues, amenities, maintenance, notices, meetings, elections');
        }

        /*
         * RECORDED EVEN IF THIS FAILS HALF-WAY, and that is not a nicety. A run that stopped at the
         * payroll — as an early version of this command did — would have left several thousand
         * invented records in a real estate with nothing saying which they were, and no way to take
         * them out again. The ranges are written in a `finally`, so an exception costs the client an
         * error message and never an unpurgeable estate.
         */
        $accounts = [];

        try {
            $post = $this->post($estate);
            $officers = $this->officers($estate, $post);
            $this->roster($estate, $post, $officers);
            $this->standingOrders($estate, $post, $officers[0]);
            $this->patrol($estate, $post, $officers[1]);
            $this->visitorPasses($estate);
            $this->gateLog($estate, $post, $officers[1]);
            $this->incidents($estate, $officers[1]);
            $this->panic($estate);
            $this->messages($estate, $officers[0]);
            $this->requests($estate, $officers[0]);
            $this->payroll($estate, $officers, $payroll);

            $this->line('  the gate: officers, a watch in progress, standing orders, a patrol round, visitor passes, the gate log, incidents, a resolved panic, payslips');

            $accounts = $this->committee($estate);
        } finally {
            SampleData::record(SampleData::PHOENIX_TAG, $key, 'mysql', $beforeCentral);
            $estate->run(fn () => SampleData::record(SampleData::PHOENIX_TAG, $key, 'tenant', $beforeTenant));
        }

        $path = $this->credentials($estate, $accounts);

        $this->newLine();
        $this->info('Done. Everything this run added is recorded in `sample_data_ranges` and comes out with:');
        $this->line("    php artisan phoenix:demo-purge --confirm --estate={$key}");
        $this->newLine();
        $this->info("The accounts it created are in: {$path}");
        $this->warn('Provisional facts used (PPV-01 in QUESTIONS.md): the amenities and their rules, the fee schedule and the arrears policy. The client corrects them in one pass. The gates have no position until somebody stands at them (GATE_POSITIONS.md).');
        $this->warn('The 2,000th lot is seeded as `'.self::EXTRA_LOT.'` because nobody has told us its real name yet (PPV-09) — it is the only lot here that is not a plain number.');
        $this->warn('The lots are split evenly across the five phases ('.implode(', ', array_column(self::PHASES, 1)).') until the client sends the real breakdown (PPV-10). One table at the top of this file.');

        return self::SUCCESS;
    }

    /**
     * The 2,000th lot — the one the client has not named yet (PPV-09).
     *
     * Vacant, so it carries no household, no dues and no part of the arrears fit: it is estate
     * STRUCTURE, which is what the client confirmed, and nothing hangs off it that would have to be
     * unpicked when its real name arrives.
     */
    private function extraLot(): void
    {
        Unit::query()->firstOrCreate(
            ['reference' => self::EXTRA_LOT],
            [
                'block' => self::EXTRA_LOT_PHASE,
                'street' => self::EXTRA_LOT_PHASE.' Drive',
                'type' => 'residential',
                'status' => 'vacant',
            ],
        );
    }

    /**
     * An estate that is still `onboarding` takes no Resident App sign-ins at all.
     *
     * `AuthController::estate()` refuses every estate whose status is not `active`, with
     * `estate_not_found` — "No estate on this platform by that name is taking sign-ins". So this
     * command could fill 2,000 lots, a committee, six months of dues and an election, and the
     * Resident App would still stop dead on its first screen with a message that reads as though
     * the estate does not exist. Nothing said so: the console looked complete.
     *
     * Found on 23 September 2026 by calling `auth/otp/request` against production from outside,
     * after the client reported the app could not reach the server. The estate had been
     * provisioned `onboarding` and never activated, because activation is normally the Gemini
     * console's own act (`ClientOnboarding`) and no one had performed it.
     *
     * This is the demonstration estate and it is meant to be live, so the seed asserts it —
     * with the same status the console's activation sets, and never for the store-review estate,
     * which is refused above.
     */
    private function activate(Tenant $estate): void
    {
        if ($estate->status === 'active') {
            return;
        }

        $this->warn("Estate status was `{$estate->status}`; a resident cannot sign in to an estate that is not active. Setting it to `active`.");

        $estate->forceFill(['status' => 'active'])->save();
    }

    /** Turn on what the committee will be shown, and say so. */
    private function features(Tenant $estate): void
    {
        $estate->run(function (): void {
            foreach (self::FEATURES as $key) {
                DB::connection('tenant')->table('estate_features')->updateOrInsert(
                    ['feature_key' => $key],
                    ['enabled' => true, 'reason' => 'Enabled for the Phoenix Park Village 1 demonstration (M8 §4.2).', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                );
            }
        });

        $this->line('  features on: '.implode(', ', self::FEATURES));
    }

    private function post(Tenant $estate): Post
    {
        $post = Post::query()->firstOrCreate(
            ['tenant_id' => $estate->getTenantKey(), 'name' => 'Main Gate'],
            ['type' => 'gate', 'is_active' => true],
        );

        /*
         * NO POSITION IS INVENTED (M26 §1.3, D-225). This used to place the Main Gate at a point on Waterloo Road "to the
         * nearest few hundred metres", and a geofence around the wrong point refuses a real officer. The Main Gate is
         * unsurveyed until somebody stands there (GATE_POSITIONS.md); until then a demonstration server starts a shift
         * there and marks it for the office, and a live one asks for the supervisor's PIN. The demonstration places its
         * own post where the presenter stands (DEMO_SCRIPT 2.1).
         */
        $post->forceFill(['is_active' => true])->save();

        return $post;
    }

    /**
     * Officers of this estate, with an hourly rate so the payslip below is arithmetic.
     *
     * @return list<Guard>
     */
    private function officers(Tenant $estate, Post $post): array
    {
        $officers = [];

        foreach (self::OFFICERS as $i => $spec) {
            $guard = Guard::query()->updateOrCreate(
                ['employee_number' => $spec['number']],
                [
                    'full_name' => $spec['name'],
                    'psra_number' => 'PSRA-'.str_replace('GS-', '', $spec['number']),
                    'psra_expires_on' => Carbon::now()->addMonths(9)->toDateString(),
                    'employment_type' => 'full_time',
                    'status' => 'active',
                    'hired_on' => Carbon::now()->subMonths(14 - $i)->toDateString(),
                    'tenant_id' => $estate->getTenantKey(),
                    'post_id' => $post->id,
                    'standard_rate_minor' => $spec['rate'],
                    'standard_rate_currency' => 'JMD',
                    'leave_entitlement_days' => 14,
                    'phone' => $spec['phone'],
                ],
            );

            // Never a reviewer: that flag is the store-review estate's, and an officer here carrying
            // it would be a second way past four gates (D-M034's reasoning, applied to this estate).
            $guard->forceFill(['reviewer' => false, 'reviewer_code_hash' => null])->save();

            $officers[] = $guard;
        }

        return $officers;
    }

    /**
     * A fortnight of watches for the first officer (so the app always has one to clock into), a
     * watch IN PROGRESS for the second (so the dashboard has a colleague on duty), and two worked
     * watches behind them with handover notes.
     *
     * @param  list<Guard>  $officers
     */
    private function roster(Tenant $estate, Post $post, array $officers): void
    {
        $now = Carbon::now();
        $site = $estate->getTenantKey();

        // The one in progress — keyed on its handover note, which nothing else on this platform writes.
        Shift::query()->updateOrCreate(
            ['tenant_id' => $site, 'guard_id' => $officers[1]->id, 'handover_note' => 'On post. Gate barrier serviced Tuesday. Sample data.'],
            [
                'post_id' => $post->id,
                'rostered_start' => $now->copy()->subHours(3),
                'rostered_end' => $now->copy()->addHours(9),
                'actual_start' => $now->copy()->subHours(3)->addMinutes(4),
                'start_method' => 'app',
                'status' => 'on_duty',
            ],
        );

        // Two already worked, so My Hours and the payroll period below have something behind them.
        foreach ([1, 2] as $back) {
            Shift::query()->updateOrCreate(
                ['tenant_id' => $site, 'guard_id' => $officers[0]->id, 'handover_note' => "Watch closed, day -{$back}. Sample data."],
                [
                    'post_id' => $post->id,
                    'rostered_start' => $now->copy()->subDays($back)->setTime(6, 0),
                    'rostered_end' => $now->copy()->subDays($back)->setTime(18, 0),
                    'actual_start' => $now->copy()->subDays($back)->setTime(5, 56),
                    'actual_end' => $now->copy()->subDays($back)->setTime(18, 3),
                    'start_method' => 'app',
                    'status' => 'completed',
                ],
            );
        }

        // And a fortnight ahead for the first officer, rebuilt each run so the clock-in walk always
        // has an un-started watch inside its window — the same reasoning as the store-review estate's.
        Shift::query()
            ->where('tenant_id', $site)
            ->where('guard_id', $officers[0]->id)
            ->whereNull('actual_start')
            ->where('rostered_end', '>=', $now)
            ->delete();

        $start = $now->copy()->startOfHour();

        for ($watch = 0; $watch < self::ROSTER_WATCHES; $watch++) {
            Shift::query()->create([
                'tenant_id' => $site,
                'guard_id' => $officers[0]->id,
                'post_id' => $post->id,
                'rostered_start' => $start->copy(),
                'rostered_end' => $start->copy()->addHours(self::WATCH_HOURS),
                'status' => Shift::ROSTERED,
            ]);

            $start = $start->copy()->addHours(self::WATCH_HOURS);
        }
    }

    private function standingOrders(Tenant $estate, Post $post, Guard $officer): void
    {
        $site = $estate->getTenantKey();

        $set = DB::connection('mysql')->table('standing_order_sets')->where('tenant_id', $site)->where('title', 'Main Gate — standing orders')->first();

        if ($set === null) {
            $id = DB::connection('mysql')->table('standing_order_sets')->insertGetId([
                'title' => 'Main Gate — standing orders',
                'category' => 'gate',
                'summary' => 'How the Main Gate is worked: passes, walk-ups, contractors and the barrier.',
                'tenant_id' => $site,
                'post_id' => $post->id,
                'version' => 1,
                'body' => "1. Check every pass on the handset. A pass that will not scan is typed in, never waved through.\n2. A visitor with no pass waits at the Visitor Entrance while the household is asked.\n3. Contractors sign in and out; their vehicle plate goes on the record.\n4. The barrier stays down between vehicles.\n5. Anything you cannot settle, call the control room before you decide it.\n\nSample data for the demonstration.",
                'effective_on' => Carbon::now()->subDays(6)->toDateString(),
                'reviewed_on' => Carbon::now()->subDays(6)->toDateString(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } else {
            $id = (int) $set->id;
        }

        DB::connection('mysql')->table('standing_order_versions')->updateOrInsert(
            ['standing_order_set_id' => $id, 'version' => 1],
            [
                'title' => 'Main Gate — standing orders',
                'summary' => 'How the Main Gate is worked.',
                'body' => 'Published for the demonstration. Sample data.',
                'effective_on' => Carbon::now()->subDays(6)->toDateString(),
                'change_note' => 'First publication.',
                'published_by_name' => 'Property Manager',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        );

        // One officer has acknowledged, one has not — so the Guard App shows both states.
        DB::connection('mysql')->table('standing_order_acknowledgements')->updateOrInsert(
            ['standing_order_set_id' => $id, 'guard_id' => $officer->id, 'version' => 1],
            ['acknowledged_at' => Carbon::now()->subDays(5), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
        );
    }

    private function patrol(Tenant $estate, Post $post, Guard $officer): void
    {
        $site = $estate->getTenantKey();

        /*
         * THE NINE THE BOARD DRAWS, in the board's own order (guard-app-07, "Phase 2 Perimeter").
         * They were four invented ones until 23 September 2026, when the client ruled that the
         * boards are the content source and not only the layout.
         */
        $labels = [
            'Main Gate',
            'Generator Room',
            'Pool Deck',
            'Phase 1 Boundary',
            'Playground — Phase 2',
            'Service Gate',
            'Phase 3 Boundary',
            'Club House',
            'Community Centre',
        ];

        /*
         * A CHECKPOINT CODE IS UNIQUE ACROSS THE WHOLE PLATFORM, not within an estate — the column
         * says so, and rightly: the code is what is printed on the physical tag screwed to the wall,
         * and one tag scanned at one estate must never mean a checkpoint at another. So the code
         * carries the estate. Seeding a second estate used to fail on `PPV-CP-01` already existing,
         * which is how this was found.
         */
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $site) ?? 'EST', 0, 12));

        $points = [];
        foreach ($labels as $i => $label) {
            $points[] = ['label' => $label, 'code' => $prefix.'-CP-'.sprintf('%02d', $i + 1)];
        }

        foreach ($points as $i => $point) {
            $id = DB::connection('mysql')->table('patrol_checkpoints')->where('tenant_id', $site)->where('code', $point['code'])->value('id');

            if ($id === null) {
                $id = DB::connection('mysql')->table('patrol_checkpoints')->insertGetId([
                    'tenant_id' => $site,
                    'post_id' => $post->id,
                    'label' => $point['label'],
                    'code' => $point['code'],
                    'sequence' => $i + 1,
                    'is_active' => true,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            // This morning's completed round, so the Patrol tab is not an empty list: from 5 AM ON THE ESTATE'S CLOCK
            // (M19A §8 — no hand-rolled hours). UTC midnight plus five hours was midnight in Kingston, not the morning.
            // 5:00 AM at the estate, then every 12 minutes — a time of day on the site's clock, not an offset.
            $at = SiteTime::today($site)->setTime(5, 0)->addMinutes(12 * $i)->utc();

            DB::connection('mysql')->table('checkpoint_scans')->updateOrInsert(
                ['patrol_checkpoint_id' => (int) $id, 'guard_id' => $officer->id, 'server_time' => $at],
                ['device_time' => $at, 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => true],
            );
        }
    }

    /**
     * A visitor pass in every state a gate meets (M8 §4.3).
     *
     * THROUGH THE REAL SERVICE, never by writing the columns. A pass carries an Ed25519 signature
     * over its own contents, and a hand-built row would look perfect in the list and then be refused
     * at the gate in front of the committee — the one failure a demonstration cannot survive.
     * `VisitorPasses::issue()` signs with the estate's current key, exactly as the Resident App does.
     */
    private function visitorPasses(Tenant $estate): void
    {
        $estate->run(function () use ($estate): void {
            $passes = app(VisitorPasses::class);
            $now = Carbon::now();

            $homes = Unit::query()->orderBy('id')->limit(5)->get();

            if ($homes->isEmpty()) {
                return;
            }

            $plan = [
                ['name' => 'Kimberly Douglas', 'category' => 'single', 'from' => $now->copy()->subHours(1), 'to' => $now->copy()->addHours(5), 'state' => 'active'],
                ['name' => 'Delroy Henry', 'category' => 'single', 'from' => $now->copy()->addDay()->setTime(14, 0), 'to' => $now->copy()->addDay()->setTime(18, 0), 'state' => 'upcoming'],
                ['name' => 'Island Grocers', 'category' => 'delivery', 'from' => $now->copy()->subHours(6), 'to' => $now->copy()->subHours(4), 'state' => 'used'],
                ['name' => 'Blue Mountain Plumbing', 'category' => 'contractor', 'from' => $now->copy()->subDays(2), 'to' => $now->copy()->subDays(2)->addHours(6), 'state' => 'expired'],
                ['name' => 'Cancelled Caller', 'category' => 'single', 'from' => $now->copy()->addDays(2), 'to' => $now->copy()->addDays(2)->addHours(3), 'state' => 'cancelled'],
            ];

            foreach ($plan as $i => $spec) {
                $unit = $homes[$i % $homes->count()];
                $resident = Resident::query()->whereHas('household', fn ($q) => $q->where('unit_id', $unit->id))->orderBy('id')->first();
                $key = 'ppv-demo-pass-'.Str::slug($spec['name']);

                if (VisitorPass::query()->where('idempotency_key', $key)->exists()) {
                    continue;
                }

                $pass = $passes->issue(
                    (string) $estate->getTenantKey(),
                    $unit,
                    $resident?->id,
                    (string) ($resident->full_name ?? 'The household'),
                    [
                        'category' => $spec['category'],
                        'visitor_name' => $spec['name'],
                        'purpose' => 'Sample data for the demonstration',
                        'valid_from' => $spec['from'],
                        'valid_to' => $spec['to'],
                    ],
                    $key,
                    true,
                );

                // The states a gate actually meets. `expired` is not a status — a pass whose window
                // has passed IS expired, which is why that one is simply issued in the past.
                if ($spec['state'] === 'used') {
                    $pass->forceFill(['status' => VisitorPass::USED, 'used_at' => $spec['to']->copy()->subMinutes(20)])->save();
                }

                if ($spec['state'] === 'cancelled') {
                    $passes->cancel($pass);
                }
            }
        });
    }

    /**
     * The day at the gate. APPEND-ONLY: these rows can never be deleted, by anyone (D-M148), so
     * every one is marked `is_simulated` and the consoles badge it as sample data for ever.
     */
    private function gateLog(Tenant $estate, Post $post, Guard $officer): void
    {
        $site = $estate->getTenantKey();
        $today = Carbon::now()->startOfDay();

        $entries = [
            // The gate API's own verdicts (`GateEvent::ADMIT`, `DENY`), which every reader
            // compares against. This wrote `admitted` and `denied` until B088.
            ['verdict' => GateEvent::ADMIT, 'category' => 'single', 'subject' => 'Kimberly Douglas', 'basis' => 'Pass verified · Lot 18', 'at' => $today->copy()->addHours(8)->addMinutes(12)],
            ['verdict' => GateEvent::ADMIT, 'category' => 'delivery', 'subject' => 'Island Grocers', 'basis' => 'Pass verified · Lot 42', 'at' => $today->copy()->addHours(9)->addMinutes(40)],
            ['verdict' => GateEvent::ADMIT, 'category' => 'contractor', 'subject' => 'Blue Mountain Plumbing', 'basis' => 'Pass verified · Lot 7', 'at' => $today->copy()->addHours(10)->addMinutes(5)],
            ['verdict' => GateEvent::DENY, 'category' => 'walk_up', 'subject' => 'Unnamed caller', 'basis' => 'Household did not answer within three minutes', 'at' => $today->copy()->addHours(11)->addMinutes(22)],
            ['verdict' => GateEvent::ADMIT, 'category' => 'walk_up', 'subject' => 'Andrea Fletcher (guest)', 'basis' => 'Approved by Lot 23', 'at' => $today->copy()->addHours(13)->addMinutes(2)],
        ];

        foreach ($entries as $entry) {
            $key = 'ppv-demo-'.md5($entry['subject'].$entry['at']->toIso8601String());

            if (DB::connection('mysql')->table('gate_events')->where('idempotency_key', $key)->exists()) {
                continue;
            }

            DB::connection('mysql')->table('gate_events')->insert([
                'tenant_id' => $site,
                'guard_id' => $officer->id,
                'guard_name' => $officer->full_name,
                'post_id' => $post->id,
                'post_name' => $post->name,
                'verdict' => $entry['verdict'],
                'category' => $entry['category'],
                'subject' => $entry['subject'],
                'basis' => $entry['basis'],
                'occurred_at' => $entry['at'],
                'device_time' => $entry['at'],
                'idempotency_key' => $key,
                'is_simulated' => true,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    private function incidents(Tenant $estate, Guard $officer): void
    {
        $site = $estate->getTenantKey();

        $rows = [
            ['kind' => 'suspicious', 'severity' => 'med', 'status' => 'closed', 'detail' => 'Grey pickup parked at the rear gate for twenty minutes, no plates. Moved off when approached. Sample data.', 'resolution' => 'Patrolled twice more; nothing further. Closed.', 'hours' => 30],
            // M26: not a "pedestrian gate on the Phase 3 side" — the estate has no gate there (PhoenixTwoGates::INCIDENT_AFTER).
            ['kind' => 'property', 'severity' => 'low', 'status' => 'open', 'detail' => PhoenixTwoGates::INCIDENT_AFTER, 'resolution' => null, 'hours' => 6],
        ];

        foreach ($rows as $row) {
            $at = Carbon::now()->subHours($row['hours']);

            DB::connection('mysql')->table('security_incidents')->updateOrInsert(
                ['tenant_id' => $site, 'detail' => $row['detail']],
                [
                    'guard_id' => $officer->id,
                    'guard_name' => $officer->full_name,
                    'kind' => $row['kind'],
                    'severity' => $row['severity'],
                    'status' => $row['status'],
                    'occurred_at' => $at,
                    'resolution' => $row['resolution'],
                    'closed_at' => $row['status'] === 'closed' ? $at->copy()->addHours(2) : null,
                    'logged_by_name' => $officer->full_name,
                    'is_simulated' => true,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ],
            );
        }
    }

    /** One panic, raised by a household and resolved — with the timeline the console draws. */
    private function panic(Tenant $estate): void
    {
        $site = $estate->getTenantKey();
        $at = Carbon::now()->subHours(20);
        $key = 'ppv-demo-panic-1';

        if (DB::connection('mysql')->table('duress_alerts')->where('idempotency_key', $key)->exists()) {
            return;
        }

        DB::connection('mysql')->table('duress_alerts')->insert([
            'tenant_id' => $site,
            'kind' => 'panic',
            'mode' => 'audible',
            'raised_by_name' => 'Lot 23 household',
            'unit_reference' => 'Lot 23',
            'status' => 'resolved',
            'device_time' => $at,
            'server_time' => $at,
            'clock_skewed' => false,
            'captured_offline' => false,
            'idempotency_key' => $key,
            'acknowledged_at' => $at->copy()->addSeconds(38),
            'resolved_at' => $at->copy()->addMinutes(14),
            'resolution_note' => 'Officer attended within four minutes. A neighbour’s alarm; nothing found. Sample data.',
            'is_simulated' => true,
            'alerted_handsets' => 2,
            'alerted_via' => 'on_duty',
            'escalated_contacts' => 0,
            'sms_recipients' => 4,
            'sms_delivered' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function messages(Tenant $estate, Guard $officer): void
    {
        $site = $estate->getTenantKey();

        $rows = [
            ['direction' => 'broadcast', 'guard_id' => null, 'body' => 'All posts: the Phase 3 water main is being worked on Thursday 10 AM to 2 PM. Expect NWC vehicles. Sample data.', 'hours' => 26],
            ['direction' => 'outbound', 'guard_id' => $officer->id, 'body' => 'Andre — the committee walks the estate at 4 PM. Meet them at the Main Gate. Sample data.', 'hours' => 4],
        ];

        foreach ($rows as $row) {
            DB::connection('mysql')->table('dispatch_messages')->updateOrInsert(
                ['tenant_id' => $site, 'body' => $row['body']],
                [
                    'direction' => $row['direction'],
                    'guard_id' => $row['guard_id'],
                    'sent_at' => Carbon::now()->subHours($row['hours']),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ],
            );
        }
    }

    private function requests(Tenant $estate, Guard $officer): void
    {
        $site = $estate->getTenantKey();

        $rows = [
            ['kind' => 'equipment', 'subject' => 'Torch — replacement', 'status' => 'approved', 'note' => 'Collect from the storekeeper. Sample data.'],
            ['kind' => 'equipment', 'subject' => 'Raincoat', 'status' => 'pending', 'note' => null],
            ['kind' => 'leave', 'subject' => 'Two days, family', 'status' => 'declined', 'note' => 'Roster is short that week — try the following one. Sample data.'],
        ];

        foreach ($rows as $row) {
            DB::connection('mysql')->table('guard_requests')->updateOrInsert(
                ['tenant_id' => $site, 'guard_id' => $officer->id, 'subject' => $row['subject']],
                [
                    'kind' => $row['kind'],
                    'status' => $row['status'],
                    'reason' => 'Sample data for the demonstration.',
                    'decided_at' => $row['status'] === 'pending' ? null : Carbon::now()->subDays(2),
                    'decision_note' => $row['note'],
                    'is_simulated' => true,
                    'created_at' => Carbon::now()->subDays(4),
                    'updated_at' => Carbon::now(),
                ],
            );
        }
    }

    /**
     * A completed pay period WITH OVERTIME, so a guard's payslip has real numbers to check (M8 §5.3).
     *
     * The figures come from `PayrollCalculator` — the same engine the estate's own payroll screens
     * use — so what the handset shows and what the platform would pay are the same arithmetic, not
     * two implementations that agree today.
     *
     * @param  list<Guard>  $officers
     */
    private function payroll(Tenant $estate, array $officers, PayrollCalculator $calculator): void
    {
        $periodEnd = Carbon::now()->startOfMonth()->subDay();
        $periodStart = $periodEnd->copy()->startOfMonth();

        /*
         * `inForceOn`, not `forPayDate`: the selector REFUSES when no card is in force — rightly, for
         * a real run — so the warning below could never be reached, and a platform without last
         * month's card lost the whole rest of the demonstration to a DomainException at this step.
         * This seeder's answer to a missing card is to say so and seed no payslips.
         */
        $rates = StatutoryRateVersion::inForceOn($periodEnd->toDateString());

        if (! $rates instanceof StatutoryRateVersion) {
            $this->warn('  no statutory rate card covers '.$periodEnd->toDateString().' — payslips skipped. Run db:seed --class=StatutoryRatesSeeder.');

            return;
        }

        /*
         * THE RUN IS BUILT, THEN APPROVED — never edited afterwards. An approved run refuses every
         * change by design (`PayrollRun::booted`), because a wage that was approved and then quietly
         * altered is the thing payroll controls exist to prevent. So the totals are known before the
         * run is approved, and approval is the last thing that happens to it.
         *
         * The reference carries the estate, so two estates seeded on the same platform in the same
         * month do not collide on one run.
         */
        $reference = strtoupper(Str::substr((string) $estate->getTenantKey(), 0, 6)).'-DEMO-'.$periodEnd->format('Y-m');

        $run = PayrollRun::query()->firstOrNew(['reference' => $reference]);

        if (in_array($run->status, ['approved', 'paid'], true)) {
            return; // already built by an earlier run of this command, and approved. Leave it alone.
        }

        $run->fill([
            'period_label' => $periodEnd->format('F Y'),
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'periods_per_year' => 12,
            'statutory_rate_version_id' => $rates->id,
            'status' => 'calculated',
            'currency' => 'JMD',
            'gross_minor' => 0,
            'net_minor' => 0,
        ])->save();

        $grossTotal = 0;
        $netTotal = 0;

        foreach ($officers as $i => $officer) {
            // A month of twelve-hour watches, with a few hours of overtime on top — different per
            // officer, so the screen is not three copies of one number.
            $earnings = $calculator->earnings(
                regularHours: 176.0,
                overtimeHours: [12.5, 6.0, 18.25][$i] ?? 8.0,
                rateMinor: (int) $officer->standard_rate_minor,
                overtimeBp: self::OVERTIME_BP,
                allowancesMinor: 3_500_00,
            );

            $slip = $calculator->payslip($earnings['gross_minor'], $rates, 12, (int) ($officer->approved_pension_minor ?? 0));
            $employer = $calculator->employerCost($earnings['gross_minor'], $rates, 12, true, (int) ($officer->approved_pension_minor ?? 0));

            Payslip::query()->updateOrCreate(
                ['payroll_run_id' => $run->id, 'guard_id' => $officer->id],
                [
                    'tenant_id' => $estate->getTenantKey(),
                    ...$earnings,
                    ...$slip,
                    'employer_nis_minor' => $employer['nis_minor'],
                    'employer_nht_minor' => $employer['nht_minor'],
                    'employer_education_tax_minor' => $employer['education_tax_minor'],
                    'employer_heart_minor' => $employer['heart_minor'],
                    'currency' => 'JMD',
                ],
            );

            $grossTotal += $earnings['gross_minor'];
            $netTotal += $slip['net_minor'];
        }

        // Totals first, approval last — the one order an approved run permits.
        $run->forceFill(['gross_minor' => $grossTotal, 'net_minor' => $netTotal])->save();
        $run->forceFill(['status' => 'approved', 'approved_at' => $periodEnd->copy()->addDays(2)])->save();
    }

    /**
     * One working account per estate role, each with a password generated here and never printed.
     *
     * @return list<array{role: string, name: string, email: string, password: string}>
     */
    private function committee(Tenant $estate): array
    {
        $accounts = [];
        $key = $estate->getTenantKey();

        foreach (self::COMMITTEE as $role => $name) {
            // The domain is `DemonstrationAccounts`' own constant, so the address this writes and
            // the rule that recognises it as a demonstration account cannot drift apart.
            $email = Str::slug(Str::before($name, ' ').' '.Str::after($role, 'estate.')).'@'.$key.DemonstrationAccounts::DEMO_DOMAIN_SUFFIX;
            $password = Str::password(16, symbols: false);

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'console' => 'estate', 'status' => 'active', 'password' => Hash::make($password), 'email_verified_at' => Carbon::now()],
            );

            $roleId = Role::query()->where('name', $role)->value('id');

            if ($roleId !== null) {
                DB::connection('mysql')->table('estate_assignments')->updateOrInsert(
                    ['user_id' => $user->id, 'tenant_id' => $key, 'role_id' => $roleId],
                    ['is_active' => true, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
                );
            }

            /*
             * AND THE ROLE ITSELF, WHICH IS A SEPARATE THING AND IS THE ONE THE CONSOLE READS.
             *
             * `estate_assignments` says which estates this person acts for — the escalation ladder
             * and the client directory read it. The console's navigation and every route gate read
             * the user's OWN assigned role. Writing only the first produced accounts that signed in
             * perfectly and then met "Not available to your role" on every screen, with an empty
             * sidebar, which is exactly what happened in front of the client on 23 September 2026.
             * Both rows are required, and neither implies the other.
             */
            $user->syncRoles([$role]);

            /*
             * AND PRE-VERIFIED FOR ALERTS (M10 §1.4). The contact gate stops every
             * estate-console account at first sign-in until it proves an alert address
             * by a one-time code — and this address is on `demo.geminisl.com`, where no
             * mailbox exists to receive one. Without this line the committee the client
             * demonstrates with would be stopped at the door, which is exactly what the
             * work order says must not happen. `DemonstrationAccounts` is the one place
             * that decides which addresses may be pre-verified.
             */
            DemonstrationAccounts::verify($user);

            $accounts[] = ['role' => Str::headline(Str::after($role, 'estate.')), 'name' => $name, 'email' => $email, 'password' => $password];
        }

        return $accounts;
    }

    /**
     * The accounts, written to a file and NEVER to the terminal (M8 §1 rule 6).
     *
     * @param  list<array{role: string, name: string, email: string, password: string}>  $accounts
     */
    private function credentials(Tenant $estate, array $accounts): string
    {
        $path = (string) ($this->option('credentials') ?: storage_path('app/private/phoenix-demo-credentials.txt'));

        @mkdir(dirname($path), 0700, true);

        $lines = [
            'GeminiSecure — '.$estate->name.' demonstration accounts',
            'Written '.Carbon::now()->toDayDateTimeString().' by phoenix:demo-seed. Sample data.',
            'Change every password before anybody real uses this estate, and delete this file.',
            '',
        ];

        foreach ($accounts as $account) {
            $lines[] = sprintf('%-22s %-26s %-46s %s', $account['role'], $account['name'], $account['email'], $account['password']);
        }

        $lines[] = '';
        $lines[] = 'Guard App: enrol a handset with a code issued by  php artisan device:enrolment-code GS-PPV-01';

        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);

        return $path;
    }
}
