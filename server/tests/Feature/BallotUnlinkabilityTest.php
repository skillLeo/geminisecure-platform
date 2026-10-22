<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Services\Estate\Governance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The secret ballot, on the API path — 13 D3
|--------------------------------------------------------------------------
|
| "elections/{id}/ballot writes ballots and receipts with no join key, unique
| (election_id, household_id); extend the unlinkability gate to the API path."
|
| One household is one unit here (`Unit::household` is has-one), so the unique
| key is the receipt's (ballot_id, unit_id). What this file holds the API to:
| nothing the vote writes or answers can pair a household with its choice —
| not the tables, not the response, and not the idempotency record the API keeps
| beside the account that sent the paper.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** A linked Resident App account on a lot, and its token. */
function voterOn(string $reference, string $name): string
{
    $unit = FacilitiesFixture::unit($reference);
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => $name.' household', 'access_restricted' => false]);
    // A shared fixture lot: the voter takes the household over for this run (one primary, DESIGN §13).
    $resident = Tests\Support\HouseholdHolder::of($household, $name);

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => strtolower(str_replace(' ', '.', $name)).'.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => $name, 'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE,
    ]);

    return $account->createToken('resident-app:ballot', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
}

it('casts through the API leaving no column, response or idempotency record that pairs a household with its marks', function () {
    // Two lots whose households are eligible: no arrears reach back past the estate's threshold.
    $lots = collect(['Lot 3', 'Lot 9', 'Lot 63', 'Lot 88', 'Lot 47'])
        ->filter(fn (string $r) => app(Governance::class)->eligibility(FacilitiesFixture::unit($r))['eligible'])
        ->values();

    expect($lots->count())->toBeGreaterThanOrEqual(2);

    $ballot = new Ballot;
    $ballot->forceFill([
        'year' => (int) now()->year, 'title' => 'Secrecy '.bin2hex(random_bytes(3)), 'kind' => Ballot::ELECTION, 'stage' => Ballot::VOTING_OPEN,
        'opens_at' => now()->subHour(), 'closes_at' => now()->addDay(), 'eligible_households' => 5,
    ])->save();

    $position = new BallotPosition;
    $position->forceFill(['ballot_id' => $ballot->id, 'name' => 'Estate Executive', 'seat_count' => 2, 'scope' => 'estate'])->save();

    $options = collect(['Andre Clarke', 'Simone Grant', 'Winston Hall'])->map(function (string $label) use ($ballot, $position): BallotOption {
        $option = new BallotOption;
        $option->forceFill(['ballot_id' => $ballot->id, 'ballot_position_id' => $position->id, 'label' => $label])->save();

        return $option;
    });

    $voters = [
        [voterOn($lots[0], 'Voter One'), [$options[0]->id, $options[1]->id], 'secrecy-key-a'],
        [voterOn($lots[1], 'Voter Two'), [$options[2]->id], 'secrecy-key-b'],
    ];

    $uri = '/api/v1/elections/'.$ballot->id.'/ballot';
    $responses = [];

    foreach ($voters as [$token, $choice, $key]) {
        app('auth')->forgetGuards();
        $responses[] = $this->withToken($token)
            ->postJson($uri, ['option_ids' => $choice], ['Idempotency-Key' => $key, 'X-Device-Time' => now()->toIso8601String()])
            ->assertCreated();
        FacilitiesFixture::boot();
    }

    $optionIds = $options->pluck('id')->all();
    $markIds = DB::connection('tenant')->table('ballot_marks')->whereIn('ballot_option_id', $optionIds)->pluck('mark_id')->all();

    expect($markIds)->toHaveCount(3);

    /* 1. THE ANSWER. The ballot and the day; no option, mark or receipt. */
    foreach ($responses as $response) {
        expect(array_keys($response->json()))->toBe(['ballot_id', 'voted', 'voted_on', 'server_time', 'device_time', 'clock_skewed']);

        foreach ($markIds as $markId) {
            expect($response->getContent())->not->toContain($markId);
        }

        expect($response->json('voted_on'))->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    }

    /* 2. THE IDEMPOTENCY RECORD. Its hash covers method and path, so two different papers hash alike; its stored body is the answer above. */
    $records = DB::connection('mysql')->table('api_idempotency_keys')->whereIn('idempotency_key', ['secrecy-key-a', 'secrecy-key-b'])->get();

    expect($records)->toHaveCount(2)
        ->and($records[0]->request_hash)->toBe($records[1]->request_hash)
        ->and($records[0]->request_hash)->toBe(hash('sha256', 'POST api/v1/elections/'.$ballot->id.'/ballot'));

    foreach ($records as $record) {
        /*
         * AND THE ANSWER IS IN THE ESTATE, NOT IN gs_platform. The central row
         * carries the key, the hash and the status code and nothing else; the body
         * is one row in this estate's own database, which is where every other
         * trace of this vote already lives.
         */
        expect($record->tenant_id)->toBe(FacilitiesFixture::ESTATE)
            ->and($record->body_store)->toBe('tenant')
            ->and($record->response_body)->toBeNull();

        $body = json_decode(
            (string) DB::connection('tenant')->table('api_idempotency_responses')->where('key_id', $record->id)->value('response_body'),
            true,
        );

        // Exactly the answer's keys — the only integer among them is the ballot's own id.
        expect(array_keys($body))->toBe(['ballot_id', 'voted', 'voted_on', 'server_time', 'device_time', 'clock_skewed'])
            ->and($body['ballot_id'])->toBe($ballot->id);

        foreach ($markIds as $markId) {
            expect(json_encode($body))->not->toContain($markId);
        }
    }

    /* 3. THE TABLES. Marks carry two columns; receipts carry no clock; the two share nothing to join on. */
    $marks = Schema::connection('tenant')->getColumnListing('ballot_marks');
    $receipts = Schema::connection('tenant')->getColumnListing('ballot_receipts');

    expect($marks)->toEqualCanonicalizing(['mark_id', 'ballot_option_id'])
        ->and(array_intersect($marks, $receipts))->toBe([])
        ->and($receipts)->not->toContain('created_at')
        ->and($receipts)->not->toContain('updated_at');

    foreach (Schema::connection('tenant')->getColumns('ballot_receipts') as $column) {
        expect(in_array($column['type_name'], ['timestamp', 'datetime', 'time'], true))->toBeFalse("ballot_receipts.{$column['name']} is a clock");
    }

    foreach ($markIds as $markId) {
        expect($markId)->toMatch('/^[0-9a-f]{32}$/');
    }

    /* 4. A SECOND PAPER. Refused on a new key; on the same key with another body, the first answer is replayed and nothing is written. */
    app('auth')->forgetGuards();
    $this->withToken($voters[0][0])->postJson($uri, ['option_ids' => [$options[2]->id]], ['Idempotency-Key' => 'secrecy-key-a2', 'X-Device-Time' => now()->toIso8601String()])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_voted');
    FacilitiesFixture::boot();

    app('auth')->forgetGuards();
    $this->withToken($voters[0][0])->postJson($uri, ['option_ids' => [$options[2]->id]], ['Idempotency-Key' => 'secrecy-key-a', 'X-Device-Time' => now()->toIso8601String()])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    FacilitiesFixture::boot();

    expect(DB::connection('tenant')->table('ballot_marks')->whereIn('ballot_option_id', $optionIds)->count())->toBe(3)
        ->and(DB::connection('tenant')->table('ballot_receipts')->where('ballot_id', $ballot->id)->count())->toBe(2);
});
