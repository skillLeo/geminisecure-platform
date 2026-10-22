<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Enums\Console;
use App\Enums\PermissionVerb;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\StatutoryRateVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gemini\ClientDirectory;
use App\Services\Gemini\ClientOnboarding;
use App\Services\Gemini\PlatformOverview;
use App\Support\NamedActs;
use App\Support\PlatformMoney;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The Gemini Admin Assistant sees no money — M10 §5, the platform half
|--------------------------------------------------------------------------
|
| The client: "The Admin Assistant must not see anything about what anyone pays
| or what the company earns", in the interface AND refused by the API — "a field
| hidden in the interface but returned by the API is not hidden."
|
| So every assertion here is about the SERIALISED PAYLOAD, never the template.
| A Vue `v-if` that hid the MRR column while the row still carried the figure
| would pass a screenshot and fail this file, which is the point of it.
|
| THREE LAYERS, as `GuardSeesNoMoneyTest` keeps them:
|
|   KEYS     the named money keys are ABSENT — not null, not "—", absent — from
|            every payload the role can reach, and the sort keys and chip that
|            would rank or filter by money are refused.
|
|   VALUES   a whole-payload scan for a currency figure and for the vocabulary
|            of billing, because a figure can ride in on a permitted key: a
|            subtitle, a `meta` line, a label that interpolates an amount. No
|            key-name check can see those.
|
|   ROUTES   every billing, payroll, report, audit and settings route — screens,
|            exports and writes — answers 403, and every /api/v1 endpoint refuses
|            a console session outright.
|
| AND THE DIRECTOR STILL SEES ALL OF IT, asserted beside each refusal against the
| same rows. A withholding that also blanked the Director would pass every
| negative assertion in this file and be a broken console; the positive half is
| what proves the negative half is the ROLE and not an empty database.
|
| No RefreshDatabase: the fixture's central half is committed once per process,
| and everything this file writes is inside a transaction that is rolled back.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $suffix = random_int(100000, 999999);

    /*
     * One token every row this file makes carries in its name, so a directory
     * search narrows to them alone and the assertions are about these rows
     * whatever else a shared test database holds.
     */
    $this->token = 'Moneytest'.$suffix;

    // A plan name no other row in the database can have: finding it in a
    // payload is finding THIS tier, not a coincidence of wording.
    $this->planName = 'Moneyview'.$suffix;

    $planId = DB::connection('mysql')->table('plans')->insertGetId([
        'key' => 'moneyview'.$suffix,
        'name' => $this->planName,
        'price_per_unit_minor' => 340_00,
        'currency' => 'JMD',
        'min_units' => 10,
        'is_active' => true,
        'sort' => 97,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /*
     * FOUR CLIENTS, each for one question:
     *
     *   live       active, on the plan, with an OVERDUE invoice and a paid one —
     *              the record §5 names by hand ("the Overdue badge on
     *              /clients/phoenixparkvillage1")
     *   rich       active, alphabetically LAST and by revenue FIRST, so a sort
     *              by MRR and a sort by name give different orders
     *   dunning    behind on its invoices — "At risk" is billing status too
     *   onboarding a plan prepared, not in force: projected MRR, plan prepared,
     *              and the payment-method step
     */
    $clients = [
        'live' => [$this->token.' Harbour', 'active', 120, now()->subDay()],
        'rich' => [$this->token.' Zenith', 'active', 60000, now()->subDays(2)],
        'dunning' => [$this->token.' Marlin', 'dunning', 80, now()->subDays(3)],
        'onboarding' => [$this->token.' Bayside', 'onboarding', 50, now()->subDays(4)],
    ];

    $this->ids = [];

    foreach ($clients as $role => [$name, $status, $units, $provisioned]) {
        $id = strtolower($role).'mny'.$suffix;
        $this->ids[$role] = $id;

        DB::connection('mysql')->table('tenants')->insert([
            'id' => $id,
            'name' => $name,
            'status' => $status,
            'demo' => 0,
            'parish' => 'St. Mary',
            'address_line' => 'Port Maria',
            'provisioned_at' => $provisioned,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('mysql')->table('subscriptions')->insert([
            'tenant_id' => $id,
            'plan_id' => $planId,
            'unit_count' => $units,
            'contracted_guards' => 0,
            'status' => $status === 'onboarding' ? 'onboarding' : $status,
            'started_on' => $status === 'onboarding' ? null : now()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $subscription = (int) DB::connection('mysql')->table('subscriptions')->where('tenant_id', $this->ids['live'])->value('id');

    // THE OVERDUE ONE: issued, and its due date is behind us.
    $this->overdueInvoiceId = (int) DB::connection('mysql')->table('invoices')->insertGetId([
        'tenant_id' => $this->ids['live'],
        'subscription_id' => $subscription,
        'reference' => 'MNY-'.$suffix.'-2',
        'period' => 'Aug 2026',
        'period_start' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'period_end' => now()->subMonths(2)->endOfMonth()->toDateString(),
        'total_minor' => 40_800_00,
        'currency' => 'JMD',
        'due_on' => now()->subDays(20)->toDateString(),
        'status' => 'issued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Paid today, so it is the newest event on the activity feed.
    $this->paidInvoiceId = (int) DB::connection('mysql')->table('invoices')->insertGetId([
        'tenant_id' => $this->ids['live'],
        'subscription_id' => $subscription,
        'reference' => 'MNY-'.$suffix.'-1',
        'period' => 'Jul 2026',
        'period_start' => now()->subMonths(3)->startOfMonth()->toDateString(),
        'period_end' => now()->subMonths(3)->endOfMonth()->toDateString(),
        'total_minor' => 40_800_00,
        'currency' => 'JMD',
        'due_on' => now()->subMonths(2)->toDateString(),
        'status' => 'paid',
        'paid_on' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /*
     * AN OFFICER WHO HAS BEEN PAID, or the payslip assertion below passes for
     * the wrong reason: a guard with no payslip shows "No payslip yet", which
     * carries no figure whoever reads it. J$87,654 is a gross nothing else in
     * the database will print.
     */
    $post = Post::create(['tenant_id' => $this->ids['live'], 'name' => 'Gate '.$suffix, 'type' => 'gate', 'is_active' => true]);

    $this->guard = Guard::create([
        'full_name' => 'Kemar '.$this->token,
        'employee_number' => 'GS-MNY'.$suffix,
        'psra_number' => 'PSRA-MNY'.$suffix,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => $this->ids['live'],
        'post_id' => $post->id,
    ]);

    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);

    $end = now()->startOfMonth()->subDay();

    $this->runId = (int) DB::connection('mysql')->table('payroll_runs')->insertGetId([
        'reference' => 'TEST-MNY-'.$suffix,
        'period_label' => $end->format('F Y'),
        'period_start' => $end->copy()->startOfMonth()->toDateString(),
        'period_end' => $end->toDateString(),
        'periods_per_year' => 12,
        'statutory_rate_version_id' => StatutoryRateVersion::forPayDate($end)->id,
        'status' => 'calculated',
        'currency' => 'JMD',
        'gross_minor' => 87_654_00,
        'net_minor' => 70_100_00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('mysql')->table('payslips')->insert([
        'payroll_run_id' => $this->runId,
        'guard_id' => $this->guard->id,
        'tenant_id' => $this->ids['live'],
        'gross_minor' => 87_654_00,
        'net_minor' => 70_100_00,
        'currency' => 'JMD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->assistant = FacilitiesFixture::geminiViewer(Role::ADMIN_ASSISTANT);
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $this->accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** The Inertia props a response carried, as the browser would receive them. */
function moneyProps(TestResponse $response): array
{
    $props = [];

    $response->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return json_decode((string) json_encode($props), true);
}

/**
 * Every place money could hide in a payload, as a list of what was found.
 *
 * Lower-cased and with slashes unescaped, so "/unit/mo" is found as the page
 * would print it. `$plan` is the test's own tier name — unique to this run —
 * because a tier is a price list whatever it is called, and the generic words
 * cannot know what a given estate's plan is called.
 *
 * @return list<string>
 */
function moneyFound(array $props, string $plan): array
{
    $json = (string) json_encode($props, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $lower = strtolower($json);

    $found = [];

    // "$40,800", "J$ 87,654.00" — a currency figure of three digits or more.
    if (preg_match('/\$\s?\d[\d,]{2,}/', $json, $match)) {
        $found[] = 'figure '.$match[0];
    }

    foreach (['mrr', 'overdue', 'gross', '/unit/mo', 'invoice', '87,654', 'at risk', strtolower($plan)] as $word) {
        if (str_contains($lower, $word)) {
            $found[] = $word;
        }
    }

    return $found;
}

/**
 * Put this file's rows at the head of the platform's activity feed, for one test.
 *
 * THE FEED IS THE WHOLE PLATFORM'S LATEST EIGHT (M11 B5). `PlatformOverview::activityFeed()`
 * merges every estate onboarded, invoice paid, guard deployed and licence lapsed, newest
 * first, and keeps `FEED_ROWS`. This file's onboarding is dated yesterday and its paid invoice
 * today, so whether either is among the eight depended on how many rows OTHER files had
 * committed to this shared central database since yesterday. After one full run five were
 * standing there, each an estate its fixture stamps "provisioned now" whenever it runs
 * (EstateRolesEditorTest's two, EstateSettingsTest's, RolesAreDynamicTest's two), so the margin
 * was one row. With seven the live estate fell off the Accountant's feed and the plan-name
 * assertion failed; with ten it fell off the Admin Assistant's too, and the check that its row
 * "stays" failed — although nothing about who may see money had changed.
 *
 * So every OTHER row that competes for the eight places is moved thirty days back, inside this
 * test's transaction, which is rolled back. The feed is then asked about exactly the rows its
 * assertions name, whatever else the database holds. The product is untouched and every
 * assertion is the one it was.
 *
 * @param  list<string>  $ours  this file's own estate ids
 */
function moneyFeedPutsOursFirst(array $ours): void
{
    $behind = now()->subDays(30);
    $central = DB::connection('mysql');

    $central->table('tenants')
        ->whereNotIn('id', $ours)
        ->where('provisioned_at', '>', $behind)
        ->update(['provisioned_at' => $behind]);

    $central->table('invoices')
        ->whereNotIn('tenant_id', $ours)
        ->where('status', 'paid')
        ->where('paid_on', '>', $behind->toDateString())
        ->update(['paid_on' => $behind->toDateString()]);

    // An unassigned guard is on the feed too, and NOT IN alone would let SQL drop its null.
    $notOurs = static fn ($query) => $query->whereNull('tenant_id')->orWhereNotIn('tenant_id', $ours);

    $central->table('guards')
        ->where($notOurs)
        ->where('hired_on', '>', $behind->toDateString())
        ->update(['hired_on' => $behind->toDateString()]);

    $central->table('guards')
        ->where($notOurs)
        ->where('status', 'licence_expired')
        ->where('psra_expires_on', '>', $behind->toDateString())
        ->update(['psra_expires_on' => $behind->toDateString()]);
}

/* -----------------------------------------------------------------
 | 1. The rule, and who it lands on
 |------------------------------------------------------------------ */

it('decides money from the permission in one place, and it lands on exactly the three roles without the modules', function () {
    /*
     * Read off `RbacMatrixSeeder::GEMINI_GRID` with no cell changed. The Head of
     * Security and the Dispatcher lose the figures too: neither holds Billing or
     * Payroll either, and a dashboard is not a side door into a module.
     */
    $expect = [
        Role::DIRECTOR => [true, true],
        Role::OPERATIONS_MANAGER => [true, true],
        Role::ACCOUNTANT => [true, true],
        Role::HEAD_OF_SECURITY => [false, false],
        Role::DISPATCHER => [false, false],
        Role::ADMIN_ASSISTANT => [false, false],
    ];

    foreach ($expect as $role => [$revenue, $pay]) {
        $viewer = FacilitiesFixture::geminiViewer($role);

        expect(PlatformMoney::maySeeRevenue($viewer))->toBe($revenue, "{$role} revenue")
            ->and(PlatformMoney::maySeePay($viewer))->toBe($pay, "{$role} pay");
    }

    expect(PlatformMoney::maySeeRevenue(null))->toBeFalse()
        ->and(PlatformMoney::maySeePay(null))->toBeFalse();
});

it('pins the matrix the rule reads: the Admin Assistant holds its four modules and no verb at all on the five money and system modules', function () {
    $role = Role::named(Role::ADMIN_ASSISTANT);

    foreach (['dashboard', 'clients', 'dispatch', 'guard_workforce'] as $module) {
        expect($role->hasPermissionTo("gemini.{$module}.view"))->toBeTrue("gemini.{$module}.view");
    }

    /*
     * A NESTED LOOP OVER EVERY VERB, not a list of names. A verb added to the
     * enum later fails this until somebody decides whether the Admin Assistant
     * gets it on a money module — which is a decision, not a default.
     */
    foreach (['billing_subscriptions', 'payroll_accounting', 'cross_tenant_reports', 'access_audit_log', 'platform_settings'] as $module) {
        foreach (PermissionVerb::cases() as $verb) {
            // A verb split into named acts (M11 B3) is its acts — Platform
            // settings' configure is three permissions — and each is refused.
            $names = NamedActs::under(Console::Gemini, $module, $verb);

            foreach ($names === [] ? [$verb->value] : $names as $name) {
                expect($role->hasPermissionTo("gemini.{$module}.{$name}"))
                    ->toBeFalse("the Admin Assistant must not hold gemini.{$module}.{$name}");
            }
        }
    }
});

it('follows the permission, not the role name, so a role the Director builds inherits the rule from its cells', function () {
    /*
     * M10 §4 makes roles editable. A "Front desk" role with Dashboard and
     * Clients and no Billing must be shown exactly what the Admin Assistant is —
     * and the moment it is given Billing & subscriptions, exactly what the
     * Director is. Nobody edits a list of role names to make either true.
     */
    Role::findOrCreate('gemini.front_desk_mny', 'web');

    $role = Role::named('gemini.front_desk_mny');
    $role->forceFill(['console' => 'gemini', 'label' => 'Front desk', 'sort' => 95, 'scope_default' => 'all'])->save();
    $role->givePermissionTo(['gemini.dashboard.view', 'gemini.clients.view']);

    $desk = User::factory()->create(['console' => 'gemini', 'status' => 'active']);
    $desk->syncRoles(['gemini.front_desk_mny']);
    $desk = $desk->fresh();

    $props = moneyProps($this->actingAs($desk)->get('/clients?q='.$this->token));

    // M32 F5: the Tier and MRR columns are drawn only where `moneyColumns` says, and neither sorts for this reader.
    expect($props['moneyColumns'])->toBeFalse()
        ->and($props['table']['sortable'])->not->toContain('mrr')
        ->and($props['table']['sortable'])->not->toContain('tier')
        ->and(moneyFound($props, $this->planName))->toBe([]);

    $role->givePermissionTo('gemini.billing_subscriptions.view');

    $props = moneyProps($this->actingAs($desk->fresh())->get('/clients?q='.$this->token));

    expect($props['moneyColumns'])->toBeTrue()
        ->and($props['table']['sortable'])->toContain('mrr')
        ->and($props['table']['sortable'])->toContain('tier');
});

/* -----------------------------------------------------------------
 | 2. The dashboard and its feed
 |------------------------------------------------------------------ */

it('sends the Admin Assistant a dashboard with no MRR card, no tier panel and no paid invoices — and the Director all three', function () {
    $props = moneyProps($this->actingAs($this->assistant)->get('/dashboard'));

    expect(array_column($props['kpis'], 'key'))->not->toContain('mrr')
        ->and(array_column($props['kpis'], 'key'))->toContain('clients')

        // ABSENT, not empty: an empty list is what a platform with no clients sends.
        ->and($props)->not->toHaveKey('tiers')
        ->and($props['withheld_reason'])->toBeString()
        ->and(moneyFound($props, $this->planName))->toBe([]);

    $director = moneyProps($this->actingAs($this->director)->get('/dashboard'));

    expect(array_column($director['kpis'], 'key'))->toContain('mrr')
        ->and($director['tiers'])->not->toBeEmpty()
        ->and(array_column($director['tiers'], 'label'))->toContain($this->planName)
        ->and($director['withheld_reason'])->toBeNull();
});

it('keeps paid invoices and plan names out of the full activity feed for the Admin Assistant, and not for the Accountant', function () {
    moneyFeedPutsOursFirst(array_values($this->ids));

    $props = moneyProps($this->actingAs($this->assistant)->get('/dashboard/activity'));
    $keys = array_column($props['events'], 'key');

    expect(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'invoice:')))->toBe([])

        // The onboarding row itself stays — it is what happened — and loses the plan beside it.
        ->and($keys)->toContain('estate:'.$this->ids['live'])
        ->and(moneyFound($props, $this->planName))->toBe([]);

    $accountant = moneyProps($this->actingAs($this->accountant)->get('/dashboard/activity'));
    $events = collect($accountant['events'])->keyBy('key');

    expect($events->keys()->all())->toContain('invoice:'.$this->paidInvoiceId)
        ->and($events['estate:'.$this->ids['live']]['meta'])->toContain($this->planName);
});

it('drops the MRR card and only that card when the service is told the viewer may not read revenue', function () {
    /*
     * The service asks every caller who is looking and what they may read — neither has a default
     * any more (bug hunt B007: an omitted viewer was the whole platform). Asked as the Director,
     * the answer to the revenue question alone decides the MRR card.
     */
    $overview = app(PlatformOverview::class);

    expect(array_column($overview->kpis($this->director, true), 'key'))->toContain('mrr')
        ->and(array_column($overview->kpis($this->director, false), 'key'))->not->toContain('mrr')
        ->and(count($overview->kpis($this->director, false)))->toBe(count($overview->kpis($this->director, true)) - 1);
});

/* -----------------------------------------------------------------
 | 3. The client directory
 |------------------------------------------------------------------ */

it('sends the Admin Assistant a directory with no tier, no MRR and no At risk chip, on any row', function () {
    $props = moneyProps($this->actingAs($this->assistant)->get('/clients?q='.$this->token));

    expect($props['clients'])->toHaveCount(4)
        ->and($props['moneyColumns'])->toBeFalse()
        ->and($props['table']['sortable'])->not->toContain('tier')
        ->and($props['table']['sortable'])->not->toContain('mrr')
        ->and($props['filters'])->not->toHaveKey('at-risk');

    foreach ($props['clients'] as $row) {
        expect($row)->not->toHaveKey('mrr')
            ->and($row)->not->toHaveKey('tierKey')
            ->and($row)->not->toHaveKey('tierLabel');
    }

    /*
     * "AT RISK" IS BILLING STATUS. A client in dunning reads as the live client
     * it operationally is, with the live client's pill — not a softened word
     * for the same fact, and not the `due` colour that says it anyway.
     */
    $dunning = collect($props['clients'])->firstWhere('id', $this->ids['dunning']);

    expect($dunning['statusLabel'])->toBe('Active')
        ->and($dunning['statusBadge'])->toBe('active')
        ->and(moneyFound($props, $this->planName))->toBe([]);

    $director = moneyProps($this->actingAs($this->director)->get('/clients?q='.$this->token));
    $row = collect($director['clients'])->firstWhere('id', $this->ids['live']);

    expect($director['moneyColumns'])->toBeTrue()
        ->and($director['table']['sortable'])->toContain('tier')
        ->and($director['table']['sortable'])->toContain('mrr')
        ->and($director['filters'])->toHaveKey('at-risk')
        ->and($row['tierLabel'])->toBe($this->planName)
        ->and($row['mrr'])->toContain('40,800')
        ->and(collect($director['clients'])->firstWhere('id', $this->ids['dunning'])['statusLabel'])->toBe('At risk');
});

it('refuses to rank or filter by money for the Admin Assistant: ?sort=mrr, ?sort=tier and ?status=at-risk fall back as if they did not exist', function () {
    $default = array_column(moneyProps($this->actingAs($this->assistant)->get('/clients?q='.$this->token))['clients'], 'id');

    /*
     * A DIRECTORY SORTED BY MRR REPORTS THE REVENUE RANKING WITHOUT PRINTING A
     * FIGURE. So the column is narrowed away exactly as an unknown one is: the
     * directory is in its own order, by NAME — and, as for any column that does
     * not exist, a direction asked for beside it goes with it (M32 F5's one rule
     * for every table's address). Zenith, the richest client, is last by name, so
     * a revenue order would put it first.
     */
    foreach (['mrr', 'tier'] as $sort) {
        foreach (['asc', 'desc'] as $dir) {
            $sorted = moneyProps($this->actingAs($this->assistant)->get("/clients?q={$this->token}&sort={$sort}&dir={$dir}"));

            expect(array_column($sorted['clients'], 'id'))->toBe($default, "sort={$sort}&dir={$dir}")
                ->and($sorted['table']['sort'])->toBeNull()
                ->and($sorted['table']['default'])->toMatchArray(['sort' => 'name', 'dir' => 'asc']);
        }
    }

    expect(end($default))->toBe($this->ids['rich']);

    // The chip that lists clients behind on their invoices filters nothing.
    $chip = moneyProps($this->actingAs($this->assistant)->get("/clients?q={$this->token}&status[]=at-risk"));

    expect($chip['isFiltered'])->toBeTrue()  // the search is still a filter
        ->and(array_column($chip['clients'], 'id'))->toBe($default);

    // And the service refuses the same thing when it is called some other way.
    $criteria = ['q' => $this->token, 'status' => ['at-risk']];
    $asked = ClientDirectory::table(Request::create('/clients', 'GET', ['sort' => 'mrr', 'dir' => 'asc']), false);

    expect(array_column(app(ClientDirectory::class)->directory($this->assistant, $criteria, false, $asked)['clients'], 'id'))
        ->toBe($default);

    // The Accountant's revenue sort does rank by revenue: the richest client first.
    $ranked = moneyProps($this->actingAs($this->accountant)->get("/clients?q={$this->token}&sort=mrr&dir=desc"));

    expect($ranked['clients'][0]['id'])->toBe($this->ids['rich']);
});

/* -----------------------------------------------------------------
 | 4. One client's record
 |------------------------------------------------------------------ */

it('sends the Admin Assistant a client record with no tier pill, MRR, billing status, Overdue badge, per-unit price or invoice rows', function () {
    $props = moneyProps($this->actingAs($this->assistant)->get('/clients/'.$this->ids['live']));
    $estate = $props['estate'];

    expect(array_column($estate['stats'], 'label'))->toBe(['Units', 'Guards deployed'])
        ->and($estate)->not->toHaveKey('tierLabel')
        ->and($estate)->not->toHaveKey('subscription')
        ->and($estate)->not->toHaveKey('invoices')
        ->and($estate['statusLabel'])->toBe('Active')
        ->and($estate['withheld_reason'])->toBeString()
        ->and($props['canViewBilling'])->toBeFalse()

        // The whole payload, because a figure can ride in on a permitted key.
        ->and(moneyFound($props, $this->planName))->toBe([]);

    /*
     * THE SAME RECORD AS THE DIRECTOR, and the Overdue badge is there. Without
     * this half the assertions above would pass against a client with no
     * invoices at all, which is not the client §5 is about.
     */
    $director = moneyProps($this->actingAs($this->director)->get('/clients/'.$this->ids['live']))['estate'];
    $stats = collect($director['stats'])->keyBy('label');

    expect($stats->keys()->all())->toBe(['Units', 'Guards deployed', 'MRR', 'Billing status'])
        ->and($stats['Billing status']['value'])->toBe('Overdue')
        ->and($director['tierLabel'])->toBe($this->planName.' tier')
        ->and(collect($director['subscription'])->firstWhere('label', 'Plan')['value'])->toContain('/unit/mo')
        ->and($director['invoices'])->not->toBeEmpty();
});

it('withholds the same things from an onboarding record: plan prepared, projected MRR, billing status and the payment-method step', function () {
    $estate = moneyProps($this->actingAs($this->assistant)->get('/clients/'.$this->ids['onboarding']))['estate'];

    expect(array_column($estate['stats'], 'label'))->toBe(['Units'])
        ->and($estate)->not->toHaveKey('tierLabel')
        ->and($estate['statusLabel'])->toBe('Onboarding')
        ->and(array_column($estate['onboarding']['checklist'], 'label'))->not->toContain('Payment method on file')

        // The go-live gate still reads the plan step, and still reads it right.
        ->and($estate['onboarding']['canComplete'])->toBeTrue()
        ->and(moneyFound(['estate' => $estate], $this->planName))->toBe([]);

    $director = moneyProps($this->actingAs($this->director)->get('/clients/'.$this->ids['onboarding']))['estate'];

    expect(array_column($director['stats'], 'label'))->toBe(['Units', 'Plan prepared', 'Projected MRR', 'Billing status'])
        ->and(array_column($director['onboarding']['checklist'], 'label'))->toContain('Payment method on file');
});

it('reads a client in dunning to the Admin Assistant as the live client it is, and to the Director as At risk', function () {
    $estate = moneyProps($this->actingAs($this->assistant)->get('/clients/'.$this->ids['dunning']))['estate'];

    expect($estate['statusLabel'])->toBe('Active')
        ->and(moneyFound(['estate' => $estate], $this->planName))->toBe([]);

    expect(moneyProps($this->actingAs($this->director)->get('/clients/'.$this->ids['dunning']))['estate']['statusLabel'])
        ->toBe('At risk');
});

it('carries the answer through ClientOnboarding::detail, the other door into the same record', function () {
    $tenant = Tenant::query()->findOrFail($this->ids['live']);

    $withheld = app(ClientOnboarding::class)->detail($tenant, false);
    $shown = app(ClientOnboarding::class)->detail($tenant);

    expect($withheld)->not->toHaveKey('invoices')
        ->and(moneyFound($withheld, $this->planName))->toBe([])
        ->and($shown['invoices'])->not->toBeEmpty();
});

/* -----------------------------------------------------------------
 | 5. A guard's record and the dispatch map
 |------------------------------------------------------------------ */

it('sends the Admin Assistant a guard record with no gross pay, and the Accountant the payslip gross', function () {
    $props = moneyProps($this->actingAs($this->assistant)->get('/guards/'.$this->guard->id));

    expect(array_column($props['guard']['stats'], 'label'))->not->toContain('Last gross pay')
        ->and(array_column($props['guard']['stats'], 'label'))->toContain('Licence number')
        ->and(moneyFound($props, $this->planName))->toBe([]);

    // A guard WITH a payslip, so the absence above is the rule and not an empty table.
    foreach ([$this->accountant, $this->director] as $viewer) {
        $stats = collect(moneyProps($this->actingAs($viewer)->get('/guards/'.$this->guard->id))['guard']['stats'])->keyBy('label');

        expect($stats)->toHaveKey('Last gross pay')
            ->and($stats['Last gross pay']['value'])->toContain('87,654');
    }
});

it('drops the subscription tier from every dispatch map site card for the Admin Assistant', function () {
    $props = moneyProps($this->actingAs($this->assistant)->get('/dispatch/map'));

    expect($props['sites'])->not->toBeEmpty();

    foreach ($props['sites'] as $site) {
        expect($site)->not->toHaveKey('plan')
            ->and($site)->not->toHaveKey('tier')
            ->and($site['staffing'])->toBeString();
    }

    expect(moneyFound(['sites' => $props['sites']], $this->planName))->toBe([]);

    foreach (moneyProps($this->actingAs($this->director)->get('/dispatch/map'))['sites'] as $site) {
        expect($site)->toHaveKey('plan');
    }
});

/* -----------------------------------------------------------------
 | 6. The whole payload, on every screen the role holds
 |------------------------------------------------------------------ */

it('finds no figure and no billing word anywhere in any payload the Admin Assistant can open', function () {
    $screens = [
        '/dashboard',
        '/dashboard/activity',
        '/notifications',
        '/clients',
        '/clients?q='.$this->token,
        '/clients/'.$this->ids['live'],
        '/clients/'.$this->ids['rich'],
        '/clients/'.$this->ids['dunning'],
        '/clients/'.$this->ids['onboarding'],
        '/guards/'.$this->guard->id,
        '/dispatch/map',
    ];

    foreach ($screens as $screen) {
        expect(moneyFound(moneyProps($this->actingAs($this->assistant)->get($screen)), $this->planName))
            ->toBe([], "{$screen} carried money to the Admin Assistant");
    }

    /*
     * AND EVERY OTHER SCREEN THE ROLE CAN OPEN, which carry no money by design
     * and are scanned so that stays true. One word is allowed on these and only
     * these: a patrol that missed a checkpoint is "overdue" on the alertness
     * board, which is a guard's round, not an invoice.
     */
    $operational = [
        '/guards',
        '/guards/compliance',
        '/guards/'.$this->guard->id.'/compliance',
        '/guards/supervisors',
        '/guards/roster',
        '/guards/standing-orders',
        '/guards/activity',
        '/guards/incidents',
        '/dispatch/alerts',
        '/dispatch/coverage',
        '/dispatch/alertness',
        '/dispatch/requests',
        '/dispatch/requests/history',
    ];

    foreach ($operational as $screen) {
        $found = array_values(array_diff(moneyFound(moneyProps($this->actingAs($this->assistant)->get($screen)), $this->planName), ['overdue']));

        expect($found)->toBe([], "{$screen} carried money to the Admin Assistant");
    }

    /*
     * THE SCAN IS NOT VACUOUS. The same scan over the Director's view of the
     * overdue client finds the badge, the per-unit price and a figure — so a
     * clean result above is the payload being clean, not the scan being blind.
     */
    $found = moneyFound(moneyProps($this->actingAs($this->director)->get('/clients/'.$this->ids['live'])), $this->planName);

    expect($found)->toContain('overdue')
        ->and($found)->toContain('/unit/mo')
        ->and(implode(' ', $found))->toContain('figure');
});

/* -----------------------------------------------------------------
 | 7. The routes: screens, writes, exports and the API
 |------------------------------------------------------------------ */

it('refuses the Admin Assistant every billing, payroll, report, audit and settings screen', function () {
    $refused = [
        '/billing',
        '/billing/plans',
        '/billing/payment-methods',
        '/billing/invoices/'.$this->overdueInvoiceId,
        '/billing/preview/'.$this->ids['live'],
        '/payroll',
        '/payroll/filings',
        '/payroll/rates',
        '/payroll/'.$this->runId,
        '/reports',
        '/reports/mrr',
        '/reports/revenue',
        '/reports/churn',
        '/reports/utilisation',
        '/reports/client-health',
        '/audit',
        '/settings',
        '/settings/packages',
        '/settings/line-items',
        '/settings/roles',
    ];

    foreach ($refused as $path) {
        $this->actingAs($this->assistant)->get($path)->assertForbidden();
    }

    // The Director reaches them — the refusal is the role's, not a broken route.
    foreach (['/billing', '/payroll', '/reports/mrr', '/audit'] as $path) {
        $this->actingAs($this->director)->get($path)->assertOk();
    }
});

it('refuses the Admin Assistant every money write', function () {
    $this->actingAs($this->assistant)->post('/billing/invoices/'.$this->overdueInvoiceId.'/resend')->assertForbidden();
    $this->actingAs($this->assistant)->post('/billing/invoices/'.$this->overdueInvoiceId.'/credit-note', ['amount' => '1.00', 'reason' => 'x'])->assertForbidden();
    $this->actingAs($this->assistant)->post('/billing/preview/'.$this->ids['live'].'/raise')->assertForbidden();
    $this->actingAs($this->assistant)->post('/payroll/'.$this->runId.'/approve')->assertForbidden();
    $this->actingAs($this->assistant)->post('/settings/prices', [])->assertForbidden();
    $this->actingAs($this->assistant)->post('/clients/'.$this->ids['live'].'/plan', [])->assertForbidden();
});

it('lets the Admin Assistant take no export at all, and the one export in a module it holds carries no money column', function () {
    foreach ([
        '/billing/invoices/'.$this->overdueInvoiceId.'/pdf',
        '/reports/mrr/export',
        '/reports/revenue/export',
        '/audit/export',
        '/guards/compliance/export',
    ] as $path) {
        $this->actingAs($this->assistant)->get($path)->assertForbidden();
    }

    /*
     * The licence register is the only export inside a module the Admin
     * Assistant holds. They cannot take it — it needs `export`, which View does
     * not carry — but should a later cell give it to them, this is what they
     * would get, and it has no money in it.
     */
    $csv = $this->actingAs($this->director)->get('/guards/compliance/export')->assertOk()->streamedContent();
    $header = strtolower(strtok($csv, "\n") ?: '');

    expect($header)->toContain('psra')
        ->and($header)->not->toContain('pay')
        ->and($header)->not->toContain('rate')

        // Every row, not only the heading: a figure in a cell is still a figure.
        ->and(moneyFound(['csv' => $csv], $this->planName))->toBe([]);
});

it('refuses a console session every authenticated /api/v1 endpoint, so the mobile API is no side door', function () {
    /*
     * The mobile API admits a guard's or a resident's handset token and nothing
     * else. Every authenticated GET the catalogue registers is asked, as the
     * Admin Assistant's console session, and every one refuses before a
     * controller runs — so none of them can be the place money leaks from.
     */
    $asked = 0;

    foreach (Catalogue::endpoints() as $name => $endpoint) {
        if ($endpoint['capability'] === null || $endpoint['method'] !== 'GET') {
            continue;
        }

        $uri = '/api/v1/'.ltrim((string) preg_replace('/\{[^}]+\}/', '1', $endpoint['uri']), '/');
        $response = $this->actingAs($this->assistant)->getJson($uri);

        expect($response->status())->toBeIn([401, 403], "{$name} ({$uri}) answered ".$response->status())
            ->and(moneyFound((array) $response->json(), $this->planName))->toBe([], "{$name} carried money");

        $asked++;
    }

    expect($asked)->toBeGreaterThan(0);
});
