<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Passes\OfflinePassVerifier;
use App\Services\Passes\PassSigningKeys;
use App\Services\Passes\VisitorPasses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M47 §5.4, §5.5 and §6 — whose guest it is, where they came in, and the e-pass half an hour at a time
|--------------------------------------------------------------------------
|
| Every visitor read "Guest of" whoever was signed in: the server never said who issued a pass. It now does (`host`).
| Recent activity could say only "Entered": the gate now records its own name and the guard's on the pass.
| And the resident's own e-pass was good for 24 hours, so a screenshot of it was too: what the phone shows is now good
| for thirty minutes, and the phone keeps a day of half hours (48) for a gate with no signal — each a token a guard
| verifies offline in its own half hour and no other. One pass row a day, one gate code.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 4749');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Half-hour household', 'access_restricted' => false]);
});

afterEach(function () {
    Carbon::setTestNow();
    FacilitiesFixture::boot();
    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
    Resident::withRemoved()->where('household_id', $this->household->id)->delete();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** @return array{0: ResidentAccount, 1: string, 2: Resident} */
function m47Member(Household $household, string $name, bool $primary): array
{
    FacilitiesFixture::boot();
    $email = 'half.'.bin2hex(random_bytes(3)).'@example.com';

    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => $name, 'email' => $email, 'phone' => '+1 876 555 0149', 'relationship' => $primary ? 'owner' : 'spouse', 'is_primary' => $primary, 'status' => Resident::VERIFIED])->save();

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'full_name' => $name,
        'resident_id' => $person->id, 'unit_id' => $household->unit_id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$account, $account->createToken('resident-app:half', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken, $person];
}

/** @param  array<string, mixed>  $query */
function m47Get(TestCase $test, string $token, string $name, array $query = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    app('auth')->forgetGuards();
    $response = $test->withToken($token)->getJson('/api/v1/'.$entry['uri'].($query === [] ? '' : '?'.http_build_query($query)));
    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** What a guard's phone says of this token at that moment, with the estate's keys and no network. */
function m47Gate(string $token, Carbon $at): string
{
    FacilitiesFixture::boot();
    $keys = app(PassSigningKeys::class)->publicKeys(FacilitiesFixture::ESTATE);

    return app(OfflinePassVerifier::class)->verify($token, [FacilitiesFixture::ESTATE => $keys], FacilitiesFixture::ESTATE, $at)['verdict'];
}

it('names the member who issued a pass, whoever reads the list', function () {
    [, , $andrea] = m47Member($this->household, 'Andrea Fletcher', primary: true);
    [, $hassam] = m47Member($this->household, 'Hassam Ahmed', primary: false);

    app(VisitorPasses::class)->issue(FacilitiesFixture::ESTATE, $this->unit, $andrea->id, 'Andrea Fletcher', [
        'category' => 'single', 'visitor_name' => 'Yahya Khan', 'visitor_phone' => '+1 876 555 0150', 'purpose' => 'Friend',
        'no_vehicle' => true, 'valid_from' => Carbon::now(), 'valid_to' => Carbon::now()->addHours(2),
    ]);

    $items = m47Get($this, $hassam, 'visitor_passes.index')->json('items');
    expect(collect($items)->firstWhere('visitor_name', 'Yahya Khan')['host'])->toBe('Andrea Fletcher');
});

it('says which gate let the visitor in, and which guard — "Entered · Main Gate · by Guard Whyte"', function () {
    [, $token, $andrea] = m47Member($this->household, 'Andrea Fletcher', primary: true);
    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Main Gate M47', 'also_called' => 'Gate 1', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'Delroy Whyte', 'employee_number' => 'GS-M47-1', 'psra_number' => 'PSRA-M47-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);
    $handset = app(DeviceEnrolment::class)->enrol($guard, 'M47 gate handset')['token'];

    $pass = app(VisitorPasses::class)->issue(FacilitiesFixture::ESTATE, $this->unit, $andrea->id, 'Andrea Fletcher', [
        'category' => 'recurring', 'visitor_name' => 'Yahya Khan', 'visitor_phone' => '+1 876 555 0150', 'purpose' => 'Friend',
        'no_vehicle' => true, 'valid_from' => Carbon::now()->subHour(), 'valid_to' => Carbon::now()->addHours(4),
    ]);

    // Not let in yet: nothing to say.
    $before = collect(m47Get($this, $token, 'visitor_passes.index')->json('items'))->firstWhere('visitor_name', 'Yahya Khan');
    expect($before['last_admitted_post'])->toBeNull()->and($before['last_admitted_by'])->toBeNull();

    app('auth')->forgetGuards();
    $this->withToken($handset)->postJson('/api/v1/gate/entry', ['category' => 'Visitor', 'pass_id' => $pass->pass_id], [
        'Idempotency-Key' => 'm47-entry-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String(),
    ])->assertCreated();
    FacilitiesFixture::boot();

    $after = collect(m47Get($this, $token, 'visitor_passes.index')->json('items'))->firstWhere('visitor_name', 'Yahya Khan');
    // The post's own name, as the board writes it — not the long label the guards know it by ("Main Gate M47 (Gate 1)").
    expect($after['last_admitted_at'])->not->toBeNull()
        ->and($after['last_admitted_post'])->toBe('Main Gate M47')
        ->and($after['last_admitted_by'])->toBe('Delroy Whyte');

    DB::connection('tenant')->table('live_events')->where('entity_id', $pass->pass_id)->delete();
});

it('shows the half hour this moment is in, and keeps a day of them', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:47:10', 'UTC'));
    [, $token] = m47Member($this->household, 'Hassam Ahmed', primary: true);

    $epass = m47Get($this, $token, 'epass.show');
    $slots = $epass->json('slots');

    expect($slots)->toHaveCount(VisitorPasses::RESIDENT_SLOTS)
        ->and($slots[0]['valid_from'])->toBe('2026-10-07T14:30:00+00:00')
        ->and($slots[0]['valid_to'])->toBe('2026-10-07T15:00:00+00:00')
        ->and($slots[47]['valid_to'])->toBe('2026-10-08T14:30:00+00:00')
        ->and($epass->json('valid_from'))->toBe($slots[0]['valid_from'])
        ->and($epass->json('valid_to'))->toBe($slots[0]['valid_to'])
        ->and($epass->json('token'))->toBe($slots[0]['token'])
        ->and($epass->json('refresh_after'))->toBe($slots[0]['valid_to']);

    foreach ($slots as $i => $slot) {
        if ($i > 0) {
            expect($slot['valid_from'])->toBe($slots[$i - 1]['valid_to']);
        }
        expect(Carbon::parse($slot['valid_from'])->diffInMinutes(Carbon::parse($slot['valid_to'])))->toEqual(30.0);
    }
});

it('opens the gate in its own half hour and no other — a screenshot is dead within thirty minutes', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:47:10', 'UTC'));
    [, $token] = m47Member($this->household, 'Hassam Ahmed', primary: true);
    $slots = m47Get($this, $token, 'epass.show')->json('slots');

    expect(m47Gate($slots[0]['token'], Carbon::parse('2026-10-07 14:50:00', 'UTC')))->toBe(OfflinePassVerifier::VALID_OFFLINE)
        ->and(m47Gate($slots[0]['token'], Carbon::parse('2026-10-07 16:00:00', 'UTC')))->toBe(OfflinePassVerifier::EXPIRED)
        ->and(m47Gate($slots[10]['token'], Carbon::parse('2026-10-07 14:50:00', 'UTC')))->toBe(OfflinePassVerifier::NOT_YET_VALID)
        ->and(m47Gate($slots[10]['token'], Carbon::parse('2026-10-07 19:40:00', 'UTC')))->toBe(OfflinePassVerifier::VALID_OFFLINE);

    // Online too: the half hour's token, and the day's gate code.
    FacilitiesFixture::boot();
    Carbon::setTestNow(Carbon::parse('2026-10-07 14:52:00', 'UTC'));
    $passes = app(VisitorPasses::class);
    $row = VisitorPass::query()->where('unit_id', $this->unit->id)->where('category', VisitorPasses::RESIDENT)->sole();
    expect($passes->verifyOnline(FacilitiesFixture::ESTATE, $slots[0]['token'], Carbon::now())['verdict'])->toBe('valid')
        ->and($passes->verifyOnline(FacilitiesFixture::ESTATE, $row->code, Carbon::now())['verdict'])->toBe('valid');
});

it('keeps one pass and one gate code a day, renewed once less than a day of it is left', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'UTC'));
    [$account, $token] = m47Member($this->household, 'Hassam Ahmed', primary: true);
    $first = m47Get($this, $token, 'epass.show');
    // A day later the phone signs in again: the thirty-minute window closed long ago.
    $again = fn (): string => $account->createToken('resident-app:half', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    Carbon::setTestNow(Carbon::parse('2026-10-08 07:00:00', 'UTC'));
    $token = $again();
    $later = m47Get($this, $token, 'epass.show');
    expect($later->json('id'))->toBe($first->json('id'))
        ->and($later->json('code'))->toBe($first->json('code'))
        ->and($later->json('slots'))->toHaveCount(VisitorPasses::RESIDENT_SLOTS);

    Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00', 'UTC'));
    $token = $again();
    $renewed = m47Get($this, $token, 'epass.show');
    expect($renewed->json('id'))->not->toBe($first->json('id'))
        ->and($renewed->json('slots'))->toHaveCount(VisitorPasses::RESIDENT_SLOTS);
});

it('never sends the pass row\'s own two-day token', function () {
    [, $token] = m47Member($this->household, 'Hassam Ahmed', primary: true);
    $epass = m47Get($this, $token, 'epass.show');

    $row = VisitorPass::query()->where('unit_id', $this->unit->id)->where('category', VisitorPasses::RESIDENT)->sole();
    expect($epass->getContent())->not->toContain((string) $row->token);
});
