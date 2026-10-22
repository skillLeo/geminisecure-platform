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
use App\Services\Passes\VisitorPasses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Two things the review found that no test could have caught — M6 §1
|--------------------------------------------------------------------------
|
| 1. `POST /api/v1/alerts` took `unit_reference` from the BODY, so a resident's
|    panic could name any lot on the estate. The address on an alert is the line
|    a dispatcher reads when deciding where to send people, and it is the estate's
|    permanent record of whose home it happened at. The contract test could not
|    see it: the field is documented, the response does not carry it, and the
|    only endpoint that ever got it right — `duress.store` — is a different file.
|
| 2. `POST /api/v1/gate/entry` with `verified_offline: true` admits on a pass the
|    server would have refused online — cancelled, expired, already used, or a
|    household in arrears restriction. It answered `reconciliation` to the PHONE
|    and stored nothing: `gate_events` has no column for it and `GateLog` takes no
|    argument for it, so the row was indistinguishable from a valid admission.
|    `GuardApiContractTest` asserts the RESPONSE says `pass_already_used` and
|    passes — which is exactly why the hole survived.
|
| Both assertions below read the STORED ROW, never the answer.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Review Gate', 'type' => 'gate', 'is_active' => true]);

    $this->guard = Guard::create([
        'full_name' => 'Nadine Review',
        'employee_number' => 'GS-REV-1',
        'psra_number' => 'PSRA-REV-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $this->post->id,
    ]);

    $this->guardToken = app(DeviceEnrolment::class)->enrol($this->guard, 'Review handset')['token'];

    // Two lots of this file's OWN: the estate database is committed and shared (FacilitiesFixture::scratchUnit).
    $this->home = FacilitiesFixture::scratchUnit('Lot 941');
    $this->neighbour = FacilitiesFixture::scratchUnit('Lot 942');

    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->home->id], ['name' => 'Review household', 'access_restricted' => false]);
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Paulette Review'],
        ['relationship' => 'owner', 'is_primary' => true],
    );

    $this->account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'channel' => 'email',
        'destination' => 'review.'.bin2hex(random_bytes(4)).'@example.com',
        'status' => ResidentAccount::ACTIVE,
        'full_name' => 'Paulette Review',
        'unit_id' => $this->home->id,
        'resident_id' => $this->resident->id,
    ]);

    $this->residentToken = $this->account->createToken('resident-app:review', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    // The estate's database is committed, so this file clears its own marks on its own lots.
    VisitorPass::query()->whereIn('unit_id', [$this->home->id, $this->neighbour->id])->delete();
});

/** One write as a handset, with the two headers every write carries. */
function reviewPost(Tests\TestCase $test, string $token, string $uri, array $body): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->postJson($uri, $body, [
        'Idempotency-Key' => 'rev-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

it('puts the resident\'s OWN home on a panic, whatever unit the handset names', function () {
    $raised = reviewPost($this, $this->residentToken, '/api/v1/alerts', [
        'kind' => 'panic',
        // The neighbour's lot, from the phone. Before the fix this is what dispatch was sent to.
        'unit_reference' => $this->neighbour->reference,
    ]);

    expect($raised->status())->toBe(201);

    $stored = DB::connection('mysql')->table('duress_alerts')->where('id', $raised->json('id'))->first();

    expect($stored->unit_reference)->toBe($this->home->reference)
        ->and($stored->unit_reference)->not->toBe($this->neighbour->reference)
        // The raiser's name is the token's too — never the body's.
        ->and($stored->raised_by_name)->toBe('Paulette Review')
        ->and($stored->guard_id)->toBeNull();

    // …and omitting it still resolves to the same home, so nothing was lost in the process.
    $silent = reviewPost($this, $this->residentToken, '/api/v1/alerts', ['kind' => 'panic']);

    expect(DB::connection('mysql')->table('duress_alerts')->where('id', $silent->json('id'))->value('unit_reference'))
        ->toBe($this->home->reference);
});

it('still lets a GUARD name the lot an alert is at, because the token says which officer that is', function () {
    $raised = reviewPost($this, $this->guardToken, '/api/v1/alerts', [
        'kind' => 'intrusion',
        'unit_reference' => $this->neighbour->reference,
    ]);

    expect($raised->status())->toBe(201);

    $stored = DB::connection('mysql')->table('duress_alerts')->where('id', $raised->json('id'))->first();

    expect($stored->unit_reference)->toBe($this->neighbour->reference)
        ->and((int) $stored->guard_id)->toBe($this->guard->id);
});

it('writes an audit entry when an offline-claimed entry admits on a pass the server would have refused', function () {
    $pass = app(VisitorPasses::class)->issue(FacilitiesFixture::ESTATE, $this->home, $this->resident->id, 'Paulette Review', [
        'category' => 'single',
        'visitor_name' => 'Winston Caller',
        'valid_from' => Carbon::now()->subMinutes(5),
        'valid_to' => Carbon::now()->addHours(2),
    ]);

    app(VisitorPasses::class)->cancel($pass);

    // Online the same call is refused 409 and the guard is told that admitting is an override, with a reason.
    expect(reviewPost($this, $this->guardToken, '/api/v1/gate/entry', [
        'pass_id' => $pass->pass_id,
        'category' => 'Visitor',
    ])->status())->toBe(409);

    // `verified_offline` is a claim the server cannot check, and it turns the refusal into an admission.
    $admitted = reviewPost($this, $this->guardToken, '/api/v1/gate/entry', [
        'pass_id' => $pass->pass_id,
        'category' => 'Visitor',
        'verified_offline' => true,
    ]);

    expect($admitted->status())->toBe(201)
        ->and($admitted->json('reconciliation'))->toBe('pass_cancelled');

    /*
     * THE POINT OF THE TEST. The gate row says "admit · QR pass · verified offline" and has no
     * column that could say the pass was cancelled. The audit log is append-only at the database,
     * outlives the gate row, and is what an estate reads when a resident asks how somebody got in
     * on a pass they had cancelled.
     */
    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'gate.admitted_on_unadmissible_pass')
        ->where('entity_id', (string) $admitted->json('id'))
        ->first();

    expect($entry)->not->toBeNull('an admission the server would have refused left no record of that fact');

    $after = json_decode((string) ($entry->after ?? ''), true) ?: [];

    expect($entry->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($entry->entity_type)->toBe('GateEvent')
        ->and($after['reconciliation'] ?? null)->toBe('pass_cancelled')
        ->and($after['pass_id'] ?? null)->toBe($pass->pass_id)
        ->and($after['verified_offline_claimed'] ?? null)->toBeTrue()
        ->and($after['employee_number'] ?? null)->toBe('GS-REV-1');

    // An ORDINARY admission is not audited: four hundred of those a week would bury the two that matter.
    $good = app(VisitorPasses::class)->issue(FacilitiesFixture::ESTATE, $this->home, $this->resident->id, 'Paulette Review', [
        'category' => 'single',
        'visitor_name' => 'Ordinary Caller',
        'valid_from' => Carbon::now()->subMinute(),
        'valid_to' => Carbon::now()->addHour(),
    ]);

    $plain = reviewPost($this, $this->guardToken, '/api/v1/gate/entry', ['pass_id' => $good->pass_id, 'category' => 'Visitor']);

    expect($plain->json('reconciliation'))->toBe('consumed')
        ->and(DB::connection('mysql')->table('audit_log')
            ->where('action', 'gate.admitted_on_unadmissible_pass')
            ->where('entity_id', (string) $plain->json('id'))
            ->exists())->toBeFalse();
});
