<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Console\Commands\ApplyCentralAppendOnlyGrants;
use App\Events\AlertRaised;
use App\Events\AlertReachKnown;
use App\Jobs\Alerts\SendPanicSms;
use App\Jobs\Push\SendExpoPush;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
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
use App\Models\User;
use App\Notifications\PanicRaisedAtSite;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\DeliveryReport;
use App\Services\Dispatch\LiveMap;
use App\Services\Dispatch\PanicSms;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Services\Estate\EstateSecurity;
use App\Services\Gemini\PlatformNotifications;
use App\Services\Push\PanicDeliveries;
use App\Services\Push\PushAnnouncer;
use App\Support\GrantedTables;
use App\Support\SiteTime;
use Database\Seeders\SiteEmergencyNumbersSeeder;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| WHO WAS TOLD, AND HOW FAST — M10 §1.3 and §10
|--------------------------------------------------------------------------
|
| "Measure and record delivery time to each destination", and "walk a panic end
| to end and confirm every destination in §1.2 received it, with the measured
| time to each". What this file proves:
|
|   1  a resident's panic writes one row per destination per channel, each a
|      HAND-OFF with a sane time on the fan-out's own clock — never a claim of
|      arrival on a phone
|   2  a leg that went nowhere says so, in a sentence, rather than going quiet
|   3  a failure while recording breaks nothing: the push, the mail, the texts,
|      the broadcast and the resident's answer are all exactly what they were
|   4  the record is append-only for real — the trigger refuses an edit
|   5  `panic:walk` raises a DRILL on the resident's path and prints every §1.2
|      destination, with NOT REACHED and the reason for the ones it did not reach
|   6  a drill says DRILL everywhere it lands and never moves a response time
|
| NO TEST REACHES A NETWORK. Push is off unless a test turns it on and fakes the
| bus; mail and the live channel are faked where a test needs them to "work";
| `Http::preventStrayRequests()` fails anything that tried.
|
| The central database is wrapped in a transaction with the framework's TESTING
| transaction manager, so the "after commit" fan-out runs inside `create()` here as
| it does in production, and the broadcast follows it — the real order.
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
    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Timing Gate', 'type' => 'gate', 'is_active' => true]);

    // A lot of this file's own (the fixture's rule).
    $this->unit = FacilitiesFixture::scratchUnit('Lot 734');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Timing household', 'access_restricted' => false]);
    // Lot 734 is AlertProgressTest's too: this file takes the household over for its own run (one primary, DESIGN §13).
    $this->resident = Tests\Support\HouseholdHolder::of($this->household, 'Rae Stopwatch');

    // Not the demonstration estate, on Kingston's clock — asserted, not assumed.
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();

    // No provider, which is the platform's true state today: texts are written down.
    config(['services.sms.driver' => 'log']);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    app()['env'] = 'testing';
    FacilitiesFixture::boot();
});

/* ── helpers ─────────────────────────────────────────────────────────── */

/** Nobody on duty, nobody rostered, nobody to escalate to, no numbers — asserted by the tests that care. */
function timingBareSite(TestCase $test): void
{
    Shift::query()->where('tenant_id', $test->site)->delete();
    Guard::query()->where('tenant_id', $test->site)->update(['status' => 'inactive', 'device_id' => null]);
    EstateAssignment::query()->where('tenant_id', $test->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $test->site)->delete();
}

/** One officer clocked on at the site, with a live handset. */
function timingOfficerOnDuty(TestCase $test): Guard
{
    $guard = Guard::create([
        'full_name' => 'Officer Stopwatch', 'employee_number' => 'GS-TM-1', 'psra_number' => 'PSRA-GS-TM-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $test->site, 'post_id' => $test->post->id,
    ]);

    app(DeviceEnrolment::class)->enrol($guard, 'Timing handset');

    DevicePushToken::query()->create([
        'tenant_id' => $test->site, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => $guard->id, 'app' => AppMatrix::GUARD,
        'access_token_id' => null, 'expo_token' => 'ExponentPushToken[timingxxxxxxxxxxxxxxxx]', 'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
    ]);

    $day = SiteTime::today($test->site);

    // Without events: a roster change is its own push, and not what this file measures.
    Shift::withoutEvents(static fn (): Shift => Shift::create([
        'tenant_id' => $test->site, 'guard_id' => $guard->id, 'post_id' => $test->post->id,
        'rostered_start' => $day->copy()->setTime(0, 0), 'rostered_end' => $day->copy()->setTime(23, 59),
        'actual_start' => Carbon::now()->subHour(), 'status' => 'on_duty',
    ]));

    return $guard->fresh();
}

/** The Gemini Head of Security who covers this site. */
function timingGeminiHead(TestCase $test): User
{
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    EstateAssignment::create([
        'user_id' => $head->id, 'tenant_id' => $test->site,
        'role_id' => Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id, 'is_active' => true,
    ]);

    return $head->fresh();
}

/** The client's four numbers, on this estate. */
function timingFourNumbers(string $tenantId): void
{
    SiteEmergencyNumber::query()->where('tenant_id', $tenantId)->delete();

    foreach (SiteEmergencyNumbersSeeder::NUMBERS as $index => $row) {
        app(SiteEmergencyNumbers::class)->put($tenantId, $row['role'], $row['label'], $row['number'], $index + 1);
    }
}

function timingResidentToken(TestCase $test, string $email): string
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => $test->site, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Resident '.$email,
        'resident_id' => $test->resident->id, 'unit_id' => $test->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return $account->createToken('resident-app:'.$email, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
}

function timingCall(TestCase $test, string $token, string $name, array $body = [], array $path = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = $entry['uri'];

    foreach ($path as $key => $value) {
        $uri = str_replace('{'.$key.'}', (string) $value, $uri);
    }

    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($entry['method'], '/api/v1/'.$uri, $body, [
        'Idempotency-Key' => 'timing-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** Push on, and the three things a leg can be handed to made to "work" without a network. */
function timingEverythingLive(): void
{
    config([
        'push.enabled' => true, 'push.coalesce_seconds' => 300, 'push.receipt_delay_seconds' => 0,
        'mail.default' => 'smtp',
        'broadcasting.default' => 'reverb',
    ]);

    Bus::fake([SendExpoPush::class]);
    Notification::fake();

    // Both broadcasts: the alert, and the count that follows it. Neither reaches a socket.
    Event::fake([AlertRaised::class, AlertReachKnown::class]);
}

/** @return \Illuminate\Support\Collection<int, DuressAlertDelivery> */
function timingLegs(int $alertId): Illuminate\Support\Collection
{
    return DuressAlertDelivery::query()->where('duress_alert_id', $alertId)->orderBy('id')->get();
}

/* ── 1 · every leg, one row each, a hand-off with a sane time ─────────── */

it('writes one row per destination and channel for a resident\'s panic, each a hand-off timed from the moment the platform began telling anyone', function () {
    timingEverythingLive();
    timingBareSite($this);

    timingOfficerOnDuty($this);
    $head = timingGeminiHead($this);
    FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);
    FacilitiesFixture::viewer(Role::PRESIDENT);
    timingFourNumbers($this->site);

    $answer = timingCall($this, timingResidentToken($this, 'timing.all@example.com'), 'duress.store', ['mode' => 'audible']);
    $alert = DuressAlert::query()->findOrFail($answer->json('id'));
    $legs = timingLegs($alert->id);

    // The panic itself is exactly what it always was.
    expect($answer->json('alerted_handsets'))->toBe(1)
        ->and($alert->fanout_ms)->not->toBeNull();
    Notification::assertSentTo($head, PanicRaisedAtSite::class);

    $by = static fn (string $destination, string $channel): ?DuressAlertDelivery => $legs->first(
        static fn (DuressAlertDelivery $leg): bool => $leg->destination === $destination && $leg->channel === $channel,
    );

    /*
     * THIRTEEN LEGS, NO MORE. The push; the alert mail, split into the three people it is
     * for; the four site numbers, filed under the people they belong to; the household's
     * own emergency contacts (M10 §1.2 item 7), one row; each Head of Security's own
     * phone (items 4 and 5), one row each; and the one broadcast, which three
     * destinations listen to — the dispatch screens, the estate's pop-up and, since the
     * Gemini console listens too, the Director's.
     */
    expect($legs)->toHaveCount(13);

    $pushed = $by(DuressAlertDelivery::GUARDS_ON_SHIFT, DuressAlertDelivery::PUSH);

    expect($pushed->recipients)->toBe(1)
        ->and($pushed->reached())->toBeTrue()
        ->and($pushed->note)->toBe('1 handset of 1 officer on shift or rostered today.');

    foreach ([DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY, DuressAlertDelivery::ESTATE_CONSOLE] as $mailed) {
        expect($by($mailed, DuressAlertDelivery::EMAIL)->recipients)->toBe(1)
            ->and($by($mailed, DuressAlertDelivery::EMAIL)->outcome)->toBe(DuressAlertDelivery::QUEUED);
    }

    expect($by(DuressAlertDelivery::DISPATCHER_CONSOLE, DuressAlertDelivery::BROADCAST)->outcome)->toBe(DuressAlertDelivery::SENT)
        ->and($by(DuressAlertDelivery::ESTATE_CONSOLE, DuressAlertDelivery::BROADCAST)->outcome)->toBe(DuressAlertDelivery::SENT)
        ->and($by(DuressAlertDelivery::DIRECTOR_DASHBOARD, DuressAlertDelivery::BROADCAST)->outcome)->toBe(DuressAlertDelivery::SENT)
        ->and($by(DuressAlertDelivery::DIRECTOR_DASHBOARD, DuressAlertDelivery::BROADCAST)->recipients)->toBe(1);

    /*
     * THE TEXTS, FILED UNDER WHO THEY ARE — and honestly NOT SENT, because there is no
     * provider: the log driver writes a message down and delivers it to nobody.
     */
    expect($by(DuressAlertDelivery::ESTATE_CONSOLE, DuressAlertDelivery::SMS)->recipients)->toBe(2)
        ->and($by(DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY, DuressAlertDelivery::SMS)->recipients)->toBe(1)
        ->and($by(DuressAlertDelivery::DISPATCHER_CONSOLE, DuressAlertDelivery::SMS)->recipients)->toBe(1)
        ->and($legs->where('channel', DuressAlertDelivery::SMS)->where('destination', '!=', DuressAlertDelivery::EMERGENCY_CONTACTS)
            ->reject(static fn (DuressAlertDelivery $leg): bool => str_starts_with((string) $leg->note, PanicDeliveries::OWN_PHONE))
            ->pluck('outcome')->unique()->all())->toBe([DuressAlertDelivery::SKIPPED])
        ->and($by(DuressAlertDelivery::ESTATE_CONSOLE, DuressAlertDelivery::SMS)->note)->toContain('No SMS provider');

    /*
     * AND EACH HEAD OF SECURITY'S OWN PHONE (M10 §1.2 items 4 and 5): neither gave one on
     * §1.4's page, so each row says there was nobody to text — never a site number's
     * text counted as theirs.
     */
    $own = $legs->filter(static fn (DuressAlertDelivery $leg): bool => str_starts_with((string) $leg->note, PanicDeliveries::OWN_PHONE));

    expect($own->pluck('destination')->all())->toBe([DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY])
        ->and($own->pluck('outcome')->unique()->all())->toBe([DuressAlertDelivery::NONE])
        ->and($own->first()->note)->toContain('no phone number to text');

    /*
     * THE HOUSEHOLD'S OWN CONTACTS HAVE A LEG, and this household nominated nobody: one
     * row that says so, never the site's four numbers filed under it.
     */
    $household = $by(DuressAlertDelivery::EMERGENCY_CONTACTS, DuressAlertDelivery::SMS);

    expect($household->recipients)->toBe(0)
        ->and($household->outcome)->toBe(DuressAlertDelivery::NONE)
        ->and($household->note)->toBe('The household that raised it has no emergency contacts on file.');

    // Every row is filed under one of §1.2's seven destinations, and nothing else.
    expect($legs->pluck('destination')->all())->each->toBeIn(array_keys(DuressAlertDelivery::DESTINATIONS));

    /*
     * SANE TIMES. Non-negative, bounded (ten seconds, so a loaded test machine running
     * fourteen suites cannot make this flaky — the three-second target is the PRD's to
     * judge, not a fixture's), and in the order the legs actually go since M10 §1.2 —
     * the live broadcast FIRST, then push, mail, texts.
     */
    foreach ($legs as $leg) {
        expect($leg->elapsed_ms)->toBeGreaterThanOrEqual(0)->toBeLessThan(10_000)
            ->and($leg->tenant_id)->toBe($this->site);
    }

    $push = $by(DuressAlertDelivery::GUARDS_ON_SHIFT, DuressAlertDelivery::PUSH)->elapsed_ms;
    $mail = $by(DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY, DuressAlertDelivery::EMAIL)->elapsed_ms;
    $text = $by(DuressAlertDelivery::ESTATE_CONSOLE, DuressAlertDelivery::SMS)->elapsed_ms;
    $live = $by(DuressAlertDelivery::DISPATCHER_CONSOLE, DuressAlertDelivery::BROADCAST)->elapsed_ms;

    expect($live)->toBeLessThanOrEqual($push)
        ->and($push)->toBeLessThanOrEqual($mail)
        ->and($mail)->toBeLessThanOrEqual($text)

        // Both consoles — and the Director's, which is the Gemini console — hear one publish,
        // so their three rows carry one time.
        ->and($by(DuressAlertDelivery::ESTATE_CONSOLE, DuressAlertDelivery::BROADCAST)->elapsed_ms)->toBe($live)
        ->and($by(DuressAlertDelivery::DIRECTOR_DASHBOARD, DuressAlertDelivery::BROADCAST)->elapsed_ms)->toBe($live);

    // The count that follows is NOT a destination: no row is ever written for it.
    Event::assertDispatched(AlertReachKnown::class, static fn (AlertReachKnown $event): bool => $event->alert->is($alert));
    expect($legs->where('channel', DuressAlertDelivery::BROADCAST))->toHaveCount(3);

    /*
     * AND THE LIST A PERSON READS. All seven destinations: the household's contacts, of
     * whom there are none on file, said to be not reached, with the reason — and not one
     * label claiming a delivery.
     */
    $report = app(DeliveryReport::class)->forAlert($alert);

    expect($report['of'])->toBe(7)
        ->and($report['reached'])->toBe(6)
        ->and(collect($report['destinations'])->where('reached', false)->pluck('key')->all())
        ->toBe([DuressAlertDelivery::EMERGENCY_CONTACTS])
        ->and(collect($report['destinations'])->firstWhere('key', DuressAlertDelivery::EMERGENCY_CONTACTS)['reason'])->toContain('no emergency contacts on file')
        ->and($report['caption'])->toContain('not when anybody\'s phone received it');

    // The fan-out's sentence claims what the legs record: pushed, mailed — and no text, since no provider took one.
    expect($report['fanout'])->toBe('The live channel went first. Guards pushed and alert mail queued within '.(int) $alert->fanout_ms.' ms of the fan-out starting. No text was handed off.');

    foreach (collect($report['destinations'])->flatMap(static fn (array $d): array => $d['legs']) as $leg) {
        expect($leg['elapsed_label'])->toMatch('/^(handed off in \d+ ms|not handed off|failed at \d+ ms)$/')
            ->and(strtolower($leg['outcome_label']))->not->toContain('deliver');
    }
});

it('says in a sentence when a leg went nowhere, instead of going quiet', function () {
    // The suite's own defaults: push off, broadcasting `null`, mail to an array.
    timingBareSite($this);

    $answer = timingCall($this, timingResidentToken($this, 'timing.nowhere@example.com'), 'duress.store');
    $legs = timingLegs((int) $answer->json('id'));

    expect($answer->json('console_alerted'))->toBeTrue();

    $push = $legs->firstWhere('destination', DuressAlertDelivery::GUARDS_ON_SHIFT);

    expect($push->outcome)->toBe(DuressAlertDelivery::SKIPPED)
        ->and($push->note)->toContain('Push is switched off');

    // Nobody on the alert mail's list: three rows of "nobody to tell", each saying who.
    $mail = $legs->where('channel', DuressAlertDelivery::EMAIL);

    expect($mail)->toHaveCount(3)
        ->and($mail->pluck('outcome')->unique()->all())->toBe([DuressAlertDelivery::NONE])
        ->and($mail->firstWhere('destination', DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY)->note)->toContain('No Gemini Head of Security');

    // No live channel configured: the consoles poll, and the pop-up cannot appear — said so.
    $broadcast = $legs->where('channel', DuressAlertDelivery::BROADCAST);

    expect($broadcast->pluck('outcome')->unique()->all())->toBe([DuressAlertDelivery::SKIPPED])
        ->and($broadcast->firstWhere('destination', DuressAlertDelivery::ESTATE_CONSOLE)->note)->toContain('no pop-up can appear');

    // No numbers on file: no text leg is invented for the site's numbers…
    $own = static fn (DuressAlertDelivery $leg): bool => str_starts_with((string) $leg->note, PanicDeliveries::OWN_PHONE);

    expect($legs->where('channel', DuressAlertDelivery::SMS)->where('destination', '!=', DuressAlertDelivery::EMERGENCY_CONTACTS)->reject($own))->toBeEmpty();

    // …no Head of Security is on file to text, and each row says which one is missing…
    expect($legs->filter($own)->pluck('outcome')->unique()->all())->toBe([DuressAlertDelivery::NONE])
        ->and($legs->filter($own)->firstWhere('destination', DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY)->note)->toContain('no Gemini Head of Security covering this site');

    // …and the household's own contacts say, in a sentence, that there is nobody to text.
    expect($legs->firstWhere('destination', DuressAlertDelivery::EMERGENCY_CONTACTS)->outcome)->toBe(DuressAlertDelivery::NONE)
        ->and($legs->firstWhere('destination', DuressAlertDelivery::EMERGENCY_CONTACTS)->note)->toContain('no emergency contacts on file');

    $report = app(DeliveryReport::class)->forAlert(DuressAlert::query()->findOrFail($answer->json('id')));

    expect($report['reached'])->toBe(0);

    /*
     * AND THE SUMMARY SAYS NOTHING WENT (production's first walk, 25 September): it used to read
     * "Guards pushed and alert mail queued within … ms; the texts follow" beside a table in which
     * no guard was pushed and every text went to nobody.
     */
    if ($report['fanout'] !== null) {
        expect($report['fanout'])->toBe('The live channel was not reached. Nothing went to the push or mail queue. No text was handed off.');
    }
});

it('says which of the fan-out\'s legs went, and no more, when some did and some did not', function () {
    // Production on 25 September: the live channel and the alert mail went; no guard's handset and no text.
    $alert = DuressAlert::withoutEvents(fn (): DuressAlert => tap((new DuressAlert)->forceFill([
        'tenant_id' => $this->site, 'kind' => 'panic', 'status' => 'open', 'server_time' => now(), 'is_simulated' => false, 'fanout_ms' => 121,
    ]))->save());

    foreach ([
        [DuressAlertDelivery::GUARDS_ON_SHIFT, DuressAlertDelivery::PUSH, DuressAlertDelivery::NONE, 0],
        [DuressAlertDelivery::DISPATCHER_CONSOLE, DuressAlertDelivery::BROADCAST, DuressAlertDelivery::SENT, 1],
        [DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY, DuressAlertDelivery::EMAIL, DuressAlertDelivery::QUEUED, 1],
        [DuressAlertDelivery::EMERGENCY_CONTACTS, DuressAlertDelivery::SMS, DuressAlertDelivery::NONE, 0],
    ] as [$destination, $channel, $outcome, $recipients]) {
        (new DuressAlertDelivery)->forceFill([
            'duress_alert_id' => $alert->id, 'tenant_id' => $this->site, 'destination' => $destination, 'channel' => $channel,
            'outcome' => $outcome, 'recipients' => $recipients, 'elapsed_ms' => $outcome === DuressAlertDelivery::NONE ? 0 : 87,
        ])->save();
    }

    expect(app(DeliveryReport::class)->forAlert($alert->fresh())['fanout'])
        ->toBe('The live channel went first. Alert mail queued within 121 ms of the fan-out starting. No guard was pushed. No text was handed off.');
});

/* ── 3 · a failure while recording breaks nothing ────────────────────── */

it('does not let a failure while recording the hand-offs touch the panic', function () {
    timingEverythingLive();
    Exceptions::fake();
    timingBareSite($this);

    timingOfficerOnDuty($this);
    $head = timingGeminiHead($this);
    timingFourNumbers($this->site);

    /*
     * THE RECORDER'S ONE WRITE THROWS. Bound before anything resolves it, and the
     * announcer forgotten so the fan-out it holds is built again around this one.
     */
    app()->instance(PanicDeliveries::class, new class(app(SiteEmergencyNumbers::class)) extends PanicDeliveries
    {
        protected function insert(array $rows): void
        {
            throw new RuntimeException('the hand-off record could not be written');
        }
    });
    app()->forgetInstance(PushAnnouncer::class);

    $answer = timingCall($this, timingResidentToken($this, 'timing.broken@example.com'), 'duress.store', ['mode' => 'audible']);
    $alert = DuressAlert::query()->findOrFail($answer->json('id'));

    // The resident is answered with the truth, exactly as before.
    expect($answer->json('alerted_handsets'))->toBe(1)
        ->and($answer->json('console_alerted'))->toBeTrue()
        ->and($answer->json('escalated_contacts'))->toBe(1)
        ->and($answer->json('sms_recipients'))->toBe(4);

    // Every leg went: the push, the mail, the broadcast — and `fanout_ms` still written.
    Bus::assertDispatched(SendExpoPush::class, static fn (SendExpoPush $job): bool => $job->urgent);
    Notification::assertSentTo($head, PanicRaisedAtSite::class);
    Event::assertDispatched(AlertRaised::class, static fn (AlertRaised $event): bool => $event->alert->is($alert));
    expect($alert->fanout_ms)->not->toBeNull();

    // The only thing lost is the record — and the loss was reported, not swallowed silently.
    expect(timingLegs($alert->id))->toBeEmpty();
    Exceptions::assertReported(static fn (RuntimeException $e): bool => $e->getMessage() === 'the hand-off record could not be written');
});

/* ── 4 · append-only, for real ───────────────────────────────────────── */

it('refuses to update or delete a hand-off record, at the database', function () {
    $id = DB::connection('mysql')->table('duress_alert_deliveries')->insertGetId([
        'duress_alert_id' => 999999001, 'tenant_id' => $this->site, 'destination' => DuressAlertDelivery::GUARDS_ON_SHIFT,
        'channel' => DuressAlertDelivery::PUSH, 'recipients' => 2, 'outcome' => DuressAlertDelivery::QUEUED,
        'elapsed_ms' => 9, 'note' => null, 'created_at' => now(),
    ]);

    // Through the query builder, so this is the DATABASE refusing and not Eloquent declining.
    expect(fn () => DB::connection('mysql')->table('duress_alert_deliveries')->where('id', $id)->update(['elapsed_ms' => 1]))
        ->toThrow(QueryException::class, 'duress_alert_deliveries is append-only');

    expect(fn () => DB::connection('mysql')->table('duress_alert_deliveries')->where('id', $id)->delete())
        ->toThrow(QueryException::class, 'duress_alert_deliveries is append-only');

    // And through the model, which a later screen written in good faith would use.
    expect(fn () => DuressAlertDelivery::query()->findOrFail($id)->forceFill(['outcome' => DuressAlertDelivery::SENT])->save())
        ->toThrow(QueryException::class, 'duress_alert_deliveries is append-only');

    $kept = DuressAlertDelivery::query()->findOrFail($id);

    expect($kept->elapsed_ms)->toBe(9)
        ->and($kept->outcome)->toBe(DuressAlertDelivery::QUEUED);

    $triggers = DB::connection('mysql')->table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', DB::connection('mysql')->getDatabaseName())
        ->where('EVENT_OBJECT_TABLE', 'duress_alert_deliveries')
        ->pluck('EVENT_MANIPULATION')->map(static fn ($event): string => strtoupper((string) $event))->all();

    // The grant half, as a declaration: `grants:append-only` reads exactly this list.
    expect($triggers)->toContain('UPDATE')->toContain('DELETE')
        ->and(GrantedTables::CENTRAL_APPEND_ONLY)->toContain('duress_alert_deliveries')
        ->and(GrantedTables::CENTRAL_MUTABLE)->not->toContain('duress_alert_deliveries')
        ->and(ApplyCentralAppendOnlyGrants::APPEND_ONLY_TABLES)->toContain('duress_alert_deliveries');
});

/* ── 5 · panic:walk ──────────────────────────────────────────────────── */

it('refuses to walk a real estate, or to run in production, without --force — and raises nothing', function () {
    $before = DuressAlert::query()->where('is_drill', true)->count();

    $this->artisan('panic:walk', ['estate' => $this->site, '--lot' => 'Lot 47'])
        ->expectsOutputToContain('is not a demonstration estate')
        ->assertExitCode(1);

    app()['env'] = 'production';

    $this->artisan('panic:walk', ['estate' => $this->site, '--lot' => 'Lot 47'])
        ->expectsOutputToContain('will not run in production without --force')
        ->assertExitCode(1);

    app()['env'] = 'testing';

    $this->artisan('panic:walk', ['estate' => 'no-such-estate'])
        ->expectsOutputToContain('No estate [no-such-estate]')
        ->assertExitCode(1);

    expect(DuressAlert::query()->where('is_drill', true)->count())->toBe($before);
});

it('walks a drill through the resident\'s path on a demonstration estate and prints every destination, NOT REACHED with the reason', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300, 'push.receipt_delay_seconds' => 0]);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    timingBareSite($this);
    timingOfficerOnDuty($this);
    timingGeminiHead($this);

    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => true])->save();

    // One substring per printed line: the console mock marks only one expectation per write.
    $this->artisan('panic:walk', ['estate' => $this->site, '--lot' => 'Lot 47'])
        ->expectsOutputToContain('raised at Phoenix Park ['.$this->site.'], Lot 47, through the resident\'s path')
        ->expectsOutputToContain('hand-off times, never arrival on a phone')
        ->expectsOutputToContain('Guards on shift')
        ->expectsOutputToContain('NOT REACHED  Gemini dispatch — Demonstration estate: Gemini\'s consoles do not listen to its channel')
        ->expectsOutputToContain('NOT REACHED  Gemini Director — Demonstration estate: Gemini\'s consoles do not listen to its channel')
        ->expectsOutputToContain('NOT REACHED  Gemini Head of Security for this site — Demonstration estate or simulated alert: nobody real is mailed')
        ->expectsOutputToContain('NOT REACHED  The household\'s emergency contacts — Demonstration estate or simulated alert: nobody real is texted')
        ->expectsOutputToContain('1 of 7 destinations reached.')
        ->expectsOutputToContain('The drill is still open')
        ->assertSuccessful();

    $drill = DuressAlert::query()->where('tenant_id', $this->site)->where('is_drill', true)->latest('id')->firstOrFail();

    // The resident's path, not a model of it: a panic, at the lot, answered like one.
    expect($drill->kind)->toBe('panic')
        ->and($drill->unit_reference)->toBe('Lot 47')
        ->and($drill->is_simulated)->toBeFalse()
        ->and($drill->alerted_handsets)->toBe(1)
        ->and($drill->getAttribute('mode'))->toBe('silent');

    // Its push says DRILL on the lock screen, and what was printed is what was written.
    Bus::assertDispatched(SendExpoPush::class, static fn (SendExpoPush $job): bool => str_starts_with($job->title, DuressAlert::DRILL_PREFIX));
    expect(timingLegs($drill->id)->firstWhere('destination', DuressAlertDelivery::GUARDS_ON_SHIFT)->reached())->toBeTrue();
});

it('refuses a lot the estate does not have, and raises nothing', function () {
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => true])->save();
    $before = DuressAlert::query()->where('is_drill', true)->count();

    $this->artisan('panic:walk', ['estate' => $this->site, '--lot' => 'Lot 99999'])
        ->expectsOutputToContain('No lot [Lot 99999]')
        ->assertExitCode(1);

    expect(DuressAlert::query()->where('is_drill', true)->count())->toBe($before);
});

/* ── the estate pop-up is told the truth, not a reassurance ──────────── */

/**
 * Listen for both broadcasts for real (to the `null` broadcaster, so no socket), and
 * write down, AT THE MOMENT EACH IS SENT, its payload and how much of the fan-out had
 * already been queued. A fake would record the event and let a test read the payload
 * later, off a model the fan-out had since filled in — which would prove nothing about
 * order.
 *
 * @return object{sent: list<array<string, mixed>>}
 */
function timingRecordTheBroadcasts(?User $mailed = null): object
{
    config(['broadcasting.default' => 'null']);

    $log = new class
    {
        /** @var list<array<string, mixed>> */
        public array $sent = [];
    };

    $snapshot = static fn (string $event, array $payload): array => [
        'event' => $event,
        'payload' => $payload,
        'pushes_queued' => Bus::dispatched(SendExpoPush::class)->count(),
        'texts_queued' => Bus::dispatched(SendPanicSms::class)->count(),
        'mails_queued' => $mailed === null ? 0 : Notification::sent($mailed, PanicRaisedAtSite::class)->count(),
    ];

    Event::listen(AlertRaised::class, static function (AlertRaised $event) use ($log, $snapshot): void {
        $log->sent[] = $snapshot('alert.raised', $event->broadcastWith());
    });

    Event::listen(AlertReachKnown::class, static function (AlertReachKnown $event) use ($log, $snapshot): void {
        $log->sent[] = $snapshot('alert.reach', $event->broadcastWith());
    });

    return $log;
}

it('tells the consoles FIRST — before any officer is pushed, any mail queued or any text queued — and then what the fan-out reached', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300, 'push.receipt_delay_seconds' => 0, 'mail.default' => 'smtp']);
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    timingBareSite($this);

    timingOfficerOnDuty($this);
    $head = timingGeminiHead($this);
    timingFourNumbers($this->site);

    $log = timingRecordTheBroadcasts($head);

    $answer = timingCall($this, timingResidentToken($this, 'timing.order@example.com'), 'duress.store', ['mode' => 'audible']);
    $id = (int) $answer->json('id');

    // Two broadcasts, in this order, and nothing else on the channel.
    expect(array_column($log->sent, 'event'))->toBe(['alert.raised', 'alert.reach']);

    [$raised, $reach] = $log->sent;

    /*
     * §1.2, "no step gated behind another": when the consoles were told, NOTHING of the
     * fan-out had been queued yet — and the payload did not pretend to know a count.
     */
    expect($raised)->toMatchArray(['pushes_queued' => 0, 'mails_queued' => 0, 'texts_queued' => 0])
        ->and($raised['payload'])->toMatchArray(['id' => $id, 'alerted_handsets' => null, 'alerted_via' => null]);

    // And by the time the count went out, every leg had been handed off.
    expect($reach['pushes_queued'])->toBe(1)
        ->and($reach['mails_queued'])->toBe(1)
        ->and($reach['texts_queued'])->toBe(1)
        ->and($reach['payload'])->toBe([
            'id' => $id,
            'estate' => $this->site,
            'alerted_handsets' => 1,
            'alerted_via' => 'on_duty',
            'is_drill' => false,
        ]);

    // The resident's own answer is exactly what it always was: the fan-out still ran first for THEM.
    expect($answer->json('alerted_handsets'))->toBe(1)
        ->and($answer->json('console_alerted'))->toBeTrue();

    // The hand-off record was written before the count went out, so a screen re-reading on it finds it —
    // all thirteen legs, the household's own emergency contacts (M10 §1.2 item 7), each
    // Head of Security's own phone (items 4 and 5) and the Director's among them.
    expect(timingLegs($id))->toHaveCount(13);
});

it('sends the count on the alert\'s own private channel, as `alert.reach`, with only the count in it', function () {
    // Written without events, and the fan-out's columns forced: they are the fan-out's to fill, not fillable.
    $alert = DuressAlert::withoutEvents(fn (): DuressAlert => tap((new DuressAlert)->forceFill([
        'tenant_id' => $this->site, 'kind' => 'panic', 'status' => 'open', 'server_time' => now(), 'is_simulated' => false,
        'alerted_handsets' => 3, 'alerted_via' => 'rostered', 'is_drill' => true,
    ]))->save());

    $event = new AlertReachKnown($alert);

    // Taken when the event is made: a later change to the model does not rewrite what was sent.
    $alert->forceFill(['alerted_handsets' => 99]);

    expect($event->broadcastAs())->toBe('alert.reach')
        ->and(collect($event->broadcastOn())->map(static fn ($channel): string => $channel->name)->all())
        ->toBe(['private-estate.'.$this->site.'.alerts'])
        ->and($event->broadcastWith())->toBe([
            'id' => (int) $alert->id,
            'estate' => $this->site,
            'alerted_handsets' => 3,
            'alerted_via' => 'rostered',
            'is_drill' => true,
        ])
        ->and($event)->toBeInstanceOf(ShouldBroadcastNow::class);
});

it('gives the estate\'s pop-up only what is true when each broadcast leaves: no count yet, then the count, the control room and who to call', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300, 'push.receipt_delay_seconds' => 0]);
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    timingBareSite($this);

    $log = timingRecordTheBroadcasts();

    // NOBODY: no officer, no numbers. No count on the alert; a zero, said as a zero, after it.
    $nobody = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Rae Stopwatch', unitReference: 'Lot 734');

    expect($log->sent[0]['payload'])->toMatchArray(['id' => $nobody->id, 'alerted_handsets' => null, 'control_room' => true, 'call' => null])
        ->and($log->sent[1]['payload'])->toMatchArray(['id' => $nobody->id, 'alerted_handsets' => 0, 'alerted_via' => 'none']);

    // AN OFFICER ON DUTY, and the site's four numbers: Gemini Dispatch's line on the alert, the count after.
    timingOfficerOnDuty($this);
    timingFourNumbers($this->site);
    $log->sent = [];

    $reached = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Rae Stopwatch', unitReference: 'Lot 734');

    expect($log->sent[0]['payload'])->toMatchArray([
        'id' => $reached->id,
        'alerted_handsets' => null,
        'control_room' => true,
        'call' => ['label' => 'Gemini Dispatch', 'number' => '876-354-2119'],
    ])
        ->and($log->sent[1]['payload'])->toMatchArray(['id' => $reached->id, 'alerted_handsets' => 1, 'alerted_via' => 'on_duty']);

    // A SIMULATED ALERT reaches the consoles and rings no phone: there is no count to send, so none is.
    $log->sent = [];
    $simulated = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Simulator', isSimulated: true);

    expect(array_column($log->sent, 'event'))->toBe(['alert.raised'])
        ->and($log->sent[0]['payload'])->toMatchArray(['id' => $simulated->id, 'is_simulated' => true, 'alerted_handsets' => null]);

    // THE DEMONSTRATION ESTATE: Gemini's control room does not receive it, and the pop-up must not say it does.
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => true])->save();

    expect((new AlertRaised($reached->fresh()))->broadcastWith()['control_room'])->toBeFalse();
});

it('puts the reach on the dispatcher\'s queue row: alerting while it is on its way, the count once known, never "alerting" for ever', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    // Each written without events and with the fan-out's columns forced, as the fan-out leaves them.
    $alert = function (?int $handsets, ?string $via, Carbon $at, bool $simulated = false): DuressAlert {
        return DuressAlert::withoutEvents(fn (): DuressAlert => tap((new DuressAlert)->forceFill([
            'tenant_id' => $this->site, 'kind' => 'panic', 'status' => 'open', 'raised_by_name' => 'Rae Stopwatch',
            'server_time' => $at, 'is_simulated' => $simulated, 'alerted_handsets' => $handsets, 'alerted_via' => $via,
        ]))->save());
    };

    $onItsWay = $alert(null, null, now());
    $overdue = $alert(null, null, now()->subMinutes(5));
    $nobody = $alert(0, 'none', now());
    $three = $alert(3, 'on_duty', now());
    $rostered = $alert(2, 'rostered', now());
    $simulated = $alert(null, null, now(), simulated: true);

    $meta = [];

    // A full closure, not an arrow function: an arrow function would capture `$meta` by value.
    $this->actingAs($director)->get('/dispatch/alerts')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$meta): void {
            $page->where('alerts', function ($rows) use (&$meta): bool {
                $meta = collect($rows)->pluck('meta', 'key')->all();

                return true;
            });
        });

    expect($meta['alert-'.$onItsWay->id])->toContain('Alerting officers…')
        ->and($meta['alert-'.$overdue->id])->toContain('Officers\' phones reached: not recorded')
        ->and($meta['alert-'.$overdue->id])->not->toContain('Alerting')
        ->and($meta['alert-'.$nobody->id])->toContain('No officer\'s phone reached')
        ->and($meta['alert-'.$three->id])->toContain('Officers\' phones alerted: 3')
        ->and($meta['alert-'.$rostered->id])->toContain('Officers\' phones alerted: 2 (rostered today, none clocked on)')
        ->and($meta['alert-'.$simulated->id])->not->toContain('Alerting')
        ->and($meta['alert-'.$simulated->id])->not->toContain('phone');
});

it('broadcasts nothing until the alert has committed, and nothing at all for an alert rolled back', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300, 'push.receipt_delay_seconds' => 0]);
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    timingBareSite($this);
    timingOfficerOnDuty($this);

    $log = timingRecordTheBroadcasts();

    // Inside a transaction: the row exists, and nobody has been told yet — not the consoles, not a guard.
    DB::connection('mysql')->transaction(function () use ($log): void {
        app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Rae Stopwatch', unitReference: 'Lot 734');

        expect($log->sent)->toBe([]);
        Bus::assertNotDispatched(SendExpoPush::class);
    });

    // On commit: the consoles first, then the count.
    expect(array_column($log->sent, 'event'))->toBe(['alert.raised', 'alert.reach'])
        ->and($log->sent[0]['pushes_queued'])->toBe(0)
        ->and($log->sent[1]['pushes_queued'])->toBe(1);

    // Rolled back: an alert that does not exist is announced to nobody.
    $log->sent = [];

    try {
        DB::connection('mysql')->transaction(function (): void {
            app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Rae Stopwatch', unitReference: 'Lot 734');

            throw new RuntimeException('the request failed after the insert');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($log->sent)->toBe([]);
    Bus::assertDispatchedTimes(SendExpoPush::class, 1);
});

/* ── 6 · a drill is labelled, and never moves a response time ────────── */

it('labels a drill DRILL on the push, the text, the broadcast, the queue, the response screen, the bell and the estate\'s list, and mails no Head of Security', function () {
    timingEverythingLive();
    timingBareSite($this);
    timingOfficerOnDuty($this);
    $head = timingGeminiHead($this);

    $drill = app(AlertIntake::class)->record(
        tenantId: $this->site, kind: 'panic', raisedByName: 'Drill (panic:walk)', unitReference: 'Lot 47', isDrill: true,
    );

    Bus::assertDispatched(SendExpoPush::class, static fn (SendExpoPush $job): bool => $job->title === 'DRILL: Panic alert');
    // A drill wakes nobody outside its officers (M73 §0.2, `f3fb090a`): the escalation mail, "DRILL" in its subject until
    // then, is not sent at all. ADrillWakesNobodyOutsideTest holds the other four outside legs to the same rule.
    Notification::assertNotSentTo($head, PanicRaisedAtSite::class);
    Event::assertDispatched(AlertRaised::class, static fn (AlertRaised $event): bool => $event->broadcastWith()['is_drill'] === true);

    expect(PanicSms::body($drill))->toContain('DRILL PANIC ALARM')
        ->and(strlen(PanicSms::body($drill)))->toBeLessThanOrEqual(PanicSms::MAX_LENGTH);

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)->get('/dispatch/alerts/'.$drill->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Dispatch/Alert')
            ->where('alert.headline', 'DRILL: Panic alert')
            ->where('alert.is_drill', true)
            ->where('deliveries.recorded', true)
            ->where('deliveries.of', 7));

    $this->actingAs($director)->get('/dispatch/alerts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('alerts', fn ($rows) => collect($rows)->contains(
                static fn (array $row): bool => $row['key'] === 'alert-'.$drill->id && str_starts_with($row['title'], 'DRILL: '),
            )));

    $bell = collect(app(PlatformNotifications::class)->attention($director->fresh())['items'])
        ->firstWhere('href', '/dispatch/alerts/'.$drill->id);

    expect($bell['title'])->toStartWith('DRILL: Panic')
        ->and(collect(app(EstateSecurity::class)->alerts($this->site))->firstWhere('id', $drill->id)['is_drill'])->toBeTrue();

    // And a real alert says nothing of the kind, anywhere.
    $real = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Rae Stopwatch', unitReference: 'Lot 734');

    expect($real->is_drill)->toBeFalse()
        ->and($real->drillPrefix())->toBe('')
        ->and(PanicSms::body($real))->not->toContain('DRILL');
});

it('draws the dispatcher\'s queue and response screen in readable text, not double-encoded dashes and dots', function () {
    /*
     * THE SCREEN THIS PANEL SITS ON SAID "Rae Stopwatch Â· Phoenix Park Â· Lot 734"
     * and "Resolved â€” false alarm". DispatchController's separators and dashes had been
     * saved double-encoded, and every meta line, subtitle and resolved pill on the two
     * dispatch screens carried the damage to a dispatcher mid-incident.
     */
    $alert = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Rae Stopwatch', unitReference: 'Lot 734');
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $garbled = static fn (string $text): bool => str_contains($text, 'Â') || str_contains($text, 'â€');

    $this->actingAs($director)->get('/dispatch/alerts/'.$alert->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('alert.subtitle', fn ($subtitle) => str_contains((string) $subtitle, 'Rae Stopwatch · ') && ! $garbled((string) $subtitle))
            ->where('timeline', fn ($steps) => collect($steps)->every(static fn (array $step): bool => ! $garbled($step['title'].$step['meta']))));

    $this->actingAs($director)->get('/dispatch/alerts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('alerts', fn ($rows) => collect($rows)
                ->where('key', 'alert-'.$alert->id)
                ->every(static fn (array $row): bool => $row['title'] === 'Rae Stopwatch: panic button' && ! $garbled($row['meta']))));
});

it('tells a resident\'s own status screen when their alert is a drill, and that it is not when it is not', function () {
    $token = timingResidentToken($this, 'timing.status@example.com');
    $answer = timingCall($this, $token, 'duress.store');

    expect($answer->json('is_drill'))->toBeFalse();

    DuressAlert::query()->whereKey($answer->json('id'))->update(['is_drill' => true]);

    expect(timingCall($this, $token, 'duress.show', path: ['alert' => $answer->json('id')])->json('is_drill'))->toBeTrue();
});

it('leaves a drill out of the acknowledgement-time figure, however long it sat', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $average = static fn (): string => (string) collect(app(LiveMap::class)->forViewer($director->fresh())['kpis'])
        ->firstWhere('label', 'Avg. acknowledgement time, 30d')['value'];

    $acknowledged = function (bool $drill): void {
        // Written without events: this is about the figure, not about who a panic reaches.
        DuressAlert::withoutEvents(fn () => DuressAlert::create([
            'tenant_id' => $this->site, 'kind' => 'panic', 'status' => 'acknowledged', 'raised_by_name' => 'Rae Stopwatch',
            'server_time' => now()->subHours(4), 'acknowledged_at' => now()->subHour(), 'is_simulated' => false, 'is_drill' => $drill,
        ]));
    };

    $before = $average();

    // A drill left open for three hours while somebody walked the consoles.
    $acknowledged(true);

    expect($average())->toBe($before);

    // The same three hours on a real alert does move it — so the assertion above is not vacuous.
    $acknowledged(false);

    expect($average())->not->toBe($before);
});
