<?php

declare(strict_types=1);

use App\Console\Commands\GateTenantIsolation;
use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Estate\Settings;
use App\Services\Gemini\PlatformAdmins;
use App\Services\Invitations;
use App\Services\Rbac\CrossTenantRoleProbe;
use App\Services\Rbac\RoleEditor;
use App\Support\EstateNavigation;
use Database\Seeders\Estate\SettingsSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Roles and permissions are dynamic, and the guard rails hold (M10 §4)
|--------------------------------------------------------------------------
|
| The client asked for the Director and the Community Super Admin to create,
| rename, re-grant and delete roles, and named five things that must not
| bend. This file is where each of them stops being a claim:
|
|   - nobody grants a permission they do not hold, approve included
|   - nothing crosses a tenant or a console: a role made in one estate is
|     invisible and unassignable in another, and no estate role can reach a
|     Gemini module — the security company's payroll among them
|   - the last Director and the last Community Super Admin cannot be removed,
|     demoted or stripped, and the refusal says why
|   - every create, change and delete writes an audit row with actor, role,
|     before and after
|   - a change takes effect on the very next request, with nobody clearing a
|     cache by hand — the stale-cache lockout the client named
|
| And that the seeded roles keep working through a deploy: a re-seed puts back
| every seeded role nobody changed, keeps a seeded Gemini role the Director
| changed exactly as they left it (while still giving it any new module), never
| lets an estate role opt out of the grid, and leaves a community's own roles
| alone. Resetting, from the console or with `rbac:reset-role`, is the one
| deliberate way back to the default, and it is audited.
|
| TWO ESTATES OF THIS FILE'S OWN, because half of what is under test is that
| one cannot see the other's roles. Their databases are named from the
| configured tenancy prefix, as every fixture's now is, so two copies of the
| suite never drop each other's. Everything central a test writes is rolled
| back after it; the estates themselves are provisioned once per process.
|
*/

const DYNAMIC_ESTATE_A = 'rolesdynamica';
const DYNAMIC_ESTATE_B = 'rolesdynamicb';

/**
 * The matrix, seeded on the NEWEST CELL rather than on a role existing — the
 * rule `FacilitiesFixture` learned. A database seeded before M10 §4 has every
 * role already, and without this the Community Super Admin would be a version
 * behind and refused everything below for a reason that is not the one under
 * test.
 */
function dynamicPlatform(): void
{
    $current = Role::query()
        ->where('name', Role::COMMUNITY_SUPER_ADMIN)
        ->where('is_system', true)
        ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.settings.approve'))
        ->exists();

    if (! $current) {
        Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    }
}

/** One of this file's two estates, provisioned once per process. */
function dynamicEstate(string $key): Tenant
{
    static $provisioned = [];

    if (isset($provisioned[$key])) {
        return Tenant::findOrFail($key);
    }

    dynamicPlatform();

    $database = config('tenancy.database.prefix').$key;
    $owner = DB::connection('mysql_owner');

    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::connection('mysql')->table('domains')->where('tenant_id', $key)->delete();
    DB::connection('mysql')->table('tenants')->where('id', $key)->delete();

    // Written raw, without the provisioning pipeline, for the reason
    // EstateSettingsTest gives: nothing under test here is a MySQL grant.
    DB::connection('mysql')->table('tenants')->insert([
        'id' => $key,
        'name' => $key === DYNAMIC_ESTATE_A ? 'Roles Estate A' : 'Roles Estate B',
        'receipt_prefix' => $key === DYNAMIC_ESTATE_A ? 'RDA' : 'RDB',
        'address_line' => 'Hope Road',
        'parish' => 'St. Andrew',
        'gate_count' => 1,
        'phases' => json_encode(['Phase 1']),
        'status' => 'active',
        'provisioned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('mysql')->table('domains')->insert([
        'domain' => $key,
        'tenant_id' => $key,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('tenants:migrate', ['--tenants' => [$key]]);

    $tenant = Tenant::findOrFail($key);

    $tenant->run(static function (): void {
        Artisan::call('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);
    });

    tenancy()->end();

    $provisioned[$key] = true;

    return $tenant;
}

/** Runs a closure inside one of the two estates, and always leaves it. */
function inDynamic(string $key, callable $work): mixed
{
    try {
        return dynamicEstate($key)->run($work);
    } finally {
        tenancy()->end();
    }
}

/**
 * A user of one estate holding exactly one role — seeded or custom — with the
 * assignment that lets them reach it. `$who` tells two holders of one role
 * apart.
 */
function dynamicUser(string|Role $role, string $estate, string $who = 'a'): User
{
    $role = $role instanceof Role ? $role : Role::named($role);

    $email = str_replace('.', '-', $role->name).'-'.$who.'@'.$estate.'.test';

    $user = User::query()->firstOrNew(['email' => $email]);

    $user->fill([
        'name' => ucwords(str_replace(['estate.', 'custom.', '.', '_'], ['', '', ' ', ' '], $role->name)).' '.strtoupper($who),
        'password' => 'password',
        'console' => Console::Estate->value,
        'status' => 'active',
    ]);

    /*
     * WITH A PROVEN ALERT ADDRESS (M10 §1.4), the way EstateSettingsTest's
     * fixture does it. Every estate account meets the contact gate until it has
     * one, and this file is about roles, not about the gate, which
     * ContactVerificationGateTest covers. Both columns in one save, so the model
     * keeps the proof rather than clearing it as a change of address.
     */
    $user->forceFill([
        'contact_email' => $email,
        'contact_email_verified_at' => now(),
    ])->save();

    $user->syncRoles([$role->name]);

    EstateAssignment::updateOrCreate(
        ['user_id' => $user->id, 'tenant_id' => $estate],
        ['role_id' => $role->id, 'is_active' => true],
    );

    return $user->fresh();
}

/** A Gemini Console account holding one role. */
function dynamicGeminiUser(string|Role $role): User
{
    $role = $role instanceof Role ? $role : Role::named($role);

    $user = User::factory()->geminiStaff()->create();
    $user->syncRoles([$role->name]);

    return $user->fresh();
}

function dynamicUrl(string $estate, string $path): string
{
    return 'http://'.$estate.'.'.config('app.estate_domain').$path;
}

/** @return list<string> */
function compiledPermissions(Role $role): array
{
    return $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
}

function editor(): RoleEditor
{
    return app(RoleEditor::class);
}

/**
 * One role's label, sort, scope, every cell and its compiled permissions, as
 * one string two moments can be compared by.
 */
function roleFingerprint(string|Role $role): string
{
    $id = $role instanceof Role ? $role->id : Role::named($role)->id;
    $role = Role::query()->with(['moduleAccess.module', 'permissions'])->findOrFail($id);

    return $role->label.'|'.$role->sort.'|'.$role->scope_default->value.'|'
        .$role->moduleAccess->sortBy('module_id')
            ->map(static fn (RoleModuleAccess $c): string => $c->module->key.':'.$c->level->value.':'.json_encode($c->verbs).':'.(int) $c->can_approve.':'.$c->scope->value)
            ->implode(',')
        .'|'.$role->permissions->pluck('name')->sort()->implode(',');
}

/** @return array<string, string> every seeded role's fingerprint, by name */
function seededFingerprints(): array
{
    return Role::query()->where('is_system', true)->orderBy('name')->pluck('name')
        ->mapWithKeys(static fn (string $name): array => [$name => roleFingerprint($name)])
        ->all();
}

/**
 * The real matrix seeder with its Gemini grid changed: modules appended and
 * rows replaced, the way a future release would change it. A subclass rather
 * than an edit to the constants, so the test shows what a DEPLOY does to a role
 * the Director changed.
 *
 * @param  list<array{0: string, 1: string, 2: string|null, 3: int, 4: bool}>  $extraModules
 * @param  array<string, array<int, string>>  $rows
 */
function changedMatrix(array $extraModules = [], array $rows = []): RbacMatrixSeeder
{
    return new class($extraModules, $rows) extends RbacMatrixSeeder
    {
        /**
         * @param  list<array{0: string, 1: string, 2: string|null, 3: int, 4: bool}>  $extraModules
         * @param  array<string, array<int, string>>  $rows
         */
        public function __construct(private readonly array $extraModules, private readonly array $rows) {}

        protected function geminiModules(): array
        {
            return [...parent::geminiModules(), ...$this->extraModules];
        }

        protected function geminiGrid(): array
        {
            return array_replace(parent::geminiGrid(), $this->rows);
        }
    };
}

/**
 * The isolation probe's one authenticated request, through this test's HTTP
 * kernel. It answers the redirect's Location as well as the status, because
 * that is how the probe tells a refusal from the contact gate.
 */
function probeRequest(BaseTestCase $test): Closure
{
    return static function (User $user, Tenant $tenant, string $method, string $path, array $data) use ($test): array {
        $response = $test->actingAs($user)->call($method, dynamicUrl((string) $tenant->getTenantKey(), '/'.$path), $data);

        return ['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location')];
    };
}

/** The Director's change to the Dispatcher every re-seed test starts from. */
function editTheDispatcher(User $director): Role
{
    return editor()->setPermissions($director, Role::named(Role::DISPATCHER), [
        'cross_tenant_reports' => ['level' => 'view'],
        'access_audit_log' => ['level' => 'custom', 'verbs' => ['view', 'export']],
    ]);
}

beforeEach(function () {
    dynamicEstate(DYNAMIC_ESTATE_A);
    dynamicEstate(DYNAMIC_ESTATE_B);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    // The rolled-back roles must not live on in the in-memory cache.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/* ------------------------------------------------------------------ */
/* guard rail 1 — nobody grants what they do not hold                 */
/* ------------------------------------------------------------------ */

it('refuses to let a Community Super Admin grant an approve they do not hold, however the request is shaped', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $before = Role::query()->count();

    /*
     * The clean case: the Community Super Admin holds Security at Full with no
     * Approver tag (the seeded grid's security row), so `estate.security.approve`
     * is exactly one permission they cannot give.
     */
    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Night Watch', [
        'security' => ['level' => 'full', 'can_approve' => true],
    ])))->toThrow(DomainException::class, 'You do not hold Security · approve yourself, so you cannot give it to a role.');

    // And smuggled in through the per-action list rather than the tag.
    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Night Watch', [
        'security' => ['level' => 'custom', 'verbs' => ['view', 'approve']],
    ])))->toThrow(DomainException::class, 'Security · approve');

    expect(Role::query()->count())->toBe($before);

    /*
     * THE POSITIVE CONTROL, without which the refusals prove only that nothing
     * works: the same actor CAN grant Security at Full, and per-action cells on
     * Reports, and the Approver tag on Residents, which they do hold.
     */
    $role = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Night Watch', [
        'security' => ['level' => 'full'],
        'reports' => ['level' => 'custom', 'verbs' => ['export', 'view']],
        'residents' => ['level' => 'view', 'can_approve' => true],
    ]));

    expect($role->name)->toBe('estate.custom.'.DYNAMIC_ESTATE_A.'.night_watch')
        ->and($role->tenant_id)->toBe(DYNAMIC_ESTATE_A)
        ->and($role->is_system)->toBeFalse()
        ->and($role->created_by_id)->toBe($admin->id)
        ->and(compiledPermissions($role))->toBe([
            'estate.reports.export',
            'estate.reports.view',
            'estate.residents.approve',
            'estate.residents.view',
            'estate.security.configure',
            'estate.security.create',
            'estate.security.delete',
            'estate.security.export',
            'estate.security.update',
            'estate.security.view',
        ]);

    // Every module of the console has a cell, the unnamed ones at no access.
    $cells = RoleModuleAccess::query()->where('role_id', $role->id)->get();

    expect($cells)->toHaveCount(DB::connection('mysql')->table('modules')->where('console', 'estate')->count())
        ->and($cells->firstWhere('module.key', 'reports')->level)->toBe(AccessLevel::Custom)
        ->and($cells->firstWhere('module.key', 'reports')->verbs)->toBe(['view', 'export']);

    /*
     * M11 A2 writes the cells in one INSERT rather than one `create()` a module. Read
     * raw, every row is still what `create()` stored: the level and scope as their
     * values, the verb list as JSON and null where there is none, the Approver tag as
     * a flag, and both timestamps stamped.
     */
    $keys = DB::connection('mysql')->table('modules')->where('console', 'estate')->pluck('key', 'id');
    $rows = DB::connection('mysql')->table('role_module_access')->where('role_id', $role->id)->get();

    expect($rows->pluck('module_id')->sort()->values()->all())->toBe($keys->keys()->sort()->values()->all());

    foreach ($rows as $row) {
        [$level, $verbs, $approve] = match ($keys[$row->module_id]) {
            'security' => ['full', null, 0],
            'reports' => ['custom', ['view', 'export'], 0],
            'residents' => ['view', null, 1],
            default => ['none', null, 0],
        };

        // A JSON column hands the list back in MySQL's own spacing, so it is compared decoded.
        $stored = $row->verbs === null ? null : json_decode((string) $row->verbs, true, flags: JSON_THROW_ON_ERROR);

        expect([$row->level, $stored, (int) $row->can_approve, $row->scope])->toBe([$level, $verbs, $approve, 'all'], $keys[$row->module_id])
            ->and($row->created_at)->not->toBeNull()
            ->and($row->updated_at)->toBe($row->created_at);
    }
});

it('refuses to let a narrower administrator take away what they do not hold, as well as give it', function () {
    $director = dynamicGeminiUser(Role::DIRECTOR);

    // A second administrator the Director made: may change roles, and holds
    // nothing on billing.
    $deputyRole = editor()->create($director, 'Deputy Administrator', [
        'dashboard' => ['level' => 'full'],
        'platform_settings' => ['level' => 'full', 'can_approve' => true],
    ]);

    $deputy = dynamicGeminiUser($deputyRole);

    expect(editor()->canEdit($deputy))->toBeTrue();

    // The Accountant holds Full on billing, which the deputy does not.
    expect(fn () => editor()->setPermissions($deputy, Role::named(Role::ACCOUNTANT), [
        'billing_subscriptions' => ['level' => 'none'],
    ]))->toThrow(DomainException::class, 'so you cannot take it away from a role either');

    expect(fn () => editor()->create($deputy, 'Billing Clerk', ['billing_subscriptions' => ['level' => 'view']]))
        ->toThrow(DomainException::class, 'You do not hold Billing & subscriptions · view yourself, so you cannot give it to a role.');

    expect(Role::named(Role::ACCOUNTANT)->permissions()->where('name', 'gemini.billing_subscriptions.view')->exists())->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* guard rail 2 — nothing crosses a tenant or a console               */
/* ------------------------------------------------------------------ */

it('keeps a role one estate made invisible to and unassignable from another, down to the HTTP request', function () {
    $adminA = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $adminB = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_B);
    $treasurerB = dynamicUser(Role::TREASURER, DYNAMIC_ESTATE_B);

    $role = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($adminA, 'Gardens Officer', ['facilities' => ['level' => 'view']]));

    // Estate A sees its own role, on every list.
    inDynamic(DYNAMIC_ESTATE_A, function () use ($adminA, $role) {
        expect(array_column(app(Settings::class)->invitableRoles(), 'name'))->toContain($role->name)
            ->and(array_column(app(Settings::class)->matrixBoard($adminA)['roles'], 'name'))->toContain($role->name)
            ->and(array_column(editor()->board($adminA)['roles'], 'name'))->toContain($role->name);
    });

    // Estate B sees it on none of them — and still sees the eight shared roles.
    inDynamic(DYNAMIC_ESTATE_B, function () use ($adminB, $role) {
        $invitable = array_column(app(Settings::class)->invitableRoles(), 'name');

        expect($invitable)->not->toContain($role->name)
            ->and($invitable)->toContain(Role::TREASURER)
            ->and(array_column(app(Settings::class)->matrixBoard($adminB)['roles'], 'name'))->not->toContain($role->name)
            ->and(array_column(editor()->board($adminB)['roles'], 'name'))->not->toContain($role->name)
            ->and(editor()->assignable($adminB)->pluck('name')->all())->not->toContain($role->name);
    });

    // Estate B's administrator cannot reach it by id, in any of the five acts,
    // and is told only that it is not one of theirs.
    foreach ([
        fn () => editor()->find($adminB, $role->id),
        fn () => editor()->rename($adminB, $role, 'Taken over'),
        fn () => editor()->setPermissions($adminB, $role, ['residents' => ['level' => 'full']]),
        fn () => editor()->delete($adminB, $role),
        fn () => editor()->copy($adminB, $role),
    ] as $attempt) {
        expect(fn () => inDynamic(DYNAMIC_ESTATE_B, $attempt))->toThrow(DomainException::class, RoleEditor::NOT_HERE_ESTATE);
    }

    // Nor can estate A's administrator act from inside estate B.
    expect(fn () => inDynamic(DYNAMIC_ESTATE_B, fn () => editor()->create($adminA, 'Intruder', [])))
        ->toThrow(DomainException::class, RoleEditor::NOT_HERE_ESTATE);

    // The services that hand roles out refuse it by name.
    $assignment = EstateAssignment::query()->where('user_id', $treasurerB->id)->where('tenant_id', DYNAMIC_ESTATE_B)->sole();

    expect(fn () => inDynamic(DYNAMIC_ESTATE_B, fn () => app(Settings::class)->manageUser($assignment, $role->name, 'active', $adminB)))
        ->toThrow(DomainException::class, 'That is not one of this estate\'s roles.');

    expect(fn () => app(Invitations::class)->invite('leak@rolesdynamicb.test', $role, DYNAMIC_ESTATE_B, $adminB))
        ->toThrow(DomainException::class, RoleEditor::NOT_HERE_ESTATE);

    /*
     * AND OVER HTTP, THROUGH THE REAL ROUTES. The invite controller resolves the
     * role by name with no tenant filter of its own, so this is the proof that
     * the refusal lives in the service where a typed name cannot get past it.
     */
    $this->actingAs($adminB)
        ->post(dynamicUrl(DYNAMIC_ESTATE_B, '/settings/users/'.$assignment->id), ['role' => $role->name, 'status' => 'active'])
        ->assertSessionHasErrors('role');

    $this->actingAs($adminB)
        ->post(dynamicUrl(DYNAMIC_ESTATE_B, '/settings/users/invitations'), ['email' => 'leak@rolesdynamicb.test', 'role' => $role->name])
        ->assertSessionHasErrors();

    expect(EstateAssignment::query()->whereKey($assignment->id)->value('role_id'))->toBe(Role::named(Role::TREASURER)->id)
        ->and($treasurerB->fresh()->hasRole($role->name))->toBeFalse()
        ->and(Invitation::query()->where('email', 'leak@rolesdynamicb.test')->exists())->toBeFalse();
});

it('refuses an estate role any Gemini module, the security company\'s payroll first of all', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $role = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Payroll Watcher', ['payroll' => ['level' => 'view']]));

    foreach (['payroll_accounting', 'gemini.payroll_accounting', 'guard_workforce'] as $key) {
        expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $role, [$key => ['level' => 'view']])))
            ->toThrow(DomainException::class, 'belongs to the Gemini Console');
    }

    // A key no console has is refused too, rather than skipped.
    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $role, ['everything' => ['level' => 'full']])))
        ->toThrow(DomainException::class, 'There is no "everything" module on the Estate Console.');

    // The estate's own staff payroll is not the security company's, and is allowed.
    expect(compiledPermissions($role))->toBe(['estate.payroll.view']);

    // And across the compiled table `can:` reads, nothing crosses a console.
    $crossed = DB::connection('mysql')->table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where(fn ($q) => $q
            ->where(fn ($e) => $e->where('roles.console', 'estate')->where('permissions.name', 'not like', 'estate.%'))
            ->orWhere(fn ($g) => $g->where('roles.console', 'gemini')->where('permissions.name', 'not like', 'gemini.%')))
        ->count();

    expect($crossed)->toBe(0);

    // The Director cannot reach an estate role from the other side either.
    expect(fn () => editor()->setPermissions(dynamicGeminiUser(Role::DIRECTOR), Role::named(Role::TREASURER), ['dashboard' => ['level' => 'view']]))
        ->toThrow(DomainException::class, RoleEditor::NOT_HERE_GEMINI);
});

it('runs the isolation probe gate:isolation STEP 10 uses, and every assertion in it holds', function () {
    $adminA = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $adminB = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_B);
    dynamicUser(Role::TREASURER, DYNAMIC_ESTATE_B);

    $results = app(CrossTenantRoleProbe::class)->run(
        dynamicEstate(DYNAMIC_ESTATE_A),
        dynamicEstate(DYNAMIC_ESTATE_B),
        $adminA,
        $adminB,
        probeRequest($this),
    );

    $notPassed = array_values(array_filter($results, static fn (array $r): bool => $r['status'] !== 'pass'));

    expect($notPassed)->toBe([])
        ->and(count($results))->toBeGreaterThanOrEqual(19)
        ->and(collect($results)->pluck('proves')->implode("\n"))->toContain('can open settings/roles (HTTP 200)');

    // It cleans up after itself through RoleEditor, leaving only the audit trail.
    expect(Role::query()->where('label', 'like', 'Isolation probe %')->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'role.deleted')->where('tenant_id', DYNAMIC_ESTATE_A)->exists())->toBeTrue();
});

it('never counts a POST the contact gate turned away as a refusal, and skips the HTTP proof with the reason instead', function () {
    $adminA = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $adminB = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_B);
    dynamicUser(Role::TREASURER, DYNAMIC_ESTATE_B);

    // Estate B's administrator has no proven alert address, as a new account would not.
    $adminB->forceFill(['contact_email' => null, 'contact_email_verified_at' => null])->save();
    $adminB = $adminB->fresh();

    expect($adminB->needsContactVerification())->toBeTrue();

    $results = collect(app(CrossTenantRoleProbe::class)->run(
        dynamicEstate(DYNAMIC_ESTATE_A),
        dynamicEstate(DYNAMIC_ESTATE_B),
        $adminA,
        $adminB,
        probeRequest($this),
    ));

    $http = $results->filter(static fn (array $r): bool => str_contains($r['proves'], 'POST'))->values();

    // Every POST a gated account makes is redirected with no change to any row,
    // so it would "pass" a row check; the probe reports it as unproven instead.
    expect($http->pluck('status')->all())->toBe(['skip'])
        ->and($http->first()['detail'])->toContain('has not proven an alert address')
        ->and($results->where('status', 'fail')->values()->all())->toBe([])
        // The rest of the proof does not go through the gate, and still runs.
        ->and($results->where('status', 'pass')->count())->toBeGreaterThanOrEqual(19);

    // And nothing lowered the gate for the probe.
    expect($adminB->fresh()->needsContactVerification())->toBeTrue()
        ->and(Role::query()->where('label', 'like', 'Isolation probe %')->exists())->toBeFalse();
});

it('probes records by id as a committee member who may read the Residents register, never one whose own role leaves it out', function () {
    /*
     * gate:isolation STEP 3's positive control reads records/residents/{id}, and
     * that route asks `estate.residents.view` (bug hunt B072). Every SEEDED estate
     * role holds it; a role a community makes need not. A committee clerk with
     * Settings and nothing else is a real role here, and the control run as them
     * answers a correct 403 — which the gate reported as FAILED over no isolation
     * fault at all.
     */
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $clerkRole = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Committee Clerk', ['settings' => ['level' => 'full']]));

    // Out of the running, so the choice below is between the two that follow.
    $admin->forceFill(['status' => 'suspended'])->save();

    $clerk = dynamicUser($clerkRole, DYNAMIC_ESTATE_A);
    $treasurer = dynamicUser(Role::TREASURER, DYNAMIC_ESTATE_A);

    // The refusal the control would meet, and the answer it should: the route's
    // own gate says 403 to the clerk before the lookup, and a register reader
    // gets as far as the lookup, which finds no such row.
    expect($clerk->can('estate.residents.view'))->toBeFalse()
        ->and($treasurer->can('estate.residents.view'))->toBeTrue()
        ->and($clerk->id)->toBeLessThan($treasurer->id);

    $this->actingAs($clerk)->getJson(dynamicUrl(DYNAMIC_ESTATE_A, '/records/residents/99999999'))->assertForbidden();
    $this->actingAs($treasurer)->getJson(dynamicUrl(DYNAMIC_ESTATE_A, '/records/residents/99999999'))->assertNotFound();

    $gate = app(GateTenantIsolation::class);
    $estate = dynamicEstate(DYNAMIC_ESTATE_A);

    // The lowest verified id is the clerk's; the subject is still the Treasurer.
    expect($gate->probeSubject($estate)?->id)->toBe($treasurer->id);

    // The seeded President's address is preferred only while it too may read
    // the register — a President moved into the clerk's role is passed over.
    $clerk->forceFill(['email' => 'president@'.DYNAMIC_ESTATE_A.'.test'])->save();

    expect($gate->probeSubject($estate)?->id)->toBe($treasurer->id);

    // And preferred over a lower id when it can.
    $clerk->forceFill(['email' => 'clerk@'.DYNAMIC_ESTATE_A.'.test'])->save();
    $president = dynamicUser(Role::PRESIDENT, DYNAMIC_ESTATE_A);
    $president->forceFill(['email' => 'president@'.DYNAMIC_ESTATE_A.'.test'])->save();

    expect($gate->probeSubject($estate)?->id)->toBe($president->id);

    // With nobody left who may read the register, there is no subject, and the
    // gate says it could not find one rather than probing with the clerk.
    $president->forceFill(['status' => 'suspended'])->save();
    $treasurer->forceFill(['status' => 'suspended'])->save();

    expect($gate->probeSubject($estate))->toBeNull();
});

it('fails the isolation probe on any account holding a role of the other console, by the role it holds or by a site assignment', function () {
    /*
     * Bug hunt B067's old re-role left exactly this behind, and closing the path
     * does not undo an account it already reached: Gemini's Head of Security,
     * holding the estate's widest role, with that role on the site row too.
     * Nothing may read such an account as fine, so the probe the gate runs names it.
     */
    $adminA = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $adminB = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_B);
    dynamicUser(Role::TREASURER, DYNAMIC_ESTATE_B);

    $probe = app(CrossTenantRoleProbe::class);
    $proves = 'no account holds a role of the other console, by the role it holds or by an active site assignment';

    // The positive control: with nothing crossed, it holds.
    expect($probe->accountsHoldOnlyTheirOwnConsolesRoles())->toBe(['status' => 'pass', 'proves' => $proves, 'detail' => '']);

    $head = dynamicGeminiUser(Role::HEAD_OF_SECURITY);
    $siteRow = EstateAssignment::create([
        'user_id' => $head->id,
        'tenant_id' => DYNAMIC_ESTATE_A,
        'role_id' => Role::named(Role::COMMUNITY_SUPER_ADMIN)->id,
        'is_active' => true,
    ]);
    $head->syncRoles([Role::COMMUNITY_SUPER_ADMIN]);

    // Through the whole probe, as gate:isolation STEP 10 runs it.
    $results = collect($probe->run(
        dynamicEstate(DYNAMIC_ESTATE_A),
        dynamicEstate(DYNAMIC_ESTATE_B),
        $adminA,
        $adminB,
        probeRequest($this),
    ));

    $check = $results->firstWhere('proves', $proves);

    expect($check)->not->toBeNull()
        ->and($check['status'])->toBe('fail')
        ->and($check['detail'])->toContain("gemini account {$head->email} holds ".Role::COMMUNITY_SUPER_ADMIN)
        ->and($check['detail'])->toContain("gemini account {$head->email} is assigned to ".DYNAMIC_ESTATE_A.' as '.Role::COMMUNITY_SUPER_ADMIN)
        // Nothing else in the proof moved.
        ->and($results->where('status', 'fail')->count())->toBe(1);

    // The site row alone is enough to be named.
    $head->syncRoles([Role::HEAD_OF_SECURITY]);

    $check = $probe->accountsHoldOnlyTheirOwnConsolesRoles();

    expect($check['status'])->toBe('fail')
        ->and($check['detail'])->not->toContain(' holds ')
        ->and($check['detail'])->toContain('is assigned to '.DYNAMIC_ESTATE_A);

    // So is an estate account holding a Gemini role, the same fault the other way.
    $treasurer = dynamicUser(Role::TREASURER, DYNAMIC_ESTATE_A);
    $treasurer->syncRoles([Role::ACCOUNTANT]);

    expect($probe->accountsHoldOnlyTheirOwnConsolesRoles()['detail'])->toContain("estate account {$treasurer->email} holds ".Role::ACCOUNTANT);

    // Put right — the officer's site row back in their own role, the Treasurer
    // back in theirs — it holds again. A lapsed row is history, not access.
    $siteRow->forceFill(['role_id' => Role::named(Role::HEAD_OF_SECURITY)->id])->save();
    $treasurer->syncRoles([Role::TREASURER]);

    expect($probe->accountsHoldOnlyTheirOwnConsolesRoles()['status'])->toBe('pass');

    $siteRow->forceFill(['role_id' => Role::named(Role::COMMUNITY_SUPER_ADMIN)->id, 'is_active' => false])->save();

    expect($probe->accountsHoldOnlyTheirOwnConsolesRoles()['status'])->toBe('pass');
});

/* ------------------------------------------------------------------ */
/* the seeded roles — shared, structural, and never renamed           */
/* ------------------------------------------------------------------ */

it('changes a role every community shares only for the community that changed it, and still offers the copy', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $treasurer = Role::named(Role::TREASURER);
    $before = compiledPermissions($treasurer);

    // M11 B4: the change makes estate A's own version of the Treasurer; the shared one does not move.
    $version = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $treasurer, ['governance' => ['level' => 'full']]));

    expect($version->id)->not->toBe($treasurer->id)
        ->and($version->tenant_id)->toBe(DYNAMIC_ESTATE_A)
        ->and($version->version_of_id)->toBe($treasurer->id)
        ->and(compiledPermissions($version))->toContain('estate.governance.update');

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->rename($admin, $treasurer, 'Bursar')))
        ->toThrow(DomainException::class, 'cannot be renamed');

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->delete($admin, $treasurer)))
        ->toThrow(DomainException::class, 'cannot be deleted');

    expect(compiledPermissions($treasurer))->toBe($before)
        ->and($treasurer->fresh()->label)->toBe('Treasurer');

    /*
     * Since D-230 the Community Super Admin holds every approval in the estate, so the copy is shown by one who narrowed
     * their own role in this community to the two cells it held before: Payments without its approval, and Dues &
     * ledger with setting rates alone.
     */
    inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, Role::named(Role::COMMUNITY_SUPER_ADMIN), [
        'payments' => ['level' => 'full', 'can_approve' => false],
        'dues_ledger' => ['level' => 'full', 'acts' => ['set_rates']],
    ]));
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = $admin->fresh();

    /*
     * THE COPY. What the actor does not hold is withheld and named: the
     * Treasurer approves on Payments and this Community Super Admin does not, so
     * the community's own Treasurer is prepared without that approval, and the
     * screen can say so before the copy is made.
     */
    $offer = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->copyableGrid($admin, $treasurer));

    expect($offer['withheld'])->toContain('Payments · approve')
        ->and($offer['grid']['payments'])->toBe(['level' => 'full', 'verbs' => null, 'can_approve' => false, 'acts' => null]);

    /*
     * A SPLIT APPROVAL IS COPIED ACT BY ACT (M11 B3). The Treasurer's Dues &
     * ledger Approver tag is five approvals, and the Community Super Admin holds
     * one of them — setting rates — so the copy keeps that one, on its own, and
     * names the four it cannot hand on.
     */
    expect($offer['grid']['dues_ledger'])->toBe(['level' => 'full', 'verbs' => null, 'can_approve' => false, 'acts' => ['set_rates']])
        ->and($offer['withheld'])->toContain('Dues & ledger · Reverse posted charges')
        ->and($offer['withheld'])->toContain('Dues & ledger · Activate and rule payment plans')
        ->and($offer['withheld'])->toContain('Dues & ledger · Hardship and dispute flags')
        ->and($offer['withheld'])->toContain('Dues & ledger · Put reminder wording in force')
        ->and($offer['withheld'])->not->toContain('Dues & ledger · Set dues rates');

    $copy = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->copy($admin, $treasurer, 'Our Treasurer'));

    expect($copy->tenant_id)->toBe(DYNAMIC_ESTATE_A)
        ->and($copy->copied_from_id)->toBe($treasurer->id)
        ->and($copy->label)->toBe('Our Treasurer')
        ->and(compiledPermissions($copy))->toContain('estate.payments.update')
        ->and(compiledPermissions($copy))->not->toContain('estate.payments.approve')
        ->and(compiledPermissions($copy))->toContain('estate.dues_ledger.set_rates')
        ->and(compiledPermissions($copy))->not->toContain('estate.dues_ledger.reverse_runs')
        ->and(compiledPermissions($copy))->not->toContain('estate.dues_ledger.activate_plans');

    // And the copy is this community's to change.
    inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $copy, ['governance' => ['level' => 'full']]));

    expect(compiledPermissions($copy))->toContain('estate.governance.update');

    $entry = AuditEntry::query()->where('action', 'role.created')->where('entity_id', $copy->name)->sole();

    // Copied from the Treasurer as estate A has it — its own version, since the change above (M11 B4).
    expect($entry->after['copied_from'])->toBe($version->name)
        ->and($copy->copied_from_id)->toBe($treasurer->id)
        ->and($entry->after['withheld'])->toContain('Payments · approve');
});

it('lets the Director re-grant the Gemini Console\'s seeded roles but never rename or delete them, nor strip the Director', function () {
    $director = dynamicGeminiUser(Role::DIRECTOR);
    $dispatcherRole = Role::named(Role::DISPATCHER);
    $dispatcher = dynamicGeminiUser($dispatcherRole);

    expect($dispatcher->can('gemini.cross_tenant_reports.view'))->toBeFalse();

    editor()->setPermissions($director, $dispatcherRole, ['cross_tenant_reports' => ['level' => 'view']]);

    // Effective on the very next check, with nobody clearing a cache.
    expect($dispatcher->fresh()->can('gemini.cross_tenant_reports.view'))->toBeTrue();

    expect(fn () => editor()->rename($director, $dispatcherRole, 'Controller'))
        ->toThrow(DomainException::class, 'Dispatcher is one of the platform\'s own roles, and code across the platform relies on it by name, so it cannot be renamed.');

    expect(fn () => editor()->delete($director, $dispatcherRole))
        ->toThrow(DomainException::class, 'cannot be deleted');

    // THE STRUCTURAL MINIMUM: the Director keeps Full · Approver on Platform
    // settings, whoever asks, and the refusal says why.
    foreach ([['level' => 'view'], ['level' => 'full'], ['level' => 'none']] as $cell) {
        expect(fn () => editor()->setPermissions($director, Role::named(Role::DIRECTOR), ['platform_settings' => $cell]))
            ->toThrow(DomainException::class, 'would leave nobody certain to be able to give it back');
    }

    // Everything else on the Director is the Director's to change.
    editor()->setPermissions($director, Role::named(Role::DIRECTOR), ['clients' => ['level' => 'view']]);

    expect($director->fresh()->can('gemini.clients.delete'))->toBeFalse()
        ->and($director->fresh()->can('gemini.platform_settings.approve'))->toBeTrue();

    // A custom role may not take a seeded role's name.
    expect(fn () => editor()->create($director, 'Director', []))
        ->toThrow(DomainException::class, 'There is already a role called Director here.');

    // Only the Director may do any of this.
    expect(fn () => editor()->create($dispatcher->fresh(), 'Shadow', []))
        ->toThrow(DomainException::class, 'On the Gemini Console the Director holds it, and your role does not.');
});

it('changes a role\'s label and never its machine name', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $role = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Pool Steward', ['facilities' => ['level' => 'entry']]));

    $renamed = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->rename($admin, $role, 'Amenities Steward'));

    expect($renamed->label)->toBe('Amenities Steward')
        ->and($renamed->fresh()->name)->toBe('estate.custom.'.DYNAMIC_ESTATE_A.'.pool_steward');

    // Two roles with one name would make every list of users ambiguous.
    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->rename($admin, $role, 'treasurer')))
        ->toThrow(DomainException::class, 'There is already a role called Treasurer here.');

    // The same name in ANOTHER estate is that estate's business, and its
    // machine name is qualified by its own estate.
    $adminB = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_B);
    $theirs = inDynamic(DYNAMIC_ESTATE_B, fn () => editor()->create($adminB, 'Pool Steward', []));

    expect($theirs->name)->toBe('estate.custom.'.DYNAMIC_ESTATE_B.'.pool_steward');
});

/* ------------------------------------------------------------------ */
/* guard rail 3 — the last Director and the last Community Super Admin */
/* ------------------------------------------------------------------ */

it('refuses to demote, suspend or remove the last Community Super Admin of an estate, and says why', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $president = dynamicUser(Role::PRESIDENT, DYNAMIC_ESTATE_A);
    $assignment = EstateAssignment::query()->where('user_id', $admin->id)->where('tenant_id', DYNAMIC_ESTATE_A)->sole();

    // Asked by somebody else: the service is called directly, so whether the
    // route would let the President through is not the question here.
    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($assignment, Role::PRESIDENT, 'active', $president)))
        ->toThrow(DomainException::class, Settings::LAST_SUPER_ADMIN);

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($assignment, Role::COMMUNITY_SUPER_ADMIN, 'suspended', $president)))
        ->toThrow(DomainException::class, Settings::LAST_SUPER_ADMIN);

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->removeUser($assignment, $president)))
        ->toThrow(DomainException::class, Settings::LAST_SUPER_ADMIN);

    // Asked by the last one of their own row, they are told the nearer reason
    // (M11 B3): nobody changes their own role or suspends themselves at all.
    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($assignment, Role::PRESIDENT, 'active', $admin)))
        ->toThrow(DomainException::class, Settings::NOT_YOUR_OWN_ROLE);

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($assignment, Role::COMMUNITY_SUPER_ADMIN, 'suspended', $admin)))
        ->toThrow(DomainException::class, Settings::NOT_YOUR_OWN_ROLE);

    expect($admin->fresh()->can('estate.settings.approve'))->toBeTrue()
        ->and($assignment->fresh()->is_active)->toBeTrue();

    /*
     * WITH A SECOND ONE, THE SECOND CAN BE MOVED — the rule is about the last,
     * not about the role. The first is still the estate's owner and still not
     * manageable from here.
     */
    $second = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A, 'b');
    $secondAssignment = EstateAssignment::query()->where('user_id', $second->id)->where('tenant_id', DYNAMIC_ESTATE_A)->sole();

    inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($secondAssignment, Role::PRESIDENT, 'active', $admin));

    expect($second->fresh()->hasRole(Role::PRESIDENT))->toBeTrue()
        ->and($second->fresh()->can('estate.settings.approve'))->toBeFalse();
});

it('refuses to remove the last Director, and removes an administrator by suspending them rather than deleting them', function () {
    $director = dynamicGeminiUser(Role::DIRECTOR);
    $ops = dynamicGeminiUser(Role::OPERATIONS_MANAGER);
    $dispatcher = dynamicGeminiUser(Role::DISPATCHER);

    User::query()
        ->where('id', '!=', $director->id)
        ->whereHas('roles', fn ($q) => $q->where('name', Role::DIRECTOR))
        ->update(['status' => 'suspended']);

    expect(fn () => app(PlatformAdmins::class)->remove($director->fresh(), $ops))
        ->toThrow(DomainException::class, 'only active Director');

    expect(fn () => app(PlatformAdmins::class)->remove($director->fresh(), $director->fresh()))
        ->toThrow(DomainException::class, 'You cannot remove yourself.');

    // An administrator is removed only by somebody who holds what they hold.
    $second = dynamicGeminiUser(Role::DIRECTOR);

    expect(fn () => app(PlatformAdmins::class)->remove($second, $ops))
        ->toThrow(DomainException::class, 'Only somebody who holds that power themselves may change their role or their access.');

    app(PlatformAdmins::class)->remove($dispatcher, $director->fresh());

    $dispatcher = $dispatcher->fresh();

    expect($dispatcher->status)->toBe('suspended')
        ->and($dispatcher->roles)->toBeEmpty()
        ->and(User::query()->whereKey($dispatcher->id)->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'platform.user_removed')->where('entity_id', (string) $dispatcher->id)->exists())->toBeTrue();
});

it('lets the Director move a person into a role they made, and keeps estate-owned rows off the platform console', function () {
    $director = dynamicGeminiUser(Role::DIRECTOR);
    $dispatcher = dynamicGeminiUser(Role::DISPATCHER);
    $adminA = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);

    $night = editor()->create($director, 'Night Supervisor', ['dashboard' => ['level' => 'full'], 'dispatch' => ['level' => 'full']]);
    $estateOwn = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($adminA, 'Night Supervisor', []));

    $names = array_column(app(PlatformAdmins::class)->board($director)['roles'], 'name');

    expect($names)->toContain($night->name)
        ->and($names)->not->toContain($estateOwn->name)
        ->and(array_column(editor()->board($director)['roles'], 'name'))->not->toContain($estateOwn->name);

    app(PlatformAdmins::class)->manage($dispatcher, $night->name, 'active', [], $director);

    expect($dispatcher->fresh()->hasRole($night->name))->toBeTrue()
        ->and($dispatcher->fresh()->can('gemini.dispatch.delete'))->toBeTrue();

    expect(fn () => app(PlatformAdmins::class)->manage($dispatcher->fresh(), $estateOwn->name, 'active', [], $director))
        ->toThrow(DomainException::class, 'Choose one of the Gemini Console\'s roles.');
});

it('never lets a role the estate made outrank the Community Super Admin for ownership', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $role = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Chairperson', ['dashboard' => ['level' => 'view']]));

    // The escalation this closes: a custom role at the top of the sort order.
    $role->forceFill(['sort' => 1])->save();
    $chair = dynamicUser($role->fresh(), DYNAMIC_ESTATE_A);

    $board = inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->usersBoard(DYNAMIC_ESTATE_A));

    expect($board['owner_role'])->toBe(Role::COMMUNITY_SUPER_ADMIN);

    $adminAssignment = EstateAssignment::query()->where('user_id', $admin->id)->where('tenant_id', DYNAMIC_ESTATE_A)->sole();
    $chairAssignment = EstateAssignment::query()->where('user_id', $chair->id)->where('tenant_id', DYNAMIC_ESTATE_A)->sole();

    // The holder of the custom role cannot move the real senior officer — the
    // refusal is the role-changing power, which they do not hold.
    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($adminAssignment, Role::SECRETARY, 'active', $chair)))
        ->toThrow(DomainException::class);

    // And the Community Super Admin can still manage the custom role's holder.
    inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($chairAssignment, Role::SECRETARY, 'active', $admin));

    expect($chair->fresh()->hasRole(Role::SECRETARY))->toBeTrue();
});

it('lets only somebody who can change roles hand out a role that can', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);

    // A clerk who manages the committee — Settings at Full — without the
    // power to change what a role may do.
    $clerkRole = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Committee Clerk', ['settings' => ['level' => 'full']]));
    $clerk = dynamicUser($clerkRole, DYNAMIC_ESTATE_A);
    $secretary = dynamicUser(Role::SECRETARY, DYNAMIC_ESTATE_A);
    $assignment = EstateAssignment::query()->where('user_id', $secretary->id)->where('tenant_id', DYNAMIC_ESTATE_A)->sole();

    // Settings at Full is both halves of what `update` was (M11 B3, D-150).
    expect($clerk->can('estate.settings.manage_people'))->toBeTrue()
        ->and($clerk->can('estate.settings.edit_profile'))->toBeTrue()
        ->and($clerk->can('estate.settings.approve'))->toBeFalse();

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($assignment, Role::COMMUNITY_SUPER_ADMIN, 'active', $clerk)))
        ->toThrow(DomainException::class, 'Community Super Admin can change what every role here may do');

    expect(fn () => app(Invitations::class)->invite('friend@rolesdynamica.test', Role::named(Role::COMMUNITY_SUPER_ADMIN), DYNAMIC_ESTATE_A, $clerk))
        ->toThrow(DomainException::class, 'Community Super Admin can change what every role here may do');

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($clerk, 'Clerk Two', [])))
        ->toThrow(DomainException::class, 'On this estate the Community Super Admin holds it, and your role does not.');

    // The clerk may still seat a Treasurer, which is the job.
    inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($assignment, Role::TREASURER, 'active', $clerk));

    expect($secretary->fresh()->hasRole(Role::TREASURER))->toBeTrue();

    // And the Community Super Admin may invite into the estate's own role.
    $invitation = app(Invitations::class)->invite('clerk2@rolesdynamica.test', $clerkRole, DYNAMIC_ESTATE_A, $admin);

    expect($invitation->role_id)->toBe($clerkRole->id);
});

/* ------------------------------------------------------------------ */
/* guard rail 5 — effective on the very next request                  */
/* ------------------------------------------------------------------ */

it('applies a permission change to the very next request with no cache cleared by hand, and a deleted role grants nothing', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $role = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Minutes Reader', ['settings' => ['level' => 'view']]));
    $reader = dynamicUser($role, DYNAMIC_ESTATE_A);

    $this->actingAs($reader)->get(dynamicUrl(DYNAMIC_ESTATE_A, '/settings/roles'))->assertOk();

    inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $role, ['settings' => ['level' => 'none']]));

    // No forgetCachedPermissions() here: RoleEditor must have done it.
    $this->actingAs($reader->fresh())->get(dynamicUrl(DYNAMIC_ESTATE_A, '/settings/roles'))->assertForbidden();

    inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $role, ['settings' => ['level' => 'view']]));

    $this->actingAs($reader->fresh())->get(dynamicUrl(DYNAMIC_ESTATE_A, '/settings/roles'))->assertOk();

    // Refused while somebody holds it, naming how many.
    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->delete($admin, $role)))
        ->toThrow(DomainException::class, '1 person holds Minutes Reader. Move them to another role first');

    $assignment = EstateAssignment::query()->where('user_id', $reader->id)->where('tenant_id', DYNAMIC_ESTATE_A)->sole();
    inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->manageUser($assignment, Role::SECRETARY, 'active', $admin));

    inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->delete($admin, $role));

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
        ->and(RoleModuleAccess::query()->where('role_id', $role->id)->exists())->toBeFalse()
        ->and(DB::connection('mysql')->table('role_has_permissions')->where('role_id', $role->id)->exists())->toBeFalse();

    // The Secretary holds no Settings: refused, and not from a stale copy.
    $this->actingAs($reader->fresh())->get(dynamicUrl(DYNAMIC_ESTATE_A, '/settings/roles'))->assertForbidden();
});

it('keeps a role somebody has left, and one an invitation still names, rather than erasing that history', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $role = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Gate Liaison', ['security' => ['level' => 'view']]));

    $invitation = app(Invitations::class)->invite('liaison@rolesdynamica.test', $role, DYNAMIC_ESTATE_A, $admin);

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->delete($admin, $role)))
        ->toThrow(DomainException::class, 'An invitation still names Gate Liaison. Withdraw it first');

    app(Invitations::class)->revoke($invitation, $admin);

    $liaison = dynamicUser($role, DYNAMIC_ESTATE_A);
    $assignment = EstateAssignment::query()->where('user_id', $liaison->id)->where('tenant_id', DYNAMIC_ESTATE_A)->sole();

    // Removed from the committee: nothing deleted, access withdrawn everywhere.
    inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->removeUser($assignment, $admin));

    expect($assignment->fresh()->is_active)->toBeFalse()
        ->and($liaison->fresh()->status)->toBe('suspended')
        ->and($liaison->fresh()->roles)->toBeEmpty()
        ->and($liaison->fresh()->canAccessEstate(DYNAMIC_ESTATE_A))->toBeFalse();

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->delete($admin, $role)))
        ->toThrow(DomainException::class, 'Gate Liaison is named in the record of somebody who has since left it.');

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->removeUser(
        EstateAssignment::query()->where('user_id', $admin->id)->where('tenant_id', DYNAMIC_ESTATE_A)->sole(),
        $admin,
    )))->toThrow(DomainException::class, 'You cannot remove yourself.');
});

/* ------------------------------------------------------------------ */
/* guard rail 4 — every create, change and delete is audited          */
/* ------------------------------------------------------------------ */

it('writes one audit row per create, rename, permission change and delete, with actor, role, before and after', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);

    $role = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Audit Probe', ['reports' => ['level' => 'view']]));
    inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->rename($admin, $role, 'Audit Probe Renamed'));
    inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $role, ['reports' => ['level' => 'full']]));
    inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->delete($admin, $role));

    $rows = AuditEntry::query()->where('entity_type', 'Role')->where('entity_id', $role->name)->orderBy('id')->get();

    expect($rows->pluck('action')->all())->toBe(['role.created', 'role.renamed', 'role.permissions_changed', 'role.deleted']);

    foreach ($rows as $row) {
        expect($row->actor_id)->toBe($admin->id)
            ->and($row->actor_name)->toBe($admin->name)
            ->and($row->actor_role)->toBe('Community Super Admin')
            ->and($row->tenant_id)->toBe(DYNAMIC_ESTATE_A)
            ->and($row->created_at)->not->toBeNull();
    }

    [$created, $renamed, $changed, $deleted] = $rows->all();

    expect($created->before)->toBeNull()
        ->and($created->after['grid']['reports']['level'])->toBe('view')
        ->and($created->after['permissions'])->toBe(['estate.reports.view'])
        ->and($renamed->before['label'])->toBe('Audit Probe')
        ->and($renamed->after['label'])->toBe('Audit Probe Renamed')
        ->and($changed->before['permissions'])->toBe(['estate.reports.view'])
        ->and($changed->after['permissions'])->toContain('estate.reports.delete')
        ->and($changed->after['grid']['reports']['level'])->toBe('full')
        ->and($deleted->before['grid']['reports']['level'])->toBe('full')
        ->and($deleted->after)->toBeNull();
});

/* ------------------------------------------------------------------ */
/* per-action cells, visibility, Ruling 1 and scope                   */
/* ------------------------------------------------------------------ */

it('keeps a module a custom cell cannot view out of every navigation, and refuses an Approver with nothing to open', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);

    $role = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Exporter', [
        'reports' => ['level' => 'custom', 'verbs' => ['export']],
        'dashboard' => ['level' => 'custom', 'verbs' => ['view']],
    ]));

    $cells = RoleModuleAccess::query()->with('module')->where('role_id', $role->id)->get()->keyBy('module.key');

    // "view" alone is the View preset and is stored as one.
    expect($cells['dashboard']->level)->toBe(AccessLevel::View)
        ->and($cells['reports']->level)->toBe(AccessLevel::Custom)
        ->and($cells['reports']->grantsView())->toBeFalse()
        ->and($cells['reports']->pillLabel())->toBe('Custom (export)')
        ->and(compiledPermissions($role))->toBe(['estate.dashboard.view', 'estate.reports.export']);

    // Absent, not greyed: the matrix's own navigation, the estate sidebar and
    // the "reaches these modules" list all leave Reports out.
    $holder = dynamicUser($role, DYNAMIC_ESTATE_A);

    expect($role->fresh()->navigableModules()->pluck('key')->all())->toBe(['dashboard'])
        ->and(array_column((new EstateNavigation)->forViewer($holder), 'key'))->not->toContain('reports');

    $row = collect(inDynamic(DYNAMIC_ESTATE_A, fn () => app(Settings::class)->usersBoard(DYNAMIC_ESTATE_A))['rows'])->firstWhere('role', $role->name);

    expect($row['module_list'])->toBe(['Dashboard']);

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $role, [
        'residents' => ['level' => 'custom', 'verbs' => ['export'], 'can_approve' => true],
    ])))->toThrow(DomainException::class, 'Give Residents at least view before tagging it Approver.');
});

it('binds a copy of the Property Manager by Ruling 1, whatever it is renamed to', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);

    $works = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->copy($admin, Role::named(Role::PROPERTY_MANAGER), 'Works Officer'));

    expect($works->derivesFrom(Role::PROPERTY_MANAGER))->toBeTrue();

    foreach (['dues_ledger', 'payments', 'accounting_posting', 'payroll'] as $locked) {
        expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $works, [$locked => ['level' => 'view']])))
            ->toThrow(DomainException::class, 'is closed to the Property Manager, and to any role copied from it, by platform invariant');
    }

    // Renamed out of recognition, and still bound.
    $works = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->rename($admin, $works, 'Estate Maintenance'));

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $works, ['dues_ledger' => ['level' => 'view']])))
        ->toThrow(DomainException::class, 'closed to the Property Manager');

    /*
     * A ROLE BUILT FROM NOTHING IS NOT BOUND, and that is a finding for the
     * client rather than a rule this work invents: Ruling 1 was written against
     * the Property Manager role, not against the duty of commissioning work.
     */
    $clerk = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Accounts Clerk', ['dues_ledger' => ['level' => 'view']]));

    expect(compiledPermissions($clerk))->toBe(['estate.dues_ledger.view']);
});

it('gives an estate role no scope to set, and lets the Director make a site-scoped one', function () {
    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);

    expect(fn () => inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Wanderer', [], 'assigned_sites')))
        ->toThrow(DomainException::class, 'An estate role reaches the whole of this estate and nothing beyond it.');

    $director = dynamicGeminiUser(Role::DIRECTOR);
    $patrol = editor()->create($director, 'Patrol Lead', ['clients' => ['level' => 'full']], 'assigned_sites');

    $cell = RoleModuleAccess::query()->with('module')->where('role_id', $patrol->id)->get()->firstWhere('module.key', 'clients');

    expect($patrol->scope_default->value)->toBe('assigned_sites')
        ->and($cell->scope->value)->toBe('assigned_sites')
        ->and($patrol->tenant_id)->toBeNull();
});

/* ------------------------------------------------------------------ */
/* a re-seed keeps what the Director changed, and resetting undoes it */
/* ------------------------------------------------------------------ */

it('keeps the Director\'s change to the Dispatcher through a re-seed, and a community\'s own role, and puts every other seeded role back', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    $pristine = seededFingerprints();

    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $director = dynamicGeminiUser(Role::DIRECTOR);

    $own = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->create($admin, 'Seed Survivor', [
        'facilities' => ['level' => 'entry'],
        'reports' => ['level' => 'custom', 'verbs' => ['view', 'export']],
    ]));
    $ownBefore = roleFingerprint($own);

    $dispatcher = editTheDispatcher($director);
    $edited = roleFingerprint($dispatcher);

    expect($dispatcher->fresh()->permissions_edited_at)->not->toBeNull()
        ->and($dispatcher->fresh()->permissions_edited_by_id)->toBe($director->id)
        ->and(compiledPermissions($dispatcher))->toContain('gemini.cross_tenant_reports.view', 'gemini.access_audit_log.export');

    /*
     * What a re-seed must still put back: a label and sort moved by hand on the
     * edited role, and a cell moved by hand on a role nobody edited.
     */
    $dispatcher->forceFill(['label' => 'Renamed by hand', 'sort' => 999])->save();

    RoleModuleAccess::query()
        ->where('role_id', Role::named(Role::OPERATIONS_MANAGER)->id)
        ->whereHas('module', fn ($m) => $m->where('console', 'gemini')->where('key', 'clients'))
        ->update(['level' => AccessLevel::None->value]);

    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    // The Director's change, cell for cell and permission for permission, under
    // the seeded label and sort.
    expect(roleFingerprint(Role::DISPATCHER))->toBe($edited)
        ->and(Role::named(Role::DISPATCHER)->permissions_edited_at)->not->toBeNull();

    // Every other seeded role is exactly as the grid says, the hand edit undone.
    $others = static fn (array $prints): array => array_diff_key($prints, [Role::DISPATCHER => true]);

    expect($others(seededFingerprints()))->toBe($others($pristine))
        ->and(roleFingerprint($own))->toBe($ownBefore);

    // Exactly the fourteen, all marked, all platform-wide.
    expect(Role::query()->where('is_system', true)->whereNull('tenant_id')->count())->toBe(14)
        ->and(Role::query()->where('is_system', true)->whereNotNull('tenant_id')->count())->toBe(0);
});

it('still gives a seeded role the Director changed a module the grid introduces, at the grid\'s value', function () {
    $director = dynamicGeminiUser(Role::DIRECTOR);
    $dispatcher = editTheDispatcher($director);

    $cellsBefore = RoleModuleAccess::query()->where('role_id', $dispatcher->id)->orderBy('module_id')->get()
        ->map(static fn (RoleModuleAccess $c): array => [$c->module_id, $c->level->value, $c->verbs, $c->can_approve, $c->scope->value])->all();
    $permissionsBefore = compiledPermissions($dispatcher);

    // A release that adds a module. Columns: Director, Ops, HoS, Dispatcher, Admin Asst, Accountant.
    changedMatrix(
        [['m10_probe_module', 'M10 probe module', 'System', 90, false]],
        ['m10_probe_module' => ['F', 'V', 'S', 'V', '-', '-']],
    )->run();

    $probe = DB::connection('mysql')->table('modules')->where('console', 'gemini')->where('key', 'm10_probe_module')->value('id');

    $cellsAfter = RoleModuleAccess::query()->where('role_id', $dispatcher->id)->orderBy('module_id')->get()
        ->map(static fn (RoleModuleAccess $c): array => [$c->module_id, $c->level->value, $c->verbs, $c->can_approve, $c->scope->value]);

    // The new module reaches the edited role, at the grid's value …
    expect($cellsAfter->firstWhere(0, $probe))->toBe([$probe, 'view', null, false, 'all'])
        ->and(compiledPermissions($dispatcher))->toBe(collect([...$permissionsBefore, 'gemini.m10_probe_module.view'])->sort()->values()->all());

    // … and every cell the Director set is exactly as they set it.
    expect($cellsAfter->reject(static fn (array $c): bool => $c[0] === $probe)->values()->all())->toBe($cellsBefore)
        ->and($dispatcher->fresh()->permissions_edited_at)->not->toBeNull();

    // The unedited roles get the new row as drawn: the Head of Security scoped.
    expect(Role::named(Role::HEAD_OF_SECURITY)->permissions()->where('name', 'gemini.m10_probe_module.update')->exists())->toBeTrue()
        ->and(RoleModuleAccess::query()->where('role_id', Role::named(Role::HEAD_OF_SECURITY)->id)->where('module_id', $probe)->sole()->scope)->toBe(AccessScope::AssignedSites)
        ->and(Role::named(Role::ADMIN_ASSISTANT)->permissions()->where('name', 'like', 'gemini.m10_probe_module.%')->exists())->toBeFalse();
});

it('keeps an unedited seeded role in step with a changed grid, and leaves the edited one as the Director set it', function () {
    $director = dynamicGeminiUser(Role::DIRECTOR);
    $dispatcher = editTheDispatcher($director);
    $edited = roleFingerprint($dispatcher);

    expect(Role::named(Role::ACCOUNTANT)->permissions()->where('name', 'gemini.cross_tenant_reports.delete')->exists())->toBeFalse();

    /*
     * A release that changes two rows: the Accountant goes from View to Full on
     * cross-tenant reports, and the drawn Dispatcher cells on both rows move
     * too. The Accountant follows; the Dispatcher's cells are the Director's.
     */
    changedMatrix([], [
        'cross_tenant_reports' => ['F', 'F', 'S', 'F', '-', 'F'],
        'clients' => ['F', 'F', 'S', '-', 'V', 'V'],
    ])->run();

    $accountant = Role::named(Role::ACCOUNTANT);

    expect($accountant->permissions()->where('name', 'gemini.cross_tenant_reports.delete')->exists())->toBeTrue()
        ->and($accountant->permissions_edited_at)->toBeNull()
        ->and(roleFingerprint(Role::DISPATCHER))->toBe($edited)
        ->and(Role::named(Role::DISPATCHER)->permissions()->where('name', 'gemini.cross_tenant_reports.delete')->exists())->toBeFalse()
        ->and(Role::named(Role::DISPATCHER)->permissions()->where('name', 'gemini.clients.view')->exists())->toBeTrue();
});

it('puts a seeded role back to its default with rbac:reset-role only when confirmed, audits it, and applies it on the next check', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    $pristine = roleFingerprint(Role::DISPATCHER);

    $director = dynamicGeminiUser(Role::DIRECTOR);
    $holder = dynamicGeminiUser(Role::DISPATCHER);

    editor()->setPermissions($director, Role::named(Role::DISPATCHER), [
        'cross_tenant_reports' => ['level' => 'view'],
        'clients' => ['level' => 'none'],
    ]);
    $edited = roleFingerprint(Role::DISPATCHER);

    expect($holder->fresh()->can('gemini.clients.view'))->toBeFalse();

    // Without --confirm: what would change, and nothing changed.
    expect(Artisan::call('rbac:reset-role', ['name' => Role::DISPATCHER]))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('+ gemini.clients.view')
        ->and($output)->toContain('- gemini.cross_tenant_reports.view')
        ->and($output)->toContain('Nothing has changed')
        ->and(roleFingerprint(Role::DISPATCHER))->toBe($edited)
        ->and(AuditEntry::query()->where('action', 'role.reset_to_default')->exists())->toBeFalse();

    // With it: the grid, the mark cleared, one audit row with no actor.
    expect(Artisan::call('rbac:reset-role', ['name' => Role::DISPATCHER, '--confirm' => true]))->toBe(0);

    expect(roleFingerprint(Role::DISPATCHER))->toBe($pristine)
        ->and(Role::named(Role::DISPATCHER)->permissions_edited_at)->toBeNull()
        ->and(Role::named(Role::DISPATCHER)->permissions_edited_by_id)->toBeNull();

    $entry = AuditEntry::query()->where('action', 'role.reset_to_default')->sole();

    expect($entry->entity_type)->toBe('Role')
        ->and($entry->entity_id)->toBe(Role::DISPATCHER)
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->before['permissions'])->toContain('gemini.cross_tenant_reports.view')
        ->and($entry->before['edited_by_id'])->toBe($director->id)
        ->and($entry->after['permissions'])->not->toContain('gemini.cross_tenant_reports.view')
        ->and($entry->after['permissions'])->toContain('gemini.clients.view')
        ->and($entry->after['via'])->toBe('rbac:reset-role');

    // No cache cleared by hand: the command flushed it after its commit.
    expect($holder->fresh()->can('gemini.clients.view'))->toBeTrue()
        ->and($holder->fresh()->can('gemini.cross_tenant_reports.view'))->toBeFalse();

    // Unmarked, it follows the grid again, and a second reset has nothing to do.
    changedMatrix([], ['cross_tenant_reports' => ['F', 'F', 'S', 'V', '-', 'V']])->run();

    expect($holder->fresh()->can('gemini.cross_tenant_reports.view'))->toBeTrue();

    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    expect(Artisan::call('rbac:reset-role', ['name' => Role::DISPATCHER, '--confirm' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('already at GeminiSecure\'s default')
        ->and(AuditEntry::query()->where('action', 'role.reset_to_default')->count())->toBe(1);

    // A role with no default, and a role that does not exist, are refused.
    $custom = editor()->create($director, 'Night Supervisor', ['dashboard' => ['level' => 'view']]);

    expect(Artisan::call('rbac:reset-role', ['name' => $custom->name, '--confirm' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('has no GeminiSecure default')
        ->and(Artisan::call('rbac:reset-role', ['name' => 'gemini.nobody']))->toBe(1);
});

it('lets the Director reset a seeded role from the console, and clears the mark when a change lands back on the default', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    $director = dynamicGeminiUser(Role::DIRECTOR);
    $pristine = roleFingerprint(Role::DISPATCHER);

    // A save that changes nothing is not an edit: no mark, no audit row.
    editor()->setPermissions($director, Role::named(Role::DISPATCHER), ['clients' => ['level' => 'view']]);

    expect(Role::named(Role::DISPATCHER)->permissions_edited_at)->toBeNull()
        ->and(AuditEntry::query()->where('action', 'role.permissions_changed')->where('entity_id', Role::DISPATCHER)->exists())->toBeFalse();

    // Changed and changed back by hand: back on the default, so unmarked.
    editor()->setPermissions($director, Role::named(Role::DISPATCHER), ['cross_tenant_reports' => ['level' => 'view']]);
    expect(Role::named(Role::DISPATCHER)->permissions_edited_at)->not->toBeNull();

    editor()->setPermissions($director, Role::named(Role::DISPATCHER), ['cross_tenant_reports' => ['level' => 'none']]);
    expect(Role::named(Role::DISPATCHER)->permissions_edited_at)->toBeNull();

    // The board says what a reset would do, and who changed the role.
    editTheDispatcher($director);

    $row = collect(editor()->board($director)['roles'])->firstWhere('name', Role::DISPATCHER);

    expect($row['is_default'])->toBeFalse()
        ->and($row['can_reset'])->toBeTrue()
        ->and($row['reset_refusal'])->toBeNull()
        ->and($row['permissions_edited_by'])->toBe($director->name)
        ->and($row['permissions_edited_at'])->not->toBeNull()
        ->and($row['note'])->toContain('Platform updates keep your changes');

    expect(editor()->defaultDifference(Role::named(Role::DISPATCHER))['remove'])
        ->toBe(['gemini.access_audit_log.export', 'gemini.access_audit_log.view', 'gemini.cross_tenant_reports.view']);

    editor()->resetToDefault($director, Role::named(Role::DISPATCHER));

    expect(roleFingerprint(Role::DISPATCHER))->toBe($pristine)
        ->and(Role::named(Role::DISPATCHER)->permissions_edited_at)->toBeNull();

    $entry = AuditEntry::query()->where('action', 'role.reset_to_default')->sole();

    expect($entry->actor_id)->toBe($director->id)
        ->and($entry->after['via'])->toBe('console');

    $row = collect(editor()->board($director)['roles'])->firstWhere('name', Role::DISPATCHER);

    expect($row['is_default'])->toBeTrue()
        ->and($row['can_reset'])->toBeFalse()
        ->and($row['reset_refusal'])->toBe('Dispatcher already has GeminiSecure\'s default permissions.')
        ->and($row['note'])->toContain('at GeminiSecure\'s default');

    // A role made here has no default.
    $custom = editor()->create($director, 'Night Supervisor', ['dashboard' => ['level' => 'view']]);

    expect(fn () => editor()->resetToDefault($director, $custom))
        ->toThrow(DomainException::class, 'Night Supervisor was made here, so it has no GeminiSecure default to go back to.');

    /*
     * A RESET IS STILL A GRANT. The Director narrows the Accountant's billing;
     * a deputy who may change roles but holds no billing cannot put it back,
     * because the default holds what the deputy does not.
     */
    editor()->setPermissions($director, Role::named(Role::ACCOUNTANT), ['billing_subscriptions' => ['level' => 'view']]);

    $deputy = dynamicGeminiUser(editor()->create($director, 'Deputy Administrator', [
        'dashboard' => ['level' => 'full'],
        'platform_settings' => ['level' => 'full', 'can_approve' => true],
    ]));

    expect(fn () => editor()->resetToDefault($deputy, Role::named(Role::ACCOUNTANT)))
        ->toThrow(DomainException::class, 'yourself, so you cannot give it to a role.');

    expect(Role::named(Role::ACCOUNTANT)->permissions_edited_at)->not->toBeNull();
});

it('keeps every seeded estate role following the grid, even one marked as edited by hand', function () {
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    $pristine = roleFingerprint(Role::TREASURER);

    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $treasurer = Role::named(Role::TREASURER);

    // Nothing on the console marks the SHARED role (M11 B4): a community's change makes its own version,
    // and the shared role every other community holds is not touched, marked or taken off the grid.
    $version = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->setPermissions($admin, $treasurer, ['governance' => ['level' => 'full']]));
    $versionPrint = roleFingerprint($version);

    expect($treasurer->fresh()->permissions_edited_at)->toBeNull()
        ->and(roleFingerprint(Role::TREASURER))->toBe($pristine);

    // Where nobody has changed it, it is at GeminiSecure's default, and there is nothing to reset.
    $adminB = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_B);
    $row = collect(inDynamic(DYNAMIC_ESTATE_B, fn () => editor()->board($adminB))['roles'])->firstWhere('name', Role::TREASURER);

    expect($row['can_reset'])->toBeFalse()
        ->and($row['reset_refusal'])->toContain('at GeminiSecure\'s default for this community');

    // Marked and widened by hand, straight into the database …
    $treasurer->forceFill(['permissions_edited_at' => now()])->save();

    RoleModuleAccess::query()
        ->where('role_id', $treasurer->id)
        ->whereHas('module', fn ($m) => $m->where('console', 'estate')->where('key', 'governance'))
        ->update(['level' => AccessLevel::Full->value]);

    $treasurer->givePermissionTo('estate.governance.update');

    expect(roleFingerprint(Role::TREASURER))->not->toBe($pristine);

    // … and the next re-seed puts it back to the grid and takes the mark off.
    Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);

    expect(roleFingerprint(Role::TREASURER))->toBe($pristine)
        ->and(Role::named(Role::TREASURER)->permissions_edited_at)->toBeNull()
        ->and(Role::query()->where('console', 'estate')->where('is_system', true)->whereNotNull('permissions_edited_at')->exists())->toBeFalse()
        // And the re-seed leaves estate A's own version exactly as estate A left it.
        ->and(roleFingerprint($version))->toBe($versionPrint);
});

it('gives the power to change roles to the Director and the Community Super Admin and to no other seeded role', function () {
    $holders = Role::query()
        ->where('is_system', true)
        ->whereHas('permissions', fn ($q) => $q->whereIn('name', ['estate.settings.approve', 'gemini.platform_settings.approve']))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($holders)->toBe([Role::COMMUNITY_SUPER_ADMIN, Role::DIRECTOR]);

    // And the board says so to everybody else, in a sentence.
    $president = dynamicUser(Role::PRESIDENT, DYNAMIC_ESTATE_A);
    $board = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->board($president));

    expect($board['can_edit'])->toBeFalse()
        ->and($board['edit_blocked_reason'])->toContain('the Community Super Admin holds it')
        ->and(collect($board['roles'])->firstWhere('name', Role::TREASURER)['can_edit_permissions'])->toBeFalse();

    $admin = dynamicUser(Role::COMMUNITY_SUPER_ADMIN, DYNAMIC_ESTATE_A);
    $board = inDynamic(DYNAMIC_ESTATE_A, fn () => editor()->board($admin));
    $treasurer = collect($board['roles'])->firstWhere('name', Role::TREASURER);

    // A shared role is this community's to change as its own version (M11 B4).
    expect($board['can_edit'])->toBeTrue()
        ->and($treasurer['is_shared'])->toBeTrue()
        ->and($treasurer['can_edit_permissions'])->toBeTrue()
        ->and($treasurer['permissions_refusal'])->toBeNull()
        ->and($board['grantable']['security']['approve'])->toBeFalse()
        ->and($board['grantable']['security']['delete'])->toBeTrue()
        ->and(array_column($board['modules'], 'key'))->not->toContain('payroll_accounting');
});
