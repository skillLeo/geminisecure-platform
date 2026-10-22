<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;
use App\Models\Role;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The bookings waiting on the office are one press away (AME-2, register DR-089)
|--------------------------------------------------------------------------
|
| The diary counted the bookings waiting on a decision in an amber chip and could not show them: the chip was text,
| and nothing on the server filtered by status. `?waiting=1` now keeps only the bookings waiting on a decision, in SQL
| and across every page, beside the amenity chip if one is chosen; the count stays the whole diary's.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    $this->withoutVite();
});

it('keeps only the bookings waiting on a decision, the count still the whole diary\'s', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $pending = AmenityBooking::query()->where('status', AmenityBooking::PENDING)->count();
    $all = AmenityBooking::query()->count();

    expect($pending)->toBeGreaterThan(0)->and($all)->toBeGreaterThan($pending);

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/amenities/bookings?waiting=1'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('waiting', true)
            ->where('pending', $pending)
            ->where('table.total', $pending)
            ->where('rows', fn ($rows) => collect($rows)->every(fn (array $row): bool => $row['status'] === AmenityBooking::PENDING)));

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/amenities/bookings'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('waiting', false)
            ->where('table.total', $all));
});

it('narrows an amenity\'s bookings to the ones waiting, beside the amenity chip', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $booking = AmenityBooking::query()->with('amenity')->where('status', AmenityBooking::PENDING)->firstOrFail();
    $expected = AmenityBooking::query()
        ->where('status', AmenityBooking::PENDING)
        ->where('amenity_id', $booking->amenity_id)
        ->count();

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/amenities/bookings?waiting=1&amenity='.urlencode($booking->amenity->name)))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', $booking->amenity->name)
            ->where('waiting', true)
            ->where('table.total', $expected));
});
