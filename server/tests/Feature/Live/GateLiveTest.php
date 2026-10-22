<?php

declare(strict_types=1);

use App\Live\Channels;
use App\Live\Events\ApprovalLive;
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
| The gate and the household's visitors move every screen (M11 §2, DESIGN.md §2.9 I, D-169)
|--------------------------------------------------------------------------
|
| A guard admits a visitor: the estate's Security page, the Gemini console's Gate activity and every guard's gate
| list hear it (`gate.admit`, central); the pass is used and every gate handset refuses it from then on
| (`pass.used` on `estate.{t}.passes`); and the household's phones hear that their visitor is in
| (`pass.admitted`) — a RECURRING pass too, which until now recorded nothing on the household's side at all
| (§13.3 (1)). A pass the household cancels reaches every gate handset at once. A walk-up's answer reaches the
| guard waiting at the gate. Each event names the pass by its public pass id and nothing else: never its token or
| its code, never the visitor.
|
| Over the real API, as the handsets call it, with the estate open as ResolveDevice opens it.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->estate = FacilitiesFixture::ESTATE;
    $this->post = Post::create(['tenant_id' => $this->estate, 'name' => 'Live Gate', 'type' => 'gate', 'is_active' => true]);
    $this->guard = Guard::create([
        'full_name' => 'Delroy Live', 'employee_number' => 'GS-GLIVE-1', 'psra_number' => 'PSRA-GLIVE-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->estate, 'post_id' => $this->post->id,
    ]);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Live gate handset')['token'];

    $this->unit = FacilitiesFixture::scratchUnit('Lot 7911');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Live household', 'access_restricted' => false]);
    $this->household->forceFill(['access_restricted' => false])->save();
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Marcia Live'],
        ['relationship' => 'owner', 'is_primary' => true],
    );
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    // The estate database is committed and shared: take back what this file wrote to it.
    $ids = VisitorPass::query()->where('unit_id', $this->unit->id)->pluck('pass_id')->all();
    DB::connection('tenant')->table('live_events')->whereIn('entity_id', $ids)->delete();
    DB::connection('tenant')->table('live_events')->where('entity', 'approval')->whereIn('entity_id', DB::connection('tenant')->table('gate_approval_requests')->where('unit_id', $this->unit->id)->pluck('id')->map('strval')->all())->delete();
    DB::connection('tenant')->table('gate_approval_requests')->where('unit_id', $this->unit->id)->delete();
    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
});

/** A pass the household issued, inside the estate as the Resident App's request is. */
function gateLivePass(object $test, string $category = 'single'): VisitorPass
{
    FacilitiesFixture::boot();

    return tenancy()->find(FacilitiesFixture::ESTATE)->run(fn (): VisitorPass => app(VisitorPasses::class)->issue(FacilitiesFixture::ESTATE, $test->unit, $test->resident->id, 'Marcia Live', [
        'category' => $category, 'visitor_name' => 'Evening Caller', 'visitor_phone' => '876-555-0199',
        'valid_from' => now()->subHour(), 'valid_to' => now()->addHours(4),
    ]));
}

function gateLiveCall(TestCase $test, string $uri, array $body, ?string $key = null): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($test->token)->postJson($uri, $body, [
        'Idempotency-Key' => $key ?? 'glive-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

/** @return list<array{type: string, channels: list<string>, summary: mixed}> */
function estateEvents(string $entity, string $id): array
{
    FacilitiesFixture::boot();

    return DB::connection('tenant')->table('live_events')->where('entity', $entity)->where('entity_id', $id)->orderBy('id')
        ->get(['type', 'channels', 'summary'])
        ->map(static fn (object $r): array => ['type' => $r->type, 'channels' => json_decode((string) $r->channels, true), 'summary' => $r->summary])->all();
}

it('a single-use pass admitted: the visitor log moves on every screen, the pass is refused at every gate, and the household is told', function () {
    $pass = gateLivePass($this);

    $entry = gateLiveCall($this, '/api/v1/gate/entry', ['category' => 'Guest', 'pass_id' => $pass->pass_id]);
    $entry->assertCreated();

    $gate = DB::connection('mysql')->table('live_events')->where('entity', 'gate')->where('entity_id', (string) $entry->json('id'))->first();
    expect($gate?->type)->toBe('gate.admit')
        ->and(json_decode((string) $gate->channels, true))->toEqualCanonicalizing([
            Channels::estateFeed($this->estate, 'security'),
            'gemini.workforce',
            Channels::geminiFeed($this->estate, 'workforce'),
            Channels::estateGuards($this->estate),
        ]);

    $types = array_column(estateEvents('pass', $pass->pass_id), 'type');
    expect($types)->toBe(['pass.issued', 'pass.used', 'pass.admitted']);

    $used = estateEvents('pass', $pass->pass_id)[1];
    expect($used['channels'])->toContain(Channels::estatePasses($this->estate))
        ->and($used['channels'])->toContain(Channels::household($this->estate, $this->household->id));
});

it('a RECURRING pass admitted is recorded on the pass and told to the household — §13.3 (1)', function () {
    $pass = gateLivePass($this, 'recurring');

    gateLiveCall($this, '/api/v1/gate/entry', ['category' => 'Visitor', 'pass_id' => $pass->pass_id])->assertCreated();

    $fresh = VisitorPass::query()->where('pass_id', $pass->pass_id)->firstOrFail();
    expect($fresh->last_admitted_at)->not->toBeNull()
        ->and($fresh->status)->toBe(VisitorPass::ACTIVE)
        ->and(array_column(estateEvents('pass', $pass->pass_id), 'type'))->toBe(['pass.issued', 'pass.admitted']);

    // The resident's own read now says when.
    // (Visitors list shape: `last_admitted_at`, a UTC instant, catalogued for the app.)
});

it('an idempotent retry of the same entry records nothing new anywhere', function () {
    $pass = gateLivePass($this, 'recurring');
    $key = 'glive-retry-'.bin2hex(random_bytes(4));

    gateLiveCall($this, '/api/v1/gate/entry', ['category' => 'Visitor', 'pass_id' => $pass->pass_id], $key)->assertCreated();
    gateLiveCall($this, '/api/v1/gate/entry', ['category' => 'Visitor', 'pass_id' => $pass->pass_id], $key);

    expect(array_column(estateEvents('pass', $pass->pass_id), 'type'))->toBe(['pass.issued', 'pass.admitted'])
        ->and(DB::connection('mysql')->table('live_events')->where('entity', 'gate')->where('type', 'gate.admit')->where('tenant_id', $this->estate)->count())->toBe(1);
});

it('a pass the household cancels reaches every gate handset at once, named by its pass id alone', function () {
    $pass = gateLivePass($this, 'recurring');

    tenancy()->find($this->estate)->run(fn () => app(VisitorPasses::class)->cancel(VisitorPass::query()->where('pass_id', $pass->pass_id)->firstOrFail()));

    $cancelled = last(estateEvents('pass', $pass->pass_id));
    expect($cancelled['type'])->toBe('pass.cancelled')
        ->and($cancelled['channels'])->toContain(Channels::estatePasses($this->estate))
        ->and($cancelled['summary'])->toBeNull();

    // Nothing on the log could open a gate or name the visitor.
    $raw = json_encode(DB::connection('tenant')->table('live_events')->where('entity_id', $pass->pass_id)->get()->all());
    expect($raw)->not->toContain((string) $pass->token)
        ->and($raw)->not->toContain((string) $pass->code)
        ->and($raw)->not->toContain('Evening Caller')
        ->and($raw)->not->toContain('876-555-0199');
});

it('a walk-up asked at the gate reaches the household; the answer reaches the guard waiting at the gate', function () {
    $asked = gateLiveCall($this, '/api/v1/gate/approvals', ['unit' => $this->unit->reference, 'visitor_name' => 'Walk Up', 'id_type' => "Driver's licence", 'id_number' => 'D1234567', 'purpose' => 'Family visit', 'no_vehicle' => true]);
    $asked->assertCreated();
    $id = (string) $asked->json('approval_id');

    expect(array_column(estateEvents('approval', $id), 'type'))->toBe(['approval.requested'])
        ->and(estateEvents('approval', $id)[0]['channels'])->toBe([Channels::household($this->estate, $this->household->id)]);

    // A member answers (the resident's request, inside the estate).
    tenancy()->find($this->estate)->run(function () use ($id): void {
        DB::connection('tenant')->transaction(function () use ($id): void {
            DB::connection('tenant')->table('gate_approval_requests')->where('id', $id)->update(['status' => 'approved', 'responded_at' => now(), 'updated_at' => now()]);
            ApprovalLive::tell((int) $id, $this->household->id, 'answered');
        });
    });

    $answered = last(estateEvents('approval', $id));
    expect($answered['type'])->toBe('approval.answered')
        ->and($answered['channels'])->toContain(Channels::approval($this->estate, (int) $id));
});

it('a pass written outside any open estate (a seeder, a fixture) records nothing, and nothing fails', function () {
    FacilitiesFixture::boot();

    $pass = app(VisitorPasses::class)->issue($this->estate, $this->unit, $this->resident->id, 'Marcia Live', [
        'category' => 'single', 'visitor_name' => 'Seeded', 'valid_from' => Carbon::now(), 'valid_to' => Carbon::now()->addHour(),
    ]);

    expect(estateEvents('pass', $pass->pass_id))->toBe([]);
});
