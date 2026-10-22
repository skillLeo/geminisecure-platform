<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Estate\Settings;
use App\Services\Gemini\PlatformAdmins;
use App\Services\Rbac\RoleEditor;
use Database\Seeders\Estate\SettingsSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The estate's role editor, through the routes a committee actually uses
|--------------------------------------------------------------------------
|
| M10 §4 on the Estate Console: the Community Super Admin creates, copies,
| renames, re-grants and deletes this community's own roles from board 24, and
| assigns, invites and removes people — administrators among them — from
| board 22. `RolesAreDynamicTest` proves `RoleEditor`'s guard rails by calling
| it; this file proves the SAME rails hold when the request comes through the
| router, the `can:` gate, the controller and its validation, which is the
| only way a committee ever reaches them. A rail that held for a direct call
| and not for a POST would be a rail the screen walks straight past.
|
| So every write here is an HTTP request, and every refusal is read back as the
| sentence the screen will print — the one the service threw, unreworded — and
| then checked against the rows, because a refusal and a success both answer
| 302 and only the rows say which one happened.
|
| TWO ESTATES OF THIS FILE'S OWN, named from the configured tenancy prefix like
| every fixture's, because half of what is under test is that one cannot reach
| the other's roles. Everything central a test writes is rolled back after it.
|
*/

const ROLES_EDITOR_A = 'roleseditora';
const ROLES_EDITOR_B = 'roleseditorb';

/**
 * The matrix, seeded on the NEWEST CELL rather than on a role existing — the
 * Community Super Admin's Approver tag on Settings, which is what this whole
 * file is about. A database seeded before M10 §4 has every role already and
 * would refuse everything below for a reason that is not the one under test.
 */
function rolesEditorPlatform(): void
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
function rolesEditorEstate(string $key): Tenant
{
    static $provisioned = [];

    if (isset($provisioned[$key])) {
        return Tenant::findOrFail($key);
    }

    rolesEditorPlatform();

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
        'name' => $key === ROLES_EDITOR_A ? 'Editor Estate A' : 'Editor Estate B',
        'receipt_prefix' => $key === ROLES_EDITOR_A ? 'REA' : 'REB',
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
function inRolesEditorEstate(string $key, callable $work): mixed
{
    try {
        return rolesEditorEstate($key)->run($work);
    } finally {
        tenancy()->end();
    }
}

/**
 * A user of one estate holding exactly one role — shared or the estate's own —
 * with the assignment that lets them reach it. `$who` tells two holders of one
 * role apart.
 */
function rolesEditorUser(string|Role $role, string $estate, string $who = 'a'): User
{
    $role = $role instanceof Role ? $role : Role::named($role);

    $email = 'editor-'.str_replace('.', '-', $role->name).'-'.$who.'@'.$estate.'.test';

    $user = User::query()->firstOrNew(['email' => $email]);

    $user->fill([
        'name' => ucwords(str_replace(['estate.', 'custom.', $estate.'.', '.', '_'], ['', '', '', ' ', ' '], $role->name)).' '.strtoupper($who),
        'password' => 'password',
        'console' => Console::Estate->value,
        'status' => 'active',
    ]);

    /*
     * WITH A PROVEN ALERT ADDRESS (M10 §1.4), the way EstateSettingsTest's and
     * RolesAreDynamicTest's fixtures do it. Every estate account meets the
     * contact gate until it has one, and this file is about the role editor,
     * not about the gate, which ContactVerificationGateTest covers. Both columns
     * in one save, so the model keeps the proof rather than clearing it as a
     * change of address.
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

function rolesEditorUrl(string $estate, string $path): string
{
    return 'http://'.$estate.'.'.config('app.estate_domain').$path;
}

function rolesEditorAssignment(User $user, string $estate): EstateAssignment
{
    return EstateAssignment::query()->where('user_id', $user->id)->where('tenant_id', $estate)->sole();
}

/**
 * The sentence a refused write came back with — asserted to BE a refusal
 * first, because a redirect alone could as easily be a success.
 */
function rolesEditorRefusal(TestResponse $response, string $key = 'role'): string
{
    $response->assertStatus(302)->assertSessionHasErrors($key);

    return (string) session('errors')->first($key);
}

/** @return array<string, mixed> the Inertia props a GET answered with */
function rolesEditorProps(TestResponse $response): array
{
    $props = [];

    $response->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** @return list<string> */
function rolesEditorCompiled(Role $role): array
{
    return $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
}

/** One role's cells and compiled permissions, as a string two reads can compare. */
function rolesEditorFingerprint(Role $role): string
{
    $role = $role->fresh();

    return $role->label.'|'.RoleModuleAccess::query()->where('role_id', $role->id)->orderBy('module_id')->get()
        ->map(static fn (RoleModuleAccess $c): string => $c->module_id.':'.$c->level->value.':'.json_encode($c->verbs).':'.(int) $c->can_approve)
        ->implode(',').'|'.implode(',', rolesEditorCompiled($role));
}

/** The role of this estate's own with this label, made over HTTP. */
function rolesEditorOwn(string $estate, string $label): Role
{
    return Role::query()->where('tenant_id', $estate)->where('label', $label)->sole();
}

beforeEach(function () {
    rolesEditorEstate(ROLES_EDITOR_A);
    rolesEditorEstate(ROLES_EDITOR_B);

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
/* the structural minimum says why it is locked (M62 B5, Law 1) */
/* ------------------------------------------------------------------ */

it('says in its own words why the Super Admin\'s Settings cell cannot be lowered, not the Property Manager\'s note', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);

    $editor = rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles')))['editor'];
    $cell = collect($editor['roles'])->firstWhere('name', Role::COMMUNITY_SUPER_ADMIN)['cells']['settings'];

    expect($cell['minimum'])->toBeTrue()
        ->and($cell['locked_reason'])->toBe('Community Super Admin keeps Full and Approver on Settings, so somebody can always change what every role here may do.');
});

/* ------------------------------------------------------------------ */
/* the five acts, each through its own route */
/* ------------------------------------------------------------------ */

it('lets the Community Super Admin create, re-grant, rename, copy and delete a role of the estate\'s own over the real routes', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);

    // CREATE — opened on its own permissions page straight after, because a role
    // nobody has given anything is only half made.
    $response = $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), ['label' => 'Gardens Officer']);
    $role = rolesEditorOwn(ROLES_EDITOR_A, 'Gardens Officer');

    $response->assertSessionHasNoErrors()
        ->assertRedirect(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id.'/permissions'))
        ->assertSessionHas('success');

    expect($role->name)->toBe('estate.custom.'.ROLES_EDITOR_A.'.gardens_officer')
        ->and($role->is_system)->toBeFalse()
        ->and($role->created_by_id)->toBe($admin->id)
        ->and(rolesEditorCompiled($role))->toBe([]);

    // RE-GRANT — a preset, a per-action cell and an Approver tag the admin holds.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id.'/permissions'), [
        'grid' => [
            'facilities' => ['level' => 'entry'],
            'reports' => ['level' => 'custom', 'verbs' => ['view', 'export']],
            'residents' => ['level' => 'view', 'can_approve' => true],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'));

    expect(rolesEditorCompiled($role))->toBe([
        'estate.facilities.create',
        'estate.facilities.update',
        'estate.facilities.view',
        'estate.reports.export',
        'estate.reports.view',
        'estate.residents.approve',
        'estate.residents.view',
    ]);

    // RENAME — the label moves and the machine name does not, whatever the
    // body says about it.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id), [
        'label' => 'Grounds Officer',
        'name' => 'estate.treasurer',
    ])->assertSessionHasNoErrors()->assertRedirect(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'));

    expect($role->fresh()->label)->toBe('Grounds Officer')
        ->and($role->fresh()->name)->toBe('estate.custom.'.ROLES_EDITOR_A.'.gardens_officer');

    // COPY — of a shared role, whole: the Community Super Admin holds everything the Treasurer does since M55, so
    // nothing is left out, and the flash says so.
    $treasurer = Role::named(Role::TREASURER);

    $response = $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$treasurer->id.'/copy'), ['label' => 'Our Treasurer']);
    $copy = rolesEditorOwn(ROLES_EDITOR_A, 'Our Treasurer');

    $response->assertSessionHasNoErrors()
        ->assertRedirect(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$copy->id.'/permissions'));

    expect(session('success'))->toContain('Our Treasurer made from Treasurer.')
        ->and(session('success'))->toContain('Nothing was left out: it holds everything the original does.')
        ->and($copy->copied_from_id)->toBe($treasurer->id)
        ->and(rolesEditorCompiled($copy))->toContain('estate.payments.update')
        ->and(rolesEditorCompiled($copy))->toContain('estate.payments.approve');

    // A copy with no name given is called what it is a copy of.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id.'/copy'))->assertSessionHasNoErrors();

    expect(rolesEditorOwn(ROLES_EDITOR_A, 'Copy of Grounds Officer')->copied_from_id)->toBeNull();

    // DELETE — nobody holds it and no record names it.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id.'/delete'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'));

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
        ->and(RoleModuleAccess::query()->where('role_id', $role->id)->exists())->toBeFalse();

    // One audit row per act, each naming the admin and the role.
    $actions = AuditEntry::query()
        ->where('entity_type', 'Role')
        ->where('entity_id', $role->name)
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($actions)->toBe(['role.created', 'role.permissions_changed', 'role.renamed', 'role.deleted'])
        ->and(AuditEntry::query()->where('entity_id', $role->name)->where('actor_id', '!=', $admin->id)->exists())->toBeFalse();
});

it('changes what a holder of the role may reach on their very next request, with nobody clearing a cache', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), ['label' => 'Profile Keeper'])->assertSessionHasNoErrors();
    $role = rolesEditorOwn(ROLES_EDITOR_A, 'Profile Keeper');
    $keeper = rolesEditorUser($role, ROLES_EDITOR_A);

    // Made with nothing, so it reaches nothing.
    $this->actingAs($keeper)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/profile'))->assertForbidden();

    // GAINS the page: granted from the editor, and the next request opens it.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id.'/permissions'), [
        'grid' => ['settings' => ['level' => 'view']],
    ])->assertSessionHasNoErrors();

    // No forgetCachedPermissions() here: RoleEditor must have done it.
    $this->actingAs($keeper->fresh())->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/profile'))->assertOk();

    // LOSES it again the same way.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id.'/permissions'), [
        'grid' => ['settings' => ['level' => 'none']],
    ])->assertSessionHasNoErrors();

    $this->actingAs($keeper->fresh())->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/profile'))->assertForbidden();
});

/* ------------------------------------------------------------------ */
/* every refusal, in its own words */
/* ------------------------------------------------------------------ */

it('answers every refused role write with the sentence the editor refused it with, and writes nothing', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), ['label' => 'Gate Liaison'])->assertSessionHasNoErrors();
    $own = rolesEditorOwn(ROLES_EDITOR_A, 'Gate Liaison');

    $roles = Role::query()->count();
    $ownBefore = rolesEditorFingerprint($own);

    $create = fn (array $body): TestResponse => $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), $body);
    $grant = fn (array $grid): TestResponse => $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$own->id.'/permissions'), ['grid' => $grid]);

    // The name.
    expect(rolesEditorRefusal($create(['label' => '   '])))
        ->toBe('A role needs a name people will recognise. It is what the sidebar and every list of users print.')
        ->and(rolesEditorRefusal($create(['label' => str_repeat('x', RoleEditor::LABEL_MAX + 1)])))
        ->toBe('Keep a role\'s name to 60 characters. It is printed in narrow places.')
        ->and(rolesEditorRefusal($create(['label' => 'treasurer'])))
        ->toBe('There is already a role called Treasurer here. Two roles with one name would make every list of users ambiguous.');

    // The cells.
    expect(rolesEditorRefusal($grant(['residents' => ['level' => 'owner']])))
        ->toBe('"owner" is not a level. A Residents cell is none, view, entry, full or custom.')
        ->and(rolesEditorRefusal($grant(['residents' => ['level' => 'custom', 'verbs' => ['export'], 'can_approve' => true]])))
        ->toBe('Give Residents at least view before tagging it Approver. Nobody can approve what they cannot open.')
        ->and(rolesEditorRefusal($grant(['everything' => ['level' => 'full']])))
        ->toBe('There is no "everything" module on the Estate Console. A role here can only be given this console\'s own modules.');

    // A role somebody holds.
    rolesEditorUser($own, ROLES_EDITOR_A);

    expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$own->id.'/delete'))))
        ->toBe('1 person holds Gate Liaison. Move them to another role first: deleting it would leave them with no role and no access.');

    // Ruling 1, on a copy of the Property Manager.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.Role::named(Role::PROPERTY_MANAGER)->id.'/copy'), ['label' => 'Works Officer'])
        ->assertSessionHasNoErrors();
    $works = rolesEditorOwn(ROLES_EDITOR_A, 'Works Officer');

    expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$works->id.'/permissions'), [
        'grid' => ['dues_ledger' => ['level' => 'view']],
    ])))->toBe('Dues & ledger is closed to the Property Manager, and to any role copied from it, by platform invariant: whoever commissions work must never be able to pay for it, nor see a resident\'s financial position.');

    // Nothing moved: no role made, and the estate's own role exactly as it was.
    expect(Role::query()->count())->toBe($roles + 1)
        ->and(rolesEditorFingerprint($own))->toBe($ownBefore)
        ->and(rolesEditorCompiled($works))->not->toContain('estate.dues_ledger.view');
});

it('refuses the Community Super Admin any permission their own role does not hold, however the request is shaped', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $roles = Role::query()->count();

    /*
     * THE CLEAN CASE: the Community Super Admin holds Security at Full with no
     * Approver tag, so `estate.security.approve` is exactly one permission they
     * cannot give — whether asked for by the tag or smuggled in the list.
     */
    foreach ([
        ['level' => 'full', 'can_approve' => true],
        ['level' => 'custom', 'verbs' => ['view', 'approve']],
    ] as $cell) {
        expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), [
            'label' => 'Night Watch',
            'grid' => ['security' => $cell],
        ])))->toBe('You do not hold Security · approve yourself, so you cannot give it to a role.');
    }

    expect(Role::query()->count())->toBe($roles);

    // THE POSITIVE CONTROL: the same actor CAN give Security at Full, which
    // they do hold, or the refusal above proves only that nothing works.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), [
        'label' => 'Night Watch',
        'grid' => ['security' => ['level' => 'full']],
    ])->assertSessionHasNoErrors();

    expect(rolesEditorCompiled(rolesEditorOwn(ROLES_EDITOR_A, 'Night Watch')))->toContain('estate.security.configure')
        ->and(rolesEditorCompiled(rolesEditorOwn(ROLES_EDITOR_A, 'Night Watch')))->not->toContain('estate.security.approve');
});

it('never gives an estate role a Gemini permission, even when the request names the Gemini module, the console or another estate', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), ['label' => 'Payroll Watcher'])->assertSessionHasNoErrors();
    $role = rolesEditorOwn(ROLES_EDITOR_A, 'Payroll Watcher');

    $payroll = (string) Module::query()->where('console', 'gemini')->where('key', 'payroll_accounting')->value('label');

    // The security company's payroll, by its bare key and by its permission prefix.
    foreach (['payroll_accounting', 'gemini.payroll_accounting'] as $key) {
        expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id.'/permissions'), [
            'grid' => [$key => ['level' => 'view']],
        ])))->toBe($payroll.' belongs to the Gemini Console. A role on this estate can only be given this estate\'s own modules, and can never reach the security company\'s records or any other community.');
    }

    // Nor at creation, nor for any other Gemini module.
    foreach (['guard_workforce', 'clients'] as $key) {
        expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), [
            'label' => 'Company Watcher',
            'grid' => [$key => ['level' => 'view']],
        ])))->toContain('belongs to the Gemini Console');
    }

    /*
     * A BODY THAT NAMES A CONSOLE OR A TENANT IS NOT VALIDATED — IT IS NEVER
     * READ. The role is still this estate's, on this console.
     */
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), [
        'label' => 'Stowaway',
        'console' => 'gemini',
        'tenant_id' => ROLES_EDITOR_B,
        'scope_default' => 'all',
    ])->assertSessionHasNoErrors();

    $stowaway = rolesEditorOwn(ROLES_EDITOR_A, 'Stowaway');

    expect($stowaway->console)->toBe(Console::Estate)
        ->and($stowaway->tenant_id)->toBe(ROLES_EDITOR_A)
        ->and(rolesEditorCompiled($role))->toBe([]);

    // And across the compiled table `can:` reads, no estate role holds a Gemini permission.
    $crossed = DB::connection('mysql')->table('role_has_permissions')
        ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('roles.console', 'estate')
        ->where('permissions.name', 'like', 'gemini.%')
        ->count();

    expect($crossed)->toBe(0);
});

it('lets the estate change any of the eight shared roles as its own version, and never renames or deletes one', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);

    $shared = Role::query()->where('console', 'estate')->where('is_system', true)->orderBy('sort')->get();

    expect($shared)->toHaveCount(8);

    foreach ($shared as $role) {
        $before = rolesEditorFingerprint($role);

        // The sentence each act is refused with is the one RoleEditor gives the
        // board for that role — the words the screen already printed.
        [$permissions, $rename, $delete] = inRolesEditorEstate(ROLES_EDITOR_A, fn (): array => [
            app(RoleEditor::class)->refusal($admin, $role, 'permissions'),
            app(RoleEditor::class)->refusal($admin, $role, 'rename'),
            app(RoleEditor::class)->refusal($admin, $role, 'delete'),
        ]);

        // EDITABLE HERE (M11 B4): the first change makes this community's own version of it,
        // which EstateEditsASharedRoleTest follows through. Its name and its existence stay the platform's.
        expect($permissions)->toBeNull()
            ->and($rename)->toContain('cannot be renamed')
            ->and($rename)->toContain('the first change makes this community\'s own version of it')
            ->and($delete)->toContain('cannot be deleted');

        expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id), ['label' => 'Renamed '.$role->label])))->toBe($rename)
            ->and(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$role->id.'/delete'))))->toBe($delete);

        // The shared role every other community holds is exactly as it was.
        expect(rolesEditorFingerprint($role))->toBe($before);
    }

    expect(Role::named(Role::COMMUNITY_SUPER_ADMIN)->label)->toBe('Community Super Admin');
});

/* ------------------------------------------------------------------ */
/* who may reach the routes at all */
/* ------------------------------------------------------------------ */

it('answers 403 to the seven other shared estate roles on every role write and on removing a person', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), ['label' => 'Target Role'])->assertSessionHasNoErrors();
    $target = rolesEditorOwn(ROLES_EDITOR_A, 'Target Role');
    $before = rolesEditorFingerprint($target);
    $roles = Role::query()->count();

    $secretary = rolesEditorUser(Role::SECRETARY, ROLES_EDITOR_A, 'target');
    $assignment = rolesEditorAssignment($secretary, ROLES_EDITOR_A);

    $writes = [
        ['/settings/roles', ['label' => 'Intruder']],
        ['/settings/roles/'.$target->id.'/copy', ['label' => 'Intruder copy']],
        ['/settings/roles/'.$target->id, ['label' => 'Taken over']],
        ['/settings/roles/'.$target->id.'/permissions', ['grid' => ['dashboard' => ['level' => 'view']]]],
        ['/settings/roles/'.$target->id.'/delete', []],
        ['/settings/users/'.$assignment->id.'/remove', []],
    ];

    $others = [
        Role::PRESIDENT, Role::VICE_PRESIDENT, Role::SECRETARY, Role::PROPERTY_MANAGER,
        Role::TREASURER, Role::ESTATE_ADMIN_ASSISTANT, Role::ESTATE_HEAD_OF_SECURITY,
    ];

    foreach ($others as $roleName) {
        $user = rolesEditorUser($roleName, ROLES_EDITOR_A);

        foreach ($writes as [$path, $body]) {
            $this->actingAs($user)->post(rolesEditorUrl(ROLES_EDITOR_A, $path), $body)->assertForbidden();
        }
    }

    expect(Role::query()->count())->toBe($roles)
        ->and(rolesEditorFingerprint($target))->toBe($before)
        ->and($assignment->fresh()->is_active)->toBeTrue();
});

it('refuses a role id from another estate in exactly the words it refuses one that does not exist', function () {
    $adminA = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $adminB = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_B);

    $this->actingAs($adminB)->post(rolesEditorUrl(ROLES_EDITOR_B, '/settings/roles'), [
        'label' => 'Their Officer',
        'grid' => ['facilities' => ['level' => 'view']],
    ])->assertSessionHasNoErrors();

    $theirs = rolesEditorOwn(ROLES_EDITOR_B, 'Their Officer');
    $before = rolesEditorFingerprint($theirs);
    $missing = (int) Role::query()->max('id') + 1000;
    $gemini = Role::named(Role::DIRECTOR)->id;

    foreach ([$theirs->id, $missing, $gemini] as $id) {
        foreach ([
            ['/settings/roles/'.$id, ['label' => 'Taken over']],
            ['/settings/roles/'.$id.'/permissions', ['grid' => ['residents' => ['level' => 'full']]]],
            ['/settings/roles/'.$id.'/copy', ['label' => 'Borrowed']],
            ['/settings/roles/'.$id.'/delete', []],
        ] as [$path, $body]) {
            expect(rolesEditorRefusal($this->actingAs($adminA)->post(rolesEditorUrl(ROLES_EDITOR_A, $path), $body)))
                ->toBe(RoleEditor::NOT_HERE_ESTATE);
        }
    }

    // Estate B's role is exactly as B left it, and estate A made nothing.
    expect(rolesEditorFingerprint($theirs))->toBe($before)
        ->and(Role::query()->where('tenant_id', ROLES_EDITOR_A)->exists())->toBeFalse();

    // Nor can its NAME be invited into, or assigned, from estate A.
    $secretary = rolesEditorUser(Role::SECRETARY, ROLES_EDITOR_A);

    expect(rolesEditorRefusal($this->actingAs($adminA)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/invitations'), [
        'email' => 'borrowed@roleseditora.test',
        'role' => $theirs->name,
    ])))->toBe(RoleEditor::NOT_HERE_ESTATE);

    $this->actingAs($adminA)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.rolesEditorAssignment($secretary, ROLES_EDITOR_A)->id), [
        'role' => $theirs->name,
        'status' => 'active',
    ])->assertSessionHasErrors('role');

    expect(Invitation::query()->where('email', 'borrowed@roleseditora.test')->exists())->toBeFalse()
        ->and($secretary->fresh()->hasRole($theirs->name))->toBeFalse();

    // And the matrix screen of estate A does not list it.
    $props = rolesEditorProps($this->actingAs($adminA)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles')));

    expect(array_column($props['roles'], 'name'))->not->toContain($theirs->name)
        ->and(array_column($props['editor']['roles'], 'name'))->not->toContain($theirs->name);
});

/* ------------------------------------------------------------------ */
/* board 24 says, before the press, what the server will say after */
/* ------------------------------------------------------------------ */

it('draws the editor live for the Community Super Admin alone, with every reason it will need to print', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $president = rolesEditorUser(Role::PRESIDENT, ROLES_EDITOR_A);
    $treasurer = Role::named(Role::TREASURER);

    $props = rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles')));
    $editor = $props['editor'];
    $row = collect($editor['roles'])->firstWhere('name', Role::TREASURER);

    expect($props['can_edit'])->toBeTrue()
        ->and($editor['can_edit'])->toBeTrue()
        ->and($editor['modules'])->toHaveCount(14)
        ->and($editor['grantable']['security']['approve'])->toBeFalse()
        ->and($editor['grantable']['security']['configure'])->toBeTrue()
        ->and($editor['grantable_reason'])->toBe('You can only give a role, or take from it, what your own role holds.')
        // A shared role is edited here as this community's own (M11 B4), and its note says what that does.
        ->and($row['can_edit_permissions'])->toBeTrue()
        ->and($row['permissions_refusal'])->toBeNull()
        ->and($row['note'])->toContain('makes this community\'s own version of it')

        // What a copy would leave out, named before the copy: nothing, since the Community Super Admin holds every
        // approval (M55), the Treasurer's included.
        ->and($editor['copy_withheld'][(string) $treasurer->id] ?? [])->toBe([])

        // The position rule, and the three money modules it is asked about.
        ->and($editor['position_rule']['money'])->toBe(['dues_ledger', 'payments', 'accounting_posting'])
        ->and($editor['position_rule']['warning'])->toBe(Settings::POSITION_RULE['warning'])
        ->and($props['shared_role_note'])->toBe(Settings::SHARED_ROLE_NOTE);

    // A President reads the same matrix and is told why it cannot change it.
    $props = rolesEditorProps($this->actingAs($president)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles')));

    expect($props['can_edit'])->toBeFalse()
        ->and($props['editor']['can_edit'])->toBeFalse()
        ->and($props['editor']['edit_blocked_reason'])->toContain('the Community Super Admin holds it')
        ->and($props['editor']['copy_withheld'])->toBe([])
        ->and($props['read_only_reason'])->toBe(Settings::MATRIX_READ_ONLY);
});

/* ------------------------------------------------------------------ */
/* board 22 — people, administrators among them */
/* ------------------------------------------------------------------ */

it('assigns a role the estate made, invites into one, and makes and unmakes an administrator', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $secretary = rolesEditorUser(Role::SECRETARY, ROLES_EDITOR_A);
    $assignment = rolesEditorAssignment($secretary, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), [
        'label' => 'Minutes Clerk',
        'grid' => ['governance' => ['level' => 'entry']],
    ])->assertSessionHasNoErrors();
    $clerkRole = rolesEditorOwn(ROLES_EDITOR_A, 'Minutes Clerk');

    // ASSIGNED — a custom role, from the same Manage panel as a shared one.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id), [
        'role' => $clerkRole->name,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect($secretary->fresh()->hasRole($clerkRole->name))->toBeTrue()
        ->and($assignment->fresh()->role_id)->toBe($clerkRole->id)
        ->and($secretary->fresh()->can('estate.governance.update'))->toBeTrue();

    // INVITED — into the estate's own role, resolved by name through the fence.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/invitations'), [
        'email' => 'clerk2@roleseditora.test',
        'role' => $clerkRole->name,
    ])->assertSessionHasNoErrors();

    expect(Invitation::query()->where('email', 'clerk2@roleseditora.test')->value('role_id'))->toBe($clerkRole->id);

    // MADE AN ADMINISTRATOR — given the role that can change roles.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id), [
        'role' => Role::COMMUNITY_SUPER_ADMIN,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect($secretary->fresh()->can('estate.settings.approve'))->toBeTrue();

    $rows = collect(rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')))['rows'])->keyBy('id');

    expect($rows[$secretary->id]['is_administrator'])->toBeTrue()
        ->and($rows[$secretary->id]['is_last_super_admin'])->toBeFalse()
        ->and($rows[$secretary->id]['manage_refusal'])->toBeNull();

    // UNMADE — given another role, which is how an administrator stops being one.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id), [
        'role' => Role::SECRETARY,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect($secretary->fresh()->can('estate.settings.approve'))->toBeFalse();

    /*
     * ONLY AN ADMINISTRATOR MAKES ONE. A clerk who manages the committee —
     * Settings at Full, without the Approver tag — may seat a Treasurer and may
     * not seat an administrator, and board 22 says so on the role before the
     * press in the words the server refuses with after it.
     */
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), [
        'label' => 'Committee Clerk',
        'grid' => ['settings' => ['level' => 'full']],
    ])->assertSessionHasNoErrors();
    $committeeClerk = rolesEditorUser(rolesEditorOwn(ROLES_EDITOR_A, 'Committee Clerk'), ROLES_EDITOR_A);

    $sentence = 'Community Super Admin can change what every role here may do, so only somebody who can do that themselves may give it to anyone. Your role cannot.';

    expect(rolesEditorRefusal($this->actingAs($committeeClerk)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id), [
        'role' => Role::COMMUNITY_SUPER_ADMIN,
        'status' => 'active',
    ])))->toBe($sentence);

    $props = rolesEditorProps($this->actingAs($committeeClerk)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')));

    expect($props['canAdminister'])->toBeFalse()
        ->and($props['canManage'])->toBeTrue()
        ->and(collect($props['roles'])->firstWhere('name', Role::COMMUNITY_SUPER_ADMIN)['hand_out_refusal'])->toBe($sentence)
        ->and(collect($props['roles'])->firstWhere('name', Role::TREASURER)['hand_out_refusal'])->toBeNull();

    $this->actingAs($committeeClerk)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id), [
        'role' => Role::TREASURER,
        'status' => 'active',
    ])->assertSessionHasNoErrors();

    expect($secretary->fresh()->hasRole(Role::TREASURER))->toBeTrue();
});

it('refuses to remove or demote the last Community Super Admin, and prints the reason on the row before anybody presses', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $adminAssignment = rolesEditorAssignment($admin, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), [
        'label' => 'Committee Clerk',
        'grid' => ['settings' => ['level' => 'full']],
    ])->assertSessionHasNoErrors();
    $clerk = rolesEditorUser(rolesEditorOwn(ROLES_EDITOR_A, 'Committee Clerk'), ROLES_EDITOR_A);

    // Somebody else, with Settings update, asking to remove the last one.
    expect(rolesEditorRefusal($this->actingAs($clerk)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$adminAssignment->id.'/remove')), 'remove'))
        ->toBe(Settings::LAST_SUPER_ADMIN);

    // Or to move them off the role.
    expect(rolesEditorRefusal($this->actingAs($clerk)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$adminAssignment->id), [
        'role' => Role::SECRETARY,
        'status' => 'active',
    ])))->toBe(Settings::LAST_SUPER_ADMIN);

    // The last one asking to remove themselves is told the nearer reason.
    expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$adminAssignment->id.'/remove')), 'remove'))
        ->toBe(Settings::NOT_YOURSELF);

    expect($adminAssignment->fresh()->is_active)->toBeTrue()
        ->and($admin->fresh()->status)->toBe('active')
        ->and($admin->fresh()->can('estate.settings.approve'))->toBeTrue();

    // The screen said all of it first, on the row, to each of them.
    $asClerk = collect(rolesEditorProps($this->actingAs($clerk)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')))['rows'])->keyBy('id');
    $asAdmin = collect(rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')))['rows'])->keyBy('id');

    expect($asClerk[$admin->id]['is_last_super_admin'])->toBeTrue()
        ->and($asClerk[$admin->id]['is_administrator'])->toBeTrue()
        ->and($asClerk[$admin->id]['manage_refusal'])->toBe(Settings::LAST_SUPER_ADMIN)
        ->and($asClerk[$admin->id]['remove_refusal'])->toBe(Settings::LAST_SUPER_ADMIN)
        ->and($asAdmin[$admin->id]['remove_refusal'])->toBe(Settings::NOT_YOURSELF)
        ->and($asAdmin[$admin->id]['is_self'])->toBeTrue()
        ->and($asAdmin[$clerk->id]['remove_refusal'])->toBeNull()
        ->and($asAdmin[$clerk->id]['is_administrator'])->toBeFalse();
});

it('removes a person by deactivating them and keeping every row, and refuses one who is not on this committee in one sentence', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $secretary = rolesEditorUser(Role::SECRETARY, ROLES_EDITOR_A);
    $assignment = rolesEditorAssignment($secretary, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id.'/remove'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users'));

    expect(session('success'))->toContain('is off the committee');

    // REMOVED, NOT DELETED: both rows stay, the access is gone.
    $assignment = EstateAssignment::query()->whereKey($assignment->id)->first();
    $removed = User::query()->whereKey($secretary->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->is_active)->toBeFalse()
        ->and($assignment->role_id)->toBe(Role::named(Role::SECRETARY)->id)
        ->and($removed)->not->toBeNull()
        ->and($removed->status)->toBe('suspended')
        ->and($removed->roles)->toBeEmpty()
        ->and($removed->canAccessEstate(ROLES_EDITOR_A))->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'estate.user_removed')->where('entity_id', (string) $secretary->id)->exists())->toBeTrue();

    // Gone from the committee list, and a second removal is refused.
    $rows = rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')))['rows'];

    expect(array_column($rows, 'id'))->not->toContain($secretary->id);

    expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id.'/remove')), 'remove'))
        ->toBe(Settings::NOT_ON_COMMITTEE);

    // Another estate's person, and an id nobody holds: the same sentence.
    $theirs = rolesEditorAssignment(rolesEditorUser(Role::SECRETARY, ROLES_EDITOR_B), ROLES_EDITOR_B);
    $missing = (int) EstateAssignment::query()->max('id') + 1000;

    foreach ([$theirs->id, $missing] as $id) {
        expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$id.'/remove')), 'remove'))
            ->toBe(Settings::NOT_ON_COMMITTEE);
    }

    expect($theirs->fresh()->is_active)->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* Gemini's own staff are not this committee's to manage (B067) */
/* ------------------------------------------------------------------ */

/**
 * Gemini's Head of Security, covering one estate — built the way
 * `PlatformAdmins::manage()` builds it: a Gemini-console account holding the
 * Gemini role, and an ACTIVE assignment row carrying the estate's tenant id and
 * that Gemini role, which is how the role is narrowed to the sites it covers.
 */
function rolesEditorGeminiHead(string $estate): User
{
    $email = 'editor-gemini-head@'.$estate.'.test';

    $user = User::query()->firstOrNew(['email' => $email]);

    $user->fill([
        'name' => 'Gemini Head '.strtoupper($estate),
        'password' => 'password',
        'console' => Console::Gemini->value,
        'status' => 'active',
    ]);

    $user->forceFill([
        'contact_email' => $email,
        'contact_email_verified_at' => now(),
    ])->save();

    $user->syncRoles([Role::HEAD_OF_SECURITY]);

    EstateAssignment::updateOrCreate(
        ['user_id' => $user->id, 'tenant_id' => $estate],
        ['role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true],
    );

    return $user->fresh();
}

it('never lets a committee remove, suspend or re-role Gemini\'s Head of Security through the site assignment Gemini gave them', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $head = rolesEditorGeminiHead(ROLES_EDITOR_A);
    $siteRow = rolesEditorAssignment($head, ROLES_EDITOR_A);

    // The row really is this estate's, active, and the committee's power to
    // change it really is there — what is under test is the fence, not a gate.
    // That power is Settings' `manage_people` since M11 B3 split `update` (D-150).
    expect($siteRow->tenant_id)->toBe(ROLES_EDITOR_A)
        ->and($siteRow->is_active)->toBeTrue()
        ->and($admin->can('estate.settings.manage_people'))->toBeTrue()
        ->and($head->canAccessEstate(ROLES_EDITOR_B))->toBeFalse();

    // REMOVED: refused, and the officer this site's panics escalate to keeps
    // their account, their role and their site.
    expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$siteRow->id.'/remove')), 'remove'))
        ->toBe(Settings::NOT_ON_COMMITTEE);

    // RE-ROLED into the estate's widest role, and SUSPENDED: both refused.
    foreach ([
        ['role' => Role::COMMUNITY_SUPER_ADMIN, 'status' => 'active'],
        ['role' => Role::HEAD_OF_SECURITY, 'status' => 'suspended'],
        ['role' => Role::ESTATE_HEAD_OF_SECURITY, 'status' => 'active'],
    ] as $body) {
        expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$siteRow->id), $body)))
            ->toBe(Settings::NOT_ON_COMMITTEE);
    }

    $head = $head->fresh();

    expect($head->status)->toBe('active')
        ->and($head->roles->pluck('name')->all())->toBe([Role::HEAD_OF_SECURITY])
        ->and($siteRow->fresh()->is_active)->toBeTrue()
        ->and($siteRow->fresh()->role_id)->toBe(Role::named(Role::HEAD_OF_SECURITY)->id)
        // The escalation the re-role would have been: every estate's console.
        ->and($head->canAccessEstate(ROLES_EDITOR_B))->toBeFalse()
        ->and(AuditEntry::query()->where('entity_type', 'User')->where('entity_id', (string) $head->id)->exists())->toBeFalse();

    /*
     * NO ORACLE. A bad role name used to answer "not one of this estate's roles"
     * for any id at this estate and "not assigned to this estate" for any other,
     * which is how the Gemini officer's row was found. Every id that is not a
     * committee member now answers in one sentence, whatever the body says.
     */
    $theirs = rolesEditorAssignment(rolesEditorUser(Role::SECRETARY, ROLES_EDITOR_B), ROLES_EDITOR_B);
    $missing = (int) EstateAssignment::query()->max('id') + 1000;

    foreach ([$siteRow->id, $theirs->id, $missing] as $id) {
        expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$id), [
            'role' => 'not-a-role',
            'status' => 'active',
        ])))->toBe(Settings::NOT_ON_COMMITTEE);
    }

    // The screen never offered it, and still does not — in either list.
    $props = rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')));

    expect(array_column($props['rows'], 'id'))->not->toContain($head->id)
        ->and(array_column($props['removed'], 'id'))->not->toContain($head->id);
});

it('never offers a committee Gemini\'s officer back once Gemini has taken the site away, and refuses to reinstate them by id', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $head = rolesEditorGeminiHead(ROLES_EDITOR_A);
    $siteRow = rolesEditorAssignment($head, ROLES_EDITOR_A);

    // What `PlatformAdmins::manage()` does when the site leaves their scope.
    $siteRow->forceFill(['is_active' => false])->save();

    $props = rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')));

    expect(array_column($props['removed'], 'id'))->not->toContain($head->id);

    expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$siteRow->id.'/reinstate')), 'reinstate'))
        ->toBe(Settings::NOT_REMOVED_HERE);

    expect($siteRow->fresh()->is_active)->toBeFalse()
        ->and($head->fresh()->roles->pluck('name')->all())->toBe([Role::HEAD_OF_SECURITY]);
});

it('gives a Gemini account that an estate re-roled before the fence nothing at any estate, and lets the Director put it right from Platform admins', function () {
    /*
     * THE STATE THE OLD RE-ROLE LEFT BEHIND, built the way it left it: Gemini's
     * Head of Security, moved by a committee into the estate's widest role —
     * `syncRoles()` on the account and that role's id on the site row. The fence
     * stops anybody doing it again; it does not undo an account already done,
     * and the committee can no longer see such an account to undo it either.
     */
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $head = rolesEditorGeminiHead(ROLES_EDITOR_A);
    $siteRow = rolesEditorAssignment($head, ROLES_EDITOR_A);

    $siteRow->forceFill(['role_id' => Role::named(Role::COMMUNITY_SUPER_ADMIN)->id])->save();
    $head->syncRoles([Role::COMMUNITY_SUPER_ADMIN]);
    $head = $head->fresh();

    // It holds the estate's administrator permissions. They must reach nothing:
    // not every estate on the platform, by that role's scope, and not this one,
    // by a site row the committee cannot see on its own Users & roles.
    expect($head->can('estate.settings.approve'))->toBeTrue()
        ->and($head->widestScope())->not->toBe(AccessScope::All)
        ->and($head->canAccessEstate(ROLES_EDITOR_B))->toBeFalse()
        ->and($head->canAccessEstate(ROLES_EDITOR_A))->toBeFalse()
        ->and($head->accessibleEstateIds())->toBe([]);

    foreach ([ROLES_EDITOR_A, ROLES_EDITOR_B] as $estate) {
        $this->actingAs($head)->get(rolesEditorUrl($estate, '/settings/users'))->assertNotFound();
    }

    $props = rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')));

    expect(array_column($props['rows'], 'id'))->not->toContain($head->id);

    /*
     * AND GEMINI CAN PUT IT RIGHT. Platform admins refused to touch the account,
     * because the estate role it holds can change an ESTATE's roles and no
     * Gemini account can ever hold that power — so the refusal named a power
     * that is not this console's and could never be met. The Director moves it
     * back to Head of Security at its site, and the site row with it.
     */
    $director = User::factory()->geminiStaff()->create(['status' => 'active']);
    $director->syncRoles([Role::DIRECTOR]);
    $director = $director->fresh();

    $row = collect(app(PlatformAdmins::class)->board($director)['people'])->firstWhere('id', $head->id);

    expect($row)->not->toBeNull()
        ->and($row['reason'])->toBeNull();

    app(PlatformAdmins::class)->manage($head, Role::HEAD_OF_SECURITY, 'active', [ROLES_EDITOR_A], $director);

    $head = $head->fresh();

    expect($head->roles->pluck('name')->all())->toBe([Role::HEAD_OF_SECURITY])
        ->and($siteRow->fresh()->role_id)->toBe(Role::named(Role::HEAD_OF_SECURITY)->id)
        ->and($siteRow->fresh()->is_active)->toBeTrue()
        ->and($head->canAccessEstate(ROLES_EDITOR_A))->toBeTrue()
        ->and($head->canAccessEstate(ROLES_EDITOR_B))->toBeFalse();

    // What the console's OWN rule guards is untouched: an administrator of the
    // Gemini console is still managed only by somebody who is one. (A second
    // Director, so the last-Director refusal is not the one that answers.)
    $secondDirector = User::factory()->geminiStaff()->create(['status' => 'active']);
    $secondDirector->syncRoles([Role::DIRECTOR]);
    $opsManager = User::factory()->geminiStaff()->create(['status' => 'active']);
    $opsManager->syncRoles([Role::OPERATIONS_MANAGER]);

    expect(fn () => app(PlatformAdmins::class)->manage($secondDirector->fresh(), Role::DISPATCHER, 'active', [], $opsManager->fresh()))
        ->toThrow(DomainException::class, 'Only somebody who holds that power themselves may change their role or their access.');

    expect($secondDirector->fresh()->roles->pluck('name')->all())->toBe([Role::DIRECTOR]);
});

/* ------------------------------------------------------------------ */
/* a removal is not for ever (B047) */
/* ------------------------------------------------------------------ */

it('lists a removed committee member on Users & roles and brings them back in the role they held', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $treasurer = rolesEditorUser(Role::TREASURER, ROLES_EDITOR_A);
    $assignment = rolesEditorAssignment($treasurer, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id.'/remove'))
        ->assertSessionHasNoErrors();

    expect($treasurer->fresh()->canAccessEstate(ROLES_EDITOR_A))->toBeFalse()
        ->and(session('success'))->toContain('can be reinstated');

    // LISTED, with the act beside them and nothing refusing it.
    $props = rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')));
    $removed = collect($props['removed'])->keyBy('id');

    expect(array_column($props['rows'], 'id'))->not->toContain($treasurer->id)
        ->and($removed->has($treasurer->id))->toBeTrue()
        ->and($removed[$treasurer->id]['assignment_id'])->toBe($assignment->id)
        ->and($removed[$treasurer->id]['role_label'])->toBe('Treasurer')
        ->and($removed[$treasurer->id]['reinstate_refusal'])->toBeNull();

    // INVITING THEIR ADDRESS AGAIN says where the way back is — the list they
    // are on — and never the screen that no longer shows them as a member.
    $refusal = rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/invitations'), [
        'email' => $treasurer->email,
        'role' => Role::TREASURER,
    ]), 'email');

    expect($refusal)->toContain('Reinstate them')
        ->and($refusal)->not->toContain('change their role');

    // REINSTATED — the exact inverse of the removal.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id.'/reinstate'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users'));

    expect(session('success'))->toContain('is back on the committee as Treasurer');

    $back = $treasurer->fresh();

    expect($back->status)->toBe('active')
        ->and($back->roles->pluck('name')->all())->toBe([Role::TREASURER])
        ->and($assignment->fresh()->is_active)->toBeTrue()
        ->and($back->canAccessEstate(ROLES_EDITOR_A))->toBeTrue()
        ->and($back->can('estate.dues_ledger.view'))->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'estate.user_reinstated')->where('entity_id', (string) $treasurer->id)->where('actor_id', $admin->id)->exists())->toBeTrue();

    // And they can use the console again on their very next request.
    $this->actingAs($back)->get(rolesEditorUrl(ROLES_EDITOR_A, '/residents'))->assertOk();

    // On the committee again, and off the removed list.
    $props = rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')));

    expect(array_column($props['rows'], 'id'))->toContain($treasurer->id)
        ->and(array_column($props['removed'], 'id'))->not->toContain($treasurer->id);

    // Inviting an address that is on the committee says so, and points at Manage.
    expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/invitations'), [
        'email' => $treasurer->email,
        'role' => Role::TREASURER,
    ]), 'email'))->toContain('already on this estate\'s committee');
});

it('reinstates only a removed member of this committee, says why on the row first, and refuses a Manage on somebody still removed', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $secretary = rolesEditorUser(Role::SECRETARY, ROLES_EDITOR_A);
    $removedAdmin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A, 'b');
    $removedAdminRow = rolesEditorAssignment($removedAdmin, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$removedAdminRow->id.'/remove'))
        ->assertSessionHasNoErrors();

    // Somebody still on the committee, another estate's removed member, and an
    // id nobody holds: one sentence, and nothing moves.
    $theirs = rolesEditorAssignment(rolesEditorUser(Role::SECRETARY, ROLES_EDITOR_B), ROLES_EDITOR_B);
    $theirs->forceFill(['is_active' => false])->save();
    $missing = (int) EstateAssignment::query()->max('id') + 1000;

    foreach ([rolesEditorAssignment($secretary, ROLES_EDITOR_A)->id, $theirs->id, $missing] as $id) {
        expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$id.'/reinstate')), 'reinstate'))
            ->toBe(Settings::NOT_REMOVED_HERE);
    }

    expect($theirs->fresh()->is_active)->toBeFalse();

    // A REMOVED MEMBER IS NOT MANAGED WHILE REMOVED: a role handed to somebody
    // off the committee would reach nothing, and bringing them back is Reinstate.
    expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$removedAdminRow->id), [
        'role' => Role::SECRETARY,
        'status' => 'active',
    ])))->toBe(Settings::NOT_ON_COMMITTEE);

    expect($removedAdmin->fresh()->roles)->toBeEmpty()
        ->and($removedAdmin->fresh()->status)->toBe('suspended');

    /*
     * AN ADMINISTRATOR IS BROUGHT BACK ONLY BY AN ADMINISTRATOR, exactly as one
     * is made. A clerk with Settings at Full may reinstate a Secretary and may
     * not hand the power to change roles back to anybody — and the row says so.
     */
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles'), [
        'label' => 'Committee Clerk',
        'grid' => ['settings' => ['level' => 'full']],
    ])->assertSessionHasNoErrors();
    $clerk = rolesEditorUser(rolesEditorOwn(ROLES_EDITOR_A, 'Committee Clerk'), ROLES_EDITOR_A);

    $sentence = 'Community Super Admin can change what every role here may do, so only somebody who can do that themselves may give it to anyone. Your role cannot.';

    $removed = collect(rolesEditorProps($this->actingAs($clerk)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')))['removed'])->keyBy('id');

    expect($removed[$removedAdmin->id]['reinstate_refusal'])->toBe($sentence)
        ->and($removed[$removedAdmin->id]['is_administrator'])->toBeTrue();

    expect(rolesEditorRefusal($this->actingAs($clerk)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$removedAdminRow->id.'/reinstate')), 'reinstate'))
        ->toBe($sentence);

    expect($removedAdminRow->fresh()->is_active)->toBeFalse();

    // The administrator can.
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$removedAdminRow->id.'/reinstate'))
        ->assertSessionHasNoErrors();

    expect($removedAdmin->fresh()->can('estate.settings.approve'))->toBeTrue();

    // And the seven roles without Settings update never reach the route at all.
    $secretaryRow = rolesEditorAssignment($secretary, ROLES_EDITOR_A);
    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$secretaryRow->id.'/remove'))->assertSessionHasNoErrors();

    foreach ([Role::PRESIDENT, Role::VICE_PRESIDENT, Role::TREASURER, Role::PROPERTY_MANAGER] as $roleName) {
        $this->actingAs(rolesEditorUser($roleName, ROLES_EDITOR_A))
            ->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$secretaryRow->id.'/reinstate'))
            ->assertForbidden();
    }

    expect($secretaryRow->fresh()->is_active)->toBeFalse();
});

it('does not bring back an account that has since been given another estate, whose role reinstating would replace', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $treasurer = rolesEditorUser(Role::TREASURER, ROLES_EDITOR_A);
    $assignment = rolesEditorAssignment($treasurer, ROLES_EDITOR_A);

    $this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id.'/remove'))
        ->assertSessionHasNoErrors();

    // An operator seats the same account on estate B's committee as Secretary.
    $treasurer->fresh()->forceFill(['status' => 'active'])->save();
    $treasurer->fresh()->syncRoles([Role::SECRETARY]);
    EstateAssignment::create([
        'user_id' => $treasurer->id,
        'tenant_id' => ROLES_EDITOR_B,
        'role_id' => Role::named(Role::SECRETARY)->id,
        'is_active' => true,
    ]);

    $props = rolesEditorProps($this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users')));

    expect(array_column($props['removed'], 'id'))->not->toContain($treasurer->id);

    expect(rolesEditorRefusal($this->actingAs($admin)->post(rolesEditorUrl(ROLES_EDITOR_A, '/settings/users/'.$assignment->id.'/reinstate')), 'reinstate'))
        ->toBe(Settings::NOT_REMOVED_HERE);

    // Estate B's Secretary is still estate B's Secretary.
    expect($treasurer->fresh()->roles->pluck('name')->all())->toBe([Role::SECRETARY])
        ->and($assignment->fresh()->is_active)->toBeFalse();
});

/* ------------------------------------------------------------------ */
/* one role's permissions, a page of its own (Hassam, 12 Oct 2026) */
/* ------------------------------------------------------------------ */

it('opens one role\'s permissions as a page of its own: its name, who holds it, and the board for that role alone', function () {
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);
    $first = rolesEditorUser(Role::TREASURER, ROLES_EDITOR_A, 'b');
    $second = rolesEditorUser(Role::TREASURER, ROLES_EDITOR_A, 'a');
    $treasurer = Role::named(Role::TREASURER);
    $before = rolesEditorFingerprint($treasurer);

    $response = $this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$treasurer->id.'/permissions'));

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Settings/RolePermissions'));

    $props = rolesEditorProps($response);

    // The role, by name, and who holds it, by name: the people the change would reach.
    expect($props['role']['id'])->toBe($treasurer->id)
        ->and($props['role']['label'])->toBe('Treasurer')
        ->and($props['role']['can_edit_permissions'])->toBeTrue()
        ->and($props['holderNames'])->toBe(collect([$first->name, $second->name])->sort()->values()->all())
        ->and($props['role']['holders'])->toBe(2);

    // The board for this one role: what the viewer holds and every module, and no other role's cells.
    expect($props['editor'])->toHaveKeys(['verbs', 'levels', 'modules', 'grantable', 'grantable_reason', 'position_rule'])
        ->and(array_key_exists('roles', $props['editor']))->toBeFalse()
        ->and($props['editor']['position_rule'])->toBe(Settings::POSITION_RULE)
        ->and($props['invariant_note'])->toBe(Settings::INVARIANT_NOTE)
        ->and($props['rolesHref'])->toEndWith('/settings/roles')
        ->and($props['saveHref'])->toEndWith('/settings/roles/'.$treasurer->id.'/permissions');

    // Opening it changes nothing: the same cells and the same compiled permissions as before the page was read.
    expect(rolesEditorFingerprint($treasurer))->toBe($before);
});

it('opens a role\'s page only for the role that changes roles, and never another estate\'s role', function () {
    $treasurer = Role::named(Role::TREASURER);

    // A reader of Settings (the President holds it as View) is refused at the gate: the address shows nothing.
    $this->actingAs(rolesEditorUser(Role::PRESIDENT, ROLES_EDITOR_A))
        ->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$treasurer->id.'/permissions'))
        ->assertForbidden();

    // Estate B's own role is not estate A's to open: not found, the same answer as a role that does not exist.
    $this->actingAs(rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_B))
        ->post(rolesEditorUrl(ROLES_EDITOR_B, '/settings/roles'), ['label' => 'Pool Warden'])
        ->assertSessionHasNoErrors();

    $theirs = rolesEditorOwn(ROLES_EDITOR_B, 'Pool Warden');
    $admin = rolesEditorUser(Role::COMMUNITY_SUPER_ADMIN, ROLES_EDITOR_A);

    $this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/'.$theirs->id.'/permissions'))->assertNotFound();
    $this->actingAs($admin)->get(rolesEditorUrl(ROLES_EDITOR_A, '/settings/roles/999999/permissions'))->assertNotFound();
});
