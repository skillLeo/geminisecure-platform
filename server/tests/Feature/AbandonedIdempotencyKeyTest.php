<?php

declare(strict_types=1);

use App\Api\IdempotencyStore;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\SecurityIncident;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A write whose first attempt died is not "still running" for a month — bug hunt B062, B135
|--------------------------------------------------------------------------
|
| The idempotency record is written, unanswered, BEFORE the write runs, and only
| the code after the write settles it. A worker that dies in between — an FPM
| reload in a deploy, "Maximum execution time exceeded", memory exhausted, a host
| restart, a database gone for longer than the request — never settles it. Every
| retry with that key was then told `409 request_in_progress`, however old the
| record, and the phone's outbox rightly treats that as "wait and retry": so the
| act stopped the queue, and every clock-out, scan and report behind it sat on the
| phone for the thirty days until the record was pruned.
|
| NOW, past the longest any request can run, the record is ABANDONED:
|
|   an act that de-duplicates on its own key (a panic or duress: `AlertIntake`
|   returns the alert already raised under that key) is simply run again — so the
|   phone gets its alert back if the first attempt raised it, and the alert is
|   raised if it did not;
|
|   anything else is answered the FINAL `idempotent_answer_missing`, which says
|   plainly that it is not known whether it went through. Running it again could do
|   it twice; answering "still running" would stop the queue for ever.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    Http::preventStrayRequests();
    $this->withoutMiddleware(ThrottleRequests::class);

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Abandoned Gate', 'type' => 'gate', 'is_active' => true]);
    $this->guard = Guard::create([
        'full_name' => 'Abandoned Probe', 'employee_number' => 'GS-ABN-1', 'psra_number' => 'PSRA-ABN-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $this->post->id,
    ]);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Abandoned handset')['token'];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function abandonedIncident(object $test, string $key): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($test->token)->postJson('/api/v1/incidents', [
        'kind' => 'Attempted unauthorized access', 'severity' => 'med',
        'detail' => 'A person tried the pedestrian gate twice and left towards the main road.', 'location' => 'Pedestrian gate',
    ], ['Idempotency-Key' => $key, 'X-Device-Time' => now()->toIso8601String()]);

    FacilitiesFixture::boot();

    return $response;
}

function abandonedDuress(object $test, string $key): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($test->token)->postJson('/api/v1/duress', ['mode' => 'silent'], [
        'Idempotency-Key' => $key, 'X-Device-Time' => now()->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

function abandonedRecord(string $key): object
{
    $row = DB::connection('mysql')->table(IdempotencyStore::CENTRAL_TABLE)->where('idempotency_key', $key)->first();

    expect($row)->not->toBeNull();

    return $row;
}

/**
 * The worker died after the act and before its answer was kept: the record is back to
 * "no answer yet", as it was when the act began, and has been for `$ago`.
 */
function abandonedWorker(string $key, Carbon $ago): void
{
    $row = abandonedRecord($key);

    DB::connection('mysql')->table(IdempotencyStore::CENTRAL_TABLE)->where('id', $row->id)
        ->update(['status_code' => null, 'body_store' => null, 'response_body' => null, 'created_at' => $ago, 'updated_at' => $ago]);

    config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => FacilitiesFixture::database()])]);
    DB::purge('tenant');
    DB::connection('tenant')->table(IdempotencyStore::TENANT_TABLE)->where('key_id', $row->id)->delete();
    FacilitiesFixture::boot();
}

it('answers a record abandoned mid-act with a FINAL refusal that says it is not known whether it went through, and does not do it twice', function () {
    abandonedIncident($this, 'abandoned-incident')->assertCreated();
    abandonedWorker('abandoned-incident', now()->subHour());

    $retry = abandonedIncident($this, 'abandoned-incident');

    // Not `request_in_progress`, which the outbox waits on for ever: a final answer the queue moves past.
    expect($retry->status())->toBe(409)
        ->and($retry->json('error.code'))->toBe('idempotent_answer_missing')
        ->and($retry->json('error.message'))->toContain('not known whether it went through')
        ->and(SecurityIncident::query()->where('guard_id', $this->guard->id)->count())->toBe(1);

    // And the same on every retry after it, until the first attempt's own answer, if it ever comes, is kept.
    expect(abandonedIncident($this, 'abandoned-incident')->json('error.code'))->toBe('idempotent_answer_missing');
});

it('still says "in progress" while the first attempt could genuinely be running', function () {
    abandonedIncident($this, 'running-incident')->assertCreated();
    abandonedWorker('running-incident', now()->subSeconds(20));

    $retry = abandonedIncident($this, 'running-incident');

    expect($retry->status())->toBe(409)
        ->and($retry->json('error.code'))->toBe('request_in_progress')
        ->and(SecurityIncident::query()->where('guard_id', $this->guard->id)->count())->toBe(1);
});

it('hands a duress back to the phone that raised it when the first attempt died after the alert was raised', function () {
    $first = abandonedDuress($this, 'abandoned-duress')->assertCreated();
    abandonedWorker('abandoned-duress', now()->subHour());

    $retry = abandonedDuress($this, 'abandoned-duress');

    // The alert the first attempt raised, not a second one — and now the phone has its id.
    expect($retry->status())->toBe(201)
        ->and($retry->json('id'))->toBe($first->json('id'))
        ->and(DuressAlert::query()->where('guard_id', $this->guard->id)->count())->toBe(1)
        ->and(abandonedRecord('abandoned-duress')->status_code)->toBe(201);

    // The record is answered again, so the next retry is a replay like any other.
    $again = abandonedDuress($this, 'abandoned-duress');

    expect($again->status())->toBe(201)
        ->and($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($again->json('id'))->toBe($first->json('id'));
});

it('raises the duress when the first attempt died before the alert existed', function () {
    // A record the same request made, left unanswered an hour ago with nothing behind it.
    abandonedDuress($this, 'duress-template')->assertCreated();
    $template = abandonedRecord('duress-template');

    DB::connection('mysql')->table(IdempotencyStore::CENTRAL_TABLE)->insert([
        'tokenable_type' => $template->tokenable_type, 'tokenable_id' => $template->tokenable_id, 'tenant_id' => $template->tenant_id,
        'idempotency_key' => 'duress-never-raised', 'route' => $template->route, 'request_hash' => $template->request_hash,
        'status_code' => null, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
    ]);

    $retry = abandonedDuress($this, 'duress-never-raised');

    expect($retry->status())->toBe(201)
        ->and(DuressAlert::query()->where('idempotency_key', 'duress-never-raised')->count())->toBe(1)
        ->and(DuressAlert::query()->where('guard_id', $this->guard->id)->count())->toBe(2);
});

it('lets one retry, and only one, take an abandoned record over', function () {
    abandonedDuress($this, 'claimed-once')->assertCreated();
    abandonedWorker('claimed-once', now()->subHour());

    $row = abandonedRecord('claimed-once');

    expect(IdempotencyStore::claimAbandoned($row))->toBeTrue()
        // The second is told the first is running — it is: the claim restarted the clock.
        ->and(IdempotencyStore::claimAbandoned($row))->toBeFalse()
        ->and(IdempotencyStore::isAbandoned(abandonedRecord('claimed-once')))->toBeFalse();
});
