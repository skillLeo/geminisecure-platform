<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Shift;
use App\Models\StatutoryRateVersion;
use App\Models\Tenant;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Estate\Collections;
use App\Services\Passes\VisitorPasses;
use App\Support\SiteTime;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Live\Support\LiveAuthFixture;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HouseholdHolder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The Guard App's API, walked end to end — 13 D2
|--------------------------------------------------------------------------
|
| One guard's shift through every endpoint a Guard App token reaches: clock on,
| break, patrol, answer a check, verify and admit at the gate, file an incident
| with a photo, raise and cancel duress, ask for leave, read a payslip, message
| dispatch, and sync a queue captured offline.
|
| EVERY RESPONSE IS HELD TO ITS CATALOGUE ENTRY (`ApiContract`): a key the
| catalogue does not list fails, and no key on a guard route may name money
| except the guard's own payslip. EVERY WRITE carries an Idempotency-Key and the
| handset's clock, and the test fails at the end if a guard route in the
| catalogue was never called — so a new endpoint cannot ship uncontracted.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // live.auth writes its grants down (M11 Part C); the table exists before the transaction does.

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Contract Gate', 'type' => 'gate', 'is_active' => true]);
    $this->post->forceFill(['latitude' => 18.0179000, 'longitude' => -76.8099000, 'geofence_radius_m' => 5])->save();

    $this->guard = Guard::create([
        'full_name' => 'Kemar Contract',
        'employee_number' => 'GS-CTR-1',
        'psra_number' => 'PSRA-CTR-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $this->post->id,
    ]);

    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Contract handset')['token'];

    /*
     * A lot with no payment plan standing over it. Other files leave plans on the
     * shared fixture's lots, and a plan being met lifts a restriction — which is
     * right, and would turn this test's amber verdict green for a reason of its own.
     */
    $this->unit = collect(['Lot 88', 'Lot 9', 'Lot 3', 'Lot 63', 'Lot 47'])
        ->map(fn (string $reference) => FacilitiesFixture::unit($reference))
        ->first(fn ($unit) => ! app(Collections::class)->isProtected($unit));

    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Fletcher household', 'access_restricted' => false]);
    $this->household->forceFill(['access_restricted' => false])->save();
    // A shared fixture lot: this file takes the household over for its own run (one primary, DESIGN §13).
    $this->resident = HouseholdHolder::of($this->household, 'Andrea Fletcher');

    $GLOBALS['guardContractCalled'] = [];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
    DB::connection('tenant')->table('gate_approval_requests')->where('unit_id', $this->unit->id)->delete();
    $this->household->forceFill(['access_restricted' => false])->save();
});

/**
 * One call as the handset, held to its catalogue entry.
 *
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function asHandset(TestCase $test, string $token, string $name, array $params = [], array $body = [], ?int $status = null, array $headers = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    if ($entry['write']) {
        $headers += ['Idempotency-Key' => 'ctr-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()];
    }

    app('auth')->forgetGuards();

    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson($uri.($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $test->withToken($token)->json($entry['method'], $uri, $body, $headers);

    $response->assertStatus($status ?? $entry['status']);

    ApiContract::assertMatches($response, $name);

    $GLOBALS['guardContractCalled'][$name] = true;

    FacilitiesFixture::boot();

    return $response;
}

it('walks every Guard App endpoint, each answer inside its catalogue allowlist and none carrying a household\'s money', function () {
    $t = $this->token;
    $site = FacilitiesFixture::ESTATE;

    $shift = Shift::create([
        'tenant_id' => $site, 'guard_id' => $this->guard->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(12), 'status' => 'rostered',
    ]);

    $open = Shift::create([
        'tenant_id' => $site, 'guard_id' => null, 'post_id' => $this->post->id,
        'rostered_start' => now()->addDays(3), 'rostered_end' => now()->addDays(3)->addHours(12), 'status' => Shift::OPEN,
    ]);

    /* ── The roster and clocking on ─────────────────────────────── */

    expect(asHandset($this, $t, 'shifts.me.current')->json('shift.id'))->toBe($shift->id);

    $preflight = asHandset($this, $t, 'shifts.preflight', ['shift' => $shift->id], ['latitude' => 18.0179100, 'longitude' => -76.8099100, 'accuracy_m' => 3]);
    expect($preflight->json('allowed'))->toBeTrue()->and($preflight->json('within_geofence'))->toBeTrue();

    $far = asHandset($this, $t, 'shifts.preflight', ['shift' => $shift->id], ['latitude' => 18.0189, 'longitude' => -76.8099]);
    expect($far->json('allowed'))->toBeFalse()->and($far->json('distance_m'))->toBeGreaterThan(100);

    // A write with no Idempotency-Key, or no device clock, is refused before it happens.
    app('auth')->forgetGuards();
    $this->withToken($t)->postJson("/api/v1/shifts/{$shift->id}/break/start", [], ['X-Device-Time' => now()->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');
    app('auth')->forgetGuards();
    $this->withToken($t)->postJson("/api/v1/shifts/{$shift->id}/break/start", [], ['Idempotency-Key' => 'no-clock'])
        ->assertStatus(422)->assertJsonPath('error.code', 'device_time_required');

    asHandset($this, $t, 'shifts.break.start', ['shift' => $shift->id], [], 409);

    $clockIn = asHandset($this, $t, 'shifts.clock_in', ['shift' => $shift->id], ['geofence_distance_m' => 2]);
    expect($clockIn->json('status'))->toBe('on_duty')->and($clockIn->json('server_time'))->not->toBeNull();

    $break = asHandset($this, $t, 'shifts.break.start', ['shift' => $shift->id]);
    expect($break->json('ended_at'))->toBeNull();
    expect(asHandset($this, $t, 'shifts.break.end', ['shift' => $shift->id])->json('ended_at'))->not->toBeNull();
    asHandset($this, $t, 'shifts.break.end', ['shift' => $shift->id], [], 409);

    $mine = asHandset($this, $t, 'shifts.me', [], ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]);
    expect($mine->json('totals.shifts'))->toBe(1);
    asHandset($this, $t, 'shifts.me', [], ['from' => now()->subDays(40)->toDateString(), 'to' => now()->toDateString()], 422);

    $offered = asHandset($this, $t, 'shifts.open');
    expect(collect($offered->json('items'))->pluck('id'))->toContain($open->id);
    expect(asHandset($this, $t, 'shifts.claim', ['shift' => $open->id])->json('status'))->toBe('pending');

    /* ── Orders, patrol, alertness, presence ────────────────────── */

    $setId = DB::connection('mysql')->table('standing_order_sets')->insertGetId([
        'title' => 'Contract Gate orders', 'category' => 'post_specific', 'summary' => 'Post orders',
        'tenant_id' => $site, 'post_id' => $this->post->id, 'version' => 1,
        'body' => 'Log every contractor out as well as in.', 'effective_on' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $versionId = DB::connection('mysql')->table('standing_order_versions')->insertGetId([
        'standing_order_set_id' => $setId, 'version' => 1, 'title' => 'Contract Gate orders', 'summary' => 'Post orders',
        'body' => 'Log every contractor out as well as in.', 'effective_on' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $orders = asHandset($this, $t, 'orders.current', ['site' => $site]);
    expect(collect($orders->json('items'))->firstWhere('set_id', $setId)['version_id'])->toBe($versionId);
    expect(asHandset($this, $t, 'orders.acknowledge', ['version' => $versionId])->json('version_id'))->toBe($versionId);
    asHandset($this, $t, 'standing_orders.index');
    asHandset($this, $t, 'orders.current', ['site' => 'phoenixpark'], [], 403);

    $checkpoint = DB::connection('mysql')->table('patrol_checkpoints')->insertGetId([
        'tenant_id' => $site, 'post_id' => $this->post->id, 'label' => 'Pool gate', 'code' => 'CTR-POOL-7F3A', 'sequence' => 1, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    asHandset($this, $t, 'patrol.checkpoints', ['site' => $site]);
    asHandset($this, $t, 'patrol.scan', ['checkpoint' => $checkpoint], ['code' => 'WRONG-TAG'], 422);
    expect(asHandset($this, $t, 'patrol.scan', ['checkpoint' => $checkpoint], ['code' => 'CTR-POOL-7F3A'])->json('tour.scanned_today'))->toBe(1);

    $check = DB::connection('mysql')->table('alertness_checks')->insertGetId([
        'guard_id' => $this->guard->id, 'shift_id' => $shift->id, 'outcome' => 'pending',
        'issued_at' => now(), 'respond_by' => now()->addMinutes(2), 'server_time' => now(),
    ]);

    // Coordinates are compared, not kept: the ping row has no column for them.
    $ping = asHandset($this, $t, 'presence.activity', [], ['state' => 'on_post', 'latitude' => 18.0179050, 'longitude' => -76.8099050, 'battery_pct' => 81]);
    expect($ping->json('within_geofence'))->toBeTrue()
        ->and($ping->json('pending_alertness_check.check_id'))->toBe($check)
        ->and(DB::connection('mysql')->getSchemaBuilder()->hasColumn('guard_presence_pings', 'latitude'))->toBeFalse();

    // A score sent anyway is ignored, and there is nowhere to keep one (Legal review pack §7.10).
    expect(asHandset($this, $t, 'alertness.respond', ['check' => $check], ['score' => 92])->json('outcome'))->toBe('passed')
        ->and(DB::connection('mysql')->getSchemaBuilder()->hasColumn('alertness_checks', 'score'))->toBeFalse();

    $late = DB::connection('mysql')->table('alertness_checks')->insertGetId([
        'guard_id' => $this->guard->id, 'shift_id' => $shift->id, 'outcome' => 'pending',
        'issued_at' => now()->subMinutes(5), 'respond_by' => now()->subMinutes(3), 'server_time' => now()->subMinutes(5),
    ]);
    asHandset($this, $t, 'alertness.respond', ['check' => $late], [], 409);
    expect(DB::connection('mysql')->table('alertness_checks')->where('id', $late)->value('outcome'))->toBe('missed');

    /* ── The gate ───────────────────────────────────────────────── */

    asHandset($this, $t, 'passes.keys', ['site' => $site]);

    $passes = app(VisitorPasses::class);
    $pass = $passes->issue($site, $this->unit, $this->resident->id, 'Andrea Fletcher', [
        'category' => 'single', 'visitor_name' => 'Marcia James', 'purpose' => 'Family visit',
        'valid_from' => Carbon::now()->subMinutes(5), 'valid_to' => Carbon::now()->addHours(2),
    ]);

    $verdict = asHandset($this, $t, 'gate.verify', [], ['pass' => $pass->token]);
    expect($verdict->json('verdict'))->toBe('valid')
        ->and($verdict->json('access_restricted'))->toBeFalse()
        ->and($verdict->json('household'))->toBe($this->household->name)
        ->and($verdict->json('unit'))->toBe($this->unit->reference);

    expect(asHandset($this, $t, 'gate.verify', [], ['pass' => $pass->code])->json('verdict'))->toBe('valid');

    $entry = asHandset($this, $t, 'gate.entry', [], ['pass_id' => $pass->pass_id, 'category' => 'Visitor']);
    expect($entry->json('reconciliation'))->toBe('consumed')->and($entry->json('pass_based'))->toBeTrue();

    expect(asHandset($this, $t, 'gate.verify', [], ['pass' => $pass->token])->json('verdict'))->toBe('already_used');

    // Online, a used pass admits nobody; offline it was already admitted, and the sync says what happened.
    asHandset($this, $t, 'gate.entry', [], ['pass_id' => $pass->pass_id, 'category' => 'Visitor'], 409);
    $reconciled = asHandset($this, $t, 'gate.entry', [], ['pass_id' => $pass->pass_id, 'category' => 'Visitor', 'verified_offline' => true]);
    expect($reconciled->json('reconciliation'))->toBe('pass_already_used')->and($reconciled->json('basis'))->toBe('QR pass · verified offline');

    // A restricted household: amber, and the boolean is all the guard learns.
    $this->household->forceFill(['access_restricted' => true])->save();
    $guest = $passes->issue($site, $this->unit, $this->resident->id, 'Andrea Fletcher', [
        'category' => 'single', 'visitor_name' => 'Delroy Guest', 'valid_from' => Carbon::now()->subMinute(), 'valid_to' => Carbon::now()->addHour(),
    ]);
    $amber = asHandset($this, $t, 'gate.verify', [], ['pass' => $guest->token]);
    expect($amber->json('verdict'))->toBe('restricted')->and($amber->json('tone'))->toBe('amber')->and($amber->json('access_restricted'))->toBeTrue();

    // The search takes PART of a reference: "Lot 88" finds "Lot 8871" too, which another file may have left on
    // the shared estate. This unit is the one whose reference is the whole of what was typed — never `items.0`.
    $found = asHandset($this, $t, 'gate.search', [], ['unit' => $this->unit->reference]);
    $mine = collect($found->json('items'))->firstWhere('unit', $this->unit->reference);
    expect($mine)->not->toBeNull()
        ->and($mine['access_restricted'])->toBeTrue()
        ->and($mine['primary_resident'])->toBe($this->household->residents()->where('is_primary', true)->value('full_name'));
    asHandset($this, $t, 'gate.search', [], ['name' => 'Fletcher']);
    $this->household->forceFill(['access_restricted' => false])->save();

    // EVERY LOOKUP IS LOGGED (M21 §1.7): who asked, what they typed and how many matched — never which, never money.
    $lookup = App\Models\AuditEntry::query()->where('action', 'gate.lookup')->where('tenant_id', FacilitiesFixture::ESTATE)->latest('id')->firstOrFail();
    expect($lookup->after)->toMatchArray(['by' => 'name', 'typed' => 'Fletcher'])
        ->and($lookup->after['matched'])->toBeInt()
        ->and(json_encode($lookup->after))->not->toContain('restricted')
        // The officer who asked, by name: the audit log's actor, and the entry's own record of them.
        ->and((string) $lookup->actor_name)->toBe((string) $lookup->after['guard']);

    asHandset($this, $t, 'gate.exit', [], ['category' => 'Visitor', 'subject' => 'Marcia James · Lot 47']);
    expect(asHandset($this, $t, 'gate.override', [], ['category' => 'Contractor', 'subject' => 'JPS crew', 'reason' => 'Power outage on the estate'])->json('verdict'))->toBe('override');

    $walkUp = asHandset($this, $t, 'gate.approvals.store', [], ['unit' => $this->unit->reference, 'visitor_name' => 'Courier — Tank Weld', 'id_type' => "Driver's licence", 'id_number' => 'D1234567', 'purpose' => 'Delivery', 'vehicle_plate' => 'PC 4471']);
    expect($walkUp->json('status'))->toBe('pending');
    asHandset($this, $t, 'gate.entry', [], ['approval_id' => $walkUp->json('approval_id'), 'category' => 'Delivery'], 409);

    DB::connection('tenant')->table('gate_approval_requests')->where('id', $walkUp->json('approval_id'))->update(['status' => 'approved', 'responded_at' => now(), 'responded_by_name' => 'Andrea Fletcher']);
    expect(asHandset($this, $t, 'gate.approvals.show', ['approval' => $walkUp->json('approval_id')])->json('status'))->toBe('approved');
    expect(asHandset($this, $t, 'gate.entry', [], ['approval_id' => $walkUp->json('approval_id'), 'category' => 'Delivery'])->json('basis'))->toBe('Pre-approved');

    // THE ARREARS RESTRICTION APPLIES TO A WALK-UP AS TO A PASS (M21 §1.7): the household's own "yes" does not lift
    // it. Before, an approved walk-up for a restricted household went in as "Pre-approved" with no word said.
    $this->household->forceFill(['access_restricted' => true])->save();
    $held = asHandset($this, $t, 'gate.approvals.store', [], ['unit' => $this->unit->reference, 'visitor_name' => 'Cousin — Marlon', 'id_type' => "Voter's ID", 'id_number' => 'V7788', 'purpose' => 'Family visit', 'no_vehicle' => true]);
    expect($held->json('access_restricted'))->toBeTrue();
    DB::connection('tenant')->table('gate_approval_requests')->where('id', $held->json('approval_id'))->update(['status' => 'approved', 'responded_at' => now(), 'responded_by_name' => 'Andrea Fletcher']);
    $shown = asHandset($this, $t, 'gate.approvals.show', ['approval' => $held->json('approval_id')]);
    expect($shown->json('access_restricted'))->toBeTrue()
        ->and($shown->json('guidance'))->toContain('override, with a reason');
    asHandset($this, $t, 'gate.entry', [], ['approval_id' => $held->json('approval_id'), 'category' => 'Visitor'], 409)
        ->assertJsonPath('error.code', 'household_restricted')
        ->assertJsonPath('error.message', 'Access restricted: contact management. Admitting this visitor is an override, with a reason.');
    expect(asHandset($this, $t, 'gate.override', [], ['category' => 'Visitor', 'subject' => 'Cousin — Marlon · '.$this->unit->reference, 'reason' => 'Management rang: admit for the family visit'])->json('verdict'))->toBe('override');
    $this->household->forceFill(['access_restricted' => false])->save();

    expect(asHandset($this, $t, 'gate.activity')->json('counts.admitted'))->toBeGreaterThanOrEqual(3);

    asHandset($this, $t, 'passes.verify', [], ['household_id' => $this->household->id, 'pass_category' => 'guest']);
    asHandset($this, $t, 'gate_events.store', [], ['verdict' => 'admit', 'category' => 'Resident vehicle', 'subject' => 'Lot 47 · 5544 JX', 'basis' => 'Tag read']);

    /* ── Reports: incidents, duress ─────────────────────────────── */

    $incident = asHandset($this, $t, 'incidents.store', [], [
        'kind' => 'Attempted unauthorized access', 'severity' => 'med', 'location' => 'North fence',
        'detail' => 'Two men attempted to climb the north fence at 02:10 and left when challenged.',
    ]);
    expect($incident->json('shift_id'))->toBe($shift->id);

    app('auth')->forgetGuards();
    $media = $this->withToken($t)->post('/api/v1/incidents/'.$incident->json('id').'/media', [
        'file' => UploadedFile::fake()->image('north-fence.jpg', 64, 48),
    ], ['Accept' => 'application/json', 'Idempotency-Key' => 'media-1', 'X-Device-Time' => now()->toIso8601String()])->assertCreated();
    ApiContract::assertMatches($media, 'incidents.media');
    $GLOBALS['guardContractCalled']['incidents.media'] = true;
    FacilitiesFixture::boot();

    // On the ESTATE's private disk: tenancy roots `local` per estate while the request runs.
    $stored = (string) DB::connection('mysql')->table('incident_media')->where('id', $media->json('media_id'))->value('path');
    FacilitiesFixture::platform()->run(function () use ($stored, $media): void {
        expect(Storage::disk('local')->exists($stored))->toBeTrue()
            ->and(hash('sha256', (string) Storage::disk('local')->get($stored)))->toBe($media->json('sha256'));

        Storage::disk('local')->delete($stored);
    });
    FacilitiesFixture::boot();
    expect(asHandset($this, $t, 'incidents.me')->json('items.0.media_count'))->toBe(1);

    $duress = asHandset($this, $t, 'duress.store', [], ['mode' => 'silent']);
    expect($duress->json('kind'))->toBe('duress');
    expect(asHandset($this, $t, 'duress.cancel', ['alert' => $duress->json('id')])->json('status'))->toBe('false_alarm');

    $pressed = asHandset($this, $t, 'duress.store', [], ['mode' => 'audible']);
    DB::connection('mysql')->table('duress_alerts')->where('id', $pressed->json('id'))->update(['server_time' => now()->subSeconds(30)]);
    asHandset($this, $t, 'duress.cancel', ['alert' => $pressed->json('id')], [], 409);

    // Where the guard is while their duress stays open (12 Oct 2026, GUARD_TRACKING.md 5.3).
    $followed = asHandset($this, $t, 'duress.positions.store', ['alert' => $pressed->json('id')], ['positions' => [
        ['idempotency_key' => 'ctr-duress-fix-1', 'captured_at' => now()->subSeconds(5)->toIso8601String(), 'lat' => 18.0179050, 'lng' => -76.8099050, 'accuracy_metres' => 6],
    ]]);
    expect($followed->json('results.0.result'))->toBe('accepted');

    // Read back (mobile-api): the guard's own alert, in the same shape — and nobody else's.
    expect(asHandset($this, $t, 'duress.show', ['alert' => $pressed->json('id')])->json('status'))->toBe('open');
    expect(asHandset($this, $t, 'duress.show', ['alert' => $duress->json('id')])->json('status'))->toBe('false_alarm');
    asHandset($this, $t, 'duress.show', ['alert' => 999_999_999], [], 404);

    asHandset($this, $t, 'alerts.store', [], ['kind' => 'medical']);

    /* ── M10 §1.3: responding to a resident's panic from this handset (walked in full in AlertProgressTest) ── */

    $panicHere = app(AlertIntake::class)->record(
        tenantId: $site, kind: 'panic', raisedByName: 'Andrea Fletcher', unitReference: $this->unit->reference,
        idempotencyKey: 'ctr-response-'.bin2hex(random_bytes(4)),
    );

    $following = asHandset($this, $t, 'alerts.show', ['alert' => $panicHere->id]);
    expect($following->json('can_en_route'))->toBeTrue()
        ->and($following->json('where'))->toBe($this->unit->reference)
        ->and((string) $following->getContent())->not->toContain('Andrea Fletcher');   // where, never who

    expect(asHandset($this, $t, 'alerts.en_route', ['alert' => $panicHere->id])->json('responder_is_you'))->toBeTrue();
    expect(asHandset($this, $t, 'alerts.on_scene', ['alert' => $panicHere->id])->json('on_scene_at'))->not->toBeNull();
    expect(asHandset($this, $t, 'alerts.en_route', ['alert' => $panicHere->id], [], 409)->json('error.code'))->toBe('already_en_route');
    asHandset($this, $t, 'alerts.show', ['alert' => 999_999_999], [], 404);

    /* ── The guard's own: requests, pay, messages ───────────────── */

    asHandset($this, $t, 'requests.store', [], ['kind' => 'leave', 'subject' => 'vacation', 'reason' => 'Family trip', 'starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonth()->addDays(4)->toDateString()]);
    asHandset($this, $t, 'requests.store', [], ['kind' => 'leave', 'subject' => 'sabbatical', 'starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()], 422);
    expect(asHandset($this, $t, 'requests.index')->json('items.0.days'))->toBe(5);

    (new StatutoryRatesSeeder)->run();
    $rates = StatutoryRateVersion::forPayDate('2026-08-31');

    foreach (['approved' => ['PR-CTR-08', '2026-08-01', '2026-08-31', 38_450_00], 'draft' => ['PR-CTR-09', '2026-09-01', '2026-09-30', 99_999_00]] as $status => [$ref, $from, $to, $gross]) {
        $run = new PayrollRun;
        $run->forceFill([
            'reference' => $ref, 'period_label' => $ref, 'period_start' => $from, 'period_end' => $to, 'periods_per_year' => 12,
            'statutory_rate_version_id' => $rates->id, 'status' => $status, 'gross_minor' => $gross, 'net_minor' => $gross - 5_000_00, 'currency' => 'JMD',
        ])->save();

        Payslip::create([
            'payroll_run_id' => $run->id, 'guard_id' => $this->guard->id, 'tenant_id' => $site,
            'gross_minor' => $gross, 'nis_minor' => 1_153_50, 'nht_minor' => 769_00, 'education_tax_minor' => 839_03,
            'paye_minor' => 2_238_47, 'pension_minor' => 0, 'net_minor' => $gross - 5_000_00, 'currency' => 'JMD',
        ]);
    }

    $slips = asHandset($this, $t, 'payslips.me');
    expect($slips->json('items'))->toHaveCount(1)
        ->and($slips->json('items.0.gross'))->toBe('38450.00')
        ->and($slips->json('items.0.deductions.nis'))->toBe('1153.50')
        ->and($slips->json('items.0.net'))->toBe('33450.00')
        // M8 §5.3: the breakdown is absent on a slip written before it was recorded, and says so
        // with a null rather than a confident zero over a real wage.
        ->and($slips->json('items.0.hours'))->toBeNull()
        ->and($slips->json('items.0.employee.employee_number'))->toBe($this->guard->employee_number);

    /*
     * The same payslip as a PDF (M8 §5.3). Fetched directly rather than through `asHandset`, for the
     * reason `notices.attachment` is: the answer is a FILE, so there is no JSON body to hold to the
     * catalogue, and the walk is told it was called.
     */
    app('auth')->forgetGuards();
    $pdf = $this->withToken($t)->get('/api/v1/payslips/'.$slips->json('items.0.id').'/pdf')->assertOk();
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF')
        ->and($pdf->headers->get('content-disposition'))->toContain('payslip-');
    $GLOBALS['guardContractCalled']['payslips.pdf'] = true;
    FacilitiesFixture::boot();

    DB::connection('mysql')->table('dispatch_messages')->insert([
        'tenant_id' => $site, 'direction' => 'broadcast', 'body' => 'Water lock-off 10 AM to 2 PM.', 'sent_at' => now()->subMinutes(20), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(asHandset($this, $t, 'messages.index')->json('unread'))->toBeGreaterThanOrEqual(1);
    expect(asHandset($this, $t, 'messages.store', [], ['body' => 'Barrier arm sticking at the main gate.'])->json('from'))->toBe('you');

    /* ── Offline: pull, then upload the queue ───────────────────── */

    $pulled = asHandset($this, $t, 'sync.pull', [], ['since' => now()->subHour()->toIso8601String()]);
    expect(collect($pulled->json('passes.revoked'))->pluck('pass_id'))->toContain($pass->pass_id)
        ->and($pulled->json('passes.keys'))->not->toBeEmpty();

    // The queued scan below was made forty minutes ago, ON this shift — a patrol scan belongs to a running shift
    // (M21 §1.1) — so the shift is taken to have begun an hour ago, as a guard's forty-minute outage would find it.
    Shift::query()->whereKey($shift->id)->update(['actual_start' => now()->subHour()]);

    $operations = [
        ['id' => 'op-1', 'endpoint' => 'patrol.scan', 'params' => ['checkpoint' => $checkpoint], 'body' => ['code' => 'CTR-POOL-7F3A', 'captured_offline' => true], 'idempotency_key' => 'queued-scan-1', 'device_time' => now()->subMinutes(40)->toIso8601String()],
        ['id' => 'op-2', 'endpoint' => 'messages.store', 'body' => ['body' => 'Back on post after the outage.'], 'idempotency_key' => 'queued-msg-1', 'device_time' => now()->subMinutes(35)->toIso8601String()],
        ['id' => 'op-3', 'endpoint' => 'incidents.media', 'params' => ['incident' => $incident->json('id')], 'idempotency_key' => 'queued-media-1', 'device_time' => now()->subMinutes(30)->toIso8601String()],
        ['id' => 'op-4', 'endpoint' => 'shifts.me', 'idempotency_key' => 'queued-read-1', 'device_time' => now()->subMinutes(30)->toIso8601String()],
        ['id' => 'op-5', 'endpoint' => 'patrol.scan', 'params' => ['checkpoint' => 'pool'], 'body' => ['code' => 'x'], 'idempotency_key' => 'queued-bad-1', 'device_time' => now()->subMinutes(30)->toIso8601String()],
    ];

    $batch = asHandset($this, $t, 'sync.batch', [], ['operations' => $operations]);
    expect(collect($batch->json('results'))->pluck('outcome', 'id')->all())->toBe(['op-1' => 'ok', 'op-2' => 'ok', 'op-3' => 'refused', 'op-4' => 'refused', 'op-5' => 'refused'])
        ->and($batch->json('results.0.status'))->toBe(201)
        ->and($batch->json('results.2.body.error.code'))->toBe('endpoint_not_batchable')
        ->and($batch->json('results.4.body.error.code'))->toBe('params_invalid');

    // The device time of the queued scan is when it happened, not when it synced — and a
    // scan queued forty minutes ago is a late sync, not a wrong clock (bug hunt B066).
    $scan = DB::connection('mysql')->table('checkpoint_scans')->where('idempotency_key', 'queued-scan-1')->first();
    expect(Carbon::parse((string) $scan->device_time)->diffInMinutes(now()))->toBeGreaterThanOrEqual(39)
        ->and((bool) $scan->clock_skewed)->toBeFalse();

    // A batch retried after a lost answer does nothing twice.
    $again = asHandset($this, $t, 'sync.batch', [], ['operations' => array_slice($operations, 0, 2)]);
    expect($again->json('results.0.replayed'))->toBeTrue()->and($again->json('results.1.replayed'))->toBeTrue()
        ->and(DB::connection('mysql')->table('checkpoint_scans')->where('idempotency_key', 'queued-scan-1')->count())->toBe(1);

    /* ── On-duty location sharing (M11 §3): the policy, the officer's answer, what the phone sampled ── */

    expect(asHandset($this, $t, 'tracking.status')->json('consent'))->toBeNull();
    expect(asHandset($this, $t, 'tracking.consent', [], ['decision' => 'granted', 'policy_version' => config('tracking.policy_version')])->json('consent.decision'))->toBe('granted');
    $shared = asHandset($this, $t, 'tracking.positions', ['shift' => $shift->id], ['positions' => [
        ['id' => 'ctr-fix-1', 'latitude' => 18.0179050, 'longitude' => -76.8099050, 'accuracy_m' => 6, 'source' => 'checkpoint', 'battery_pct' => 81, 'captured_at' => now()->subMinutes(2)->toIso8601String()],
    ]]);
    expect($shared->json('accepted'))->toBe(['ctr-fix-1'])->and($shared->json('refused'))->toBe([]);

    // Guard live tracking (12 Oct 2026, GUARD_TRACKING.md 5.1 and 5.2): the batch, answered one by one, and the read-back.
    $batch = asHandset($this, $t, 'tracking.presence_positions', [], ['positions' => [
        ['idempotency_key' => 'ctr-reading-1', 'captured_at' => now()->subMinute()->toIso8601String(), 'lat' => 18.0179050, 'lng' => -76.8099050, 'accuracy_metres' => 6, 'battery_percent' => 80, 'is_moving' => false, 'source' => 'background'],
    ]]);
    expect($batch->json('results.0.result'))->toBe('accepted');
    expect(asHandset($this, $t, 'tracking.my_positions')->json('shift.id'))->toBe($shift->id);

    /* ── Clocking off ───────────────────────────────────────────── */

    asHandset($this, $t, 'presence.summary');
    expect(asHandset($this, $t, 'shifts.clock_out', ['shift' => $shift->id], ['handover_note' => 'Barrier arm sticking; logged with dispatch.'])->json('status'))->toBe('completed');

    /* ── M4 §3.4: the guard's own licence, their own read marks, a supervisor's PIN (walked in full in GuardOverrideContractTest) ── */

    expect(asHandset($this, $t, 'guards.me')->json('licence.number'))->toBe('PSRA-CTR-1');

    $unreadIds = collect(asHandset($this, $t, 'messages.index')->json('items'))->where('from', 'dispatch')->whereNull('read_at')->pluck('id')->all();
    expect(asHandset($this, $t, 'messages.read', [], ['ids' => $unreadIds])->json('unread'))->toBe(0);

    $vouching = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    app(SupervisorOverride::class)->setPin($vouching, '482913');
    $nextShift = Shift::create([
        'tenant_id' => $site, 'guard_id' => $this->guard->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->addDay(), 'rostered_end' => now()->addDay()->addHours(12), 'status' => 'rostered',
    ]);
    $vouched = asHandset($this, $t, 'shifts.clock_in_override', ['shift' => $nextShift->id], ['supervisor' => $vouching->email, 'pin' => '482913', 'reason' => 'biometric_failed', 'geofence_distance_m' => 2]);
    expect($vouched->json('overridden'))->toBeTrue()->and($vouched->json('overridden_by'))->toBe($vouching->name);

    /* ── M4 §3.2: where to reach this handset when the app is closed (walked in full in PushNotificationsTest) ── */

    $registered = asHandset($this, $t, 'push.register', [], ['token' => 'ExponentPushToken[contractGuardHandset01]', 'platform' => 'android', 'muted' => ['payslips', 'duress']]);
    expect($registered->json('muted'))->toBe(['payslips'])                                  // a locked category is not a mute the server keeps
        ->and((string) $registered->getContent())->not->toContain('ExponentPushToken');      // the address is never echoed
    asHandset($this, $t, 'push.register', [], ['token' => 'not-an-expo-token', 'platform' => 'android'], 422);

    // The guard ring: this handset reports it can ring like a call, and asks for a test ring (walked in full in GuardRing/).
    $ring = ['v' => 1, 'configured' => true, 'notifications' => 'on', 'ring_channel' => 'on', 'full_screen' => 'on', 'dnd_bypass' => 'on',
        'battery' => 'exempt', 'background' => 'allowed', 'alarm_volume' => 'ok', 'alarm_tone' => 'set', 'screen_lock' => 'on', 'sdk' => 35, 'maker' => 'samsung'];
    expect(asHandset($this, $t, 'push.register', [], ['token' => 'ExponentPushToken[contractGuardHandset01]', 'platform' => 'android', 'ring' => $ring])->json('ring_state'))->toBe('ready');
    expect(asHandset($this, $t, 'ring.test')->json('sent'))->toBeTrue();

    expect(asHandset($this, $t, 'push.unregister')->json('registered'))->toBeFalse();

    /* ── M11 Part C: live updates while the app is open (walked in full in ChannelMatrixTokenTest) ── */

    LiveAuthFixture::credentials();

    $own = 'guard.'.$site.'.'.$this->guard->id;
    expect(collect(asHandset($this, $t, 'live.config')->json('channels'))->pluck('name')->all())
        ->toBe([$own, 'estate.'.$site.'.guards', 'estate.'.$site.'.passes']);

    $granted = asHandset($this, $t, 'live.auth', [], ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => ['private-live.'.$own, 'private-live.estate.'.$site.'.security']]);
    expect($granted->json('channels.0.refused'))->toBeFalse()
        ->and($granted->json('channels.0.auth'))->toBe(LiveAuthFixture::expectedAuth('private-live.'.$own, LiveAuthFixture::channelData('g-'.$this->guard->id)))
        ->and($granted->json('channels.1.refused'))->toBeTrue('a guard is never told who pressed: the security feed is the console\'s');

    // What the channel missed, from the log (walked in full in CatchUpTest): its own, and never the console's feed.
    $caught = asHandset($this, $t, 'live.catch_up', [], ['channels' => [['name' => $own, 'c' => null, 'e' => null], ['name' => 'estate.'.$site.'.security', 'c' => 0, 'e' => 0]]]);
    expect($caught->json('channels.0.status'))->toBe('ok')
        ->and($caught->json('channels.1'))->toBe(['name' => 'estate.'.$site.'.security', 'status' => 'refused']);

    /* ── M6 (Q-M42): signing out, for real. LAST, because it destroys the token this whole walk holds ── */

    expect(asHandset($this, $t, 'auth.sign_out')->json('signed_out'))->toBeTrue();

    // And the token is gone from the server, not just from the phone — the whole point of the endpoint.
    app('auth')->forgetGuards();
    $this->withToken($t)->getJson('/api/v1/shifts/me/current')->assertUnauthorized();
    FacilitiesFixture::boot();

    /* ── And nothing on the guard's side of the catalogue went uncalled ── */

    $guardRoutes = collect(Catalogue::endpoints())->filter(fn (array $e) => in_array(AppMatrix::GUARD, $e['apps'], true))->keys()->sort()->values()->all();

    expect(collect(array_keys($GLOBALS['guardContractCalled']))->sort()->values()->all())->toBe($guardRoutes);
});

it('refuses a Resident App token on every guard-only route, at the ability', function () {
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'sms', 'destination' => '+18765550147',
        'full_name' => 'Andrea Fletcher', 'resident_id' => $this->resident->id, 'unit_id' => $this->unit->id,
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $token = $account->createToken('resident-app', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $guardOnly = collect(Catalogue::endpoints())->filter(fn (array $e) => $e['apps'] === [AppMatrix::GUARD]);

    expect($guardOnly->count())->toBeGreaterThan(30);

    foreach ($guardOnly as $name => $entry) {
        $uri = '/api/v1/'.preg_replace('/\{(\w+)\}/', '1', $entry['uri']);

        app('auth')->forgetGuards();
        $response = $this->withToken($token)->json($entry['method'], $uri, [], ['Idempotency-Key' => 'r-'.$name, 'X-Device-Time' => now()->toIso8601String()]);

        expect($response->status())->toBe(403, "[{$name}] answered a resident token with {$response->status()}")
            ->and($response->json('error.code'))->toBeIn(['missing_ability', 'wrong_app'], "[{$name}]");

        FacilitiesFixture::boot();
    }
});

it('counts night hours on the guard\'s own clock, not the server\'s', function () {
    // 7 PM to 7 AM, typed the way the office types it — ON THE SITE'S CLOCK (M4 §3.1). In Kingston that is
    // 00:00 to 12:00 UTC, which is what the row holds. The night window, 10 PM to 6 AM, is eight of those
    // hours; read on the UTC clock it would be six (00:00 to 06:00).
    $shift = Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $this->guard->id, 'post_id' => $this->post->id,
        'rostered_start' => '2026-09-01 19:00:00', 'rostered_end' => '2026-09-02 07:00:00',
        'actual_start' => '2026-09-01 19:00:00', 'actual_end' => '2026-09-02 07:00:00', 'status' => 'completed',
    ]);

    expect(DB::connection('mysql')->table('shifts')->where('id', $shift->id)->value('actual_start'))->toBe('2026-09-02 00:00:00');

    $mine = asHandset($this, $this->token, 'shifts.me', [], ['from' => '2026-09-01', 'to' => '2026-09-03']);

    expect((float) $mine->json('items.0.worked_hours'))->toBe(12.0)
        ->and((float) $mine->json('items.0.night_hours'))->toBe(8.0)
        ->and((float) $mine->json('totals.night_hours'))->toBe(8.0);
});

/*
|--------------------------------------------------------------------------
| THE ONE TIME RULE — M4 §3.1
|--------------------------------------------------------------------------
|
| The office types a shift ON THE SITE'S CLOCK, through the console's own roster
| routes. The row holds the UTC moment that means; the Guard App is sent that
| moment as a UTC instant, with the site's IANA zone beside it to show it in.
| Nothing anywhere knows "five hours": the second test moves the same estate to a
| zone that keeps daylight saving, and July's 07:00 lands on 11:00 UTC, not 12:00.
|
*/

/** A shift typed into the console for `$start`–`$end` (wall-clock strings), then given to the guard — the office's own two acts. */
function rosteredThroughTheConsole(TestCase $test, Post $post, Guard $guard, string $start, string $end): Shift
{
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $test->actingAs($director)
        ->post('/guards/roster/shifts', ['post_id' => $post->id, 'starts_at' => $start, 'ends_at' => $end])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $shift = Shift::query()->where('post_id', $post->id)->whereNull('guard_id')->latest('id')->firstOrFail();

    $test->actingAs($director)
        ->post('/guards/roster/shifts/'.$shift->id.'/assign', ['guard_id' => $guard->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    FacilitiesFixture::boot();

    return $shift->fresh();
}

it('stores a 07:00 Kingston shift as 12:00 UTC and sends the guard that instant with the site\'s zone', function () {
    $day = SiteTime::today(FacilitiesFixture::ESTATE)->addDay()->format('Y-m-d');

    $shift = rosteredThroughTheConsole($this, $this->post, $this->guard, $day.' 07:00', $day.' 19:00');

    // THE ROW: true UTC. 07:00 in Kingston is 12:00 UTC, 19:00 is midnight.
    $row = DB::connection('mysql')->table('shifts')->where('id', $shift->id)->first();

    expect($row->rostered_start)->toBe($day.' 12:00:00')
        ->and($row->rostered_end)->toBe(Carbon::parse($day)->addDay()->format('Y-m-d').' 00:00:00');

    // THE CONSOLE still reads the digits the office typed.
    expect($shift->rostered_start->format('Y-m-d g:i A'))->toBe($day.' 7:00 AM');

    // THE PHONE: the instant, in UTC, and the zone to show it in — on the dashboard, in the range and in the periodic pull.
    $current = asHandset($this, $this->token, 'shifts.me.current');

    expect($current->json('shift.id'))->toBe($shift->id)
        ->and($current->json('shift.rostered_start'))->toBe($day.'T12:00:00+00:00')
        ->and($current->json('shift.site.timezone'))->toBe('America/Jamaica');

    // 07:00 on the phone: the instant, shown in the zone the API names, is what the office typed.
    expect(Carbon::parse($current->json('shift.rostered_start'))->setTimezone($current->json('shift.site.timezone'))->format('H:i'))->toBe('07:00');

    $mine = asHandset($this, $this->token, 'shifts.me', [], ['from' => $day, 'to' => $day]);

    expect($mine->json('timezone'))->toBe('America/Jamaica')
        ->and($mine->json('from'))->toBe($day)
        ->and($mine->json('items.0.rostered_start'))->toBe($day.'T12:00:00+00:00')
        ->and($mine->json('items.0.site.timezone'))->toBe('America/Jamaica');

    $pull = asHandset($this, $this->token, 'sync.pull');

    expect($pull->json('timezone'))->toBe('America/Jamaica')
        ->and(collect($pull->json('shifts'))->firstWhere('id', $shift->id)['rostered_start'])->toBe($day.'T12:00:00+00:00');
});

it('reads the zone by NAME: the same 07:00 in a zone that keeps daylight saving is 11:00 UTC in July and 12:00 in January', function () {
    Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->forceFill(['timezone' => 'America/New_York'])->save();
    FacilitiesFixture::boot();

    try {
        /*
         * JULY AND JANUARY — the two halves of the zone's own year, an hour apart. A roster is
         * refused more than six months out ("a plan rather than a roster"), and no six months
         * hold both a July and a January, so these two are written through THE SAME WRITER the
         * console writes through — the `SiteDateTime` cast on the model — which is where the
         * zone is read. The console's own route is walked below, on a day inside its horizon.
         */
        $july = (now()->year + 1).'-07-15';
        $january = (now()->year + 1).'-01-15';

        $typed = fn (string $day): Shift => Shift::create([
            'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $this->guard->id, 'post_id' => $this->post->id,
            'rostered_start' => $day.' 07:00', 'rostered_end' => $day.' 19:00', 'status' => 'rostered',
        ]);

        $summer = $typed($july);
        $winter = $typed($january);

        expect(DB::connection('mysql')->table('shifts')->where('id', $summer->id)->value('rostered_start'))->toBe($july.' 11:00:00')
            ->and(DB::connection('mysql')->table('shifts')->where('id', $winter->id)->value('rostered_start'))->toBe($january.' 12:00:00');

        $mine = asHandset($this, $this->token, 'shifts.me', [], ['from' => $july, 'to' => $july]);

        expect($mine->json('timezone'))->toBe('America/New_York')
            ->and($mine->json('items.0.rostered_start'))->toBe($july.'T11:00:00+00:00')
            ->and($mine->json('items.0.site.timezone'))->toBe('America/New_York');

        /*
         * AND THE CONSOLE'S OWN PATH, on a day it accepts: the dispatcher types 07:00 and the row
         * holds the moment that is in NEW YORK — never Kingston's, and never a fixed five hours.
         */
        $soon = SiteTime::today(FacilitiesFixture::ESTATE)->addDays(30)->format('Y-m-d');
        $rostered = rosteredThroughTheConsole($this, $this->post, $this->guard, $soon.' 07:00', $soon.' 19:00');

        expect(DB::connection('mysql')->table('shifts')->where('id', $rostered->id)->value('rostered_start'))
            ->toBe(Carbon::parse($soon.' 07:00', 'America/New_York')->utc()->format('Y-m-d H:i:s'))
            ->and($rostered->rostered_start->format('Y-m-d g:i A'))->toBe($soon.' 7:00 AM');

        // The enrolment answer names the zone too — it is where the app first learns its site.
        expect(SiteTime::zone(FacilitiesFixture::ESTATE))->toBe('America/New_York');
    } finally {
        // The estate row is rolled back with the transaction; what was read of it is per-process.
        SiteTime::flush();
    }
});

it('names no money on any guard route but the guard\'s own payslip, in the catalogue itself', function () {
    foreach (Catalogue::endpoints() as $name => $entry) {
        if (! in_array(AppMatrix::GUARD, $entry['apps'], true) || $name === 'payslips.me') {
            continue;
        }

        foreach (array_keys($entry['response']) as $path) {
            expect(ApiContract::namesMoney($path))->toBeFalse("Invariant 2: [{$name}] documents `{$path}`.");
        }
    }

    // The scan itself: it catches money by word, and does not cry wolf on `allowed`.
    foreach (['balance', 'items.*.amount_due', 'arrears_bucket', 'total_minor', 'household.outstanding'] as $money) {
        expect(ApiContract::namesMoney($money))->toBeTrue($money);
    }

    foreach (['allowed', 'admit_allowed', 'access_restricted', 'checks.*.passed', 'break_minutes'] as $safe) {
        expect(ApiContract::namesMoney($safe))->toBeFalse($safe);
    }
});
