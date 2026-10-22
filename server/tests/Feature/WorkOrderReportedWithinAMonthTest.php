<?php

declare(strict_types=1);

use App\Http\Controllers\Estate\FacilitiesController;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Role;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A work order is dated within a month of being keyed (FAC-12, register DR-084)
|--------------------------------------------------------------------------
|
| "Reported at" is there for the call taken on Saturday and keyed on Monday. It took 1 January 2020, and the ticket
| printed "Jan 1, 10:00 AM" with no year, as if it were this January, five years overdue on arrival. The office may now
| date a report up to thirty days back, on the estate's clock, and the page is told the same bound for its field.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('refuses a report dated more than thirty days back, on the estate\'s clock, and takes one inside the month', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $order = fn (string $title, string $reportedAt): array => [
        'title' => $title, 'location' => 'Back gate', 'category' => 'Plumbing', 'priority' => 'low', 'reported_at' => $reportedAt,
    ];

    try {
        $this->actingAs($manager)
            ->from(FacilitiesFixture::url('/facilities/maintenance'))
            ->post(FacilitiesFixture::url('/facilities/maintenance'), $order('FAC-12 too far back', '2020-01-01 10:00:00'))
            ->assertSessionHasErrors(['reported_at' => 'A report is keyed within 30 days of being made. 1 Jan 2020, 10:00 AM is further back than that.']);

        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance'), $order('FAC-12 a day past the month', SiteTime::now()->subDays(31)->format('Y-m-d H:i:s')))
            ->assertSessionHasErrors('reported_at');

        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance'), $order('FAC-12 inside the month', SiteTime::now()->subDays(29)->format('Y-m-d H:i:s')))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        FacilitiesFixture::boot();

        expect(MaintenanceTicket::query()->where('title', 'like', 'FAC-12%')->pluck('title')->all())->toBe(['FAC-12 inside the month']);
    } finally {
        FacilitiesFixture::boot();
    }
});

it('tells the page the same bound for its field', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))
        ->get(FacilitiesFixture::url('/facilities/maintenance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reportedWithinDays', FacilitiesController::REPORTED_WITHIN_DAYS));

    expect(FacilitiesController::REPORTED_WITHIN_DAYS)->toBe(30);
});
