<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Models\Role;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A meeting is scheduled up to eighteen months ahead (GV-10, register DR-098)
|--------------------------------------------------------------------------
|
| The notice period was a floor with no ceiling: a meeting dated 2030 was taken. A date more than eighteen months
| ahead, on the estate's calendar, is now refused under the date in words that name the latest day, and the scheduler
| is told the same bound and sentence for its field. (The line under the date for a date inside the notice period was
| already there.)
|
*/

const UPPER_BOUND_TEST_TITLE = 'Upper Bound Test Meeting';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    Meeting::query()->where('title', UPPER_BOUND_TEST_TITLE)->delete();
});

it('refuses a meeting dated more than eighteen months ahead, naming the latest day', function () {
    $latest = SiteTime::date()->addMonths(Meeting::SCHEDULED_WITHIN_MONTHS);

    $this->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))
        ->from(FacilitiesFixture::url('/governance/meetings/new'))
        ->post(FacilitiesFixture::url('/governance/meetings'), ['type' => 'committee', 'title' => UPPER_BOUND_TEST_TITLE, 'starts_at' => '2030-01-10 10:00'])
        ->assertSessionHasErrors(['starts_at' => 'A meeting is scheduled up to 18 months ahead. The latest day is '.$latest->format('j M Y').'.']);

    $this->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))
        ->from(FacilitiesFixture::url('/governance/meetings/new'))
        ->post(FacilitiesFixture::url('/governance/meetings'), ['type' => 'committee', 'title' => UPPER_BOUND_TEST_TITLE, 'starts_at' => $latest->copy()->subDay()->format('Y-m-d').' 10:00'])
        ->assertSessionDoesntHaveErrors('starts_at');
});

it('tells the scheduler the same bound and sentence for its field', function () {
    $latest = SiteTime::date()->addMonths(Meeting::SCHEDULED_WITHIN_MONTHS);

    $this->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))
        ->get(FacilitiesFixture::url('/governance/meetings/new'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notice.latest', $latest->toDateString())
            ->where('notice.latest_line', 'A meeting is scheduled up to 18 months ahead. The latest day is '.$latest->format('j M Y').'.'));

    expect(Meeting::SCHEDULED_WITHIN_MONTHS)->toBe(18);
});
