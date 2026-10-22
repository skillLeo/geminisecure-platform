<?php

declare(strict_types=1);

use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Role;
use App\Services\Estate\Amenities;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Money typed into the amenity screens is dollars and cents, or refused in words (B045)
|--------------------------------------------------------------------------
|
| Board 20's fee and deposit were checked only as "numeric", and the service then
| asked Brick for an exact JMD amount. "4500.505" is numeric and is not an amount
| of Jamaican dollars, so Brick threw — not a DomainException — and the Property
| Manager lost the form to a 500 page. "1e30" is numeric too, and overflowed. The
| forfeit amount on the booking detail took the other wrong turn: it ROUNDED what
| was typed, so the estate kept a figure nobody entered.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the booking here stands on a
| scratch lot of this file's own, on a day nobody else books, and the one card
| edited is put back afterwards whether the test passed or not.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->amenities = app(Amenities::class);
    $this->manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $this->restore = null;
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        FacilitiesFixture::boot();

        if ($this->restore instanceof Closure) {
            ($this->restore)();
        }
    }
});

/**
 * The terms board 20's form sends, with one field changed.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function amenityMoneyTerms(array $changes = []): array
{
    return array_merge([
        'name' => 'Court Five-Oh-Five',
        'icon_key' => 'pavilion',
        'capacity' => 10,
        'fee' => '',
        'deposit' => '',
        'opens_at' => '08:00',
        'closes_at' => '20:00',
    ], $changes);
}

it('refuses a fee or a deposit that is not an amount of dollars and cents, against the field, without a 500', function (string $field, string $typed) {
    $response = $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/amenities'), amenityMoneyTerms([$field => $typed]));

    $response->assertStatus(302)->assertSessionHasErrors($field);

    FacilitiesFixture::boot();

    expect(Amenity::query()->where('name', 'Court Five-Oh-Five')->exists())->toBeFalse();
})->with([
    'a fee in tenths of a cent' => ['fee', '3000.505'],
    'a computed fee' => ['fee', '3333.333'],
    'a deposit in tenths of a cent' => ['deposit', '4500.505'],
    'a fee in exponent form' => ['fee', '1e30'],
    'a fee no bigint could hold' => ['fee', '99999999999999999999'],
]);

it('refuses the same on an edit, and leaves the card exactly as it was', function () {
    $gazebo = FacilitiesFixture::amenity('Gazebo');

    /*
     * EVERY COLUMN AN EDIT WRITES, put back afterwards. The Gazebo is shared by
     * every file that boots this fixture — the Resident App's contract walk
     * books it — so an edit left behind is somebody else's failure.
     */
    $columns = [
        'name', 'icon_key', 'capacity', 'booking_fee_minor', 'deposit_minor', 'opens_at', 'closes_at',
        'booking_window_days', 'cancellation_hours', 'is_bookable', 'is_active',
    ];
    $card = $gazebo->only($columns);

    $this->restore = static function () use ($gazebo, $card): void {
        Amenity::query()->findOrFail($gazebo->id)->forceFill($card)->save();
    };

    // What board 20's edit panel sends for this card, with a sub-cent fee typed.
    $terms = [
        'name' => $gazebo->name,
        'icon_key' => $gazebo->icon_key,
        'capacity' => $gazebo->capacity,
        'fee' => '4500.505',
        'deposit' => '5000.00',
        'opens_at' => substr((string) $gazebo->opens_at, 0, 5),
        'closes_at' => substr((string) $gazebo->closes_at, 0, 5),
        'booking_window_days' => $gazebo->booking_window_days,
        'cancellation_hours' => $gazebo->cancellation_hours,
        'is_bookable' => (bool) $gazebo->is_bookable,
    ];

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$gazebo->id), $terms)
        ->assertStatus(302)
        ->assertSessionHasErrors('fee');

    FacilitiesFixture::boot();

    expect(Amenity::query()->findOrFail($gazebo->id)->only($columns))->toBe($card);

    // A figure with one decimal place IS dollars and cents, and is kept exactly.
    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$gazebo->id), [...$terms, 'fee' => '4500.5'])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    expect(Amenity::query()->findOrFail($gazebo->id)->booking_fee_minor)->toBe(4_500_50);
});

it('has the service refuse a sub-cent or runaway amount in words, whoever calls it', function (string $typed) {
    expect(fn () => $this->amenities->addAmenity(amenityMoneyTerms(['fee' => $typed])))
        ->toThrow(DomainException::class);

    expect(Amenity::query()->where('name', 'Court Five-Oh-Five')->exists())->toBeFalse();
})->with(['3000.505', '1e30', '99999999999999999999']);

it('refuses to keep a part of a deposit typed in tenths of a cent, rather than rounding what the estate keeps', function () {
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Gazebo'),
        unit: FacilitiesFixture::scratchUnit('AMN-1'),
        residentName: 'Deposit Tester',
        startsAt: SiteTime::today()->addDays(157)->addHours(10),
        endsAt: SiteTime::today()->addDays(157)->addHours(12),
    );

    $this->amenities->approve($booking);
    $this->amenities->holdDeposit($booking->refresh());

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/deposit/forfeit'), [
            'reason' => 'Damage to the gazebo railing',
            'amount' => '100.505',
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors('amount');

    FacilitiesFixture::boot();

    expect(AmenityBooking::query()->findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD);
});
