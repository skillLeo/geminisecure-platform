<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Services\Rbac\RoleEditor;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The estate's two money reports ask for their money module — M11 B3's audit, D-152
|--------------------------------------------------------------------------
|
| `estate.reports.export` runs every report on board 29: the maintenance summary,
| the incident log, turnout and the minutes archive, AND Profit & Loss — the
| estate's own books — and Arrears Ageing, every household's balance side by
| side. So one permission carried two powers with different consequences, and
| the second had an owner already: `Dues::maySeeEstatePosition()`, M10 §5's rule
| for reading the estate's money about itself.
|
| Since M10 §4 a community makes its own roles, so a role with Reports and no
| money module is one save away — and a copy of the Property Manager given
| Reports would read what every resident owes, which Ruling 1 (D-010) exists to
| forbid. The two reports now ask the rule of the module whose figures they are.
|
| M11 B3's permission audit found it; bug hunt B137 closed it first, for every
| report and not only these two (`Reports::refusalFor()`, the card's
| `withheld`). This file holds the audit's finding against that fix: the money
| two ask for their own money module, each for its own, and a reader who holds
| every other module a report reads still runs the rest.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->period = '?from=2026-01-01&to=2026-12-31';
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A role of this estate's own, made directly with exactly the cells given and
 * compiled the one way cells compile.
 *
 * @param  array<string, array{level: string, verbs?: list<string>}>  $cells
 */
function moneyReportsRole(array $cells): Role
{
    $role = new Role;
    $role->forceFill([
        'name' => 'estate.custom.'.FacilitiesFixture::ESTATE.'.money_reports_'.Str::lower(Str::random(10)),
        'guard_name' => 'web',
        'console' => Console::Estate->value,
        'tenant_id' => FacilitiesFixture::ESTATE,
        'is_system' => false,
        'label' => 'Money reports '.Str::random(6),
        'sort' => 991,
        'scope_default' => AccessScope::All->value,
    ])->save();

    foreach ($cells as $key => $cell) {
        RoleModuleAccess::query()->create([
            'role_id' => $role->id,
            'module_id' => Module::query()->where('console', Console::Estate->value)->where('key', $key)->firstOrFail()->id,
            'level' => $cell['level'],
            'verbs' => $cell['verbs'] ?? null,
            'can_approve' => false,
            'scope' => AccessScope::All->value,
        ]);
    }

    $role->syncPermissions(
        RoleModuleAccess::query()->with('module')->where('role_id', $role->id)->get()
            ->flatMap(static fn (RoleModuleAccess $cell): array => $cell->permissionNames())
            ->unique()->values()->all()
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $role->fresh();
}

/** @return array<string, array<string, mixed>> the catalogue's cards, keyed by report */
function moneyReportsCards(object $test, $viewer): array
{
    $cards = [];

    $test->actingAs($viewer)
        ->get(FacilitiesFixture::url('/reports'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$cards) {
            foreach ($page->toArray()['props']['groups'] as $group) {
                foreach ($group['cards'] as $card) {
                    $cards[$card['key']] = $card;
                }
            }
        });

    return $cards;
}

it('refuses the books and the ageing to a role with Reports and no money module, and runs everything else for it', function () {
    // Every module the other four reports read (B137), and no money module.
    $clerk = FacilitiesFixture::viewer(moneyReportsRole([
        'reports' => ['level' => 'full'],
        'facilities' => ['level' => 'view'],
        'security' => ['level' => 'view'],
        'governance' => ['level' => 'view'],
    ])->name);

    // The route's own gate admits this reader: what refuses the two below is
    // the money rule, and nothing else.
    expect($clerk->can('estate.reports.export'))->toBeTrue()
        ->and($clerk->can('estate.dues_ledger.view'))->toBeFalse()
        ->and($clerk->can('estate.accounting_posting.view'))->toBeFalse();

    $cards = moneyReportsCards($this, $clerk);

    // THE CATALOGUE SAYS WHY, on the two cards, naming the module and no figure.
    expect($cards['profit_and_loss']['withheld'])->toContain('view access on Accounting')
        ->and($cards['arrears_ageing']['withheld'])->toContain('view access on Dues & ledger')
        ->and($cards['maintenance_summary']['withheld'])->toBeNull()
        ->and($cards['security_incidents']['withheld'])->toBeNull()
        ->and($cards['election_turnout']['withheld'])->toBeNull()
        ->and($cards['meeting_minutes']['withheld'])->toBeNull();

    $exports = DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count();

    // THE ROUTES REFUSE, on the screen and on the file alike.
    foreach (['profit_and_loss', 'arrears_ageing'] as $key) {
        $this->actingAs($clerk)->get(FacilitiesFixture::url('/reports/'.$key).$this->period)->assertForbidden();
        $this->actingAs($clerk)->get(FacilitiesFixture::url('/reports/'.$key.'/export').$this->period)->assertForbidden();
    }

    // No file left, so no export was recorded as taken.
    expect(DB::connection('mysql')->table('audit_log')->where('action', 'export.taken')->count())->toBe($exports);

    // What carries no money is still this reader's, screen and file.
    foreach (['maintenance_summary', 'security_incidents', 'election_turnout', 'meeting_minutes'] as $key) {
        $this->actingAs($clerk)->get(FacilitiesFixture::url('/reports/'.$key).$this->period)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Reports/Show')->where('key', $key));
    }

    $this->actingAs($clerk)->get(FacilitiesFixture::url('/reports/maintenance_summary/export').$this->period)->assertOk();
});

it('asks each money report for its own module, not for either', function () {
    $ledger = FacilitiesFixture::viewer(moneyReportsRole([
        'reports' => ['level' => 'full'],
        'dues_ledger' => ['level' => 'view'],
    ])->name);

    $books = FacilitiesFixture::viewer(moneyReportsRole([
        'reports' => ['level' => 'full'],
        'accounting_posting' => ['level' => 'view'],
    ])->name);

    $this->actingAs($ledger)->get(FacilitiesFixture::url('/reports/arrears_ageing').$this->period)->assertOk();
    $this->actingAs($ledger)->get(FacilitiesFixture::url('/reports/profit_and_loss').$this->period)->assertForbidden();

    $this->actingAs($books)->get(FacilitiesFixture::url('/reports/profit_and_loss').$this->period)->assertOk();
    $this->actingAs($books)->get(FacilitiesFixture::url('/reports/arrears_ageing').$this->period)->assertForbidden();

    expect(moneyReportsCards($this, $ledger)['arrears_ageing']['withheld'])->toBeNull()
        ->and(moneyReportsCards($this, $ledger)['profit_and_loss']['withheld'])->toContain('Accounting')
        ->and(moneyReportsCards($this, $books)['profit_and_loss']['withheld'])->toBeNull()
        ->and(moneyReportsCards($this, $books)['arrears_ageing']['withheld'])->toContain('Dues & ledger');
});

it('keeps a copy of the Property Manager out of every household\'s balance even when the estate gives it Reports', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $editor = app(RoleEditor::class);

    // The real path: the estate's own administrator copies the Property Manager
    // and gives the copy Full on Reports — a module Ruling 1 does not lock.
    FacilitiesFixture::boot();

    $copy = FacilitiesFixture::platform()->run(function () use ($editor, $admin): Role {
        $copy = $editor->copy($admin, Role::named(Role::PROPERTY_MANAGER), 'Money reports PM copy');

        return $editor->setPermissions($admin, $copy, ['reports' => ['level' => 'full']]);
    });

    tenancy()->end();
    FacilitiesFixture::boot();

    $manager = FacilitiesFixture::viewer($copy->name);

    expect($manager->can('estate.reports.export'))->toBeTrue()
        ->and($manager->can('estate.dues_ledger.view'))->toBeFalse()
        ->and($manager->can('estate.accounting_posting.view'))->toBeFalse();

    foreach (['arrears_ageing', 'profit_and_loss'] as $key) {
        $this->actingAs($manager)->get(FacilitiesFixture::url('/reports/'.$key).$this->period)->assertForbidden();
        $this->actingAs($manager)->get(FacilitiesFixture::url('/reports/'.$key.'/export').$this->period)->assertForbidden();
    }

    $this->actingAs($manager)->get(FacilitiesFixture::url('/reports/maintenance_summary').$this->period)->assertOk();
});

it('leaves both reports with every seeded role that could run them before', function () {
    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::VICE_PRESIDENT, Role::TREASURER] as $role) {
        $viewer = FacilitiesFixture::viewer($role);

        foreach (['profit_and_loss', 'arrears_ageing'] as $key) {
            $this->actingAs($viewer)->get(FacilitiesFixture::url('/reports/'.$key).$this->period)->assertOk();
        }

        // Neither money card is withheld from them. (Other cards may be: B137
        // withholds the incident log from the Treasurer, who holds no Security.)
        $cards = moneyReportsCards($this, $viewer);

        expect($cards['profit_and_loss']['withheld'])->toBeNull($role)
            ->and($cards['arrears_ageing']['withheld'])->toBeNull($role);
    }

    // The Secretary and the Property Manager hold View on Reports and generate
    // nothing at all, exactly as before: the Reports gate refuses them first.
    foreach ([Role::SECRETARY, Role::PROPERTY_MANAGER] as $role) {
        $this->actingAs(FacilitiesFixture::viewer($role))
            ->get(FacilitiesFixture::url('/reports/arrears_ageing').$this->period)
            ->assertForbidden();
    }
});
