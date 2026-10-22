<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Estate\Governance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HouseholdHolder;

/*
|--------------------------------------------------------------------------
| The election rules that cannot bend (Hassam's test campaign, 12 October 2026)
|--------------------------------------------------------------------------
|
| "With voting OPEN, prove no candidate count leaves the server. Look at the actual API response, not the page. Prove one
| vote per household against concurrency: two members of one household submitting at the same moment must produce one
| vote, refused for the second, with a message that says why. Prove a submitted ballot cannot be changed. Prove ballots
| and ballot receipts cannot be joined."
|
| Each case reads what the server actually sends: the Resident App's JSON and the estate console's page props, whole,
| searched at every depth. The ballot is this file's own (its year and its scratch lot, on a roll of that one household,
| so no other file's household is shown it), cast for through the API as the phone casts, and closed at the end.
| A vote can never be deleted (the triggers below), so each run leaves one closed ballot of this year behind.
|
*/

const UNBENDING_YEAR = 2083;
const UNBENDING_LOT = 'UNB-1';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        FacilitiesFixture::boot();

        // Nothing of this file's stays open: a ballot with papers in is closed (it can never be deleted), one without goes.
        foreach (Ballot::query()->whereIn('year', unbendingYears())->get() as $ballot) {
            if ($ballot->receipts()->exists()) {
                if ($ballot->stage === Ballot::VOTING_OPEN) {
                    $ballot->forceFill(['stage' => Ballot::TALLY, 'closes_at' => now()])->save();
                }
            } else {
                $ballot->options()->delete();
                $ballot->positions()->delete();
                $ballot->delete();
            }
        }
    }
});

/**
 * The years this file's ballots are in. The count case takes one of its own each run: the results page shows a year's
 * furthest ballot, and the other cases leave closed ballots in theirs, whose counts may rightly show.
 *
 * @return list<int>
 */
function unbendingYears(?int $add = null): array
{
    static $years = [UNBENDING_YEAR];

    if ($add !== null) {
        $years[] = $add;
    }

    return $years;
}

/**
 * This file's household, its two Members each with a Resident App account, and an open ballot put to that household
 * alone: one seat, two candidates.
 *
 * @return array{ballot: Ballot, unit: Unit, first: string, second: string, options: list<BallotOption>}
 */
function unbendingElection(int $year = UNBENDING_YEAR): array
{
    $unit = FacilitiesFixture::scratchUnit(UNBENDING_LOT);
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Unbending household', 'access_restricted' => false]);
    $primary = HouseholdHolder::of($household, 'Ura Unbending');
    $spouse = Resident::query()->firstOrCreate(
        ['household_id' => $household->id, 'full_name' => 'Ulric Unbending'],
        ['relationship' => 'spouse', 'is_primary' => false],
    );

    $token = static function (Resident $resident) use ($unit): string {
        $account = ResidentAccount::query()->create([
            'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email',
            'destination' => 'unbending.'.$resident->id.'.'.bin2hex(random_bytes(3)).'@example.com',
            'full_name' => $resident->full_name, 'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE,
        ]);

        return $account->createToken('resident-app:unbending', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    };

    $ballot = new Ballot;
    $ballot->forceFill([
        'year' => $year, 'title' => 'Unbending '.bin2hex(random_bytes(3)), 'kind' => Ballot::ELECTION, 'stage' => Ballot::VOTING_OPEN,
        'opens_at' => now()->subHour(), 'closes_at' => now()->addDay(), 'eligible_households' => 1, 'roll' => [$household->id],
    ])->save();

    $position = new BallotPosition;
    $position->forceFill(['ballot_id' => $ballot->id, 'name' => 'Chairman', 'seat_count' => 1, 'scope' => 'estate'])->save();

    $options = [];

    foreach (['Andrea Able', 'Bertram Baker'] as $index => $label) {
        $option = new BallotOption;
        $option->forceFill(['ballot_id' => $ballot->id, 'ballot_position_id' => $position->id, 'label' => $label, 'sort_order' => $index + 1])->save();
        $options[] = $option;
    }

    return ['ballot' => $ballot, 'unit' => $unit, 'first' => $token($primary), 'second' => $token($spouse), 'options' => $options];
}

/** One paper, posted as the Resident App posts it. */
function unbendingCast(object $test, string $token, Ballot $ballot, array $optionIds, string $key): TestResponse
{
    app('auth')->forgetGuards();
    $response = $test->withToken($token)->postJson('/api/v1/elections/'.$ballot->id.'/ballot', ['option_ids' => $optionIds], [
        'Idempotency-Key' => $key, 'X-Device-Time' => now()->toIso8601String(),
    ]);
    FacilitiesFixture::boot();

    return $response;
}

/** A Resident App read, as the phone makes it. */
function unbendingRead(object $test, string $token, string $uri): TestResponse
{
    app('auth')->forgetGuards();
    $response = $test->withToken($token)->getJson($uri);
    FacilitiesFixture::boot();

    return $response;
}

/**
 * Every key at every depth, so "no count" means none anywhere in what was sent.
 *
 * @param  array<mixed>  $value
 * @return list<string>
 */
function unbendingKeys(array $value): array
{
    $keys = [];

    foreach ($value as $key => $child) {
        $keys[] = (string) $key;

        if (is_array($child)) {
            $keys = [...$keys, ...unbendingKeys($child)];
        }
    }

    return $keys;
}

/** The keys a candidate's count travels under, on any screen or in any answer this server makes. */
function unbendingCountKeys(array $keys): array
{
    return array_values(array_unique(array_filter($keys, static fn (string $key): bool => in_array($key, ['votes', 'tally', 'marks', 'vote_count', 'votes_cast'], true))));
}

/** The tally straight from the marks, for the proofs below; the screens never see this while the poll is open. */
function unbendingTally(Ballot $ballot): array
{
    FacilitiesFixture::boot();

    return app(Governance::class)->tally($ballot->fresh());
}

it('sends no candidate count in any answer or page while voting is open, and the full count once it has closed', function () {
    // A year nobody else's ballot is in, so the year's pages are this ballot's (see unbendingYears()).
    $year = unbendingYears(random_int(3000, 8999))[array_key_last(unbendingYears())];
    ['ballot' => $ballot, 'first' => $first, 'second' => $second, 'options' => $options] = unbendingElection($year);

    // A paper is in, so there IS a count to leak: Andrea Able 1, Bertram Baker 0.
    unbendingCast($this, $first, $ballot, [$options[0]->id], 'unbending-count-1')->assertCreated();
    expect(unbendingTally($ballot))->toBe([$options[0]->id => 1, $options[1]->id => 0]);

    $sent = [];

    /* THE RESIDENT APP'S ANSWERS: the list, the household's standing, and the results, which are refused. */
    $sent['GET elections'] = unbendingRead($this, $second, '/api/v1/elections')->assertOk()->json();
    $sent['GET eligibility'] = unbendingRead($this, $second, '/api/v1/elections/'.$ballot->id.'/eligibility')->assertOk()->json();
    $sent['GET results'] = unbendingRead($this, $second, '/api/v1/elections/'.$ballot->id.'/results')
        ->assertStatus(409)->assertJsonPath('error.code', 'results_not_published')->json();

    expect(collect($sent['GET elections']['items'])->firstWhere('id', $ballot->id))->not->toBeNull();

    /* THE ESTATE CONSOLE'S PAGES, as the props travel in the page, read whole whether or not a screen draws them. */
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    foreach ([
        'control room' => '/governance/elections/'.$year,
        'results' => '/governance/elections/'.$year.'/results',
        'nominations' => '/governance/elections/'.$year.'/nominations',
        'dashboard' => '/',
    ] as $name => $path) {
        $response = $this->actingAs($president)->get(FacilitiesFixture::url($path))->assertOk();
        $sent[$name] = $response->viewData('page')['props'];
        FacilitiesFixture::boot();
    }

    // The certificate is refused for a count that is still open, so no document carries one either.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/governance/elections/'.$year.'/certificate'))
        ->assertRedirect();
    FacilitiesFixture::boot();

    foreach ($sent as $where => $payload) {
        expect(unbendingCountKeys(unbendingKeys($payload)))->toBe([], "{$where} carries a count while voting is open");
    }

    // The turnout may travel (one household of one has voted); nothing per candidate does.
    expect($sent['results']['cards'])->toBe([])
        ->and($sent['results']['count_closed'])->toBeFalse()
        ->and($sent['results']['turnout']['cast'])->toBe(1);

    /* ONCE THE POLL HAS CLOSED the same page carries every count: the search above can see one when it is there. */
    app(Governance::class)->close($ballot->fresh());
    FacilitiesFixture::boot();

    $closed = $this->actingAs($president)->get(FacilitiesFixture::url('/governance/elections/'.$year.'/results'))->assertOk()
        ->viewData('page')['props'];

    expect(unbendingCountKeys(unbendingKeys($closed)))->toBe(['votes'])
        ->and(collect($closed['cards'][0]['rows'])->pluck('votes', 'name')->all())->toBe(['Andrea Able' => 1, 'Bertram Baker' => 0]);
});

it('takes one paper from a household when two of its members vote at the same moment, and tells the second why', function () {
    ['ballot' => $ballot, 'unit' => $unit, 'first' => $first, 'second' => $second, 'options' => $options] = unbendingElection();

    /*
     * THE RACE, PLAYED THE SAME WAY EVERY RUN. The second member's request passes both "has this household voted?" checks
     * (the controller's, then castVote's own); at exactly that moment the first member's paper goes in and commits, as
     * it would from another phone. Then the second request writes. Only the unique key on (ballot, household) can refuse
     * it now, and it must come back as a sentence, not a stack trace.
     */
    $checks = 0;
    $raced = false;

    DB::connection('tenant')->listen(function ($query) use (&$checks, &$raced, $ballot, $unit, $options): void {
        if ($raced || ! str_contains($query->sql, 'ballot_receipts') || ! str_contains($query->sql, 'exists')) {
            return;
        }

        if (++$checks < 2) {
            return;
        }

        $raced = true;
        app(Governance::class)->castVote(Ballot::query()->findOrFail($ballot->id), Unit::query()->findOrFail($unit->id), [$options[0]->id]);
    });

    $refused = unbendingCast($this, $second, $ballot, [$options[1]->id], 'unbending-race-2');

    expect($raced)->toBeTrue();

    $refused->assertStatus(409)
        ->assertJsonPath('error.code', 'already_voted')
        ->assertJsonPath('error.message', 'Your household has already voted in '.$ballot->title.'.');

    // One paper: the first member's. The second member's choice was written nowhere.
    expect(DB::connection('tenant')->table('ballot_receipts')->where('ballot_id', $ballot->id)->count())->toBe(1)
        ->and(unbendingTally($ballot))->toBe([$options[0]->id => 1, $options[1]->id => 0]);

    // And the first member, pressing again from their own phone, is told the same, in full.
    unbendingCast($this, $first, $ballot, [$options[1]->id], 'unbending-race-3')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_voted')
        ->assertJsonPath('error.message', 'Your household has already voted in '.$ballot->title.'. A vote cast cannot be shown, changed or taken back.');
});

it('never changes a paper once it is in: not by the API, not by a replay, not by the database itself', function () {
    ['ballot' => $ballot, 'first' => $first, 'second' => $second, 'options' => $options] = unbendingElection();
    [$able, $baker] = [$options[0]->id, $options[1]->id];

    unbendingCast($this, $first, $ballot, [$able], 'unbending-change-1')->assertCreated();

    // Another paper, from either member, on a new key: refused, and the count does not move.
    unbendingCast($this, $second, $ballot, [$baker], 'unbending-change-2')->assertStatus(409)->assertJsonPath('error.code', 'already_voted');
    unbendingCast($this, $first, $ballot, [$baker], 'unbending-change-3')->assertStatus(409)->assertJsonPath('error.code', 'already_voted');

    // The first key again with another choice: the first answer is replayed, and nothing is written.
    unbendingCast($this, $first, $ballot, [$baker], 'unbending-change-1')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(unbendingTally($ballot))->toBe([$able => 1, $baker => 0]);

    // THE DATABASE REFUSES whoever asks: no mark is moved to another candidate, and no mark or receipt is removed.
    FacilitiesFixture::boot();
    $estate = DB::connection('tenant');

    $refusals = [
        'move a mark' => fn () => $estate->table('ballot_marks')->where('ballot_option_id', $able)->update(['ballot_option_id' => $baker]),
        'remove a mark' => fn () => $estate->table('ballot_marks')->where('ballot_option_id', $able)->delete(),
        'redate a receipt' => fn () => $estate->table('ballot_receipts')->where('ballot_id', $ballot->id)->update(['voted_on' => '2000-01-01']),
        'remove a receipt' => fn () => $estate->table('ballot_receipts')->where('ballot_id', $ballot->id)->delete(),
    ];

    foreach ($refusals as $what => $attempt) {
        $said = null;

        try {
            $attempt();
        } catch (QueryException $refused) {
            $said = $refused->getMessage();
        }

        expect($said)->not->toBeNull("the database let a test {$what}");
        expect(str_contains((string) $said, 'A cast vote is never'))->toBeTrue("{$what} was refused for another reason: {$said}");
    }

    expect(unbendingTally($ballot))->toBe([$able => 1, $baker => 0])
        ->and($estate->table('ballot_receipts')->where('ballot_id', $ballot->id)->count())->toBe(1);
});

it('keeps nothing that joins a household to its paper: no shared column, no clock, no audit entry and nothing in the answer', function () {
    ['ballot' => $ballot, 'first' => $first, 'options' => $options] = unbendingElection();

    $auditBefore = DB::connection('mysql')->table('audit_log')->max('id') ?? 0;

    $answer = unbendingCast($this, $first, $ballot, [$options[1]->id], 'unbending-join-1')->assertCreated();

    FacilitiesFixture::boot();
    $marks = DB::connection('tenant')->table('ballot_marks')->where('ballot_option_id', $options[1]->id)->get();
    $receipt = DB::connection('tenant')->table('ballot_receipts')->where('ballot_id', $ballot->id)->sole();

    expect($marks)->toHaveCount(1);

    /* THE TABLES: two columns on a mark, no column name in common, no clock on a receipt, a mark id that is 128 random bits. */
    $markColumns = array_keys((array) $marks[0]);
    $receiptColumns = array_keys((array) $receipt);

    expect($markColumns)->toEqualCanonicalizing(['mark_id', 'ballot_option_id'])
        ->and(array_values(array_intersect($markColumns, $receiptColumns)))->toBe([])
        ->and($receipt->voted_on)->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($marks[0]->mark_id)->toMatch('/^[0-9a-f]{32}$/');

    foreach (DB::connection('tenant')->getSchemaBuilder()->getColumns('ballot_receipts') as $column) {
        expect(in_array($column['type_name'], ['timestamp', 'datetime', 'time'], true))->toBeFalse("ballot_receipts.{$column['name']} is a clock");
    }

    /* THE ANSWER names the ballot and the day: no mark, no option, no receipt. */
    expect(array_keys($answer->json()))->toBe(['ballot_id', 'voted', 'voted_on', 'server_time', 'device_time', 'clock_skewed'])
        ->and(str_contains($answer->getContent(), $marks[0]->mark_id))->toBeFalse();

    /* THE AUDIT LOG: a vote is never its own entry (an entry carries the moment it was written). */
    expect(DB::connection('mysql')->table('audit_log')->where('id', '>', $auditBefore)->count())->toBe(0);
});
