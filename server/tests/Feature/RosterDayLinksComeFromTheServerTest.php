<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The roster's previous and next day are calendar arithmetic (bug hunt B114)
|--------------------------------------------------------------------------
|
| Roster.vue built them in the browser: `new Date('2026-09-24T00:00:00')` is
| LOCAL midnight, and `toISOString()` prints UTC — so in any browser east of
| UTC "Next day" reloaded the same day and "Previous day" skipped one. A day is
| a date, not a moment, so the server works the two neighbours out on the
| calendar and the page links to what it is given. The browser's zone never
| enters it.
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

it('sends the day before and the day after with the day, across a month and a year end', function (string $day, string $previous, string $next) {
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->get('/guards/roster?date='.$day)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('day.date', $day)
            ->where('day.previous', $previous)
            ->where('day.next', $next));
})->with([
    'mid-month' => ['2026-09-24', '2026-09-23', '2026-09-25'],
    'a month end' => ['2026-09-30', '2026-09-29', '2026-10-01'],
    'a year end' => ['2026-12-31', '2026-12-30', '2027-01-01'],
    'a leap day' => ['2028-02-29', '2028-02-28', '2028-03-01'],
]);
