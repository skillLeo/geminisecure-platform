<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Services\Rbac\RoleEditor;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The Role Access Matrix is the Director's editor — over HTTP (M10 §4)
|--------------------------------------------------------------------------
|
| RolesAreDynamicTest proves the rules on `RoleEditor` itself. This file proves
| them through the Gemini Console's own routes, the way the screen reaches
| them, because a rule that holds on the service and not on the route is a
| rule a hand-built post walks round:
|
|   - the Director creates, copies, renames, re-grants and deletes a role of
|     the console's own, and re-grants a seeded one — the lead's ruling
|   - a change applies to the holder's very next page, with nobody clearing a
|     cache
|   - no seeded role, of either console, is renamed or deleted, and the
|     refusal is RoleEditor's own sentence
|   - the last Director is never stripped, demoted or removed
|   - every write route answers 403 to the five other seeded roles, and to a
|     role that reads the settings without the power to change roles
|   - the Director cannot give an approve it does not hold
|   - no role an estate owns is ever drawn on the platform matrix or offered
|     among the roles a platform account can hold
|   - removing an administrator suspends them and keeps the account
|
| On the platform's own database, inside a transaction rolled back after each
| test; the estate is FacilitiesFixture's, and only one test goes inside it.
|
*/

/**
 * The matrix, seeded on the NEWEST CELL rather than on a role existing — the
 * rule FacilitiesFixture learned. A database seeded before M10 §4 has every
 * role already, and without this the Director would be a version behind and
 * refused every write below for a reason that is not the one under test.
 */
function preSeededForRoleEditing(): void
{
    $current = Role::query()
        ->where('name', Role::DIRECTOR)
        ->where('is_system', true)
        ->whereHas('permissions', static fn (Builder $query) => $query->where('name', 'gemini.platform_settings.approve'))
        ->exists();

    if (! $current) {
        Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    }
}

/** A Gemini Console account holding exactly this role, seeded or made here. */
function preHolder(string|Role $role): User
{
    $role = $role instanceof Role ? $role : Role::named($role);

    $user = User::factory()->geminiStaff()->create();
    $user->syncRoles([$role->name]);

    return $user->fresh();
}

/** @return list<string> */
function prePermissions(Role $role): array
{
    return $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
}

/** @return array<string, mixed> */
function preProps(TestResponse $response): array
{
    $props = [];

    $response->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return json_decode((string) json_encode($props), true);
}

/** One cell of the matrix payload: this module, this role's column. */
function preCell(array $props, string $moduleKey, int $roleId): array
{
    $module = collect($props['modules'])->firstWhere('key', $moduleKey);

    return collect($module['cells'])->firstWhere('role_id', $roleId);
}

/** Leaves this Director the only active one, inside the test's transaction. */
function preOnlyDirector(User $director): void
{
    User::query()
        ->where('id', '!=', $director->id)
        ->whereHas('roles', static fn ($query) => $query->where('name', Role::DIRECTOR))
        ->update(['status' => 'suspended']);
}

function preEditor(): RoleEditor
{
    return app(RoleEditor::class);
}

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    preSeededForRoleEditing();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Notification::fake();
});

afterEach(function () {
    tenancy()->end();

    DB::connection('mysql')->rollBack();

    // The rolled-back roles must not live on in the cache.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/* ------------------------------------------------------------------ */
/* the Director's five acts                                            */
/* ------------------------------------------------------------------ */

it('lets the Director create, re-grant, rename, copy and delete a role of the console\'s own, and re-grant a seeded one', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    // THE BOARD: every role carries its machine name and what may be done to it.
    $props = preProps($this->actingAs($director)->get('/settings/roles'));

    expect($props['canEdit'])->toBeTrue()
        ->and($props['editBlockedReason'])->toBeNull()
        ->and(array_column($props['roles'], 'name'))->toContain(Role::DIRECTOR, Role::DISPATCHER, Role::ACCOUNTANT)
        ->and(collect($props['roles'])->firstWhere('name', Role::DISPATCHER)['can_edit_permissions'])->toBeTrue()
        ->and(array_column($props['verbs'], 'key'))->not->toContain('approve')
        ->and(array_column($props['moneyModules'], 'key'))->toBe(['billing_subscriptions', 'payroll_accounting']);

    // CREATE.
    $this->actingAs($director)
        ->post('/settings/roles', ['label' => 'Night Supervisor', 'scope' => 'all'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/roles');

    $role = Role::query()->where('name', 'gemini.custom.night_supervisor')->sole();

    expect($role->tenant_id)->toBeNull()
        ->and($role->is_system)->toBeFalse()
        ->and($role->created_by_id)->toBe($director->id)
        ->and(prePermissions($role))->toBe([]);

    // RE-GRANT, with a preset and a per-action cell.
    $this->actingAs($director)
        ->post('/settings/roles/'.$role->id.'/permissions', ['grid' => [
            'dispatch' => ['level' => 'full', 'verbs' => null, 'can_approve' => false],
            'access_audit_log' => ['level' => 'custom', 'verbs' => ['view', 'export'], 'can_approve' => false],
        ]])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/roles');

    expect(prePermissions($role))->toBe([
        'gemini.access_audit_log.export',
        'gemini.access_audit_log.view',
        'gemini.dispatch.configure',
        'gemini.dispatch.create',
        'gemini.dispatch.delete',
        'gemini.dispatch.export',
        'gemini.dispatch.update',
        'gemini.dispatch.view',
    ]);

    // The pill names a Custom cell's verbs, and the column is the Director's to change.
    $props = preProps($this->actingAs($director)->get('/settings/roles'));
    $column = collect($props['roles'])->firstWhere('id', $role->id);

    expect(preCell($props, 'access_audit_log', $role->id))->toMatchArray([
        'variant' => 'view',
        'label' => 'Custom (view, export)',
        'level' => 'custom',
        'verbs' => ['view', 'export'],
    ])
        ->and(preCell($props, 'dispatch', $role->id)['label'])->toBe('Full')
        ->and($column['name'])->toBe('gemini.custom.night_supervisor')
        ->and($column['can_rename'])->toBeTrue()
        ->and($column['can_delete'])->toBeTrue();

    // RENAME: the label moves, the machine name never does, whatever is posted.
    $this->actingAs($director)
        ->post('/settings/roles/'.$role->id, ['label' => 'Night Lead', 'name' => Role::DIRECTOR])
        ->assertSessionHasNoErrors();

    expect($role->fresh()->label)->toBe('Night Lead')
        ->and($role->fresh()->name)->toBe('gemini.custom.night_supervisor');

    // COPY a role of the Director's own, and a seeded one.
    $this->actingAs($director)
        ->post('/settings/roles/'.$role->id.'/copy', ['label' => 'Night Lead Two'])
        ->assertSessionHasNoErrors();

    $copy = Role::query()->where('label', 'Night Lead Two')->sole();

    expect(prePermissions($copy))->toBe(prePermissions($role))
        ->and($copy->tenant_id)->toBeNull();

    $dispatcherRole = Role::named(Role::DISPATCHER);

    // What a copy would hold is said before it is made — money included.
    $offer = preProps($this->actingAs($director)->get('/settings/roles?copy='.Role::named(Role::ACCOUNTANT)->id))['copyOffer'];

    expect($offer['role_id'])->toBe(Role::named(Role::ACCOUNTANT)->id)
        ->and($offer['withheld'])->toBe([])
        ->and(implode(' ', $offer['lines']))->toContain('Billing & subscriptions view')
        ->and(implode(' ', $offer['lines']))->toContain('Payroll & Accounting view');

    $this->actingAs($director)->post('/settings/roles/'.$dispatcherRole->id.'/copy', [])->assertSessionHasNoErrors();

    $dispatcherCopy = Role::query()->where('label', 'Copy of Dispatcher')->sole();

    expect($dispatcherCopy->copied_from_id)->toBe($dispatcherRole->id)
        ->and(prePermissions($dispatcherCopy))->toBe(prePermissions($dispatcherRole));

    // DELETE a role nobody holds.
    $this->actingAs($director)->post('/settings/roles/'.$copy->id.'/delete')->assertSessionHasNoErrors();

    expect(Role::query()->whereKey($copy->id)->exists())->toBeFalse()
        ->and(RoleModuleAccess::query()->where('role_id', $copy->id)->exists())->toBeFalse();

    // A SEEDED ROLE'S PERMISSIONS ARE THE DIRECTOR'S TO CHANGE (the lead's ruling).
    expect(prePermissions($dispatcherRole))->not->toContain('gemini.cross_tenant_reports.view');

    $this->actingAs($director)
        ->post('/settings/roles/'.$dispatcherRole->id.'/permissions', ['grid' => ['cross_tenant_reports' => ['level' => 'view']]])
        ->assertSessionHasNoErrors();

    expect(prePermissions($dispatcherRole))->toContain('gemini.cross_tenant_reports.view')
        ->and($dispatcherRole->fresh()->label)->toBe('Dispatcher');

    // Every act is one audit row, by the Director.
    $actions = AuditEntry::query()
        ->where('entity_type', 'Role')
        ->whereIn('entity_id', [$role->name, $copy->name, $dispatcherCopy->name, Role::DISPATCHER])
        ->where('actor_id', $director->id)
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($actions)->toBe([
        'role.created',
        'role.permissions_changed',
        'role.renamed',
        'role.created',
        'role.created',
        'role.deleted',
        'role.permissions_changed',
    ]);
});

it('applies a change to the holder\'s very next page, and takes it away as fast', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)->post('/settings/roles', ['label' => 'Audit Reader'])->assertSessionHasNoErrors();

    $role = Role::query()->where('label', 'Audit Reader')->sole();
    $reader = preHolder($role);

    $this->actingAs($reader)->get('/audit')->assertForbidden();

    $this->actingAs($director)
        ->post('/settings/roles/'.$role->id.'/permissions', ['grid' => ['access_audit_log' => ['level' => 'view']]])
        ->assertSessionHasNoErrors();

    // No forgetCachedPermissions() here: the save must have done it.
    $this->actingAs($reader->fresh())->get('/audit')->assertOk();

    $this->actingAs($director)
        ->post('/settings/roles/'.$role->id.'/permissions', ['grid' => ['access_audit_log' => ['level' => 'none']]])
        ->assertSessionHasNoErrors();

    $this->actingAs($reader->fresh())->get('/audit')->assertForbidden();

    // Refused while somebody holds it, in words, and the role stays.
    $this->actingAs($director)
        ->post('/settings/roles/'.$role->id.'/delete')
        ->assertSessionHasErrors(['roles' => '1 person holds Audit Reader. Move them to another role first: deleting it would leave them with no role and no access.']);

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* the seeded roles                                                    */
/* ------------------------------------------------------------------ */

it('refuses to rename or delete any of the fourteen seeded roles, in RoleEditor\'s own sentence', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $gemini = [
        Role::DIRECTOR,
        Role::OPERATIONS_MANAGER,
        Role::HEAD_OF_SECURITY,
        Role::DISPATCHER,
        Role::ADMIN_ASSISTANT,
        Role::ACCOUNTANT,
    ];

    foreach ($gemini as $name) {
        $role = Role::named($name);

        $this->actingAs($director)
            ->post('/settings/roles/'.$role->id, ['label' => 'Renamed'])
            ->assertSessionHasErrors(['roles' => $role->label.' is one of the platform\'s own roles, and code across the platform relies on it by name, so it cannot be renamed. Its permissions can still be changed.']);

        $this->actingAs($director)
            ->post('/settings/roles/'.$role->id.'/delete')
            ->assertSessionHasErrors(['roles' => $role->label.' is one of the platform\'s own roles, and code across the platform relies on it by name, so it cannot be deleted. Its permissions can be narrowed instead, down to what it needs to restore the rest.']);
    }

    /*
     * The eight estate roles are not this console's at all. Posting one's id
     * is refused as a role that is not here, in the words a role that does
     * not exist gets — the platform console is not where they are changed.
     */
    $estate = Role::query()->where('console', 'estate')->where('is_system', true)->get();

    expect($estate)->toHaveCount(8);

    foreach ($estate as $role) {
        foreach (['', '/delete', '/permissions', '/copy'] as $act) {
            $this->actingAs($director)
                ->post('/settings/roles/'.$role->id.$act, ['label' => 'Renamed', 'grid' => ['dashboard' => ['level' => 'view']]])
                ->assertSessionHasErrors(['roles' => RoleEditor::NOT_HERE_GEMINI]);
        }
    }

    // All fourteen still there, under their own names and labels.
    $seeded = Role::query()->where('is_system', true)->whereNull('tenant_id')->orderBy('id')->get();

    expect($seeded)->toHaveCount(14)
        ->and($seeded->pluck('label')->all())->not->toContain('Renamed');

    // And the board says so before anybody presses anything.
    $props = preProps($this->actingAs($director)->get('/settings/roles'));
    $dispatcher = collect($props['roles'])->firstWhere('name', Role::DISPATCHER);

    expect($dispatcher['can_rename'])->toBeFalse()
        ->and($dispatcher['rename_refusal'])->toContain('cannot be renamed')
        ->and($dispatcher['can_delete'])->toBeFalse()
        ->and($dispatcher['delete_refusal'])->toContain('cannot be deleted');
});

/* ------------------------------------------------------------------ */
/* the last Director                                                   */
/* ------------------------------------------------------------------ */

it('never strips, demotes or removes the last Director, and says why in words', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    preOnlyDirector($director);

    $directorRole = Role::named(Role::DIRECTOR);

    // STRIPPED: the cell that lets somebody restore everything else.
    foreach ([['level' => 'full'], ['level' => 'view'], ['level' => 'none'], ['level' => 'custom', 'verbs' => ['view']]] as $cell) {
        $this->actingAs($director)
            ->post('/settings/roles/'.$directorRole->id.'/permissions', ['grid' => ['platform_settings' => $cell]])
            ->assertSessionHasErrors(['roles' => 'Director is the role that can always change permissions on the Gemini Console. Taking Full · Approver on Platform settings away from it would leave nobody certain to be able to give it back.']);
    }

    /*
     * DEMOTED AND REMOVED. The Director cannot do either to themselves, so the
     * attempt is made by a second administrator the Director made — one who
     * may change roles and people, and is not a Director.
     */
    $this->actingAs($director)
        ->post('/settings/roles', ['label' => 'Deputy Administrator', 'grid' => [
            'dashboard' => ['level' => 'full'],
            'platform_settings' => ['level' => 'full', 'can_approve' => true],
        ]])
        ->assertSessionHasNoErrors();

    $deputy = preHolder(Role::query()->where('label', 'Deputy Administrator')->sole());

    $last = 'This is the only active Director. Make somebody else a Director first — otherwise nobody could change this back.';

    $this->actingAs($deputy)
        ->post('/settings/admins/'.$director->id, ['role' => Role::OPERATIONS_MANAGER, 'status' => 'active'])
        ->assertSessionHasErrors(['admins' => $last]);

    $this->actingAs($deputy)
        ->post('/settings/admins/'.$director->id, ['role' => Role::DIRECTOR, 'status' => 'suspended'])
        ->assertSessionHasErrors(['admins' => $last]);

    $this->actingAs($deputy)
        ->post('/settings/admins/'.$director->id.'/remove')
        ->assertSessionHasErrors(['admins' => $last]);

    $this->actingAs($director)
        ->post('/settings/admins/'.$director->id.'/remove')
        ->assertSessionHasErrors(['admins' => 'You cannot remove yourself. Another administrator does that, so a slip cannot lock you out.']);

    // The row says it before the press.
    $row = collect(preProps($this->actingAs($deputy)->get('/settings/admins'))['people'])->firstWhere('id', $director->id);

    expect($row['reason'])->toBe('This is the only active Director. Make somebody else a Director before changing this account.');

    $director = $director->fresh();

    expect($director->status)->toBe('active')
        ->and($director->hasRole(Role::DIRECTOR))->toBeTrue()
        ->and($director->can('gemini.platform_settings.approve'))->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* who may write at all                                                */
/* ------------------------------------------------------------------ */

it('answers 403 on every write route, the reset included, to the five other seeded roles and to a settings clerk without approve', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $second = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $scratch = preEditor()->create($director, 'Scratch Role', ['dashboard' => ['level' => 'view']]);
    $target = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    // Reads and manages the settings — Full on Platform settings — but holds
    // no approve, so it cannot change a role or take anybody off the console.
    $clerk = preHolder(preEditor()->create($director, 'Settings Clerk', [
        'dashboard' => ['level' => 'full'],
        'platform_settings' => ['level' => 'full'],
    ]));

    $writes = [
        ['/settings/roles', ['label' => 'Shadow Role']],
        ['/settings/roles/'.$scratch->id.'/copy', ['label' => 'Shadow Copy']],
        ['/settings/roles/'.$scratch->id, ['label' => 'Taken Over']],
        ['/settings/roles/'.$scratch->id.'/permissions', ['grid' => ['dashboard' => ['level' => 'full']]]],
        ['/settings/roles/'.$scratch->id.'/delete', []],
        ['/settings/roles/'.Role::named(Role::DISPATCHER)->id.'/reset', []],
        ['/settings/admins/'.$target->id.'/remove', []],
    ];

    $resetsBefore = AuditEntry::query()->where('action', 'role.reset_to_default')->count();

    $refused = [
        ...array_map(
            static fn (string $name): User => FacilitiesFixture::geminiViewer($name),
            [Role::OPERATIONS_MANAGER, Role::HEAD_OF_SECURITY, Role::DISPATCHER, Role::ADMIN_ASSISTANT, Role::ACCOUNTANT],
        ),
        $clerk,
    ];

    foreach ($refused as $user) {
        foreach ($writes as [$path, $body]) {
            $this->actingAs($user)->post($path, $body)->assertForbidden();
        }
    }

    expect(AuditEntry::query()->where('action', 'role.reset_to_default')->count())->toBe($resetsBefore)
        ->and(Role::query()->whereIn('label', ['Shadow Role', 'Shadow Copy', 'Taken Over'])->exists())->toBeFalse()
        ->and($scratch->fresh()->label)->toBe('Scratch Role')
        ->and(prePermissions($scratch))->toBe(['gemini.dashboard.view'])
        ->and($target->fresh()->status)->toBe('active')
        ->and($target->fresh()->hasRole(Role::DISPATCHER))->toBeTrue();

    // The clerk reads the matrix, and is told in words why it is read-only.
    $props = preProps($this->actingAs($clerk)->get('/settings/roles'));

    expect($props['canEdit'])->toBeFalse()
        ->and($props['editBlockedReason'])->toContain('On the Gemini Console the Director holds it, and your role does not.')
        ->and(collect($props['roles'])->firstWhere('name', Role::DISPATCHER)['permissions_refusal'])->toContain('the Director holds it');

    /*
     * AND AN ADMINISTRATOR IS CHANGED ONLY BY SOMEBODY WHO HOLDS WHAT THEY HOLD.
     * The clerk may manage people, and may not touch a Director — the row says
     * so, and the post is refused in the same sentence.
     */
    $row = collect(preProps($this->actingAs($clerk)->get('/settings/admins'))['people'])->firstWhere('id', $second->id);
    $holder = 'This person is a Director, who can change what every role here may do. Only somebody who holds that power themselves may change their role or their access.';

    expect($row['reason'])->toBe($holder);

    $this->actingAs($clerk)
        ->post('/settings/admins/'.$second->id, ['role' => Role::DISPATCHER, 'status' => 'active'])
        ->assertSessionHasErrors(['admins' => $holder]);

    expect($second->fresh()->hasRole(Role::DIRECTOR))->toBeTrue();
});

it('refuses to let the Director give an approve it does not hold, however the post is shaped', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $role = preEditor()->create($director, 'Approver Probe', []);

    // The Director holds Clients at Full with no Approver tag.
    $this->actingAs($director)
        ->post('/settings/roles/'.$role->id.'/permissions', ['grid' => ['clients' => ['level' => 'full', 'can_approve' => true]]])
        ->assertSessionHasErrors(['roles' => 'You do not hold Clients · approve yourself, so you cannot give it to a role.']);

    // Smuggled in through the per-action list rather than the tag.
    $this->actingAs($director)
        ->post('/settings/roles/'.$role->id.'/permissions', ['grid' => ['clients' => ['level' => 'custom', 'verbs' => ['view', 'approve']]]])
        ->assertSessionHasErrors(['roles' => 'You do not hold Clients · approve yourself, so you cannot give it to a role.']);

    // And at the moment of creation.
    $this->actingAs($director)
        ->post('/settings/roles', ['label' => 'Smuggler', 'grid' => ['dispatch' => ['level' => 'view', 'can_approve' => true]]])
        ->assertSessionHasErrors(['roles' => 'You do not hold Dispatch · approve yourself, so you cannot give it to a role.']);

    expect(prePermissions($role))->toBe([])
        ->and(Role::query()->where('label', 'Smuggler')->exists())->toBeFalse();

    // THE POSITIVE CONTROL: an approve the Director does hold is theirs to give.
    $this->actingAs($director)
        ->post('/settings/roles/'.$role->id.'/permissions', ['grid' => ['payroll_accounting' => ['level' => 'view', 'can_approve' => true]]])
        ->assertSessionHasNoErrors();

    expect(prePermissions($role))->toBe(['gemini.payroll_accounting.approve', 'gemini.payroll_accounting.view']);

    // The board offered exactly that and no more.
    $grantable = preProps($this->actingAs($director)->get('/settings/roles'))['grantable'];

    expect($grantable['clients']['approve'])->toBeFalse()
        ->and($grantable['clients']['delete'])->toBeTrue()
        ->and($grantable['payroll_accounting']['approve'])->toBeTrue()
        ->and($grantable['platform_settings']['approve'])->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* reset to GeminiSecure default                                       */
/* ------------------------------------------------------------------ */

/** A role's cells as rows, for asking whether a reset put back exactly what was there. */
function preCellRows(Role $role): array
{
    return RoleModuleAccess::query()->where('role_id', $role->id)->orderBy('module_id')->get()
        ->map(static fn (RoleModuleAccess $c): array => [$c->module_id, $c->level->value, $c->verbs, $c->can_approve, $c->scope->value])
        ->all();
}

it('draws no reset control on a seeded role nobody has changed, nor on a role made here', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $custom = preEditor()->create($director, 'Night Supervisor', ['dashboard' => ['level' => 'view']]);

    $roles = collect(preProps($this->actingAs($director)->get('/settings/roles'))['roles'])->keyBy('name');

    foreach ([Role::DIRECTOR, Role::OPERATIONS_MANAGER, Role::HEAD_OF_SECURITY, Role::DISPATCHER, Role::ADMIN_ASSISTANT, Role::ACCOUNTANT] as $name) {
        expect($roles[$name]['is_default'])->toBeTrue("{$name} is not at its default")
            ->and($roles[$name]['resettable'])->toBeFalse()
            ->and($roles[$name]['edited'])->toBeNull()
            ->and($roles[$name]['reset_difference'])->toBeNull();
    }

    expect($roles[$custom->name]['resettable'])->toBeFalse()
        ->and($roles[$custom->name]['reset_difference'])->toBeNull()

        // The page still says what a platform update does, in the board's words.
        ->and($roles[Role::DISPATCHER]['note'])->toContain('It follows platform updates until you change it; after that, updates keep your changes.');
});

it('resets an edited Dispatcher to exactly the seeded grid, says so before and after, and audits it', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $dispatcherRole = Role::named(Role::DISPATCHER);
    $holder = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    $pristinePermissions = prePermissions($dispatcherRole);
    $pristineCells = preCellRows($dispatcherRole);

    $this->actingAs($director)
        ->post('/settings/roles/'.$dispatcherRole->id.'/permissions', ['grid' => [
            'cross_tenant_reports' => ['level' => 'view'],
            'dispatch' => ['level' => 'view'],
        ]])
        ->assertSessionHasNoErrors();

    expect($holder->fresh()->can('gemini.cross_tenant_reports.view'))->toBeTrue()
        ->and($holder->fresh()->can('gemini.dispatch.update'))->toBeFalse();

    // THE CONTROL, drawn now, with who changed it, when, and what a reset does.
    $row = collect(preProps($this->actingAs($director)->get('/settings/roles'))['roles'])->firstWhere('name', Role::DISPATCHER);

    expect($row['is_default'])->toBeFalse()
        ->and($row['resettable'])->toBeTrue()
        ->and($row['can_reset'])->toBeTrue()
        ->and($row['reset_refusal'])->toBeNull()
        ->and($row['edited'])->toBe('Edited '.now()->format('j F Y').' by '.$director->name.'.')
        ->and($row['reset_difference']['add'])->toBe([
            'Dispatch · configure',
            'Dispatch · create',
            'Dispatch · delete',
            'Dispatch · export',
            'Dispatch · update',
        ])
        ->and($row['reset_difference']['remove'])->toBe(['Cross-tenant reports · view'])
        ->and($row['note'])->toContain('Platform updates keep your changes');

    // THE RESET.
    $this->actingAs($director)
        ->post('/settings/roles/'.$dispatcherRole->id.'/reset')
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/roles')
        ->assertSessionHas('success');

    $dispatcherRole = $dispatcherRole->fresh();

    expect(prePermissions($dispatcherRole))->toBe($pristinePermissions)
        ->and(preCellRows($dispatcherRole))->toBe($pristineCells)
        ->and($dispatcherRole->permissions_edited_at)->toBeNull()
        ->and($dispatcherRole->permissions_edited_by_id)->toBeNull()

        // On the holder's very next check.
        ->and($holder->fresh()->can('gemini.cross_tenant_reports.view'))->toBeFalse()
        ->and($holder->fresh()->can('gemini.dispatch.update'))->toBeTrue();

    $entry = AuditEntry::query()->where('action', 'role.reset_to_default')->where('entity_id', Role::DISPATCHER)->sole();

    expect($entry->actor_id)->toBe($director->id)
        ->and($entry->after['via'])->toBe('console')
        ->and($entry->before['permissions'])->toContain('gemini.cross_tenant_reports.view')
        ->and($entry->after['permissions'])->toBe($pristinePermissions);

    // And the control is gone again: the role is back at its default.
    $row = collect(preProps($this->actingAs($director)->get('/settings/roles'))['roles'])->firstWhere('name', Role::DISPATCHER);

    expect($row['is_default'])->toBeTrue()
        ->and($row['resettable'])->toBeFalse();
});

it('refuses a reset in RoleEditor\'s sentence, and draws the control disabled with the reason before the press', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $custom = preEditor()->create($director, 'Night Supervisor', ['dashboard' => ['level' => 'view']]);

    // Nothing to reset.
    $this->actingAs($director)
        ->post('/settings/roles/'.Role::named(Role::DISPATCHER)->id.'/reset')
        ->assertSessionHasErrors(['roles' => 'Dispatcher already has GeminiSecure\'s default permissions.']);

    // No default to go back to.
    $this->actingAs($director)
        ->post('/settings/roles/'.$custom->id.'/reset')
        ->assertSessionHasErrors(['roles' => 'Night Supervisor was made here, so it has no GeminiSecure default to go back to.']);

    /*
     * NOBODY GIVES WHAT THEY DO NOT HOLD, A RESET INCLUDED. The Director
     * narrows the Director's own Clients cell; from then on the Director no
     * longer holds what the default would give back, so the console cannot
     * reset it — the server's `rbac:reset-role` can.
     */
    $directorRole = Role::named(Role::DIRECTOR);

    $this->actingAs($director)
        ->post('/settings/roles/'.$directorRole->id.'/permissions', ['grid' => ['clients' => ['level' => 'view']]])
        ->assertSessionHasNoErrors();

    $row = collect(preProps($this->actingAs($director->fresh())->get('/settings/roles'))['roles'])->firstWhere('name', Role::DIRECTOR);

    expect($row['resettable'])->toBeTrue()
        ->and($row['can_reset'])->toBeFalse()
        ->and($row['reset_refusal'])->toContain('Clients · create')
        ->and($row['reset_refusal'])->toContain('php artisan rbac:reset-role');

    $this->actingAs($director->fresh())
        ->post('/settings/roles/'.$directorRole->id.'/reset')
        ->assertSessionHasErrors(['roles' => 'You do not hold Clients · configure yourself, so you cannot give it to a role.']);

    expect(prePermissions($directorRole))->not->toContain('gemini.clients.create')
        ->and($directorRole->fresh()->permissions_edited_at)->not->toBeNull();
});

/* ------------------------------------------------------------------ */
/* nothing an estate owns                                              */
/* ------------------------------------------------------------------ */

it('never draws or hands out a role an estate owns, even one mis-created on this console', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $csa = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    // An estate's own role, made the real way, from inside that estate.
    $estateOwn = FacilitiesFixture::platform()->run(
        static fn (): Role => preEditor()->create($csa, 'Gate Liaison', ['security' => ['level' => 'view']])
    );
    tenancy()->end();

    /*
     * AND A ROW NO CODE PATH WRITES: a Gemini-console role carrying an estate's
     * tenant id. The fence is `ownedBy(null)`, and it has to hold against a
     * mistake as well as against another console.
     */
    $stray = new Role;
    $stray->forceFill([
        'name' => 'gemini.custom.stray_estate_row',
        'guard_name' => 'web',
        'console' => 'gemini',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'is_system' => false,
        'label' => 'Stray Row',
        'sort' => 990,
        'scope_default' => 'all',
    ])->save();

    $foreign = [$estateOwn->name, $stray->name];

    // Not on the matrix, and every role that is drawn is the platform's own.
    $matrix = preProps($this->actingAs($director)->get('/settings/roles'));
    $drawn = array_column($matrix['roles'], 'name');

    expect(array_intersect($drawn, $foreign))->toBe([])
        ->and(Role::query()->whereIn('name', $drawn)->where(fn ($q) => $q->whereNotNull('tenant_id')->orWhere('console', '!=', 'gemini'))->count())->toBe(0);

    foreach ($matrix['modules'] as $module) {
        expect(array_column($module['cells'], 'role_id'))->toBe(array_column($matrix['roles'], 'id'));
    }

    // Not among the roles a platform account may hold.
    $admins = preProps($this->actingAs($director)->get('/settings/admins'));

    expect(array_intersect(array_column($admins['roles'], 'name'), $foreign))->toBe([])
        ->and(array_intersect(preEditor()->assignable($director)->pluck('name')->all(), $foreign))->toBe([]);

    // Not handed out by name, whichever door it is posted to.
    foreach ($foreign as $name) {
        $this->actingAs($director)
            ->post('/settings/admins/invitations', ['email' => 'stray@gemini.test', 'role' => $name])
            ->assertSessionHasErrors('admins');

        $this->actingAs($director)
            ->post('/settings/admins/'.$dispatcher->id, ['role' => $name, 'status' => 'active'])
            ->assertSessionHasErrors('admins');
    }

    expect(Invitation::query()->where('email', 'stray@gemini.test')->exists())->toBeFalse()
        ->and($dispatcher->fresh()->hasRole(Role::DISPATCHER))->toBeTrue();

    // Not edited, copied or deleted from here, and not described either.
    foreach ([$estateOwn, $stray] as $role) {
        foreach (['', '/permissions', '/copy', '/delete'] as $act) {
            $this->actingAs($director)
                ->post('/settings/roles/'.$role->id.$act, ['label' => 'Taken', 'grid' => ['dashboard' => ['level' => 'view']]])
                ->assertSessionHasErrors(['roles' => RoleEditor::NOT_HERE_GEMINI]);
        }

        expect(preProps($this->actingAs($director)->get('/settings/roles?copy='.$role->id))['copyOffer'])->toBeNull();
    }

    expect($estateOwn->fresh()->label)->toBe('Gate Liaison')
        ->and(prePermissions($estateOwn))->toBe(['estate.security.view']);
});

/* ------------------------------------------------------------------ */
/* removing an administrator                                           */
/* ------------------------------------------------------------------ */

it('removes an administrator by suspending them and taking their role and sites, never by deleting them', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    EstateAssignment::query()->create([
        'user_id' => $head->id,
        'tenant_id' => FacilitiesFixture::ESTATE,
        'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id,
        'is_active' => true,
    ]);

    expect($head->can('gemini.clients.view'))->toBeTrue();

    $this->actingAs($director)
        ->post('/settings/admins/'.$head->id.'/remove')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $head = $head->fresh();

    expect(User::query()->whereKey($head->id)->exists())->toBeTrue()
        ->and($head->status)->toBe('suspended')
        ->and($head->roles)->toBeEmpty()
        ->and(EstateAssignment::query()->where('user_id', $head->id)->where('is_active', true)->exists())->toBeFalse()

        // The assignment row is kept, deactivated: who held what, where, is history.
        ->and(EstateAssignment::query()->where('user_id', $head->id)->exists())->toBeTrue();

    $entry = AuditEntry::query()->where('action', 'platform.user_removed')->where('entity_id', (string) $head->id)->sole();

    expect($entry->actor_id)->toBe($director->id)
        ->and($entry->before['role'])->toBe(Role::HEAD_OF_SECURITY)
        ->and($entry->after['status'])->toBe('suspended');

    // They reach nothing now: a suspended account's session ends on its next request (B068).
    $this->actingAs($head)->get('/clients')->assertRedirect('/login');
    $this->assertGuest();

    // The screen shows what is left, and the way back is the one it names.
    $row = collect(preProps($this->actingAs($director)->get('/settings/admins'))['people'])->firstWhere('id', $head->id);

    expect($row['status'])->toBe('suspended')
        ->and($row['role'])->toBeNull();

    $this->actingAs($director)
        ->post('/settings/admins/'.$head->id, ['role' => Role::DISPATCHER, 'status' => 'active'])
        ->assertSessionHasNoErrors();

    expect($head->fresh()->status)->toBe('active')
        ->and($head->fresh()->hasRole(Role::DISPATCHER))->toBeTrue();
});
