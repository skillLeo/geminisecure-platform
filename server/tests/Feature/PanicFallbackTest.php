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
use App\Notifications\PanicRaisedAtSite;
use App\Notifications\ShiftAutoClosed;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\ShiftAutoClose;
use App\Services\Dispatch\SiteEscalation;
use App\Services\Gemini\PlatformNotifications;
use App\Services\Push\PanicFanOut;
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
| PANIC WHEN NOBODY IS CLOCKED ON — M6 §2.1
|--------------------------------------------------------------------------
|
| All five parts of the ruling, and the one path that matters most: a panic at an
| estate with nobody clocked on, no officer rostered and no escalation contact —
| where the CONTROL ROOM still gets it and the RESIDENT is told, plainly, that no
| handset was reached.
|
|   1  the console always receives a panic, whoever is on shift
|   2  every guard with an active shift at that site (unchanged)
|   3  nobody clocked on, so the officers rostered HERE TODAY, then the escalation contact
|   4  a shift four hours past its rostered end is closed, the guard is told, and the
|      Head of Security gets an attendance exception
|   5  the server counts honestly, so the app can say "no handset was reached"
|
| NO TEST REACHES THE NETWORK. Push is off in the suite; each test that wants one
| turns it on and fakes the bus first, and `Http::preventStrayRequests()` fails any
| request nobody faked.
|
| The central database is wrapped in a transaction with the framework's TESTING
| transaction manager, so the "after commit" announcement a panic makes fires here
| as it does in production instead of waiting for a commit that never comes.
|
| SHUFFLED ORDERING IS ON (phpunit.xml), so nothing below may rely on what another
| file left at this estate. `bareSite()` states the preconditions it needs and makes
| them true inside this test's own transaction, rather than hoping.
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

    $this->site = FacilitiesFixture::ESTATE;
    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Fallback Gate', 'type' => 'gate', 'is_active' => true]);

    // A lot of this file's own (the fixture's rule): the estate database is committed
    // and shared, so nothing here stands on a lot another suite is standing on.
    $this->unit = FacilitiesFixture::scratchUnit('Lot 611');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Fallback household', 'access_restricted' => false]);
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Rae Fallback'],
        ['relationship' => 'owner', 'is_primary' => true],
    );
    $this->resident->forceFill(['is_primary' => true, 'removed_at' => null])->save();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

/* ── helpers ─────────────────────────────────────────────────────────── */

function fallbackToken(string $seed): string
{
    return 'ExponentPushToken['.substr(str_pad((string) preg_replace('/[^A-Za-z0-9]/', '', $seed).'xxxxxxxxxxxxxxxxxxxxxx', 22, 'x'), 0, 22).']';
}

function fallbackPushOn(): void
{
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300, 'push.receipt_delay_seconds' => 0]);
}

/**
 * Nobody on duty, nobody rostered, nobody to escalate to — asserted, not assumed.
 *
 * This estate's central rows live in THIS test's transaction, but other files have
 * committed guards, shifts and committee members against it in the past, and the
 * suite is shuffled. So the preconditions the ruling names are made true here and
 * then checked: a "reaches nobody" test that silently found somebody would pass for
 * the wrong reason and prove nothing at all.
 */
function bareSite(TestCase $test): void
{
    Shift::query()->where('tenant_id', $test->site)->delete();
    Guard::query()->where('tenant_id', $test->site)->update(['status' => 'inactive', 'device_id' => null]);
    EstateAssignment::query()->where('tenant_id', $test->site)->update(['is_active' => false]);

    expect(app(SiteEscalation::class)->contactsFor($test->site))->toBeEmpty()
        ->and(app(PanicFanOut::class)->rosteredToday($test->site))->toBe([]);
}

/** @return array{0: Guard, 1: string} the guard and their handset's API token */
function fallbackGuard(string $tenant, string $number, ?int $postId = null): array
{
    $guard = Guard::create([
        'full_name' => 'Officer '.$number, 'employee_number' => $number, 'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $tenant, 'post_id' => $postId,
    ]);

    return [$guard, app(DeviceEnrolment::class)->enrol($guard, 'Fallback handset '.$number)['token']];
}

function fallbackHandset(string $tenant, int $guardId, string $seed): DevicePushToken
{
    return DevicePushToken::query()->create([
        'tenant_id' => $tenant, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => $guardId, 'app' => AppMatrix::GUARD,
        'access_token_id' => null, 'expo_token' => fallbackToken($seed), 'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
    ]);
}

/** @return array{0: ResidentAccount, 1: string} */
function fallbackResident(string $tenant, string $email, int $unitId, int $residentId): array
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => $tenant, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Resident '.$email,
        'resident_id' => $residentId, 'unit_id' => $unitId, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$account, $account->createToken('resident-app:'.$email, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

function fallbackCall(TestCase $test, string $token, string $name, array $body = [], array $path = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = $entry['uri'];

    foreach ($path as $key => $value) {
        $uri = str_replace('{'.$key.'}', (string) $value, $uri);
    }

    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($entry['method'], '/api/v1/'.$uri, $body, [
        'Idempotency-Key' => 'fallback-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/**
 * A shift rostered for a window, optionally already clocked on.
 *
 * WITHOUT EVENTS, deliberately. Saving a `rostered` shift that starts in the future is
 * a ROSTER CHANGE, and `PushAnnouncer::shiftChanged` pushes the guard "your roster
 * changed" for it — a real and correct behaviour that has nothing to do with a panic,
 * and which would be counted by every `assertDispatchedTimes` below. Fixtures here set
 * up a WORLD; what this file measures is what a panic does to it.
 */
function fallbackShift(string $tenant, int $guardId, int $postId, Carbon $start, Carbon $end, ?Carbon $clockedInAt = null): Shift
{
    return Shift::withoutEvents(static fn (): Shift => Shift::create([
        'tenant_id' => $tenant,
        'guard_id' => $guardId,
        'post_id' => $postId,
        'rostered_start' => $start,
        'rostered_end' => $end,
        'actual_start' => $clockedInAt,
        'status' => $clockedInAt === null ? Shift::ROSTERED : 'on_duty',
    ]));
}

/* ── part 1 · the control room always receives a panic ───────────────── */

it('gives the control room a panic at an estate with no guard, no roster and no escalation contact — and tells the resident plainly that no handset was reached', function () {
    fallbackPushOn();
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    bareSite($this);

    [, $residentToken] = fallbackResident($this->site, 'nobody.on.duty@example.com', $this->unit->id, $this->resident->id);

    $answer = fallbackCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    /*
     * THE CONSOLE FIRST, because it is the part that must never depend on the
     * fallback: the alert is a committed row the moment it is raised, and the
     * dispatcher's own attention list — the console's bell, with the console's own
     * scoping applied — carries it whoever is on shift.
     */
    $alert = DuressAlert::query()->findOrFail($answer->json('id'));

    expect($alert->status)->toBe('open')
        ->and($alert->tenant_id)->toBe($this->site);

    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $bell = app(PlatformNotifications::class)->attention($dispatcher->fresh());

    expect(collect($bell['items'])->pluck('key'))->toContain('alert:'.$alert->id.':open');

    // …and not one handset rang. Said as a number, not implied by an absence.
    Bus::assertNotDispatched(SendExpoPush::class);
    Notification::assertNothingSent();

    expect($answer->json('console_alerted'))->toBeTrue()
        ->and($answer->json('alerted_handsets'))->toBe(0)
        ->and($answer->json('alerted_via'))->toBe(PanicFanOut::NOBODY)
        ->and($answer->json('escalated_contacts'))->toBe(0);

    // The same truth on the read-back the panic screen polls, not only on the press.
    $again = fallbackCall($this, $residentToken, 'duress.show', path: ['alert' => $alert->id]);

    expect($again->json('alerted_handsets'))->toBe(0)
        ->and($again->json('alerted_via'))->toBe(PanicFanOut::NOBODY)
        ->and($again->json('console_alerted'))->toBeTrue();
});

/* ── parts 2 and 3 · every leg at once, not a ladder ─────────────────── */

/*
 * THIS USED TO PROVE A LADDER, AND THE CLIENT HAS RULED IT OUT (M10 §1).
 *
 * The old behaviour ran each rung only when the one above reached ZERO handsets:
 * on-duty guards, else guards rostered today, else the escalation contact. This
 * test asserted exactly that, tier by tier — and the assertions were right about
 * the code and wrong about what a panic needs.
 *
 * The client's reasoning: "A panic alert doesn't have to have anything to do
 * with security. Maybe someone has a medical problem… the property office should
 * be able to take action." A guard at the gate does not make the property
 * office's knowing unnecessary.
 *
 * So the rungs are now parallel legs and this test proves the new rule: every
 * leg fires, none waits on another having failed, and `alerted_via` still names
 * the STRONGEST thing reached so the resident's screen and the audit trail are
 * unchanged in meaning.
 *
 * WHAT IT STILL PROVES, and must keep proving: an officer merely rostered is
 * reached as a real handset; the escalation contact is NEVER counted as one,
 * because "somebody was emailed" is not "help is coming"; and the console is
 * told regardless.
 */
it('pushes to the guards on duty AND the officers rostered today AND the escalation contact, none waiting on another', function () {
    fallbackPushOn();
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    bareSite($this);

    [$onDuty] = fallbackGuard($this->site, 'GS-FB-1', $this->post->id);
    [$rostered] = fallbackGuard($this->site, 'GS-FB-2', $this->post->id);
    $onDutyPhone = fallbackHandset($this->site, $onDuty->id, 'fbonduty');
    $rosteredPhone = fallbackHandset($this->site, $rostered->id, 'fbrostered');

    /*
     * BOTH SHIFTS ARE PINNED INSIDE THE SITE'S TODAY BY THE CLOCK, not by an offset from now:
     * "rostered today" is a calendar question, and `now + 9 hours` is tomorrow if this file
     * happens to run after three in the afternoon. That is exactly the kind of test that
     * passes for six weeks and then fails on a seed, which is what shuffled ordering exists
     * to stop. The ON-DUTY one is on duty because it has a start and no end, which is all
     * `toGuardsOnDuty` asks — its rostered window plays no part.
     */
    $day = SiteTime::today($this->site);
    $onDutyShift = fallbackShift($this->site, $onDuty->id, $this->post->id, $day->copy()->setTime(6, 0), $day->copy()->setTime(18, 0), Carbon::now()->subHour());
    fallbackShift($this->site, $rostered->id, $this->post->id, $day->copy()->setTime(18, 0), $day->copy()->setTime(23, 30));

    [, $residentToken] = fallbackResident($this->site, 'ladder@example.com', $this->unit->id, $this->resident->id);

    /*
     * BOTH LEGS, ON ONE PANIC. Somebody is clocked on AND somebody else is
     * rostered later today, and under the old ladder the second was not woken.
     * Now both are: the on-duty officer and the one due at that gate tonight.
     */
    $first = fallbackCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    expect($first->json('alerted_handsets'))->toBe(2)

        // Still the STRONGEST thing reached, which is what the resident is told.
        ->and($first->json('alerted_via'))->toBe(PanicFanOut::ON_DUTY)

        // And who was clocked on at the raise (M18A §1.2): one, the rostered officer is not on duty yet.
        ->and($first->json('on_duty_officers'))->toBe(1);

    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => in_array($onDutyPhone->id, $job->tokenIds, true) && $job->urgent);
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => in_array($rosteredPhone->id, $job->tokenIds, true) && $job->urgent);

    /*
     * NOBODY CLOCKED ON. The officers rostered at this site TODAY are still
     * reached, whether or not they ever clocked in: they are due at that gate
     * today. `alerted_via` drops to ROSTERED because that is now the strongest
     * leg that reached anybody.
     */
    $onDutyShift->forceFill(['actual_start' => null, 'status' => Shift::ROSTERED])->saveQuietly();
    Bus::fake([SendExpoPush::class]);

    $second = fallbackCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    expect($second->json('alerted_handsets'))->toBe(2)
        ->and($second->json('alerted_via'))->toBe(PanicFanOut::ROSTERED)
        ->and($second->json('on_duty_officers'))->toBe(0);
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => in_array($rosteredPhone->id, $job->tokenIds, true) && $job->urgent);

    /*
     * NO HANDSET AT ALL. The site's escalation contact is the Head of Security
     * who covers it, and they are told OFF-APP: a mail and the console's bell,
     * never a push, and NEVER counted as a handset. "Somebody was emailed" is not
     * "help is coming", and the resident's screen must not be able to say so.
     *
     * They are now told on EVERY panic rather than only this one — which is why
     * `PanicReachedNoHandset` became `PanicRaisedAtSite` and carries the real
     * handset count. Telling a Head of Security nobody was on duty while two
     * officers walk to the lot is the kind of false claim M10 exists to remove.
     */
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create([
        'user_id' => $head->id,
        'tenant_id' => $this->site,
        'role_id' => Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id,
        'is_active' => true,
    ]);

    Shift::query()->where('tenant_id', $this->site)->delete();
    Bus::fake([SendExpoPush::class]);

    $third = fallbackCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    expect($third->json('alerted_handsets'))->toBe(0)
        ->and($third->json('alerted_via'))->toBe(PanicFanOut::ESCALATION)
        ->and($third->json('escalated_contacts'))->toBe(1)
        ->and($third->json('console_alerted'))->toBeTrue();

    Bus::assertNotDispatched(SendExpoPush::class);
    Notification::assertSentTo($head->fresh(), PanicRaisedAtSite::class);
});

/*
 * NOBODY ON DUTY IS NOT AN OFFICER ON DUTY WITH NO PHONE (M18A §1.2). Both are `alerted_handsets` 0, and the resident is
 * told different things: "No officer is on duty at your community", or "Officers are on duty but their phones could not
 * be reached". `on_duty_officers` is what tells them apart, as it stood at the raise, on the press and on the read-back.
 */
it('records how many officers were clocked on at the raise, so no phone reached is told apart from nobody on duty', function () {
    fallbackPushOn();
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    bareSite($this);

    [, $residentToken] = fallbackResident($this->site, 'onduty@example.com', $this->unit->id, $this->resident->id);

    $nobody = fallbackCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    expect($nobody->json('alerted_handsets'))->toBe(0)
        ->and($nobody->json('on_duty_officers'))->toBe(0);

    // Clocked on at the gate, and no phone that can be pushed.
    [$officer] = fallbackGuard($this->site, 'GS-FB-OD', $this->post->id);
    $day = SiteTime::today($this->site);
    fallbackShift($this->site, $officer->id, $this->post->id, $day->copy()->setTime(0, 0), $day->copy()->setTime(23, 59), Carbon::now()->subHour());

    $unreachable = fallbackCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    expect($unreachable->json('alerted_handsets'))->toBe(0)
        ->and($unreachable->json('on_duty_officers'))->toBe(1)
        ->and(fallbackCall($this, $residentToken, 'duress.show', path: ['alert' => $unreachable->json('id')])->json('on_duty_officers'))->toBe(1);

    Bus::assertNotDispatched(SendExpoPush::class);
});

it('reads "the roster for that day" on the estate\'s own calendar, never the server\'s', function () {
    /*
     * Frozen at 02:00 UTC on 21 September, when the two calendars genuinely disagree:
     * Auckland is +12 that week, so the site's today is the 21st and its 9 AM shift is
     * stored on the 20th in UTC, while its 9 AM shift TOMORROW is stored on the 21st.
     *
     * So each shift is on the wrong side of the server's calendar from the site's, and
     * a `whereDate(rostered_start, today())` on the server's clock would get BOTH of
     * them exactly backwards. That is the whole assertion.
     */
    Carbon::setTestNow(Carbon::parse('2026-09-21T02:00:00Z'));
    bareSite($this);

    $estate = Tenant::query()->findOrFail($this->site);
    $wasZone = $estate->getAttribute('timezone');
    $estate->forceFill(['timezone' => 'Pacific/Auckland'])->save();

    expect(SiteTime::zone($this->site))->toBe('Pacific/Auckland');

    $siteToday = Carbon::today('Pacific/Auckland');

    [$onToday] = fallbackGuard($this->site, 'GS-FB-TZ1', $this->post->id);
    [$onTomorrow] = fallbackGuard($this->site, 'GS-FB-TZ2', $this->post->id);

    $today = fallbackShift($this->site, $onToday->id, $this->post->id, $siteToday->copy()->setTime(9, 0), $siteToday->copy()->setTime(21, 0));
    $tomorrow = fallbackShift($this->site, $onTomorrow->id, $this->post->id, $siteToday->copy()->addDay()->setTime(9, 0), $siteToday->copy()->addDay()->setTime(21, 0));

    // The disagreement, stated, so this test fails loudly if the fixture ever stops proving anything.
    expect($today->rostered_start->copy()->utc()->toDateString())->toBe('2026-09-20')
        ->and($tomorrow->rostered_start->copy()->utc()->toDateString())->toBe('2026-09-21')
        ->and(Carbon::today()->toDateString())->toBe('2026-09-21');

    $reached = app(PanicFanOut::class)->rosteredToday($this->site);

    expect($reached)->toContain($onToday->id)   // stored on the server's YESTERDAY, and it is today here
        ->and($reached)->not->toContain($onTomorrow->id); // stored on the server's TODAY, and it is tomorrow here

    $estate->forceFill(['timezone' => $wasZone])->save();
});

it('never pushes a guard their own duress back, and the fallback does not bring them back', function () {
    fallbackPushOn();
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    bareSite($this);

    [$raiser, $raiserToken] = fallbackGuard($this->site, 'GS-FB-3', $this->post->id);
    fallbackHandset($this->site, $raiser->id, 'fbraiser');

    // Rostered here today AND clocked on: the raiser is the only officer at the site.
    $day = SiteTime::today($this->site);
    fallbackShift($this->site, $raiser->id, $this->post->id, $day->copy()->setTime(6, 0), $day->copy()->setTime(18, 0), Carbon::now()->subHour());

    $answer = fallbackCall($this, $raiserToken, 'duress.store', ['mode' => 'silent']);

    /*
     * Zero, and honestly zero. A guard under SILENT duress must not have their own
     * phone light up, so tier 1 reaches nobody — and tier 2 must not undo that by
     * finding the same officer on today's roster. The excluded guard is excluded all
     * the way down the ladder.
     */
    expect($answer->json('alerted_handsets'))->toBe(0)
        ->and($answer->json('alerted_via'))->toBe(PanicFanOut::NOBODY);

    Bus::assertNotDispatched(SendExpoPush::class);
});

it('prefers the Head of Security who covers the site, and falls back to the estate\'s own named committee', function () {
    bareSite($this);

    $escalation = app(SiteEscalation::class);

    // Nobody at all.
    expect($escalation->contactsFor($this->site))->toBeEmpty();

    // The estate's own committee, when there is no Head of Security covering the site.
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    expect($escalation->contactsFor($this->site)->pluck('id')->all())->toBe([$president->id]);

    // A Head of Security who covers the site outranks it.
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create([
        'user_id' => $head->id, 'tenant_id' => $this->site,
        'role_id' => Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id, 'is_active' => true,
    ]);

    expect($escalation->contactsFor($this->site)->pluck('id')->all())->toBe([$head->id]);

    // One who does not cover it is not the escalation contact for it.
    $elsewhere = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    expect($escalation->contactsFor($this->site)->pluck('id')->all())->not->toContain($elsewhere->id);
});

/* ── part 4 · the shift nobody clocked out of ────────────────────────── */

it('closes a shift four hours past its rostered end, at the rostered end, tells the guard and raises an attendance exception', function () {
    fallbackPushOn();
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    bareSite($this);

    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create([
        'user_id' => $head->id, 'tenant_id' => $this->site,
        'role_id' => Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id, 'is_active' => true,
    ]);

    [$forgot] = fallbackGuard($this->site, 'GS-FB-4', $this->post->id);
    [$stillOn] = fallbackGuard($this->site, 'GS-FB-5', $this->post->id);
    $forgotPhone = fallbackHandset($this->site, $forgot->id, 'fbforgot');

    $now = Carbon::now();
    $rosteredEnd = $now->copy()->subHours(5);

    $forgotten = fallbackShift($this->site, $forgot->id, $this->post->id, $rosteredEnd->copy()->subHours(12), $rosteredEnd, $rosteredEnd->copy()->subHours(12));
    $recent = fallbackShift($this->site, $stillOn->id, $this->post->id, $now->copy()->subHours(15), $now->copy()->subHours(3), $now->copy()->subHours(15));

    /*
     * `closed` is counted platform-wide, so it is asserted as "at least this one" — this
     * database is shared and another suite may have left an open shift at another estate.
     * What this test is actually about is the two rows below, which are its own.
     */
    $report = app(ShiftAutoClose::class)->run($now);

    expect($report['closed'])->toBeGreaterThanOrEqual(1);

    $forgotten->refresh();
    $recent->refresh();

    // CLOSED AT THE ROSTERED END — the last hour anyone can evidence — and flagged, so
    // the row does not read as an officer who left exactly on time.
    expect($forgotten->actual_end?->copy()->utc()->toIso8601String())->toBe($rosteredEnd->copy()->utc()->toIso8601String())
        ->and($forgotten->status)->toBe('completed')
        ->and($forgotten->auto_closed_at)->not->toBeNull()
        ->and($forgotten->auto_close_hours)->toBe(4)
        // Three hours past its end is still somebody's overrun, not a forgotten clock-out.
        ->and($recent->actual_end)->toBeNull()
        ->and($recent->auto_closed_at)->toBeNull();

    // THE GUARD IS TOLD.
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$forgotPhone->id]
        && $job->data['type'] === 'shift_changed'
        && str_contains($job->body, 'closed at its rostered end'));

    // AND IT IS AN ATTENDANCE EXCEPTION, not a silent correction: a mail, the audit log,
    // and the Head of Security's own bell — none of which reading clears.
    Notification::assertSentTo($head->fresh(), ShiftAutoClosed::class);

    expect(AuditEntry::query()->where('action', 'shift.auto_closed')->where('entity_id', (string) $forgotten->id)->exists())->toBeTrue();

    $bell = app(PlatformNotifications::class)->attention($head->fresh());
    expect(collect($bell['items'])->pluck('key'))->toContain('autoclose:'.$forgotten->id);
    expect(collect($bell['items'])->firstWhere('key', 'autoclose:'.$forgotten->id)['urgent'])->toBeFalse();

    // Idempotent: a second run has nothing left to do, and nobody is told twice.
    Notification::fake();
    expect(app(ShiftAutoClose::class)->run($now)['closed'])->toBe(0);
    Notification::assertNothingSent();
});

it('takes the number of hours from the site when the site has one, and clamps a nonsense value', function () {
    Notification::fake();
    bareSite($this);

    $estate = Tenant::query()->findOrFail($this->site);
    $rule = app(ShiftAutoClose::class);

    expect($rule->hoursFor($this->site))->toBe(4); // the platform default (D-M122)

    $estate->forceFill([ShiftAutoClose::SITE_KEY => 8])->save();
    expect($rule->hoursFor($this->site))->toBe(8);

    // A site that has written nonsense gets the clamp, never a rule that never fires.
    $estate->forceFill([ShiftAutoClose::SITE_KEY => 0])->save();
    expect($rule->hoursFor($this->site))->toBe(ShiftAutoClose::MIN_HOURS);

    $estate->forceFill([ShiftAutoClose::SITE_KEY => 9999])->save();
    expect($rule->hoursFor($this->site))->toBe(ShiftAutoClose::MAX_HOURS);

    // And it is the SITE's number that decides: five hours past the end survives at eight.
    $estate->forceFill([ShiftAutoClose::SITE_KEY => 8])->save();

    [$guard] = fallbackGuard($this->site, 'GS-FB-6', $this->post->id);
    $now = Carbon::now();
    $shift = fallbackShift($this->site, $guard->id, $this->post->id, $now->copy()->subHours(17), $now->copy()->subHours(5), $now->copy()->subHours(17));

    app(ShiftAutoClose::class)->run($now);

    expect($shift->refresh()->actual_end)->toBeNull()
        ->and($shift->auto_closed_at)->toBeNull();

    $estate->forceFill([ShiftAutoClose::SITE_KEY => null])->save();
});

it('is the second rule the fallback needs: once a forgotten shift is closed, a panic stops going to somebody who went home', function () {
    /*
     * FROZEN, because this test needs two different facts about the same shift to hold at
     * once: that it ended long enough ago to be auto-closed, AND that it belongs to
     * YESTERDAY on the estate's calendar so it is not on today's roster either. Written as
     * offsets from the moment the suite happens to run, one of the two stops being true for
     * part of every day — the sort of test that passes until a seed puts it somewhere else.
     * 15:00 UTC is 10:00 in Kingston, so the site's today began five hours ago and the shift
     * below ended at 11 PM last night: eleven hours past its end, and squarely yesterday.
     */
    Carbon::setTestNow(Carbon::parse('2026-09-21T15:00:00Z'));

    fallbackPushOn();
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    bareSite($this);

    // Said out loud rather than assumed: the arithmetic above is Kingston's, and another file
    // (or another test in this one) may have left this estate on a different clock.
    Tenant::query()->findOrFail($this->site)->forceFill(['timezone' => 'America/Jamaica'])->save();
    expect(SiteTime::zone($this->site))->toBe('America/Jamaica');

    [$wentHome] = fallbackGuard($this->site, 'GS-FB-7', $this->post->id);
    $homePhone = fallbackHandset($this->site, $wentHome->id, 'fbwenthome');

    // Last night's shift, never clocked out. The system believes they are still on duty.
    $now = Carbon::now();
    $lastNight = SiteTime::today($this->site)->subDay();
    $forgotten = fallbackShift($this->site, $wentHome->id, $this->post->id, $lastNight->copy()->setTime(18, 0), $lastNight->copy()->setTime(23, 0), $lastNight->copy()->setTime(18, 0));

    // Yesterday on the estate's calendar, so nothing below can pass by being "on today's roster".
    expect(app(PanicFanOut::class)->rosteredToday($this->site))->toBe([]);

    [, $residentToken] = fallbackResident($this->site, 'second.rule@example.com', $this->unit->id, $this->resident->id);

    $before = fallbackCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    expect($before->json('alerted_via'))->toBe(PanicFanOut::ON_DUTY)
        ->and($before->json('alerted_handsets'))->toBe(1);
    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$homePhone->id] && $job->urgent);

    // The rule runs. Their shift is closed; they are no longer on duty at 3 AM at home.
    app(ShiftAutoClose::class)->run($now);

    expect($forgotten->refresh()->actual_end)->not->toBeNull()
        ->and($forgotten->auto_closed_at)->not->toBeNull();

    /*
     * AND NOW THE LADDER WORKS AGAIN, which is the point of the rule. While the system
     * believed somebody was on duty it never descended at all — one forgotten tap had
     * quietly disabled the whole site's safety net. The estate is bare apart from this
     * shift, so the honest answer now is "no handset", said as a number.
     */
    Bus::fake([SendExpoPush::class]);
    $after = fallbackCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    expect($after->json('alerted_handsets'))->toBe(0)
        ->and($after->json('alerted_via'))->toBe(PanicFanOut::NOBODY)
        ->and($after->json('console_alerted'))->toBeTrue();

    Bus::assertNotDispatched(SendExpoPush::class);
});
