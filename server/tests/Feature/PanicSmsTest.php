<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Alerts\SendPanicSms;
use App\Jobs\Push\SendExpoPush;
use App\Models\AlertSmsDispatch;
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
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Notifications\PanicRaisedAtSite;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\PanicSms;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Services\Push\PanicFanOut;
use App\Services\ResidentApp\LogSmsGateway;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Support\JamaicanNumber;
use App\Support\SiteTime;
use Database\Seeders\SiteEmergencyNumbersSeeder;
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
| A PANIC ALSO GOES OUT AS A TEXT — Q-M09
|--------------------------------------------------------------------------
|
| The client's ruling, verbatim:
|
|   "Agree with your recommendations. Additionally the SMS must be sent to:
|    President (1-876-508-8082), Property Office (1-876-224-4444), PPV1 Head of
|    Security (1-876-845-9422) and Gemini Dispatch (876-354-2119)."
|
| What this file proves, in the order the ruling has to be proved:
|
|   1  all four of the site's numbers are texted on a panic, in the site's own order
|   2  a provider that FAILS does not stop the console, the push or the escalation
|   3  what is recorded matches what actually happened, one row per number
|   4  the numbers come from the SITE's settings, and a second estate gets its own
|   5  the message says WHERE and WHAT and names nobody
|   6  the demonstration estate and a simulated alert text nobody at all
|
| NO TEST REACHES A NETWORK. There is no SMS provider; the bound gateway is the log
| driver, which records and delivers nothing, and `Http::preventStrayRequests()` fails
| anything that tried.
|
| SHUFFLED ORDERING IS ON, so every precondition is made true here and then asserted.
| The central database is wrapped in a transaction with the framework's TESTING
| transaction manager, so the "after commit" announcement a panic makes fires here as
| it does in production.
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
    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Text Gate', 'type' => 'gate', 'is_active' => true]);

    // Not Lot 903: `EstateStructureWritesTest` builds Lots 901–910 in its own "Hillside"
    // phase on this same committed estate, and in a shuffled run that got there first.
    $this->unit = FacilitiesFixture::scratchUnit('Lot 923');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Text household', 'access_restricted' => false]);
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Rae Textcheck'],
        ['relationship' => 'owner', 'is_primary' => true],
    );
    $this->resident->forceFill(['is_primary' => true, 'removed_at' => null])->save();

    // This estate is not the demonstration one, and it is on Kingston's clock. Both are
    // asserted rather than assumed: another file may have moved either.
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

/* ── helpers ─────────────────────────────────────────────────────────── */

/**
 * The client's four numbers, on THIS test's estate, written exactly as the client
 * wrote them — three with the 1-876 prefix and one without.
 */
function smsFourNumbers(string $tenantId): void
{
    SiteEmergencyNumber::query()->where('tenant_id', $tenantId)->delete();

    $numbers = app(SiteEmergencyNumbers::class);

    foreach (SiteEmergencyNumbersSeeder::NUMBERS as $index => $row) {
        $numbers->put($tenantId, $row['role'], $row['label'], $row['number'], $index + 1);
    }
}

/** Nobody clocked on, nobody rostered, nobody to escalate to — asserted, not assumed. */
function smsBareSite(TestCase $test): void
{
    Shift::query()->where('tenant_id', $test->site)->delete();
    Guard::query()->where('tenant_id', $test->site)->update(['status' => 'inactive', 'device_id' => null]);
    EstateAssignment::query()->where('tenant_id', $test->site)->update(['is_active' => false]);
}

function smsCall(TestCase $test, string $token, string $name, array $body = [], array $path = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = $entry['uri'];

    foreach ($path as $key => $value) {
        $uri = str_replace('{'.$key.'}', (string) $value, $uri);
    }

    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($entry['method'], '/api/v1/'.$uri, $body, [
        'Idempotency-Key' => 'sms-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** @return array{0: ResidentAccount, 1: string} */
function smsResident(string $tenant, string $email, int $unitId, int $residentId): array
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => $tenant, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Resident '.$email,
        'resident_id' => $residentId, 'unit_id' => $unitId, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$account, $account->createToken('resident-app:'.$email, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

/**
 * A gateway that records every number it is handed and answers however this test says.
 *
 * Bound in place of the log driver, which is the ONLY implementation this platform has
 * — proving the failure path needs a provider that can fail, and inventing credentials
 * to get one would be the one thing the ruling forbids.
 */
function smsGatewaySpy(string $answer = SmsResult::SENT): object
{
    $spy = new class($answer) implements SmsGateway
    {
        /** @var list<array{to: string, message: string}> */
        public array $sent = [];

        public function __construct(private readonly string $answer) {}

        public function send(string $to, string $message): SmsResult
        {
            $this->sent[] = ['to' => $to, 'message' => $message];

            return match ($this->answer) {
                SmsResult::SENT => SmsResult::sent('test', 'ref-'.count($this->sent)),
                SmsResult::FAILED => SmsResult::failed('test', 'ProviderRefused'),
                'throw' => throw new RuntimeException('the provider is on fire'),
                default => SmsResult::recorded('test'),
            };
        }
    };

    app()->instance(SmsGateway::class, $spy);

    return $spy;
}

/* ── 1 · all four numbers are texted on a panic ──────────────────────── */

it('texts all four of the site\'s emergency numbers when a resident presses panic, in the site\'s own order', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    smsBareSite($this);
    smsFourNumbers($this->site);

    [, $residentToken] = smsResident($this->site, 'four.numbers@example.com', $this->unit->id, $this->resident->id);

    $answer = smsCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    // The API says four were attempted, and says nothing yet about delivery.
    expect($answer->json('sms_recipients'))->toBe(4)
        ->and($answer->json('sms_delivered'))->toBeNull();

    // THE JOB IS QUEUED, not run in the request: nothing about a panic waits on a carrier.
    Bus::assertDispatched(SendPanicSms::class, fn (SendPanicSms $job): bool => $job->alertId === (int) $answer->json('id'));

    // And when it runs, all four are handed to the gateway, in the client's order.
    $spy = smsGatewaySpy(SmsResult::SENT);
    (new SendPanicSms((int) $answer->json('id')))->handle(app(SiteEmergencyNumbers::class), $spy);

    expect(array_column($spy->sent, 'to'))->toBe(['+18765088082', '+18762244444', '+18768459422', '+18763542119']);

    $alert = DuressAlert::query()->findOrFail($answer->json('id'));

    expect($alert->sms_recipients)->toBe(4)
        ->and($alert->sms_delivered)->toBe(4);

    // The read-back the panic screen polls says the same thing.
    $again = smsCall($this, $residentToken, 'duress.show', path: ['alert' => $alert->id]);

    expect($again->json('sms_recipients'))->toBe(4)
        ->and($again->json('sms_delivered'))->toBe(4);
});

it('reads the three 1-876 numbers and the one written without it as the same kind of number', function () {
    // The client gave three with the country code and one without. Neither form is
    // rewritten in the table; both resolve to the same diallable shape.
    expect(JamaicanNumber::e164('1-876-508-8082'))->toBe('+18765088082')
        ->and(JamaicanNumber::e164('876-354-2119'))->toBe('+18763542119')
        ->and(JamaicanNumber::e164('(876) 354 2119'))->toBe('+18763542119')
        ->and(JamaicanNumber::e164('+44 20 7946 0000'))->toBe('+442079460000')
        // NOTHING IS GUESSED. Seven digits with no area code, a short code and rubbish
        // are refused rather than completed into a number nobody meant.
        ->and(JamaicanNumber::e164('354-2119'))->toBeNull()
        ->and(JamaicanNumber::e164('119'))->toBeNull()
        ->and(JamaicanNumber::e164('not a number'))->toBeNull();

    smsFourNumbers($this->site);

    $stored = SiteEmergencyNumber::query()->where('tenant_id', $this->site)->orderBy('sort')->get();

    // Stored EXACTLY as the client wrote them — the platform never edits what it was given.
    expect($stored->pluck('number')->all())->toBe(['1-876-508-8082', '1-876-224-4444', '1-876-845-9422', '876-354-2119'])
        ->and($stored->pluck('e164')->all())->toBe(['+18765088082', '+18762244444', '+18768459422', '+18763542119'])
        ->and($stored->pluck('label')->all())->toBe(['President', 'Property Office', 'PPV1 Head of Security', 'Gemini Dispatch']);
});

/* ── 2 · a provider that fails stops nothing ─────────────────────────── */

it('gives the console, the guards on duty and the escalation contact everything they already got, even when every text fails', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300, 'push.receipt_delay_seconds' => 0]);
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    smsBareSite($this);
    smsFourNumbers($this->site);

    // An officer clocked on, with a handset: the push path must be untouched.
    $guard = Guard::create([
        'full_name' => 'Officer Textcheck', 'employee_number' => 'GS-SMS-1', 'psra_number' => 'PSRA-GS-SMS-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->site, 'post_id' => $this->post->id,
    ]);
    app(DeviceEnrolment::class)->enrol($guard, 'Textcheck handset');
    $handset = DevicePushToken::query()->create([
        'tenant_id' => $this->site, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => $guard->id, 'app' => AppMatrix::GUARD,
        'access_token_id' => null, 'expo_token' => 'ExponentPushToken[smsfailxxxxxxxxxxxxxx]', 'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
    ]);

    $day = SiteTime::today($this->site);
    Shift::withoutEvents(fn () => Shift::create([
        'tenant_id' => $this->site, 'guard_id' => $guard->id, 'post_id' => $this->post->id,
        'rostered_start' => $day->copy()->setTime(6, 0), 'rostered_end' => $day->copy()->setTime(18, 0),
        'actual_start' => Carbon::now()->subHour(), 'status' => 'on_duty',
    ]));

    [, $residentToken] = smsResident($this->site, 'provider.down@example.com', $this->unit->id, $this->resident->id);

    $answer = smsCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    /*
     * THE THREE EXISTING PATHS, UNCHANGED AND NOT WAITING FOR ANYTHING.
     *
     * The console has the alert because the row exists; the officer on duty was pushed;
     * the ladder answered `on_duty` — and every one of those facts was already true and
     * already written before the SMS job was even queued.
     */
    expect($answer->json('console_alerted'))->toBeTrue()
        ->and($answer->json('alerted_handsets'))->toBe(1)
        ->and($answer->json('alerted_via'))->toBe(PanicFanOut::ON_DUTY);

    Bus::assertDispatched(SendExpoPush::class, fn (SendExpoPush $job): bool => $job->tokenIds === [$handset->id] && $job->urgent);

    $alertId = (int) $answer->json('id');

    // NOW THE PROVIDER FAILS ON EVERY NUMBER — including by throwing, which must not
    // cost the next number its text.
    $spy = smsGatewaySpy(SmsResult::FAILED);
    (new SendPanicSms($alertId))->handle(app(SiteEmergencyNumbers::class), $spy);

    $alert = DuressAlert::query()->findOrFail($alertId);

    // Nothing above moved. The handset count is still 1 and is NOT reduced by the texts,
    // and it was never increased by them either.
    expect($alert->alerted_handsets)->toBe(1)
        ->and($alert->alerted_via)->toBe(PanicFanOut::ON_DUTY)
        ->and($alert->sms_recipients)->toBe(4)
        ->and($alert->sms_delivered)->toBe(0);

    $rows = AlertSmsDispatch::query()->where('duress_alert_id', $alertId)->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('status')->unique()->all())->toBe([SmsResult::FAILED])
        ->and($rows->pluck('error')->unique()->all())->toBe(['ProviderRefused']);

    // A provider that THROWS is the same story: four attempts, four honest failures.
    $thrower = smsGatewaySpy('throw');
    $second = smsCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);
    (new SendPanicSms((int) $second->json('id')))->handle(app(SiteEmergencyNumbers::class), $thrower);

    expect($thrower->sent)->toHaveCount(4)
        ->and(DuressAlert::query()->findOrFail($second->json('id'))->sms_delivered)->toBe(0)
        ->and(AlertSmsDispatch::query()->where('duress_alert_id', $second->json('id'))->pluck('status')->unique()->all())
        ->toBe([SmsResult::FAILED]);
});

it('still reaches the escalation contact when no handset was reached and the texts go nowhere', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    smsBareSite($this);
    smsFourNumbers($this->site);

    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create([
        'user_id' => $head->id, 'tenant_id' => $this->site,
        'role_id' => Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id, 'is_active' => true,
    ]);

    [, $residentToken] = smsResident($this->site, 'ladder.and.text@example.com', $this->unit->id, $this->resident->id);

    $answer = smsCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    // The ladder's last tier ran, exactly as it did before any of this existed…
    expect($answer->json('alerted_handsets'))->toBe(0)
        ->and($answer->json('alerted_via'))->toBe(PanicFanOut::ESCALATION)
        ->and($answer->json('escalated_contacts'))->toBe(1);

    Notification::assertSentTo($head->fresh(), PanicRaisedAtSite::class);

    // …and the texts are a separate, additional fact that never became a handset.
    expect($answer->json('sms_recipients'))->toBe(4)
        ->and($answer->json('alerted_handsets'))->toBe(0);
});

/* ── 3 · what is recorded matches what happened ──────────────────────── */

it('writes one row per number with the outcome that actually happened, and counts only what a provider accepted', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    smsBareSite($this);
    smsFourNumbers($this->site);

    // One of the four is a number nobody can dial. It stays on the list — that is how an
    // office discovers the typo — and it is recorded as unroutable, never as sent.
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->where('role', 'property_office')
        ->update(['number' => '224-4444', 'e164' => null]);

    [, $residentToken] = smsResident($this->site, 'one.bad.number@example.com', $this->unit->id, $this->resident->id);

    $answer = smsCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);
    $alertId = (int) $answer->json('id');

    $spy = smsGatewaySpy(SmsResult::SENT);
    (new SendPanicSms($alertId))->handle(app(SiteEmergencyNumbers::class), $spy);

    // THREE were handed to the provider. The fourth was never attempted.
    expect(array_column($spy->sent, 'to'))->toBe(['+18765088082', '+18768459422', '+18763542119']);

    $rows = AlertSmsDispatch::query()->where('duress_alert_id', $alertId)->orderBy('id')->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('status')->all())->toBe([SmsResult::SENT, SmsResult::UNROUTABLE, SmsResult::SENT, SmsResult::SENT])
        ->and($rows->firstWhere('role', 'property_office')->number)->toBe('224-4444')
        ->and($rows->firstWhere('role', 'property_office')->e164)->toBeNull()
        ->and($rows->firstWhere('role', 'president')->reference)->toBe('ref-1')
        // WHO was told is copied onto the row, so editing the list later cannot rewrite
        // the record of who was told that night.
        ->and($rows->pluck('label')->all())->toBe(['President', 'Property Office', 'PPV1 Head of Security', 'Gemini Dispatch']);

    expect(DuressAlert::query()->findOrFail($alertId)->sms_delivered)->toBe(3);
});

it('records a text as WRITTEN DOWN rather than sent while this platform has no provider, and tells the app 0', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    smsBareSite($this);
    smsFourNumbers($this->site);

    // The platform's real, bound gateway. No provider is configured, so the honest
    // answer is `recorded`: the message was written down and delivered to nobody.
    expect(app(SmsGateway::class)->driver())->toBeInstanceOf(LogSmsGateway::class)
        ->and(app(SmsGateway::class)->send('+18763542119', 'test')->status)->toBe(SmsResult::RECORDED)
        ->and(app(SmsGateway::class)->send('+18763542119', 'test')->delivered())->toBeFalse();

    [, $residentToken] = smsResident($this->site, 'no.provider@example.com', $this->unit->id, $this->resident->id);

    $answer = smsCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);
    $alertId = (int) $answer->json('id');

    (new SendPanicSms($alertId))->handle(app(SiteEmergencyNumbers::class), app(SmsGateway::class));

    $rows = AlertSmsDispatch::query()->where('duress_alert_id', $alertId)->get();

    expect($rows)->toHaveCount(4)
        ->and($rows->pluck('status')->unique()->all())->toBe([SmsResult::RECORDED])
        ->and($rows->pluck('driver')->unique()->all())->toBe([LogSmsGateway::DRIVER])
        ->and($rows->first()->delivered())->toBeFalse();

    // 0, not 4. A number on file is not a message delivered, and the app is told the
    // difference so that it can never say four people were texted when nobody was.
    expect(DuressAlert::query()->findOrFail($alertId)->sms_delivered)->toBe(0)
        ->and(smsCall($this, $residentToken, 'duress.show', path: ['alert' => $alertId])->json('sms_delivered'))->toBe(0);
});

it('refuses to let the record of who was told be edited or deleted', function () {
    smsFourNumbers($this->site);

    $row = AlertSmsDispatch::query()->create([
        'duress_alert_id' => 0, 'tenant_id' => $this->site, 'site_emergency_number_id' => null,
        'role' => 'dispatch', 'label' => 'Gemini Dispatch', 'number' => '876-354-2119', 'e164' => '+18763542119',
        'status' => SmsResult::SENT, 'driver' => 'test', 'reference' => null, 'error' => null, 'attempted_at' => Carbon::now(),
    ]);

    // Append-only by a trigger as well as by a withheld grant — and the trigger holds
    // even here, where the suite connects as the schema owner.
    expect(fn () => DB::connection('mysql')->table('alert_sms_dispatches')->where('id', $row->id)->update(['status' => SmsResult::FAILED]))
        ->toThrow(Illuminate\Database\QueryException::class)
        ->and(fn () => DB::connection('mysql')->table('alert_sms_dispatches')->where('id', $row->id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class);
});

/* ── 4 · the numbers are the SITE's, and a second estate gets its own ── */

it('takes the numbers from the site\'s own settings, and a second estate is texted its own people and never these four', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    smsBareSite($this);
    smsFourNumbers($this->site);

    /*
     * A SECOND ESTATE, WITH ITS OWN PEOPLE. Named here rather than borrowed from a
     * fixture: which demonstration estates exist in the shared test database is not
     * this test's business, and the point being proved — that one estate's numbers are
     * never reached from another's alert — needs two estate ids and nothing else.
     */
    $other = 'asecondestate';

    SiteEmergencyNumber::query()->where('tenant_id', $other)->delete();
    app(SiteEmergencyNumbers::class)->put($other, 'president', 'Ocean View President', '1-876-700-1111', 1);
    app(SiteEmergencyNumbers::class)->put($other, 'dispatch', 'Gemini Dispatch', '876-354-2119', 2);

    $numbers = app(SiteEmergencyNumbers::class);

    expect($numbers->forSite($this->site)->pluck('e164')->all())
        ->toBe(['+18765088082', '+18762244444', '+18768459422', '+18763542119'])
        ->and($numbers->forSite($other)->pluck('e164')->all())
        ->toBe(['+18767001111', '+18763542119'])
        // An estate nobody has written numbers for texts nobody, and says so with a 0.
        ->and($numbers->forSite('anestatewithnonumbers')->all())->toBe([]);

    // And the panic path reads the site off the ALERT, never off a request.
    [, $residentToken] = smsResident($this->site, 'per.site@example.com', $this->unit->id, $this->resident->id);
    $answer = smsCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    $spy = smsGatewaySpy(SmsResult::SENT);
    (new SendPanicSms((int) $answer->json('id')))->handle($numbers, $spy);

    expect(array_column($spy->sent, 'to'))->not->toContain('+18767001111');

    SiteEmergencyNumber::query()->where('tenant_id', $other)->delete();
});

it('texts nobody at an estate that has no numbers on file, and says 0 rather than leaving the app waiting', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    smsBareSite($this);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    [, $residentToken] = smsResident($this->site, 'no.numbers@example.com', $this->unit->id, $this->resident->id);

    $answer = smsCall($this, $residentToken, 'duress.store', ['mode' => 'audible']);

    expect($answer->json('sms_recipients'))->toBe(0)
        ->and($answer->json('sms_delivered'))->toBe(0)
        // Still a real alert: the console has it and the honest handset count stands.
        ->and($answer->json('console_alerted'))->toBeTrue();

    Bus::assertNotDispatched(SendPanicSms::class);
});

/* ── 5 · what the message says ───────────────────────────────────────── */

it('says WHERE and WHAT, names nobody, and fits one message', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-21T23:42:00Z')); // 6:42 PM in Kingston

    $alert = DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'panic', 'guard_id' => null,
        'raised_by_name' => 'Andrea Fletcher', 'unit_reference' => 'Lot 47', 'status' => 'open',
        'server_time' => Carbon::now(), 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false,
    ]);

    $body = PanicSms::body($alert);

    expect($body)->toContain('PANIC ALARM')
        ->and($body)->toContain('Lot 47')
        ->and($body)->toContain('Alert #'.$alert->id)
        ->and($body)->toContain('Do not reply')
        // THE SITE'S CLOCK, by its IANA name, never the server's and never an offset.
        // The estate's clock, tagged (M19A §8.7).
        ->and($body)->toContain('6:42 PM JA, 21 Sep')
        // AND IT NAMES NOBODY. A text is read on a lock screen by whoever is holding the
        // phone; the responder drives to a unit, not to a name.
        ->and($body)->not->toContain('Andrea')
        ->and($body)->not->toContain('Fletcher')
        ->and(strlen($body))->toBeLessThanOrEqual(PanicSms::MAX_LENGTH);

    // A guard's duress says what it is and where the site is, and still names nobody.
    $duress = DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'duress', 'guard_id' => null, 'unit_reference' => null, 'status' => 'open',
        'server_time' => Carbon::now(), 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false,
    ]);

    expect(PanicSms::body($duress))->toContain('OFFICER DURESS')
        ->and(strlen(PanicSms::body($duress)))->toBeLessThanOrEqual(PanicSms::MAX_LENGTH);

    // A very long estate name is shortened — the NAME, never the unit, the time or the
    // alert number, because those are the three facts a responder acts on.
    $estate = Tenant::query()->findOrFail($this->site);
    $wasName = $estate->name;
    $estate->forceFill(['name' => str_repeat('Extremely Long Estate Name ', 6)])->save();

    $long = PanicSms::body($alert->fresh()->load('estate'));

    expect(strlen($long))->toBeLessThanOrEqual(PanicSms::MAX_LENGTH)
        ->and($long)->toContain('Lot 47')
        ->and($long)->toContain('Alert #'.$alert->id);

    $estate->forceFill(['name' => $wasName])->save();
});

/* ── 6 · the demonstration estate and simulated alerts text nobody ───── */

it('never texts a real person for a simulated alert or for the demonstration estate', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    smsBareSite($this);
    smsFourNumbers($this->site);

    $simulated = DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'panic', 'unit_reference' => 'Lot 47', 'status' => 'open',
        'server_time' => Carbon::now(), 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => true,
    ]);

    expect(app(PanicSms::class)->raise($simulated))->toBe(0);
    Bus::assertNotDispatched(SendPanicSms::class);

    expect($simulated->sms_recipients)->toBe(0)
        ->and($simulated->sms_delivered)->toBe(0);

    /*
     * AND THE DEMONSTRATION ESTATE. An app-store reviewer pressing panic must not text
     * the client's President at 3 AM about a resident who does not exist — the same
     * refusal `PanicFanOut` already applies to the escalation mail (M5 §3).
     */
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => true])->save();

    $onDemo = DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'panic', 'unit_reference' => 'Lot 47', 'status' => 'open',
        'server_time' => Carbon::now(), 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false,
    ]);

    expect(app(PanicSms::class)->raise($onDemo))->toBe(0)
        ->and($onDemo->sms_recipients)->toBe(0)
        ->and($onDemo->sms_delivered)->toBe(0);

    Bus::assertNotDispatched(SendPanicSms::class);

    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false])->save();
});
