<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Estate\PlanGate;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A module the estate's plan leaves out is absent, and its address says so (Hassam's closing order, 12 Oct)
|--------------------------------------------------------------------------
|
| "A disabled module is absent from navigation, and a deep link to one shows a clean 'not included in your plan' page,
| never a stack trace." Nothing read the plan for that before: the Features page drew switches whose answer nothing
| else asked, and every module stayed in the sidebar and opened, whatever the estate paid for. Two modules are a
| feature of their own, Accounting and Payroll & HR (`PlanGate`). An estate with no plan on record has nothing closed.
|
| The plan is changed here inside the central transaction each case rolls back; the estate's own records are untouched.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    // The plans and the package grid this file reads, made here rather than left to whichever test ran before it: on a
    // fresh database there was no Plan row, and three cases failed (the Hub, 12 Oct). Inside the transaction above.
    planGateCatalogue();

    $this->withoutVite();
    $this->admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    // On for these cases; off by default until every live estate's plan is checked (config/estate_features.php).
    config(['estate_features.plan_gate' => true]);
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/**
 * The three tiers, as BillingSeeder names them, where a database has none yet, and the package grid over them, written
 * by PlatformCatalogueSeeder itself (idempotent, central rows only), so the file needs no other test to have run first.
 */
function planGateCatalogue(): void
{
    foreach ([['essential', 'Essential', 180_00, 25, 10], ['standard', 'Standard', 260_00, 50, 20], ['premium', 'Premium', 340_00, 100, 30]] as [$key, $name, $price, $minUnits, $sort]) {
        Plan::query()->firstOrCreate(
            ['key' => $key],
            ['name' => $name, 'price_per_unit_minor' => $price, 'currency' => 'JMD', 'min_units' => $minUnits, 'is_active' => true, 'sort' => $sort],
        );
    }

    (new PlatformCatalogueSeeder)->run();
}

/** Put the fixture estate on a plan, inside this case's transaction. */
function planGateOn(string $planKey): void
{
    $plan = Plan::query()->where('key', $planKey)->firstOrFail();
    $subscription = Subscription::query()->where('tenant_id', FacilitiesFixture::ESTATE)->orderBy('id')->first();

    if ($subscription === null) {
        Subscription::query()->create([
            'tenant_id' => FacilitiesFixture::ESTATE,
            'plan_id' => $plan->id,
            'unit_count' => 40,
            'contracted_guards' => 0,
            'status' => 'active',
            'started_on' => now()->subYear()->toDateString(),
        ]);

        return;
    }

    $subscription->forceFill(['plan_id' => $plan->id])->save();
}

/** The sidebar's item keys, as the shell is sent them on a page the administrator may open. */
function planGateSidebar(object $test): array
{
    $keys = [];

    $test->actingAs($test->admin)
        ->get(FacilitiesFixture::url('/settings/profile'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$keys): void {
            $keys = collect($page->toArray()['props']['estateNav'])->pluck('key')->all();
        });

    return $keys;
}

it('takes Payroll & HR out of the sidebar on a plan without it, and answers its address with a page that says so', function (): void {
    planGateOn('standard');

    $sidebar = planGateSidebar($this);

    expect($sidebar)->not->toContain('payroll')
        // Standard includes Accounting: only what the plan leaves out goes.
        ->and($sidebar)->toContain('accounting');

    $this->actingAs($this->admin)->get(FacilitiesFixture::url('/payroll'))
        ->assertStatus(403)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/NotInPlan')
            ->where('module', 'Payroll & HR')
            ->where('planName', 'Standard'));

    // A deep link inside the module, not only its front door.
    $this->actingAs($this->admin)->get(FacilitiesFixture::url('/payroll/employees'))
        ->assertStatus(403)
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/NotInPlan'));

    // A write is refused back where it came from, in words, and changes nothing.
    $this->actingAs($this->admin)
        ->from(FacilitiesFixture::url('/settings/profile'))
        ->post(FacilitiesFixture::url('/payroll/runs'))
        ->assertRedirect(FacilitiesFixture::url('/settings/profile'))
        ->assertSessionHas('error', 'Payroll & HR is not included in the Standard plan. Nothing was changed.');

    $this->actingAs($this->admin)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))->assertOk();
});

it('closes Accounting too on a plan with neither, and nothing on a plan with both', function (): void {
    planGateOn('essential');

    expect(planGateSidebar($this))->not->toContain('accounting')->not->toContain('payroll');

    $this->actingAs($this->admin)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))
        ->assertStatus(403)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/NotInPlan')
            ->where('module', 'Accounting')
            ->where('planName', 'Essential'));

    planGateOn('premium');

    expect(planGateSidebar($this))->toContain('accounting')->toContain('payroll');

    $this->actingAs($this->admin)->get(FacilitiesFixture::url('/payroll'))->assertOk();
    $this->actingAs($this->admin)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))->assertOk();
});

it('closes nothing while the switch is off, and the features page says the module is still open', function (): void {
    config(['estate_features.plan_gate' => false]);
    planGateOn('standard');

    expect(PlanGate::enforced())->toBeFalse()
        ->and(planGateSidebar($this))->toContain('payroll')->toContain('accounting');

    $this->actingAs($this->admin)->get(FacilitiesFixture::url('/payroll'))->assertOk();

    $row = null;

    $this->actingAs($this->admin)->get(FacilitiesFixture::url('/settings/features'))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$row): void {
            $row = collect($page->toArray()['props']['groups'])->flatMap(fn (array $group): array => $group['rows'])->firstWhere('key', 'estate_payroll');
        });

    // The page does not call a module closed that is open: the plan leaves it out, and it is still running here.
    expect($row['state_label'])->toBe('Active')
        ->and($row['plan_line'])->toBe('Not in the Standard plan, but still open to this estate');
});

it('closes nothing for an estate with no plan on record, and gates only the two modules that are features', function (): void {
    expect(PlanGate::forEstate('no-such-estate'))->toBe(['plan' => null, 'plan_name' => null, 'left_out' => []])
        ->and(PlanGate::leavesOut('estate_payroll', 'no-such-estate'))->toBeFalse()
        ->and(PlanGate::leavesOut('estate_payroll', ''))->toBeFalse()
        // A feature that is no module of its own is never gated, whatever the plan.
        ->and(PlanGate::leavesOut('evoting', FacilitiesFixture::ESTATE))->toBeFalse()
        ->and(PlanGate::leavesOut('resident_core', FacilitiesFixture::ESTATE))->toBeFalse();
});

it('draws the page with the shell, a way to the dashboard, and no em dash', function (): void {
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/NotInPlan.vue'));

    expect($page)->toContain('<EstateConsole')
        // Without a board's stylesheet the shell's own mark is drawn across the whole screen.
        ->and($page)->toContain("useWireframe('community-admin-06-settings-estate-profile-users-and-roles')")
        ->and($page)->toContain('action-label="Go to the dashboard"')
        ->and($page)->toContain('is not included in your plan');

    $template = substr($page, (int) strpos($page, '<template>'));

    expect(str_contains($template, '—'))->toBeFalse();
});
