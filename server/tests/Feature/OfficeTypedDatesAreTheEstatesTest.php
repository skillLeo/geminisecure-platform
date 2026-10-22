<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Role;
use App\Services\Estate\Amenities;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| "Not in the future" is judged on the estate's clock (B050)
|--------------------------------------------------------------------------
|
| A work order's "reported at" is typed into a datetime-local field: digits with
| no offset, which ARE the estate's wall clock (M4 §3.1), and `SiteTime::parse()`
| stores the UTC moment they mean. The validator beside it said
| `before_or_equal:now` — and read the same digits as UTC. Kingston is five hours
| behind, so at 10:00 in Kingston a manager could type 14:30 today, the validator
| saw a time half an hour ago, and the ticket was filed four and a half hours in
| the future: the service clock started late and the ticket's age came out
| negative.
|
| The deposit dates on the booking detail were the same rule on a calendar:
| `before_or_equal:today` is UTC's today, which from 7 PM in Kingston is already
| tomorrow — so a deposit could be recorded as received on a day that had not
| happened on the estate.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
});

/** A work order as board 17's form sends it. */
function officeTypedWorkOrder(string $title, string $reportedAt): array
{
    return [
        'title' => $title,
        'location' => 'Main Gate',
        'category' => 'Access control',
        'priority' => 'medium',
        'reported_at' => $reportedAt,
    ];
}

it('refuses a work order reported later than the estate\'s own now, even when UTC has already passed that time', function () {
    // 10:00 in Kingston is 15:00 UTC. The manager types 14:30 — four and a half hours ahead.
    Carbon::setTestNow(Carbon::parse('2026-09-24 15:00:00', 'UTC'));

    expect(SiteTime::now()->format('Y-m-d H:i'))->toBe('2026-09-24 10:00');

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/maintenance'), officeTypedWorkOrder('Reported from the future', '2026-09-24T14:30'))
        ->assertSessionHasErrors('reported_at');

    FacilitiesFixture::boot();

    expect(MaintenanceTicket::query()->where('title', 'Reported from the future')->exists())->toBeFalse();
});

it('files a work order reported earlier today on the estate\'s clock, at the moment those digits mean', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-24 15:00:00', 'UTC'));

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/maintenance'), officeTypedWorkOrder('Reported a quarter-hour ago', '2026-09-24T09:45'))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    FacilitiesFixture::boot();

    $ticket = MaintenanceTicket::query()->where('title', 'Reported a quarter-hour ago')->firstOrFail();

    // 09:45 in Kingston is 14:45 UTC — in the past, and exactly where the clock starts.
    expect($ticket->reported_at->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-09-24 14:45')
        ->and($ticket->reported_at->lessThanOrEqualTo(Carbon::now()))->toBeTrue();
});

it('has the service refuse a report in the future, whichever door it comes through', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-24 15:00:00', 'UTC'));

    expect(fn () => app(Maintenance::class)->report(
        title: 'Service-level future report',
        locationLabel: 'Main Gate',
        reportedAt: '2026-09-24T14:30',
    ))->toThrow(DomainException::class);

    expect(MaintenanceTicket::query()->where('title', 'Service-level future report')->exists())->toBeFalse();
});

it('refuses a deposit recorded on a day that has not yet begun on the estate', function (string $act) {
    // Held at 10 AM on the estate's Sep 24, before the evening below. On the real clock it was held on whatever day the
    // suite ran, and once that day was past Sep 24 the refund came before the money it gives back, which M85 A6 refuses
    // (a bank never goes below nothing). This test is about the day, so its days are kept in order.
    Carbon::setTestNow(Carbon::parse('2026-09-24 15:00:00', 'UTC'));

    $amenities = app(Amenities::class);

    $booking = $amenities->book(
        amenity: FacilitiesFixture::amenity('Gazebo'),
        unit: FacilitiesFixture::scratchUnit($act === 'hold' ? 'DAY-1' : 'DAY-2'),
        residentName: 'Evening Recorder',
        startsAt: SiteTime::today()->addDays($act === 'hold' ? 163 : 164)->addHours(10),
        endsAt: SiteTime::today()->addDays($act === 'hold' ? 163 : 164)->addHours(12),
    );

    $amenities->approve($booking);

    if ($act === 'refund') {
        $amenities->holdDeposit($booking->refresh());
    }

    $stateBefore = $booking->refresh()->deposit_state;

    // 8 PM on Sep 24 in Kingston is already 01:00 on Sep 25 in UTC.
    Carbon::setTestNow(Carbon::parse('2026-09-25 01:00:00', 'UTC'));

    expect(SiteTime::today()->toDateString())->toBe('2026-09-24');

    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/deposit/'.$act), ['on' => '2026-09-25'])
        ->assertSessionHasErrors('on');

    FacilitiesFixture::boot();

    expect(AmenityBooking::query()->findOrFail($booking->id)->deposit_state)->toBe($stateBefore);

    // The estate's own today is accepted.
    $this->actingAs($this->manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/deposit/'.$act), ['on' => '2026-09-24'])
        ->assertSessionHasNoErrors();
})->with(['hold', 'refund']);
