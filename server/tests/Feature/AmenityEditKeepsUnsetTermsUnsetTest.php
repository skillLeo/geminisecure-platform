<?php

declare(strict_types=1);

use App\Models\Estate\Amenity;
use App\Models\Role;
use App\Services\Estate\Amenities;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An edit to the rate card writes only what was typed (found beside B045)
|--------------------------------------------------------------------------
|
| An amenity's booking window and cancellation notice may be unset — the seeded
| cards leave both unset, because no board states them — and unset means "no
| limit". Editing the card cast whatever came back to an integer, so an unset
| window was saved as ZERO: the amenity then took "bookings up to 0 days ahead",
| which is none at all, and its cancellation notice became "0 hours". A Property
| Manager correcting a fee had closed the diary without touching either field.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the card edited here is put back
| exactly as it was, every column the edit writes, whether the test passed or not.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->card = FacilitiesFixture::amenity('Community Centre');
    $this->before = $this->card->only([
        'name', 'icon_key', 'capacity', 'booking_fee_minor', 'deposit_minor', 'opens_at', 'closes_at',
        'booking_window_days', 'cancellation_hours', 'is_bookable', 'is_active',
    ]);
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        FacilitiesFixture::boot();

        Amenity::query()->findOrFail($this->card->id)->forceFill($this->before)->save();
    }
});

/** The terms board 20's edit panel sends for this card, as it stands. */
function rateCardAsDrawn(Amenity $card, array $changes = []): array
{
    return array_merge([
        'name' => $card->name,
        'icon_key' => $card->icon_key,
        'capacity' => $card->capacity,
        'fee' => $card->booking_fee_minor === null ? '' : number_format($card->booking_fee_minor / 100, 2, '.', ''),
        'deposit' => $card->deposit_minor === null ? '' : number_format($card->deposit_minor / 100, 2, '.', ''),
        'opens_at' => substr((string) $card->opens_at, 0, 5),
        'closes_at' => substr((string) $card->closes_at, 0, 5),

        // What the panel's two number fields hold for a card that never set them.
        'booking_window_days' => $card->booking_window_days,
        'cancellation_hours' => $card->cancellation_hours,
        'is_bookable' => (bool) $card->is_bookable,
    ], $changes);
}

it('leaves an unset booking window and cancellation notice unset when only the fee is changed', function () {
    // The precondition the finding rests on: the seeded card sets neither.
    expect($this->card->booking_window_days)->toBeNull()
        ->and($this->card->cancellation_hours)->toBeNull();

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$this->card->id), rateCardAsDrawn($this->card, ['fee' => '7500.00']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    FacilitiesFixture::boot();

    $after = Amenity::query()->findOrFail($this->card->id);

    expect($after->booking_fee_minor)->toBe(7_500_00)
        ->and($after->booking_window_days)->toBeNull()
        ->and($after->cancellation_hours)->toBeNull();
});

it('still takes a booking months ahead after the fee is edited, because the diary was never closed', function () {
    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$this->card->id), rateCardAsDrawn($this->card, ['fee' => '7500.00']))
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $booking = app(Amenities::class)->book(
        amenity: Amenity::query()->findOrFail($this->card->id),
        unit: FacilitiesFixture::scratchUnit('AMN-5'),
        residentName: 'Far Ahead Household',
        startsAt: SiteTime::today()->addDays(165)->addHours(10),
        endsAt: SiteTime::today()->addDays(165)->addHours(12),
    );

    expect($booking->fee_minor)->toBe(7_500_00)
        ->and($booking->cancellation_hours)->toBeNull();
});

it('still writes a window and a notice the Property Manager did type', function () {
    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$this->card->id), rateCardAsDrawn($this->card, [
            'booking_window_days' => 60,
            'cancellation_hours' => 0,
        ]))
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    $after = Amenity::query()->findOrFail($this->card->id);

    // Zero hours is a real rule — cancellable until it starts — when somebody types it.
    expect($after->booking_window_days)->toBe(60)
        ->and($after->cancellation_hours)->toBe(0);
});
