<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\ResidentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HouseholdHolder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M50 §5.3 — a household changes its own vehicles, and keeps its pets
|--------------------------------------------------------------------------
|
| The Profile card "Vehicles & pets" listed the cars and offered no way to change one, and pets did not exist at all.
| A vehicle is changed in place; a pet is added, changed and removed. The household's own rows only — another
| household's id and an id nobody has are the same 404 — and every change and removal is on the estate's audit log.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lots (9551, 9552), and every row it adds is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->household = Household::query()->firstOrCreate(['unit_id' => FacilitiesFixture::scratchUnit('Lot 9551')->id], ['name' => 'Items household', 'access_restricted' => false]);
    $resident = HouseholdHolder::of($this->household, 'Ivy Items');
    $this->other = Household::query()->firstOrCreate(['unit_id' => FacilitiesFixture::scratchUnit('Lot 9552')->id], ['name' => 'Other items household', 'access_restricted' => false]);

    $this->account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'items.'.bin2hex(random_bytes(3)).'@example.com', 'full_name' => 'Ivy Items',
        'resident_id' => $resident->id, 'unit_id' => $this->household->unit_id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->token = $this->account->createToken('resident-app:items', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    foreach (['household_pets', 'household_vehicles'] as $table) {
        DB::connection('tenant')->table($table)->whereIn('household_id', [$this->household->id, $this->other->id])->delete();
    }
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    foreach (['household_pets', 'household_vehicles'] as $table) {
        DB::connection('tenant')->table($table)->whereIn('household_id', [$this->household->id, $this->other->id])->delete();
    }
});

/**
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function itemsCall(TestCase $test, string $name, array $params = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->json($entry['method'], $uri, $body, $entry['write'] ? [
        'Idempotency-Key' => 'items-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ] : []);
    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** @param array<string, mixed> $values */
function itemsRow(string $table, int $householdId, array $values): int
{
    return (int) DB::connection('tenant')->table($table)->insertGetId(['household_id' => $householdId, ...$values, 'created_at' => now(), 'updated_at' => now()]);
}

function itemsAudit(string $action, int $householdId): ?object
{
    return DB::connection('mysql')->table('audit_log')->where('action', $action)->where('entity_id', (string) $householdId)->orderByDesc('id')->first();
}

it('changes the household\'s own vehicle in place, and audits it before and after', function () {
    $mine = itemsRow('household_vehicles', $this->household->id, ['plate' => 'PPV 154154', 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Silver']);
    $theirs = itemsRow('household_vehicles', $this->other->id, ['plate' => '9988 JA', 'make' => 'Honda', 'model' => 'Fit', 'colour' => 'Red']);
    $car = ['plate' => 'ppv  1541', 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Grey'];

    // Another household's car and an id nobody has: the same 404, and the car is untouched.
    expect(itemsCall($this, 'vehicles.update', ['vehicle' => $theirs], $car, 404)->json('error.message'))
        ->toBe(itemsCall($this, 'vehicles.update', ['vehicle' => 999_999_999], $car, 404)->json('error.message'));
    expect(DB::connection('tenant')->table('household_vehicles')->where('id', $theirs)->value('plate'))->toBe('9988 JA');

    $changed = itemsCall($this, 'vehicles.update', ['vehicle' => $mine], $car);
    expect($changed->json())->toMatchArray(['id' => $mine, 'plate' => 'PPV 1541', 'colour' => 'Grey']);

    $entry = itemsAudit('household.vehicle_changed', $this->household->id);
    expect($entry)->not->toBeNull()
        ->and(json_decode((string) $entry->before, true))->toMatchArray(['vehicle_id' => $mine, 'plate' => 'PPV 154154', 'colour' => 'Silver'])
        ->and(json_decode((string) $entry->after, true))->toMatchArray(['plate' => 'PPV 1541', 'colour' => 'Grey', 'via' => 'resident_app']);

    // Every field is required, as on the way in.
    foreach (['plate', 'make', 'model', 'colour'] as $field) {
        itemsCall($this, 'vehicles.update', ['vehicle' => $mine], collect($car)->except($field)->all(), 422);
    }
});

it('refuses a plate already on the list for another of the household\'s vehicles, and changes nothing', function () {
    $first = itemsRow('household_vehicles', $this->household->id, ['plate' => 'PPV 4471', 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Silver']);
    $second = itemsRow('household_vehicles', $this->household->id, ['plate' => '1234 AB', 'make' => 'Nissan', 'model' => 'Note', 'colour' => 'White']);

    $refused = itemsCall($this, 'vehicles.update', ['vehicle' => $second], ['plate' => 'ppv 4471', 'make' => 'Nissan', 'model' => 'Note', 'colour' => 'White'], 409);
    expect($refused->json('error.code'))->toBe('already_listed')
        ->and(DB::connection('tenant')->table('household_vehicles')->where('id', $second)->value('plate'))->toBe('1234 AB');

    // Its own plate again is no conflict: the same car, saved with a new colour.
    expect(itemsCall($this, 'vehicles.update', ['vehicle' => $first], ['plate' => 'PPV 4471', 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Black'])->json('colour'))->toBe('Black');
});

it('adds a pet once, changes it and removes it — the household\'s own pets only', function () {
    $bella = itemsCall($this, 'pets.store', [], ['name' => '  Bella  ', 'kind' => 'dog']);
    expect($bella->json())->toMatchArray(['name' => 'Bella', 'kind' => 'dog', 'kind_label' => 'Dog', 'already_listed' => false]);

    // The same pet again, in other capitals, is the row already there — a double tap never makes a second Bella.
    $again = itemsCall($this, 'pets.store', [], ['name' => 'BELLA', 'kind' => 'dog'], 200);
    expect($again->json('id'))->toBe($bella->json('id'))->and($again->json('already_listed'))->toBeTrue();
    expect(DB::connection('tenant')->table('household_pets')->where('household_id', $this->household->id)->count())->toBe(1);

    // A kind the list does not have, and no name: said, nothing added.
    itemsCall($this, 'pets.store', [], ['name' => 'Rex', 'kind' => 'dragon'], 422);
    itemsCall($this, 'pets.store', [], ['name' => '', 'kind' => 'cat'], 422);

    expect(itemsCall($this, 'pets.update', ['pet' => $bella->json('id')], ['name' => 'Bella', 'kind' => 'cat'])->json('kind_label'))->toBe('Cat');
    expect(collect(itemsCall($this, 'me.household')->json('pets'))->pluck('name')->all())->toBe(['Bella']);

    $theirs = itemsRow('household_pets', $this->other->id, ['name' => 'Max', 'kind' => 'dog']);
    itemsCall($this, 'pets.update', ['pet' => $theirs], ['name' => 'Mine now', 'kind' => 'dog'], 404);
    itemsCall($this, 'pets.destroy', ['pet' => $theirs], [], 404);
    expect(DB::connection('tenant')->table('household_pets')->where('id', $theirs)->value('name'))->toBe('Max');

    expect(itemsCall($this, 'pets.destroy', ['pet' => $bella->json('id')])->json('removed'))->toBeTrue();
    itemsCall($this, 'pets.destroy', ['pet' => $bella->json('id')], [], 404);
    expect(itemsCall($this, 'pets.index')->json('items'))->toBe([]);

    expect(json_decode((string) itemsAudit('household.pet_changed', $this->household->id)?->before, true))->toMatchArray(['name' => 'Bella', 'kind' => 'dog'])
        ->and(json_decode((string) itemsAudit('household.pet_removed', $this->household->id)?->before, true))->toMatchArray(['name' => 'Bella', 'kind' => 'cat']);
});
