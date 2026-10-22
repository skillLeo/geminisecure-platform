<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Estate\Amenities;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A BOOKING WAITS FOR THE OFFICE, AMENITY BY AMENITY (M53 §2, Hassam's Sunday order)
|--------------------------------------------------------------------------
|
| Every booking made in the app was paid for and confirmed at once, so the console's Approve and Decline never had a
| booking to act on. The switch is on by default: a booking of the amenity is a request; the app may not pay for it;
| the office's approval confirms it and charges its fee in the same step; a request nobody decided before its time
| expires in the morning run, and is never called declined. Off, an amenity keeps the pay-in-app flow
| (`M47AmenityCheckoutTest`). On this file's own lots (9484 to 9488), one a test, and its own amenities.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    config(['payments.demo_card' => true]);

    // 10:00 AM at the estate (UTC−5) on Wednesday 14 October 2026.
    Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00', 'UTC'));

    $this->place = app(Amenities::class)->addAmenity([
        'name' => 'Approval Pavilion '.bin2hex(random_bytes(2)), 'icon_key' => 'pavilion', 'capacity' => 40,
        'fee' => '4000.00', 'deposit' => null, 'opens_at' => '08:00', 'closes_at' => '22:00',
        'booking_window_days' => 60, 'cancellation_hours' => 24,
    ]);
});

afterEach(function () {
    FacilitiesFixture::boot();
    // Not deleted (its bookings carry posted entries), but out of every list the next test reads.
    $this->place->forceFill(['is_active' => false, 'is_bookable' => false])->save();
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** A household of one on its own lot, its member signed in to the app. @return array{0: Unit, 1: string, 2: ResidentAccount} */
function approvalHome(string $lot): array
{
    FacilitiesFixture::boot();
    $unit = FacilitiesFixture::scratchUnit($lot);
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Approval '.$lot, 'access_restricted' => false]);
    $email = 'approval.'.bin2hex(random_bytes(3)).'@example.com';

    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => 'Hassam Ahmed', 'email' => $email, 'phone' => '+1 876 555 0172', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Hassam Ahmed',
        'resident_id' => $person->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$unit, approvalSignIn($account), $account];
}

/** The app signed in for this account, as it is after a sign-in. */
function approvalSignIn(ResidentAccount $account): string
{
    return $account->createToken('resident-app:approval', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
}

/** @param  array<string, mixed>  $body */
function approvalCall(TestCase $test, string $token, string $name, array $body = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    app('auth')->forgetGuards();

    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson('/api/v1/'.$entry['uri'])
        : $test->withToken($token)->postJson('/api/v1/'.$entry['uri'], $body, ['Idempotency-Key' => 'approval-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String()]);
    FacilitiesFixture::boot();

    return $response;
}

/** Saturday 17 October, 2 PM to 4 PM at the estate: hours inside every amenity here. @return array{starts_at: string, ends_at: string} */
function approvalHours(): array
{
    return ['starts_at' => '2026-10-17T14:00:00-05:00', 'ends_at' => '2026-10-17T16:00:00-05:00'];
}

it('makes a new amenity wait for the office unless it is switched off, and tells the app so', function () {
    [, $token] = approvalHome('Lot 9484');

    expect($this->place->fresh()->requires_approval)->toBeTrue();

    $listed = collect(approvalCall($this, $token, 'amenities.index')->assertOk()->json('items'))->firstWhere('id', $this->place->id);
    expect($listed['requires_approval'])->toBeTrue();
});

it('refuses to take payment in the app for an amenity that waits, and takes its request uncharged', function () {
    [$unit, $token] = approvalHome('Lot 9485');

    approvalCall($this, $token, 'bookings.book', [...approvalHours(), 'amenity_id' => $this->place->id, 'brand' => 'visa', 'last4' => '4242', 'total' => '4000.00'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'approval_required');

    expect(AmenityBooking::query()->where('amenity_id', $this->place->id)->count())->toBe(0);

    $request = approvalCall($this, $token, 'bookings.store', [...approvalHours(), 'amenity_id' => $this->place->id])->assertCreated();

    expect($request->json('status'))->toBe('pending')
        ->and($request->json('fee_charged'))->toBeFalse()
        ->and(Charge::query()->where('unit_id', $unit->id)->count())->toBe(0);
});

it('confirms a request and charges its fee in the same step when the office approves it', function () {
    [$unit, $token] = approvalHome('Lot 9486');
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $id = (int) approvalCall($this, $token, 'bookings.store', [...approvalHours(), 'amenity_id' => $this->place->id])->assertCreated()->json('id');

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$id.'/approve'))
        ->assertRedirect()
        ->assertSessionHas('success', fn (string $said): bool => str_contains($said, 'confirmed') && str_contains($said, '$4,000') && str_contains($said, 'Lot 9486'));

    $booking = AmenityBooking::query()->findOrFail($id);
    $fee = Charge::query()->findOrFail($booking->fee_charge_id);

    expect($booking->status)->toBe(AmenityBooking::CONFIRMED)
        ->and($fee->unit_id)->toBe($unit->id)
        ->and((int) $fee->amount_minor)->toBe(400000);

    // Charged once: approving again is refused, and no second charge is raised.
    $this->actingAs($admin)->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$id.'/approve'))->assertSessionHasErrors('booking');
    expect(Charge::query()->where('unit_id', $unit->id)->count())->toBe(1);
});

it('confirms a request for a free amenity with nothing charged', function () {
    [$unit, $token] = approvalHome('Lot 9487');
    $this->place->forceFill(['booking_fee_minor' => null])->save();
    $id = (int) approvalCall($this, $token, 'bookings.store', [...approvalHours(), 'amenity_id' => $this->place->id])->assertCreated()->json('id');

    app(Amenities::class)->approveRequest(AmenityBooking::query()->findOrFail($id), FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN));

    expect(AmenityBooking::query()->findOrFail($id)->status)->toBe(AmenityBooking::CONFIRMED)
        ->and(Charge::query()->where('unit_id', $unit->id)->count())->toBe(0);
});

it('expires a request nobody decided before its time in the morning run, and never calls it declined', function () {
    [, $token, $account] = approvalHome('Lot 9488');
    $id = (int) approvalCall($this, $token, 'bookings.store', [...approvalHours(), 'amenity_id' => $this->place->id])->assertCreated()->json('id');

    // Saturday 2:30 PM at the estate: its time has come and nobody answered. (Three days on, the phone signs in again.)
    Carbon::setTestNow(Carbon::parse('2026-10-17 19:30:00', 'UTC'));
    $token = approvalSignIn($account);

    $report = app(Amenities::class)->settle();
    $booking = AmenityBooking::query()->findOrFail($id);

    expect($booking->status)->toBe(AmenityBooking::EXPIRED)
        ->and($booking->declined_reason)->toBeNull()
        ->and(collect($report['expired'])->contains(fn (string $line): bool => str_contains($line, $booking->reference)))->toBeTrue();

    // The app reads it as expired, its fee owed as nothing.
    $listed = collect(approvalCall($this, $token, 'bookings.index')->assertOk()->json('items'))->firstWhere('id', $id);
    expect($listed['status'])->toBe('expired')
        ->and($listed['fee_outstanding'])->toBe('0.00');
});
