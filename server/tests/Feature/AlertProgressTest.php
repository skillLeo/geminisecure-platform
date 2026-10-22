<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Push\SendExpoPush;
use App\Models\AuditEntry;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| THE HONEST STATE, END TO END — M10 §1.3
|--------------------------------------------------------------------------
|
| "The resident's screen still shows the honest state: alerting → acknowledged →
| dispatched → en route → on scene → resolved."
|
| The row could say three of those six. `responding` was listed and never written,
| the dispatcher's timeline drew "<guard> has not yet confirmed response" — a step
| nothing could complete — and the acknowledgement push told a resident "Help is on
| the way" when all that had happened was a button in the control room.
|
| This file walks every step over HTTP, from the console and from a guard's handset:
|
|   1  the dispatcher sends an officer from the alert's own reach; it is audited, the
|      officer's handset is pushed "You are dispatched to Lot X, <estate>", and the
|      resident is pushed "An officer has been dispatched"
|   2  en route and on scene, recorded by radio on the officer's behalf, each saying so
|   3  the officer's own handset: the officer sent, or any officer the alert reached
|      when nobody was sent — and nobody else, and never at another estate
|   4  forward only, and nothing after resolution, each refusal a sentence
|   5  the resident's read-back gains the times and loses nothing
|   6  the acknowledgement push says only what is true
|
| NO TEST REACHES THE NETWORK. Push is off in the suite; each test that wants one
| turns it on and fakes the bus, and `Http::preventStrayRequests()` fails anything
| nobody faked. The central database runs inside a transaction with the framework's
| TESTING transaction manager, so "after commit" pushes fire here as in production.
|
*/

const PROGRESS_OTHER_ESTATE = 'progressother';

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

    /*
     * A BARE SITE, asserted rather than assumed — `PanicFallbackTest`'s rule. The estate's
     * central rows are shared and the suite is shuffled, so an officer another file left
     * clocked on here would join the dispatch list and every "exactly these officers"
     * below would pass or fail for somebody else's reason.
     */
    Shift::query()->where('tenant_id', $this->site)->delete();
    Guard::query()->where('tenant_id', $this->site)->update(['status' => 'inactive', 'device_id' => null]);
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);

    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Progress Gate', 'type' => 'gate', 'is_active' => true]);

    Tenant::withoutEvents(function (): void {
        (new Tenant)->forceFill(['id' => PROGRESS_OTHER_ESTATE, 'name' => 'Ocean View (progress test)', 'receipt_prefix' => 'PGO', 'status' => 'active'])->save();
    });

    $this->unit = FacilitiesFixture::scratchUnit('Lot 734');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Progress household', 'access_restricted' => false]);
    $this->resident = Tests\Support\HouseholdHolder::of($this->household, 'Rae Progress');

    // Two officers the panic reaches — one clocked on, one rostered later today — and two it does not.
    $day = SiteTime::today($this->site);

    [$this->onDuty, $this->onDutyToken] = progressGuard($this->site, 'GS-PRG-1', 'Marcus Reid', $this->post->id);
    progressShift($this->onDuty, $this->post->id, $day->copy()->setTime(6, 0), $day->copy()->setTime(18, 0), Carbon::now()->subHour());

    [$this->rostered, $this->rosteredToken] = progressGuard($this->site, 'GS-PRG-2', 'Andre Bailey', $this->post->id);
    progressShift($this->rostered, $this->post->id, $day->copy()->setTime(18, 0), $day->copy()->setTime(23, 30));

    [$this->offShift, $this->offShiftToken] = progressGuard($this->site, 'GS-PRG-3', 'Paul Offshift', $this->post->id);

    $otherPost = Post::create(['tenant_id' => PROGRESS_OTHER_ESTATE, 'name' => 'Other Gate', 'type' => 'gate', 'is_active' => true]);
    [$this->elsewhere, $this->elsewhereToken] = progressGuard(PROGRESS_OTHER_ESTATE, 'GS-PRG-4', 'Owen Elsewhere', $otherPost->id);
    progressShift($this->elsewhere, $otherPost->id, $day->copy()->setTime(6, 0), $day->copy()->setTime(18, 0), Carbon::now()->subHour());

    [$this->account, $this->residentToken] = progressResident($this->site, 'progress.panic@example.com', $this->unit->id, $this->resident->id);

    $this->dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/* ── helpers ─────────────────────────────────────────────────────────── */

/** @return array{0: Guard, 1: string} the officer and their handset's API token */
function progressGuard(string $tenant, string $number, string $name, int $postId): array
{
    $guard = Guard::create([
        'full_name' => $name, 'employee_number' => $number, 'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $tenant, 'post_id' => $postId,
    ]);

    return [$guard, app(DeviceEnrolment::class)->enrol($guard, 'Progress handset '.$number)['token']];
}

/** A shift, without events: a roster change pushes the guard "your roster changed", which is not what this file counts. */
function progressShift(Guard $guard, int $postId, Carbon $start, Carbon $end, ?Carbon $clockedInAt = null): Shift
{
    return Shift::withoutEvents(static fn (): Shift => Shift::create([
        'tenant_id' => $guard->tenant_id, 'guard_id' => $guard->id, 'post_id' => $postId,
        'rostered_start' => $start, 'rostered_end' => $end, 'actual_start' => $clockedInAt,
        'status' => $clockedInAt === null ? Shift::ROSTERED : 'on_duty',
    ]));
}

/** @return array{0: ResidentAccount, 1: string} */
function progressResident(string $tenant, string $email, int $unitId, int $residentId): array
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => $tenant, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Rae Progress',
        'resident_id' => $residentId, 'unit_id' => $unitId, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$account, $account->createToken('resident-app:'.$email, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

function progressPhone(string $tenant, string $type, int $principalId, string $seed): DevicePushToken
{
    return DevicePushToken::query()->create([
        'tenant_id' => $tenant, 'principal_type' => $type, 'principal_id' => $principalId, 'app' => $type,
        'access_token_id' => null, 'expo_token' => 'ExponentPushToken['.substr(str_pad($seed, 22, 'x'), 0, 22).']',
        'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
    ]);
}

/** One call as a handset, held to its catalogue entry. */
function progressCall(TestCase $test, string $token, string $name, array $path = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $path[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'progress-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($entry['method'], '/api/v1/'.$uri, $body, $headers);
    $response->assertStatus($status ?? $entry['status']);

    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** A console act, as the dispatcher, from the alert's own screen. */
function progressConsole(TestCase $test, User $user, DuressAlert $alert, string $act, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    $screen = '/dispatch/alerts/'.$alert->id;
    $response = $test->actingAs($user)->from($screen)->post($screen.'/'.$act, $body);

    FacilitiesFixture::boot();

    return $response;
}

/** The sentence a console act was refused with, or null. */
function progressRefusal(): ?string
{
    $errors = app('session.store')->get('errors');

    // A ViewErrorBag, or — the session serialises as JSON here — the array it was written down as.
    $first = match (true) {
        $errors instanceof \Illuminate\Support\ViewErrorBag => $errors->first('progress'),
        is_array($errors) => $errors['default']['messages']['progress'][0] ?? null,
        default => null,
    };

    return is_string($first) && $first !== '' ? $first : null;
}

/** The panic screen's props, as the dispatcher sees them. */
function progressScreen(TestCase $test, User $user, DuressAlert $alert): array
{
    app('auth')->forgetGuards();

    $response = $test->actingAs($user)->get('/dispatch/alerts/'.$alert->id)->assertOk();
    FacilitiesFixture::boot();

    return $response->viewData('page')['props'];
}

/** A resident presses panic, through the Resident App's own endpoint. */
function progressPanic(TestCase $test): DuressAlert
{
    $answer = progressCall($test, $test->residentToken, 'duress.store', body: ['mode' => 'audible']);

    return DuressAlert::query()->findOrFail($answer->json('id'));
}

/** @return list<AuditEntry> */
function progressAudit(DuressAlert $alert, string $action): array
{
    return AuditEntry::query()->where('action', $action)->where('entity_type', 'DuressAlert')->where('entity_id', (string) $alert->id)->orderBy('id')->get()->all();
}

/* ── 1 · the dispatcher sends an officer ─────────────────────────────── */

it('dispatches an officer the panic reached: who and when on the row, status responding, audited, and both phones told', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300]);
    Bus::fake([SendExpoPush::class]);

    $officerPhone = progressPhone($this->site, DevicePushToken::GUARD, $this->onDuty->id, 'prgofficer');
    $residentPhone = progressPhone($this->site, DevicePushToken::RESIDENT, $this->account->id, 'prgresident');

    $alert = progressPanic($this);

    // THE PICK LIST IS THE PANIC'S OWN REACH: clocked on, and rostered today. Nobody else.
    $screen = progressScreen($this, $this->dispatcher, $alert);
    $offered = array_column($screen['actions']['dispatch_candidates'], 'guard_id');

    expect($offered)->toEqualCanonicalizing([$this->onDuty->id, $this->rostered->id])
        ->and($screen['actions']['can_dispatch'])->toBeTrue()
        ->and(collect($screen['timeline'])->pluck('meta')->implode(' | '))->not->toContain('has not yet confirmed response');

    progressConsole($this, $this->dispatcher, $alert, 'dispatch', ['guard_id' => $this->onDuty->id])
        ->assertRedirect('/dispatch/alerts/'.$alert->id)
        ->assertSessionHasNoErrors();

    $alert->refresh();

    expect($alert->status)->toBe('responding')
        ->and($alert->dispatched_at)->not->toBeNull()
        ->and((int) $alert->dispatched_by)->toBe($this->dispatcher->id)
        ->and((int) $alert->responding_guard_id)->toBe($this->onDuty->id)
        // Sending an officer IS the control room taking the alert: acknowledged by the same act.
        ->and($alert->acknowledged_at)->not->toBeNull()
        ->and((int) $alert->acknowledged_by)->toBe($this->dispatcher->id);

    // AUDITED: actor, before, after, when.
    $entries = progressAudit($alert, 'alert.dispatched');
    expect($entries)->toHaveCount(1);

    $entry = $entries[0];
    expect((int) $entry->actor_id)->toBe($this->dispatcher->id)
        ->and($entry->actor_name)->toBe($this->dispatcher->name)
        ->and($entry->tenant_id)->toBe($this->site)
        ->and($entry->before['status'])->toBe('open')
        ->and($entry->before['dispatched_at'])->toBeNull()
        ->and($entry->after['status'])->toBe('responding')
        ->and((int) $entry->after['responding_guard_id'])->toBe($this->onDuty->id)
        ->and($entry->after['officer'])->toBe('Marcus Reid')
        ->and($entry->after['dispatched_at'])->toBe(SiteTime::iso($alert->dispatched_at))
        ->and($entry->created_at)->not->toBeNull();

    // THE OFFICER SENT: full priority, the type every handset routes, where and never who.
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$officerPhone->id]
        && $job->data === ['type' => 'site_alert', 'id' => (string) $alert->id, 'kind' => 'dispatched']
        && $job->urgent === true
        && $job->title === 'You are dispatched'
        && str_contains($job->body, 'You are dispatched to Lot 734, Phoenix Park.')
        && ! str_contains($job->title.$job->body, 'Rae'));

    // THE PERSON WHO PRESSED IT: the first push that may say help is on the way.
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$residentPhone->id]
        && $job->data === ['type' => 'panic_update', 'id' => (string) $alert->id, 'kind' => 'dispatched']
        && $job->title === 'An officer has been dispatched'
        && str_contains($job->body, 'Help is on the way')
        && $job->urgent);

    // The screen now says who was sent, by whom, and stops offering to send anybody.
    $after = progressScreen($this, $this->dispatcher, $alert);
    $titles = collect($after['timeline'])->pluck('title')->all();

    expect($titles)->toContain('Dispatched Marcus Reid')
        ->and(collect($after['timeline'])->firstWhere('title', 'Dispatched Marcus Reid')['meta'])->toContain('Sent by '.$this->dispatcher->name)
        ->and($after['actions']['can_dispatch'])->toBeFalse()
        ->and($after['actions']['can_en_route'])->toBeTrue()
        // The button is the verb of the Emergency list's state (Hassam, 11 Oct): "Mark on the way", never "en route".
        ->and($after['actions']['en_route_label'])->toBe('Mark Marcus on the way');
});

it('says DRILL on a drill\'s dispatch push, on the raiser\'s update and on the officer\'s own read of it', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300]);
    Bus::fake([SendExpoPush::class]);

    $officerPhone = progressPhone($this->site, DevicePushToken::GUARD, $this->onDuty->id, 'prgdrillofficer');
    $residentPhone = progressPhone($this->site, DevicePushToken::RESIDENT, $this->account->id, 'prgdrillresident');

    $alert = progressPanic($this);
    $alert->forceFill(['is_drill' => true])->save();

    progressConsole($this, $this->dispatcher, $alert, 'dispatch', ['guard_id' => $this->onDuty->id])
        ->assertSessionHasNoErrors();

    // An officer opened from "You are dispatched" learns it is a drill before they run.
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$officerPhone->id]
        && $job->data['kind'] === 'dispatched'
        && $job->title === 'DRILL: You are dispatched');

    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$residentPhone->id]
        && $job->data['kind'] === 'dispatched'
        && $job->title === 'DRILL: An officer has been dispatched');

    $read = progressCall($this, $this->onDutyToken, 'alerts.show', ['alert' => $alert->id]);

    expect($read->json('is_drill'))->toBeTrue();
});

it('records en route and on scene from the console on the officer\'s behalf, each saying who recorded it', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300]);
    Bus::fake([SendExpoPush::class]);

    $residentPhone = progressPhone($this->site, DevicePushToken::RESIDENT, $this->account->id, 'prgradio');
    $alert = progressPanic($this);

    progressConsole($this, $this->dispatcher, $alert, 'dispatch', ['guard_id' => $this->rostered->id])->assertSessionHasNoErrors();
    progressConsole($this, $this->dispatcher, $alert, 'en-route')->assertSessionHasNoErrors();

    $alert->refresh();
    expect($alert->en_route_at)->not->toBeNull()
        ->and((int) $alert->en_route_recorded_by)->toBe($this->dispatcher->id)
        ->and($alert->on_scene_at)->toBeNull();

    [$enRoute] = progressAudit($alert, 'alert.en_route');
    expect((int) $enRoute->actor_id)->toBe($this->dispatcher->id)
        ->and($enRoute->after['recorded_via'])->toBe('console')
        ->and($enRoute->after['recorded_by'])->toBe($this->dispatcher->name)
        ->and($enRoute->after['officer'])->toBe('Andre Bailey')
        ->and($enRoute->before['en_route_at'])->toBeNull();

    progressConsole($this, $this->dispatcher, $alert, 'on-scene')->assertSessionHasNoErrors();

    $alert->refresh();
    expect($alert->on_scene_at)->not->toBeNull()
        ->and((int) $alert->on_scene_recorded_by)->toBe($this->dispatcher->id)
        ->and(progressAudit($alert, 'alert.on_scene'))->toHaveCount(1);

    // Each step, told to the resident in its own words.
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$residentPhone->id] && $job->data['kind'] === 'en_route' && $job->title === 'An officer is on the way');
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$residentPhone->id] && $job->data['kind'] === 'on_scene' && $job->title === 'An officer has arrived');

    // THE TIMELINE SAYS A RADIO CALL, never the officer's own handset.
    $timeline = collect(progressScreen($this, $this->dispatcher, $alert)['timeline'])->keyBy('title');

    expect($timeline['Andre Bailey on the way']['meta'])->toContain('Recorded by '.$this->dispatcher->name.' after a radio call')
        // The Emergency list's word for the state (Hassam, 11 Oct): On scene, never "at the scene".
        ->and($timeline['Andre Bailey on scene']['meta'])->toContain('Recorded by '.$this->dispatcher->name.' after a radio call')
        ->and($timeline['Andre Bailey on scene']['pending'])->toBeFalse();
});

/* ── 2 · forward only, and nothing after resolution ──────────────────── */

it('refuses every step backwards and every step after resolution, each with its own sentence', function () {
    $alert = progressPanic($this);

    // Nobody sent: a radio call is recorded FOR somebody.
    progressConsole($this, $this->dispatcher, $alert, 'en-route');
    expect(progressRefusal())->toStartWith('Dispatch an officer first');

    // An officer the panic never reached, at another client.
    progressConsole($this, $this->dispatcher, $alert, 'dispatch', ['guard_id' => $this->elsewhere->id]);
    expect(progressRefusal())->toBe('Owen Elsewhere is not on shift at Phoenix Park, so this alert never reached them. Pick an officer it did reach.');

    // …or at this estate but off shift.
    progressConsole($this, $this->dispatcher, $alert, 'dispatch', ['guard_id' => $this->offShift->id]);
    expect(progressRefusal())->toContain('Paul Offshift is not on shift');
    expect($alert->refresh()->dispatched_at)->toBeNull();

    progressConsole($this, $this->dispatcher, $alert, 'dispatch', ['guard_id' => $this->onDuty->id])->assertSessionHasNoErrors();

    // A SECOND DISPATCH is a step backwards: one alert, one dispatched officer.
    progressConsole($this, $this->dispatcher, $alert, 'dispatch', ['guard_id' => $this->rostered->id]);
    expect(progressRefusal())->toStartWith('Marcus Reid was already dispatched to this alert at ');
    expect((int) $alert->refresh()->responding_guard_id)->toBe($this->onDuty->id);

    // ON SCENE FIRST — an officer who ran straight there — and then "en route" is behind it.
    progressConsole($this, $this->dispatcher, $alert, 'on-scene')->assertSessionHasNoErrors();
    progressConsole($this, $this->dispatcher, $alert, 'en-route');
    expect(progressRefusal())->toContain('already recorded at the scene')
        ->and(progressRefusal())->toContain('the response only moves forward');

    progressConsole($this, $this->dispatcher, $alert, 'on-scene');
    expect(progressRefusal())->toStartWith('Marcus Reid was already recorded at the scene at ');

    // Skipped, not back-filled: the timeline says en route was never recorded.
    $alert->refresh();
    expect($alert->en_route_at)->toBeNull();
    $pending = collect(progressScreen($this, $this->dispatcher, $alert)['timeline'])->firstWhere('title', 'Not yet on the way');
    expect($pending['pending'])->toBeTrue()
        ->and($pending['meta'])->toBe('Not recorded: Marcus Reid was marked on scene without it');

    // RESOLVED: nothing more can be recorded, and the sentence says why and when.
    app(AlertIntake::class)->resolve($alert->refresh(), $this->dispatcher, 'Attended; resident safe.');
    // On the estate's clock, and naming it (M19A §4.2): "2:47 PM JA".
    $closedAt = SiteTime::fmt(SiteTime::local($alert->refresh()->resolved_at, $this->site), 'g:i A');

    $second = progressPanic($this);
    app(AlertIntake::class)->resolve($second, $this->dispatcher, 'Test press.');

    progressConsole($this, $this->dispatcher, $second, 'dispatch', ['guard_id' => $this->onDuty->id]);
    expect(progressRefusal())->toStartWith('This alert was resolved at ')
        ->and(progressRefusal())->toEndWith('so nobody can be sent to it now.');

    progressConsole($this, $this->dispatcher, $alert, 'en-route');
    expect(progressRefusal())->toBe('This alert was resolved at '.$closedAt.', so there is nothing left to record.');

    // And resolution still works exactly as it did, and nothing above wrote a step it refused.
    expect($alert->refresh()->status)->toBe('resolved')
        ->and(progressAudit($alert, 'alert.dispatched'))->toHaveCount(1)
        ->and(progressAudit($alert, 'alert.en_route'))->toHaveCount(0)
        ->and(progressAudit($alert, 'alert.on_scene'))->toHaveCount(1)
        ->and($second->refresh()->dispatched_at)->toBeNull();
});

it('gates the three console acts the way acknowledge is gated', function () {
    $alert = progressPanic($this);
    $assistant = FacilitiesFixture::geminiViewer(Role::ADMIN_ASSISTANT);

    // Watching the queue is not sending an officer.
    foreach (['dispatch' => ['guard_id' => $this->onDuty->id], 'en-route' => [], 'on-scene' => []] as $act => $body) {
        progressConsole($this, $assistant, $alert, $act, $body)->assertForbidden();
    }

    expect(progressScreen($this, $assistant, $alert)['actions']['can_dispatch'])->toBeFalse()
        ->and($alert->refresh()->dispatched_at)->toBeNull();
});

/* ── 3 · the officer's own handset ───────────────────────────────────── */

it('lets the officer\'s own handset say en route and on scene: whoever was sent, or anyone the alert reached when nobody was', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300]);
    Bus::fake([SendExpoPush::class]);

    $residentPhone = progressPhone($this->site, DevicePushToken::RESIDENT, $this->account->id, 'prghandset');

    /*
     * NOBODY SENT. The officer rostered for tonight heard the push and is running: nothing in a
     * panic waits on the dispatcher, so their handset may say so — and from then on they are
     * the alert's responder.
     */
    $alert = progressPanic($this);

    $before = progressCall($this, $this->rosteredToken, 'alerts.show', ['alert' => $alert->id]);
    expect($before->json('can_en_route'))->toBeTrue()
        ->and($before->json('can_on_scene'))->toBeTrue()
        ->and($before->json('responder'))->toBeNull()
        ->and($before->json('reason'))->toBeNull();

    $going = progressCall($this, $this->rosteredToken, 'alerts.en_route', ['alert' => $alert->id]);

    expect($going->json('status'))->toBe('responding')
        ->and($going->json('en_route_at'))->not->toBeNull()
        ->and($going->json('responder'))->toBe('Andre Bailey')
        ->and($going->json('responder_is_you'))->toBeTrue()
        ->and($going->json('dispatched_to_you'))->toBeFalse()
        ->and($going->json('can_en_route'))->toBeFalse()
        ->and($going->json('can_on_scene'))->toBeTrue()
        ->and($going->json('clock_skewed'))->toBeFalse();

    $alert->refresh();
    expect((int) $alert->responding_guard_id)->toBe($this->rostered->id)
        ->and($alert->en_route_recorded_by)->toBeNull()          // null IS "from the officer's own handset"
        ->and($alert->dispatched_at)->toBeNull();

    [$entry] = progressAudit($alert, 'alert.en_route');
    expect($entry->actor_id)->toBeNull()
        ->and($entry->actor_name)->toBe('Andre Bailey')
        ->and($entry->actor_role)->toBe('Guard App handset')
        ->and($entry->after['recorded_via'])->toBe('handset')
        ->and($entry->after['device_time'])->not->toBeNull();

    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$residentPhone->id] && $job->data['kind'] === 'en_route');

    // ANOTHER OFFICER may not speak for the response now — the message names the one who is.
    $refused = progressCall($this, $this->onDutyToken, 'alerts.on_scene', ['alert' => $alert->id], status: 403);
    expect($refused->json('error.code'))->toBe('another_officer_responding')
        ->and($refused->json('error.message'))->toStartWith('Andre Bailey is the officer responding to this alert');

    $watching = progressCall($this, $this->onDutyToken, 'alerts.show', ['alert' => $alert->id]);
    expect($watching->json('can_on_scene'))->toBeFalse()
        ->and($watching->json('responder_is_you'))->toBeFalse()
        ->and($watching->json('reason'))->toStartWith('Andre Bailey is the officer responding');

    expect(progressCall($this, $this->rosteredToken, 'alerts.on_scene', ['alert' => $alert->id])->json('on_scene_at'))->not->toBeNull();

    $timeline = collect(progressScreen($this, $this->dispatcher, $alert)['timeline'])->keyBy('title');
    expect($timeline['Andre Bailey on the way']['meta'])->toContain("From Andre Bailey's own handset")
        ->and($timeline['No officer dispatched']['meta'])->toBe('Andre Bailey took this alert from their own handset without being sent');

    /*
     * SENT BY A DISPATCHER: only that officer's handset records the response, even though the
     * other officer was reached by the same panic.
     */
    $next = progressPanic($this);
    progressConsole($this, $this->dispatcher, $next, 'dispatch', ['guard_id' => $this->onDuty->id])->assertSessionHasNoErrors();

    expect(progressCall($this, $this->rosteredToken, 'alerts.en_route', ['alert' => $next->id], status: 403)->json('error.code'))->toBe('another_officer_responding');

    $sent = progressCall($this, $this->onDutyToken, 'alerts.en_route', ['alert' => $next->id]);
    expect($sent->json('dispatched_to_you'))->toBeTrue()
        ->and($sent->json('responder_is_you'))->toBeTrue();
});

it('refuses a guard at another estate, a guard the alert never reached, and the officer whose own duress it is', function () {
    $alert = progressPanic($this);

    // ANOTHER ESTATE: the same 404 as an id nobody has — the handset learns nothing about a client it does not work.
    expect(progressCall($this, $this->elsewhereToken, 'alerts.en_route', ['alert' => $alert->id], status: 404)->json('error.code'))->toBe('not_found');
    expect(progressCall($this, $this->elsewhereToken, 'alerts.on_scene', ['alert' => $alert->id], status: 404)->json('error.code'))->toBe('not_found');
    progressCall($this, $this->elsewhereToken, 'alerts.show', ['alert' => $alert->id], status: 404);

    // THIS ESTATE, NOT ON SHIFT: the panic never reached them.
    $offShift = progressCall($this, $this->offShiftToken, 'alerts.en_route', ['alert' => $alert->id], status: 403);
    expect($offShift->json('error.code'))->toBe('not_on_shift')
        ->and($offShift->json('error.message'))->toBe('You are not on shift at Phoenix Park, so this alert was not sent to you and you cannot respond to it from this handset.');
    expect(progressCall($this, $this->offShiftToken, 'alerts.show', ['alert' => $alert->id], status: 403)->json('error.code'))->toBe('not_on_shift');

    // A RESIDENT'S TOKEN never reaches these at all.
    app('auth')->forgetGuards();
    $this->withToken($this->residentToken)->postJson('/api/v1/alerts/'.$alert->id.'/en-route', [], ['Idempotency-Key' => 'prg-res', 'X-Device-Time' => now()->toIso8601String()])
        ->assertForbidden()->assertJsonPath('error.code', 'wrong_app');
    FacilitiesFixture::boot();

    // THE OFFICER IN DURESS: help is coming TO them.
    $duress = progressCall($this, $this->onDutyToken, 'duress.store', body: ['mode' => 'silent']);
    $own = progressCall($this, $this->onDutyToken, 'alerts.en_route', ['alert' => $duress->json('id')], status: 403);
    expect($own->json('error.code'))->toBe('own_alert');

    // …while a colleague the duress reached may respond to it.
    expect(progressCall($this, $this->rosteredToken, 'alerts.en_route', ['alert' => $duress->json('id')])->json('responder'))->toBe('Andre Bailey');

    expect($alert->refresh()->en_route_at)->toBeNull()
        ->and(progressAudit($alert, 'alert.en_route'))->toHaveCount(0);
});

it('refuses the handset after resolution and after a false alarm, and says which and when', function () {
    $alert = progressPanic($this);
    progressCall($this, $this->onDutyToken, 'alerts.en_route', ['alert' => $alert->id]);

    app(AlertIntake::class)->resolve($alert->refresh(), $this->dispatcher, 'Resident safe.');
    $at = SiteTime::fmt(SiteTime::local($alert->refresh()->resolved_at, $this->site), 'g:i A');

    $closed = progressCall($this, $this->onDutyToken, 'alerts.on_scene', ['alert' => $alert->id], status: 409);
    expect($closed->json('error.code'))->toBe('alert_closed')
        ->and($closed->json('error.message'))->toBe('This alert was resolved at '.$at.', so there is nothing left to respond to.');

    // Still readable, and it says there is nothing to draw.
    $read = progressCall($this, $this->onDutyToken, 'alerts.show', ['alert' => $alert->id]);
    expect($read->json('status'))->toBe('resolved')
        ->and($read->json('resolved_at'))->not->toBeNull()
        ->and($read->json('can_en_route'))->toBeFalse()
        ->and($read->json('can_on_scene'))->toBeFalse()
        ->and($read->json('reason'))->toStartWith('This alert was resolved at ');

    // A false alarm is named as one.
    $false = progressPanic($this);
    app(AlertIntake::class)->resolve($false, $this->dispatcher, 'Pocket press.', falseAlarm: true);

    expect(progressCall($this, $this->onDutyToken, 'alerts.en_route', ['alert' => $false->id], status: 409)->json('error.message'))
        ->toStartWith('This alert was closed as a false alarm at ');

    expect($alert->refresh()->on_scene_at)->toBeNull();
});

/* ── 4 · what the resident is told ───────────────────────────────────── */

it('gives the resident\'s own read-back every step\'s time, adding fields and removing none', function () {
    $pressed = progressCall($this, $this->residentToken, 'duress.store', body: ['mode' => 'audible']);
    $id = $pressed->json('id');
    $original = array_keys($pressed->json());

    // Every field the answer carried before is still carried; the four steps are there from the first answer.
    foreach (['acknowledged_at', 'status', 'alerted_handsets', 'alerted_via', 'escalated_contacts', 'console_alerted', 'sms', 'dispatched_at', 'en_route_at', 'on_scene_at', 'resolved_at'] as $key) {
        expect($original)->toContain($key);
    }

    expect($pressed->json('dispatched_at'))->toBeNull()
        ->and($pressed->json('en_route_at'))->toBeNull()
        ->and($pressed->json('on_scene_at'))->toBeNull()
        ->and($pressed->json('resolved_at'))->toBeNull();

    $alert = DuressAlert::query()->findOrFail($id);
    $read = fn (): TestResponse => progressCall($this, $this->residentToken, 'duress.show', ['alert' => $id]);

    progressConsole($this, $this->dispatcher, $alert, 'acknowledge');
    $acknowledged = $read();
    expect($acknowledged->json('acknowledged_at'))->not->toBeNull()
        ->and($acknowledged->json('dispatched_at'))->toBeNull()
        ->and($acknowledged->json('status'))->toBe('acknowledged');

    progressConsole($this, $this->dispatcher, $alert, 'dispatch', ['guard_id' => $this->onDuty->id]);
    $dispatched = $read();
    expect($dispatched->json('status'))->toBe('responding')
        ->and($dispatched->json('dispatched_at'))->toBe(SiteTime::iso($alert->refresh()->dispatched_at))
        ->and($dispatched->json('en_route_at'))->toBeNull()
        // The acknowledgement is never overwritten by the dispatch that followed it.
        ->and($dispatched->json('acknowledged_at'))->toBe($acknowledged->json('acknowledged_at'));

    progressCall($this, $this->onDutyToken, 'alerts.en_route', ['alert' => $id]);
    expect($read()->json('en_route_at'))->not->toBeNull();

    progressCall($this, $this->onDutyToken, 'alerts.on_scene', ['alert' => $id]);
    expect($read()->json('on_scene_at'))->not->toBeNull();

    progressConsole($this, $this->dispatcher, $alert, 'resolve', ['classification' => 'genuine_security', 'note' => 'Officer attended; resident safe.']);
    $final = $read();

    expect($final->json('status'))->toBe('resolved')
        ->and($final->json('resolved_at'))->not->toBeNull()
        ->and(array_diff($original, array_keys($final->json())))->toBe([]);

    // Once an officer is on the way, the ten-second cancel cannot stand them down from the phone.
    $quick = progressPanic($this);
    progressCall($this, $this->rosteredToken, 'alerts.en_route', ['alert' => $quick->id]);

    app('auth')->forgetGuards();
    $this->withToken($this->residentToken)->postJson('/api/v1/duress/'.$quick->id.'/cancel', [], ['Idempotency-Key' => 'prg-cancel-'.$quick->id, 'X-Device-Time' => now()->toIso8601String()])
        ->assertStatus(409)->assertJsonPath('error.code', 'already_responding');
    FacilitiesFixture::boot();
});

it('pushes an acknowledgement as the control room having the alert — never as help on the way', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300]);
    Bus::fake([SendExpoPush::class]);

    $residentPhone = progressPhone($this->site, DevicePushToken::RESIDENT, $this->account->id, 'prgack');
    $alert = progressPanic($this);

    progressConsole($this, $this->dispatcher, $alert, 'acknowledge')->assertSessionHasNoErrors();

    $mine = fn (): \Illuminate\Support\Collection => Bus::dispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$residentPhone->id]);

    $ack = $mine()->sole();
    expect($ack->data)->toBe(['type' => 'panic_update', 'id' => (string) $alert->id, 'kind' => 'acknowledged'])
        ->and($ack->title)->toBe('The control room has your alert')
        ->and($ack->urgent)->toBeTrue()
        ->and(strtolower($ack->title.' '.$ack->body))->not->toContain('on the way')
        ->and(strtolower($ack->title.' '.$ack->body))->not->toContain('responding')
        ->and(strtolower($ack->title.' '.$ack->body))->not->toContain('help');

    // "Help is on the way" is said once an officer is SENT, and not before.
    progressConsole($this, $this->dispatcher, $alert, 'dispatch', ['guard_id' => $this->rostered->id])->assertSessionHasNoErrors();

    $sent = $mine()->filter(fn (SendExpoPush $job): bool => $job->data['kind'] === 'dispatched')->sole();
    expect($sent->body)->toContain('Help is on the way')
        ->and($mine())->toHaveCount(2);

    // A silent duress stays silent on the coerced officer's own phone, whatever step dispatch records.
    $raiserPhone = progressPhone($this->site, DevicePushToken::GUARD, $this->onDuty->id, 'prgsilent');
    $duress = DuressAlert::query()->findOrFail(progressCall($this, $this->onDutyToken, 'duress.store', body: ['mode' => 'silent'])->json('id'));

    progressConsole($this, $this->dispatcher, $duress, 'dispatch', ['guard_id' => $this->rostered->id])->assertSessionHasNoErrors();
    progressConsole($this, $this->dispatcher, $duress, 'en-route')->assertSessionHasNoErrors();

    Bus::assertNotDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$raiserPhone->id]);
});
