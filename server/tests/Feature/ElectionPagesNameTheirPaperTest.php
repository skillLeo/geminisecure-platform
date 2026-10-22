<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Models\Role;
use App\Services\Estate\Governance;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Each election page names the paper it shows (Security's dev-server note, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| A year's election can have more than one paper. The control room draws the stage of the paper furthest behind and
| the results page the count of the paper furthest along, so with Ballot A at its tally and Ballot B taking
| nominations, one page said "voting closed" and the other "nominations open" for the same year, and neither said
| which paper it meant. Both now send every paper with its stage and which one they show. And a committee's motion is
| not the year's election (M62 A9), on the results page as on the control room.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED, so the papers here are in a year of this file's own, deleted after.
|
*/

const PAPERS_YEAR = 2073;

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->governance = app(Governance::class);
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        FacilitiesFixture::boot();
        Ballot::query()->where('year', PAPERS_YEAR)->whereDoesntHave('receipts')->delete();
    }
});

/** One paper of this file's year, written straight to its row: nothing is announced and nobody is told. */
function papersBallot(?string $code, string $title, string $kind, string $stage): Ballot
{
    return Ballot::query()->create([
        'year' => PAPERS_YEAR,
        'code' => $code,
        'title' => $title,
        'kind' => $kind,
        'question' => $kind === Ballot::RESOLUTION ? 'Does the committee agree?' : null,
        'stage' => $stage,
        'quorum_percent' => 25,
    ]);
}

it('names, on each page, the paper it shows of a year with two, and lists the other with its stage', function () {
    $a = papersBallot('A', 'Ballot A (Test Executive)', Ballot::ELECTION, Ballot::TALLY);
    $b = papersBallot('B', 'Ballot B (Test Phases)', Ballot::ELECTION, Ballot::NOMINATIONS_OPEN);

    $room = $this->governance->controlRoom(PAPERS_YEAR);
    $results = $this->governance->resultsBoard(PAPERS_YEAR);

    // The control room's stage is the paper furthest behind, and it says so.
    expect($room['stage'])->toBe(Ballot::NOMINATIONS_OPEN)
        ->and($room['papers'])->toBe([
            ['id' => $a->id, 'title' => 'Ballot A (Test Executive)', 'stage' => Ballot::TALLY, 'shown' => false],
            ['id' => $b->id, 'title' => 'Ballot B (Test Phases)', 'stage' => Ballot::NOMINATIONS_OPEN, 'shown' => true],
        ]);

    // The results page's count is the paper furthest along, and it says so.
    expect($results['ballot']['id'])->toBe($a->id)
        ->and(array_column($results['papers'], 'shown', 'id'))->toBe([$a->id => true, $b->id => false]);
});

it('reads a cancelled election\'s moment back from the database as a date, so its control room draws', function () {
    // Larastan read `cancelled_at` as a string handed to SiteTime::local(), which would be a TypeError under
    // strict_types (Hassam, 12 Oct: read each warning; one in this project was a real crash). Cancelled through the
    // service, then read fresh, the way the page reads it.
    $ballot = papersBallot(null, PAPERS_YEAR.' Election', Ballot::ELECTION, Ballot::NOMINATIONS_OPEN);
    $this->governance->cancelElection($ballot, 'A test cancellation.', PAPERS_YEAR.' Election', FacilitiesFixture::viewer(Role::PRESIDENT));
    FacilitiesFixture::boot();

    $life = $this->governance->controlRoom(PAPERS_YEAR)['life'];

    expect($life['stage'])->toBe(Ballot::CANCELLED)
        ->and($life['cancelled']['reason'])->toBe('A test cancellation.')
        ->and($life['cancelled']['when'])->toMatch('/^\d{1,2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} [AP]M$/');
});

it('never shows a committee motion further along as the year\'s election result', function () {
    $election = papersBallot(null, PAPERS_YEAR.' Election', Ballot::ELECTION, Ballot::NOMINATIONS_OPEN);
    papersBallot(null, 'Test motion', Ballot::RESOLUTION, Ballot::CERTIFIED);

    $results = $this->governance->resultsBoard(PAPERS_YEAR);

    expect($results['ballot']['id'])->toBe($election->id)
        ->and(array_column($results['papers'], 'id'))->toBe([$election->id]);
});
