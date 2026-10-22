<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HouseholdHolder;

/*
|--------------------------------------------------------------------------
| M50 §6.13 — the test data on screen: which rows are seeded, which were typed in the app, and only the named ones go
|--------------------------------------------------------------------------
|
| `household:test-rows` lists a household's people, vehicles, contacts and pets, each marked SEEDED (by which command)
| or TYPED in the app (by which account), and removes only the rows named in --remove, only with --confirm — never the
| primary, never a row of another household.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lot (9561), and every row it adds is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->household = Household::query()->firstOrCreate(['unit_id' => FacilitiesFixture::scratchUnit('Lot 9561')->id], ['name' => 'Test rows household', 'access_restricted' => false]);
    $this->primary = HouseholdHolder::of($this->household, 'Paula Primary');
    $estate = DB::connection('tenant');
    $estate->table('household_vehicles')->where('household_id', $this->household->id)->delete();
    $estate->table('household_emergency_contacts')->where('household_id', $this->household->id)->delete();

    $this->seededCar = (int) $estate->table('household_vehicles')->insertGetId(['household_id' => $this->household->id, 'plate' => 'PPV 4471', 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Silver', 'created_at' => now(), 'updated_at' => now()]);
    // The application can never write the mark (SampleAwareBuilder); a seeding command's row is made one here by hand.
    $estate->statement('UPDATE household_vehicles SET seeded_at = NOW(), seeded_by = ? WHERE id = ?', ['phoenix:demo-seed', $this->seededCar]);
    $this->typedCar = (int) $estate->table('household_vehicles')->insertGetId(['household_id' => $this->household->id, 'plate' => 'PPV 154154', 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Silver', 'added_by_account_id' => 777, 'created_at' => now(), 'updated_at' => now()]);
    $this->typedContact = (int) $estate->table('household_emergency_contacts')->insertGetId(['household_id' => $this->household->id, 'name' => 'Bhai', 'relationship' => 'Bro', 'phone' => '03110000571', 'added_by_account_id' => 777, 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    $estate = DB::connection('tenant');
    $estate->table('household_vehicles')->where('household_id', $this->household->id)->delete();
    $estate->table('household_emergency_contacts')->where('household_id', $this->household->id)->delete();
});

function testRows(array $options): array
{
    $code = Artisan::call('household:test-rows', ['--estate' => FacilitiesFixture::ESTATE, ...$options]);
    $output = Artisan::output();
    FacilitiesFixture::boot();

    return [$code, $output];
}

it('says which rows were seeded and which were typed in the app — and changes nothing', function () {
    [$code, $output] = testRows(['--household' => $this->household->id]);

    expect($code)->toBe(0)
        ->and($output)->toContain('vehicle:'.$this->seededCar.'  PPV 4471')->toContain('SEEDED by phoenix:demo-seed')
        ->and($output)->toContain('vehicle:'.$this->typedCar.'  PPV 154154')->toContain('TYPED in the app by app account 777')
        ->and($output)->toContain('contact:'.$this->typedContact.'  Bhai · Bro · 03110000571')
        ->and($output)->toContain('Paula Primary')->toContain('PRIMARY');
    expect(DB::connection('tenant')->table('household_vehicles')->where('household_id', $this->household->id)->count())->toBe(2);
});

it('a dry run of the named rows says what would go, and nothing goes without --confirm', function () {
    [$code, $output] = testRows(['--household' => $this->household->id, '--remove' => 'vehicle:'.$this->typedCar.',contact:'.$this->typedContact]);

    expect($code)->toBe(0)->and($output)->toContain('Nothing is changed without --confirm');
    expect(DB::connection('tenant')->table('household_vehicles')->where('id', $this->typedCar)->exists())->toBeTrue()
        ->and(DB::connection('tenant')->table('household_emergency_contacts')->where('id', $this->typedContact)->exists())->toBeTrue();
});

it('with --confirm removes only the rows named, each on the audit log', function () {
    [$code] = testRows(['--household' => $this->household->id, '--remove' => 'vehicle:'.$this->typedCar.',contact:'.$this->typedContact, '--confirm' => true]);

    expect($code)->toBe(0);
    expect(DB::connection('tenant')->table('household_vehicles')->where('id', $this->typedCar)->exists())->toBeFalse()
        ->and(DB::connection('tenant')->table('household_vehicles')->where('id', $this->seededCar)->exists())->toBeTrue()
        ->and(DB::connection('tenant')->table('household_emergency_contacts')->where('id', $this->typedContact)->exists())->toBeFalse();
    expect(DB::connection('mysql')->table('audit_log')->where('action', 'household.test_row_removed')->where('entity_id', (string) $this->household->id)->count())->toBe(2);
});

it('never removes the primary, nor a row that is not this household\'s — and then changes nothing at all', function () {
    [$code, $output] = testRows(['--household' => $this->household->id, '--remove' => 'vehicle:'.$this->typedCar.',member:'.$this->primary->id, '--confirm' => true]);
    expect($code)->not->toBe(0)->and($output)->toContain('primary resident and is never removed here');
    expect(DB::connection('tenant')->table('household_vehicles')->where('id', $this->typedCar)->exists())->toBeTrue();

    [$code, $output] = testRows(['--household' => $this->household->id, '--remove' => 'vehicle:999999999', '--confirm' => true]);
    expect($code)->not->toBe(0)->and($output)->toContain('is not one of this household\'s rows');

    expect(Resident::query()->find($this->primary->id))->not->toBeNull();
});
