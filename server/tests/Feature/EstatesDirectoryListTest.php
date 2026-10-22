<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The pre-sign-in estate list offers only estates marked to appear (ESTATES-01)
|--------------------------------------------------------------------------
|
| The Resident App shows a list of estates before anyone signs in, so a
| homeowner does not have to type a community code. Two internal tenants — a
| bare "Demo estate" and a developer's "SkillLeo Estats" — were reaching that
| list on production, where a client would see them.
|
| `listed` decides it now, and defaults TRUE so a real estate is never hidden by
| an oversight. An estate left off the list is still reachable by its community
| code, so excluding one wrongly locks nobody out.
|
*/

const DIR_TEST_IDS = ['zzlistedprobe', 'zzinternalprobe', 'zzdemoprobe'];

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
});

afterEach(function () {
    Tenant::withoutEvents(function () {
        Tenant::query()->whereIn('id', DIR_TEST_IDS)->delete();
    });
    FacilitiesFixture::boot();
});

/** A bare central tenant row — no provisioned database, which the pick-list query never touches. */
function bareTenant(string $id, string $name, bool $listed, bool $demo = false): void
{
    Tenant::withoutEvents(function () use ($id, $name, $listed, $demo) {
        Tenant::query()->firstOrNew(['id' => $id])
            ->forceFill(['id' => $id, 'name' => $name, 'status' => 'active', 'demo' => $demo, 'listed' => $listed])
            ->save();
    });
}

/**
 * The pre-sign-in estate list, as the Resident App asks for it (no token). In a helper taking a typed TestCase,
 * the way `asResident()` does, so the call site reads `$this` as the test case it is.
 *
 * @return Collection<int, array<string, mixed>>
 */
function estateList(TestCase $test): Collection
{
    return collect($test->getJson('/api/v1/estates')->assertOk()->json('items'));
}

it('offers a listed active estate and hides an unlisted one, and defaults new estates to listed', function () {
    bareTenant('zzlistedprobe', 'Listed Probe Estate', listed: true);
    bareTenant('zzinternalprobe', 'Internal Probe Estate', listed: false);

    $ids = estateList($this)->pluck('id');

    expect($ids)->toContain(FacilitiesFixture::ESTATE)   // the fixture estate is active and listed by default
        ->toContain('zzlistedprobe')
        ->not->toContain('zzinternalprobe');

    // The column defaults TRUE, so nobody has to remember to list a real estate.
    expect(Tenant::query()->find('zzlistedprobe')->listed)->toBeTrue()
        ->and(Tenant::query()->find('zzinternalprobe')->listed)->toBeFalse();
});

it('keeps a labelled demonstration estate in the list, so "Explore a demo community" still has a way in', function () {
    bareTenant('zzdemoprobe', 'Demo Probe (demonstration data)', listed: true, demo: true);

    $row = estateList($this)->firstWhere('id', 'zzdemoprobe');

    expect($row)->not->toBeNull()
        ->and($row['demo'])->toBeTrue();
});

it('a brand-new estate onboarded without saying anything about listing appears (the default carries it)', function () {
    // The onboarding path does not set `listed`; the column default TRUE must carry it, or a real estate would go
    // live invisible. Created the way FacilitiesFixture::platform() creates the fixture estate: no `listed` given.
    Tenant::withoutEvents(function () {
        (new Tenant)->forceFill(['id' => 'zzlistedprobe', 'name' => 'Fresh Estate', 'status' => 'active'])->save();
    });

    expect(estateList($this)->pluck('id'))->toContain('zzlistedprobe')
        ->and(Tenant::query()->find('zzlistedprobe')->listed)->toBeTrue();
});
