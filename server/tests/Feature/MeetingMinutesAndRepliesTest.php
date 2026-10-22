<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingMinutes;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A meeting's replies reach the office; its minutes reach the households (M21 §1.4)
|--------------------------------------------------------------------------
|
| Two halves of the meeting loop that did not exist. A household's reply in the Resident App was stored and no console
| screen read it — "it tells the secretary who to expect" told nobody. And minutes were written by a seeder only: no
| screen recorded them, and the app said "ask your estate office for a copy". Now the office writes the minutes once the
| meeting has been held, publishes them, and the household reads them in the app; until they are published nobody
| outside the office is told they exist.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: what this file makes is its own and is taken away afterwards.
|
*/

const MINUTES_TEST_TITLE = 'Minutes Test Phase Meeting';

/*
 * (The election's own acts — opened, closed with who voted, certified, published — are held to the audit log by
 * ElectionIsAuditedTest.)
 */

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 983');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Minutes household', 'access_restricted' => false]);
    $this->person = new Resident;
    $this->person->forceFill(['household_id' => $this->household->id, 'full_name' => 'Pat Minutes', 'email' => 'pat.minutes.'.bin2hex(random_bytes(3)).'@example.com', 'phone' => '+1 876 555 0983', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $this->person->email, 'full_name' => 'Pat Minutes',
        'resident_id' => $this->person->id, 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
        'device_uid' => 'minutes-install-1', 'device_platform' => 'android',
    ]);
    $this->token = $account->createToken('resident-app:minutes-install-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $this->president = FacilitiesFixture::viewer(Role::PRESIDENT);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    $ids = Meeting::query()->where('title', MINUTES_TEST_TITLE)->pluck('id');
    MeetingMinutes::query()->whereIn('meeting_id', $ids)->delete();
    DB::connection('tenant')->table('meeting_rsvps')->whereIn('meeting_id', $ids)->delete();
    Meeting::query()->whereIn('id', $ids)->delete();
    Resident::withRemoved()->where('household_id', $this->household->id)->delete();
});

function minutesMeeting(string $startsAt): Meeting
{
    FacilitiesFixture::boot();

    return Meeting::query()->forceCreate([
        'type' => Meeting::EGM, 'title' => MINUTES_TEST_TITLE, 'starts_at' => $startsAt, 'held_as' => Meeting::ZOOM,
        'virtual_link' => 'https://zoom.us/j/1234567890', 'audience_scope' => Meeting::WHOLE_ESTATE,
        'quorum_percent' => 20, 'quorum_basis' => Meeting::HOUSEHOLDS, 'eligible_households' => 10,
        'status' => Meeting::SCHEDULED, 'published_at' => now()->subDays(30),
    ]);
}

/** The Resident App's call, held to its catalogue entry. */
function asHousehold(TestCase $test, string $token, string $name, array $params = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'min-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $response = $test->withToken($token)->json($entry['method'], $uri, $body, $headers);
    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

it('puts a household\'s reply in front of the office: who to expect, from which lot, said by whom and when', function () {
    $meeting = minutesMeeting(now()->addDays(10)->toDateTimeString());

    // Published, with its online link, in the household's list.
    $listed = collect(asHousehold($this, $this->token, 'meetings.index')->json('items'))->firstWhere('id', $meeting->id);
    expect($listed['virtual_link'])->toBe('https://zoom.us/j/1234567890')->and($listed['minutes_available'])->toBeFalse();

    asHousehold($this, $this->token, 'meetings.rsvp', ['meeting' => $meeting->id], ['response' => 'attending']);

    $this->actingAs($this->secretary)->get(FacilitiesFixture::url('/governance/meetings/'.$meeting->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('meeting.rsvps.attending', 1)
            // When, in Law 5's form (M62 A5, DR-039): "12 Oct, 6:05 PM", on the estate's clock and with no tag.
            ->where('meeting.rsvps.rows', fn ($rows) => collect($rows)->contains(fn (array $r): bool => $r['lot'] === 'Lot 983' && $r['response'] === 'Attending' && $r['by'] === 'Pat Minutes' && preg_match('/^\d{1,2} [A-Z][a-z]{2}, \d{1,2}:\d{2} [AP]M$/', (string) $r['when']) === 1)));
});

it('lets the office write the minutes once the meeting is held, and the household read them only once they are published', function () {
    $upcoming = minutesMeeting(now()->addDays(3)->toDateTimeString());
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/meetings/'.$upcoming->id.'/minutes/record'), ['body' => 'Too soon: this meeting has not happened yet, so nothing can be minuted.'])
        ->assertSessionHasErrors('minutes');

    $held = minutesMeeting(now()->subDays(2)->toDateTimeString());
    $body = "Present: 14 households. Apologies: 3.\n1. The gate barrier quotation was approved.\n2. The pool hours stay as they are.";
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/meetings/'.$held->id.'/minutes/record'), ['body' => $body])
        ->assertRedirect()->assertSessionHasNoErrors();

    // Saved and not published: the household is told nothing, and cannot read them.
    expect(collect(asHousehold($this, $this->token, 'meetings.index')->json('items'))->firstWhere('id', $held->id)['minutes_available'])->toBeFalse();
    asHousehold($this, $this->token, 'meetings.minutes', ['meeting' => $held->id], [], 404);

    // Publishing is the publisher's act, not the secretary's.
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/meetings/'.$held->id.'/minutes/publish'))->assertForbidden();
    $this->actingAs($this->president)->post(FacilitiesFixture::url('/governance/meetings/'.$held->id.'/minutes/publish'))->assertRedirect()->assertSessionHasNoErrors();

    $listed = collect(asHousehold($this, $this->token, 'meetings.index')->json('items'))->firstWhere('id', $held->id);
    // Held two days ago: the app is told it was held, not offered its link ("scheduled" for ever, before M21).
    expect($listed['minutes_available'])->toBeTrue()->and($listed['status'])->toBe('held');
    $read = asHousehold($this, $this->token, 'meetings.minutes', ['meeting' => $held->id]);
    expect($read->json('body'))->toBe($body)
        ->and($read->json('title'))->toBe(MINUTES_TEST_TITLE)
        ->and($read->json('recorded_by'))->not->toBeNull();

    // Published minutes are the record: they are not rewritten.
    $this->actingAs($this->secretary)->post(FacilitiesFixture::url('/governance/meetings/'.$held->id.'/minutes/record'), ['body' => 'A rewrite of history after the fact, which is refused.'])
        ->assertSessionHasErrors('minutes');
    FacilitiesFixture::boot();
    expect(MeetingMinutes::query()->where('meeting_id', $held->id)->value('body'))->toBe($body);
});
