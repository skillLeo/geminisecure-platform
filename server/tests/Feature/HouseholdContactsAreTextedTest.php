<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Alerts\SendHouseholdContactsSms;
use App\Jobs\Alerts\SendPanicSms;
use App\Jobs\Push\SendExpoPush;
use App\Models\AlertSmsDispatch;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Guard;
use App\Models\PlatformSmsSetting;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\DeliveryReport;
use App\Services\Dispatch\HouseholdContactsSms;
use App\Services\Dispatch\PanicSmsOutcome;
use App\Services\Platform\SmsSettings;
use App\Services\Push\PanicDeliveries;
use App\Services\ResidentApp\LogSmsGateway;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Services\ResidentApp\TwilioSmsGateway;
use App\Support\SiteTime;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Stancl\Tenancy\Events\TenancyInitialized;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| THE HOUSEHOLD'S OWN EMERGENCY CONTACTS ARE TEXTED — M10 §1.2 item 7
|--------------------------------------------------------------------------
|
| "The household's nominated emergency contacts — SMS as already built." It was
| not built: the contacts were stored in the estate's database and nothing on the
| panic path read them. What this file proves:
|
|   1  a resident's panic queues ONE job on the panic queue, and it texts one
|      message per contact, the first five nominated, and records the rest
|   2  an officer's duress texts no household — not even a row that names one
|   3  under the log driver every row is `recorded`, and the resident is told no
|      text went out
|   4  with Twilio (faked) accepting, the rows are `sent` and the counts right;
|      with Twilio refusing, they are `failed` and nothing is claimed
|   5  only the one household that pressed is read, and only inside the alert's
|      own estate — another household's contacts and another estate's are never
|   6  the dispatcher's alert screen says what the household's contacts were sent
|
| NO TEST REACHES A NETWORK. `Http::preventStrayRequests()` fails anything that
| tried; the "provider" is either a gateway spy or `Http::fake()` standing in for
| Twilio's one endpoint.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED, so this file writes its contacts on
| two lots of its own (936 and 937) and removes them afterwards. The central
| database is wrapped in a transaction with the framework's TESTING manager, so the
| "after commit" fan-out a panic makes runs here as it does in production.
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

    /*
     * NO PROVIDER, ASSERTED RATHER THAN ASSUMED — `NothingClaimsAnSmsWasSentTest`'s rule.
     * The saved row is removed inside the transaction and the rollback puts it back.
     */
    PlatformSmsSetting::query()->delete();
    config(['services.sms.driver' => 'log', 'services.sms.account_sid' => null, 'services.sms.token' => null, 'services.sms.from' => null]);
    expect(app(SmsSettings::class)->canSend())->toBeFalse();

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();

    // THE SITE'S OWN NUMBERS ARE `PanicSmsTest`'s. None here, so every text in this
    // file is a household's and every count is unambiguous.
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 936');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Contactcheck household', 'access_restricted' => false]);
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Rae Contactcheck'],
        ['relationship' => 'owner', 'is_primary' => true],
    );
    $this->resident->forceFill(['is_primary' => true, 'removed_at' => null])->save();

    // THE NEIGHBOURS, whose contacts must never be read by this household's panic.
    $this->neighbourUnit = FacilitiesFixture::scratchUnit('Lot 937');
    $this->neighbour = Household::query()->firstOrCreate(['unit_id' => $this->neighbourUnit->id], ['name' => 'Next door household', 'access_restricted' => false]);

    hhcForget($this);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();

    hhcForget($this);
});

/* ── helpers ─────────────────────────────────────────────────────────── */

/** Remove this file's contacts from the committed estate database. */
function hhcForget(TestCase $test): void
{
    DB::connection('tenant')->table('household_emergency_contacts')
        ->whereIn('household_id', [$test->household->id, $test->neighbour->id])
        ->delete();
}

/**
 * Nominate contacts for a household, in order, as the Resident App would.
 *
 * @param  list<array{0: string, 1: string|null, 2: string}>  $contacts  [name, relationship, phone]
 * @return list<int>
 */
function hhcContacts(int $householdId, array $contacts): array
{
    $ids = [];

    foreach ($contacts as [$name, $relationship, $phone]) {
        $ids[] = (int) DB::connection('tenant')->table('household_emergency_contacts')->insertGetId([
            'household_id' => $householdId, 'name' => $name, 'relationship' => $relationship, 'phone' => $phone,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $ids;
}

/** @return array{0: ResidentAccount, 1: string} */
function hhcResident(TestCase $test, string $email): array
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => $test->site, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Rae Contactcheck',
        'resident_id' => $test->resident->id, 'unit_id' => $test->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$account, $account->createToken('resident-app:'.$email, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

/** @return array{0: Guard, 1: string} */
function hhcGuard(TestCase $test): array
{
    $post = Post::create(['tenant_id' => $test->site, 'name' => 'Contacts Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'Officer Contactcheck', 'employee_number' => 'GS-HHC-1', 'psra_number' => 'PSRA-HHC-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $test->site, 'post_id' => $post->id,
    ]);

    return [$guard, app(DeviceEnrolment::class)->enrol($guard, 'Contactcheck handset')['token']];
}

/** One catalogued endpoint, held to its allowlist — `sms.household.*` included. */
function hhcCall(TestCase $test, string $token, string $name, array $body = [], array $path = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = $entry['uri'];

    foreach ($path as $key => $value) {
        $uri = str_replace('{'.$key.'}', (string) $value, $uri);
    }

    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($entry['method'], '/api/v1/'.$uri, $body, [
        'Idempotency-Key' => 'hhc-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** A gateway that records every number and answers however the test says. */
function hhcSpy(string $answer = SmsResult::SENT): object
{
    return new class($answer) implements SmsGateway
    {
        /** @var list<array{to: string, message: string}> */
        public array $sent = [];

        public function __construct(private readonly string $answer) {}

        public function send(string $to, string $message): SmsResult
        {
            $this->sent[] = ['to' => $to, 'message' => $message];

            return $this->answer === SmsResult::SENT
                ? SmsResult::sent('test', 'ref-'.count($this->sent))
                : SmsResult::failed('test', 'ProviderRefused');
        }
    };
}

/** Run the household's job as the worker would, and put the fixture back afterwards. */
function hhcRun(int $alertId, SmsGateway $gateway): void
{
    (new SendHouseholdContactsSms($alertId))->handle(app(HouseholdContactsSms::class), $gateway);
    FacilitiesFixture::boot();
}

/** A Twilio row `canSend()` accepts. The token is a placeholder, not a credential. */
function hhcTwilio(): void
{
    PlatformSmsSetting::query()->create([
        'driver' => PlatformSmsSetting::TWILIO,
        'account_sid' => 'AC'.str_repeat('0123456789abcdef', 2),
        'token' => 'placeholder-not-a-credential',
        'from' => '+1 876 555 0100',
        'verified_at' => Carbon::now(),
    ]);

    expect(app(SmsSettings::class)->canSend())->toBeTrue()
        ->and(app(SmsGateway::class)->driver())->toBeInstanceOf(TwilioSmsGateway::class);
}

/** @return Illuminate\Support\Collection<int, AlertSmsDispatch> */
function hhcRows(int $alertId): Illuminate\Support\Collection
{
    return AlertSmsDispatch::query()->where('duress_alert_id', $alertId)->where('audience', AlertSmsDispatch::HOUSEHOLD)->orderBy('id')->get();
}

/* ── 1 · a resident's panic queues the household's texts ─────────────── */

it('queues one job on the panic queue when a resident presses panic, and texts the first five contacts nominated', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    // SEVEN on file. A panic texts five; the other two are recorded, never dropped silently.
    $ids = hhcContacts($this->household->id, array_map(
        static fn (int $n): array => ['Contact '.$n, 'relative', '+1 876 555 01'.(10 + $n)],
        range(1, 7),
    ));

    [$account, $token] = hhcResident($this, 'household.cap@example.com');

    $answer = hhcCall($this, $token, 'duress.store', ['mode' => 'audible']);
    $alertId = (int) $answer->json('id');

    // ONE job, on the central connection's PANIC queue, carrying the alert id and nothing else.
    Bus::assertDispatchedTimes(SendHouseholdContactsSms::class, 1);
    Bus::assertDispatched(SendHouseholdContactsSms::class, fn (SendHouseholdContactsSms $job): bool => $job->alertId === $alertId
        && $job->queue === config('push.urgent_queue')
        && $job->connection === config('queue.central_connection', 'central'));

    $alert = DuressAlert::query()->findOrFail($alertId);

    // The account is on the row from the first insert — the only exact route to ONE household.
    expect($alert->raised_by_account_id)->toBe($account->id)
        ->and($alert->household_sms_recipients)->toBe(5)
        ->and($alert->household_sms_delivered)->toBeNull()
        ->and($alert->household_sms_over_cap)->toBe(2);

    // The resident is told nothing has been confirmed yet — never a count of phones.
    expect($answer->json('sms.household'))->toBe(['attempted' => 0, 'delivered' => 0, 'finished' => false]);

    // …and when the worker runs it, five are handed to the provider, in the order nominated.
    $spy = hhcSpy(SmsResult::SENT);
    hhcRun($alertId, $spy);

    expect(array_column($spy->sent, 'to'))->toBe(['+18765550111', '+18765550112', '+18765550113', '+18765550114', '+18765550115']);

    $rows = hhcRows($alertId);

    expect($rows)->toHaveCount(5)
        ->and($rows->pluck('household_emergency_contact_id')->all())->toBe(array_slice($ids, 0, 5))
        ->and($rows->pluck('status')->unique()->all())->toBe([SmsResult::SENT]);

    $alert->refresh();

    expect($alert->household_sms_recipients)->toBe(5)
        ->and($alert->household_sms_delivered)->toBe(5)
        ->and($alert->household_sms_over_cap)->toBe(2)
        // The SITE's count is its own and untouched: this estate has no numbers on file.
        ->and($alert->sms_recipients)->toBe(0)
        ->and(AlertSmsDispatch::query()->where('duress_alert_id', $alertId)->where('audience', AlertSmsDispatch::SITE)->count())->toBe(0);
});

it('texts nobody and says so at once when the household has nominated nobody', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    [, $token] = hhcResident($this, 'household.none@example.com');

    $answer = hhcCall($this, $token, 'duress.store', ['mode' => 'audible']);

    Bus::assertNotDispatched(SendHouseholdContactsSms::class);

    // Finished, not pending: an app polling for a household with no contacts would poll for ever.
    expect($answer->json('sms.household'))->toBe(['attempted' => 0, 'delivered' => 0, 'finished' => true])
        ->and($answer->json('sms.note'))->toBe(PanicSmsOutcome::NO_PROVIDER);
});

/* ── 2 · an officer's duress texts no household ──────────────────────── */

it('texts no household for an officer\'s duress — not even a duress row that names a resident\'s account', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    hhcContacts($this->household->id, [['Karen Contactcheck', 'sister', '+1 876 555 0120']]);
    [$account] = hhcResident($this, 'household.duress@example.com');
    [$guard, $guardToken] = hhcGuard($this);

    $duress = hhcCall($this, $guardToken, 'duress.store', ['mode' => 'silent']);

    expect($duress->json('kind'))->toBe('duress')
        // NULL, not a zero: the leg does not apply, which is a different fact from "nobody".
        ->and($duress->json('sms.household'))->toBeNull();

    Bus::assertNotDispatched(SendHouseholdContactsSms::class);

    $row = DuressAlert::query()->findOrFail($duress->json('id'));

    expect($row->household_sms_recipients)->toBeNull()
        ->and($row->household_sms_delivered)->toBeNull()
        ->and($row->household_sms_over_cap)->toBeNull();

    // BELT AND BRACES. A duress that somehow carries a resident's account is still a duress:
    // the leg refuses it, and so does the job if it were ever replayed against one.
    $planted = DuressAlert::withoutEvents(fn () => DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'duress', 'guard_id' => $guard->id, 'raised_by_account_id' => $account->id,
        'status' => 'open', 'server_time' => Carbon::now(), 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false,
    ]));

    expect(app(HouseholdContactsSms::class)->raise($planted))->toBe(0);

    $spy = hhcSpy(SmsResult::SENT);
    hhcRun((int) $planted->id, $spy);

    expect($spy->sent)->toBe([])
        ->and(hhcRows((int) $planted->id))->toHaveCount(0)
        ->and(app(PanicSmsOutcome::class)->of($planted->fresh())['household'])->toBeNull();

    Bus::assertNotDispatched(SendHouseholdContactsSms::class);
});

/* ── 3 · the log driver: written down, delivered to nobody ───────────── */

it('records every household text as written down under the log driver, and tells the resident no text went out', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    hhcContacts($this->household->id, [
        ['Karen Contactcheck', 'sister', '+1 876 555 0131'],
        ['Devon Contactcheck', null, '876-555-0132'],
    ]);
    [, $token] = hhcResident($this, 'household.log@example.com');

    $raised = hhcCall($this, $token, 'duress.store', ['mode' => 'audible']);
    $alertId = (int) $raised->json('id');

    // Before the job runs, with no provider: already the plain truth, both audiences named.
    expect($raised->json('sms.household'))->toBe(['attempted' => 0, 'delivered' => 0, 'finished' => false])
        ->and($raised->json('sms.note'))->toBe(PanicSmsOutcome::NO_PROVIDER_FOR_EITHER);

    // The job runs against the REAL bound gateway — the log driver.
    expect(app(SmsGateway::class)->driver())->toBeInstanceOf(LogSmsGateway::class);
    hhcRun($alertId, app(SmsGateway::class));

    $rows = hhcRows($alertId);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe([SmsResult::RECORDED])
        ->and($rows->pluck('driver')->unique()->all())->toBe([LogSmsGateway::DRIVER])
        ->and($rows->first()->delivered())->toBeFalse()
        // WHAT THE LEDGER HOLDS: the relationship, the number masked, no diallable form.
        ->and($rows->pluck('role')->unique()->all())->toBe([HouseholdContactsSms::ROLE])
        ->and($rows->pluck('label')->all())->toBe(['sister', 'Emergency contact'])
        ->and($rows->pluck('number')->all())->toBe(['+1876•••0131', '+1876•••0132'])
        ->and($rows->pluck('e164')->unique()->all())->toBe([null]);

    // …and NEVER the contact's name, or a full number, anywhere on the central rows.
    $ledger = json_encode($rows->toArray(), JSON_UNESCAPED_UNICODE);

    expect($ledger)->not->toContain('Karen')
        ->and($ledger)->not->toContain('Contactcheck')
        ->and($ledger)->not->toContain('5550131')
        ->and($ledger)->not->toContain('5550132');

    expect(DuressAlert::query()->findOrFail($alertId)->household_sms_delivered)->toBe(0);

    $read = hhcCall($this, $token, 'duress.show', path: ['alert' => $alertId]);

    expect($read->json('sms.household'))->toBe(['attempted' => 0, 'delivered' => 0, 'finished' => true])
        ->and($read->json('sms.note'))->toStartWith('No text was sent')
        ->and($read->json('sms.note'))->toBe(PanicSmsOutcome::NO_PROVIDER_FOR_EITHER);

    // Nowhere in the answer is there a claim, a name or a number.
    $content = (string) $read->getContent();

    expect(strtolower($content))->not->toContain('accepted')
        ->and(strtolower($content))->not->toContain('received')
        ->and($content)->not->toContain('Karen')
        ->and($content)->not->toContain('5550131');
});

/* ── 4 · Twilio, accepting and refusing ──────────────────────────────── */

it('records the texts as sent only when Twilio accepted them, and says how many the provider accepted', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    // 9:41 PM in Kingston.
    Carbon::setTestNow(Carbon::parse('2026-09-24T02:41:00Z'));

    hhcContacts($this->household->id, [
        ['Karen Contactcheck', 'sister', '+1 876 555 0141'],
        ['Devon Contactcheck', 'son', '(876) 555-0142'],
    ]);
    hhcTwilio();

    Http::fake([
        'api.twilio.com/*' => Http::response(['sid' => 'SM0123456789abcdef0123456789abcdef', 'status' => 'queued'], 201),
    ]);

    [, $token] = hhcResident($this, 'household.twilio@example.com');

    $raised = hhcCall($this, $token, 'duress.store', ['mode' => 'audible']);
    $alertId = (int) $raised->json('id');

    hhcRun($alertId, app(SmsGateway::class));

    // WHAT A STRANGER READS: who, where, when, and nothing else.
    // The time says whose clock it is on (M19A §8.7): the contact may be anywhere.
    $expected = 'GeminiSecure: Rae raised an emergency alert at Phoenix Park, Lot 936, at 9:41 PM JA. Estate security has been alerted.';

    Http::assertSentCount(2);
    Http::assertSent(fn (HttpRequest $request): bool => $request['To'] === '+18765550141' && $request['Body'] === $expected);
    Http::assertSent(fn (HttpRequest $request): bool => $request['To'] === '+18765550142' && $request['Body'] === $expected);

    expect(strlen($expected))->toBeLessThanOrEqual(HouseholdContactsSms::MAX_LENGTH)
        ->and($expected)->not->toContain('Contactcheck')
        ->and($expected)->not->toContain('http')
        ->and($expected)->not->toContain('#');

    $rows = hhcRows($alertId);

    expect($rows->pluck('status')->all())->toBe([SmsResult::SENT, SmsResult::SENT])
        ->and($rows->pluck('driver')->unique()->all())->toBe([TwilioSmsGateway::DRIVER])
        ->and($rows->pluck('reference')->unique()->all())->toBe(['SM0123456789abcdef0123456789abcdef'])
        ->and(DuressAlert::query()->findOrFail($alertId)->household_sms_delivered)->toBe(2);

    $read = hhcCall($this, $token, 'duress.show', path: ['alert' => $alertId]);

    expect($read->json('sms.provider_connected'))->toBeTrue()
        ->and($read->json('sms.household'))->toBe(['attempted' => 2, 'delivered' => 2, 'finished' => true])
        // The estate's own numbers are unchanged by any of it: none on file, none texted.
        ->and($read->json('sms.attempted'))->toBe(0)
        ->and($read->json('sms.delivered'))->toBe(0)
        ->and($read->json('sms.note'))->toBe('No text was sent to this estate\'s emergency numbers. The SMS provider accepted 2 of 2 texts to your emergency contacts.')
        ->and(strtolower((string) $read->json('sms.note')))->not->toContain('received');
});

it('records the texts as failed when Twilio refused them, and claims nothing', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    hhcContacts($this->household->id, [
        ['Karen Contactcheck', 'sister', '+1 876 555 0143'],
        ['Devon Contactcheck', 'son', '+1 876 555 0144'],
    ]);
    hhcTwilio();

    Http::fake([
        'api.twilio.com/*' => Http::response(['code' => 21211, 'message' => 'The To number is not a valid phone number.', 'status' => 400], 400),
    ]);

    [, $token] = hhcResident($this, 'household.refused@example.com');

    $alertId = (int) hhcCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');

    hhcRun($alertId, app(SmsGateway::class));

    Http::assertSentCount(2);

    $rows = hhcRows($alertId);

    expect($rows->pluck('status')->all())->toBe([SmsResult::FAILED, SmsResult::FAILED])
        ->and($rows->pluck('error')->unique()->all())->toBe(['http_400:21211'])
        ->and($rows->pluck('reference')->unique()->all())->toBe([null])
        ->and(DuressAlert::query()->findOrFail($alertId)->household_sms_delivered)->toBe(0);

    $read = hhcCall($this, $token, 'duress.show', path: ['alert' => $alertId]);

    expect($read->json('sms.household'))->toBe(['attempted' => 2, 'delivered' => 0, 'finished' => true])
        ->and($read->json('sms.note'))->toBe('No text was sent to this estate\'s emergency numbers. No text to your emergency contacts was delivered: the SMS provider did not accept any of them.');
});

/* ── 5 · one household, one estate ───────────────────────────────────── */

it('reads only the household that pressed panic, and only inside the alert\'s own estate', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    hhcContacts($this->household->id, [['Karen Contactcheck', 'sister', '+1 876 555 0151']]);
    hhcContacts($this->neighbour->id, [['Next Door Brother', 'brother', '+1 876 555 0159']]);

    [$account, $token] = hhcResident($this, 'household.isolation@example.com');

    // Every estate opened, and every read of the contacts table, while this panic runs.
    $opened = [];
    $reads = [];

    Event::listen(TenancyInitialized::class, function (TenancyInitialized $event) use (&$opened): void {
        $opened[] = (string) $event->tenancy->tenant->getTenantKey();
    });
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, 'household_emergency_contacts')) {
            $reads[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'connection' => $query->connectionName];
        }
    });

    $alertId = (int) hhcCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');

    $spy = hhcSpy(SmsResult::SENT);
    hhcRun($alertId, $spy);

    // The neighbours' brother is never texted…
    expect(array_column($spy->sent, 'to'))->toBe(['+18765550151']);

    // …and never even READ: every query names this household, and only this one.
    expect($reads)->not->toBeEmpty();

    foreach ($reads as $read) {
        expect($read['sql'])->toContain('`household_id` = ?')
            ->and($read['bindings'])->toContain($this->household->id)
            ->and($read['bindings'])->not->toContain($this->neighbour->id)
            ->and($read['connection'])->toBe('tenant');
    }

    // The only estate ever opened is the alert's own.
    expect(array_values(array_unique($opened)))->toBe([$this->site]);

    /*
     * AND NO ALERT CAN WALK INTO ANOTHER ESTATE'S HOUSEHOLD. An account id is unique only
     * centrally, so the account is checked against the ALERT's estate before anything is
     * opened. Both directions:
     *
     *   an alert at another estate naming THIS estate's account, and
     *   an alert at THIS estate naming another estate's account whose unit id happens to
     *   be this household's unit id — the case a missing check would read straight through.
     */
    $opened = [];
    $reads = [];

    $abroad = DuressAlert::withoutEvents(fn () => DuressAlert::create([
        'tenant_id' => 'hhcsecondestate', 'kind' => 'panic', 'raised_by_account_id' => $account->id, 'unit_reference' => 'Lot 936',
        'status' => 'open', 'server_time' => Carbon::now(), 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false,
    ]));

    $stranger = ResidentAccount::query()->create([
        'tenant_id' => 'hhcsecondestate', 'channel' => 'email', 'destination' => 'stranger.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Another Estate', 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    $borrowed = DuressAlert::withoutEvents(fn () => DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'panic', 'raised_by_account_id' => $stranger->id, 'unit_reference' => 'Lot 936',
        'status' => 'open', 'server_time' => Carbon::now(), 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false,
    ]));

    foreach ([$abroad, $borrowed] as $alert) {
        expect(app(HouseholdContactsSms::class)->raise($alert))->toBe(0)
            ->and($alert->household_sms_recipients)->toBe(0)
            ->and(app(HouseholdContactsSms::class)->contacts($alert))->toBe(['contacts' => [], 'on_file' => 0]);
    }

    FacilitiesFixture::boot();

    expect($opened)->toBe([])
        ->and($reads)->toBe([]);

    Bus::assertDispatchedTimes(SendHouseholdContactsSms::class, 1);
});

/* ── 6 · the dispatcher's alert screen ───────────────────────────────── */

it('tells the dispatcher what the household\'s contacts were sent, and leaves the line off an officer\'s duress', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    hhcContacts($this->household->id, [
        ['Karen Contactcheck', 'sister', '+1 876 555 0161'],
        ['Devon Contactcheck', 'son', '+1 876 555 0162'],
    ]);
    [, $token] = hhcResident($this, 'household.dispatcher@example.com');

    $alertId = (int) hhcCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $line = function (int $id) use ($director): mixed {
        $seen = null;

        $this->actingAs($director)
            ->get('/dispatch/alerts/'.$id)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$seen): AssertableInertia {
                $alert = $page->toArray()['props']['alert'];

                // A key that is MISSING is a different failure from one that is null.
                $seen = array_key_exists('household_contacts', $alert) ? $alert['household_contacts'] : 'missing';

                return $page->component('Gemini/Dispatch/Alert');
            });

        // The page reads the household's people inside the estate (PanicPeople, M18C), and leaving it closes this
        // test's estate connection with it; the next line of the test opens it again, as every request does.
        FacilitiesFixture::boot();

        return $seen;
    };

    // The platform today: nothing can be sent, and the screen says so rather than "2".
    expect($line($alertId))->toBe('not texted: no SMS provider connected');

    // A provider accepts both.
    hhcRun($alertId, hhcSpy(SmsResult::SENT));

    expect($line($alertId))->toBe('2 texted');

    // A household that nominated nobody.
    DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $this->household->id)->delete();
    $none = (int) hhcCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');

    expect($line($none))->toBe('none on file');

    // An officer's duress: no household leg, and no row on the screen.
    [, $guardToken] = hhcGuard($this);
    $duress = (int) hhcCall($this, $guardToken, 'duress.store', ['mode' => 'silent'])->json('id');

    expect($line($duress))->toBeNull();
});

/* ── the message itself ──────────────────────────────────────────────── */

it('keeps the household text to one segment, shortening the estate\'s name and never the lot or the time', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-24T02:41:00Z'));

    $alert = DuressAlert::withoutEvents(fn () => DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'medical', 'raised_by_name' => 'Andrea Fletcher', 'unit_reference' => 'Lot 47',
        'status' => 'open', 'server_time' => Carbon::now(), 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false,
    ]));

    $estate = Tenant::query()->findOrFail($this->site);
    $estate->forceFill(['name' => str_repeat('Extremely Long Estate Name ', 6)])->save();

    $body = HouseholdContactsSms::body($alert->fresh()->load('estate'));

    expect(mb_strlen($body))->toBeLessThanOrEqual(HouseholdContactsSms::MAX_LENGTH)
        ->and($body)->toStartWith('GeminiSecure: Andrea raised an emergency alert at Extremely Long')
        ->and($body)->toContain(', Lot 47, at 9:41 PM JA.')
        ->and($body)->toEndWith('Estate security has been alerted.')
        // The first name only, and not the kind: a guess at "medical" is not the resident's to be made for them.
        ->and($body)->not->toContain('Fletcher')
        ->and($body)->not->toContain('edical');

    // A panic with no name on it still says who, in words a stranger understands.
    $nameless = DuressAlert::withoutEvents(fn () => DuressAlert::create([
        'tenant_id' => $this->site, 'kind' => 'panic', 'raised_by_name' => null, 'unit_reference' => null,
        'status' => 'open', 'server_time' => Carbon::now(), 'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false,
    ]));

    expect(HouseholdContactsSms::body($nameless->fresh()->load('estate')))->toStartWith('GeminiSecure: A resident raised an emergency alert at ');

    // A DRILL says so before anything else (M10 §1.3), and still fits.
    $drill = $alert->fresh()->load('estate')->forceFill(['is_drill' => true]);

    expect(HouseholdContactsSms::body($drill))->toStartWith('GeminiSecure: DRILL: Andrea raised an emergency alert at ')
        ->and(mb_strlen(HouseholdContactsSms::body($drill)))->toBeLessThanOrEqual(HouseholdContactsSms::MAX_LENGTH)
        ->and(HouseholdContactsSms::body($alert->fresh()->load('estate')))->not->toContain('DRILL');
});

/* ── 7 · the hand-off record (M10 §1.3) ──────────────────────────────── */

/** The one `emergency_contacts` row the recorder wrote for this alert. */
function hhcLeg(int $alertId): DuressAlertDelivery
{
    $legs = DuressAlertDelivery::query()->where('duress_alert_id', $alertId)
        ->where('destination', DuressAlertDelivery::EMERGENCY_CONTACTS)->get();

    expect($legs)->toHaveCount(1);

    return $legs->first();
}

it('records the household\'s leg as §1.2\'s emergency contacts, by text, with the real count and an honest outcome', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    hhcContacts($this->household->id, array_map(
        static fn (int $n): array => ['Contact '.$n, 'relative', '+1 876 555 02'.(10 + $n)],
        range(1, 7),
    ));
    [, $token] = hhcResident($this, 'household.leg@example.com');

    // UNDER THE LOG DRIVER: counted — five, the cap — and `skipped`, because a message
    // written down and delivered to nobody is not a hand-off to anybody.
    $logged = (int) hhcCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');
    $leg = hhcLeg($logged);

    expect($leg->channel)->toBe(DuressAlertDelivery::SMS)
        ->and($leg->recipients)->toBe(5)
        ->and($leg->outcome)->toBe(DuressAlertDelivery::SKIPPED)
        ->and($leg->reached())->toBeFalse()
        ->and($leg->elapsed_ms)->toBeGreaterThanOrEqual(0)->toBeLessThan(10_000)
        ->and($leg->note)->toBe('No SMS provider is connected: the text to 5 emergency contacts is written down and delivered to nobody. 2 more contacts on file are not texted: a panic texts at most 5.');

    // …and the site's own numbers are never filed under it: none are on file here.
    // (The Heads of Security's own texts have SMS rows of their own; none is a site number.)
    expect(DuressAlertDelivery::query()->where('duress_alert_id', $logged)->where('channel', DuressAlertDelivery::SMS)->get()
        ->reject(static fn (DuressAlertDelivery $leg): bool => str_starts_with((string) $leg->note, PanicDeliveries::OWN_PHONE))
        ->count())->toBe(1);

    // WITH A PROVIDER: queued on the panic queue, the same five, and REACHED on the report.
    hhcTwilio();

    $queued = (int) hhcCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');
    $leg = hhcLeg($queued);

    expect($leg->recipients)->toBe(5)
        ->and($leg->outcome)->toBe(DuressAlertDelivery::QUEUED)
        ->and($leg->reached())->toBeTrue()
        ->and($leg->note)->toBe('Texted to the household\'s 5 emergency contacts through the panic queue. 2 more contacts on file are not texted: a panic texts at most 5.');

    $report = collect(app(DeliveryReport::class)->forAlert(DuressAlert::query()->findOrFail($queued))['destinations'])
        ->firstWhere('key', DuressAlertDelivery::EMERGENCY_CONTACTS);

    expect($report['reached'])->toBeTrue()
        ->and($report['legs'][0]['elapsed_label'])->toStartWith('handed off in ')
        ->and($report['legs'][0]['recipients_label'])->toBe('5 numbers');

    // AN OFFICER'S DURESS: a row, `none`, and the reason — never silence.
    [, $guardToken] = hhcGuard($this);
    $duress = (int) hhcCall($this, $guardToken, 'duress.store', ['mode' => 'silent'])->json('id');
    $leg = hhcLeg($duress);

    expect($leg->recipients)->toBe(0)
        ->and($leg->outcome)->toBe(DuressAlertDelivery::NONE)
        ->and($leg->note)->toBe('An officer\'s duress texts no household: only a resident\'s panic reaches the household\'s own emergency contacts.');
});

it('shows the household\'s leg in panic:walk, with the reason a drill texts no household', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class]);
    Notification::fake();

    // A drill is raised by nobody's account, so there is no household to find — and the
    // walk says exactly that, instead of "nothing on the platform texts them".
    $this->artisan('panic:walk', ['estate' => $this->site, '--force' => true])
        ->expectsOutputToContain('NOT REACHED  The household\'s emergency contacts — This alert was not raised from a Resident App account')
        ->assertSuccessful();

    FacilitiesFixture::boot();

    $drill = DuressAlert::query()->where('tenant_id', $this->site)->where('is_drill', true)->latest('id')->firstOrFail();
    $leg = hhcLeg((int) $drill->id);

    expect($leg->outcome)->toBe(DuressAlertDelivery::NONE)
        ->and($leg->recipients)->toBe(0);

    Bus::assertNotDispatched(SendHouseholdContactsSms::class);
});
