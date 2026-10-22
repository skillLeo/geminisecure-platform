<?php

declare(strict_types=1);

use App\Models\Estate\Ballot;
use App\Services\Estate\Reports;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Election Turnout: held elections only, over the period chosen (REP-2, REP-7; M85 Phase B)
|--------------------------------------------------------------------------
|
| M81 §5: "unheld years appear with zero ballots ... Only held elections appear", and the report's From and To changed
| nothing (REP-7: "Works, validate it"). Its own docblock always said "each election whose poll closed in the period";
| it read every year on record. Now an election appears when it was held (its poll opened, and it was not cancelled)
| and its poll ran in the period chosen, and the report states that period as every other report does.
|
| Two elections of this file's own, in years nobody else uses, inside a transaction that is rolled back.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    DB::connection('tenant')->beginTransaction();

    $election = static fn (int $year, string $stage, ?string $opens, ?string $closes): Ballot => Ballot::query()->create([
        'year' => $year, 'code' => 'A', 'title' => 'M85 turnout '.$year, 'kind' => Ballot::ELECTION, 'stage' => $stage,
        'opens_at' => $opens, 'closes_at' => $closes, 'eligible_households' => 10, 'quorum_percent' => 25,
    ]);

    // Held: its poll ran in May 2011. Never held: still a draft in 2012.
    $election(2011, Ballot::VOTING_CLOSED, '2011-05-01 11:00:00', '2011-05-03 01:00:00');
    $election(2012, Ballot::DRAFT, null, null);
});

afterEach(function (): void {
    DB::connection('tenant')->rollBack();
});

/** The years the report lists over a period. @return list<string> */
function m85TurnoutYears(string $from, string $to): array
{
    return array_values(array_unique(array_column(app(Reports::class)->run('election_turnout', $from, $to)['rows'], 0)));
}

it('lists an election only once it has been held, never a year whose ballot never opened', function (): void {
    expect(m85TurnoutYears('2010-01-01', '2013-12-31'))->toBe(['2011']);
});

it('lists the elections whose poll ran in the period chosen, and states that period', function (): void {
    expect(m85TurnoutYears('2011-01-01', '2011-12-31'))->toBe(['2011'])
        ->and(m85TurnoutYears('2011-06-01', '2011-12-31'))->toBe([])
        ->and(app(Reports::class)->run('election_turnout', '2011-01-01', '2011-12-31')['period'])->toBe('January 1, 2011 to December 31, 2011');
});
