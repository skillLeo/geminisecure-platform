<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\AuditEntry;
use App\Models\DuressAlert;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Shift;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\AlertProgress;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| AN ALERT'S STATE IS DECIDED FROM THE ROW AS IT IS, UNDER ITS LOCK
| (bug hunt B013)
|--------------------------------------------------------------------------
|
|   B013  `AlertIntake::acknowledge()` and `resolve()` decided from the copy of the
|         alert the request had loaded and wrote over the row with it. A dispatcher's
|         Acknowledge landing a moment after a colleague's Dispatch put the alert
|         back to "acknowledged" and re-stamped who took it and when — "the evidence
|         of how long the person waited" — and two resolves both closed it.
|
|   B094  The handset's ten-second cancel checked the copy it had just read, took no
|         lock and never asked whether the alert was already closed. A cancel landing
|         after a dispatcher's resolve overwrote the outcome, the time and the note
|         with "false alarm"; one whose read predated an officer's "On my way" closed
|         an alert that officer was running to.
|
| They now do what `AlertProgress::step()` does: one transaction, the row read again
| under `lockForUpdate`, the decision taken from THAT row.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();
    config(['services.sms.driver' => 'log']);

    Shift::query()->where('tenant_id', $this->site)->delete();
    Guard::query()->where('tenant_id', $this->site)->update(['status' => 'inactive', 'device_id' => null]);
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    // One officer on shift, whom the panic reaches and a dispatcher may send.
    $post = Post::create(['tenant_id' => $this->site, 'name' => 'Lock Gate', 'type' => 'gate', 'is_active' => true]);
    $this->officer = Guard::create([
        'full_name' => 'Marcus Lock', 'employee_number' => 'GS-LCK-1', 'psra_number' => 'PSRA-GS-LCK-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->site, 'post_id' => $post->id,
    ]);
    $this->officerToken = app(DeviceEnrolment::class)->enrol($this->officer, 'Lock handset')['token'];

    $day = SiteTime::today($this->site);
    Shift::withoutEvents(fn (): Shift => Shift::create([
        'tenant_id' => $this->site, 'guard_id' => $this->officer->id, 'post_id' => $post->id,
        'rostered_start' => $day->copy()->setTime(0, 0), 'rostered_end' => $day->copy()->setTime(23, 59),
        'actual_start' => Carbon::now()->subHour(), 'status' => 'on_duty',
    ]));

    $unit = FacilitiesFixture::scratchUnit('Lot 519');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Lock household', 'access_restricted' => false]);
    $resident = Resident::query()->firstOrCreate(['household_id' => $household->id, 'full_name' => 'Rae Lock'], ['relationship' => 'owner', 'is_primary' => true]);

    $account = ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'rae.lock@example.com', 'full_name' => 'Rae Lock',
        'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->residentToken = $account->createToken('resident-app:rae.lock', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->first = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $this->second = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

function lockedDevice(TestCase $test, string $token, string $method, string $uri, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($method, '/api/v1/'.$uri, $body, [
        'Idempotency-Key' => 'lock-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

function lockedPanic(TestCase $test): DuressAlert
{
    $answer = lockedDevice($test, $test->residentToken, 'POST', 'duress', ['mode' => 'audible'])->assertCreated();

    return DuressAlert::query()->findOrFail($answer->json('id'));
}

function lockedAudits(DuressAlert $alert, string $action): int
{
    return AuditEntry::query()->where('action', $action)->where('entity_type', 'DuressAlert')->where('entity_id', (string) $alert->id)->count();
}

/* ── B013 · acknowledge and resolve ──────────────────────────────────── */

it('never steps a dispatched alert back to acknowledged from a copy loaded before the dispatch', function () {
    $alert = lockedPanic($this);
    $stale = DuressAlert::query()->findOrFail($alert->id);   // the Acknowledge request's copy, loaded first

    app(AlertProgress::class)->dispatch($alert, $this->officer, $this->first);
    $dispatched = $alert->fresh();

    $answered = app(AlertIntake::class)->acknowledge($stale, $this->second);

    $row = $alert->fresh();

    expect($row->status)->toBe(AlertProgress::RESPONDING)
        ->and($row->acknowledged_by)->toBe($this->first->id)
        ->and($row->acknowledged_at->equalTo($dispatched->acknowledged_at))->toBeTrue()
        ->and(lockedAudits($alert, 'alert.acknowledged'))->toBe(0)

        // …and the caller is answered with the row as it is, not the copy it came in with.
        ->and($answered->status)->toBe(AlertProgress::RESPONDING)
        ->and($answered->acknowledged_by)->toBe($this->first->id);
});

it('records the first of two overlapping acknowledgements and only the first', function () {
    $alert = lockedPanic($this);
    $one = DuressAlert::query()->findOrFail($alert->id);
    $two = DuressAlert::query()->findOrFail($alert->id);

    app(AlertIntake::class)->acknowledge($one, $this->first);
    $first = $alert->fresh();

    Carbon::setTestNow(Carbon::now()->addSeconds(4));
    app(AlertIntake::class)->acknowledge($two, $this->second);

    $row = $alert->fresh();

    expect($row->status)->toBe('acknowledged')
        ->and($row->acknowledged_by)->toBe($this->first->id)
        ->and($row->acknowledged_at->equalTo($first->acknowledged_at))->toBeTrue()
        ->and(lockedAudits($alert, 'alert.acknowledged'))->toBe(1);
});

it('closes an alert once when two resolves overlap, keeping the first note', function () {
    $alert = lockedPanic($this);
    $one = DuressAlert::query()->findOrFail($alert->id);
    $two = DuressAlert::query()->findOrFail($alert->id);

    app(AlertIntake::class)->resolve($one, $this->first, 'Resident safe — checked at the door.');
    $first = $alert->fresh();

    Carbon::setTestNow(Carbon::now()->addSeconds(4));
    app(AlertIntake::class)->resolve($two, $this->second, 'A second note');

    $row = $alert->fresh();

    expect($row->status)->toBe('resolved')
        ->and($row->resolution_note)->toBe('Resident safe — checked at the door.')
        ->and($row->resolved_at->equalTo($first->resolved_at))->toBeTrue()
        ->and(lockedAudits($alert, 'alert.resolved'))->toBe(1);
});

it('does not acknowledge an alert a colleague closed while the request was loading it', function () {
    $alert = lockedPanic($this);
    $stale = DuressAlert::query()->findOrFail($alert->id);

    app(AlertIntake::class)->resolve($alert, $this->first, 'Known test');
    app(AlertIntake::class)->acknowledge($stale, $this->second);

    $row = $alert->fresh();

    expect($row->status)->toBe('resolved')
        ->and($row->acknowledged_at)->toBeNull()
        ->and(lockedAudits($alert, 'alert.acknowledged'))->toBe(0);
});

it('keeps both through the console: an Acknowledge that lands after a Dispatch changes nothing', function () {
    $alert = lockedPanic($this);

    app(AlertProgress::class)->dispatch($alert, $this->officer, $this->first);

    app('auth')->forgetGuards();
    $this->actingAs($this->second)->from('/dispatch/alerts/'.$alert->id)->post('/dispatch/alerts/'.$alert->id.'/acknowledge')->assertRedirect();
    FacilitiesFixture::boot();

    expect($alert->fresh()->status)->toBe(AlertProgress::RESPONDING)
        ->and($alert->fresh()->acknowledged_by)->toBe($this->first->id);
});

/* ── B094 · the handset's ten-second cancel ──────────────────────────── */

function lockedCancel(TestCase $test, DuressAlert $alert, string $reason = 'Pressed it by mistake'): TestResponse
{
    return lockedDevice($test, $test->residentToken, 'POST', 'duress/'.$alert->id.'/cancel', ['reason' => $reason]);
}

it('leaves a dispatcher\'s resolution, its time and its note alone when the phone cancels inside the ten seconds', function () {
    $alert = lockedPanic($this);

    Carbon::setTestNow(Carbon::now()->addSeconds(5));
    app(AlertIntake::class)->resolve($alert, $this->first, 'Known test');
    $resolved = $alert->fresh();

    Carbon::setTestNow(Carbon::now()->addSeconds(3));   // 8 s after the press: the cancel is still offered
    $answer = lockedCancel($this, $alert)->assertOk();

    $row = $alert->fresh();

    expect($row->status)->toBe('resolved')
        ->and($row->resolution_note)->toBe('Known test')
        ->and($row->resolved_at->equalTo($resolved->resolved_at))->toBeTrue()
        ->and($row->getAttribute('cancelled_at'))->toBeNull()
        ->and($row->updated_at->equalTo($resolved->updated_at))->toBeTrue()

        // …and the phone is told how it really closed, so it says "closed", never "you cancelled".
        ->and($answer->json('status'))->toBe('resolved')
        ->and($answer->json('cancelled_at'))->toBeNull();
});

it('leaves a dispatcher\'s own false alarm as the dispatcher wrote it', function () {
    $alert = lockedPanic($this);

    app(AlertIntake::class)->resolve($alert, $this->first, 'Resident confirmed a test by phone.', falseAlarm: true);
    $closed = $alert->fresh();

    Carbon::setTestNow(Carbon::now()->addSeconds(4));
    lockedCancel($this, $alert)->assertOk()->assertJsonPath('status', 'false_alarm')->assertJsonPath('cancelled_at', null);

    $row = $alert->fresh();

    expect($row->resolution_note)->toBe('Resident confirmed a test by phone.')
        ->and($row->resolved_at->equalTo($closed->resolved_at))->toBeTrue()
        ->and($row->getAttribute('cancelled_at'))->toBeNull();
});

it('refuses a cancel whose read came a moment before an officer\'s "On my way", and leaves the officer coming', function () {
    $alert = lockedPanic($this);

    /*
     * THE RACE, MADE CERTAIN. The officer's handset commits "On my way" the moment the
     * cancel has read the row to find it — after that read, before its write.
     */
    $armed = true;
    DuressAlert::retrieved(function (DuressAlert $read) use (&$armed, $alert): void {
        if (! $armed || (int) $read->id !== (int) $alert->id) {
            return;
        }

        $armed = false;
        app(AlertProgress::class)->enRoute(DuressAlert::query()->findOrFail($alert->id), $this->officer);
    });

    lockedCancel($this, $alert)->assertStatus(409)->assertJsonPath('error.code', 'already_responding');
    $armed = false;

    $row = $alert->fresh();

    expect($row->status)->toBe(AlertProgress::RESPONDING)
        ->and($row->en_route_at)->not->toBeNull()
        ->and((int) $row->responding_guard_id)->toBe($this->officer->id)
        ->and($row->getAttribute('cancelled_at'))->toBeNull()
        ->and($row->resolved_at)->toBeNull();
});

it('refuses a cancel whose read came a moment before a dispatcher resolved it, and keeps the dispatcher\'s note', function () {
    $alert = lockedPanic($this);

    $armed = true;
    DuressAlert::retrieved(function (DuressAlert $read) use (&$armed, $alert): void {
        if (! $armed || (int) $read->id !== (int) $alert->id) {
            return;
        }

        $armed = false;
        app(AlertIntake::class)->resolve(DuressAlert::query()->findOrFail($alert->id), $this->first, 'Known test');
    });

    lockedCancel($this, $alert)->assertOk()->assertJsonPath('status', 'resolved');
    $armed = false;

    $row = $alert->fresh();

    expect($row->status)->toBe('resolved')
        ->and($row->resolution_note)->toBe('Known test')
        ->and($row->getAttribute('cancelled_at'))->toBeNull();
});

it('still cancels an alert nobody has touched, once, and answers a retry with the same cancel', function () {
    $alert = lockedPanic($this);

    Carbon::setTestNow(Carbon::now()->addSeconds(3));
    lockedCancel($this, $alert, 'Pressed it by mistake')->assertOk()->assertJsonPath('status', 'false_alarm');
    $cancelled = $alert->fresh();

    expect($cancelled->getAttribute('cancelled_at'))->not->toBeNull()
        ->and($cancelled->resolution_note)->toBe('Cancelled from the handset within 10 seconds. Reason given: Pressed it by mistake');

    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    lockedCancel($this, $alert, 'A second reason')->assertOk()->assertJsonPath('status', 'false_alarm');

    $row = $alert->fresh();

    expect($row->resolution_note)->toBe($cancelled->resolution_note)
        ->and($row->resolved_at->equalTo($cancelled->resolved_at))->toBeTrue();
});

it('still refuses a cancel after the ten seconds, and after the control room has taken the alert', function () {
    $late = lockedPanic($this);
    Carbon::setTestNow(Carbon::now()->addSeconds(11));
    lockedCancel($this, $late)->assertStatus(409)->assertJsonPath('error.code', 'cancel_window_closed');
    expect($late->fresh()->status)->toBe('open');

    Carbon::setTestNow();
    $taken = lockedPanic($this);
    app(AlertIntake::class)->acknowledge($taken, $this->first);
    lockedCancel($this, $taken)->assertStatus(409)->assertJsonPath('error.code', 'already_acknowledged');
    expect($taken->fresh()->status)->toBe('acknowledged');
});

