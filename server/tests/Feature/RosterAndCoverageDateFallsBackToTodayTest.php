<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Gemini\Roster;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A ?date= that is not a real day selects today (bug hunt B014)
|--------------------------------------------------------------------------
|
| Both screens promise it in their own comments: "a malformed ?date= lands on
| today rather than an error: this parameter selects a view". The check was a
| shape — four digits, two, two — so 2026-13-45 passed it and reached
| Carbon::parse, which threw, and a stale bookmark answered 500. And 2026-02-30
| passed it too and quietly became 2 March, which is a different day from the
| one asked for rather than a fall-back to today.
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

dataset('dates that are not days', ['2026-13-45', '2026-12-32', '2026-13-01', '9999-99-99', '2026-02-30', '2026-00-00']);

it('opens the coverage board on today for a date that is not a day', function (string $date) {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)
        ->get('/dispatch/coverage?date='.$date)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('date', SiteTime::today()->toDateString())
            ->where('isToday', true));
})->with('dates that are not days');

it('opens the roster on today, with no day view asked for, for a date that is not a day', function (string $date) {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)
        ->get('/guards/roster?date='.$date)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('day.date', SiteTime::today()->toDateString())
            ->where('dayRequested', false));
})->with('dates that are not days');

it('keeps a real date exactly as asked, on both screens', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $day = SiteTime::today()->addDays(3)->toDateString();

    $this->actingAs($director)
        ->get('/dispatch/coverage?date='.$day)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('date', $day));

    $this->actingAs($director)
        ->get('/guards/roster?date='.$day)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('day.date', $day)->where('dayRequested', true));

    // The service is not a second way in: a day it cannot read is today.
    expect(app(Roster::class)->day('2026-13-45')['date'])->toBe(SiteTime::today()->toDateString());
});
