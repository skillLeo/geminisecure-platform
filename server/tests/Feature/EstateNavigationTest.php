<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Role;
use App\Models\User;
use App\Support\EstateNavigation;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The Estate Console sidebar, asserted against the matrix rather than a board
|--------------------------------------------------------------------------
|
| THE BOARDS CANNOT BE THE AUTHORITY HERE, and one test settles it: board 05's
| persona is the PROPERTY MANAGER, and its sidebar draws Dues & ledger,
| Accounting and Payroll & HR — the three modules Ruling 1 locks that role out
| of. Every estate board draws the same ten items whatever persona it names, so
| the sidebar on those boards is an illustration and belongs to no role.
|
| The Build Spec is explicit about what does govern it: "This matrix generates
| navigation. A role without a module permission does not see that module at
| all." So the pixel harness measures the CONTENT region of an estate board, and
| the sidebar is proven here instead — against the permission matrix, which is
| the thing it is actually generated from.
|
| The Property Manager case is the one that matters. A sidebar that showed them
| Dues & ledger would be the console handing a resident's financial position to
| the person who commissions the work, which is a locked platform invariant and
| not an estate setting. D-010, D-044.
|
*/

uses(DatabaseTransactions::class);

/**
 * The matrix itself, seeded once into the test database.
 *
 * Seeded rather than hand-built, and that is the point of the whole file: what
 * is under test is the REAL matrix — the one transcribed from board 24 and
 * amended by Ruling 1 — not a fixture that happens to agree with it. A
 * hand-built grid here would pass while the seeded one locked the wrong role
 * out of the wrong module.
 */
beforeEach(function () {
    /*
     * SEEDED ON THE NEWEST CELL, NOT ON A ROLE — the rule `FacilitiesFixture::platform()`
     * follows (D-053). A database seeded before M10 §2 already HAS a Treasurer, so a check for
     * the role alone would leave the matrix a version behind, and the Security item would be
     * missing from a sidebar that is working exactly as it should.
     */
    $current = Role::query()
        ->where('name', Role::COMMUNITY_SUPER_ADMIN)
        ->whereHas('permissions', static fn ($query) => $query->where('name', 'estate.security.view'))
        ->exists();

    if (! $current) {
        Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    }

    // The permission cache is per-process and would otherwise hold whatever a
    // previous test file left in it.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A user holding exactly one estate role. */
function navViewer(string $roleName): User
{
    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$roleName]);

    return $user->fresh();
}

/** @return list<string> the sidebar item keys this role would see */
function sidebarFor(string $roleName): array
{
    return array_column((new EstateNavigation)->forViewer(navViewer($roleName)), 'key');
}

it('never shows the Property Manager a money module', function () {
    $items = sidebarFor(Role::PROPERTY_MANAGER);

    // The invariant. Not a preference, not an estate setting: whoever
    // commissions work must never be able to pay for it, nor see a resident's
    // financial position.
    expect($items)->not->toContain('dues_ledger')
        ->and($items)->not->toContain('accounting')
        ->and($items)->not->toContain('payroll');

    // And they DO reach what they are meant to — a positive control, without
    // which this test would pass on a console that showed them nothing at all.
    expect($items)->toContain('facilities')
        ->and($items)->toContain('estate_structure');
});

it('does not show the Property Manager Accounting, whose every screen Ruling 1 refuses them', function () {
    /*
     * THIS ASSERTED THE OPPOSITE UNTIL B138, and the reason it was wrong is
     * worth keeping. The matrix gives the Property Manager `-` on
     * accounting_posting and `V` on vendor_costs, and the item was drawn on
     * either — but every Accounting screen, the supplier register included,
     * sits behind accounting_posting.view, so the item this test pinned was a
     * link that answered 403 on every click. An item is now drawn on the gate
     * its own page checks; `EstateSidebarReachesItsPagesTest` follows it.
     */
    expect(sidebarFor(Role::PROPERTY_MANAGER))->not->toContain('accounting');
});

it('does not show the Treasurer estate structure, whatever the boards draw', function () {
    /*
     * Board 24's matrix gives the Treasurer an em dash on estate structure, and
     * boards 05, 06, 25 and 35 all draw it in the sidebar anyway. The matrix
     * wins. This is the assertion that replaced roughly two points of pixel
     * diff on every estate screen.
     */
    $items = sidebarFor(Role::TREASURER);

    expect($items)->not->toContain('estate_structure')
        ->and($items)->toContain('dues_ledger')
        ->and($items)->toContain('accounting')
        ->and($items)->toContain('payroll');
});

it('gives the Community Super Admin everything within their own estate', function () {
    // D-009's seventh role. Full access inside one estate, and no visibility
    // into another or into Gemini's own payroll — the second half of that is
    // enforced by tenancy rather than by this list.
    //
    // ELEVEN, NOT THE BOARDS' TEN (M10 §2). The boards draw ten items; §2 added
    // Security, the estate's own picture of its gate, which no board draws.
    expect(sidebarFor(Role::COMMUNITY_SUPER_ADMIN))->toHaveCount(11)
        ->and(sidebarFor(Role::COMMUNITY_SUPER_ADMIN))->toContain('security');
});

it('orders the sidebar in the boards own groups', function () {
    $nav = (new EstateNavigation)->forViewer(navViewer(Role::COMMUNITY_SUPER_ADMIN));

    // Security sits in Community, between Governance and Reports (M10 §2).
    expect(array_column($nav, 'section'))->toBe([
        null,
        'Estate', 'Estate',
        'Finance', 'Finance', 'Finance',
        'Community', 'Community', 'Community', 'Community',
        'System',
    ])
        ->and(array_column($nav, 'key'))->toBe([
            'dashboard',
            'estate_structure', 'residents',
            'dues_ledger', 'accounting', 'payroll',
            'facilities', 'governance', 'security', 'reports',
            'settings',
        ]);
});

it('gives every item a role can see somewhere to go', function () {
    /*
     * THIS REPLACED A WEAKER TEST, and the reason is worth keeping.
     *
     * The sidebar carried a nullable href through the build: a module with no
     * screens yet drew greyed and inert, so a role could still check what it
     * would reach, and the old test asserted only that the two halves agreed
     * with each other. It would have passed on a console where every item was
     * pending. What it could not catch is the failure that actually happened
     * twice — Accounting and Facilities kept their null long after their screens
     * existed, so the console told a manager a module was missing while nine of
     * its pages sat one click away.
     *
     * Reports was the tenth and last item, the nullable case became unreachable,
     * and it is gone (D-070). What is asserted now is the invariant that
     * replaced it: an item is in this sidebar because a role holds the module,
     * and it goes somewhere.
     */
    foreach ([Role::TREASURER, Role::PROPERTY_MANAGER, Role::COMMUNITY_SUPER_ADMIN] as $roleName) {
        $nav = (new EstateNavigation)->forViewer(navViewer($roleName));

        expect($nav)->not->toBeEmpty();

        foreach ($nav as $item) {
            expect($item['href'])->toStartWith('/');
        }
    }
});

it('puts the estate in the path only where the environment serves it that way', function () {
    $nav = (new EstateNavigation)->forViewer(navViewer(Role::TREASURER), tenantKey: 'phoenixpark');
    $dues = collect($nav)->firstWhere('key', 'dues_ledger');

    // Local serves every estate from one host with the estate in the path,
    // because *.localhost does not resolve on Windows. Production gives each
    // its own hostname and the path is bare.
    expect($dues['href'])->toBe(
        app()->isLocal() ? '/estate/phoenixpark/finance/arrears' : '/finance/arrears'
    );
});
