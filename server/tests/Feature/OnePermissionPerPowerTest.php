<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\Console;
use App\Enums\PermissionVerb;
use App\Models\Estate\Account;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\Bill;
use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\DunningTemplateDraft;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\PaymentPlanInstalment;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitCollectionFlag;
use App\Models\Estate\Vendor;
use App\Models\EstateAssignment;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Estate\ChargeSchedules;
use App\Services\Estate\Collections;
use App\Services\Estate\Payables;
use App\Services\Estate\Settings;
use App\Services\Gemini\InvoiceRun;
use App\Services\Rbac\RoleEditor;
use App\Support\NamedActs;
use Brick\Money\Money;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| One permission per power — M11 B3, PRICE-02, D-150
|--------------------------------------------------------------------------
|
| "Being able to change a dues rate must not also grant reversing a posted
| charge run or activating payment plans. Those are three different powers with
| three different risks. Make them three permissions, assign them sensibly by
| default, and check nothing else in the permission set is bundled the same way."
|
| Seven verbs were bundled that way, and each is now several named acts
| (`App\Support\NamedActs`): Dues & ledger's approval (five), Accounting's (two),
| Payroll's (two), Governance's (two), the estate Settings' update (two), Billing
| & subscriptions' update (four) and Platform settings' configure (three). This
| file proves, for every one of those twenty acts:
|
|   - the route that used the bundled permission names exactly that act, and no
|     `can:` gate anywhere names a permission that does not exist;
|   - a role holding every OTHER act of the same verb is refused it over HTTP, and
|     a role holding only that act is let through — and, for the five dues acts
|     the client named, actually does the thing;
|   - the defaults: the Community Super Admin sets dues rates and nothing else
|     on Dues & ledger that needs approval, the Treasurer keeps every approval it
|     had, the Property Manager holds none (Ruling 1), and every other seeded role
|     holds exactly what the old grid gave it, under the new names;
|   - the migration moves every holder of a retired permission onto all of its
|     replacements, narrows the Community Super Admin itself, records every
|     estate's own role left wider than its administrator, and rolls back failing
|     closed; a re-seed keeps a seeded Gemini role the Director changed, and
|     retires a bundled permission that came back;
|   - the role editor's guard rails cover every act: nobody gives or takes one
|     they do not hold, an act needs view however it is granted, Ruling 1 still
|     binds, the structural minimum is compiled act by act, and one grant has one
|     stored form;
|   - nobody changes their own role from the committee screen;
|   - the console gate (`gate:console`) still passes.
|
| THE ESTATE IS `FacilitiesFixture`'s, committed and shared by every file in the
| process (the fixture drops and rebuilds its database once, when the process
| first boots it). What this file writes there lands on lots of its own — Lot 964
| to 966 in Ackee Walk — and on a supplier of its own, SPLIT_VENDOR, neither of
| which any other file uses. Most of it is taken away afterwards (see
| `afterEach`). WHAT STAYS, BECAUSE THE LEDGER IS APPEND-ONLY: the journal
| entries of each charge run this file posts and of its reversal, which net to
| nothing on its own lots; and the one bill it approves, J$1,234.00 owed to its
| own supplier and posted to 2000 Accounts Payable, with that bill's row, since a
| bill whose posting cannot go must not go either. Every other file measures
| payables and receivables against what was there before it, so neither moves an
| assertion anywhere else. The central database is rolled back after every test.
|
*/

const SPLIT_BLOCK = 'Ackee Walk';
const SPLIT_LOTS = ['Lot 964', 'Lot 965', 'Lot 966'];
const SPLIT_SCHEDULE = 'Split Test Maintenance Fee';
const SPLIT_STEP = 'Split test reminder';

/** This file's own supplier, so no other file's per-supplier figures move. */
const SPLIT_VENDOR = 'Split Test Electrical Supplies';

/** The five dues approvals, in the order the registry lists them. */
const DUES_ACTS = ['set_rates', 'reverse_runs', 'activate_plans', 'flag_units', 'adopt_dunning'];

/**
 * A role made directly, as a database the editor could not have written would
 * hold it: each cell as given, compiled the one way cells are compiled.
 *
 * DIRECTLY, AND NOT THROUGH `RoleEditor`, because the roles this file needs hold
 * approvals no seeded editor may give — nobody who can edit an estate's roles
 * holds all five dues approvals — and what is under test is what a role holding
 * one act may do, not who may make it.
 *
 * @param  array<string, array{level: string, verbs?: list<string>|null, acts?: list<string>|null, can_approve?: bool}>  $cells
 */
function splitRole(Console $console, string $label, array $cells): Role
{
    $role = new Role;
    $role->forceFill([
        'name' => $console->value.'.custom.'.($console === Console::Estate ? FacilitiesFixture::ESTATE.'.' : '').'split_'.Str::lower(Str::random(10)),
        'guard_name' => 'web',
        'console' => $console->value,
        'tenant_id' => $console === Console::Estate ? FacilitiesFixture::ESTATE : null,
        'is_system' => false,
        'label' => $label,
        'sort' => 990,
        'scope_default' => AccessScope::All->value,
    ])->save();

    foreach ($cells as $key => $cell) {
        $module = Module::query()->where('console', $console->value)->where('key', $key)->firstOrFail();

        RoleModuleAccess::query()->create([
            'role_id' => $role->id,
            'module_id' => $module->id,
            'level' => $cell['level'],
            'verbs' => $cell['verbs'] ?? null,
            'acts' => $cell['acts'] ?? null,
            'can_approve' => $cell['can_approve'] ?? false,
            'scope' => AccessScope::All->value,
        ]);
    }

    $role->syncPermissions(splitCompiled($role));

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role->fresh();
}

/**
 * An estate administrator NARROWER than the Community Super Admin, who holds every approval since M55: it may edit
 * roles (Settings · Approver) and holds one dues act, so the editor's rule that nobody gives or takes what they do not
 * hold themselves is still proven against somebody who lacks something.
 */
function splitNarrowAdministrator(): User
{
    $role = splitRole(Console::Estate, 'Split narrow administrator', [
        'settings' => ['level' => 'full', 'can_approve' => true],
        'dues_ledger' => ['level' => 'full', 'acts' => ['set_rates']],
    ]);

    return FacilitiesFixture::viewer($role->name);
}

/** A holder of an estate role made WITHOUT the fixture's viewer, whose matrix check would re-seed the roles. */
function splitEstateHolder(Role $role): User
{
    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'role_id' => $role->id, 'is_active' => true]);

    return $user->fresh();
}

/** @return list<string> what a role's cells compile to, sorted */
function splitCompiled(Role $role): array
{
    $names = RoleModuleAccess::query()->with('module')->where('role_id', $role->id)->get()
        ->flatMap(static fn (RoleModuleAccess $cell): array => $cell->permissionNames())
        ->unique()
        ->sort()
        ->values()
        ->all();

    return $names;
}

/** @return list<string> the role's compiled permissions, as `can:` reads them, sorted */
function splitHeld(Role|string $role): array
{
    $role = $role instanceof Role ? $role : Role::named($role);

    return $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
}

/** Every act permission there is, sorted. */
function splitAllActs(): array
{
    $all = array_merge(...array_values(NamedActs::replacements()));
    sort($all);

    return $all;
}

/** A user of a Gemini role with nothing else about them — no alert gate applies to a custom role. */
function splitGeminiUser(Role $role): User
{
    return FacilitiesFixture::geminiViewer($role->name);
}

/** The estate's own editor, acting inside the fixture's estate. */
function splitInEstate(callable $work): mixed
{
    FacilitiesFixture::boot();

    try {
        return FacilitiesFixture::platform()->run($work);
    } finally {
        tenancy()->end();
        FacilitiesFixture::boot();
    }
}

/** The migration itself, so its grant move can be run against planted rows. */
function splitMigration(): object
{
    return require database_path('migrations/2026_09_24_200000_one_permission_per_power_where_one_verb_carried_several.php');
}

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // One fixed day, so every month named below is the month the test means —
    // the day `EstateEditablePricingTest` uses, for the same reason.
    $this->travelTo(Carbon::parse('2026-09-24 10:00:00'));

    // Watermarks on the estate tables this file adds to, taken before it adds.
    $tenant = DB::connection('tenant');
    $this->estateWas = [
        'dunning_template_drafts' => (int) $tenant->table('dunning_template_drafts')->max('id'),
        'dunning_templates' => (int) $tenant->table('dunning_templates')->max('id'),
        'bank_reconciliations' => (int) $tenant->table('bank_reconciliations')->max('id'),
        'statutory_filings' => (int) $tenant->table('statutory_filings')->max('id'),
        'ballots' => (int) $tenant->table('ballots')->max('id'),
        'meetings' => (int) $tenant->table('meetings')->max('id'),
    ];

    $this->treasurer = FacilitiesFixture::viewer(Role::TREASURER);
});

afterEach(function () {
    FacilitiesFixture::boot();

    $schedules = app(ChargeSchedules::class);
    $ids = ChargeSchedule::query()->where('description', SPLIT_SCHEDULE)->pluck('id');

    // Every month this file posted, taken back out of the lots it billed.
    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->whereNull('reversed_at')->get()
        ->each(fn (ChargeRun $run) => $schedules->reverse($run, 'Split test month, taken back out.', $this->treasurer));

    $tenant = DB::connection('tenant');
    $tenant->table('charge_schedule_rate_changes')->whereIn('charge_schedule_id', $ids)->delete();
    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->delete();
    ChargeSchedule::query()->whereIn('id', $ids)->delete();

    $units = Unit::query()->whereIn('reference', SPLIT_LOTS)->pluck('id');
    $plans = PaymentPlan::query()->whereIn('unit_id', $units)->pluck('id');
    $tenant->table('payment_plan_instalments')->whereIn('payment_plan_id', $plans)->delete();
    PaymentPlan::query()->whereIn('id', $plans)->delete();
    UnitCollectionFlag::query()->whereIn('unit_id', $units)->delete();

    // Children before parents, and only what this file added.
    foreach (['dunning_template_drafts', 'dunning_templates', 'bank_reconciliations', 'statutory_filings', 'ballots', 'meetings'] as $table) {
        $tenant->table($table)->where('id', '>', $this->estateWas[$table])->delete();
    }

    // This file's bills that posted nothing — a draft, or one voided as a draft.
    // The approved one stays with its journal entry, which cannot go (see above).
    $vendor = $tenant->table('vendors')->where('name', SPLIT_VENDOR)->value('id');

    if ($vendor !== null) {
        $tenant->table('bills')->where('vendor_id', $vendor)->whereNull('journal_ref')->delete();
    }

    DB::connection('mysql')->rollBack();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/* ------------------------------------------------------------------ */
/* the registry and the route table                                    */
/* ------------------------------------------------------------------ */

it('lists each bundled verb as the acts that replaced it, and no act shares a name with a verb', function () {
    expect(NamedActs::replacements())->toBe([
        'estate.dues_ledger.approve' => [
            'estate.dues_ledger.set_rates',
            'estate.dues_ledger.reverse_runs',
            'estate.dues_ledger.activate_plans',
            'estate.dues_ledger.flag_units',
            'estate.dues_ledger.adopt_dunning',
        ],
        'estate.accounting_posting.approve' => [
            'estate.accounting_posting.approve_bills',
            'estate.accounting_posting.complete_reconciliations',
        ],
        'estate.payroll.approve' => [
            'estate.payroll.approve_runs',
            'estate.payroll.file_returns',
        ],
        'estate.governance.approve' => [
            'estate.governance.certify_elections',
            'estate.governance.publish_meetings',
        ],
        'estate.settings.update' => [
            'estate.settings.manage_people',
            'estate.settings.edit_profile',
        ],
        'gemini.billing_subscriptions.update' => [
            'gemini.billing_subscriptions.price_clients',
            'gemini.billing_subscriptions.raise_invoices',
            'gemini.billing_subscriptions.resend_invoices',
            'gemini.billing_subscriptions.issue_credit_notes',
        ],
        'gemini.platform_settings.configure' => [
            'gemini.platform_settings.set_prices',
            'gemini.platform_settings.configure_messaging',
            'gemini.platform_settings.run_simulator',
        ],
    ]);

    // THE MIGRATION SAYS THE SAME. It writes its own copy of the list, because it
    // must mean on the day it runs what it meant when it was written; the two may
    // not drift apart before it ships.
    $migration = new ReflectionClassConstant(splitMigration(), 'SPLITS');

    expect($migration->getValue())->toBe(NamedActs::replacements());

    // An act named like a verb would compile to a permission a verb already means.
    $verbs = array_map(static fn (PermissionVerb $verb): string => $verb->value, PermissionVerb::cases());

    foreach (splitAllActs() as $permission) {
        expect(in_array(explode('.', $permission)[2], $verbs, true))->toBeFalse($permission.' is named like a verb');
    }

    // THE MATRIX HOLDS EVERY ACT, AND NONE OF THE PERMISSIONS THEY REPLACED.
    $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

    expect(array_diff(splitAllActs(), $existing))->toBe([])
        ->and(array_intersect(array_keys(NamedActs::replacements()), $existing))->toBe([]);
});

it('gates every route that used a bundled permission on exactly the one act it is', function () {
    $expected = [
        'estate.dues.schedule.create' => 'estate.dues_ledger.set_rates',
        'estate.dues.schedule.stop' => 'estate.dues_ledger.set_rates',
        'estate.dues.schedule.rate' => 'estate.dues_ledger.set_rates',
        'estate.dues.schedule.run.reverse' => 'estate.dues_ledger.reverse_runs',
        'estate.dues.plan.activate' => 'estate.dues_ledger.activate_plans',
        // Bug hunt B019's rulings arrived with the base merge on `approve`; they
        // are the same power as activating, and the same act.
        'estate.dues.plan.instalment.met' => 'estate.dues_ledger.activate_plans',
        'estate.dues.plan.instalment.missed' => 'estate.dues_ledger.activate_plans',
        'estate.dues.unit.flag' => 'estate.dues_ledger.flag_units',
        'estate.dues.unit.flag.lift' => 'estate.dues_ledger.flag_units',
        'estate.dues.dunning.adopt' => 'estate.dues_ledger.adopt_dunning',
        'estate.accounting.bill.approve' => 'estate.accounting_posting.approve_bills',
        // Bug hunt B036's void, the mirror of approving.
        'estate.accounting.bill.void' => 'estate.accounting_posting.approve_bills',
        'estate.accounting.reconciliation.complete' => 'estate.accounting_posting.complete_reconciliations',
        'estate.payroll.run.approve' => 'estate.payroll.approve_runs',
        'estate.payroll.filing.file' => 'estate.payroll.file_returns',
        'estate.governance.ballot.certify' => 'estate.governance.certify_elections',
        'estate.governance.meeting.publish' => 'estate.governance.publish_meetings',
        'estate.settings.user.manage' => 'estate.settings.manage_people',
        'estate.settings.user.remove' => 'estate.settings.manage_people',
        // M10 §4's reinstatement, the removal's inverse, which arrived on `update`.
        'estate.settings.user.reinstate' => 'estate.settings.manage_people',
        'estate.settings.profile.save' => 'estate.settings.edit_profile',
        'estate.settings.profile.logo' => 'estate.settings.edit_profile',
        'estate.settings.notifications.save' => 'estate.settings.edit_profile',
        // Billing's update, found by the reviewers after the first six: pricing a
        // client (bug hunt B004 and B069 put it on the verb) and the three invoice writes.
        'gemini.clients.plan' => 'gemini.billing_subscriptions.price_clients',
        'gemini.clients.plan.save' => 'gemini.billing_subscriptions.price_clients',
        'gemini.clients.create' => 'gemini.billing_subscriptions.price_clients',
        'gemini.clients.store' => 'gemini.billing_subscriptions.price_clients',
        'gemini.billing_subscriptions.invoice.raise' => 'gemini.billing_subscriptions.raise_invoices',
        'gemini.billing_subscriptions.invoice.resend' => 'gemini.billing_subscriptions.resend_invoices',
        'gemini.billing_subscriptions.invoice.credit_note' => 'gemini.billing_subscriptions.issue_credit_notes',
        'gemini.platform_settings.price' => 'gemini.platform_settings.set_prices',
        'gemini.platform_settings.rate' => 'gemini.platform_settings.set_prices',
        'gemini.platform_settings.packages.save' => 'gemini.platform_settings.set_prices',
        'gemini.platform_settings.line_items.add' => 'gemini.platform_settings.set_prices',
        'gemini.platform_settings.line_items.end' => 'gemini.platform_settings.set_prices',
        'gemini.platform_settings.mail.save' => 'gemini.platform_settings.configure_messaging',
        'gemini.platform_settings.mail.test' => 'gemini.platform_settings.configure_messaging',
        'gemini.platform_settings.sms.save' => 'gemini.platform_settings.configure_messaging',
        'gemini.platform_settings.sms.test' => 'gemini.platform_settings.configure_messaging',
        'gemini.simulator' => 'gemini.platform_settings.run_simulator',
        'gemini.simulator.alert' => 'gemini.platform_settings.run_simulator',
        'gemini.simulator.gate_event' => 'gemini.platform_settings.run_simulator',
        'gemini.simulator.shifts' => 'gemini.platform_settings.run_simulator',
        'gemini.simulator.ambient' => 'gemini.platform_settings.run_simulator',
    ];

    $acts = splitAllActs();

    foreach ($expected as $name => $permission) {
        // Each route is registered once per resolver (subdomain and, locally, path).
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => $route->getName() === $name);

        expect($routes)->not->toBeEmpty("route {$name} is missing");

        foreach ($routes as $route) {
            $gates = array_values(array_filter($route->gatherMiddleware(), static fn ($m): bool => is_string($m) && str_starts_with($m, 'can:')));

            // Its act, and no other act and no retired verb.
            expect($gates)->toContain('can:'.$permission)
                ->and(array_values(array_intersect(array_map(static fn (string $g): string => substr($g, 4), $gates), $acts)))->toBe([$permission]);
        }
    }

    // And every act gates at least one route: none was split off and left unenforced.
    $used = collect(Route::getRoutes()->getRoutes())
        ->flatMap(static fn ($route): array => $route->gatherMiddleware())
        ->filter(static fn ($m): bool => is_string($m) && str_starts_with($m, 'can:'))
        ->map(static fn (string $m): string => substr($m, 4))
        ->unique()
        ->all();

    expect(array_diff($acts, $used))->toBe([]);
});

it('names only permissions that exist, on every can: gate in the route table', function () {
    $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->flip();

    $missing = collect(Route::getRoutes()->getRoutes())
        ->flatMap(static fn ($route): array => array_map(
            static fn ($m): ?string => is_string($m) && str_starts_with($m, 'can:') ? explode(',', substr($m, 4))[0] : null,
            $route->gatherMiddleware(),
        ))
        ->filter()
        ->unique()
        ->reject(static fn (string $ability): bool => $existing->has($ability))
        ->values()
        ->all();

    // A gate on a permission nobody can hold refuses everybody, silently.
    expect($missing)->toBe([]);
});

it('leaves no check anywhere in the application naming a permission that was split', function () {
    $pattern = '/([\'"])(?:can:)?('.implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), array_keys(NamedActs::replacements()))).')\1/';
    $found = [];

    foreach (['app', 'routes', 'resources/js', 'database/seeders'] as $dir) {
        foreach (File::allFiles(base_path($dir)) as $file) {
            if (! in_array($file->getExtension(), ['php', 'vue', 'js'], true)) {
                continue;
            }

            if (preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches)) {
                $found[] = $file->getRelativePathname().': '.implode(', ', array_unique($matches[2]));
            }
        }
    }

    expect($found)->toBe([]);
});

/* ------------------------------------------------------------------ */
/* the defaults                                                        */
/* ------------------------------------------------------------------ */

it('gives every seeded role exactly the approvals the old grid gave it, and the Community Super Admin every one (M55)', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    /*
     * The old grid, read cell by cell: whoever held a bundled permission holds
     * every act it became. Written out rather than derived, so the test states
     * the defaults instead of repeating the code that makes them.
     */
    $dues = ['estate.dues_ledger.set_rates', 'estate.dues_ledger.reverse_runs', 'estate.dues_ledger.activate_plans', 'estate.dues_ledger.flag_units', 'estate.dues_ledger.adopt_dunning'];
    $accounting = ['estate.accounting_posting.approve_bills', 'estate.accounting_posting.complete_reconciliations'];
    $payroll = ['estate.payroll.approve_runs', 'estate.payroll.file_returns'];
    $governance = ['estate.governance.certify_elections', 'estate.governance.publish_meetings'];
    $settings = ['estate.settings.manage_people', 'estate.settings.edit_profile'];
    $billing = ['gemini.billing_subscriptions.price_clients', 'gemini.billing_subscriptions.raise_invoices', 'gemini.billing_subscriptions.resend_invoices', 'gemini.billing_subscriptions.issue_credit_notes'];
    $platform = ['gemini.platform_settings.set_prices', 'gemini.platform_settings.configure_messaging', 'gemini.platform_settings.run_simulator'];

    $expected = [
        // EVERY APPROVAL (the user, 8 Oct 2026, M55: "Make the Super Admin genuinely super", master reference §5.3).
        // This supersedes M11 B3's single dues act. Settings · update was this role's alone, and both halves stay.
        Role::COMMUNITY_SUPER_ADMIN => [...$dues, ...$accounting, ...$payroll, ...$governance, ...$settings],
        Role::PRESIDENT => $governance,
        Role::VICE_PRESIDENT => $governance,
        Role::SECRETARY => [],
        Role::PROPERTY_MANAGER => [],
        Role::TREASURER => [...$dues, ...$accounting],
        Role::ESTATE_ADMIN_ASSISTANT => [],
        Role::ESTATE_HEAD_OF_SECURITY => [],
        // Billing & subscriptions' update was the Director's and the Accountant's
        // (both Full); the Operations Manager reads Billing and holds none of it.
        Role::DIRECTOR => [...$billing, ...$platform],
        Role::OPERATIONS_MANAGER => [],
        Role::HEAD_OF_SECURITY => [],
        Role::DISPATCHER => [],
        Role::ADMIN_ASSISTANT => [],
        Role::ACCOUNTANT => $billing,
    ];

    foreach ($expected as $role => $acts) {
        sort($acts);

        expect(array_values(array_intersect(splitHeld($role), splitAllActs())))->toBe($acts, $role);
    }

    // THE CLIENT'S THREE POWERS are still three permissions, and the Community Super Admin holds all of them, with
    // the three the user found refused: publishing a meeting, approving a bill and voiding one (the same act).
    $csa = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    expect($csa->can('estate.dues_ledger.set_rates'))->toBeTrue()
        ->and($csa->can('estate.dues_ledger.reverse_runs'))->toBeTrue()
        ->and($csa->can('estate.dues_ledger.activate_plans'))->toBeTrue()
        ->and($csa->can('estate.dues_ledger.flag_units'))->toBeTrue()
        ->and($csa->can('estate.dues_ledger.adopt_dunning'))->toBeTrue()
        ->and($csa->can('estate.governance.publish_meetings'))->toBeTrue()
        ->and($csa->can('estate.governance.certify_elections'))->toBeTrue()
        ->and($csa->can('estate.accounting_posting.approve_bills'))->toBeTrue();

    // The Property Manager holds nothing on Dues & ledger at all — Ruling 1.
    expect(array_filter(splitHeld(Role::PROPERTY_MANAGER), static fn (string $p): bool => str_starts_with($p, 'estate.dues_ledger.')))->toBe([]);

    // The pills say it: one approval on its own, and the tag that is all of them.
    $pill = static fn (string $role, string $module): string => RoleModuleAccess::query()->with('module')
        ->where('role_id', Role::named($role)->id)
        ->whereHas('module', static fn ($m) => $m->where('key', $module))
        ->sole()
        ->pillLabel();

    expect($pill(Role::COMMUNITY_SUPER_ADMIN, 'dues_ledger'))->toBe('Full · Approver')
        ->and($pill(Role::TREASURER, 'dues_ledger'))->toBe('Full · Approver')
        ->and($pill(Role::DIRECTOR, 'platform_settings'))->toBe('Full · Approver');
});

it('refuses a grid that would give the Property Manager a dues act, or say an act the pill already grants', function () {
    $grid = static fn (array $row): RbacMatrixSeeder => new class($row) extends RbacMatrixSeeder
    {
        /** @param  array<int, string>  $row */
        public function __construct(private readonly array $row) {}

        protected function estateGrid(): array
        {
            return array_replace(parent::estateGrid(), ['dues_ledger' => $this->row]);
        }
    };

    // Ruling 1, even for an approval on its own and even at view.
    expect(fn () => $grid(['F+set_rates', 'V', 'V', '-', 'V+set_rates', 'A', 'E', '-'])->run())
        ->toThrow(LogicException::class, 'D-010');

    // An act the Approver tag already grants, an act the module does not have, and
    // every act listed where the tag belongs.
    expect(fn () => $grid(['A+set_rates', 'V', 'V', '-', '-', 'A', 'E', '-'])->run())->toThrow(LogicException::class, 'already grants')
        ->and(fn () => $grid(['F+set_prices', 'V', 'V', '-', '-', 'A', 'E', '-'])->run())->toThrow(LogicException::class, 'does not have')
        ->and(fn () => $grid(['F+'.implode(',', DUES_ACTS), 'V', 'V', '-', '-', 'A', 'E', '-'])->run())->toThrow(LogicException::class, 'Write the verb instead');
});

/* ------------------------------------------------------------------ */
/* the migration and the re-seed                                       */
/* ------------------------------------------------------------------ */

it('moves every holder of a bundled permission onto all of its replacements, and takes the bundled one away', function () {
    // THE WORLD BEFORE THE SPLIT, planted: the two bundled permissions, a custom
    // role holding each, and an account holding one directly.
    $bundled = Permission::query()->create(['name' => 'estate.dues_ledger.approve', 'guard_name' => 'web']);
    $configure = Permission::query()->create(['name' => 'gemini.platform_settings.configure', 'guard_name' => 'web']);

    $estateRole = splitRole(Console::Estate, 'Split pre-split approver', ['dues_ledger' => ['level' => 'full']]);
    $geminiRole = splitRole(Console::Gemini, 'Split pre-split configurer', ['platform_settings' => ['level' => 'view']]);
    $holder = User::factory()->create();

    DB::connection('mysql')->table('role_has_permissions')->insert([
        ['permission_id' => $bundled->id, 'role_id' => $estateRole->id],
        ['permission_id' => $configure->id, 'role_id' => $geminiRole->id],
    ]);
    DB::connection('mysql')->table('model_has_permissions')->insert([
        'permission_id' => $bundled->id, 'model_type' => $holder->getMorphClass(), 'model_id' => $holder->id,
    ]);

    $dues = NamedActs::replacements()['estate.dues_ledger.approve'];
    $platform = NamedActs::replacements()['gemini.platform_settings.configure'];

    $moved = splitMigration()->moveGrants('mysql');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // THE SAME POWERS, UNDER THEIR NEW NAMES — every act, for every holder.
    expect($moved)->toBe(['estate.dues_ledger.approve' => 2, 'gemini.platform_settings.configure' => 1])
        ->and(array_values(array_intersect(splitHeld($estateRole), $dues)))->toBe(collect($dues)->sort()->values()->all())
        ->and(array_values(array_intersect(splitHeld($geminiRole), $platform)))->toBe(collect($platform)->sort()->values()->all())
        ->and($holder->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())->toBe(collect($dues)->sort()->values()->all())
        ->and(Permission::query()->whereIn('name', ['estate.dues_ledger.approve', 'gemini.platform_settings.configure'])->exists())->toBeFalse();

    // Idempotent: a second run finds nothing left to move.
    expect(splitMigration()->moveGrants('mysql'))->toBe([]);
});

it('rolls back failing closed: a holder of every act gets the verb, a holder of some loses them, and each loss is on the audit log', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // THE SPLIT WORLD, planted: a community's role holding one act of each of
    // three verbs and the whole of a fourth, a Gemini role holding one configure
    // act, an account holding one approval directly and another holding both.
    $estate = splitRole(Console::Estate, 'Split rollback estate', [
        'dues_ledger' => ['level' => 'view', 'acts' => ['set_rates']],
        'payroll' => ['level' => 'view', 'acts' => ['file_returns']],
        'settings' => ['level' => 'view', 'acts' => ['edit_profile']],
        'governance' => ['level' => 'view', 'can_approve' => true],
    ]);
    $gemini = splitRole(Console::Gemini, 'Split rollback gemini', [
        'platform_settings' => ['level' => 'view', 'acts' => ['run_simulator']],
    ]);
    $partial = User::factory()->create();
    $partial->givePermissionTo('estate.governance.certify_elections');
    $whole = User::factory()->create();
    $whole->givePermissionTo('estate.governance.certify_elections', 'estate.governance.publish_meetings');

    $logged = DB::connection('mysql')->table('audit_log')->max('id') ?? 0;

    $folded = splitMigration()->foldGrants('mysql');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Every retired verb is back and every act is gone.
    expect(array_keys($folded))->toBe(array_keys(NamedActs::replacements()))
        ->and(Permission::query()->whereIn('name', array_keys(NamedActs::replacements()))->count())->toBe(count(NamedActs::replacements()))
        ->and(Permission::query()->whereIn('name', splitAllActs())->exists())->toBeFalse();

    // NARROWED, NEVER WIDENED: one act of a verb is not the verb. Given only the
    // estate profile, a role would otherwise come back able to seat a Treasurer.
    expect(splitHeld($estate))->not->toContain('estate.dues_ledger.approve')
        ->and(splitHeld($estate))->not->toContain('estate.payroll.approve')
        ->and(splitHeld($estate))->not->toContain('estate.settings.update')
        ->and(splitHeld($gemini))->not->toContain('gemini.platform_settings.configure')
        ->and($partial->fresh()->getDirectPermissions()->pluck('name')->all())->toBe([]);

    // A HOLDER OF THE WHOLE VERB KEEPS IT: the tag on a community's own role, an
    // account holding both acts, and every seeded role at its grid — the Community
    // Super Admin holds every act since M55, so it keeps every verb.
    expect(splitHeld($estate))->toContain('estate.governance.approve')
        ->and($whole->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['estate.governance.approve'])
        ->and(splitHeld(Role::TREASURER))->toContain('estate.dues_ledger.approve', 'estate.accounting_posting.approve')
        ->and(splitHeld(Role::COMMUNITY_SUPER_ADMIN))->toContain('estate.payroll.approve', 'estate.settings.update', 'estate.dues_ledger.approve', 'estate.governance.approve')
        ->and(splitHeld(Role::DIRECTOR))->toContain('gemini.billing_subscriptions.update', 'gemini.platform_settings.configure')
        ->and(splitHeld(Role::ACCOUNTANT))->toContain('gemini.billing_subscriptions.update');

    // And the cells say what the table now holds: no act list left, and no tag or
    // verb put where only some acts were.
    $cells = RoleModuleAccess::query()->with('module')->whereIn('role_id', [$estate->id, $gemini->id])->get()
        ->keyBy(static fn (RoleModuleAccess $cell): string => $cell->module->key);

    expect([$cells['dues_ledger']->level, $cells['dues_ledger']->can_approve, $cells['dues_ledger']->acts])->toBe([AccessLevel::View, false, null])
        ->and([$cells['payroll']->level, $cells['payroll']->can_approve, $cells['payroll']->acts])->toBe([AccessLevel::View, false, null])
        ->and([$cells['settings']->level, $cells['settings']->verbs, $cells['settings']->acts])->toBe([AccessLevel::View, null, null])
        ->and([$cells['governance']->can_approve, $cells['governance']->acts])->toBe([true, null])
        ->and([$cells['platform_settings']->level, $cells['platform_settings']->verbs, $cells['platform_settings']->acts])->toBe([AccessLevel::View, null, null]);

    // EVERY LOSS IS WRITTEN DOWN, so it can be re-granted knowingly: the four the
    // planted roles lost and the account's — and no seeded role lost anything, each
    // having held the whole of every verb it held. Asked of these holders only: the
    // shared central database may hold other files' roles.
    $mine = [$estate->name, $gemini->name, (string) $partial->id, (string) $whole->id, ...Role::query()->where('is_system', true)->pluck('name')->all()];

    $losses = DB::connection('mysql')->table('audit_log')->where('id', '>', $logged)
        ->whereIn('action', ['role.act_lost_in_rollback', 'user.act_lost_in_rollback'])
        ->whereIn('entity_id', $mine)
        ->get(['action', 'entity_id', 'tenant_id', 'before', 'actor_id'])
        ->map(static fn ($row): array => [$row->action, $row->entity_id, $row->tenant_id, json_decode((string) $row->before, true)['permissions'], $row->actor_id]);

    expect($losses->all())->toEqualCanonicalizing([
        ['role.act_lost_in_rollback', $estate->name, FacilitiesFixture::ESTATE, ['estate.dues_ledger.set_rates'], null],
        ['role.act_lost_in_rollback', $estate->name, FacilitiesFixture::ESTATE, ['estate.payroll.file_returns'], null],
        ['role.act_lost_in_rollback', $estate->name, FacilitiesFixture::ESTATE, ['estate.settings.edit_profile'], null],
        ['role.act_lost_in_rollback', $gemini->name, null, ['gemini.platform_settings.run_simulator'], null],
        ['user.act_lost_in_rollback', (string) $partial->id, null, ['estate.governance.certify_elections'], null],
    ]);
});

it('compiles a cell written before the split to every act its verb became, so a custom role keeps exactly its powers', function () {
    $approver = splitRole(Console::Estate, 'Split old approver', [
        'dues_ledger' => ['level' => 'full', 'can_approve' => true],
        'payroll' => ['level' => 'view', 'can_approve' => true],
    ]);

    $configurer = splitRole(Console::Gemini, 'Split old configurer', [
        'platform_settings' => ['level' => 'custom', 'verbs' => ['view', 'configure']],
    ]);

    expect(splitHeld($approver))->toContain('estate.dues_ledger.set_rates', 'estate.dues_ledger.reverse_runs', 'estate.dues_ledger.activate_plans', 'estate.dues_ledger.flag_units', 'estate.dues_ledger.adopt_dunning', 'estate.payroll.approve_runs', 'estate.payroll.file_returns')
        ->and(splitHeld($approver))->not->toContain('estate.dues_ledger.approve')
        ->and(splitHeld($configurer))->toBe(['gemini.platform_settings.configure_messaging', 'gemini.platform_settings.run_simulator', 'gemini.platform_settings.set_prices', 'gemini.platform_settings.view']);

    // A re-seed leaves a community's own role exactly as it was.
    $before = splitHeld($approver);

    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    expect(splitHeld($approver))->toBe($before);
});

it('keeps what the Director changed on a seeded Gemini role through a re-seed, acts and all, and resets it on request', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $editor = app(RoleEditor::class);

    // The Accountant, given the prices alone; the Operations Manager, given the whole
    // of Platform settings — every configure act with it.
    $editor->setPermissions($director, Role::named(Role::ACCOUNTANT), [
        'platform_settings' => ['level' => 'view', 'acts' => ['set_prices']],
    ]);
    $editor->setPermissions($director, Role::named(Role::OPERATIONS_MANAGER), [
        'platform_settings' => ['level' => 'full'],
    ]);

    $accountant = splitHeld(Role::ACCOUNTANT);
    $operations = splitHeld(Role::OPERATIONS_MANAGER);

    expect($accountant)->toContain('gemini.platform_settings.set_prices')
        ->and($accountant)->not->toContain('gemini.platform_settings.configure_messaging')
        ->and($operations)->toContain('gemini.platform_settings.set_prices', 'gemini.platform_settings.configure_messaging', 'gemini.platform_settings.run_simulator');

    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // THE DIRECTOR'S CHANGES STAND, and the Accountant's pill says which act it holds.
    expect(splitHeld(Role::ACCOUNTANT))->toBe($accountant)
        ->and(splitHeld(Role::OPERATIONS_MANAGER))->toBe($operations)
        ->and(Role::named(Role::ACCOUNTANT)->permissions_edited_at)->not->toBeNull()
        ->and(RoleModuleAccess::query()->with('module')->where('role_id', Role::named(Role::ACCOUNTANT)->id)
            ->whereHas('module', static fn ($m) => $m->where('key', 'platform_settings'))->sole()->pillLabel())->toBe('View · Sets prices');

    // And the way back: what a reset would take away is named by act, and it goes.
    $difference = $editor->defaultDifference(Role::named(Role::ACCOUNTANT));

    expect($difference['remove'])->toContain('gemini.platform_settings.set_prices', 'gemini.platform_settings.view');

    $editor->resetToDefault($director, Role::named(Role::ACCOUNTANT));

    expect(splitHeld(Role::ACCOUNTANT))->not->toContain('gemini.platform_settings.set_prices')
        ->and(Role::named(Role::ACCOUNTANT)->permissions_edited_at)->toBeNull();
});

it('re-seeds the Community Super Admin to every dues approval (M55), from the one act M11 B3 left it', function () {
    // The cell M11 B3 left on every server: Full plus `set_rates` alone.
    $csa = Role::named(Role::COMMUNITY_SUPER_ADMIN);
    $cell = RoleModuleAccess::query()->where('role_id', $csa->id)
        ->whereHas('module', static fn ($m) => $m->where('console', 'estate')->where('key', 'dues_ledger'))->sole();
    $cell->forceFill(['can_approve' => false, 'acts' => ['set_rates']])->save();
    $csa->syncPermissions(splitCompiled($csa));

    expect(splitHeld($csa))->not->toContain('estate.dues_ledger.reverse_runs');

    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $cell = $cell->fresh();
    $dues = NamedActs::replacements()['estate.dues_ledger.approve'];
    sort($dues);

    expect($cell->can_approve)->toBeTrue()
        ->and($cell->acts)->toBeNull()
        ->and(array_values(array_intersect(splitHeld($csa), $dues)))->toBe($dues);
});

it('narrows the Community Super Admin in the M11 B3 migration itself, and the M55 re-seed every deploy runs gives every approval back', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    // A server the moment step 2 has run: the Approver tag D-141 left on the cell,
    // compiled to all five acts. The deploy runs no re-seed after this.
    $csa = Role::named(Role::COMMUNITY_SUPER_ADMIN);
    $cell = RoleModuleAccess::query()->where('role_id', $csa->id)
        ->whereHas('module', static fn ($m) => $m->where('console', 'estate')->where('key', 'dues_ledger'))->sole();
    $cell->forceFill(['can_approve' => true, 'acts' => null])->save();
    $csa->syncPermissions(splitCompiled($csa));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Read off the role, not through a fixture account: `FacilitiesFixture` re-seeds a
    // matrix whose Community Super Admin still reverses, which is the point here.
    expect(splitHeld($csa))->toContain('estate.dues_ledger.reverse_runs', 'estate.dues_ledger.activate_plans');

    $taken = splitMigration()->narrowTheCommunitySuperAdmin('mysql');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $dues = NamedActs::replacements()['estate.dues_ledger.approve'];

    // The cell and the compiled table both: Full plus the one approval D-141 was for.
    expect($taken)->toBe(['estate.dues_ledger.reverse_runs', 'estate.dues_ledger.activate_plans', 'estate.dues_ledger.flag_units', 'estate.dues_ledger.adopt_dunning'])
        ->and([$cell->fresh()->level, $cell->fresh()->can_approve, $cell->fresh()->acts])->toBe([AccessLevel::Full, false, ['set_rates']])
        ->and(array_values(array_intersect(splitHeld($csa), $dues)))->toBe(['estate.dues_ledger.set_rates'])
        ->and(splitHeld($csa))->toBe(splitCompiled($csa));

    // NARROWING ONLY, and idempotent: a second run finds nothing to do, and a cell
    // that approves nothing is not given `set_rates` here.
    expect(splitMigration()->narrowTheCommunitySuperAdmin('mysql'))->toBe([]);

    // Fresh: the migration wrote the cell behind this model's back.
    $cell = $cell->fresh();
    $cell->forceFill(['can_approve' => false, 'acts' => null])->save();
    $csa->syncPermissions(splitCompiled($csa));

    expect(splitMigration()->narrowTheCommunitySuperAdmin('mysql'))->toBe([])
        ->and($cell->fresh()->acts)->toBeNull()
        ->and(splitHeld($csa))->not->toContain('estate.dues_ledger.set_rates');

    // THE DEPLOY'S RE-SEED (deploy-m51.sh, after the migrations) then writes the M55 cell: every approval, which the
    // Community Super Admin uses over HTTP on the very next request.
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect([$cell->fresh()->can_approve, $cell->fresh()->acts])->toBe([true, null])
        ->and(array_values(array_intersect(splitHeld($csa), $dues)))->toBe(collect($dues)->sort()->values()->all());

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    $this->actingAs($admin)->get(FacilitiesFixture::url('/finance/charge-schedule'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canSetRates', true)->where('canReverse', true));
});

it('records every estate\'s own role left wider than its administrator, and the copy that narrows one', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // THE ADMINISTRATOR AS M11 B3 LEFT IT, which is what this step of the migration ran against: Full on Dues & ledger
    // plus `set_rates` alone. Since M55 a re-seed gives it every approval, so no role can be wider than it any more.
    $csa = Role::named(Role::COMMUNITY_SUPER_ADMIN);
    RoleModuleAccess::query()->where('role_id', $csa->id)
        ->whereHas('module', static fn ($m) => $m->where('console', 'estate')->where('key', 'dues_ledger'))->sole()
        ->forceFill(['can_approve' => false, 'acts' => ['set_rates']])->save();
    $csa->syncPermissions(splitCompiled($csa));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // What the migration leaves a community that copied the Treasurer while D-141
    // was in force: the old Approver tag, all five dues acts, held by somebody.
    $copy = splitRole(Console::Estate, 'Split our own treasurer', [
        'dues_ledger' => ['level' => 'full', 'can_approve' => true],
        'accounting_posting' => ['level' => 'view'],
    ]);
    $holder = splitEstateHolder($copy);

    // And two that are not: a community role inside its administrator's powers, and
    // a Gemini role, which no estate administrator edits.
    $narrow = splitRole(Console::Estate, 'Split rates clerk', ['dues_ledger' => ['level' => 'view', 'acts' => ['set_rates']]]);
    $gemini = splitRole(Console::Gemini, 'Split gemini approver', ['platform_settings' => ['level' => 'full', 'can_approve' => true]]);

    $logged = DB::connection('mysql')->table('audit_log')->max('id') ?? 0;

    $recorded = collect(splitMigration()->recordRolesWiderThanTheirAdministrator('mysql'))->keyBy('role');

    $wider = ['estate.dues_ledger.activate_plans', 'estate.dues_ledger.adopt_dunning', 'estate.dues_ledger.flag_units', 'estate.dues_ledger.reverse_runs'];

    expect($recorded->has($copy->name))->toBeTrue()
        ->and($recorded[$copy->name])->toBe(['role' => $copy->name, 'tenant_id' => FacilitiesFixture::ESTATE, 'permissions' => $wider, 'holders' => 1])
        ->and($recorded->has($narrow->name))->toBeFalse()
        ->and($recorded->has($gemini->name))->toBeFalse();

    // ON THE AUDIT LOG, against its estate, by nobody signed in.
    $entry = DB::connection('mysql')->table('audit_log')->where('id', '>', $logged)
        ->where('action', 'role.wider_than_its_administrator')->where('entity_id', $copy->name)->sole();

    expect($entry->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->entity_type)->toBe('Role')
        ->and(json_decode((string) $entry->after, true))->toMatchArray(['permissions' => $wider, 'holders' => 1, 'label' => 'Split our own treasurer']);

    // WHY IT IS RECORDED: an administrator narrower than the role cannot narrow it in place…
    $admin = splitNarrowAdministrator();
    $editor = app(RoleEditor::class);

    expect(fn () => splitInEstate(fn () => $editor->setPermissions($admin, $copy, ['dues_ledger' => ['level' => 'full', 'acts' => ['set_rates']]])))
        ->toThrow(DomainException::class, 'so you cannot take it away from a role either');

    // …and THE WAY OUT D-150 NAMES works from the console: a copy made by the
    // administrator withholds, by name, the four approvals it does not hold, and
    // its holder can be moved onto it.
    $narrowed = splitInEstate(fn (): Role => $editor->copy($admin, $copy, 'Split our own treasurer, narrowed'));

    expect(array_values(array_intersect(splitHeld($narrowed), NamedActs::replacements()['estate.dues_ledger.approve'])))->toBe(['estate.dues_ledger.set_rates']);

    $assignment = EstateAssignment::query()->where('user_id', $holder->id)->where('tenant_id', FacilitiesFixture::ESTATE)->sole();

    $this->actingAs($admin)->post(FacilitiesFixture::url('/settings/users/'.$assignment->id), ['role' => $narrowed->name, 'status' => 'active'])
        ->assertSessionHasNoErrors();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($holder->fresh()->can('estate.dues_ledger.set_rates'))->toBeTrue()
        ->and($holder->fresh()->can('estate.dues_ledger.reverse_runs'))->toBeFalse();
});

it('retires a bundled permission that came back, on every re-seed, keeping what its direct holders could do', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    // A server where the previous release was put back and re-seeded: its seeder
    // re-created the bundled permissions and compiled roles onto them.
    $bundled = Permission::query()->create(['name' => 'gemini.billing_subscriptions.update', 'guard_name' => 'web']);
    Permission::query()->create(['name' => 'estate.dues_ledger.approve', 'guard_name' => 'web']);

    DB::connection('mysql')->table('role_has_permissions')->insert(['permission_id' => $bundled->id, 'role_id' => Role::named(Role::ACCOUNTANT)->id]);
    $holder = User::factory()->create();
    $holder->givePermissionTo('gemini.billing_subscriptions.update');

    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Gone, from the table and from every holder; the role holds what its cells
    // compile to; the account holds every act the permission became.
    expect(Permission::query()->whereIn('name', array_keys(NamedActs::replacements()))->exists())->toBeFalse()
        ->and(splitHeld(Role::ACCOUNTANT))->toBe(splitCompiled(Role::named(Role::ACCOUNTANT)))
        ->and($holder->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(collect(NamedActs::replacements()['gemini.billing_subscriptions.update'])->sort()->values()->all());

    // And the console gate, which fails on a bundled permission that exists, passes.
    expect(Artisan::call('gate:console'))->toBe(0);
});

/* ------------------------------------------------------------------ */
/* the role editor's guard rails, act by act                           */
/* ------------------------------------------------------------------ */

it('lets an estate administrator give its own role the approval it holds, one act on its own, and nothing it does not', function () {
    $admin = splitNarrowAdministrator();
    $editor = app(RoleEditor::class);

    $own = splitInEstate(fn (): Role => $editor->create($admin, 'Split rates officer', ['dues_ledger' => ['level' => 'view']]));

    // One act on its own: stored as the act, compiled to it and nothing else.
    splitInEstate(fn () => $editor->setPermissions($admin, $own, ['dues_ledger' => ['level' => 'view', 'acts' => ['set_rates']]]));

    $cell = RoleModuleAccess::query()->with('module')->where('role_id', $own->id)
        ->whereHas('module', static fn ($m) => $m->where('key', 'dues_ledger'))->sole();

    expect($cell->acts)->toBe(['set_rates'])
        ->and($cell->can_approve)->toBeFalse()
        ->and($cell->pillLabel())->toBe('View · Sets dues rates')
        ->and(array_values(array_filter(splitHeld($own), static fn (string $p): bool => str_starts_with($p, 'estate.dues_ledger.'))))
        ->toBe(['estate.dues_ledger.set_rates', 'estate.dues_ledger.view']);

    // GUARD RAIL 3, BY ACT: what the admin does not hold, it cannot give — by
    // name, by the Approver tag that is all five, or by the verb list.
    foreach ([
        ['level' => 'view', 'acts' => ['set_rates', 'reverse_runs']],
        ['level' => 'view', 'can_approve' => true],
        ['level' => 'custom', 'verbs' => ['view', 'approve']],
    ] as $cell) {
        expect(fn () => splitInEstate(fn () => $editor->setPermissions($admin, $own, ['dues_ledger' => $cell])))
            ->toThrow(DomainException::class, 'yourself, so you cannot give it to a role.');
    }

    expect(fn () => splitInEstate(fn () => $editor->setPermissions($admin, $own, ['dues_ledger' => ['level' => 'view', 'acts' => ['reverse_runs']]])))
        ->toThrow(DomainException::class, 'You do not hold Dues & ledger · Reverse posted charges yourself, so you cannot give it to a role.');

    // …nor take away an act it does not hold, from a role that has it.
    $cell = RoleModuleAccess::query()->where('role_id', $own->id)->whereHas('module', static fn ($m) => $m->where('key', 'dues_ledger'))->sole();
    $cell->forceFill(['acts' => ['set_rates', 'activate_plans']])->save();
    $own->syncPermissions(splitCompiled($own));
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(fn () => splitInEstate(fn () => $editor->setPermissions($admin, $own, ['dues_ledger' => ['level' => 'view', 'acts' => ['set_rates']]])))
        ->toThrow(DomainException::class, 'You do not hold Dues & ledger · Activate and rule payment plans yourself, so you cannot take it away from a role either.');

    // The screen offers only what the admin holds, act by act.
    $board = splitInEstate(fn (): array => $editor->board($admin));
    $module = collect($board['modules'])->firstWhere('key', 'dues_ledger');

    expect(array_column($module['acts'], 'key'))->toBe(DUES_ACTS)
        ->and($board['grantable']['dues_ledger']['set_rates'])->toBeTrue()
        ->and($board['grantable']['dues_ledger']['reverse_runs'])->toBeFalse()
        ->and($board['grantable']['dues_ledger']['approve'])->toBeFalse()
        ->and(collect($board['modules'])->firstWhere('key', 'security')['acts'])->toBe([]);
});

it('refuses an act that is not the module\'s, an act on a cell that cannot open the module, and a copy of the Property Manager any of them', function () {
    // An actor who holds every estate approval and may edit roles — made
    // directly, since no seeded estate role holds both.
    $super = splitRole(Console::Estate, 'Split super editor', [
        'settings' => ['level' => 'full', 'can_approve' => true],
        'dues_ledger' => ['level' => 'full', 'can_approve' => true],
        'accounting_posting' => ['level' => 'full', 'can_approve' => true],
        'payroll' => ['level' => 'full', 'can_approve' => true],
        'governance' => ['level' => 'full', 'can_approve' => true],
        'security' => ['level' => 'full'],
    ]);
    $actor = FacilitiesFixture::viewer($super->name);
    $editor = app(RoleEditor::class);

    $own = splitInEstate(fn (): Role => $editor->create($actor, 'Split target', []));

    $refused = [
        // Another module's act.
        [['dues_ledger' => ['level' => 'view', 'acts' => ['approve_bills']]], 'is not something a role can be given on Dues & ledger'],
        // A module with no acts at all.
        [['security' => ['level' => 'view', 'acts' => ['set_rates']]], 'which has no named acts'],
        // An act with nothing to open.
        [['dues_ledger' => ['level' => 'none', 'acts' => ['flag_units']]], 'at least view before giving it Hardship and dispute flags'],
        [['governance' => ['level' => 'custom', 'verbs' => ['export'], 'acts' => ['publish_meetings']]], 'at least view before giving it Publish meetings'],
        // …however it is granted: every act of a verb posted one by one is folded
        // into the verb, and the verb itself on a Custom list, are acts all the same.
        [['settings' => ['level' => 'none', 'acts' => ['manage_people', 'edit_profile']]], 'at least view before giving it Manage people, Edit the estate profile'],
        [['settings' => ['level' => 'custom', 'verbs' => ['update']]], 'at least view before giving it Manage people, Edit the estate profile'],
        [['settings' => ['level' => 'custom', 'verbs' => ['export', 'update'], 'acts' => ['manage_people']]], 'at least view before giving it Manage people, Edit the estate profile'],
        // All five dues approvals posted with nothing to open ARE the tag.
        [['dues_ledger' => ['level' => 'none', 'acts' => DUES_ACTS]], 'at least view before tagging it Approver'],
    ];

    foreach ($refused as [$grid, $sentence]) {
        expect(fn () => splitInEstate(fn () => $editor->setPermissions($actor, $own, $grid)))->toThrow(DomainException::class, $sentence);
    }

    // Refused, so nothing was written: the role still holds nothing at all.
    expect(splitHeld($own))->toBe([]);

    // ONE GRANT, ONE STORED FORM. Every act of a verb, one by one, is the verb;
    // an act the tag already grants is not listed twice.
    splitInEstate(fn () => $editor->setPermissions($actor, $own, [
        'dues_ledger' => ['level' => 'view', 'acts' => DUES_ACTS],
        'payroll' => ['level' => 'view', 'can_approve' => true, 'acts' => ['file_returns']],
        'governance' => ['level' => 'view', 'acts' => ['publish_meetings', 'certify_elections', 'publish_meetings']],
    ]));

    $cells = RoleModuleAccess::query()->with('module')->where('role_id', $own->id)->get()->keyBy(fn (RoleModuleAccess $c): string => $c->module->key);

    expect([$cells['dues_ledger']->can_approve, $cells['dues_ledger']->acts])->toBe([true, null])
        ->and([$cells['payroll']->can_approve, $cells['payroll']->acts])->toBe([true, null])
        ->and([$cells['governance']->can_approve, $cells['governance']->acts])->toBe([true, null])
        ->and($cells['dues_ledger']->pillLabel())->toBe('View · Approver');

    // A second save of the same grant, said the other way, changes nothing.
    $held = splitHeld($own);

    splitInEstate(fn () => $editor->setPermissions($actor, $own, ['dues_ledger' => ['level' => 'view', 'can_approve' => true]]));

    expect(splitHeld($own))->toBe($held);

    // RULING 1 follows a copy of the Property Manager, whatever the act.
    $copy = splitInEstate(fn (): Role => $editor->copy($actor, Role::named(Role::PROPERTY_MANAGER), 'Split PM copy'));

    expect(fn () => splitInEstate(fn () => $editor->setPermissions($actor, $copy, ['dues_ledger' => ['level' => 'view', 'acts' => ['set_rates']]])))
        ->toThrow(DomainException::class, 'closed to the Property Manager');
});

it('holds the Director to every configure act on Platform settings, and lets the Director give a role one of them', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $editor = app(RoleEditor::class);

    // THE STRUCTURAL MINIMUM, COMPILED ACT BY ACT: dropping any one configure act
    // from the Director's own cell is dropping Full · Approver.
    expect(fn () => $editor->setPermissions($director, Role::named(Role::DIRECTOR), [
        'platform_settings' => ['level' => 'custom', 'verbs' => ['view', 'create', 'update', 'delete', 'export'], 'can_approve' => true, 'acts' => ['set_prices', 'run_simulator']],
    ]))->toThrow(DomainException::class, 'Director is the role that can always change permissions');

    $board = $editor->board($director);

    expect($board['grantable']['platform_settings']['configure'])->toBeTrue()
        ->and($board['grantable']['platform_settings']['configure_messaging'])->toBeTrue()
        ->and(array_column(collect($board['modules'])->firstWhere('key', 'platform_settings')['acts'], 'key'))->toBe(['set_prices', 'configure_messaging', 'run_simulator']);

    $pricing = $editor->create($director, 'Split pricing clerk', ['platform_settings' => ['level' => 'view', 'acts' => ['set_prices']]]);

    expect(array_values(array_filter(splitHeld($pricing), static fn (string $p): bool => str_starts_with($p, 'gemini.platform_settings.'))))
        ->toBe(['gemini.platform_settings.set_prices', 'gemini.platform_settings.view']);

    /*
     * AN ACT NEEDS VIEW HOWEVER IT IS POSTED. One act alone was always refused;
     * all three, which the editor folds into Custom (configure), were stored and
     * compiled — the prices, the platform's mail and SMS relay and the simulator,
     * on a role that could not open the module. So is the verb posted as a verb,
     * on Platform settings and on Billing & subscriptions alike.
     */
    $allThree = 'at least view before giving it Set platform prices, Mail and SMS delivery, Run the event simulator';

    foreach ([
        [['platform_settings' => ['level' => 'none', 'acts' => ['set_prices']]], 'at least view before giving it Set platform prices.'],
        [['platform_settings' => ['level' => 'none', 'acts' => ['set_prices', 'configure_messaging', 'run_simulator']]], $allThree],
        [['platform_settings' => ['level' => 'custom', 'verbs' => ['configure']]], $allThree],
        [['billing_subscriptions' => ['level' => 'custom', 'verbs' => ['update', 'export']]], 'at least view before giving it Price clients, Raise invoices, Resend invoices, Issue credit notes'],
    ] as [$grid, $sentence]) {
        expect(fn () => $editor->setPermissions($director, $pricing, $grid))->toThrow(DomainException::class, $sentence);
    }

    expect(array_values(array_filter(splitHeld($pricing), static fn (string $p): bool => str_starts_with($p, 'gemini.platform_settings.'))))
        ->toBe(['gemini.platform_settings.set_prices', 'gemini.platform_settings.view'])
        ->and(array_filter(splitHeld($pricing), static fn (string $p): bool => str_starts_with($p, 'gemini.billing_subscriptions.')))->toBe([]);

    // A cell saved that way before this rule is not copied that way: the copy
    // leaves the three acts out, and says so, rather than being refused.
    $legacy = splitRole(Console::Gemini, 'Split legacy configurer', ['platform_settings' => ['level' => 'custom', 'verbs' => ['configure']]]);

    ['grid' => $grid, 'withheld' => $withheld] = $editor->copyableGrid($director, $legacy);

    expect($grid['platform_settings'])->toBe(['level' => 'none', 'verbs' => null, 'can_approve' => false, 'acts' => null])
        ->and($withheld)->toBe(['Platform settings · Set platform prices', 'Platform settings · Mail and SMS delivery', 'Platform settings · Run the event simulator']);

    $copy = $editor->copy($director, $legacy, 'Split legacy copy');

    expect(array_filter(splitHeld($copy), static fn (string $p): bool => str_starts_with($p, 'gemini.platform_settings.')))->toBe([]);
});

it('lets the Community Super Admin give a role the estate profile without the people, and stores both halves as the update they were', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $editor = app(RoleEditor::class);

    // A split verb that is not an approval: Settings' update is two acts, and
    // the Community Super Admin holds both.
    $board = splitInEstate(fn (): array => $editor->board($admin));

    expect(array_column(collect($board['modules'])->firstWhere('key', 'settings')['acts'], 'key'))->toBe(['manage_people', 'edit_profile'])
        ->and(array_column(collect($board['modules'])->firstWhere('key', 'settings')['acts'], 'verb'))->toBe(['update', 'update'])
        ->and($board['grantable']['settings']['update'])->toBeTrue()
        ->and($board['grantable']['settings']['manage_people'])->toBeTrue()
        ->and($board['grantable']['settings']['edit_profile'])->toBeTrue();

    $letterhead = splitInEstate(fn (): Role => $editor->create($admin, 'Split letterhead', [
        'settings' => ['level' => 'view', 'acts' => ['edit_profile']],
    ]));

    $cell = static fn (): RoleModuleAccess => RoleModuleAccess::query()->with('module')->where('role_id', $letterhead->id)
        ->whereHas('module', static fn ($m) => $m->where('key', 'settings'))->sole();

    expect($cell()->acts)->toBe(['edit_profile'])
        ->and($cell()->pillLabel())->toBe('View · Edits the profile')
        ->and(array_values(array_filter(splitHeld($letterhead), static fn (string $p): bool => str_starts_with($p, 'estate.settings.'))))
        ->toBe(['estate.settings.edit_profile', 'estate.settings.view']);

    // Both halves one by one ARE the verb, stored on the cell's verb list.
    splitInEstate(fn () => $editor->setPermissions($admin, $letterhead, [
        'settings' => ['level' => 'view', 'acts' => ['manage_people', 'edit_profile']],
    ]));

    expect([$cell()->level, $cell()->verbs, $cell()->acts])->toBe([AccessLevel::Custom, ['view', 'update'], null])
        ->and($cell()->pillLabel())->toBe('Custom (view, update)')
        ->and(array_values(array_filter(splitHeld($letterhead), static fn (string $p): bool => str_starts_with($p, 'estate.settings.'))))
        ->toBe(['estate.settings.edit_profile', 'estate.settings.manage_people', 'estate.settings.view']);

    // Unticking one half at Custom keeps the other on its own.
    splitInEstate(fn () => $editor->setPermissions($admin, $letterhead, [
        'settings' => ['level' => 'custom', 'verbs' => ['view'], 'acts' => ['manage_people']],
    ]));

    expect([$cell()->level, $cell()->acts])->toBe([AccessLevel::View, ['manage_people']])
        ->and(splitHeld($letterhead))->not->toContain('estate.settings.edit_profile');

    // And neither half reaches the power to change roles, which stays Settings' approval.
    expect(splitHeld($letterhead))->not->toContain('estate.settings.approve');
});

/* ------------------------------------------------------------------ */
/* over HTTP: each power refused without its own act, allowed with it  */
/* ------------------------------------------------------------------ */

it('lets each of the five dues approvals do its own act, and refuses it the other four', function () {
    foreach (SPLIT_LOTS as $lot) {
        FacilitiesFixture::scratchUnit($lot, SPLIT_BLOCK);
    }

    /** @var array<string, User> $by */
    $by = [];

    foreach (DUES_ACTS as $act) {
        $by[$act] = FacilitiesFixture::viewer(splitRole(Console::Estate, 'Split '.$act, [
            'dues_ledger' => ['level' => 'view', 'acts' => [$act]],
        ])->name);
    }

    $others = static fn (string $act): array => array_diff_key($by, [$act => true]);
    $url = static fn (string $path): string => FacilitiesFixture::url($path);

    // THE SCREEN SAYS WHICH: only the rates officer sees the rate controls live,
    // and only the reverser the reversal.
    foreach ($by as $act => $user) {
        $this->actingAs($user)
            ->get($url('/finance/charge-schedule'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canSetRates', $act === 'set_rates')
                ->where('canReverse', $act === 'reverse_runs'));
    }

    /* ---- set_rates: define a schedule ---- */
    $define = ['description' => SPLIT_SCHEDULE, 'amount' => '5000.00', 'scope' => 'phase', 'phase' => SPLIT_BLOCK, 'due_day' => 1, 'account_code' => '4000'];

    foreach ($others('set_rates') as $user) {
        $this->actingAs($user)->post($url('/finance/charge-schedule'), $define)->assertForbidden();
    }

    FacilitiesFixture::boot();
    expect(ChargeSchedule::query()->where('description', SPLIT_SCHEDULE)->exists())->toBeFalse();

    $this->actingAs($by['set_rates'])->post($url('/finance/charge-schedule'), $define)->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $schedule = ChargeSchedule::query()->where('description', SPLIT_SCHEDULE)->sole();

    /* ---- set_rates: change the rate from a month not yet billed ---- */
    $rate = ['amount' => '5500.00', 'effective_period' => '2026-10', 'reason' => 'Split test: the rate the AGM set from October.'];

    foreach ($others('set_rates') as $user) {
        $this->actingAs($user)->post($url('/finance/charge-schedule/'.$schedule->id.'/rate'), $rate)->assertForbidden();
    }

    FacilitiesFixture::boot();
    expect(DB::connection('tenant')->table('charge_schedule_rate_changes')->where('charge_schedule_id', $schedule->id)->count())->toBe(0);

    $this->actingAs($by['set_rates'])->post($url('/finance/charge-schedule/'.$schedule->id.'/rate'), $rate)->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect((int) DB::connection('tenant')->table('charge_schedule_rate_changes')->where('charge_schedule_id', $schedule->id)->value('to_minor'))->toBe(5_500_00);

    // September posts, by the office — `create`, which none of the five holds.
    $schedules = app(ChargeSchedules::class);
    $preview = $schedules->preview($schedule->fresh(), $schedules->nextPeriod($schedule->fresh()));

    $this->actingAs($by['set_rates'])
        ->post($url('/finance/charge-schedule/'.$schedule->id.'/runs'), ['period' => $preview['period'], 'expected_count' => $preview['count'], 'expected_total_minor' => $preview['total_minor']])
        ->assertForbidden();

    $run = $schedules->post($schedule->fresh(), $preview['period'], $preview['count'], $preview['total_minor'], $this->treasurer);

    expect($run->unit_count)->toBe(3);

    /* ---- activate_plans ---- */
    FacilitiesFixture::boot();
    $collections = app(Collections::class);
    $plan = $collections->draft(FacilitiesFixture::unit('Lot 964'), 2);
    $collections->agree($plan, 'Split Householder');

    foreach ($others('activate_plans') as $user) {
        $this->actingAs($user)->post($url('/finance/payment-plans/'.$plan->id.'/activate'), ['agreed_by_name' => 'Split Householder'])->assertForbidden();
    }

    FacilitiesFixture::boot();
    expect(PaymentPlan::query()->findOrFail($plan->id)->status)->toBe(PaymentPlan::DRAFT);

    $this->actingAs($by['activate_plans'])->post($url('/finance/payment-plans/'.$plan->id.'/activate'), ['agreed_by_name' => 'Split Householder'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(PaymentPlan::query()->findOrFail($plan->id)->status)->toBe(PaymentPlan::ACTIVE)
        ->and(PaymentPlan::query()->findOrFail($plan->id)->approved_by_name)->toBe($by['activate_plans']->name);

    /*
     * ---- activate_plans: rule each instalment met, then missed (bug hunt B019) ----
     * The same act as activating: each ruling decides whether the plan still
     * shields the household at the gate.
     */
    [$first, $second] = PaymentPlanInstalment::query()->where('payment_plan_id', $plan->id)->orderBy('sequence')->get()->all();

    foreach ($others('activate_plans') as $user) {
        $this->actingAs($user)->post($url('/finance/payment-plan-instalments/'.$first->id.'/met'))->assertForbidden();
    }

    FacilitiesFixture::boot();
    expect(PaymentPlanInstalment::query()->findOrFail($first->id)->status)->toBe(PaymentPlanInstalment::DUE);

    $this->actingAs($by['activate_plans'])->post($url('/finance/payment-plan-instalments/'.$first->id.'/met'))->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(PaymentPlanInstalment::query()->findOrFail($first->id)->status)->toBe(PaymentPlanInstalment::MET)
        ->and(PaymentPlan::query()->findOrFail($plan->id)->status)->toBe(PaymentPlan::ACTIVE);

    // An instalment is ruled missed only once its date has passed, so the
    // clock moves past the second one's, and back again afterwards.
    $this->travelTo($second->due_on->copy()->addDays(2)->setTime(10, 0));

    foreach ($others('activate_plans') as $user) {
        $this->actingAs($user)->post($url('/finance/payment-plan-instalments/'.$second->id.'/missed'))->assertForbidden();
    }

    FacilitiesFixture::boot();
    expect(PaymentPlanInstalment::query()->findOrFail($second->id)->status)->toBe(PaymentPlanInstalment::DUE)
        ->and(PaymentPlan::query()->findOrFail($plan->id)->status)->toBe(PaymentPlan::ACTIVE);

    $this->actingAs($by['activate_plans'])->post($url('/finance/payment-plan-instalments/'.$second->id.'/missed'))->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(PaymentPlanInstalment::query()->findOrFail($second->id)->status)->toBe(PaymentPlanInstalment::MISSED)
        ->and(PaymentPlan::query()->findOrFail($plan->id)->status)->toBe(PaymentPlan::DEFAULTED);

    $this->travelTo(Carbon::parse('2026-09-24 10:00:00'));

    /* ---- flag_units: raise, then lift ---- */
    $flagged = FacilitiesFixture::unit('Lot 965');
    $raise = ['kind' => 'hardship', 'reason' => 'Split test: lost employment, paying what they can.', 'minute_reference' => 'Min. 2026-09-20 §5'];

    foreach ($others('flag_units') as $user) {
        $this->actingAs($user)->post($url('/finance/units/'.$flagged->id.'/flag'), $raise)->assertForbidden();
    }

    FacilitiesFixture::boot();
    expect(UnitCollectionFlag::query()->where('unit_id', $flagged->id)->exists())->toBeFalse();

    $this->actingAs($by['flag_units'])->post($url('/finance/units/'.$flagged->id.'/flag'), $raise)->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $flag = UnitCollectionFlag::query()->where('unit_id', $flagged->id)->sole();
    $lift = ['lifted_reason' => 'Split test: back at work.', 'lifted_minute_reference' => 'Min. 2026-10-04 §2'];

    foreach ($others('flag_units') as $user) {
        $this->actingAs($user)->post($url('/finance/units/'.$flagged->id.'/flag/'.$flag->id.'/lift'), $lift)->assertForbidden();
    }

    $this->actingAs($by['flag_units'])->post($url('/finance/units/'.$flagged->id.'/flag/'.$flag->id.'/lift'), $lift)->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(UnitCollectionFlag::query()->findOrFail($flag->id)->isInForce())->toBeFalse();

    /* ---- adopt_dunning ---- */
    $draft = $collections->saveDraft([
        'label' => SPLIT_STEP,
        'stage' => 17,
        'channel' => 'email',
        'subject' => 'Your account, {unit_label}',
        'body' => 'Dear {resident_first_name}, please call the office about {unit_label}.',
        'days_overdue' => 200,
    ], $this->treasurer);

    foreach ($others('adopt_dunning') as $user) {
        $this->actingAs($user)->post($url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => 'Res. 2026-31'])->assertForbidden();
    }

    FacilitiesFixture::boot();
    expect(DunningTemplateDraft::query()->findOrFail($draft->id)->adopted_at)->toBeNull();

    $this->actingAs($by['adopt_dunning'])->post($url('/finance/dunning/drafts/'.$draft->id.'/adopt'), ['resolution_reference' => 'Res. 2026-31'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $adopted = DunningTemplateDraft::query()->findOrFail($draft->id);

    expect($adopted->adopted_at)->not->toBeNull()
        ->and(DunningTemplate::query()->findOrFail($adopted->dunning_template_id)->label)->toBe(SPLIT_STEP);

    /* ---- reverse_runs ---- */
    foreach ($others('reverse_runs') as $user) {
        $this->actingAs($user)->post($url('/finance/charge-runs/'.$run->id.'/reverse'), ['reason' => 'Split test: billed in error.'])->assertForbidden();
    }

    FacilitiesFixture::boot();
    expect(ChargeRun::query()->findOrFail($run->id)->reversed_at)->toBeNull();

    $this->actingAs($by['reverse_runs'])->post($url('/finance/charge-runs/'.$run->id.'/reverse'), ['reason' => 'Split test: billed in error.'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(ChargeRun::query()->findOrFail($run->id)->reversed_at)->not->toBeNull();

    /* ---- set_rates: stop the schedule ---- */
    foreach ($others('set_rates') as $user) {
        $this->actingAs($user)->post($url('/finance/charge-schedule/'.$schedule->id.'/stop'))->assertForbidden();
    }

    FacilitiesFixture::boot();
    expect(ChargeSchedule::query()->findOrFail($schedule->id)->is_active)->toBeTrue();

    $this->actingAs($by['set_rates'])->post($url('/finance/charge-schedule/'.$schedule->id.'/stop'))->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(ChargeSchedule::query()->findOrFail($schedule->id)->is_active)->toBeFalse();
});

it('lets the Community Super Admin reverse a posted month and activate a plan over HTTP since M55, and refuses a role that holds neither', function () {
    foreach (SPLIT_LOTS as $lot) {
        FacilitiesFixture::scratchUnit($lot, SPLIT_BLOCK);
    }

    $csa = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $schedules = app(ChargeSchedules::class);

    // The rate is the Community Super Admin's to set…
    $this->actingAs($csa)
        ->post(FacilitiesFixture::url('/finance/charge-schedule'), ['description' => SPLIT_SCHEDULE, 'amount' => '4000.00', 'scope' => 'phase', 'phase' => SPLIT_BLOCK, 'due_day' => 1, 'account_code' => '4000'])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $schedule = ChargeSchedule::query()->where('description', SPLIT_SCHEDULE)->sole();
    $preview = $schedules->preview($schedule, $schedules->nextPeriod($schedule));
    $run = $schedules->post($schedule, $preview['period'], $preview['count'], $preview['total_minor'], $this->treasurer);

    FacilitiesFixture::boot();
    $collections = app(Collections::class);
    $plan = $collections->draft(FacilitiesFixture::unit('Lot 966'), 2);
    $collections->agree($plan, 'Split Householder');

    // A role that reads Dues & ledger and holds neither act is refused both, and nothing moves.
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $this->actingAs($president)->post(FacilitiesFixture::url('/finance/charge-runs/'.$run->id.'/reverse'), ['reason' => 'Split test: billed in error.'])->assertForbidden();
    $this->actingAs($president)->post(FacilitiesFixture::url('/finance/payment-plans/'.$plan->id.'/activate'), ['agreed_by_name' => 'Split Householder'])->assertForbidden();

    FacilitiesFixture::boot();
    expect(ChargeRun::query()->findOrFail($run->id)->reversed_at)->toBeNull()
        ->and(PaymentPlan::query()->findOrFail($plan->id)->status)->toBe(PaymentPlan::DRAFT);

    // …and the Community Super Admin does both, as the Treasurer does.
    $this->actingAs($csa)->post(FacilitiesFixture::url('/finance/payment-plans/'.$plan->id.'/activate'), ['agreed_by_name' => 'Split Householder'])->assertSessionHasNoErrors();
    $this->actingAs($csa)->post(FacilitiesFixture::url('/finance/charge-runs/'.$run->id.'/reverse'), ['reason' => 'Split test: billed in error.'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(ChargeRun::query()->findOrFail($run->id)->reversed_at)->not->toBeNull()
        ->and(PaymentPlan::query()->findOrFail($plan->id)->status)->toBe(PaymentPlan::ACTIVE);
});

it('approves a bill only with Approve bills, and signs off a reconciliation only with Sign off reconciliations', function () {
    $bills = FacilitiesFixture::viewer(splitRole(Console::Estate, 'Split bills', ['accounting_posting' => ['level' => 'view', 'acts' => ['approve_bills']]])->name);
    $recon = FacilitiesFixture::viewer(splitRole(Console::Estate, 'Split reconciler', ['accounting_posting' => ['level' => 'view', 'acts' => ['complete_reconciliations']]])->name);

    FacilitiesFixture::boot();

    // A supplier of this file's own: the approved bill below stays on the append-only
    // ledger, and no other file's supplier should be owed it.
    $vendor = Vendor::query()->firstOrCreate(['name' => SPLIT_VENDOR], ['category' => 'Electrical contractor', 'status' => Vendor::ACTIVE]);

    $bill = app(Payables::class)->recordBill(
        $vendor,
        Money::of('1234.00', 'JMD'),
        'Split test: rewiring the gatehouse',
        Carbon::today()->addDays(14),
    );

    $this->actingAs($bills)->get(FacilitiesFixture::url('/accounting/bills'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canApprove', true));
    $this->actingAs($recon)->get(FacilitiesFixture::url('/accounting/bills'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canApprove', false));

    $this->actingAs($recon)->post(FacilitiesFixture::url('/accounting/bills/'.$bill->id.'/approve'))->assertForbidden();

    FacilitiesFixture::boot();
    expect(Bill::query()->findOrFail($bill->id)->status)->toBe(Bill::DRAFT);

    $this->actingAs($bills)->post(FacilitiesFixture::url('/accounting/bills/'.$bill->id.'/approve'))->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(Bill::query()->findOrFail($bill->id)->status)->toBe(Bill::APPROVED);

    // VOIDING A DRAFT (bug hunt B036) is the mirror of approving it, so it is
    // Approve bills too, and not the reconciliation sign-off.
    $mistake = app(Payables::class)->recordBill(
        $vendor,
        Money::of('99.00', 'JMD'),
        'Split test: recorded twice by mistake',
        Carbon::today()->addDays(14),
    );

    $this->actingAs($recon)->post(FacilitiesFixture::url('/accounting/bills/'.$mistake->id.'/void'), ['reason' => 'Split test: a duplicate.'])->assertForbidden();

    FacilitiesFixture::boot();
    expect(Bill::query()->findOrFail($mistake->id)->status)->toBe(Bill::DRAFT);

    $this->actingAs($bills)->post(FacilitiesFixture::url('/accounting/bills/'.$mistake->id.'/void'), ['reason' => 'Split test: a duplicate.'])->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(Bill::query()->findOrFail($mistake->id)->status)->toBe(Bill::VOID);

    // A statement with nothing on it and no movement: balanced, so it can be signed off.
    $statement = BankReconciliation::query()->create([
        'account_id' => Account::query()->where('code', '1000')->value('id'),
        'statement_date' => '2000-01-31',
        'opening_minor' => 0,
        'closing_minor' => 0,
        'currency' => 'JMD',
        'status' => BankReconciliation::OPEN,
    ]);

    $this->actingAs($bills)->post(FacilitiesFixture::url('/accounting/reconciliation/'.$statement->id.'/complete'))->assertForbidden();

    FacilitiesFixture::boot();
    expect(BankReconciliation::query()->findOrFail($statement->id)->status)->toBe(BankReconciliation::OPEN);

    $this->actingAs($recon)->post(FacilitiesFixture::url('/accounting/reconciliation/'.$statement->id.'/complete'))->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(BankReconciliation::query()->findOrFail($statement->id)->status)->toBe(BankReconciliation::COMPLETED);
});

it('files a return only with File statutory returns, and passes a pay run\'s approval gate only with Approve pay runs', function () {
    $runs = FacilitiesFixture::viewer(splitRole(Console::Estate, 'Split pay approver', ['payroll' => ['level' => 'view', 'acts' => ['approve_runs']]])->name);
    $returns = FacilitiesFixture::viewer(splitRole(Console::Estate, 'Split returns clerk', ['payroll' => ['level' => 'view', 'acts' => ['file_returns']]])->name);

    $this->actingAs($returns)->get(FacilitiesFixture::url('/payroll/filings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canFile', true));
    $this->actingAs($runs)->get(FacilitiesFixture::url('/payroll/filings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canFile', false)->where('reasons.file', fn (string $r) => str_contains($r, 'File statutory returns')));

    // A return with nothing to remit, so filing it posts no entry.
    FacilitiesFixture::boot();
    $filing = DB::connection('tenant')->table('statutory_filings')->insertGetId([
        'form_code' => 'S01', 'form_title' => 'Split test return', 'period_label' => 'January 2000',
        'period_start' => '2000-01-01', 'period_end' => '2000-01-31', 'due_on' => '2000-02-14',
        'status' => 'not_started', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($runs)->post(FacilitiesFixture::url('/payroll/filings/'.$filing.'/file'))->assertForbidden();

    FacilitiesFixture::boot();
    expect(DB::connection('tenant')->table('statutory_filings')->where('id', $filing)->value('status'))->toBe('not_started');

    $this->actingAs($returns)->post(FacilitiesFixture::url('/payroll/filings/'.$filing.'/file'))->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    expect(DB::connection('tenant')->table('statutory_filings')->where('id', $filing)->value('status'))->toBe('filed');

    /*
     * THE PAY RUN'S GATE. `{slug}` is resolved by the controller, not bound, so
     * the permission is asked first: the returns clerk is refused at the gate
     * (403), and the pay approver is let past it to be told there is no such run
     * (404). The full approval, by the seeded holder, is `EstatePayrollTest`'s.
     */
    $this->actingAs($returns)->post(FacilitiesFixture::url('/payroll/runs/split-test-run/approve'))->assertForbidden();
    $this->actingAs($runs)->post(FacilitiesFixture::url('/payroll/runs/split-test-run/approve'))->assertNotFound();
});

it('certifies an election only with Certify election results, and publishes a meeting only with Publish meetings', function () {
    $certifier = FacilitiesFixture::viewer(splitRole(Console::Estate, 'Split returning officer', ['governance' => ['level' => 'view', 'acts' => ['certify_elections']]])->name);
    $publisher = FacilitiesFixture::viewer(splitRole(Console::Estate, 'Split notices officer', ['governance' => ['level' => 'view', 'acts' => ['publish_meetings']]])->name);

    $this->actingAs($publisher)->get(FacilitiesFixture::url('/governance/meetings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canPublish', true));
    $this->actingAs($certifier)->get(FacilitiesFixture::url('/governance/meetings'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canPublish', false));

    FacilitiesFixture::boot();
    $tenant = DB::connection('tenant');
    $ballot = $tenant->table('ballots')->insertGetId(['year' => 2099, 'title' => 'Split test election', 'created_at' => now(), 'updated_at' => now()]);
    $meeting = $tenant->table('meetings')->insertGetId(['type' => 'agm', 'title' => 'Split test AGM', 'starts_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);

    // Each refused the other's act at the gate…
    $this->actingAs($publisher)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot.'/certify'))->assertForbidden();
    $this->actingAs($certifier)->post(FacilitiesFixture::url('/governance/meetings/'.$meeting.'/publish'))->assertForbidden();

    /*
     * …and let past it to its own, where the service answers on the merits: a
     * ballot still in draft is not certified, and an AGM tomorrow is inside its
     * notice period. The gate is what this proves; the full certification and
     * publication, by the President, are `EstateGovernanceTest`'s.
     */
    $this->actingAs($certifier)->post(FacilitiesFixture::url('/governance/ballots/'.$ballot.'/certify'))
        ->assertSessionHasErrors('outcome');

    expect((string) session('errors')?->first('outcome'))->toContain('cannot be certified');

    $this->actingAs($publisher)->post(FacilitiesFixture::url('/governance/meetings/'.$meeting.'/publish'))
        ->assertSessionHasErrors('starts_at');

    // The one line M56 §2.4 asked for: the period and the earliest day.
    expect((string) session('errors')?->first('starts_at'))->toContain("days' notice. Earliest ");

    FacilitiesFixture::boot();
    expect($tenant->table('ballots')->where('id', $ballot)->value('stage'))->toBe('draft')
        ->and($tenant->table('meetings')->where('id', $meeting)->value('published_at'))->toBeNull();
});

it('moves somebody between roles only with Manage people, and keeps the letterhead only with Edit the estate profile', function () {
    // The estate's senior officer, so the Secretary below is never the one
    // row nobody may manage (`Settings::assertNotOwner()`).
    FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    $people = FacilitiesFixture::viewer(splitRole(Console::Estate, 'Split committee clerk', [
        'settings' => ['level' => 'view', 'acts' => ['manage_people']],
    ])->name);
    $letterhead = FacilitiesFixture::viewer(splitRole(Console::Estate, 'Split letterhead keeper', [
        'settings' => ['level' => 'view', 'acts' => ['edit_profile']],
    ])->name);

    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $assignment = EstateAssignment::query()->where('user_id', $secretary->id)->where('tenant_id', FacilitiesFixture::ESTATE)->sole();
    $url = static fn (string $path): string => FacilitiesFixture::url($path);

    // THE SCREENS SAY WHICH, each naming the permission the reader lacks.
    $this->actingAs($people)->get($url('/settings/users'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', true));
    $this->actingAs($letterhead)->get($url('/settings/users'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManage', false)
            ->where('manageBlockedReason', fn (string $reason) => str_contains($reason, 'Manage people permission')));

    foreach (['/settings/profile', '/settings/notifications'] as $screen) {
        $this->actingAs($letterhead)->get($url($screen))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', true));
        $this->actingAs($people)->get($url($screen))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canEdit', false)
                ->where('blockedReason', fn (string $reason) => str_contains($reason, 'Edit the estate profile permission')));
    }

    // MANAGING A PERSON: refused to the letterhead at the gate, done by the clerk.
    $move = ['role' => Role::TREASURER, 'status' => 'active'];

    $this->actingAs($letterhead)->post($url('/settings/users/'.$assignment->id), $move)->assertForbidden();
    $this->actingAs($letterhead)->post($url('/settings/users/'.$assignment->id.'/remove'))->assertForbidden();

    expect($secretary->fresh()->hasRole(Role::SECRETARY))->toBeTrue()
        ->and($assignment->fresh()->is_active)->toBeTrue();

    $this->actingAs($people)->post($url('/settings/users/'.$assignment->id), $move)->assertSessionHasNoErrors();

    expect($secretary->fresh()->hasRole(Role::TREASURER))->toBeTrue();

    // TAKING THEM OFF AND BRINGING THEM BACK (M10 §4) are Manage people too:
    // the removal by the clerk, and its inverse refused to the letterhead at the
    // gate and done by the clerk.
    $this->actingAs($people)->post($url('/settings/users/'.$assignment->id.'/remove'))->assertSessionHasNoErrors();

    expect($assignment->fresh()->is_active)->toBeFalse()
        ->and($secretary->fresh()->status)->toBe('suspended');

    $this->actingAs($letterhead)->post($url('/settings/users/'.$assignment->id.'/reinstate'))->assertForbidden();

    expect($assignment->fresh()->is_active)->toBeFalse();

    $this->actingAs($people)->post($url('/settings/users/'.$assignment->id.'/reinstate'))->assertSessionHasNoErrors();

    expect($assignment->fresh()->is_active)->toBeTrue()
        ->and($secretary->fresh()->status)->toBe('active')
        ->and($secretary->fresh()->hasRole(Role::TREASURER))->toBeTrue();

    // THE LETTERHEAD: refused to the clerk at the gate, and let past it by its own
    // to be refused on its fields — an empty form, so the shared estate's profile
    // is never actually changed.
    foreach (['/settings/profile', '/settings/profile/logo', '/settings/notifications'] as $path) {
        $this->actingAs($people)->post($url($path), [])->assertForbidden();
        $this->actingAs($letterhead)->post($url($path), [])->assertSessionHasErrors();
    }

    /*
     * MANAGE PEOPLE DOES NOT REACH ITS HOLDER'S OWN ROW (PERM-01's first point,
     * closed). The clerk may not seat itself as Treasurer — every dues approval
     * with it — nor suspend itself, and the row says so before anybody presses.
     */
    $own = EstateAssignment::query()->where('user_id', $people->id)->where('tenant_id', FacilitiesFixture::ESTATE)->sole();

    foreach ([$move, ['role' => Role::COMMUNITY_SUPER_ADMIN, 'status' => 'active'], ['role' => $own->role->name, 'status' => 'suspended']] as $self) {
        $this->actingAs($people)->post($url('/settings/users/'.$own->id), $self)->assertSessionHasErrors('role');

        expect((string) session('errors')?->first('role'))->toBe(Settings::NOT_YOUR_OWN_ROLE);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($people->fresh()->hasRole(Role::TREASURER))->toBeFalse()
        ->and($people->fresh()->status)->toBe('active')
        ->and($people->fresh()->can('estate.dues_ledger.reverse_runs'))->toBeFalse();

    $this->actingAs($people)->get($url('/settings/users'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $people->id)['manage_refusal'] === Settings::NOT_YOUR_OWN_ROLE));
});

it('refuses anybody their own role, so a copy of the Property Manager given Manage people cannot seat itself as Treasurer', function () {
    // The estate's senior officer, so neither row below is the one nobody manages.
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $editor = app(RoleEditor::class);

    /*
     * THE REVIEWERS' CASE. Settings is not a locked module, so the Community Super
     * Admin may give a copy of the Property Manager Settings' Manage people. That
     * copy commissions work; were it able to seat itself as Treasurer, it could
     * then approve paying for it — Ruling 1's separation (D-010), undone through
     * the committee screen.
     */
    $pmCopy = splitInEstate(fn (): Role => $editor->copy($admin, Role::named(Role::PROPERTY_MANAGER), 'Split PM with people'));
    splitInEstate(fn () => $editor->setPermissions($admin, $pmCopy, ['settings' => ['level' => 'view', 'acts' => ['manage_people']]]));

    $manager = FacilitiesFixture::viewer($pmCopy->name);
    $own = EstateAssignment::query()->where('user_id', $manager->id)->where('tenant_id', FacilitiesFixture::ESTATE)->sole();

    $this->actingAs($manager)->post(FacilitiesFixture::url('/settings/users/'.$own->id), ['role' => Role::TREASURER, 'status' => 'active'])
        ->assertSessionHasErrors('role');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect((string) session('errors')?->first('role'))->toBe(Settings::NOT_YOUR_OWN_ROLE)
        ->and($manager->fresh()->hasRole($pmCopy->name))->toBeTrue()
        ->and($manager->fresh()->can('estate.dues_ledger.view'))->toBeFalse()
        ->and($own->fresh()->role_id)->toBe($pmCopy->id);

    // The power itself still works on everybody else.
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $theirs = EstateAssignment::query()->where('user_id', $secretary->id)->where('tenant_id', FacilitiesFixture::ESTATE)->sole();

    $this->actingAs($manager)->post(FacilitiesFixture::url('/settings/users/'.$theirs->id), ['role' => Role::VICE_PRESIDENT, 'status' => 'active'])
        ->assertSessionHasNoErrors();

    expect($secretary->fresh()->hasRole(Role::VICE_PRESIDENT))->toBeTrue();

    // And an administrator is held to it as well: another one steps them down.
    $adminRow = EstateAssignment::query()->where('user_id', $admin->id)->where('tenant_id', FacilitiesFixture::ESTATE)->sole();

    $this->actingAs($admin)->post(FacilitiesFixture::url('/settings/users/'.$adminRow->id), ['role' => Role::SECRETARY, 'status' => 'active'])
        ->assertSessionHasErrors('role');

    expect((string) session('errors')?->first('role'))->toBe(Settings::NOT_YOUR_OWN_ROLE)
        ->and($admin->fresh()->hasRole(Role::COMMUNITY_SUPER_ADMIN))->toBeTrue();
});

it('lets the prices, the mail and SMS relay and the simulator each be given to a Gemini role on its own', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $one = static fn (string $act): User => splitGeminiUser(splitRole(Console::Gemini, 'Split '.$act, [
        'platform_settings' => ['level' => 'view', 'acts' => [$act]],
    ]));

    $prices = $one('set_prices');
    $messaging = $one('configure_messaging');
    $simulator = $one('run_simulator');

    // Each screen offers its write to the one role that holds it.
    $this->actingAs($prices)->get('/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canWrite', true));
    $this->actingAs($messaging)->get('/settings')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canWrite', false));
    $this->actingAs($messaging)->get('/settings/email')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canConfigure', true));
    $this->actingAs($prices)->get('/settings/sms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canConfigure', false));

    // Refused at the gate by every role but its own…
    foreach ([$messaging, $simulator] as $user) {
        $this->actingAs($user)->post('/settings/prices', [])->assertForbidden();
        $this->actingAs($user)->post('/settings/rates', [])->assertForbidden();
    }

    foreach ([$prices, $simulator] as $user) {
        $this->actingAs($user)->post('/settings/email', [])->assertForbidden();
        $this->actingAs($user)->post('/settings/sms', [])->assertForbidden();
    }

    foreach ([$prices, $messaging] as $user) {
        $this->actingAs($user)->get('/simulator')->assertForbidden();
    }

    // …and let past it by its own: an empty form is refused on its fields, not its role.
    $this->actingAs($prices)->post('/settings/prices', [])->assertSessionHasErrors();
    $this->actingAs($messaging)->post('/settings/email', [])->assertSessionHasErrors();
    $this->actingAs($messaging)->post('/settings/sms', [])->assertSessionHasErrors();
    $this->actingAs($simulator)->get('/simulator')->assertOk();
});

it('lets pricing a client, raising, resending and crediting an invoice each be given to a Gemini role on its own', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // A client of this test's own, on a tier, inside the central transaction.
    $client = 'splitbillingtest';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $client, 'name' => 'Split Billing Test Estate', 'receipt_prefix' => 'SBT', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'key' => 'splitbillingtest', 'name' => 'Split Billing Test', 'price_per_unit_minor' => 250_00,
        'currency' => 'JMD', 'min_units' => 1, 'is_active' => true, 'sort' => 98,
    ]);

    Subscription::query()->create([
        'tenant_id' => $client, 'plan_id' => $plan->id, 'unit_count' => 20, 'contracted_guards' => 0,
        'status' => 'active', 'started_on' => now()->startOfMonth()->toDateString(),
    ]);

    // Each role reads Billing and holds Clients in full, so the one thing that
    // tells them apart is the act.
    $one = static fn (string $act): User => splitGeminiUser(splitRole(Console::Gemini, 'Split '.$act, [
        'billing_subscriptions' => ['level' => 'view', 'acts' => [$act]],
        'clients' => ['level' => 'full'],
    ]));

    $by = [];

    foreach (NamedActs::under(Console::Gemini, 'billing_subscriptions', PermissionVerb::Update) as $act) {
        $by[$act] = $one($act);
    }

    $others = static fn (string $act): array => array_diff_key($by, [$act => true]);

    // THE SCREENS SAY WHICH, each naming the permission its reader lacks.
    foreach ($by as $act => $user) {
        $this->actingAs($user)->get('/billing/preview/'.$client)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canRaise', $act === 'raise_invoices')
                ->where('raiseDisabledReason', fn (string $reason) => str_contains($reason, 'Raise invoices permission')));
    }

    $invoice = app(InvoiceRun::class)->raise($client, FacilitiesFixture::geminiViewer(Role::DIRECTOR));

    foreach ($by as $act => $user) {
        $this->actingAs($user)->get('/billing/invoices/'.$invoice->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canResend', $act === 'resend_invoices')
                ->where('canCredit', $act === 'issue_credit_notes')
                ->where('resendDisabledReason', fn (string $reason) => str_contains($reason, 'Resend invoices permission'))
                ->where('creditDisabledReason', fn (string $reason) => str_contains($reason, 'Issue credit notes permission')));
    }

    // REFUSED AT THE GATE BY EVERY ROLE BUT ITS OWN…
    foreach ($others('price_clients') as $user) {
        $this->actingAs($user)->get('/clients/'.FacilitiesFixture::ESTATE.'/plan')->assertForbidden();
        $this->actingAs($user)->post('/clients/'.FacilitiesFixture::ESTATE.'/plan', [])->assertForbidden();
    }

    foreach ($others('raise_invoices') as $user) {
        $this->actingAs($user)->post('/billing/preview/'.$client.'/raise', [])->assertForbidden();
    }

    foreach ($others('resend_invoices') as $user) {
        $this->actingAs($user)->post('/billing/invoices/'.$invoice->id.'/resend')->assertForbidden();
    }

    foreach ($others('issue_credit_notes') as $user) {
        $this->actingAs($user)->post('/billing/invoices/'.$invoice->id.'/credit-note', ['amount' => '1.00', 'reason' => 'Split test'])->assertForbidden();
    }

    expect(DB::connection('mysql')->table('invoice_sends')->where('invoice_id', $invoice->id)->exists())->toBeFalse()
        ->and(DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->exists())->toBeFalse();

    // …AND LET PAST IT BY ITS OWN, to be answered on the merits: the plan screen
    // opens, an empty plan or raise is refused on its fields, the estate has nobody
    // who may read its invoice so the send is refused in words, and the credit note
    // is issued.
    $this->actingAs($by['price_clients'])->get('/clients/'.FacilitiesFixture::ESTATE.'/plan')->assertOk();
    $this->actingAs($by['price_clients'])->post('/clients/'.FacilitiesFixture::ESTATE.'/plan', [])->assertSessionHasErrors();
    $this->actingAs($by['raise_invoices'])->post('/billing/preview/'.$client.'/raise', [])->assertSessionHasErrors('invoice');
    $this->actingAs($by['resend_invoices'])->post('/billing/invoices/'.$invoice->id.'/resend')->assertSessionHasErrors('invoice');
    $this->actingAs($by['issue_credit_notes'])->post('/billing/invoices/'.$invoice->id.'/credit-note', ['amount' => '1.00', 'reason' => 'Split test: one unit billed twice.'])
        ->assertSessionHasNoErrors();

    expect(DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->count())->toBe(1);
});

/* ------------------------------------------------------------------ */
/* the console gate                                                    */
/* ------------------------------------------------------------------ */

it('still passes the console gate', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    $exit = Artisan::call('gate:console');
    $output = Artisan::output();

    expect($output)->toContain('GATE PASSED')
        ->and($output)->not->toContain('FAIL')
        ->and($exit)->toBe(0);

    // And the gate itself now holds both Gemini splits: each bundled verb gone,
    // the Director on every act, the Dispatcher on none.
    expect($output)->toContain('gemini.platform_settings.configure is not a permission any more - it is 3 acts')
        ->and($output)->toContain('gemini.billing_subscriptions.update is not a permission any more - it is 4 acts');

    foreach (['billing_subscriptions', 'platform_settings'] as $module) {
        foreach (NamedActs::of(Console::Gemini, $module) as $act) {
            expect($output)->toContain('CONTROL - the Director holds '.$act['permission'])
                ->and($output)->toContain('Dispatcher is denied '.$act['permission']);
        }
    }
});

it('fails the console gate when the bundled Platform settings permission comes back', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    // A server where the migration never ran, or something re-created the verb.
    Permission::query()->create(['name' => 'gemini.platform_settings.configure', 'guard_name' => 'web']);

    $exit = Artisan::call('gate:console');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('FAIL gemini.platform_settings.configure is not a permission any more')
        ->and($output)->toContain('GATE FAILED');
});
