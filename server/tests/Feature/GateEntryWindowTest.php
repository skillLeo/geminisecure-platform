<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\Post;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Passes\VisitorPasses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A pass admits only inside its window, at the gate too — bug hunt B061
|--------------------------------------------------------------------------
|
| `gate.entry` checked a pass for cancellation, use, expiry and restriction, and
| never for `valid_from`. So a single-use pass for tomorrow, entered online today,
| was answered `consumed`: used up, logged as an ordinary QR admission, audited
| nowhere — and tomorrow the visitor it was issued for was turned away as "already
| used". `gate.verify` had said `not_yet_valid` about the very same pass. The same
| happened, silently, when an offline handset whose clock ran ahead admitted early.
|
| And expiry was judged on the HANDSET's clock even online, so an entry sent with an
| old X-Device-Time got an expired pass through.
|
| Every assertion reads the stored rows as well as the answer.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    Carbon::setTestNow(Carbon::parse('2026-09-24 14:00:00', 'UTC'));

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Window Gate', 'type' => 'gate', 'is_active' => true]);
    $this->guard = Guard::create([
        'full_name' => 'Delroy Window', 'employee_number' => 'GS-WIN-1', 'psra_number' => 'PSRA-WIN-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $this->post->id,
    ]);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Window handset')['token'];

    // A lot of this file's OWN: the estate database is committed and shared.
    $this->unit = FacilitiesFixture::scratchUnit('Lot 7805');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Window household', 'access_restricted' => false]);
    $this->household->forceFill(['access_restricted' => false])->save();
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Marcia Window'],
        ['relationship' => 'owner', 'is_primary' => true],
    );
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
});

function windowPass(object $test, Carbon $from, Carbon $to): VisitorPass
{
    FacilitiesFixture::boot();

    return app(VisitorPasses::class)->issue(FacilitiesFixture::ESTATE, $test->unit, $test->resident->id, 'Marcia Window', [
        'category' => 'single', 'visitor_name' => 'Early Caller', 'valid_from' => $from, 'valid_to' => $to,
    ]);
}

/** One call as the guard's handset, with the handset's own clock in X-Device-Time. */
function windowCall(TestCase $test, string $token, string $uri, array $body, ?Carbon $deviceTime = null): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->postJson($uri, $body, [
        'Idempotency-Key' => 'win-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => ($deviceTime ?? now())->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

function windowAudit(TestResponse $admitted): ?array
{
    $row = DB::connection('mysql')->table('audit_log')
        ->where('action', 'gate.admitted_on_unadmissible_pass')
        ->where('entity_id', (string) $admitted->json('id'))
        ->first();

    return $row === null ? null : (json_decode((string) $row->after, true) ?: []);
}

it('refuses an online entry on a pass whose window has not opened, and leaves the pass for the visitor it was issued for', function () {
    $pass = windowPass($this, now()->addDay()->setTime(14, 0), now()->addDay()->setTime(20, 0));

    // What the guard is shown at the barrier.
    $verdict = windowCall($this, $this->token, '/api/v1/gate/verify', ['pass' => $pass->token]);
    expect($verdict->json('verdict'))->toBe('not_yet_valid')->and($verdict->json('admit_allowed'))->toBeFalse();

    // And what the gate does with an entry recorded against it anyway.
    $entry = windowCall($this, $this->token, '/api/v1/gate/entry', ['category' => 'Visitor', 'pass_id' => $pass->pass_id]);

    expect($entry->status())->toBe(409)
        ->and($entry->json('error.code'))->toBe('pass_not_admissible')
        ->and($entry->json('error.message'))->toContain('not yet valid')
        ->and($pass->fresh()?->status)->toBe(VisitorPass::ACTIVE)
        ->and($pass->fresh()?->used_at)->toBeNull()
        ->and(DB::connection('mysql')->table('gate_events')->where('guard_id', $this->guard->id)->count())->toBe(0);

    // Tomorrow, inside its window, the visitor it was issued for is admitted on it. (A day idle
    // closes a handset's session, so the guard signs the handset in again first.)
    Carbon::setTestNow(now()->addDay()->setTime(15, 0));
    $token = app(DeviceEnrolment::class)->enrol($this->guard, 'Window handset')['token'];
    $tomorrow = windowCall($this, $token, '/api/v1/gate/entry', ['category' => 'Visitor', 'pass_id' => $pass->pass_id]);

    expect($tomorrow->status())->toBe(201)
        ->and($tomorrow->json('reconciliation'))->toBe('consumed')
        ->and($pass->fresh()?->status)->toBe(VisitorPass::USED);
});

it('records an early OFFLINE admission as one the server would have refused, audits it, and does not use the pass up', function () {
    // The pass opens in half an hour; the handset's clock runs forty minutes ahead, so it thought the window was open.
    $pass = windowPass($this, now()->addMinutes(30), now()->addHours(4));

    $admitted = windowCall($this, $this->token, '/api/v1/gate/entry', [
        'category' => 'Visitor', 'pass_id' => $pass->pass_id, 'verified_offline' => true,
    ], deviceTime: now()->addMinutes(40));

    expect($admitted->status())->toBe(201)
        ->and($admitted->json('reconciliation'))->toBe('pass_not_yet_valid');

    $after = windowAudit($admitted);

    expect($after)->not->toBeNull('an early admission left no record that the server would have refused it')
        ->and($after['reconciliation'] ?? null)->toBe('pass_not_yet_valid')
        ->and($after['pass_id'] ?? null)->toBe($pass->pass_id)
        ->and($after['verified_offline_claimed'] ?? null)->toBeTrue();

    // Not used up: the visit that happens inside the window is the one the resident issued it for.
    expect($pass->fresh()?->status)->toBe(VisitorPass::ACTIVE)
        ->and($pass->fresh()?->used_at)->toBeNull();
});

it('judges an online entry on the server\'s clock, so an old X-Device-Time does not carry an expired pass through', function () {
    $pass = windowPass($this, now()->subHours(3), now()->subHour());

    $entry = windowCall($this, $this->token, '/api/v1/gate/entry', [
        'category' => 'Visitor', 'pass_id' => $pass->pass_id,
    ], deviceTime: now()->subHours(2));

    expect($entry->status())->toBe(409)
        ->and($entry->json('error.code'))->toBe('pass_not_admissible')
        ->and($entry->json('error.message'))->toContain('pass expired')
        ->and($pass->fresh()?->status)->toBe(VisitorPass::ACTIVE);
});

it('agrees with gate.verify at the edges of a window, where both give the same two minutes for a clock', function () {
    // A minute before it opens, and a minute after it closed: verify says admit, so the entry must not refuse.
    $opening = windowPass($this, now()->addMinute(), now()->addHours(2));
    $closing = windowPass($this, now()->subHours(2), now()->subMinute());

    foreach ([$opening, $closing] as $pass) {
        expect(windowCall($this, $this->token, '/api/v1/gate/verify', ['pass' => $pass->token])->json('verdict'))->toBe('valid');

        $entry = windowCall($this, $this->token, '/api/v1/gate/entry', ['category' => 'Visitor', 'pass_id' => $pass->pass_id]);

        expect($entry->status())->toBe(201)->and($entry->json('reconciliation'))->toBe('consumed');
    }
});

it('still takes an offline admission made inside the window and synced after it closed as an ordinary one', function () {
    $pass = windowPass($this, now()->subHours(2), now()->subMinutes(30));

    // Admitted an hour ago with no signal, while the pass was good; synced now.
    $admitted = windowCall($this, $this->token, '/api/v1/gate/entry', [
        'category' => 'Visitor', 'pass_id' => $pass->pass_id, 'verified_offline' => true,
    ], deviceTime: now()->subHour());

    expect($admitted->status())->toBe(201)
        ->and($admitted->json('reconciliation'))->toBe('consumed')
        ->and(windowAudit($admitted))->toBeNull()
        ->and($pass->fresh()?->status)->toBe(VisitorPass::USED);
});
