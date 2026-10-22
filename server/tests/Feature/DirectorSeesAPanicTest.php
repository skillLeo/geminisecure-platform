<?php

declare(strict_types=1);

use App\Events\AlertRaised;
use App\Events\AlertReachKnown;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\AlertProgress;
use App\Services\Dispatch\DeliveryReport;
use App\Services\Dispatch\PanicWatch;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| THE DIRECTOR, AND THE HEAD OF SECURITY FOR THE SITE, SEE A PANIC — M10 §1.2 items 3 and 4
|--------------------------------------------------------------------------
|
| "The Gemini Director — dashboard alert." "The Gemini Head of Security for that
| site — dashboard alert plus email." Nothing reached either of them live: the
| Gemini console listened for alerts only on the dispatch screens, so a Director
| saw a panic on the bell at their next page load, and `panic:walk` listed the
| Director as NOT REACHED because nothing addressed them. What this file proves:
|
|   1  THE SOCKET IS THE BOUNDARY. Through the broadcasting auth endpoint, with a
|      driver that really authorises: the Director is admitted to every estate's
|      alert channel, a Head of Security to the sites they cover and to no other,
|      and the Accountant to none. A Head of Security used to be admitted to
|      every estate's channel, because the callback asked only for the permission.
|
|   2  WHO THE POP-UP IS FOR. The console's watch is handed to whoever may ACT on
|      an alert — the Director, the Dispatcher, the Head of Security for their
|      sites — and not to the Admin Assistant or the Accountant, whose poll is
|      refused too.
|
|   3  THE LEG IS RECORDED. The broadcast to the Director's console is §1.2's
|      `director_dashboard` destination: one row, one channel, handed to the
|      service when broadcasting is on, and NOT REACHED with the reason when it is
|      off, refused, or the estate is the demonstration one. `panic:walk` lists
|      the Director as reached.
|
|   4  THE POP-UP HAS WHAT IT NEEDS — the estate, the lot, the name, the time, the
|      kind, DRILL, and the officer count only as the server knows it — and never
|      claims an acknowledgement nobody made.
|
| The central database runs under the framework's TESTING transaction manager, as
| `PanicDeliveryTimingTest` does, so the after-commit fan-out runs inside
| `create()` and the broadcast follows it — the real order.
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

    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();
    config(['services.sms.driver' => 'log']);

    /*
     * A SECOND CLIENT, for a Head of Security who covers somewhere else. Written to the
     * table rather than through `Tenant::create()`, whose provisioning would build a
     * database and a MySQL user for an estate nothing here opens.
     */
    $this->elsewhere = 'directorelsewhere'.random_int(1000, 9999);

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->elsewhere,
        'name' => 'Harbour View Estate',
        'status' => 'active',
        'demo' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Quiet by default: nobody on shift, nobody to mail, no numbers to text.
    Shift::query()->where('tenant_id', $this->site)->delete();
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

/* ── helpers ─────────────────────────────────────────────────────────── */

/** A Gemini Head of Security assigned to exactly one estate. */
function directorHeadOfSecurityAt(string $tenantId): User
{
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    EstateAssignment::create([
        'user_id' => $head->id,
        'tenant_id' => $tenantId,
        'role_id' => Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id,
        'is_active' => true,
    ]);

    return $head->fresh();
}

/**
 * A broadcaster that REALLY authorises.
 *
 * `phpunit.xml` sets `BROADCAST_CONNECTION=null`, and `NullBroadcaster::auth()` is an
 * empty method: against it every subscription passes and this file would prove
 * nothing. The pusher protocol is what Reverb speaks; the credentials are invented
 * because signing a subscription is an HMAC that never leaves the process. The channel
 * file is re-run because `Broadcast::channel()` bound its callbacks to the null driver
 * at boot.
 */
function directorRealAuthoriser(): void
{
    config(['broadcasting.connections.directortest' => [
        'driver' => 'pusher',
        'key' => 'director-test-key',
        'secret' => 'director-test-secret',
        'app_id' => 'director-test',
        'options' => ['host' => '127.0.0.1', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false],
    ], 'broadcasting.default' => 'directortest']);

    Broadcast::purge('directortest');

    require base_path('routes/channels.php');
}

/** Ask the broadcasting auth endpoint to subscribe this person to one estate's alert channel. */
function directorSubscribe(TestCase $test, User $user, string $tenantId): TestResponse
{
    return $test->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'private-estate.'.$tenantId.'.alerts',
        'socket_id' => '1234.5678',
    ]);
}

/** Both broadcasts and the other legs made to "work" without a network. */
function directorLiveChannel(): void
{
    config(['broadcasting.default' => 'reverb']);
    Event::fake([AlertRaised::class, AlertReachKnown::class]);
    Notification::fake();
}

function directorPanic(TestCase $test, bool $drill = false): DuressAlert
{
    return app(AlertIntake::class)->record(
        tenantId: $test->site,
        kind: 'panic',
        raisedByName: 'Rae Director',
        unitReference: 'Lot 47',
        isDrill: $drill,
    );
}

function directorLeg(DuressAlert $alert): ?DuressAlertDelivery
{
    return DuressAlertDelivery::query()
        ->where('duress_alert_id', $alert->id)
        ->where('destination', DuressAlertDelivery::DIRECTOR_DASHBOARD)
        ->first();
}

/* ── 1 · the socket is the boundary ──────────────────────────────────── */

it('admits the Director and this site\'s Head of Security to the alert channel, and refuses a Head of Security for another estate and the Accountant', function () {
    directorRealAuthoriser();

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $ours = directorHeadOfSecurityAt($this->site);
    $theirs = directorHeadOfSecurityAt($this->elsewhere);
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    // The Director sees every estate's alerts — this one and the other one.
    directorSubscribe($this, $director, $this->site)->assertOk()->assertJsonStructure(['auth']);
    directorSubscribe($this, $director, $this->elsewhere)->assertOk();

    // The Head of Security for THIS site is admitted to it.
    directorSubscribe($this, $ours, $this->site)->assertOk()->assertJsonStructure(['auth']);

    /*
     * THE ONE THAT USED TO GET THROUGH. A Head of Security for another estate holds the
     * same dispatch permission, and the callback asked for nothing more — so the socket
     * would have carried this estate's lot, resident's name and panic to them. Refused
     * now; and admitted to their own site, so the refusal is the scope and not a broken
     * account.
     */
    directorSubscribe($this, $theirs, $this->site)->assertForbidden();
    directorSubscribe($this, $theirs, $this->elsewhere)->assertOk();

    // The Accountant holds no dispatch permission at all.
    directorSubscribe($this, $accountant, $this->site)->assertForbidden();
});

it('admits the Admin Assistant only to the queue it already watches, refuses a suspended Director, and follows the permission rather than the role name', function () {
    directorRealAuthoriser();

    /*
     * THE ADMIN ASSISTANT HOLDS DISPATCH VIEW, AND KEEPS ITS LIVE QUEUE. The matrix gives
     * that role "View" on dispatch — it may watch the queue all day — and the queue is on
     * this same channel. Refusing it here would drop the Admin Assistant's queue to the
     * fallback poll under a bar that says the channel is DOWN, which is a false sentence.
     * What the Admin Assistant does not get is the pop-up: that is for the people who may
     * act on an alert, and the next test proves it is withheld.
     */
    directorSubscribe($this, FacilitiesFixture::geminiViewer(Role::ADMIN_ASSISTANT), $this->site)->assertOk();

    // The account's status is checked at the point of use; a socket outlives the request it was opened in.
    $suspended = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $suspended->forceFill(['status' => 'suspended'])->save();

    directorSubscribe($this, $suspended->fresh(), $this->site)->assertForbidden();

    /*
     * A ROLE THE DIRECTOR BUILDS (M10 §4) inherits the rule from its cells and its scope:
     * dispatch view, scoped to assigned sites, and it reaches the site it is assigned to
     * and no other.
     */
    $patrol = Role::findOrCreate('test.director.patrol_lead', 'web');
    $patrol->forceFill(['console' => 'gemini', 'scope_default' => 'assigned_sites'])->save();
    $patrol->givePermissionTo('gemini.dispatch.view');

    $lead = User::factory()->create(['console' => 'gemini', 'status' => 'active']);
    $lead->syncRoles([$patrol->name]);

    EstateAssignment::create(['user_id' => $lead->id, 'tenant_id' => $this->elsewhere, 'role_id' => $patrol->id, 'is_active' => true]);

    directorSubscribe($this, $lead->fresh(), $this->elsewhere)->assertOk();
    directorSubscribe($this, $lead->fresh(), $this->site)->assertForbidden();
});

/* ── 2 · who the pop-up is for ───────────────────────────────────────── */

it('hands the console\'s watch to whoever may act on an alert, with exactly the estates their channel admits, on every Gemini page', function () {
    $alert = directorPanic($this);

    $watchOn = function (User $viewer, string $path = '/dashboard'): mixed {
        $watch = null;

        $this->actingAs($viewer)->get($path)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$watch): void {
            $watch = $page->toArray()['props']['geminiPanicWatch'] ?? null;
        });

        return $watch;
    };

    // THE DIRECTOR: every estate, named, and the outstanding panic in the list.
    $director = $watchOn(FacilitiesFixture::geminiViewer(Role::DIRECTOR));

    expect(collect($director['estates'])->pluck('name', 'id')->all())
        ->toMatchArray([$this->site => 'Phoenix Park', $this->elsewhere => 'Harbour View Estate'])
        ->and(collect($director['alerts'])->pluck('id')->all())->toContain($alert->id)
        ->and($director['poll'])->toBe(PanicWatch::POLL_URL);

    // On pages that have nothing to do with dispatch, too — the whole point of the shell.
    foreach (['/guards', '/notifications', '/dispatch/requests'] as $path) {
        expect($watchOn(FacilitiesFixture::geminiViewer(Role::DIRECTOR), $path))->not->toBeNull();
    }

    // THE HEAD OF SECURITY FOR THIS SITE: this site, and nothing else.
    $ours = $watchOn(directorHeadOfSecurityAt($this->site));

    expect(collect($ours['estates'])->pluck('id')->all())->toBe([$this->site])
        ->and(collect($ours['alerts'])->pluck('id')->all())->toContain($alert->id);

    // A HEAD OF SECURITY FOR ANOTHER ESTATE: their estate — and not this panic.
    $theirs = $watchOn(directorHeadOfSecurityAt($this->elsewhere));

    expect(collect($theirs['estates'])->pluck('id')->all())->toBe([$this->elsewhere])
        ->and(collect($theirs['alerts'])->pluck('id')->all())->not->toContain($alert->id);

    // THE DISPATCHER gets it too — it stands down only on a screen that already shows the alert.
    expect($watchOn(FacilitiesFixture::geminiViewer(Role::DISPATCHER)))->not->toBeNull();

    // THE ADMIN ASSISTANT AND THE ACCOUNTANT: not watched, and their poll is refused.
    foreach ([Role::ADMIN_ASSISTANT, Role::ACCOUNTANT] as $role) {
        $viewer = FacilitiesFixture::geminiViewer($role);

        expect($watchOn($viewer))->toBeNull();

        $this->actingAs($viewer)->getJson(PanicWatch::POLL_URL)->assertForbidden();
    }

    // AND NEVER INSIDE AN ESTATE: the committee has its own pop-up, on its own prop.
    $this->actingAs(FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN))
        ->get(FacilitiesFixture::url('/residents'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('geminiPanicWatch', null)
            ->where('panicWatch.estate', $this->site));
});

it('never puts a simulated alert or the demonstration estate in front of the Director', function () {
    $simulated = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Simulated Resident', isSimulated: true);

    DB::connection('mysql')->table('tenants')->where('id', $this->elsewhere)->update(['demo' => true]);

    $demo = DuressAlert::withoutEvents(fn (): DuressAlert => DuressAlert::create([
        'tenant_id' => $this->elsewhere, 'kind' => 'panic', 'status' => 'open', 'raised_by_name' => 'Review Resident',
        'unit_reference' => 'Lot 1', 'server_time' => now(), 'is_simulated' => false,
    ]));

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $watch = app(PanicWatch::class)->forViewer($director);

    expect(collect($watch['estates'])->pluck('id')->all())->not->toContain($this->elsewhere)
        ->and(collect($watch['alerts'])->pluck('id')->all())->not->toContain($simulated->id)
        ->and(collect($watch['alerts'])->pluck('id')->all())->not->toContain($demo->id);

    // Asked for by id, still not answered: the browser names ids, the server decides.
    $polled = app(PanicWatch::class)->poll($director, [$simulated->id, $demo->id]);

    expect(collect($polled['alerts'])->pluck('id')->all())->not->toContain($simulated->id)
        ->and(collect($polled['alerts'])->pluck('id')->all())->not->toContain($demo->id);
});

/* ── 3 · the leg is recorded ─────────────────────────────────────────── */

it('records the broadcast to the Director\'s console as the director_dashboard leg — one channel, handed to the service', function () {
    directorLiveChannel();

    $alert = directorPanic($this);
    $leg = directorLeg($alert);

    /*
     * ONE ROW, ONE CHANNEL. `recipients` is the channel, never a count of whoever had a
     * console open — the socket server does not report that back, and the note says so
     * rather than implying somebody saw it.
     */
    expect($leg)->not->toBeNull()
        ->and($leg->channel)->toBe(DuressAlertDelivery::BROADCAST)
        ->and($leg->recipients)->toBe(1)
        ->and($leg->outcome)->toBe(DuressAlertDelivery::SENT)
        ->and($leg->reached())->toBeTrue()
        ->and($leg->elapsed_ms)->toBeGreaterThanOrEqual(0)->toBeLessThan(10_000)
        ->and($leg->note)->toContain('Director')
        ->and($leg->note)->toContain('not something the channel reports');

    // Timed with the other broadcast legs, because it IS the same publish.
    $dispatcher = DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)
        ->where('destination', DuressAlertDelivery::DISPATCHER_CONSOLE)->where('channel', DuressAlertDelivery::BROADCAST)->firstOrFail();

    expect($leg->elapsed_ms)->toBe($dispatcher->elapsed_ms);

    // And the list a person reads says the Director was reached, by the live channel.
    $director = collect(app(DeliveryReport::class)->forAlert($alert)['destinations'])->firstWhere('key', DuressAlertDelivery::DIRECTOR_DASHBOARD);

    expect($director['reached'])->toBeTrue()
        ->and($director['legs'][0]['recipients_label'])->toBe('the estate\'s live channel')
        ->and($director['legs'][0]['elapsed_label'])->toMatch('/^handed off in \d+ ms$/');
});

it('says NOT REACHED for the Director, with the reason, when there is no live channel, when it refused, and on the demonstration estate', function () {
    // THE SUITE'S DEFAULT: broadcasting is `null`. No pop-up can appear, and the row says so.
    $offline = directorLeg(directorPanic($this));

    expect($offline->outcome)->toBe(DuressAlertDelivery::SKIPPED)
        ->and($offline->reached())->toBeFalse()
        ->and($offline->note)->toContain('no pop-up can appear on the Director\'s console');

    // THE CHANNEL REFUSED IT: failed, and the error was reported — never "sent".
    Event::listen(AlertRaised::class, static function (): never {
        throw new RuntimeException('Reverb is restarting.');
    });

    $refused = directorLeg(directorPanic($this));

    expect($refused->outcome)->toBe(DuressAlertDelivery::FAILED)
        ->and($refused->note)->toContain('refused');
});

it('does not claim the Director on the demonstration estate, whose channel Gemini\'s consoles never listen to', function () {
    directorLiveChannel();

    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => true])->save();

    $leg = directorLeg(directorPanic($this));

    expect($leg->outcome)->toBe(DuressAlertDelivery::SKIPPED)
        ->and($leg->note)->toContain('Demonstration estate');
});

it('lists the Director as reached on panic:walk when broadcasting is on', function () {
    directorLiveChannel();

    $before = (int) DuressAlert::query()->max('id');

    $this->artisan('panic:walk', ['estate' => $this->site, '--force' => true])
        ->expectsOutputToContain('Who was told, and how fast')
        ->doesntExpectOutputToContain('NOT REACHED  Gemini Director')
        ->assertSuccessful();

    $drill = DuressAlert::query()->where('tenant_id', $this->site)->where('is_drill', true)->where('id', '>', $before)->latest('id')->firstOrFail();

    expect(directorLeg($drill)?->reached())->toBeTrue();
});

/* ── 4 · what the pop-up needs, and nothing it must not ──────────────── */

it('hands the overlay the estate, the lot, the name, the time, the kind, DRILL and the officer count as the server knows it', function () {
    Event::fake([AlertRaised::class, AlertReachKnown::class]);

    $alert = directorPanic($this);

    $sent = static fn (string $class): array => (Event::dispatched($class, static fn ($event): bool => $event->alert->is($alert))->first()[0] ?? null)?->broadcastWith()
        ?? throw new RuntimeException($class.' was not dispatched for this alert.');

    /*
     * THE FIRST BROADCAST, as it was really sent: everything the card draws except the
     * estate's name, which the console's watch carries — keyed by the same `estate` the
     * payload names. Sent before any officer's phone was tried, so NO COUNT: the card
     * says "Alerting officers…", never a guessed 0 or 1.
     */
    $payload = $sent(AlertRaised::class);

    expect($payload)->toHaveKeys(['id', 'estate', 'unit', 'who', 'server_time', 'kind', 'kind_label', 'is_drill', 'is_simulated', 'alerted_handsets', 'alerted_via'])
        ->and($payload['estate'])->toBe($this->site)
        ->and($payload['unit'])->toBe('Lot 47')
        ->and($payload['who'])->toBe('Rae Director')
        ->and($payload['kind_label'])->toBe('Panic button')
        ->and($payload['is_drill'])->toBeFalse()
        ->and($payload['alerted_handsets'])->toBeNull();

    /*
     * THE SECOND, on the SAME channel the console's pop-up subscribes to — so the Director
     * needs no broadcast of their own. Nobody on shift here: a zero, said as a zero.
     */
    $reach = $sent(AlertReachKnown::class);

    expect($reach)->toMatchArray(['id' => $alert->id, 'estate' => $this->site, 'alerted_handsets' => 0, 'is_drill' => false])
        ->and(collect((new AlertReachKnown($alert))->broadcastOn())->map(static fn ($channel): string => $channel->name)->all())
        ->toBe(['private-estate.'.$this->site.'.alerts']);

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $watch = app(PanicWatch::class)->forViewer($director);

    expect(collect($watch['estates'])->firstWhere('id', $payload['estate'])['name'])->toBe('Phoenix Park');

    // THE SERVER'S OWN ROW, for a page drawn after it was raised: the same facts, plus where it stands.
    $row = collect($watch['alerts'])->firstWhere('id', $alert->id);

    expect($row)->toMatchArray([
        'estate' => $this->site,
        'estate_name' => 'Phoenix Park',
        'unit' => 'Lot 47',
        'who' => 'Rae Director',
        'kind_label' => 'Panic button',
        'is_drill' => false,
        'alerted_handsets' => 0,
        'status' => 'open',
        'acknowledged_at' => null,
        'href' => '/dispatch/alerts/'.$alert->id,

        // How far the response has got, from `AlertProgress`'s own columns — nothing yet.
        'dispatched_at' => null,
        'en_route_at' => null,
        'on_scene_at' => null,
        'cancelled' => false,
    ])->and($row['server_time'])->toBe($alert->server_time->toIso8601String());

    // NOTHING THE ALERT SCREEN KEEPS BEHIND ITS OWN PERMISSION: no position, no medical note, no money.
    $json = strtolower((string) json_encode($watch));

    foreach (['latitude', 'longitude', 'medical_notes', 'balance', 'arrear', 'amount', 'jmd'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }

    // A DRILL SAYS SO, on both.
    $drill = directorPanic($this, drill: true);

    expect((new AlertRaised($drill))->broadcastWith()['is_drill'])->toBeTrue()
        ->and(collect(app(PanicWatch::class)->forViewer($director)['alerts'])->firstWhere('id', $drill->id)['is_drill'])->toBeTrue();

    // BEFORE THE FAN-OUT HAS COUNTED: null on both — "Alerting officers…", never a guessed 0 or 1.
    $unfinished = DuressAlert::withoutEvents(fn (): DuressAlert => DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'medical', 'status' => 'open', 'raised_by_name' => 'Rae Director',
        'unit_reference' => 'Lot 48', 'server_time' => now(), 'is_simulated' => false,
    ]));

    expect((new AlertRaised($unfinished))->broadcastWith()['alerted_handsets'])->toBeNull()
        ->and(collect(app(PanicWatch::class)->forViewer($director)['alerts'])->firstWhere('id', $unfinished->id))
        ->toMatchArray(['alerted_handsets' => null, 'kind_label' => 'Medical']);
});

it('tells a dispatch screen which of the alerts its siren is sounding for the control room still owes an answer, and only about its own estates', function () {
    /*
     * B107. The siren promised to repeat "until the dispatcher silences it or the alert leaves
     * the unacknowledged state", and an acknowledgement from another console is never
     * broadcast — so the screen that is sounding asks. Open, and an officer on the way with no
     * acknowledgement, are still owed an answer; acknowledged is not; another estate's alert is
     * answered only to somebody who covers it.
     */
    $open = directorPanic($this);

    $acknowledged = directorPanic($this);
    app(AlertIntake::class)->acknowledge($acknowledged, FacilitiesFixture::geminiViewer(Role::DIRECTOR));

    $responding = DuressAlert::withoutEvents(fn (): DuressAlert => DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'panic', 'status' => 'responding', 'en_route_at' => now(), 'raised_by_name' => 'Rae Director',
        'unit_reference' => 'Lot 48', 'server_time' => now(), 'is_simulated' => false,
    ]));

    $elsewhere = DuressAlert::withoutEvents(fn (): DuressAlert => DuressAlert::create([
        'tenant_id' => $this->elsewhere, 'kind' => 'duress', 'status' => 'open', 'raised_by_name' => 'Owen Elsewhere',
        'server_time' => now(), 'is_simulated' => false,
    ]));

    $ask = fn (User $viewer): TestResponse => $this->actingAs($viewer)->getJson('/dispatch/alarm?'.http_build_query([
        'ids' => [$open->id, $acknowledged->id, $responding->id, $elsewhere->id],
    ]));

    $director = $ask(FacilitiesFixture::geminiViewer(Role::DIRECTOR))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($director->json('awaiting'))->toEqualCanonicalizing([$open->id, $responding->id, $elsewhere->id]);

    // A Head of Security for THIS site hears nothing about the other estate.
    expect($ask(directorHeadOfSecurityAt($this->site))->assertOk()->json('awaiting'))
        ->toEqualCanonicalizing([$open->id, $responding->id]);

    // The Admin Assistant watches the queue, and its siren, so it may ask; the Accountant may not.
    $ask(FacilitiesFixture::geminiViewer(Role::ADMIN_ASSISTANT))->assertOk();
    $ask(FacilitiesFixture::geminiViewer(Role::ACCOUNTANT))->assertForbidden();

    // Nothing to ask about is a malformed question, not an empty answer that reads as "acknowledged".
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))->getJson('/dispatch/alarm')->assertUnprocessable();
});

it('sends the alert screen the instant the alert was raised, so "Fired" can count on while the screen is open', function () {
    /*
     * B112's sibling on the response screen. "Fired" was the server's diffForHumans() at the
     * moment the page was drawn, and the screen re-reads its props only when something happens
     * at the estate — so on a quiet channel it said "2 minutes ago" for as long as a dispatcher
     * worked the incident. The instant travels too, and the screen counts from it.
     */
    $alert = directorPanic($this);

    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->get('/dispatch/alerts/'.$alert->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Dispatch/Alert')
            ->where('alert.fired_at', $alert->server_time->toIso8601String())
            ->has('alert.fired'));
});

it('keeps an alert an officer took from the handset outstanding until the control room acknowledges it', function () {
    /*
     * B090. An officer the panic reached may tap "On my way" before anybody in the control
     * room has looked: `AlertProgress` then makes the alert `responding` and leaves
     * `acknowledged_at` null (`AlertIntake::acknowledge()` says so). The watch counted only
     * `open` as outstanding, so the Director's bar stopped and the card read "Acknowledged;
     * an officer is on the way" about an alert nobody in the control room had acknowledged.
     */
    $post = Post::create(['tenant_id' => $this->site, 'name' => 'Director Gate', 'type' => 'gate', 'is_active' => true]);
    $officer = Guard::create([
        'full_name' => 'Marcus Handset', 'employee_number' => 'GS-DIR-B090', 'psra_number' => 'PSRA-GS-DIR-B090',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->site, 'post_id' => $post->id,
    ]);
    $day = SiteTime::today($this->site);
    Shift::withoutEvents(fn (): Shift => Shift::create([
        'tenant_id' => $this->site, 'guard_id' => $officer->id, 'post_id' => $post->id,
        'rostered_start' => $day->copy()->setTime(0, 0), 'rostered_end' => $day->copy()->setTime(23, 59),
        'actual_start' => now()->subHour(), 'status' => 'on_duty',
    ]));

    $alert = directorPanic($this);
    app(AlertProgress::class)->enRoute($alert, $officer);

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $row = fn (?array $answer): ?array => collect($answer['alerts'] ?? [])->firstWhere('id', $alert->id);

    // The officer is on the way; the control room has not acknowledged it.
    expect($alert->fresh()->status)->toBe(AlertProgress::RESPONDING)
        ->and($alert->fresh()->acknowledged_at)->toBeNull();

    // STILL OUTSTANDING: on the page's own list, and on the poll's list of what nobody has acknowledged.
    expect($row(app(PanicWatch::class)->forViewer($director)))
        ->toMatchArray(['status' => 'responding', 'acknowledged_at' => null])
        ->and($row(app(PanicWatch::class)->forViewer($director))['en_route_at'])->not->toBeNull()
        ->and($row(app(PanicWatch::class)->poll($director, [])))->not->toBeNull();

    // THE CONTROL ROOM ACKNOWLEDGES IT: it leaves the outstanding list, still responding, now acknowledged.
    app(AlertIntake::class)->acknowledge($alert->fresh(), $director);

    expect($row(app(PanicWatch::class)->forViewer($director)))->toBeNull()
        ->and($row(app(PanicWatch::class)->poll($director, [])))->toBeNull();

    $asked = $row(app(PanicWatch::class)->poll($director, [$alert->id]));

    expect($asked['status'])->toBe('responding')
        ->and($asked['acknowledged_at'])->not->toBeNull();
});

it('tells the pop-up an alert was acknowledged only once it was, and answers a Head of Security only about their own sites', function () {
    $alert = directorPanic($this);
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $poll = fn (User $viewer): TestResponse => $this->actingAs($viewer)
        ->getJson(PanicWatch::POLL_URL.'?'.http_build_query(['ids' => [$alert->id]]));

    // OUTSTANDING: open, no acknowledgement claimed, and not cacheable.
    $open = $poll($director)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect(collect($open->json('alerts'))->firstWhere('id', $alert->id))
        ->toMatchArray(['status' => 'open', 'acknowledged_at' => null]);

    // ACKNOWLEDGED: the poll says so, with when — and it leaves the list of what is outstanding.
    app(AlertIntake::class)->acknowledge($alert, $director);

    $acknowledged = collect($poll($director)->assertOk()->json('alerts'))->firstWhere('id', $alert->id);

    expect($acknowledged['status'])->toBe('acknowledged')
        ->and($acknowledged['acknowledged_at'])->not->toBeNull();

    expect(collect($this->actingAs($director)->getJson(PanicWatch::POLL_URL)->assertOk()->json('alerts'))->pluck('id')->all())
        ->not->toContain($alert->id);

    // RESOLVED: the same.
    app(AlertIntake::class)->resolve($alert->fresh(), $director, 'Resident safe.');

    expect(collect($poll($director)->json('alerts'))->firstWhere('id', $alert->id)['status'])->toBe('resolved');

    // A HEAD OF SECURITY FOR ANOTHER ESTATE who asks for it by id is told nothing about it.
    expect(collect($poll(directorHeadOfSecurityAt($this->elsewhere))->assertOk()->json('alerts'))->pluck('id')->all())
        ->not->toContain($alert->id);

    // And the one for this site is.
    expect(collect($poll(directorHeadOfSecurityAt($this->site))->assertOk()->json('alerts'))->pluck('id')->all())
        ->toContain($alert->id);
});
