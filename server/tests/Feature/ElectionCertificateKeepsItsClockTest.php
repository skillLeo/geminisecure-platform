<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Services\Estate\Governance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The election certificate says whose clock its time is on (Hassam, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| "The clock name is DROPPED where the reader is certainly at the estate, and KEPT where the document leaves the estate
| or the reader might not be." A certificate leaves the estate, and "an ambiguous timestamp on a result is a gift to
| anyone disputing it": so its time carries "Jamaica time", and the day is the estate's (a result certified at 8:30 PM
| in Kingston on September 30 is 1:30 AM on October 1 by the server's clock).
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED, so the paper here is in a year of this file's own, deleted after.
|
*/

const CERTIFICATE_YEAR = 2074;

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        FacilitiesFixture::boot();
        Ballot::query()->where('year', CERTIFICATE_YEAR)->whereDoesntHave('receipts')->delete();
    }
});

it('prints the certified time with the clock\'s name, on the estate\'s day', function () {
    $ballot = Ballot::query()->create([
        'year' => CERTIFICATE_YEAR,
        'code' => null,
        'title' => CERTIFICATE_YEAR.' Election',
        'kind' => Ballot::ELECTION,
        'question' => null,
        'stage' => Ballot::CERTIFIED,
        'quorum_percent' => 25,
    ]);

    // 01:30 UTC on 1 October is 8:30 PM in Kingston on 30 September.
    $ballot->forceFill(['certified_at' => Carbon::parse('2026-10-01 01:30:00', 'UTC')])->save();

    $certificate = app(Governance::class)->certificateData($ballot->id);

    expect($certificate['certified_at'])->toBe('September 30, 2026 at 8:30 PM Jamaica time');
});
