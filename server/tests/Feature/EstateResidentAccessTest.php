<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Who may open the residents screens, and who is refused
|--------------------------------------------------------------------------
|
| EVERY ROUTE IS ASSERTED IN BOTH DIRECTIONS. A gate that only ever returns 200
| for the role it was written for proves nothing: what makes a permission real is
| the 403, and a middleware string with a typo in it fails open silently while
| every happy-path test goes on passing. So each of the nine routes below is
| requested twice — once by a role that holds the permission and once by a role
| that does not.
|
| THE ONE THAT MATTERS MOST IS THE SECRETARY ON `approve`. D-013 separated
| `approve` from `update` so that a role could prepare an irreversible act
| without being able to commit it, and approving a unit claim is exactly such an
| act: it binds a person to a household, deciding whose guest passes they may
| issue and whose gate they may be admitted at, and no edit afterwards unbinds
| the night somebody was let through. The Secretary holds Full on Residents —
| they may refuse a claim and ask for a document all day — and is refused the
| approval. If that ever returns 302 this file has caught the collapse of the
| ruling.
|
| AND `estate.residents.view` DOES NOT CARRY A BALANCE. The register is a
| different module from Dues & ledger, and D-010 locks the Property Manager out
| of the second while giving them Full on the first. `EstateResidentsTest` proves
| the payload withholds it; this file proves they can still open the screen,
| which is the other half of the same ruling — the separation is meant to narrow
| what they see, not to lock them out of their own register.
|
| A REAL ESTATE, OVER A REAL HOSTNAME. These requests go through subdomain
| tenancy exactly as production serves them, so what is under test is the whole
| stack a committee member actually meets: resolve the estate, authenticate,
| check the assignment, then check the module.
|
*/

uses(DatabaseTransactions::class);

/** The estate these requests are made against. */
const ACCESS_TENANT = 'restest';

/**
 * The estate's database, built once per process.
 *
 * SMALL ON PURPOSE, and unlike the fixture `EstateResidentsTest` builds. Nothing
 * here asserts a figure: what is being proven is which door opens for whom, and
 * that is reachable with four lots and one claim. Building 450 units and six
 * months of dues to check a middleware string would put half a minute in front
 * of every run of this file.
 *
 * The tenant ROW is not created here — it is created per test, inside the
 * transaction, so it rolls back. The database is not, because DDL cannot be
 * rolled back and because it is issued on the owner connection, which is a
 * different PDO from the one the test transaction is open on.
 */
function estateAccessDatabase(): string
{
    static $built = false;

    $database = config('tenancy.database.prefix').ACCESS_TENANT;

    /*
     * Declared on EVERY call and not only on the first. Laravel rebuilds the
     * application between tests, so a connection defined once inside the build
     * branch is gone by the second test — which reads as "connection not
     * configured" a long way from the fixture that forgot to redeclare it.
     *
     * A connection of its own rather than pointing `tenant` at the database.
     * This file's requests need the DEFAULT connection to stay central — tenancy
     * switches it per request — and a fixture that repointed it would leave
     * every assertion reading an estate database from outside a request.
     */
    config([
        'database.connections.estate_access_fixture' => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        ),
    ]);

    DB::purge('estate_access_fixture');

    if ($built) {
        return $database;
    }

    $owner = DB::connection('mysql_owner');
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::purge('estate_access_fixture');

    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--database' => 'estate_access_fixture',
        '--force' => true,
    ]);

    seedEstateAccessFixture('estate_access_fixture');

    $built = true;

    return $database;
}

/**
 * Four lots, four households, five phases and one claim still pending.
 *
 * Written with the query builder rather than through the models, because the
 * estate models resolve the default connection and the default connection here
 * is the central one. Nothing about what these tests prove depends on going
 * through a service.
 */
function seedEstateAccessFixture(string $connection): void
{
    $db = DB::connection($connection);

    foreach ([1, 2, 3, 4] as $n) {
        $db->table('estate_phases')->insert([
            'name' => 'Phase '.$n,
            'sequence' => $n,
            'block_count' => $n,
            'officers_assigned' => $n,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unitId = $db->table('units')->insertGetId([
            'reference' => 'Lot '.$n,
            'block' => 'Phase '.$n,
            'street' => 'Phase '.$n.' Drive',
            'type' => 'residential',
            'status' => 'occupied',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $householdId = $db->table('households')->insertGetId([
            'unit_id' => $unitId,
            'name' => 'Household '.$n,
            'access_restricted' => false,
            'last_active_at' => now()->subDays($n),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $db->table('residents')->insert([
            'household_id' => $householdId,
            'full_name' => 'Resident '.$n,
            'email' => 'resident'.$n.'@restest.test',
            'relationship' => 'owner',
            'is_primary' => true,
            'status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * A claim nobody has decided yet, created fresh for the test that decides it.
 *
 * FRESH EVERY TIME, and it has to be. These requests write to the estate
 * database, which is not inside the test transaction — tenancy opens it per
 * request — so a claim approved by one test would still be approved when the
 * next one tried to refuse it, and the refusal would fail for the wrong reason.
 */
function pendingAccessClaim(): int
{
    estateAccessDatabase();

    $db = DB::connection('estate_access_fixture');

    $unitId = (int) $db->table('units')->orderBy('id')->value('id');

    return (int) $db->table('unit_claims')->insertGetId([
        'unit_id' => $unitId,
        'claim_type' => 'unit',
        'submitted_name' => 'Access Claimant '.uniqid(),
        'submitted_phase' => 'Phase 1',
        'submitted_lot' => 'Lot 1',
        'match_result' => 'partial',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * The estate, as a tenant row this test can reach over its own hostname.
 *
 * Inserted with the query builder rather than through `Tenant::create()`, which
 * would fire the provisioning pipeline and try to create a database that already
 * exists. The row carries no `tenancy_db_username`, so stancl falls back to the
 * template connection's credentials — which is what the whole suite already
 * authenticates as.
 */
function estateAccessTenant(): Tenant
{
    estateAccessDatabase();

    if (Tenant::find(ACCESS_TENANT) === null) {
        DB::table('tenants')->insert([
            'id' => ACCESS_TENANT,
            'name' => 'Access Test Estate',
            'status' => 'active',
            'provisioned_at' => now(),
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The BARE subdomain, not the hostname: the resolver strips the central
        // domain off the host and looks up what remains. See EstateProvisioner.
        DB::table('domains')->insert([
            'tenant_id' => ACCESS_TENANT,
            'domain' => ACCESS_TENANT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return Tenant::findOrFail(ACCESS_TENANT);
}

/** A committee member of this estate, holding exactly one role. */
function estateAccessUser(string $roleName, Console $console = Console::Estate): User
{
    estateAccessTenant();

    $role = Role::named($roleName);

    $user = User::factory()->create([
        'console' => $console->value,
        'status' => 'active',
    ]);

    $user->syncRoles([$roleName]);

    EstateAssignment::updateOrCreate(
        ['user_id' => $user->id, 'tenant_id' => ACCESS_TENANT],
        ['role_id' => $role->id, 'is_active' => true],
    );

    return $user->fresh();
}

/** The estate's own hostname, as production serves it. */
function estateAccessUrl(string $path): string
{
    return 'http://'.ACCESS_TENANT.'.'.config('app.estate_domain').$path;
}

beforeEach(function () {
    /*
     * SEEDED ON THE CELL, NOT ON THE ROLE. A test database built before D-053
     * added the two Approver cells to the Residents row already HAS a Property
     * Manager, so a check for the role would find one and leave the matrix a
     * version behind — and every approval assertion below would then fail as a
     * 403 that looked like a broken route rather than a stale fixture.
     */
    $current = Role::query()
        ->where('name', Role::PROPERTY_MANAGER)
        ->whereHas('permissions', fn ($permission) => $permission->where('name', 'estate.residents.approve'))
        ->exists();

    if (! $current) {
        Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    }

    // Per-process cache, and it would otherwise hold whatever a previous file
    // left in it — including a matrix seeded before the Approver cells existed.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The page components are not built by this wave, and a request that tried
    // to resolve a Vite manifest entry for one would fail on an asset rather
    // than on a permission.
    $this->withoutVite();
});

/**
 * TENANCY IS ENDED AFTER EVERY REQUEST, AND IT IS NOT HOUSEKEEPING.
 *
 * A tenant request switches `database.default` to the estate connection and
 * nothing switches it back — in production the process ends, and there is
 * nothing to put right. In a test there is: `DatabaseTransactions` rolls back
 * "the default connection", resolved at teardown, so a test that left tenancy
 * initialised would roll back the ESTATE connection and leave the central
 * transaction open — holding a lock on the `tenants` row every later test tries
 * to insert. That failure reads as a 50-second lock wait timeout in a file about
 * permissions, which is a long way from its cause.
 */
afterEach(function () {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

/* ------------------------------------------------------------------ */
/* board 3 — estate structure */
/* ------------------------------------------------------------------ */

it('opens the estate structure screen for a Property Manager', function () {
    // Board 24 gives the Property Manager Full on Estate structure. They are the
    // role the module was written for.
    $this->actingAs(estateAccessUser(Role::PROPERTY_MANAGER))
        ->get(estateAccessUrl('/estate'))
        ->assertOk();
});

it('refuses the estate structure screen to a Treasurer, whatever the boards draw', function () {
    /*
     * Board 24's matrix gives the Treasurer an em dash on Estate structure, and
     * boards 05, 06, 25 and 35 all draw the item in the sidebar anyway. The
     * matrix wins (D-044) — and the sidebar test proves the item is absent,
     * while this proves the route behind it is shut. An item merely hidden from
     * a menu is not a permission.
     */
    $this->actingAs(estateAccessUser(Role::TREASURER))
        ->get(estateAccessUrl('/estate'))
        ->assertForbidden();
});

/* ------------------------------------------------------------------ */
/* boards 4, 31, 34 and 38 — reading the register */
/* ------------------------------------------------------------------ */

it('opens every residents screen for a Property Manager, who holds no ledger at all', function (string $path) {
    /*
     * The other half of D-010. The Property Manager is locked out of Dues &
     * ledger and holds Full on Residents, so every one of these opens — the
     * separation narrows what they are shown, and `EstateResidentsTest` proves
     * the balance is withheld from the payload they get.
     */
    $manager = estateAccessUser(Role::PROPERTY_MANAGER);

    expect($manager->can('estate.dues_ledger.view'))->toBeFalse();

    $this->actingAs($manager)->get(estateAccessUrl($path))->assertOk();
})->with([
    '/residents',
    '/residents/claims',
    '/residents/new',
    '/residents/lot-1',
]);

it('refuses every residents screen to a role holding no estate module', function (string $path) {
    /*
     * A Gemini dispatcher, assigned to this estate and holding not one
     * `estate.*` permission. Tenancy lets them resolve the estate and the module
     * gate refuses them, which is the two layers doing different jobs: the first
     * decides which community, the second decides which module.
     */
    $this->actingAs(estateAccessUser(Role::DISPATCHER, Console::Gemini))
        ->get(estateAccessUrl($path))
        ->assertForbidden();
})->with([
    '/residents',
    '/residents/claims',
    '/residents/new',
    '/residents/lot-1',
]);

it('answers 404 for a lot the estate does not have', function () {
    // Not a permission failure and not a 500. The route matches any lowercase
    // slug, so a mistyped bookmark has to land somewhere sensible.
    $this->actingAs(estateAccessUser(Role::PROPERTY_MANAGER))
        ->get(estateAccessUrl('/residents/lot-9999'))
        ->assertNotFound();
});

/* ------------------------------------------------------------------ */
/* adding a resident — `create`, and View does not reach it */
/* ------------------------------------------------------------------ */

it('lets a Property Manager put a person on the register', function () {
    $this->actingAs(estateAccessUser(Role::PROPERTY_MANAGER))
        ->post(estateAccessUrl('/residents'), [
            'phase' => 'Phase 2',
            'lot' => '2',
            'full_name' => 'Simone Barrett',
            'email' => 'simone.barrett@email.com',
            'phone' => '876 555 0388',
            'verification' => 'invite',
            // Lot 2 holds Household 2 already (M32 F1): the person joins it as a member.
            'household_choice' => 'member',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(DB::connection('estate_access_fixture')
        ->table('residents')
        ->where('full_name', 'Simone Barrett')
        ->exists())->toBeTrue();
});

it('refuses to let a Treasurer put a person on the register', function () {
    // Board 24 gives the Treasurer View on Residents. Reading who lives in the
    // estate is not the same as deciding who does.
    $this->actingAs(estateAccessUser(Role::TREASURER))
        ->post(estateAccessUrl('/residents'), [
            'phase' => 'Phase 2',
            'lot' => '2',
            'full_name' => 'Refused Addition',
            'verification' => 'invite',
        ])
        ->assertForbidden();

    expect(DB::connection('estate_access_fixture')
        ->table('residents')
        ->where('full_name', 'Refused Addition')
        ->exists())->toBeFalse();
});

/* ------------------------------------------------------------------ */
/* deciding a claim — `approve` is not `update`, and this is where it shows */
/* ------------------------------------------------------------------ */

it('lets a Property Manager approve a unit claim', function () {
    $claim = pendingAccessClaim();

    // Board 31's sidebar footer names Patricia Morgan, Property Manager, as the
    // reviewer. D-053 gave that role the Approver tag for this act.
    $this->actingAs(estateAccessUser(Role::PROPERTY_MANAGER))
        ->post(estateAccessUrl('/residents/claims/'.$claim.'/approve'))
        ->assertRedirect();

    expect(DB::connection('estate_access_fixture')
        ->table('unit_claims')
        ->where('id', $claim)
        ->value('status'))->toBe('approved');
});

it('refuses the approval to a Secretary who holds update and not approve', function () {
    $claim = pendingAccessClaim();
    $secretary = estateAccessUser(Role::SECRETARY);

    /*
     * THE D-013 TEST. The Secretary holds Full on Residents — every verb the
     * level grants, `update` among them — and does NOT hold `approve`, because
     * Full without the Approver tag must not grant it (D-008). Approving binds a
     * person to a household and no edit afterwards unbinds the night somebody
     * was let through, so it is the one act they may prepare and not commit.
     */
    expect($secretary->can('estate.residents.update'))->toBeTrue()
        ->and($secretary->can('estate.residents.approve'))->toBeFalse();

    $this->actingAs($secretary)
        ->post(estateAccessUrl('/residents/claims/'.$claim.'/approve'))
        ->assertForbidden();

    // And the claim is untouched, which is the point of the refusal rather than
    // a consequence of it.
    expect(DB::connection('estate_access_fixture')
        ->table('unit_claims')
        ->where('id', $claim)
        ->value('status'))->toBe('pending');
});

it('lets that same Secretary refuse a claim and ask for a document', function () {
    $secretary = estateAccessUser(Role::SECRETARY);
    $document = pendingAccessClaim();
    $rejected = pendingAccessClaim();

    /*
     * The other side of D-013, and the reason the split is a separation rather
     * than a demotion. Both of these are `update`: each is answered by making
     * the opposite decision, and neither authorises anybody against a unit.
     */
    $this->actingAs($secretary)
        ->post(estateAccessUrl('/residents/claims/'.$document.'/document'), ['document' => 'photo ID'])
        ->assertRedirect();

    $this->actingAs($secretary)
        ->post(estateAccessUrl('/residents/claims/'.$rejected.'/reject'), [
            'reason' => 'The lot is held by a household we have already verified.',
        ])
        ->assertRedirect();

    $claims = DB::connection('estate_access_fixture')->table('unit_claims');

    expect($claims->where('id', $document)->value('document_requested_at'))->not->toBeNull()
        ->and(DB::connection('estate_access_fixture')->table('unit_claims')->where('id', $rejected)->value('status'))
        ->toBe('rejected');
});

it('refuses a refusal and a document request to a Treasurer', function (string $action, array $payload) {
    // View on Residents reaches neither. A role that may read the register may
    // not answer for the estate to somebody claiming a unit in it.
    $claim = pendingAccessClaim();

    $this->actingAs(estateAccessUser(Role::TREASURER))
        ->post(estateAccessUrl('/residents/claims/'.$claim.'/'.$action), $payload)
        ->assertForbidden();

    expect(DB::connection('estate_access_fixture')
        ->table('unit_claims')
        ->where('id', $claim)
        ->value('status'))->toBe('pending');
})->with([
    ['reject', ['reason' => 'No.']],
    ['document', ['document' => 'photo ID']],
]);
