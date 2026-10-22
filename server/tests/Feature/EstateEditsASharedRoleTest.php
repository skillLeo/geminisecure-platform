<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\SiteEscalation;
use App\Services\Estate\Settings;
use App\Services\Rbac\RoleEditor;
use Database\Seeders\Estate\SettingsSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| An estate edits one of the shared roles as its own (M11 B4)
|--------------------------------------------------------------------------
|
| The client: "Let the Community Super Admin add, change and remove roles and
| permissions. 'Copy it first, then edit the copy' isn't that." The first change
| an estate makes to one of the eight shared roles makes that estate's version of
| it behind the scenes. The version is an exact copy with the same label and
| place; this estate's holders and invitations move onto it; every other estate
| keeps the shared role as it was. "Reset to GeminiSecure default" puts this
| estate's people back on the shared role and removes the version.
|
| And the rules that bind a shared role bind its version:
| - nobody gives or takes away what they do not hold;
| - Ruling 1 closes the money to a version of the Property Manager;
| - a version of the Community Super Admin keeps Settings' Full · Approver, and
|   its last holder cannot be demoted;
| - a version of the estate's Head of Security is still texted on a panic.
|
| TWO ESTATES OF THIS FILE'S OWN, provisioned once per process, for the reason
| RolesAreDynamicTest gives: half of what is under test is that one estate's
| change does not reach the other.
|
*/

const VERSION_ESTATE_A = 'roleversiona';
const VERSION_ESTATE_B = 'roleversionb';

function versionEstate(string $key): Tenant
{
    static $provisioned = [];

    if (isset($provisioned[$key])) {
        return Tenant::findOrFail($key);
    }

    $current = Role::query()
        ->where('name', Role::COMMUNITY_SUPER_ADMIN)
        ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'estate.dues_ledger.set_rates'))
        ->exists();

    if (! $current) {
        Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    }

    $database = config('tenancy.database.prefix').$key;
    $owner = DB::connection('mysql_owner');

    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::connection('mysql')->table('domains')->where('tenant_id', $key)->delete();
    DB::connection('mysql')->table('tenants')->where('id', $key)->delete();

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $key,
        'name' => $key === VERSION_ESTATE_A ? 'Version Estate A' : 'Version Estate B',
        'receipt_prefix' => $key === VERSION_ESTATE_A ? 'RVA' : 'RVB',
        'address_line' => 'Hope Road',
        'parish' => 'St. Andrew',
        'gate_count' => 1,
        'phases' => json_encode(['Phase 1']),
        'status' => 'active',
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

function inVersionEstate(string $key, callable $work): mixed
{
    try {
        return versionEstate($key)->run($work);
    } finally {
        tenancy()->end();
    }
}

/** A person of one estate holding one role, with the assignment that lets them reach it. */
function versionUser(Role $role, string $estate, string $who = 'a'): User
{
    $email = str_replace('.', '-', $role->name).'-'.$who.'@'.$estate.'.test';

    $user = User::query()->firstOrNew(['email' => $email]);
    $user->fill(['name' => $role->label.' '.strtoupper($who), 'password' => 'password', 'console' => Console::Estate->value, 'status' => 'active']);
    $user->forceFill(['contact_email' => $email, 'contact_email_verified_at' => now()])->save();
    $user->syncRoles([$role->name]);

    EstateAssignment::updateOrCreate(
        ['user_id' => $user->id, 'tenant_id' => $estate],
        ['role_id' => $role->id, 'is_active' => true],
    );

    return $user->fresh();
}

function versions(): RoleEditor
{
    return app(RoleEditor::class);
}

/** @return list<string> */
function versionPermissions(Role $role): array
{
    return $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
}

/** Whether this person, as the permission cache now has them, may do this. */
function versionCan(User $user, string $permission): bool
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return User::query()->findOrFail($user->id)->can($permission);
}

beforeEach(function () {
    versionEstate(VERSION_ESTATE_A);
    versionEstate(VERSION_ESTATE_B);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::connection('mysql')->beginTransaction();

    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('makes this community its own version on the first change, and moves this community\'s holders onto it — only this community\'s', function () {
    $shared = Role::named(Role::TREASURER);
    $sharedBefore = versionPermissions($shared);

    $admin = versionUser(Role::named(Role::COMMUNITY_SUPER_ADMIN), VERSION_ESTATE_A);
    $treasurerA = versionUser($shared, VERSION_ESTATE_A);
    $treasurerB = versionUser($shared, VERSION_ESTATE_B);

    expect(versionCan($treasurerA, 'estate.governance.create'))->toBeFalse();

    $version = inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $shared, ['governance' => ['level' => 'full']]));

    // A new role of this community's, standing for the shared one: its label, its place, its lineage.
    expect($version->id)->not->toBe($shared->id)
        ->and($version->tenant_id)->toBe(VERSION_ESTATE_A)
        ->and($version->version_of_id)->toBe($shared->id)
        ->and($version->copied_from_id)->toBe($shared->id)
        ->and($version->label)->toBe('Treasurer')
        ->and($version->sort)->toBe($shared->sort)
        ->and($version->is_system)->toBeFalse()
        ->and($version->standsFor())->toBe(Role::TREASURER)
        ->and($version->isEstateVersion())->toBeTrue();

    // What it holds: everything the shared role holds, and the change.
    expect(versionPermissions($version))->toBe(collect([...$sharedBefore, 'estate.governance.create', 'estate.governance.update', 'estate.governance.delete', 'estate.governance.export', 'estate.governance.configure'])->unique()->sort()->values()->all());

    // The shared role, which every other community holds, did not move.
    expect(versionPermissions($shared))->toBe($sharedBefore);

    // This community's Treasurer holds the version now — the assignment and the role both — and can do the new thing.
    expect(EstateAssignment::query()->where('user_id', $treasurerA->id)->where('tenant_id', VERSION_ESTATE_A)->value('role_id'))->toBe($version->id)
        ->and($treasurerA->fresh()->hasRole($version->name))->toBeTrue()
        ->and($treasurerA->fresh()->hasRole(Role::TREASURER))->toBeFalse()
        ->and(versionCan($treasurerA, 'estate.governance.create'))->toBeTrue();

    // The other community's Treasurer is exactly where they were.
    expect(EstateAssignment::query()->where('user_id', $treasurerB->id)->where('tenant_id', VERSION_ESTATE_B)->value('role_id'))->toBe($shared->id)
        ->and($treasurerB->fresh()->hasRole(Role::TREASURER))->toBeTrue()
        ->and(versionCan($treasurerB, 'estate.governance.create'))->toBeFalse();

    // Audited: the version made, then the change to it.
    $made = AuditEntry::query()->where('action', 'role.version_created')->where('entity_id', $version->name)->first();
    $changed = AuditEntry::query()->where('action', 'role.permissions_changed')->where('entity_id', $version->name)->first();

    expect($made)->not->toBeNull()
        ->and($made->tenant_id)->toBe(VERSION_ESTATE_A)
        ->and($made->after['version_of'])->toBe(Role::TREASURER)
        ->and($made->after['holders_moved'])->toBe(1)
        ->and($changed)->not->toBeNull();

    // A second change edits the same version: one version per community.
    $again = inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $shared, ['security' => ['level' => 'view']]));

    expect($again->id)->toBe($version->id)
        ->and(Role::query()->where('version_of_id', $shared->id)->where('tenant_id', VERSION_ESTATE_A)->count())->toBe(1);
});

it('shows the version in the shared role\'s place on this community\'s board, and nowhere else', function () {
    $shared = Role::named(Role::TREASURER);
    $admin = versionUser(Role::named(Role::COMMUNITY_SUPER_ADMIN), VERSION_ESTATE_A);
    $adminB = versionUser(Role::named(Role::COMMUNITY_SUPER_ADMIN), VERSION_ESTATE_B);

    // Before any change: the shared role, editable here (the first change makes the version).
    $row = collect(inVersionEstate(VERSION_ESTATE_A, fn () => versions()->board($admin))['roles'])->firstWhere('name', Role::TREASURER);

    expect($row['can_edit_permissions'])->toBeTrue()
        ->and($row['is_shared'])->toBeTrue()
        ->and($row['is_version'])->toBeFalse()
        ->and($row['can_reset'])->toBeFalse();

    $version = inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $shared, ['governance' => ['level' => 'full']]));

    $roles = collect(inVersionEstate(VERSION_ESTATE_A, fn () => versions()->board($admin))['roles']);
    $treasurers = $roles->where('label', 'Treasurer')->values();

    // One Treasurer here, and it is the version, in the shared role's place among the platform's roles.
    expect($treasurers)->toHaveCount(1)
        ->and($treasurers[0]['name'])->toBe($version->name)
        ->and($treasurers[0]['is_version'])->toBeTrue()
        ->and($treasurers[0]['stands_for'])->toBe(Role::TREASURER)
        ->and($treasurers[0]['can_reset'])->toBeTrue()
        ->and($treasurers[0]['can_rename'])->toBeFalse()
        ->and($treasurers[0]['can_delete'])->toBeFalse()
        ->and($roles->pluck('name')->all())->not->toContain(Role::TREASURER)
        ->and($roles->search(fn (array $r): bool => $r['name'] === $version->name))
        ->toBeLessThan($roles->search(fn (array $r): bool => ! $r['is_system'] && ! $r['is_version']) ?: PHP_INT_MAX);

    // The other community still sees the shared role, unchanged, and not this community's version.
    $rolesB = collect(inVersionEstate(VERSION_ESTATE_B, fn () => versions()->board($adminB))['roles']);

    expect($rolesB->pluck('name')->all())->toContain(Role::TREASURER)
        ->and($rolesB->pluck('name')->all())->not->toContain($version->name);

    // Finding the shared role by name from this community answers with the version — the people-facing lookups too.
    expect(inVersionEstate(VERSION_ESTATE_A, fn () => versions()->find($admin, Role::TREASURER))->id)->toBe($version->id)
        ->and(inVersionEstate(VERSION_ESTATE_A, fn () => versions()->assignableNamed($admin, Role::TREASURER))->id)->toBe($version->id)
        ->and(inVersionEstate(VERSION_ESTATE_A, fn () => versions()->assignable($admin))->pluck('id')->all())->not->toContain($shared->id)
        ->and(inVersionEstate(VERSION_ESTATE_B, fn () => versions()->find($adminB, Role::TREASURER))->id)->toBe($shared->id);
});

it('puts this community\'s people back on the shared role and removes the version on "Reset to GeminiSecure default"', function () {
    $shared = Role::named(Role::TREASURER);
    $sharedBefore = versionPermissions($shared);
    $admin = versionUser(Role::named(Role::COMMUNITY_SUPER_ADMIN), VERSION_ESTATE_A);
    $treasurer = versionUser($shared, VERSION_ESTATE_A);

    $version = inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $shared, ['governance' => ['level' => 'full']]));

    // An invitation into the Treasurer, sent while the version stands, names the version.
    $invitation = Invitation::query()->create([
        'email' => 'next-treasurer@roleversiona.test', 'tenant_id' => VERSION_ESTATE_A, 'role_id' => $version->id,
        'invited_by' => $admin->id, 'token' => str_repeat('a', 64), 'expires_at' => now()->addDay(),
    ]);

    $back = inVersionEstate(VERSION_ESTATE_A, fn () => versions()->resetToDefault($admin, $version));

    expect($back->id)->toBe($shared->id)
        ->and(Role::query()->whereKey($version->id)->exists())->toBeFalse()
        ->and(EstateAssignment::query()->where('user_id', $treasurer->id)->where('tenant_id', VERSION_ESTATE_A)->value('role_id'))->toBe($shared->id)
        ->and($treasurer->fresh()->hasRole(Role::TREASURER))->toBeTrue()
        ->and(versionCan($treasurer, 'estate.governance.create'))->toBeFalse()
        ->and($invitation->fresh()->role_id)->toBe($shared->id)
        ->and(versionPermissions($shared))->toBe($sharedBefore);

    $reset = AuditEntry::query()->where('action', 'role.version_reset')->where('entity_id', $version->name)->first();

    expect($reset)->not->toBeNull()
        ->and($reset->before)->not->toBeNull()
        ->and($reset->after['back_on'])->toBe(Role::TREASURER);

    // With no version, there is nothing to reset: the shared role is at GeminiSecure's default here.
    expect(inVersionEstate(VERSION_ESTATE_A, fn () => versions()->refusal($admin, $shared, 'reset')))->not->toBeNull();
});

it('seats a new member of this community in the version when they are given the shared role by name', function () {
    $shared = Role::named(Role::TREASURER);
    $admin = versionUser(Role::named(Role::COMMUNITY_SUPER_ADMIN), VERSION_ESTATE_A);
    $secretary = versionUser(Role::named(Role::SECRETARY), VERSION_ESTATE_A);

    $version = inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $shared, ['governance' => ['level' => 'full']]));

    $assignment = EstateAssignment::query()->where('user_id', $secretary->id)->where('tenant_id', VERSION_ESTATE_A)->firstOrFail();

    inVersionEstate(VERSION_ESTATE_A, fn () => app(Settings::class)->manageUser($assignment, Role::TREASURER, 'active', $admin));

    expect($assignment->fresh()->role_id)->toBe($version->id)
        ->and($secretary->fresh()->hasRole($version->name))->toBeTrue();
});

it('keeps every guard rail on the version: nobody takes away what they do not hold, and a refused change makes no version', function () {
    $shared = Role::named(Role::TREASURER);
    $csa = Role::named(Role::COMMUNITY_SUPER_ADMIN);
    $admin = versionUser($csa, VERSION_ESTATE_A);
    versionUser($shared, VERSION_ESTATE_A);

    /*
     * Since D-230 the Community Super Admin holds every power in the estate, so the rail is shown with one who narrowed
     * their own role in this community: no reversing posted months any more (B3), so none to take from the Treasurer.
     */
    inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $csa, ['dues_ledger' => ['level' => 'view']]));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $narrowed = $admin->fresh();

    expect(fn () => inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($narrowed, $shared, ['dues_ledger' => ['level' => 'view']])))
        ->toThrow(DomainException::class);

    expect(Role::query()->where('version_of_id', $shared->id)->exists())->toBeFalse();
});

it('closes the money to a version of the Property Manager, as Ruling 1 closes it to the Property Manager', function () {
    $shared = Role::named(Role::PROPERTY_MANAGER);
    $admin = versionUser(Role::named(Role::COMMUNITY_SUPER_ADMIN), VERSION_ESTATE_A);

    $version = inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $shared, ['governance' => ['level' => 'view']]));

    expect($version->derivesFrom(Role::PROPERTY_MANAGER))->toBeTrue();

    expect(fn () => inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $version, ['dues_ledger' => ['level' => 'view']])))
        ->toThrow(DomainException::class);

    expect(versionPermissions($version))->not->toContain('estate.dues_ledger.view');
});

it('keeps a version of the Community Super Admin able to restore everything, and its last holder the administrator', function () {
    $shared = Role::named(Role::COMMUNITY_SUPER_ADMIN);
    $admin = versionUser($shared, VERSION_ESTATE_A);

    $version = inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $shared, ['estate_structure' => ['level' => 'view']]));

    expect($version->standsFor())->toBe(Role::COMMUNITY_SUPER_ADMIN)
        ->and(versionCan($admin, 'estate.settings.approve'))->toBeTrue();

    // Settings' Full · Approver is the cell that lets somebody put every other role back.
    expect(fn () => inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $version, ['settings' => ['level' => 'full']])))
        ->toThrow(DomainException::class);

    // The only holder of the version is the estate's last administrator: the guard that keeps one reads the version as
    // the Community Super Admin it stands for. (Asked directly: every way of reaching it from the screen is refused
    // first by "you cannot change your own role", the only other person who could ask being them.)
    $assignment = EstateAssignment::query()->with(['role', 'user'])->where('user_id', $admin->id)->where('tenant_id', VERSION_ESTATE_A)->firstOrFail();
    $isLast = new ReflectionMethod(Settings::class, 'isLastSuperAdmin');

    expect($assignment->role_id)->toBe($version->id)
        ->and($isLast->invoke(app(Settings::class), $assignment))->toBeTrue();

    // With a second holder of the version, the first is not the last.
    $other = versionUser($version, VERSION_ESTATE_A, 'b');

    expect($other->fresh()->hasRole($version->name))->toBeTrue()
        ->and($isLast->invoke(app(Settings::class), $assignment->fresh(['role', 'user'])))->toBeFalse();
});

it('resets through the console\'s own route, behind the power to change roles, and redraws the shared role in its place', function () {
    $this->withoutVite();

    $shared = Role::named(Role::TREASURER);
    $admin = versionUser(Role::named(Role::COMMUNITY_SUPER_ADMIN), VERSION_ESTATE_A);
    $president = versionUser(Role::named(Role::PRESIDENT), VERSION_ESTATE_A);
    $url = static fn (string $path): string => 'http://'.VERSION_ESTATE_A.'.'.config('app.estate_domain').$path;

    // The first change, through the screen's own route.
    $this->actingAs($admin)
        ->post($url('/settings/roles/'.$shared->id.'/permissions'), ['grid' => ['governance' => ['level' => 'full']]])
        ->assertSessionHasNoErrors();

    $version = Role::query()->where('tenant_id', VERSION_ESTATE_A)->where('version_of_id', $shared->id)->sole();

    // Nobody without the power to change roles reaches the reset at all.
    $this->actingAs($president)->post($url('/settings/roles/'.$version->id.'/reset'))->assertForbidden();

    expect(Role::query()->whereKey($version->id)->exists())->toBeTrue();

    $this->actingAs($admin)
        ->post($url('/settings/roles/'.$version->id.'/reset'))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(Role::query()->whereKey($version->id)->exists())->toBeFalse();
});

it('still texts a version of the estate\'s Head of Security on a panic', function () {
    $shared = Role::named(Role::ESTATE_HEAD_OF_SECURITY);
    $admin = versionUser(Role::named(Role::COMMUNITY_SUPER_ADMIN), VERSION_ESTATE_A);

    // The Head of Security's Governance is View; the Community Super Admin holds it in full.
    $version = inVersionEstate(VERSION_ESTATE_A, fn () => versions()->setPermissions($admin, $shared, ['governance' => ['level' => 'full']]));

    expect(SiteEscalation::estateHeadOfSecurityRoles(VERSION_ESTATE_A))->toContain($version->id)
        ->and(SiteEscalation::estateHeadOfSecurityRoles(VERSION_ESTATE_B))->not->toContain($version->id)
        ->and(SiteEscalation::estateHeadOfSecurityRoles(VERSION_ESTATE_B))->toContain($shared->id);
});
