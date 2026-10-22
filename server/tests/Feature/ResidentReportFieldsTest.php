<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Push\SendExpoPush;
use App\Models\DevicePushToken;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Resident;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Estate\Maintenance;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M4 §3.5 — the named fields on a resident's report
|--------------------------------------------------------------------------
|
|   COLUMNS       "Allow entry when I'm not home" and "Best time to come" were
|                 lines the phone appended to the description. They are columns:
|                 `access_permitted` (three-valued — null is "did not say") and
|                 `preferred_time`. A request captured on a handset BEFORE this
|                 build and synced after it carries the old shape, and is still
|                 accepted exactly as it was.
|   MAP PIN       a security report may carry where the resident was standing,
|                 only if they agreed to attach it. Half a coordinate, or one off
|                 the globe, is refused; no coordinates at all is ordinary.
|   HAPPENING NOW the guards ON DUTY at that site are told, and the ticket does
|                 not move: same owner, same status, no vendor, no technician.
|                 "Notification is not ownership."
|
| THE ESTATE DATABASE IS COMMITTED, so this file stands on a lot of its own
| (Phase 9 · Lot 985) and takes away every ticket it puts in.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // Push is off in the suite; the tests that want one turn it on. Nothing here reaches Expo.
    Http::preventStrayRequests();

    $this->site = FacilitiesFixture::ESTATE;

    $this->unit = FacilitiesFixture::scratchUnit('Lot 985', 'Phase 9');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Lot 985 household', 'access_restricted' => false]);
    $this->household->forceFill(['access_restricted' => false])->save();

    $this->resident = Resident::withRemoved()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Fields Andrea'],
        ['relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED],
    );
    $this->resident->forceFill(['removed_at' => null, 'is_primary' => true])->save();

    $this->account = ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'fields.'.bin2hex(random_bytes(4)).'@example.com',
        'status' => ResidentAccount::ACTIVE, 'full_name' => $this->resident->full_name,
        'unit_id' => $this->unit->id, 'resident_id' => $this->resident->id, 'verified_at' => now(),
    ]);
    $this->token = $this->account->createToken('resident-app:fields', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Fields Gate', 'type' => 'gate', 'is_active' => true]);

    $this->made = [];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    // What this file put into the committed estate database comes out again.
    DB::connection('tenant')->table('maintenance_ticket_activity')->whereIn('maintenance_ticket_id', $this->made)->delete();
    DB::connection('tenant')->table('maintenance_tickets')->whereIn('id', $this->made)->delete();
});

/* ── helpers (names of this file's own: Pest's top-level functions are global) ── */

/**
 * One call as a phone, held to its catalogue entry.
 *
 * @param  array<string, mixed>  $body
 */
function fieldsCall(TestCase $test, string $token, string $name, array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $headers = $entry['write'] ? ['Idempotency-Key' => 'fields-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();

    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson('/api/v1/'.$entry['uri'].($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $test->withToken($token)->json($entry['method'], '/api/v1/'.$entry['uri'], $body, $headers);

    $response->assertStatus($status ?? $entry['status']);

    if ($status === null || $status < 400) {
        ApiContract::assertMatches($response, $name);
    }

    FacilitiesFixture::boot();

    return $response;
}

/** A report filed by this file's resident; its id is remembered so afterEach can take it away. */
function fieldsReport(object $test, array $body): TestResponse
{
    $response = fieldsCall($test, $test->token, 'tickets.store', $body);
    $test->made[] = (int) $response->json('id');

    return $response;
}

function fieldsRow(int $id): MaintenanceTicket
{
    FacilitiesFixture::boot();

    return MaintenanceTicket::query()->findOrFail($id);
}

/** @return array{0: Guard, 1: string} a guard of this estate and their handset's API token */
function fieldsGuard(object $test, string $number): array
{
    $guard = Guard::create([
        'full_name' => 'Officer '.$number, 'employee_number' => $number, 'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $test->site, 'post_id' => $test->post->id,
    ]);

    return [$guard, app(DeviceEnrolment::class)->enrol($guard, 'Fields handset '.$number)['token']];
}

function fieldsOnDuty(object $test, Guard $guard): Shift
{
    return Shift::create([
        'tenant_id' => $test->site, 'guard_id' => $guard->id, 'post_id' => $test->post->id,
        'rostered_start' => now()->subHour(), 'rostered_end' => now()->addHours(11),
        'actual_start' => now()->subHour(), 'status' => 'on_duty',
    ]);
}

function fieldsPushAddress(object $test, Guard $guard, string $seed): DevicePushToken
{
    return DevicePushToken::query()->create([
        'tenant_id' => $test->site, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => $guard->id, 'app' => AppMatrix::GUARD,
        'expo_token' => 'ExponentPushToken['.substr(str_pad($seed.'xxxxxxxxxxxxxxxxxxxxxx', 22, 'x'), 0, 22).']',
        'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
    ]);
}

/* ── A. the two named fields ─────────────────────────────────────────── */

it('takes "allow entry" and "best time" as columns, shows them to the office, and still accepts a request queued in the OLD shape', function () {
    $fresh = fieldsReport($this, [
        'title' => 'Kitchen tap drips all night',
        'category' => 'plumbing',
        'description' => 'It has been going since Sunday.',
        'priority' => 'medium',
        'access_permitted' => true,
        'preferred_time' => 'Weekday mornings',
    ]);

    // Returned by name, not buried in the description.
    expect($fresh->json('access_permitted'))->toBeTrue()
        ->and($fresh->json('preferred_time'))->toBe('Weekday mornings')
        ->and($fresh->json('description'))->toBe('It has been going since Sunday.')
        ->and($fresh->json('description'))->not->toContain('Notes to the office');

    // …and stored as what they are.
    $row = fieldsRow((int) $fresh->json('id'));
    expect($row->access_permitted)->toBeTrue()->and($row->preferred_time)->toBe('Weekday mornings');

    // The office's own screen says it, in the words board 18 prints beside the meta line.
    expect(app(Maintenance::class)->ticketBoard($row)['ticket']['access'])->toBe('Entry with nobody home: yes · Best time: Weekday mornings');

    /*
     * A REQUEST CAPTURED BEFORE THIS BUILD AND SYNCED AFTER IT. The phone wrote the
     * two answers into the description as labelled lines and sent nothing else. It is
     * accepted exactly as it always was, its words untouched, and the columns stay
     * null — the request did not say, which is not the same as "no".
     */
    $old = fieldsReport($this, [
        'title' => 'Bathroom light flickers',
        'category' => 'electrical',
        'description' => "Started this week.\n\nNotes to the office:\n- Entry when I'm not home: allowed\n- Best time to come: after 4",
        'priority' => 'low',
    ]);

    $oldRow = fieldsRow((int) $old->json('id'));

    expect($old->json('access_permitted'))->toBeNull()
        ->and($old->json('preferred_time'))->toBeNull()
        ->and($old->json('description'))->toContain('Best time to come: after 4')
        ->and($oldRow->access_permitted)->toBeNull()
        ->and($oldRow->preferred_time)->toBeNull()
        // A ticket that said nothing about entry is not one that said no: the office is told nothing.
        ->and(app(Maintenance::class)->ticketBoard($oldRow)['ticket']['access'])->toBeNull();

    // "No" is a third answer, and it is kept as one.
    $refused = fieldsReport($this, ['title' => 'Gate remote will not pair', 'category' => 'other', 'description' => 'It will not pair since the battery change.', 'access_permitted' => false, 'priority' => 'low']);
    expect($refused->json('access_permitted'))->toBeFalse()
        ->and(app(Maintenance::class)->ticketBoard(fieldsRow((int) $refused->json('id')))['ticket']['access'])->toBe('Entry with nobody home: no');

    // Both come back in the household's list, by name.
    $listed = collect(fieldsCall($this, $this->token, 'tickets.index')->json('items'))->firstWhere('id', $fresh->json('id'));
    expect($listed['access_permitted'])->toBeTrue()->and($listed['preferred_time'])->toBe('Weekday mornings');

    // A preferred time longer than the form's own line is refused, rather than silently cut.
    fieldsCall($this, $this->token, 'tickets.store', ['title' => 'Too much to say', 'preferred_time' => str_repeat('a', 81)], 422);
});

/* ── B. the map pin ──────────────────────────────────────────────────── */

it('stores where the resident was, only when they attached it, and refuses half a coordinate', function () {
    $with = fieldsReport($this, [
        'title' => 'A car nobody knows by the playground',
        'category' => 'security',
        'description' => 'Dark saloon, engine running.',
        'priority' => 'high',
        'latitude' => 18.0179123,
        'longitude' => -76.8099456,
        'accuracy_m' => 12,
    ]);

    $row = fieldsRow((int) $with->json('id'));
    expect((float) $row->latitude)->toBe(18.0179123)
        ->and((float) $row->longitude)->toBe(-76.8099456)
        ->and($row->location_accuracy_m)->toBe(12);

    // REFUSING NEVER BLOCKS THE REPORT: no coordinates is an ordinary report, filed the same way.
    $without = fieldsReport($this, ['title' => 'Someone tried the back gate', 'category' => 'security', 'description' => 'A man rattling the lock, then gone.', 'priority' => 'medium']);
    $bare = fieldsRow((int) $without->json('id'));
    expect($bare->latitude)->toBeNull()->and($bare->longitude)->toBeNull()->and($bare->location_accuracy_m)->toBeNull()
        ->and($without->json('status'))->toBe('submitted');

    // Half a coordinate is not a place, and neither is a point off the globe.
    fieldsCall($this, $this->token, 'tickets.store', ['title' => 'Half a fix', 'latitude' => 18.0179123], 422);
    fieldsCall($this, $this->token, 'tickets.store', ['title' => 'Half a fix', 'longitude' => -76.8099456], 422);
    fieldsCall($this, $this->token, 'tickets.store', ['title' => 'Off the globe', 'latitude' => 91, 'longitude' => 0], 422);
    fieldsCall($this, $this->token, 'tickets.store', ['title' => 'Off the globe', 'latitude' => 0, 'longitude' => 181], 422);
});

/* ── C. happening now ────────────────────────────────────────────────── */

it('tells the guards ON DUTY at that site what a resident says is happening now — and moves nothing about the ticket', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300, 'push.receipt_delay_seconds' => 0]);
    Bus::fake([SendExpoPush::class]);

    [$onDutyGuard] = fieldsGuard($this, 'GS-FLD-1');
    [$offDutyGuard] = fieldsGuard($this, 'GS-FLD-2');
    fieldsOnDuty($this, $onDutyGuard);

    $onDutyPhone = fieldsPushAddress($this, $onDutyGuard, 'fldonduty');
    fieldsPushAddress($this, $offDutyGuard, 'fldoffduty');

    $report = fieldsReport($this, [
        'title' => 'Two men climbing the back wall',
        'category' => 'security',
        'description' => 'By the transformer, heading towards the pool.',
        'location' => 'Back wall by the pool',
        'priority' => 'high',
        'latitude' => 18.0179123,
        'longitude' => -76.8099456,
        'accuracy_m' => 9,
        'happening_now' => true,
    ]);

    $id = (int) $report->json('id');

    /*
     * ONE JOB, to the guard who is clocked on. Not the one who is off duty — a guard
     * at home is not on duty at that site — and the words name a PLACE, never the
     * household that sent it.
     */
    Bus::assertDispatchedTimes(SendExpoPush::class, 1);
    Bus::assertDispatched(SendExpoPush::class, function (SendExpoPush $job) use ($onDutyPhone, $id): bool {
        return $job->tokenIds === [$onDutyPhone->id]
            && $job->data === ['type' => 'resident_report', 'id' => (string) $id]
            && $job->urgent === false
            && str_contains($job->body, 'Back wall by the pool')
            && ! str_contains($job->title.$job->body, 'Andrea');
    });

    /*
     * NOTIFICATION IS NOT OWNERSHIP. The ticket is where it was: the estate office's
     * queue, submitted, unassigned, nobody's technician, reported BY the resident.
     */
    $row = fieldsRow($id);
    expect($row->status)->toBe(MaintenanceTicket::SUBMITTED)
        ->and($row->assigned_vendor_id)->toBeNull()
        ->and($row->assigned_at)->toBeNull()
        ->and($row->technician_name)->toBeNull()
        ->and($row->unit_id)->toBe($this->unit->id)
        ->and($row->reported_by_name)->toBe('Fields Andrea')
        ->and($row->happening_now)->toBeTrue()
        ->and(DB::connection('tenant')->table('maintenance_ticket_activity')->where('maintenance_ticket_id', $id)->pluck('event')->all())->toBe(['reported']);

    /* ── and it reaches the open app through sync.pull ── */

    [$onDutyGuardB, $onDutyToken] = fieldsGuard($this, 'GS-FLD-3');
    fieldsOnDuty($this, $onDutyGuardB);
    [, $offDutyToken] = fieldsGuard($this, 'GS-FLD-4');

    $pull = fieldsCall($this, $onDutyToken, 'sync.pull', ['since' => now()->subHour()->toIso8601String()]);
    $mine = collect($pull->json('resident_reports'))->firstWhere('report_id', $id);

    expect($mine)->not->toBeNull()
        ->and($mine['title'])->toBe('Two men climbing the back wall')
        ->and($mine['location'])->toBe('Back wall by the pool')
        ->and($mine['detail'])->toContain('transformer')
        ->and($mine['latitude'])->toBe(18.0179123)
        ->and($mine['longitude'])->toBe(-76.8099456)
        ->and($mine['accuracy_m'])->toBe(9)
        ->and($mine['reported_at'])->toEndWith('+00:00');

    // Off duty: nothing. The delta is for whoever is standing the post.
    expect(fieldsCall($this, $offDutyToken, 'sync.pull', ['since' => now()->subHour()->toIso8601String()])->json('resident_reports'))->toBe([]);

    // A report the resident did NOT mark is not an alert: no push, and nothing in the pull.
    Bus::fake([SendExpoPush::class]);
    $quiet = fieldsReport($this, ['title' => 'Broken slat on the pool gate', 'category' => 'security', 'description' => 'One slat hanging loose.', 'priority' => 'low']);
    Bus::assertNotDispatched(SendExpoPush::class);

    $again = fieldsCall($this, $onDutyToken, 'sync.pull', ['since' => now()->subHour()->toIso8601String()])->json('resident_reports');
    expect(collect($again)->pluck('report_id'))->toContain($id)->not->toContain((int) $quiet->json('id'));
});
