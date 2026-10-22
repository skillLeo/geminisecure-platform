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
| A household removes its own vehicle or emergency contact (INV-11, T-REM-2)
|--------------------------------------------------------------------------
|
| The phone said "To remove a vehicle or a contact, contact the estate office", and no estate console screen
| reads either table, so nobody could — while the user's household listed one spouse four times. Nothing records
| that the estate owns these rows and no gate decision reads the plates, so the household removes them itself,
| after the app's confirmation, and each removal is audited. Another household's row and an id nobody has are the
| same 404.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lots, and every row it adds is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 6621');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Removals household', 'access_restricted' => false]);
    $resident = HouseholdHolder::of($this->household, 'Rae Removals');

    $this->other = Household::query()->firstOrCreate(['unit_id' => FacilitiesFixture::scratchUnit('Lot 6622')->id], ['name' => 'Other removals household', 'access_restricted' => false]);

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'removals.'.bin2hex(random_bytes(3)).'@example.com', 'full_name' => 'Rae Removals',
        'resident_id' => $resident->id, 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->account = $account;
    $this->token = $account->createToken('resident-app:removals', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    foreach (['household_emergency_contacts', 'household_vehicles'] as $table) {
        DB::connection('tenant')->table($table)->whereIn('household_id', [$this->household->id, $this->other->id])->delete();
    }
});

/** @param array<string, scalar> $params */
function removalsCall(TestCase $test, string $name, array $params = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->json($entry['method'], $uri, [], $entry['write'] ? [
        'Idempotency-Key' => 'removals-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ] : []);
    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

function removalsRow(string $table, int $householdId, array $values): int
{
    return (int) DB::connection('tenant')->table($table)->insertGetId(['household_id' => $householdId, ...$values, 'created_at' => now(), 'updated_at' => now()]);
}

it('removes the household\'s own emergency contact and audits the relationship and a masked number, never the name', function () {
    $mine = removalsRow('household_emergency_contacts', $this->household->id, ['name' => 'Marcia Ahmed', 'relationship' => 'Spouse', 'phone' => '876-555-0148']);
    $kept = removalsRow('household_emergency_contacts', $this->household->id, ['name' => 'Karen Ahmed', 'relationship' => 'Sister', 'phone' => '876-555-0149']);
    $theirs = removalsRow('household_emergency_contacts', $this->other->id, ['name' => 'Next Door', 'relationship' => 'Friend', 'phone' => '876-555-0150']);

    // Another household's row and an id nobody has: the same 404, and neither is touched.
    expect(removalsCall($this, 'contacts.destroy', ['contact' => $theirs], 404)->json('error.code'))->toBe('not_found')
        ->and(removalsCall($this, 'contacts.destroy', ['contact' => 999_999_999], 404)->json('error.message'))
        ->toBe(removalsCall($this, 'contacts.destroy', ['contact' => $theirs], 404)->json('error.message'));
    expect(DB::connection('tenant')->table('household_emergency_contacts')->where('id', $theirs)->exists())->toBeTrue();

    $removed = removalsCall($this, 'contacts.destroy', ['contact' => $mine]);
    expect($removed->json('id'))->toBe($mine)
        ->and($removed->json('removed'))->toBeTrue()
        ->and(collect(removalsCall($this, 'contacts.index')->json('items'))->pluck('id')->all())->toBe([$kept]);

    // Gone is gone: a second press is the 404, not a second removal.
    removalsCall($this, 'contacts.destroy', ['contact' => $mine], 404);

    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'household.contact_removed')->where('entity_id', (string) $this->household->id)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and(json_decode((string) $entry->before, true))->toMatchArray(['contact_id' => $mine, 'relationship' => 'Spouse', 'number' => '+1876•••0148'])
        ->and(json_decode((string) $entry->before, true))->toHaveCount(3)
        ->and(json_decode((string) $entry->after, true))->toMatchArray(['removed_by_account_id' => $this->account->id, 'by' => 'Rae Removals', 'via' => 'resident_app'])
        ->and((string) $entry->before.(string) $entry->after)->not->toContain('Marcia')->not->toContain('555-0148');
});

it('removes the household\'s own vehicle, audited with its plate, and never another household\'s', function () {
    $mine = removalsRow('household_vehicles', $this->household->id, ['plate' => 'RM 1001', 'make' => 'Toyota', 'model' => 'Axio', 'colour' => 'Silver']);
    $theirs = removalsRow('household_vehicles', $this->other->id, ['plate' => 'RM 2002', 'make' => 'Honda', 'model' => 'Fit', 'colour' => 'Blue']);

    removalsCall($this, 'vehicles.destroy', ['vehicle' => $theirs], 404);
    removalsCall($this, 'vehicles.destroy', ['vehicle' => 999_999_999], 404);
    expect(DB::connection('tenant')->table('household_vehicles')->where('id', $theirs)->exists())->toBeTrue();

    $removed = removalsCall($this, 'vehicles.destroy', ['vehicle' => $mine]);
    expect($removed->json('id'))->toBe($mine)
        ->and($removed->json('removed'))->toBeTrue()
        ->and(removalsCall($this, 'vehicles.index')->json('items'))->toBe([]);
    removalsCall($this, 'vehicles.destroy', ['vehicle' => $mine], 404);

    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'household.vehicle_removed')->where('entity_id', (string) $this->household->id)->first();
    expect($entry)->not->toBeNull()
        ->and(json_decode((string) $entry->before, true))->toMatchArray(['vehicle_id' => $mine, 'plate' => 'RM 1001'])
        ->and(json_decode((string) $entry->after, true)['removed_by_account_id'])->toBe($this->account->id);
});

it('is an online-only write the outbox never queues, and a removal is not batched', function () {
    foreach (['contacts.destroy', 'vehicles.destroy'] as $name) {
        $entry = Catalogue::endpoints()[$name];

        expect($entry['offline'])->toStartWith('Online only')
            ->and($entry['batch'])->toBeFalse()
            ->and($entry['method'])->toBe('DELETE')
            ->and($entry['apps'])->toBe([AppMatrix::RESIDENT]);
    }
});
