<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Passes\OfflinePassVerifier;
use App\Services\Passes\PassSigningKeys;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| "Pass not yet valid", re-tested — M12 §3.1
|--------------------------------------------------------------------------
|
| THE ORDER, verbatim: "Issue a pass for the next thirty minutes in estate time and scan it. If it still refuses,
| it is a genuine time-zone bug in the validity comparison. Do not close this on explanation alone — the client saw
| a pass he believed was valid being refused."
|
| So this file does exactly that, end to end over HTTP, at moments across the estate's day — including the evening,
| when the UTC date has already moved on, and the last half hour before midnight, when the window crosses into
| tomorrow on the estate's own clock:
|
|   1  the Resident App's request, in the exact shape the phone sends it: the estate's wall clock WITH its offset
|      ("2026-09-26T10:12:00-05:00"), built by atSiteZone on the phone;
|   2  the guard's scan, online: POST /gate/verify with the token the pass carries in its QR;
|   3  the same token, verified the way a handset with no signal does (OfflinePassVerifier, public key only);
|   4  the entry that follows the Admit tap.
|
| Every one must say admit. The same pass, scanned before its window, still says not yet valid — the rule works.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    // A lot of this file's OWN: the estate database is committed and shared.
    $this->unit = FacilitiesFixture::scratchUnit('Lot 9461');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Half-hour household', 'access_restricted' => false]);
    $this->household->forceFill(['access_restricted' => false])->save();
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Andrea Halfhour'],
        ['relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED],
    );

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Half-hour Gate', 'type' => 'gate', 'is_active' => true]);
    $this->guard = Guard::create([
        'full_name' => 'Odane Halfhour', 'employee_number' => 'GS-HH-1', 'psra_number' => 'PSRA-HH-1',
        'psra_expires_on' => '2030-01-01', 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $this->post->id,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
});

/** A resident's token and a guard handset's, signed in at the test's "now" (a day idle closes a session). */
function halfHourTokens(object $test): array
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email',
        'destination' => 'halfhour.'.bin2hex(random_bytes(4)).'@example.com', 'full_name' => 'Andrea Halfhour',
        'resident_id' => $test->resident->id, 'unit_id' => $test->unit->id, 'status' => ResidentAccount::ACTIVE,
        'verified_at' => now(), 'device_uid' => 'halfhour-'.bin2hex(random_bytes(4)), 'device_platform' => 'android',
    ]);

    return [
        'resident' => $account->createToken('resident-app:halfhour', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken,
        'guard' => app(DeviceEnrolment::class)->enrol($test->guard, 'Half-hour handset')['token'],
    ];
}

function halfHourCall(TestCase $test, string $token, string $uri, array $body): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->postJson($uri, $body, [
        'Idempotency-Key' => 'hh-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

/** The phone's atSiteZone: the estate's wall clock, to the minute, with the estate's offset — "2026-09-26T10:12:00-05:00". */
function onTheEstateClock(Carbon $at): string
{
    return $at->copy()->setTimezone('America/Jamaica')->startOfMinute()->format('Y-m-d\TH:i:sP');
}

dataset('moments on the estate clock', [
    'early morning' => ['2026-09-26 06:10'],
    'mid-morning' => ['2026-09-26 10:12'],
    'just before two' => ['2026-09-26 13:59'],
    'evening — already tomorrow in UTC' => ['2026-09-26 19:05'],
    'the window crosses midnight' => ['2026-09-26 23:40'],
]);

it('admits a pass for the next thirty minutes, scanned now, at any time of the estate\'s day', function (string $wall) {
    Carbon::setTestNow(Carbon::parse($wall, 'America/Jamaica')->addSeconds(20)->utc());
    $tokens = halfHourTokens($this);

    // 1 — the resident issues it from the phone, for the next half hour on the estate's clock.
    $issued = halfHourCall($this, $tokens['resident'], '/api/v1/visitor-passes', [
        'category' => 'single', 'visitor_name' => 'Kemar Halfhour', 'purpose' => 'Family visit', 'visitor_phone' => '876 555 0123', 'no_vehicle' => true,
        'valid_from' => onTheEstateClock(now()),
        'valid_to' => onTheEstateClock(now()->addMinutes(30)),
    ]);
    expect($issued->status())->toBe(201, (string) $issued->getContent());

    $pass = VisitorPass::query()->where('pass_id', $issued->json('pass_id'))->firstOrFail();
    expect($pass->valid_from->getTimestamp())->toBe(now()->startOfMinute()->getTimestamp())
        ->and($pass->valid_to->getTimestamp())->toBe(now()->startOfMinute()->addMinutes(30)->getTimestamp());

    // 2 — the guard scans its QR, with signal.
    $verdict = halfHourCall($this, $tokens['guard'], '/api/v1/gate/verify', ['pass' => $pass->token]);
    expect($verdict->json('verdict'))->toBe('valid', 'refused as '.$verdict->json('verdict').' at '.$wall.' on the estate clock')
        ->and($verdict->json('admit_allowed'))->toBeTrue();

    // 3 — and with none: the handset's own check, on the public key alone.
    $keys = app(PassSigningKeys::class)->publicKeys(FacilitiesFixture::ESTATE);
    $offline = (new OfflinePassVerifier)->verify($pass->token, [FacilitiesFixture::ESTATE => $keys], FacilitiesFixture::ESTATE, now());
    expect($offline['verdict'])->toBe(OfflinePassVerifier::VALID_OFFLINE);

    // 4 — the Admit tap.
    $entry = halfHourCall($this, $tokens['guard'], '/api/v1/gate/entry', ['category' => 'Visitor', 'pass_id' => $pass->pass_id]);
    expect($entry->status())->toBe(201)->and($entry->json('reconciliation'))->toBe('consumed');
})->with('moments on the estate clock');

it('refuses a pass whose time has already passed — a draft written last night and sent by itself this morning (D-191)', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-26 00:34:00', 'America/Jamaica')->utc());
    $tokens = halfHourTokens($this);

    // Written at 11:14 PM for "now until midnight", with no signal; the phone sent it at 00:34 with the draft's key.
    $late = halfHourCall($this, $tokens['resident'], '/api/v1/visitor-passes', [
        'category' => 'single', 'visitor_name' => 'Airplane Latepass', 'purpose' => 'Family visit', 'visitor_phone' => '876 555 0123', 'no_vehicle' => true,
        'valid_from' => '2026-09-25T23:14:00-05:00', 'valid_to' => '2026-09-25T23:59:00-05:00',
    ]);
    expect($late->status())->toBe(422)
        ->and($late->json('error.code'))->toBe('pass_refused')
        ->and($late->json('error.message'))->toBe('The time chosen for this visit has already passed, so the pass would let nobody in. Invite them again for a time that is still ahead.')
        ->and(VisitorPass::query()->where('unit_id', $this->unit->id)->where('visitor_name', 'Airplane Latepass')->exists())->toBeFalse();

    // A window that has STARTED but not ended is still a pass: it admits from now until its end.
    $running = halfHourCall($this, $tokens['resident'], '/api/v1/visitor-passes', [
        'category' => 'single', 'visitor_name' => 'Airplane Stillon', 'purpose' => 'Family visit', 'visitor_phone' => '876 555 0123', 'no_vehicle' => true,
        'valid_from' => '2026-09-26T00:10:00-05:00', 'valid_to' => '2026-09-26T01:00:00-05:00',
    ]);
    expect($running->status())->toBe(201, (string) $running->getContent());
});

it('still says not yet valid for the same half hour scanned before it starts — the rule is the window, not the zone', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-26 10:12:20', 'America/Jamaica')->utc());
    $tokens = halfHourTokens($this);

    // Issued at 10:12 for 10:30 – 11:00, the next half-hour slot: scanned at 10:12 it is early, by eighteen minutes.
    $issued = halfHourCall($this, $tokens['resident'], '/api/v1/visitor-passes', [
        'category' => 'single', 'visitor_name' => 'Early Halfhour', 'purpose' => 'Family visit', 'visitor_phone' => '876 555 0123', 'no_vehicle' => true,
        'valid_from' => '2026-09-26T10:30:00-05:00', 'valid_to' => '2026-09-26T11:00:00-05:00',
    ]);
    $pass = VisitorPass::query()->where('pass_id', $issued->json('pass_id'))->firstOrFail();

    $early = halfHourCall($this, $tokens['guard'], '/api/v1/gate/verify', ['pass' => $pass->token]);
    expect($early->json('verdict'))->toBe('not_yet_valid');

    // At 10:29 — inside the two minutes a clock is allowed — it admits.
    Carbon::setTestNow(Carbon::parse('2026-09-26 10:29:00', 'America/Jamaica')->utc());
    $tokens = halfHourTokens($this);
    expect(halfHourCall($this, $tokens['guard'], '/api/v1/gate/verify', ['pass' => $pass->token])->json('verdict'))->toBe('valid');
});
