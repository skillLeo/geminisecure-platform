<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Guard;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\Seeded\ReservedContacts;
use App\Support\Seeded\SeedsMarkedRows;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Fill the SECURITY COMPANY's side of the platform, the way the estate's side is filled.
 *
 * WHY THIS EXISTS. The estate console carries Phoenix Park's demonstration content — 2,000 lots, 441
 * households (the demonstration's; the real community has 2,000 homes, M26), twelve thousand charges — and the
 * Gemini console beside it read zero. The
 * Director's dashboard said "Units under management 0" and "Platform MRR $0" while the estate it
 * manages had two thousand lots on screen one click away. Billing had no tiers, no invoices and
 * no payment method, so no client could be put on a plan at all.
 *
 * ONE MISSING ROW EXPLAINED FOUR OF THE ZEROS. `PlatformOverview::kpis()` reads units as
 * `sum(unit_count)` over ACTIVE subscriptions, and MRR the same way; with no subscription on
 * file, units, MRR, billable clients and "active subscriptions" are all zero at once. It was
 * never four separate faults.
 *
 * THE FIGURES ARE THE ESTATE'S OWN, NOT A NUMBER TYPED HERE. `unit_count` is counted from the
 * estate's `units` table and `contracted_guards` from its active guards, so "units under
 * management" on the company dashboard is the same number as "lots" on the estate's, by
 * construction rather than by coincidence. `BillingSeeder` puts Phoenix Park at the board's 450
 * units; that is right for a board comparison and wrong for a live estate with 2,000, and the two
 * consoles would have disagreed on screen.
 *
 * PRICES ARE THE BOARDS'. Essential J$180, Standard J$260, Premium J$340 per unit per month, plus
 * J$4,500 per guard — four boards agree on that arithmetic, and `BillingSeeder`'s docblock
 * records how an earlier reading of J$380/unit was right in total and wrong in decomposition.
 * This seeds the same rate card so every screen still reconciles.
 *
 * IDEMPOTENT. Every write is updateOrCreate keyed on something stable, so running it twice
 * changes nothing and running it after a re-seed brings it back.
 */
class CompanyDemoSeed extends Command
{
    // Every row it writes for an estate carries the seeded mark (M33 §5.2).
    use SeedsMarkedRows;

    protected $signature = 'company:demo-seed
        {--estate=phoenixparkvillage1 : the client to put on a plan}
        {--plan=premium : essential, standard or premium}
        {--months=8 : how many months of invoice history to write}
        {--guards=14 : how many guards the workforce should have}';

    protected $description = "Fill the security company's console — plans, a subscription priced off the estate's real size, invoice history, posts and a guard workforce";

    /**
     * The rate card, from the boards that draw it.
     *
     * [key, name, price per unit per month in minor units, min units, highlights]
     */
    private const PLANS = [
        ['essential', 'Essential', 180_00, 25, [
            'Resident directory & verification',
            'Dues & ledger',
            'Notices & basic reporting',
        ]],
        ['standard', 'Standard', 260_00, 50, [
            'Everything in Essential',
            'Maintenance & amenity booking',
            'Governance & elections',
        ]],
        ['premium', 'Premium', 340_00, 100, [
            'Everything in Standard',
            'Payroll & statutory filing',
            'Full accounting suite',
        ]],
    ];

    /** Jamaican officers, with the licence numbers and rates a real roster carries. */
    private const OFFICERS = [
        /*
         * NOT "Andre Campbell", which is PhoenixDemoSeed::OFFICERS[0] (GS-PPV-01,
         * PSRA-PPV-01). Two seeders that cannot see each other both reached for
         * the same common Jamaican name, and both post their officers to Phoenix
         * Park Village 1 — so the guard directory listed "Andre Campbell" twice,
         * same client, same Main Gate, same Active pill, differing only in a PSRA
         * number nobody reads across two rows.
         *
         * They are genuinely two employee records rather than one duplicated, and
         * two officers really can share a name. But on a console shown to a
         * client it reads as the roster double-counting people, and "is your
         * headcount right?" is not a question worth inviting over a seed value.
         */
        ['Andre Chambers', 'PSRA-JM-40118', 1_250_00],
        ['Kemar Blake', 'PSRA-JM-40224', 1_250_00],
        ['Shaneka Brown', 'PSRA-JM-40337', 1_310_00],
        ['Oneil Grant', 'PSRA-JM-40451', 1_250_00],
        ['Tashana Miller', 'PSRA-JM-40566', 1_180_00],
        ['Damion Reid', 'PSRA-JM-40672', 1_250_00],
        ['Alicia Henry', 'PSRA-JM-40788', 1_310_00],
        ['Rohan Bennett', 'PSRA-JM-40893', 1_250_00],
        ['Kadian Walters', 'PSRA-JM-40915', 1_180_00],
        ['Everton Facey', 'PSRA-JM-41027', 1_400_00],
        ['Simone Clarke', 'PSRA-JM-41134', 1_250_00],
        ['Marlon Service', 'PSRA-JM-41248', 1_250_00],
        ['Peta-Gaye Dixon', 'PSRA-JM-41352', 1_310_00],
        ['Courtney Nelson', 'PSRA-JM-41469', 1_250_00],
        ['Delroy Sinclair', 'PSRA-JM-41571', 1_180_00],
    ];

    /**
     * THE POSTS PHOENIX PARK HAS, AS THE CLIENT SAYS (M26, M27; D-225): "We only have two gates… the main gate and the
     * back gate, which is what we call gate number five or phase five gate." A shift cannot be rostered without one.
     * The patrol is not a post: it is a shift's duty at the Main Gate, where it starts. The "Phase 3 Pedestrian Gate"
     * and the "Perimeter Patrol" this list once held were invented here and never existed; `phoenix:two-gates` takes
     * them off an estate seeded before. No position is set: a gate is placed by somebody standing at it.
     *
     * [name, type, also called]
     */
    private const POSTS = [
        ['Main Gate', 'gate', null],
        ['Back Gate', 'gate', 'Gate 5, Phase 5 Gate'],
    ];

    public function handle(): int
    {
        $key = (string) $this->option('estate');
        $estate = Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error("There is no estate `{$key}` on this platform.");

            return self::FAILURE;
        }

        $this->info("Filling the company console against {$estate->name}.");

        $plans = $this->plans();
        $this->catalogue();

        [$units, $guardCount] = $this->estateSize($estate);
        $this->line("   the estate's own numbers: {$units} lots, {$guardCount} active officers");

        $posts = $this->posts($estate);
        $this->workforce($estate, $posts);

        // Re-read: the workforce may have added officers, and the subscription is priced on what
        // is actually on the roster rather than on what was there when the command started.
        [$units, $guardCount] = $this->estateSize($estate);

        $subscription = $this->subscription($estate, $plans[(string) $this->option('plan')], $units, $guardCount);
        $this->invoices($estate, $subscription, $plans[(string) $this->option('plan')], $units, $guardCount);
        $this->paymentMethod($estate);

        $this->newLine();
        $this->info('The company console now has:');
        $this->line('   plans              '.Plan::query()->count());
        $this->line('   subscriptions      '.Subscription::query()->count().'  ('.Subscription::query()->where('status', 'active')->count().' active)');
        $this->line('   invoices           '.Invoice::query()->count().'  ('.Invoice::query()->where('status', 'issued')->count().' outstanding)');
        $this->line('   posts              '.Post::query()->where('tenant_id', $key)->count());
        $this->line('   guards             '.Guard::query()->where('tenant_id', $key)->where('status', 'active')->count().' active');
        $this->newLine();
        $this->line('   Units under management will read  '.number_format((int) Subscription::query()->where('status', 'active')->sum('unit_count')));

        return self::SUCCESS;
    }

    /** @return array<string, Plan> */
    private function plans(): array
    {
        $out = [];

        foreach (self::PLANS as $i => [$key, $name, $price, $minUnits, $highlights]) {
            $out[$key] = Plan::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $name,
                    'description' => $name.' tier',
                    'highlights' => $highlights,
                    'price_per_unit_minor' => $price,
                    'currency' => 'JMD',
                    'min_units' => $minUnits,
                    'is_active' => true,
                    'sort' => ($i + 1) * 10,
                ],
            );
        }

        $this->line('   plans: '.implode(', ', array_keys($out)));

        return $out;
    }

    /** The per-guard rate and the tier feature grid — both estate-agnostic, both from the boards. */
    private function catalogue(): void
    {
        Artisan::call('db:seed', ['--class' => PlatformCatalogueSeeder::class, '--force' => true]);
        $this->line('   platform rates and the tier feature grid seeded');
    }

    /** @return array{0: int, 1: int} the estate's lots, and its active officers */
    private function estateSize(Tenant $estate): array
    {
        $units = (int) $estate->run(fn (): int => DB::connection('tenant')->table('units')->count());

        $guards = Guard::query()
            ->where('tenant_id', $estate->getTenantKey())
            ->where('status', 'active')
            ->count();

        return [$units, $guards];
    }

    /** @return array<int, Post> */
    private function posts(Tenant $estate): array
    {
        $out = [];

        foreach (self::POSTS as [$name, $type, $also]) {
            $out[] = Post::updateOrCreate(
                ['tenant_id' => $estate->getTenantKey(), 'name' => $name],
                ['type' => $type, 'also_called' => $also, 'is_active' => true],
            );
        }

        $this->line('   posts: '.count($out).' ('.implode(', ', array_column(self::POSTS, 0)).')');

        return $out;
    }

    /**
     * A roster big enough to schedule.
     *
     * A payroll run with one employee and four exceptions is what a broken payroll looks like,
     * and a scheduler with nobody to schedule cannot be demonstrated at all. Officers already on
     * file keep their details; the list only tops up to the target.
     *
     * @param  array<int, Post>  $posts
     */
    private function workforce(Tenant $estate, array $posts): void
    {
        $target = (int) $this->option('guards');
        $added = 0;

        foreach (array_slice(self::OFFICERS, 0, $target) as $i => [$name, $psra, $rate]) {
            $guard = Guard::query()->firstOrNew([
                'tenant_id' => $estate->getTenantKey(),
                'employee_number' => 'GS-'.str_pad((string) (2001 + $i), 4, '0', STR_PAD_LEFT),
            ]);

            $existed = $guard->exists;

            $guard->fill([
                'full_name' => $guard->full_name ?: $name,
                'psra_number' => $guard->psra_number ?: $psra,

                // A licence that lapses next week is an exception on the payroll screen. These
                // are spread across the coming year so the roster is workable, and ONE is left
                // close enough to warn — a compliance screen with nothing on it teaches nobody
                // what it is for.
                'psra_expires_on' => $guard->psra_expires_on ?? Carbon::now()->addDays($i === 0 ? 24 : 120 + ($i * 21)),
                'employment_type' => $guard->employment_type ?: 'full_time',
                'standard_rate_minor' => $guard->standard_rate_minor ?: $rate,
                'standard_rate_currency' => 'JMD',
                'status' => $guard->status ?: 'active',
                'phone' => $guard->phone ?: ReservedContacts::seededPhone(400 + $i),
                'hired_on' => $guard->hired_on ?? Carbon::now()->subMonths(6 + $i),
                'post_id' => $guard->post_id ?? $posts[$i % count($posts)]->id,
            ]);

            $guard->save();
            $existed || $added++;
        }

        $this->line("   guards: {$added} added, ".Guard::query()->where('tenant_id', $estate->getTenantKey())->count().' on the roster');
    }

    private function subscription(Tenant $estate, Plan $plan, int $units, int $guards): Subscription
    {
        $months = (int) $this->option('months');

        $subscription = Subscription::updateOrCreate(
            ['tenant_id' => $estate->getTenantKey()],
            [
                'plan_id' => $plan->id,
                'unit_count' => $units,
                'contracted_guards' => $guards,

                /*
                 * ACTIVE, and that is the word the dashboard reads. `ClientPlan::activate()`
                 * leaves a new subscription `onboarding` on purpose — activating a plan sets what
                 * a client WILL be billed, and completing onboarding is what starts charging. The
                 * estate here has been live and taking sign-ins since this morning, so the
                 * subscription that describes it is active too; leaving it onboarding would put
                 * the company dashboard back to zero while the estate ran.
                 */
                'status' => 'active',
                'started_on' => Carbon::now()->subMonths($months)->startOfMonth(),
                'renews_on' => Carbon::now()->addMonth()->startOfMonth(),
                'term_months' => 24,
                'contract_renewal_on' => Carbon::now()->addMonths(18)->startOfMonth(),
            ],
        );

        $mrr = ($units * $plan->price_per_unit_minor) + ($guards * $this->guardRateMinor());

        $this->line(sprintf(
            '   subscription: %s, %s units x %s + %d guards x %s = %s/month',
            $plan->name,
            number_format($units),
            $this->money($plan->price_per_unit_minor),
            $guards,
            $this->money($this->guardRateMinor()),
            $this->money($mrr),
        ));

        return $subscription;
    }

    /**
     * A billing history with something in every state the screen can draw.
     *
     * Every month but the newest is paid; the newest is issued and outstanding, so the billing
     * screen has a live figure rather than a settled archive. A chart needs history behind it or
     * it is a flat line, which is why this writes months rather than one invoice.
     */
    private function invoices(Tenant $estate, Subscription $subscription, Plan $plan, int $units, int $guards): void
    {
        $months = (int) $this->option('months');
        $prefix = strtoupper(substr($estate->getTenantKey(), 0, 2));
        $written = 0;

        for ($back = $months; $back >= 0; $back--) {
            $start = Carbon::now()->subMonths($back)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            $newest = $back === 0;

            $software = $units * $plan->price_per_unit_minor;
            $guarding = $guards * $this->guardRateMinor();
            $total = $software + $guarding;

            $invoice = Invoice::updateOrCreate(
                ['reference' => $prefix.'-'.$start->format('Ym')],
                [
                    'tenant_id' => $estate->getTenantKey(),
                    'subscription_id' => $subscription->id,
                    'period' => $start->format('F Y'),
                    'period_start' => $start,
                    'period_end' => $end,
                    'subtotal_minor' => $total,
                    'total_minor' => $total,
                    'currency' => 'JMD',
                    'due_on' => $start->copy()->addDays(14),
                    'status' => $newest ? 'issued' : 'paid',
                    'paid_on' => $newest ? null : $start->copy()->addDays(9),
                ],
            );

            InvoiceLine::updateOrCreate(
                ['invoice_id' => $invoice->id, 'description' => $plan->name.' subscription'],
                [
                    'quantity' => $units,
                    'unit_price_minor' => $plan->price_per_unit_minor,
                    'total_minor' => $software,
                    'currency' => 'JMD',
                ],
            );

            if ($guards > 0) {
                InvoiceLine::updateOrCreate(
                    ['invoice_id' => $invoice->id, 'description' => 'Security Provider add-on'],
                    [
                        'quantity' => $guards,
                        'unit_price_minor' => $this->guardRateMinor(),
                        'total_minor' => $guarding,
                        'currency' => 'JMD',
                    ],
                );
            }

            $written++;
        }

        $this->line("   invoices: {$written} months, the newest outstanding");
    }

    /** How this client settles. One is on file; the board's "not yet on file" case is another estate's. */
    private function paymentMethod(Tenant $estate): void
    {
        try {
            DB::connection('mysql')->table('payment_methods')->updateOrInsert(
                ['tenant_id' => $estate->getTenantKey()],
                [
                    'institution' => 'NCB Jamaica',
                    'last_four' => '7712',
                    'is_default' => true,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ],
            );
            $this->line('   payment method: NCB Jamaica ****7712');
        } catch (\Throwable $e) {
            $this->warn('   payment method not written: '.$e->getMessage());
        }
    }

    /**
     * THE ROWS THIS COMMAND WROTE AT AN ESTATE, BY WHAT THEY SAY (M33 §5, KI-25).
     *
     * Unlike `phoenix:demo-seed` it recorded no range, and on production it ran before the seeded mark existed, so
     * `launch:retire-sample-data` knows its rows by what this command writes and nothing else: the officers on its roster
     * under the numbers it gives them, with the names and licences it gives them; the invoice references it makes; the
     * payment method it files. An officer the office has renamed, or an invoice the invoice run raised (`…-INV-…`), is
     * not among them. The subscription and the posts are the estate's own — its contract and its gates — and are left out.
     *
     * @return array<string, Builder> table => a query for the ids of those rows
     */
    public static function rowsAt(string $tenantId): array
    {
        $db = DB::connection('mysql');

        $guards = $db->table('guards')->where('tenant_id', $tenantId)->where(static function ($query): void {
            foreach (self::OFFICERS as $i => [$name, $psra]) {
                $query->orWhere(static fn ($one) => $one
                    ->where('employee_number', 'GS-'.str_pad((string) (2001 + $i), 4, '0', STR_PAD_LEFT))
                    ->where('full_name', $name)
                    ->where('psra_number', $psra));
            }
        });

        $invoices = $db->table('invoices')->where('tenant_id', $tenantId)
            ->whereRaw('`reference` REGEXP ?', ['^'.strtoupper(substr($tenantId, 0, 2)).'-[0-9]{6}$']);

        return [
            'guards' => $guards->select('id'),
            'invoices' => (clone $invoices)->select('id'),
            'invoice_lines' => $db->table('invoice_lines')->whereIn('invoice_id', (clone $invoices)->select('id'))->select('id'),
            'payment_methods' => $db->table('payment_methods')->where('tenant_id', $tenantId)
                ->where('institution', 'NCB Jamaica')->where('last_four', '7712')->select('id'),
        ];
    }

    private function guardRateMinor(): int
    {
        return (int) (DB::connection('mysql')->table('platform_rates')
            ->where('key', 'security_provider_guard')
            ->value('amount_minor') ?? 4_500_00);
    }

    private function money(int $minor): string
    {
        return 'J$'.number_format($minor / 100);
    }
}
