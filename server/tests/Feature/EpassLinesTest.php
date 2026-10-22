<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A household's page says each person's e-Pass (M98, the Residents census: e-Pass management)
|--------------------------------------------------------------------------
|
| The e-Pass is the pass a guard admits a resident on, issued in the Resident App to a person whose account is active.
| The office manages it through app access (End access cancels it at every gate, Give access back lets it be issued
| again), and the household page never said where anybody's stood. It now says it for each person, in one line.
|
| Lot 9873 is this file's own scratch lot: the fixture estate is shared.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 9873');
    epassClear($this->unit->id);
});

afterEach(function () {
    config(['services.resident_app.access_levels' => false]);
    FacilitiesFixture::boot();
    epassClear($this->unit->id);

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** This file's rows on its own scratch lot, gone. */
function epassClear(int $unitId): void
{
    $households = Household::withRemoved()->where('unit_id', $unitId)->pluck('id');
    VisitorPass::query()->whereIn('household_id', $households)->delete();
    Resident::withRemoved()->whereIn('household_id', $households)->delete();
    Household::withRemoved()->whereIn('id', $households)->delete();
}

function epassPerson(Household $household, string $name, bool $primary = false, array $more = []): Resident
{
    $person = new Resident;
    $person->forceFill([
        'household_id' => $household->id, 'full_name' => $name, 'email' => null, 'phone' => '+1 876 555 0988',
        'relationship' => $primary ? 'owner' : 'family', 'is_primary' => $primary, 'status' => Resident::VERIFIED, ...$more,
    ])->save();

    return $person;
}

function epassAccount(Resident $person, int $unitId, string $status, ?Carbon $revokedAt = null): void
{
    ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'epass.'.$person->id.'.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => $person->full_name, 'resident_id' => $person->id, 'unit_id' => $unitId, 'status' => $status,
        'verified_at' => now(), 'revoked_at' => $revokedAt, 'device_uid' => 'epass-'.$person->id, 'device_platform' => 'android',
    ]);
}

it('says each person\'s e-Pass in one line: active, cancelled by the office, paused by the household, or none', function () {
    config(['services.resident_app.access_levels' => true]);

    $household = Household::query()->create(['unit_id' => $this->unit->id, 'name' => 'Pass household', 'access_restricted' => false]);
    $holder = epassPerson($household, 'Ava Pass', primary: true);
    $ended = epassPerson($household, 'Ben Pass');
    $helper = epassPerson($household, 'Cleo Helper', more: ['access_level' => 'helper', 'access_paused_at' => now()]);
    epassPerson($household, 'Dan Pass');

    epassAccount($holder, $this->unit->id, ResidentAccount::ACTIVE);
    epassAccount($ended, $this->unit->id, ResidentAccount::REVOKED, now());
    epassAccount($helper, $this->unit->id, ResidentAccount::ACTIVE);

    $this->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))
        ->get(FacilitiesFixture::url('/residents/'.$this->unit->slug()))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $lines = collect($page->toArray()['props']['people'])->mapWithKeys(fn (array $p): array => [$p['full_name'] => $p['epass']]);

            expect($lines['Ava Pass'])->toBe(['state' => 'active', 'line' => 'e-Pass in the Resident App, at any hour'])
                ->and($lines['Ben Pass'])->toBe(['state' => 'cancelled', 'line' => 'e-Pass cancelled: the office ended their app access'])
                ->and($lines['Cleo Helper'])->toBe(['state' => 'paused', 'line' => 'e-Pass paused by the household'])
                ->and($lines['Dan Pass'])->toBe(['state' => 'none', 'line' => 'No e-Pass: not on the Resident App']);
        });
});
