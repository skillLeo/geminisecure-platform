<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Module;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\RoleEditor;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Every sidebar item goes somewhere its reader can open
|--------------------------------------------------------------------------
|
| `EstateNavigationTest` proves which items a role is shown. This proves the
| other half, by following each one: an item the sidebar draws is a real link,
| and a link that answers "Not available to your role" is the dead link the
| class docblock and cross-cutting rule 9 say must never render — with an error
| page that then tells the reader they "arrived here by URL", when they clicked
| the console's own navigation.
|
| EVERY SEEDED ESTATE ROLE, AND A ROLE FOR EACH MODULE. The seeded Property
| Manager found the first of these (Accounting, through Vendor costs); a role a
| Community Super Admin builds today from one module (M10 §4) finds the rest,
| because an item drawn on one module and gated on another is wrong for
| somebody whether or not a seeded role happens to be that somebody.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * The props this viewer's dashboard is sent.
 *
 * @return array<string, mixed>
 */
function sidebarDashboardProps(mixed $test, User $viewer): array
{
    $props = [];

    $test->actingAs($viewer)
        ->get(FacilitiesFixture::url('/'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return $props;
}

it('sends every role only to pages it can open, from every item its sidebar draws (B138)', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $editor = app(RoleEditor::class);

    $viewers = [];

    foreach ([
        Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::VICE_PRESIDENT, Role::SECRETARY,
        Role::PROPERTY_MANAGER, Role::TREASURER, Role::ESTATE_ADMIN_ASSISTANT, Role::ESTATE_HEAD_OF_SECURITY,
    ] as $name) {
        $viewers[$name] = FacilitiesFixture::viewer($name);
    }

    $modules = Module::query()->where('console', Console::Estate->value)->where('key', '!=', 'dashboard')->orderBy('sort')->pluck('key');

    expect($modules)->not->toBeEmpty();

    tenancy()->initialize(Tenant::findOrFail(FacilitiesFixture::ESTATE));

    try {
        foreach ($modules as $module) {
            $role = $editor->create($admin, 'Only '.str_replace('_', ' ', $module), [
                'dashboard' => ['level' => 'view'],
                $module => ['level' => 'view'],
            ]);

            $viewers['a role holding only '.$module] = $role->name;
        }
    } finally {
        tenancy()->end();
        FacilitiesFixture::boot();
    }

    $followed = 0;

    foreach ($viewers as $who => $viewer) {
        $viewer = $viewer instanceof User ? $viewer : FacilitiesFixture::viewer($viewer);

        foreach (sidebarDashboardProps($this, $viewer)['estateNav'] as $item) {
            $status = $this->actingAs($viewer)->get(FacilitiesFixture::url($item['href']))->getStatusCode();

            expect($status)->toBe(200, "{$who}: the sidebar draws {$item['label']}, and {$item['href']} answers {$status}.");

            $followed++;
        }
    }

    // Not vacuous: the seeded eight alone draw dozens of items between them.
    expect($followed)->toBeGreaterThan(40);
});

it('gives the Property Manager no link to the chart of accounts that Ruling 1 refuses them (C03)', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    /*
     * THE DASHBOARD IS WHERE THE CRAWL FOUND IT, and the link was the sidebar's:
     * nothing the dashboard sends — sidebar, tiles, panels or quick actions —
     * may point a Property Manager at the estate's books.
     */
    $props = sidebarDashboardProps($this, $manager);

    expect(json_encode($props, JSON_UNESCAPED_SLASHES))->not->toContain('/accounting/')
        ->and(array_column($props['estateNav'], 'key'))->not->toContain('accounting');

    // And the refusal it would have led to is still the right answer (D-010).
    $this->actingAs($manager)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))->assertForbidden();
    $this->actingAs($manager)->get(FacilitiesFixture::url('/accounting/vendors'))->assertForbidden();
});
