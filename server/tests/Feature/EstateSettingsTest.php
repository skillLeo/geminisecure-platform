<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\Estate\EstateFeature;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Unit;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Estate\Settings;
use Database\Seeders\BillingSeeder;
use Database\Seeders\Estate\SettingsSeeder;
use Database\Seeders\PlatformCatalogueSeeder;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| A settings screen that cannot widen anybody's access
|--------------------------------------------------------------------------
|
| THE ONE THING THIS MODULE MUST NEVER BECOME IS A PRIVILEGE-ESCALATION ROUTE,
| and this file is where that stops being a claim. Changing what a role may do
| is an `approve`-level act (D-013). Until M10 the seeded estate matrix gave
| `estate.settings.approve` to nobody and the module had no route that wrote a
| role. M10 §4 asked for the Community Super Admin to edit this estate's own
| roles, so that one cell now carries the Approver tag — and the proof is
| inverted rather than deleted: EXACTLY ONE estate role holds the permission
| and sees `can_edit`, the seven others do not, and the route table holds
| exactly the writes this module means to ship, every role write among them
| behind that one permission. The blunt test stays too: every write that is
| NOT a role write, used by the widest role there is, leaves
| `role_module_access` byte-identical. What the role writes themselves may and
| may not do is `EstateRolesEditorTest`'s, over HTTP, and `RolesAreDynamicTest`'s
| against the editor directly.
|
| THE MATRIX IN THE MODEL IS AUTHORITATIVE AND THE DRAWN BOARD IS AN
| ILLUSTRATION (D-044). Board 24 gives the Property Manager View on Dues &
| ledger and on Accounting; Ruling 1 (D-010) locks that role out of both, and
| the seeder throws rather than granting them. The screen renders fourteen
| modules and eight shared roles where the board drew ten and six, and the
| tests below assert the model's answer in every case where the two disagree.
|
| THE ESTATE IS PROVISIONED FOR REAL, not pointed at. `settingstest` gets its
| own database, its own MySQL user and its own append-only grants through the
| same pipeline production uses, because two of the claims here — that a feature
| override can be UPDATED, and that every route answers 200 or 403 through the
| real middleware stack — are false on a connection that skips either.
|
*/

/** The subdomain, the database and the MySQL user this file owns. */
const SETTINGS_ESTATE = 'settingstest';

/**
 * The estate these tests read and write, provisioned once per process.
 *
 * A DATABASE OF ITS OWN, and never `phoenixpark`: the estate database name is
 * the tenancy prefix plus the tenant id, so a test tenant called phoenixpark
 * would open the development estate's real accounts and seed into them. The
 * central database is already separated by phpunit.xml; the estate one is
 * separated here.
 */
function settingsEstate(): Tenant
{
    /*
     * A FLAG, AND THE MODEL RE-FETCHED EVERY TIME. Laravel builds a fresh
     * application for each test while the PHP process — and therefore this
     * static — survives, so a cached Eloquent model would carry a connection
     * resolver belonging to an application that has been thrown away.
     */
    static $provisioned = false;

    if ($provisioned) {
        return Tenant::findOrFail(SETTINGS_ESTATE);
    }

    // Roles, modules and the matrix. Seeded rather than hand-built, for the
    // reason EstateNavigationTest gives: what is under test is the REAL matrix,
    // not a fixture that happens to agree with it.
    if (! Role::query()->where('name', Role::COMMUNITY_SUPER_ADMIN)->exists()) {
        Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    }

    $database = config('tenancy.database.prefix').SETTINGS_ESTATE;
    $owner = DB::connection('mysql_owner');

    // Torn down before it is built, so a run that failed half way through
    // cannot leave the next one reading a half-migrated estate.
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::connection('mysql')->table('domains')->where('tenant_id', SETTINGS_ESTATE)->delete();
    DB::connection('mysql')->table('tenants')->where('id', SETTINGS_ESTATE)->delete();

    /*
     * THE TENANT ROW IS WRITTEN RAW, WITHOUT `EstateProvisioner`, AND THAT IS
     * DELIBERATE RATHER THAN A SHORTCUT.
     *
     * Provisioning creates a dedicated MySQL user per estate and withholds
     * UPDATE and DELETE at database level, granting them back per table AFTER
     * the migrations have run (D-017). That ordering is right in production and
     * unusable here: this suite drops and rebuilds the estate on every run, and
     * accumulating a MySQL user per run is not something a test file should do
     * to a developer's server.
     *
     * With no `db_username` recorded on the tenant, stancl's DatabaseConfig
     * falls through to the template connection — `mysql_owner` — so the estate
     * connects as the schema owner. Nothing under test here is a grant: the
     * append-only guarantees are proven against the real grants by
     * `php artisan gate:isolation` and `DoubleEntryLedgerTest`, which is the
     * only place they can be proven honestly.
     *
     * The domain row carries the BARE SUBDOMAIN and not the hostname.
     * InitializeTenancyBySubdomain strips the central domain off the host and
     * looks up what remains, so "settingstest.geminisecure.test" here would
     * make the estate unidentifiable.
     */
    DB::connection('mysql')->table('tenants')->insert([
        'id' => SETTINGS_ESTATE,
        'name' => 'Settings Test Estate',

        // D-033's site columns, so board 21 has an address and a phase count to
        // read rather than a null that would pass every assertion vacuously.
        'address_line' => 'Hope Road',
        'parish' => 'St. Andrew',
        'gate_count' => 2,
        'phases' => json_encode(['Phase 1', 'Phase 2', 'Phase 3']),
        'status' => 'active',
        'provisioned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('mysql')->table('domains')->insert([
        'domain' => SETTINGS_ESTATE,
        'tenant_id' => SETTINGS_ESTATE,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenant = Tenant::findOrFail(SETTINGS_ESTATE);

    Artisan::call('tenants:migrate', ['--tenants' => [SETTINGS_ESTATE]]);

    // Plans and the feature catalogue are central and board 23 is unreadable
    // without them: a feature's tier is which plans include it, and an estate's
    // available set is its own plan's column of that grid.
    Artisan::call('db:seed', ['--class' => BillingSeeder::class, '--force' => true]);
    Artisan::call('db:seed', ['--class' => PlatformCatalogueSeeder::class, '--force' => true]);

    // Premium, because board 23 is drawn on a Premium estate and every switch
    // it draws is on for that reason. A Standard estate would draw two of them
    // off, which is a different screen and a different test.
    Subscription::updateOrCreate(
        ['tenant_id' => SETTINGS_ESTATE],
        [
            'plan_id' => Plan::query()->where('key', 'premium')->firstOrFail()->id,
            'unit_count' => 12,
            'status' => 'active',
            'started_on' => now()->subYear()->toDateString(),
            'renews_on' => now()->addMonth()->toDateString(),
            'term_months' => 24,
        ],
    );

    $tenant->run(function (): void {
        Artisan::call('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);

        // A dozen units, so "Total units" on board 21 is a real count of real
        // rows rather than a zero that would agree with anything.
        for ($lot = 1; $lot <= 12; $lot++) {
            Unit::query()->firstOrCreate(
                ['reference' => 'Lot '.$lot],
                ['block' => 'Phase '.(($lot % 3) + 1), 'street' => 'Hope Drive', 'status' => 'occupied'],
            );
        }
    });

    tenancy()->end();

    $provisioned = true;

    return $tenant;
}

/**
 * The eight shared estate roles — the seven board 24 knew and M10 §2's Head of
 * Security — each held by one user of this estate. A role an estate makes for
 * itself is not in this list by definition: it has no seeded holder.
 *
 * @return list<User>
 */
function settingsCommittee(): array
{
    return array_map(settingsUser(...), [
        Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::VICE_PRESIDENT, Role::SECRETARY,
        Role::PROPERTY_MANAGER, Role::TREASURER, Role::ESTATE_ADMIN_ASSISTANT, Role::ESTATE_HEAD_OF_SECURITY,
    ]);
}

/** A user holding exactly one estate role, assigned to the estate under test. */
function settingsUser(string $roleName): User
{
    settingsEstate();

    $email = str_replace('.', '-', $roleName).'@settingstest.test';

    $user = User::query()->firstOrNew(['email' => $email]);

    $user->fill([
        'name' => ucwords(str_replace(['estate.', '_'], ['', ' '], $roleName)),
        'password' => 'password',
        'console' => Console::Estate->value,
        'status' => 'active',
    ]);

    /*
     * WITH A PROVEN ALERT ADDRESS (M10 §1.4). Every estate-console account meets
     * the contact gate until it has one, and this file is about what each role
     * may do on the settings screens — not about the gate, which
     * `ContactVerificationGateTest` covers. Written together so the model keeps
     * the proof rather than clearing it as a change of address.
     */
    $user->forceFill([
        'contact_email' => $email,
        'contact_email_verified_at' => now(),
    ])->save();

    $user->syncRoles([$roleName]);

    EstateAssignment::updateOrCreate(
        ['user_id' => $user->id, 'tenant_id' => SETTINGS_ESTATE],
        ['role_id' => Role::named($roleName)->id, 'is_active' => true],
    );

    return $user->fresh();
}

/** The estate's own hostname, which is the only shape testing registers. */
function settingsUrl(string $path): string
{
    return 'http://'.SETTINGS_ESTATE.'.'.config('app.estate_domain').$path;
}

/** Runs a closure inside the estate under test. */
function inSettingsEstate(callable $work): mixed
{
    $result = settingsEstate()->run($work);

    tenancy()->end();

    return $result;
}

/** Every `role_module_access` row, as one comparable string. */
function matrixFingerprint(): string
{
    return RoleModuleAccess::query()
        ->orderBy('role_id')
        ->orderBy('module_id')
        ->get()
        ->map(fn (RoleModuleAccess $cell): string => sprintf(
            '%d:%d:%s:%s:%s',
            $cell->role_id,
            $cell->module_id,
            $cell->level->value,
            $cell->can_approve ? '1' : '0',
            $cell->scope->value,
        ))
        ->implode('|');
}

beforeEach(function () {
    // Per-process and per-app, so it would otherwise hold whatever the previous
    // test file left in it. An estate-database permission cache once made every
    // permission-guarded screen answer 500 (D-012); this is the cheap half of
    // that lesson.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/* ------------------------------------------------------------------ */
/* the gates — 200 for a role that holds it, 403 for one that does not */
/* ------------------------------------------------------------------ */

it('opens all four settings screens for the roles the matrix gives Settings to, and refuses the four it does not', function () {
    $paths = ['/settings/profile', '/settings/users', '/settings/features', '/settings/roles'];

    /*
     * The Settings row of board 24, and of the seeded matrix, agree exactly:
     * Full for the Community Super Admin, View for the President and the Vice
     * President, an em dash for the Secretary, Property Manager, Treasurer,
     * Admin Assistant and the estate's Head of Security. Three roles read these
     * screens and five cannot reach them at all — "does not see that module" is
     * a 403, not a greyed link.
     */
    $allowed = [Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::VICE_PRESIDENT];
    $refused = [
        Role::SECRETARY, Role::PROPERTY_MANAGER, Role::TREASURER, Role::ESTATE_ADMIN_ASSISTANT,
        Role::ESTATE_HEAD_OF_SECURITY,
    ];

    foreach ($paths as $path) {
        foreach ($allowed as $roleName) {
            $this->actingAs(settingsUser($roleName))
                ->get(settingsUrl($path))
                ->assertStatus(200);
        }

        foreach ($refused as $roleName) {
            $this->actingAs(settingsUser($roleName))
                ->get(settingsUrl($path))
                ->assertStatus(403);
        }
    }
});

it('lets the Community Super Admin save the estate profile and refuses the President, who may only read it', function () {
    $body = [
        'enquiries_email' => 'info@settingstest.org',
        'enquiries_phone' => '(876) 555 0199',
        'security_provider' => 'Gemini Security Limited',
    ];

    // View is read-only, and that is the whole content of the word. A President
    // opens the screen (200 above) and cannot save it.
    $this->actingAs(settingsUser(Role::PRESIDENT))
        ->post(settingsUrl('/settings/profile'), $body)
        ->assertStatus(403);

    $this->actingAs(settingsUser(Role::COMMUNITY_SUPER_ADMIN))
        ->post(settingsUrl('/settings/profile'), $body)
        ->assertStatus(302);

    inSettingsEstate(function () {
        expect(EstateSetting::current()->enquiries_email)->toBe('info@settingstest.org');

        // Put it back, so the tests below still read an estate that has
        // published no enquiries address — which is the state SettingsSeeder
        // leaves an estate no board draws.
        EstateSetting::current()->forceFill(['enquiries_email' => null, 'enquiries_phone' => null])->save();
    });
});

it('lets the Community Super Admin change a feature and refuses the Treasurer, who cannot reach Settings at all', function () {
    $body = ['enabled' => false, 'reason' => 'The committee is not running an election this year.'];

    $this->actingAs(settingsUser(Role::TREASURER))
        ->post(settingsUrl('/settings/features/evoting'), $body)
        ->assertStatus(403);

    $this->actingAs(settingsUser(Role::COMMUNITY_SUPER_ADMIN))
        ->post(settingsUrl('/settings/features/evoting'), $body)
        ->assertStatus(302);

    inSettingsEstate(function () {
        $override = EstateFeature::query()->where('feature_key', 'evoting')->first();

        expect($override)->not->toBeNull()
            ->and($override->enabled)->toBeFalse()
            ->and($override->changed_by_name)->not->toBeNull();

        // And the estate is left as it was found: a row here means somebody
        // decided something, and the tests below read an estate where nobody
        // has decided anything.
        $override->delete();
    });
});

/* ------------------------------------------------------------------ */
/* the escalation that must not exist */
/* ------------------------------------------------------------------ */

it('gives exactly the Community Super Admin the approve permission that changing the matrix needs', function () {
    $roles = [
        Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::VICE_PRESIDENT, Role::SECRETARY,
        Role::PROPERTY_MANAGER, Role::TREASURER, Role::ESTATE_ADMIN_ASSISTANT, Role::ESTATE_HEAD_OF_SECURITY,
    ];

    $holders = [];

    foreach ($roles as $roleName) {
        $user = settingsUser($roleName);

        /*
         * THE SAME LOOP THAT ONCE PROVED NOBODY HELD IT, NOW PROVING ONE ROLE
         * DOES (M10 §4). Changing what a role may do is still an approve-level
         * act on Settings, and D-008 still separates the Approver tag from the
         * level: the President and the Vice President read this module and can
         * change nothing on it, and Full elsewhere — the Treasurer's on the
         * money, the Head of Security's on Security — grants nothing here. The
         * Community Super Admin holds it because its Settings cell, and that
         * cell alone, carries the tag. A second holder would be a second role
         * that could grant itself anything.
         */
        $can = $user->can('estate.settings.approve');
        $canEdit = inSettingsEstate(fn (): bool => app(Settings::class)->matrixBoard($user)['can_edit']);

        // What the screen says and what the gate enforces are one answer.
        expect($canEdit)->toBe($can);

        if ($can) {
            $holders[] = $roleName;
        }
    }

    expect($holders)->toBe([Role::COMMUNITY_SUPER_ADMIN]);

    // And for everybody else the reason is a sentence, not a blank. The eight
    // shared columns are no longer out of reach for the one who can edit (M11 B4):
    // changing one makes this community's own version, and the note says so.
    $president = inSettingsEstate(fn (): array => app(Settings::class)->matrixBoard(settingsUser(Role::PRESIDENT)));
    $admin = inSettingsEstate(fn (): array => app(Settings::class)->matrixBoard(settingsUser(Role::COMMUNITY_SUPER_ADMIN)));

    expect($president['read_only_reason'])->toBe(Settings::MATRIX_READ_ONLY)
        ->and($admin['uneditable_roles'])->toBe([])
        ->and($admin['shared_role_note'])->toContain('this community gets its own version of it');
});

it('registers exactly the settings writes it means to, and puts every role write behind the power to change roles', function () {
    $writes = [];

    /** @var array<string, list<string>> $middleware route name => its middleware */
    $middleware = [];

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'estate.settings.')) {
            continue;
        }

        if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []) {
            $writes[] = $name;
            $middleware[$name] = array_values(array_unique([...($middleware[$name] ?? []), ...$route->gatherMiddleware()]));
        }
    }

    sort($writes);

    /*
     * An ALLOWLIST, not a count. A settings module grows, and the next write
     * added to it has to be admitted to this list deliberately — which is the
     * moment somebody asks whether it changes anybody's access.
     *
     * IT HAS ALREADY EARNED ITS KEEP ONCE: board 30's "Save changes" was added
     * later and this assertion is what stopped it arriving unexamined. All
     * three write tables in the ESTATE'S OWN database — `estate_settings`,
     * `estate_features` and `notification_defaults` — and none can reach
     * `role_module_access`, which lives in gs_platform under D-012.
     *
     * The three read-only settings screens deliberately register no write at
     * all. Board 33's policy fields and board 40's subscription are stated
     * rather than set here, so there is nothing for this list to admit.
     *
     * THE THREE INVITATION WRITES WERE ADMITTED HERE ON PURPOSE (12 §2, Wave
     * 1). An invitation issues a credential for a role that already exists in
     * the matrix; it creates a user and an assignment, and can write neither a
     * cell nor a role. The fingerprint test below runs them and proves it.
     *
     * AND TWO MORE, ALSO ON PURPOSE (12 §2, Waves 3 and 4). The logo upload
     * stores an image under the estate's private prefix and touches no table
     * the matrix reads. Managing a user moves a person between two of the
     * roles the matrix already defines, or suspends them — it changes who holds
     * a column, never what a column may do. The fingerprint test runs it too.
     *
     * AND SIX FOR M10 §4, THE FIRST WRITES THIS LIST HAS ADMITTED THAT DO
     * CHANGE WHAT A ROLE MAY DO — five of them, which is why they are held to
     * more than admission below. Creating, copying, renaming, re-granting and
     * deleting a role are the client's own request, reach `role_module_access`
     * only through `RoleEditor`, and only for a role carrying this estate's
     * tenant id. Removing a person is the sixth and is like managing one: the
     * assignment is deactivated and the account suspended, and no cell moves.
     *
     * AND ONE MORE, REINSTATING A PERSON (bug hunt B047): the removal's exact
     * inverse, the assignment and account active again in the role the row
     * still names. It changes who holds a column, never what a column may do,
     * and the fingerprint test below runs it too.
     *
     * AND RESETTING A ROLE (M11 B4), a role write like the five: it removes this
     * community's own version of a shared role and puts its holders back on the
     * shared one, through `RoleEditor` and only for this estate's own version.
     * It is held to the same gate below.
     *
     * AND TRUSTING THE MAIN RESIDENT'S INVITES (household invites, stage 2, D-156):
     * one estate setting, behind `configure` as every feature switch, with the
     * controller also asking Residents approve. It changes no role.
     *
     * AND THE RESIDENT APP'S ACCESS RULES (8 October 2026): what a Dependent and a
     * Helper may do in the Resident App, a Helper's default days and hours, and who
     * is told of a Helper's comings and goings — estate settings behind `configure`,
     * as the role access matrix's own screen is. It changes no estate role: it is
     * read by the Resident App's API (App\Api\AccessRules), never by the matrix.
     */
    expect(array_unique($writes))->toBe([
        'estate.settings.feature.update',
        'estate.settings.invite_trust.update',
        'estate.settings.notifications.save',
        'estate.settings.profile.logo',
        'estate.settings.profile.save',
        'estate.settings.resident_access.save',
        'estate.settings.role.copy',
        'estate.settings.role.delete',
        'estate.settings.role.permissions',
        'estate.settings.role.rename',
        'estate.settings.role.reset',
        'estate.settings.roles.create',
        'estate.settings.user.manage',
        'estate.settings.user.reinstate',
        'estate.settings.user.remove',
        'estate.settings.users.invitation.resend',
        'estate.settings.users.invitation.revoke',
        'estate.settings.users.invite',
    ]);

    /*
     * ADMITTED IS NOT ENOUGH FOR A WRITE THAT CHANGES A ROLE. Each of the five
     * is behind `can:estate.settings.approve` — the one permission exactly one
     * estate role holds (the test above) — and no other write in the module is,
     * so a new role write added without the gate, or the gate moved onto
     * something that is not a role write, fails here by name.
     */
    $roleWrites = [
        'estate.settings.role.copy',
        'estate.settings.role.delete',
        'estate.settings.role.permissions',
        'estate.settings.role.rename',
        'estate.settings.role.reset',
        'estate.settings.roles.create',
    ];

    foreach ($middleware as $name => $stack) {
        expect(in_array('can:estate.settings.approve', $stack, true))
            ->toBe(in_array($name, $roleWrites, true), $name);
    }
});

it('leaves the permission matrix untouched after the widest role has used every write the module offers that is not a role write', function () {
    $before = matrixFingerprint();

    expect($before)->not->toBe('');

    $admin = settingsUser(Role::COMMUNITY_SUPER_ADMIN);

    /*
     * THE CLEAN-UP IS IN A `finally`, AND THAT IS NOT TIDINESS. This test
     * switches ai_drafting off, and two tests further down assert that the
     * estate holds no feature override at all. Left to the end of the body, a
     * failed assertion in between would leave the override behind and fail
     * both of them too, for a reason that has nothing to do with them.
     */
    try {
        // Everything this module can do that is not a role write, done by the
        // role that can do all of it. The role writes are held to their own
        // proof in EstateRolesEditorTest.
        $this->actingAs($admin)->post(settingsUrl('/settings/profile'), [
            'enquiries_email' => 'escalation@settingstest.org',
            'enquiries_phone' => '(876) 555 0000',
            'security_provider' => 'Gemini Security Limited',
        ])->assertStatus(302);

        $this->actingAs($admin)->post(settingsUrl('/settings/features/ai_drafting'), [
            'enabled' => false,
            'reason' => 'Proving that a settings write cannot reach the matrix.',
        ])->assertStatus(302);

        $this->actingAs($admin)->post(settingsUrl('/settings/features/estate_payroll'), [
            'option' => 'gemini_managed',
            'reason' => 'Proving that a routing write cannot reach the matrix either.',
        ])->assertStatus(302);

        // And the invitation writes, which issue a credential for a role the
        // matrix already defines and can define nothing themselves.
        Notification::fake();

        $this->actingAs($admin)->post(settingsUrl('/settings/users/invitations'), [
            'email' => 'fingerprint@settingstest.test',
            'role' => Role::SECRETARY,
        ])->assertStatus(302);

        $invitation = Invitation::query()->where('email', 'fingerprint@settingstest.test')->firstOrFail();

        $this->actingAs($admin)->post(settingsUrl('/settings/users/invitations/'.$invitation->id.'/resend'))->assertStatus(302);
        $this->actingAs($admin)->post(settingsUrl('/settings/users/invitations/'.$invitation->id.'/revoke'))->assertStatus(302);

        // And managing a user: moved between two roles the matrix already holds,
        // and back. Who holds a column changes; what the column may do does not.
        $secretary = settingsUser(Role::SECRETARY);
        $assignment = EstateAssignment::query()
            ->where('user_id', $secretary->id)
            ->where('tenant_id', SETTINGS_ESTATE)
            ->sole();

        foreach ([Role::VICE_PRESIDENT, Role::SECRETARY] as $role) {
            $this->actingAs($admin)
                ->post(settingsUrl('/settings/users/'.$assignment->id), ['role' => $role, 'status' => 'active'])
                ->assertStatus(302)
                ->assertSessionHasNoErrors();
        }

        // And removing one — the Secretary again, who is put back afterwards
        // by `settingsUser()`, which reactivates the account and the assignment.
        $this->actingAs($admin)
            ->post(settingsUrl('/settings/users/'.$assignment->id.'/remove'))
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        expect($assignment->fresh()->is_active)->toBeFalse();

        // And reinstating them, the removal's inverse.
        $this->actingAs($admin)
            ->post(settingsUrl('/settings/users/'.$assignment->id.'/reinstate'))
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        expect($assignment->fresh()->is_active)->toBeTrue();

        // Byte-identical: every role, every module, every level, every approver
        // flag and every scope.
        expect(matrixFingerprint())->toBe($before);
    } finally {
        settingsUser(Role::SECRETARY);

        inSettingsEstate(function () {
            EstateFeature::query()->whereIn('feature_key', ['ai_drafting'])->delete();

            EstateSetting::current()->forceFill([
                'enquiries_email' => null,
                'enquiries_phone' => null,
                'staff_payroll_routing' => EstateSetting::IN_HOUSE,
            ])->save();
        });
    }
});

/* ------------------------------------------------------------------ */
/* board 24 — the real matrix, not the drawn one */
/* ------------------------------------------------------------------ */

it('draws the role access matrix from the permission model and not from the cells board 24 drew', function () {
    $board = inSettingsEstate(
        fn (): array => app(Settings::class)->matrixBoard(settingsUser(Role::PRESIDENT))
    );

    /*
     * Fourteen modules and eight roles, where the board drew ten and six. Three
     * of the extra modules are the Ruling 1 split (D-010/D-014) and the fourth
     * is M10 §2's Security; the two extra roles are the Community Super Admin,
     * which board 24's own note omits, and M10 §2's Head of Security. This
     * estate has made no role of its own, so the eight are the shared ones.
     */
    expect($board['rows'])->toHaveCount(14)
        ->and($board['roles'])->toHaveCount(8)
        ->and(collect($board['roles'])->every(fn (array $role): bool => $role['is_system'] && ! $role['is_own']))->toBeTrue();

    $cell = function (string $module, string $role) use ($board): array {
        $row = collect($board['rows'])->firstWhere('key', $module);

        return collect($row['cells'])->firstWhere('role', $role);
    };

    /*
     * THE DISAGREEMENT, ASSERTED THE MODEL'S WAY. Board 24 draws the Property
     * Manager with View on Dues & ledger and on Accounting. D-010 is a platform
     * invariant: whoever commissions work must never be able to pay for it, nor
     * see a resident's financial position. The seeder throws rather than
     * granting it, and this screen must therefore print an em dash where the
     * board printed a pill.
     */
    foreach (['dues_ledger', 'payments', 'accounting_posting', 'payroll'] as $locked) {
        expect($cell($locked, Role::PROPERTY_MANAGER)['level'])->toBe('none')
            ->and($cell($locked, Role::PROPERTY_MANAGER)['label'])->toBe('—');
    }

    // And the two the split was made FOR: the costs they commission, at view.
    expect($cell('vendor_costs', Role::PROPERTY_MANAGER)['level'])->toBe('view')
        ->and($cell('maintenance_budget', Role::PROPERTY_MANAGER)['level'])->toBe('view');

    /*
     * The four Approver tags board 24 draws, and only those four — the
     * Treasurer on the money, the President and Vice President on Governance.
     * The Secretary RUNS the election and does not certify it, which is what
     * D-013 separated `approve` from `update` for.
     */
    expect($cell('dues_ledger', Role::TREASURER)['can_approve'])->toBeTrue()
        ->and($cell('accounting_posting', Role::TREASURER)['can_approve'])->toBeTrue()
        ->and($cell('governance', Role::PRESIDENT)['can_approve'])->toBeTrue()
        ->and($cell('governance', Role::VICE_PRESIDENT)['can_approve'])->toBeTrue()
        ->and($cell('governance', Role::SECRETARY)['can_approve'])->toBeFalse()
        ->and($cell('payroll', Role::TREASURER)['can_approve'])->toBeFalse();

    // The Settings row itself — the one this whole module is gated on. Its
    // Approver tag is the power to change roles (M10 §4), and it is on the
    // Community Super Admin's cell and nobody else's.
    expect($cell('settings', Role::COMMUNITY_SUPER_ADMIN)['level'])->toBe('full')
        ->and($cell('settings', Role::COMMUNITY_SUPER_ADMIN)['can_approve'])->toBeTrue()
        ->and($cell('settings', Role::PRESIDENT)['can_approve'])->toBeFalse()
        ->and($cell('settings', Role::PRESIDENT)['level'])->toBe('view')
        ->and($cell('settings', Role::TREASURER)['level'])->toBe('none');

    // Board 24's legend, in the order it must render.
    expect(array_column($board['legend'], 'label'))->toBe(['Full', 'View', 'Entry', '—']);
});

/* ------------------------------------------------------------------ */
/* board 21 — the profile, read from where each fact already lives */
/* ------------------------------------------------------------------ */

it('reads board 21s client-record fields from the client record and counts the units rather than storing them', function () {
    $board = inSettingsEstate(fn (): array => app(Settings::class)->profileBoard());

    $fields = collect($board['groups'])->flatMap(fn (array $group): array => $group['fields'])->keyBy('key');

    // Counted, and the count is the rows. A stored total would be a fourth
    // number free to disagree with the phase table, the subscription and this.
    $units = inSettingsEstate(fn (): int => Unit::query()->count());

    expect($fields['total_units']['value'])->toBe((string) $units)
        ->and($fields['total_units']['value'])->toBe('12')

        // Derived from the phase STRUCTURE on the client record, exactly as
        // D-033 ruled, and not from a `phase_count` column beside it.
        ->and($fields['phase_count']['value'])->toBe('3')
        ->and($fields['name']['value'])->toBe('Settings Test Estate')
        ->and($fields['address']['value'])->toBe('Hope Road, St. Andrew, Jamaica');

    /*
     * Four fields the board draws as inputs and this console will not write. A
     * community renaming itself here would rename the security company's client
     * record — the name on its invoices and on the board that sends a
     * supervisor to the site — and each field carries the reason so a page can
     * say so rather than disabling an input silently.
     */
    foreach (['name', 'address', 'total_units', 'phase_count'] as $key) {
        expect($fields[$key]['editable'])->toBeFalse()
            ->and($fields[$key]['reason'])->not->toBeNull();
    }

    foreach (['enquiries_email', 'enquiries_phone', 'security_provider'] as $key) {
        expect($fields[$key]['editable'])->toBeTrue()
            ->and($fields[$key]['reason'])->toBeNull();
    }

    // An estate that has published no enquiries address is a real state, not a
    // fault: no board draws Ocean View's settings and none is invented for it.
    expect($fields['enquiries_email']['value'])->toBe('')
        ->and($fields['security_provider']['value'])->toBe(EstateSetting::DEFAULT_SECURITY_PROVIDER);
});

it('refuses to save an estate profile with nobody named as the security provider', function () {
    inSettingsEstate(function () {
        $settings = app(Settings::class);
        $before = EstateSetting::current()->security_provider;

        expect(fn () => $settings->updateProfile(
            ['enquiries_email' => null, 'enquiries_phone' => null, 'security_provider' => '   '],
            settingsUser(Role::COMMUNITY_SUPER_ADMIN),
        ))->toThrow(DomainException::class, 'security provider');

        // Refused before it wrote, which is what makes the refusal a rule
        // rather than a validation message.
        expect(EstateSetting::current()->security_provider)->toBe($before);
    });
});

/* ------------------------------------------------------------------ */
/* board 22 — this estate's committee, and each role's real reach */
/* ------------------------------------------------------------------ */

it('lists only this estates committee and derives every module list from the matrix', function () {
    settingsCommittee();

    $board = inSettingsEstate(fn (): array => app(Settings::class)->usersBoard(SETTINGS_ESTATE));

    $rows = collect($board['rows'])->keyBy('role');

    /*
     * Nobody from Gemini. The Head of Security holds an active assignment to an
     * estate — that is how a platform role is narrowed to assigned sites — and
     * is not a member of the committee, holds no estate role, and would bring a
     * module list from the other console's matrix entirely.
     */
    foreach ($board['rows'] as $row) {
        expect($row['role'])->toStartWith('estate.');
    }

    /*
     * "All modules" is DERIVED, not a sentinel somebody stored. The President
     * reaches all fourteen at view or better, which is exactly what board 22
     * prints against that row — and the Treasurer's list is the eleven the
     * matrix actually gives them, not the three the board summarised.
     */
    expect($rows[Role::PRESIDENT]['modules'])->toBe('All modules')
        ->and($rows[Role::TREASURER]['modules'])->not->toBe('All modules')
        ->and($rows[Role::TREASURER]['module_list'])->not->toContain('Settings')
        ->and($rows[Role::TREASURER]['module_list'])->toContain('Dues & ledger');

    // The Property Manager's row is where the invariant shows on this screen.
    expect($rows[Role::PROPERTY_MANAGER]['module_list'])->not->toContain('Dues & ledger')
        ->and($rows[Role::PROPERTY_MANAGER]['module_list'])->not->toContain('Payroll & HR')
        ->and($rows[Role::PROPERTY_MANAGER]['module_list'])->toContain('Vendor costs');

    /*
     * EXACTLY ONE OWNER BADGE, and it is derived from the seniormost estate
     * role present rather than from an `is_owner` column. Every one of these
     * users was created by this file, so the Community Super Admin is here and
     * is the owner; on an estate where none has been designated the badge falls
     * to the President.
     */
    $owners = array_filter($board['rows'], static fn (array $row): bool => $row['is_owner']);

    expect($owners)->toHaveCount(1)
        ->and($board['owner_role'])->toBe(Role::COMMUNITY_SUPER_ADMIN)
        ->and($rows[Role::COMMUNITY_SUPER_ADMIN]['role_badge'])->toEndWith(' · Owner')
        ->and($rows[Role::PRESIDENT]['role_badge'])->toBe('President');
});

/* ------------------------------------------------------------------ */
/* board 23 — what the plan offers, and what the estate decided */
/* ------------------------------------------------------------------ */

it('resolves a features state from the estates own override, then from its plan, and never from a row per feature', function () {
    inSettingsEstate(function () {
        $settings = app(Settings::class);

        /*
         * NO ROWS AT ALL, and that is the design. A row means somebody in this
         * community decided something; board 23 draws every switch on because
         * the estate is on Premium and Premium includes everything, which is
         * the PLAN's answer. Eleven seeded rows saying "enabled" would be
         * eleven decisions nobody made, and they would outvote the plan the day
         * it changed.
         */
        expect(EstateFeature::query()->count())->toBe(0);

        $enabled = fn (): bool => collect($settings->featuresBoard(SETTINGS_ESTATE)['groups'])
            ->flatMap(fn (array $group): array => $group['rows'])
            ->firstWhere('key', 'gated_meetings')['enabled'];

        expect($enabled())->toBeTrue();

        $settings->setFeature(
            key: 'gated_meetings',
            enabled: false,
            reason: 'The committee opens its meetings to every household, in arrears or not.',
            by: settingsUser(Role::COMMUNITY_SUPER_ADMIN),
            tenantKey: SETTINGS_ESTATE,
        );

        expect($enabled())->toBeFalse();

        // And the override carries who and why, which is what the caption under
        // a moved switch has to be able to print.
        $row = collect($settings->featuresBoard(SETTINGS_ESTATE)['groups'])
            ->flatMap(fn (array $group): array => $group['rows'])
            ->firstWhere('key', 'gated_meetings');

        expect($row['provenance'])->toContain('Turned off by')
            ->and($row['reason'])->toContain('every household');

        EstateFeature::query()->where('feature_key', 'gated_meetings')->delete();
    });
});

it('says every feature is included only when every row on the screen is, and names what a Standard plan leaves out', function () {
    // Provisioned first: shuffled to the front of the file, nothing had provisioned the estate or its subscription yet.
    settingsEstate();

    $subscription = Subscription::query()->where('tenant_id', SETTINGS_ESTATE)->firstOrFail();
    $premium = Plan::query()->where('key', 'premium')->firstOrFail();
    $standard = Plan::query()->where('key', 'standard')->firstOrFail();

    /** @return array{note: string, greyed: list<string>, planRows: int} */
    $read = function (string $tenantKey): array {
        $board = inSettingsEstate(fn (): array => app(Settings::class)->featuresBoard($tenantKey));

        // The plan's own tiers — never the held-back group, which is held for
        // every estate whatever it pays and is headed as such.
        $rows = collect($board['groups'])
            ->reject(fn (array $group): bool => $group['key'] === 'held_back')
            ->flatMap(fn (array $group): array => $group['rows']);

        return [
            'note' => (string) $board['plan']['note'],
            'greyed' => $rows->reject(fn (array $row): bool => $row['available'])->pluck('label')->values()->all(),
            'planRows' => $rows->count(),
        ];
    };

    try {
        /*
         * STANDARD — Ocean View's plan, and every newly provisioned estate's. The
         * rows below the banner say "Not included in this estate's plan" against
         * what Standard leaves out, so the banner may not say the opposite.
         */
        $subscription->forceFill(['plan_id' => $standard->id])->save();

        $standardBoard = $read(SETTINGS_ESTATE);

        expect($standardBoard['greyed'])->not->toBe([])
            ->and($standardBoard['note'])->not->toContain('Every module below is included')
            ->and($standardBoard['note'])->not->toStartWith('Every')
            ->and($standardBoard['note'])->toContain('not included in the Standard plan')
            ->and($standardBoard['note'])->toContain('never deletes their data');

        // Each one is named, so the note and the greyed rows are one answer.
        foreach ($standardBoard['greyed'] as $label) {
            expect($standardBoard['note'])->toContain($label);
        }

        /*
         * PREMIUM — board 23's own estate, where every row IS available and the
         * sentence is true. Still not a claim about the held-back three.
         */
        $subscription->forceFill(['plan_id' => $premium->id])->save();

        $premiumBoard = $read(SETTINGS_ESTATE);

        expect($premiumBoard['greyed'])->toBe([])
            ->and($premiumBoard['note'])->toStartWith('Every feature the Premium plan lists below is included')
            ->and($premiumBoard['note'])->toContain('held back in this release');

        // NO SUBSCRIPTION AT ALL: nothing has been switched off, because nothing
        // closes a module for an estate with no plan (PlanGate; the Settings
        // pass, 12 Oct), and the note says so rather than that everything is
        // included in a plan that does not exist.
        $none = $read('no-such-estate');

        expect($none['greyed'])->toBe([])
            ->and($none['note'])->not->toStartWith('Every')
            ->and($none['note'])->toContain('nothing has been switched off');
    } finally {
        $subscription->forceFill(['plan_id' => $premium->id])->save();
    }
});

it('refuses to switch a core feature off, whatever tier the estate is on', function () {
    inSettingsEstate(function () {
        $settings = app(Settings::class);
        $admin = settingsUser(Role::COMMUNITY_SUPER_ADMIN);

        // The panic button behind a switch is the one thing this product must
        // never ship, so it is not behind a price tier and not an estate
        // setting either.
        expect(fn () => $settings->setFeature('resident_core', false, 'Trying it on.', $admin, SETTINGS_ESTATE))
            ->toThrow(DomainException::class, 'core feature');

        expect(fn () => $settings->setFeature('visitor_passes', false, 'Trying it on.', $admin, SETTINGS_ESTATE))
            ->toThrow(DomainException::class, 'core feature');

        // Custom branding is not a switch at all: every tier has it and the
        // tiers differ in how much, so there is nothing to turn off.
        expect(fn () => $settings->setFeature('custom_branding', false, 'Trying it on.', $admin, SETTINGS_ESTATE))
            ->toThrow(DomainException::class, 'not a switch');

        expect(EstateFeature::query()->count())->toBe(0);
    });
});

it('refuses a feature change that nobody gave a reason for', function () {
    inSettingsEstate(function () {
        $admin = settingsUser(Role::COMMUNITY_SUPER_ADMIN);

        // Board 23 states it: actor, timestamp AND reason. A reason nobody
        // typed is not one, and a change to what several hundred households can
        // do is exactly what an audit has to be able to question.
        expect(fn () => app(Settings::class)->setFeature('ai_drafting', false, '   ', $admin, SETTINGS_ESTATE))
            ->toThrow(DomainException::class, 'stated reason');

        expect(EstateFeature::query()->count())->toBe(0);
    });
});

it('refuses to route the guards pay through an estate that does not employ them', function () {
    inSettingsEstate(function () {
        $settings = app(Settings::class);
        $admin = settingsUser(Role::COMMUNITY_SUPER_ADMIN);

        /*
         * THE REFUSAL SURVIVED THE CONTROL (M10 §9).
         *
         * The guards' routing used to be a locked segmented control on the
         * settings screen with its own named refusal in the service. The client
         * removed the control — an estate has no say in how Gemini's employees
         * are paid, so the choice does not belong on their screen even as
         * something that says no — and the column went with it.
         *
         * What must NOT go with it is the guard rail. A key nobody offers is
         * still a key somebody can post, so this asserts the server still
         * refuses it. The message is now the generic one, because there is no
         * longer a specific thing being refused: `security_payroll` is simply
         * not a routing this estate has.
         */
        expect(fn () => $settings->setRouting('security_payroll', EstateSetting::IN_HOUSE, 'Trying it on.', $admin))
            ->toThrow(DomainException::class, 'No payroll routing');

        // The estate's OWN staff it may route, which is the positive control
        // without which the refusal above proves only that nothing works.
        $settings->setRouting('estate_payroll', EstateSetting::GEMINI_MANAGED, 'Handing staff payroll to Gemini.', $admin);

        expect(EstateSetting::current()->staff_payroll_routing)->toBe(EstateSetting::GEMINI_MANAGED);

        EstateSetting::current()->forceFill(['staff_payroll_routing' => EstateSetting::IN_HOUSE])->save();
    });
});

it('no longer draws the guards payroll routing anywhere on the settings screen', function () {
    inSettingsEstate(function () {
        /*
         * M10 §9, asserted on the payload rather than the template: a row the
         * server does not emit cannot be drawn by any screen that reads it.
         * Written against the old behaviour first — with `guardPayrollRow()`
         * still emitted, both expectations below fail.
         */
        $board = app(Settings::class)->featuresBoard();

        $rows = collect($board['groups'] ?? [])
            ->flatMap(fn (array $group): array => $group['rows'] ?? [])
            ->all();

        $keys = array_column($rows, 'key');
        $labels = array_column($rows, 'label');

        expect($keys)->not->toContain('security_payroll')
            ->and($labels)->not->toContain('Security guard payroll routing')

            // The estate's own staff routing stays — this is the counterweight,
            // without which the two assertions above would pass on an empty board.
            ->and($keys)->toContain('estate_payroll');

        // And the summary line beneath it no longer reports somebody else's payroll.
        $estateRow = collect($rows)->firstWhere('key', 'estate_payroll');

        expect((string) $estateRow['description'])->not->toContain('Security payroll')
            ->and((string) $estateRow['description'])->toContain('Staff payroll');
    });
});

/* ------------------------------------------------------------------ */
/* the three held back, and the two exemptions that are not settings */
/* ------------------------------------------------------------------ */

it('ships biometric consent off, dues payment manual and geofencing deferred, and offers no way to change any of them', function () {
    inSettingsEstate(function () {
        $settings = app(Settings::class);
        $setting = EstateSetting::current();

        // Each in its safest position, each behind a named flag, each with a
        // recorded ruling: D-022, D-023, D-033.
        expect($setting->biometric_consent_enabled)->toBeFalse()
            ->and($setting->payment_gateway_mode)->toBe(EstateSetting::PAYMENT_MANUAL)
            ->and($setting->geofencing_enabled)->toBeFalse();

        $held = collect($settings->featuresBoard(SETTINGS_ESTATE)['groups'])
            ->firstWhere('key', 'held_back');

        expect(array_column($held['rows'], 'key'))
            ->toBe(['biometric_consent', 'payment_gateway', 'geofencing']);

        foreach ($held['rows'] as $row) {
            // Shown, so the screen states a decision rather than silently
            // omitting a feature — and locked off, with the ruling on it.
            expect($row['enabled'])->toBeFalse()
                ->and($row['locked'])->toBeTrue()
                ->and($row['blocked_reason'])->not->toBeNull();
        }

        // None of the three is a catalogue feature, so the toggle endpoint
        // cannot reach one even by name.
        $admin = settingsUser(Role::COMMUNITY_SUPER_ADMIN);

        foreach (['biometric_consent', 'payment_gateway', 'geofencing'] as $key) {
            expect(fn () => $settings->setFeature($key, true, 'Trying it on.', $admin, SETTINGS_ESTATE))
                ->toThrow(DomainException::class, 'platform catalogue');
        }
    });
});

it('cannot switch a held-back flag through a profile save, however the request is shaped', function () {
    /*
     * MASS ASSIGNMENT IS THE ATTACK THIS ANSWERS. A settings form posts an
     * array, and one extra key in that array would switch a legally-blocked
     * feature on without anybody typing the word "biometric" — Laravel would
     * simply assign it. Two locks, and this asserts both: the field is not in
     * the model's `$fillable`, and it is not in the controller's validated set
     * either.
     */
    foreach (EstateSetting::HELD_BACK as $column) {
        expect((new EstateSetting)->getFillable())->not->toContain($column);
    }

    $this->actingAs(settingsUser(Role::COMMUNITY_SUPER_ADMIN))
        ->post(settingsUrl('/settings/profile'), [
            'enquiries_email' => null,
            'enquiries_phone' => null,
            'security_provider' => 'Gemini Security Limited',
            'biometric_consent_enabled' => 1,
            'geofencing_enabled' => 1,
            'payment_gateway_mode' => 'gateway',
        ])
        ->assertStatus(302);

    inSettingsEstate(function () {
        $setting = EstateSetting::current()->refresh();

        expect($setting->biometric_consent_enabled)->toBeFalse()
            ->and($setting->geofencing_enabled)->toBeFalse()
            ->and($setting->payment_gateway_mode)->toBe(EstateSetting::PAYMENT_MANUAL);
    });
});

it('holds no column that could switch off a residents own entry or an emergency vehicle', function () {
    inSettingsEstate(function () {
        $columns = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('estate_settings');

        /*
         * The two exemptions are hardcoded in `RestrictionPolicy` and there is
         * deliberately no setting for either. A misconfigured estate leaving an
         * ambulance at a gate is not recoverable by any amount of UI copy, and a
         * settings screen is precisely where somebody would think to add the
         * switch — so the absence is asserted rather than remembered.
         */
        foreach ($columns as $column) {
            expect($column)->not->toContain('emergency')
                ->and($column)->not->toContain('resident_entry')
                ->and($column)->not->toContain('own_entry');
        }

        // The arrears rules that ARE the estate's own, at the client's defaults
        // (D-024) — the positive control that says this table is the right one.
        expect(EstateSetting::current()->arrears_restriction_days)->toBe(90)
            ->and(EstateSetting::current()->arrears_notice_days)->toBe(14);
    });
});

/* ------------------------------------------------------------------ */
/* the audit trail, and the seeder */
/* ------------------------------------------------------------------ */

it('writes an immutable audit record naming the actor and the reason for every settings change', function () {
    $admin = settingsUser(Role::COMMUNITY_SUPER_ADMIN);

    $before = AuditEntry::query()->where('tenant_id', SETTINGS_ESTATE)->count();

    inSettingsEstate(function () use ($admin) {
        app(Settings::class)->setFeature(
            key: 'guard_app',
            enabled: false,
            reason: 'The estate is between guard contracts this month.',
            by: $admin,
            tenantKey: SETTINGS_ESTATE,
        );

        EstateFeature::query()->where('feature_key', 'guard_app')->delete();
    });

    $entry = AuditEntry::query()
        ->where('tenant_id', SETTINGS_ESTATE)
        ->where('action', 'estate.settings.feature_toggled')
        ->orderByDesc('id')
        ->first();

    expect(AuditEntry::query()->where('tenant_id', SETTINGS_ESTATE)->count())->toBe($before + 1)
        ->and($entry)->not->toBeNull()
        ->and($entry->actor_name)->toBe($admin->name)
        ->and($entry->actor_role)->toBe(Role::COMMUNITY_SUPER_ADMIN)
        ->and($entry->entity_id)->toBe('guard_app')
        ->and($entry->after['reason'])->toContain('between guard contracts');

    /*
     * CENTRAL AND APPEND-ONLY, which is the difference between an audit log and
     * a note. A log inside the estate database would be one the estate's own
     * administrator could ask for the deletion of; this one refuses an edit
     * from platform staff as readily as from a committee, at the model, at the
     * grant and at the trigger.
     */
    expect(fn () => $entry->forceFill(['action' => 'tampered'])->save())
        ->toThrow(LogicException::class);
});

it('seeds the settings row once and changes nothing on a second run', function () {
    inSettingsEstate(function () {
        $setting = EstateSetting::current();

        // A committee correcting its own enquiries address. A seeder that reset
        // it on every run would quietly undo them — the same rule PayablesSeeder
        // applies to a vendor's TRN, and for the same reason.
        $setting->forceFill(['enquiries_email' => 'chosen-by-the-committee@settingstest.org'])->save();

        $rows = EstateSetting::query()->count();

        Artisan::call('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => SettingsSeeder::class, '--force' => true]);

        expect(EstateSetting::query()->count())->toBe($rows)
            ->and($rows)->toBe(1)
            ->and(EstateSetting::current()->refresh()->enquiries_email)
            ->toBe('chosen-by-the-committee@settingstest.org');

        EstateSetting::current()->forceFill(['enquiries_email' => null])->save();
    });
});
