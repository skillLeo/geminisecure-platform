<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The dashboard sends nobody to a page that refuses them (M98, Hassam, 12 October)
|--------------------------------------------------------------------------
|
| "The dashboard is role adaptive: every tile, link and action on it obeys the three levels, so a Property Manager is
| never sent to a page that will refuse them." For every estate role: each link the dashboard sends opens for that
| role; a tile comes only with the module it summarises, by the sidebar's own rule; and an action the role can never
| take is absent, not greyed with a reason beside it (M96 level 3), on the wire and in the template.
|
*/

const DASHBOARD_ROLES = [
    Role::COMMUNITY_SUPER_ADMIN,
    Role::PRESIDENT,
    Role::VICE_PRESIDENT,
    Role::SECRETARY,
    Role::PROPERTY_MANAGER,
    Role::TREASURER,
    Role::ESTATE_ADMIN_ASSISTANT,
    Role::ESTATE_HEAD_OF_SECURITY,
];

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

/** @return array<string, mixed> the dashboard's props, as this viewer is sent them */
function dashboardPropsFor(object $test, mixed $viewer): array
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

it('opens every link the dashboard sends, for every estate role', function () {
    foreach (DASHBOARD_ROLES as $roleName) {
        $viewer = FacilitiesFixture::viewer($roleName);
        $props = dashboardPropsFor($this, $viewer);

        $links = array_values(array_filter([
            $props['quickActions']['notice']['href'] ?? null,
            $props['quickActions']['addResident']['href'] ?? null,
            $props['quickActions']['newCharge']['href'] ?? null,
            $props['arrears']['ledgerHref'] ?? null,
            $props['activity']['seeAllHref'] ?? null,
            $props['notifications']['href'] ?? null,
        ]));

        // Every role is sent the full activity log and the bell at least.
        expect(count($links))->toBeGreaterThanOrEqual(2, $roleName);

        foreach ($links as $href) {
            expect($this->actingAs($viewer)->get(FacilitiesFixture::url($href))->status())->toBe(200, "{$roleName} is sent to {$href}");
        }
    }
});

it('sends a tile only to a role whose sidebar holds the module it summarises, and Total units to every role', function () {
    $tiles = ['occupied' => 'residents', 'maintenance' => 'facilities', 'meeting' => 'governance'];

    foreach (DASHBOARD_ROLES as $roleName) {
        $props = dashboardPropsFor($this, FacilitiesFixture::viewer($roleName));
        $sent = array_column($props['kpis'], 'key');
        $nav = collect($props['estateNav'] ?? [])->pluck('key')->all();

        foreach ($tiles as $tile => $module) {
            expect(in_array($tile, $sent, true))->toBe(in_array($module, $nav, true), "{$roleName}: the {$tile} tile against the {$module} item");
        }

        /*
         * A COUNT IS NOT THE MODULE'S DATA (Hassam, 12 Oct): "A Treasurer cannot read dues coverage without knowing how
         * many units exist." Every role is sent Total units, the Treasurer and the Admin Assistant too, who hold no
         * Estate structure; nothing on the tile leads into that module.
         */
        expect(in_array('units', $sent, true))->toBeTrue("{$roleName}: the Total units tile");
    }

    // The role the order names holds no Governance: no meeting tile, and its own three are there.
    $manager = array_column(dashboardPropsFor($this, FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))['kpis'], 'key');

    expect(in_array('meeting', $manager, true))->toBeFalse()
        ->and(array_values(array_intersect(['units', 'occupied', 'maintenance'], $manager)))->toBe(['units', 'occupied', 'maintenance']);
});

it('sends no reason for an action a role can never take, and the page draws no greyed twin', function () {
    foreach (DASHBOARD_ROLES as $roleName) {
        $props = dashboardPropsFor($this, FacilitiesFixture::viewer($roleName));

        foreach ($props['quickActions'] as $name => $action) {
            expect(array_keys($action))->toBe(['href'], "{$roleName}: {$name}");
        }

        expect(array_key_exists('ledgerReason', $props['arrears']))->toBeFalse($roleName)
            ->and(array_key_exists('positionReason', $props['arrears']))->toBeFalse($roleName);
    }

    $sfc = (string) file_get_contents(resource_path('js/Pages/Estate/Dashboard.vue'));

    expect(preg_match('/^<template>\R(.*)^<\/template>/ms', $sfc, $found))->toBe(1);

    $template = (string) preg_replace('/<!--.*?-->/s', '', $found[1]);

    // The tiles are counts, never a way into a module (Hassam, 12 Oct): no link anywhere in the tile row.
    expect(preg_match('/class="kpi-row">(.*?)<p v-if="computed\?\.structure"/s', $template, $row))->toBe(1)
        ->and(str_contains($row[1], 'href'))->toBeFalse('a tile links somewhere');

    expect(preg_match('/\sdisabled[\s>=]/', $template))->toBe(0, 'a greyed control is drawn')
        ->and(str_contains($template, 'arrears.positionReason'))->toBeFalse('the withheld chart is explained in a panel')
        ->and(str_contains($template, 'Reason'))->toBeFalse('a reason is still read')
        ->and(str_contains($template, '.reason'))->toBeFalse('a reason is still read');
});
