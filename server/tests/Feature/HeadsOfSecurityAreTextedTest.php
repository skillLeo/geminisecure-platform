<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Enums\Console;
use App\Jobs\Alerts\SendHeadsOfSecuritySms;
use App\Jobs\Alerts\SendHouseholdContactsSms;
use App\Jobs\Alerts\SendPanicSms;
use App\Jobs\Push\SendExpoPush;
use App\Models\AlertSmsDispatch;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\PlatformSmsSetting;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\HeadsOfSecuritySms;
use App\Services\Dispatch\PanicSms;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Services\Platform\SmsSettings;
use App\Services\Push\PanicDeliveries;
use App\Services\ResidentApp\LogSmsGateway;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Services\ResidentApp\TwilioSmsGateway;
use App\Support\SiteTime;
use Database\Seeders\SiteEmergencyNumbersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| BOTH HEADS OF SECURITY ARE TEXTED — M10 §1.2 items 4 and 5
|--------------------------------------------------------------------------
|
| "The Gemini Head of Security for that site — dashboard alert plus email (and
| SMS when a provider exists)" and "the estate's own Head of Security —
| dashboard alert plus email/SMS". What this file proves:
|
|   1  both are texted, at the phone each gave on §1.4's page, on a resident's
|      panic and on a guard's duress — one job, on the panic queue — and Twilio
|      accepting them is the only thing that makes a row `sent`
|   2  under the log driver the rows are `recorded` and no provider is asked
|   3  a Head of Security with no phone is nobody to text, and says why
|   4  a number already on the site's list is texted once, and the row says so
|   5  another estate's Heads of Security are never texted for this one's alert
|   6  the hand-off rows and the dispatcher's line say what happened
|
| NO TEST REACHES A NETWORK: `Http::preventStrayRequests()`, and `Http::fake()`
| standing in for Twilio's one endpoint. The central database is wrapped in a
| transaction with the TESTING manager, so the "after commit" fan-out runs here as
| it does in production; every person and number this file makes goes with it.
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
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class, SendHeadsOfSecuritySms::class]);
    Notification::fake();

    // NO PROVIDER, asserted rather than assumed.
    PlatformSmsSetting::query()->delete();
    config(['services.sms.driver' => 'log', 'services.sms.account_sid' => null, 'services.sms.token' => null, 'services.sms.from' => null]);
    expect(app(SmsSettings::class)->canSend())->toBeFalse();

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();

    // NOBODY ELSE'S PEOPLE OR NUMBERS: every Head of Security and number here is this file's.
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 938');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Headcheck household', 'access_restricted' => false]);
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Rae Headcheck'],
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

/** A Gemini Head of Security assigned to one estate, with the phone they gave (or none). */
function hosGemini(?string $phone, string $tenantId): User
{
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    EstateAssignment::create([
        'user_id' => $head->id, 'tenant_id' => $tenantId,
        'role_id' => Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id, 'is_active' => true,
    ]);

    $head->forceFill(['contact_phone' => $phone])->save();

    return $head->fresh();
}

/** An estate's own Head of Security (§2), assigned to that estate, with the phone they gave. */
function hosEstate(?string $phone, string $tenantId): User
{
    if ($tenantId === FacilitiesFixture::ESTATE) {
        $head = FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);
    } else {
        $head = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
        $head->syncRoles([Role::ESTATE_HEAD_OF_SECURITY]);

        EstateAssignment::create([
            'user_id' => $head->id, 'tenant_id' => $tenantId,
            'role_id' => Role::query()->where('name', Role::ESTATE_HEAD_OF_SECURITY)->firstOrFail()->id, 'is_active' => true,
        ]);
    }

    $head->forceFill(['contact_phone' => $phone])->save();

    return $head->fresh();
}

function hosResidentToken(TestCase $test, string $email): string
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => $test->site, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Rae Headcheck',
        'resident_id' => $test->resident->id, 'unit_id' => $test->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return $account->createToken('resident-app:'.$email, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
}

function hosGuardToken(TestCase $test): string
{
    $post = Post::create(['tenant_id' => $test->site, 'name' => 'Headcheck Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'Officer Headcheck', 'employee_number' => 'GS-HOS-1', 'psra_number' => 'PSRA-HOS-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $test->site, 'post_id' => $post->id,
    ]);

    return app(DeviceEnrolment::class)->enrol($guard, 'Headcheck handset')['token'];
}

function hosCall(TestCase $test, string $token, string $name, array $body = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];

    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($entry['method'], '/api/v1/'.$entry['uri'], $body, [
        'Idempotency-Key' => 'hos-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** A Twilio row `canSend()` accepts. The token is a placeholder, not a credential. */
function hosTwilio(): void
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

/** A gateway that records every number and accepts every one. */
function hosSpy(): object
{
    return new class implements SmsGateway
    {
        /** @var list<string> */
        public array $to = [];

        public function send(string $to, string $message): SmsResult
        {
            $this->to[] = $to;

            return SmsResult::sent('test', 'ref-'.count($this->to));
        }
    };
}

/** Run the Heads of Security's job as the worker would. */
function hosRun(int $alertId, SmsGateway $gateway): void
{
    (new SendHeadsOfSecuritySms($alertId))->handle(app(HeadsOfSecuritySms::class), $gateway, app(SmsSettings::class));
    FacilitiesFixture::boot();
}

/** @return Illuminate\Support\Collection<int, AlertSmsDispatch> */
function hosRows(int $alertId): Illuminate\Support\Collection
{
    return AlertSmsDispatch::query()->where('duress_alert_id', $alertId)->where('audience', AlertSmsDispatch::STAFF)->orderBy('id')->get();
}

/** The Head of Security's OWN text leg under a destination — never the site number filed there too. */
function hosLeg(int $alertId, string $destination): DuressAlertDelivery
{
    $legs = DuressAlertDelivery::query()->where('duress_alert_id', $alertId)
        ->where('destination', $destination)->where('channel', DuressAlertDelivery::SMS)
        ->get()
        ->filter(static fn (DuressAlertDelivery $leg): bool => str_starts_with((string) $leg->note, PanicDeliveries::OWN_PHONE));

    expect($legs)->toHaveCount(1);

    return $legs->first();
}

/** The dispatcher's line for the Heads of Security's texts. */
function hosLine(TestCase $test, int $alertId): mixed
{
    $seen = 'missing';

    $test->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->get('/dispatch/alerts/'.$alertId)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$seen): AssertableInertia {
            $alert = $page->toArray()['props']['alert'];
            $seen = array_key_exists('heads_of_security_texts', $alert) ? $alert['heads_of_security_texts'] : 'missing';

            return $page->component('Gemini/Dispatch/Alert');
        });

    return $seen;
}

/* ── 1 · both are texted, and Twilio accepting is what makes it `sent` ─── */

it('texts both Heads of Security on a resident\'s panic and on a guard\'s duress, and records `sent` only when Twilio accepted', function () {
    // 9:41 PM in Kingston.
    Carbon::setTestNow(Carbon::parse('2026-09-24T02:41:00Z'));

    $gemini = hosGemini('+1 876 555 0171', $this->site);
    $estate = hosEstate('876 555 0172', $this->site);

    hosTwilio();
    Http::fake([
        'api.twilio.com/*' => Http::response(['sid' => 'SM0123456789abcdef0123456789abcdef', 'status' => 'queued'], 201),
    ]);

    $alertId = (int) hosCall($this, hosResidentToken($this, 'hos.twilio@example.com'), 'duress.store', ['mode' => 'audible'])->json('id');

    // ONE job, on the central connection's PANIC queue, carrying the alert id and nothing else.
    Bus::assertDispatched(SendHeadsOfSecuritySms::class, fn (SendHeadsOfSecuritySms $job): bool => $job->alertId === $alertId
        && $job->queue === config('push.urgent_queue')
        && $job->connection === config('queue.central_connection', 'central'));

    $alert = DuressAlert::query()->findOrFail($alertId);

    expect($alert->staff_sms_recipients)->toBe(2)
        ->and($alert->staff_sms_delivered)->toBeNull()
        ->and($alert->staff_sms_no_phone)->toBe(0)
        ->and($alert->staff_sms_deduplicated)->toBe(0);

    hosRun($alertId, app(SmsGateway::class));

    // THE SITE'S OWN MESSAGE: where, what, the site's clock, the alert number — and no name.
    $body = PanicSms::body($alert->fresh()->load('estate'));

    Http::assertSentCount(2);
    Http::assertSent(fn (HttpRequest $request): bool => $request['To'] === '+18765550171' && $request['Body'] === $body);
    Http::assertSent(fn (HttpRequest $request): bool => $request['To'] === '+18765550172' && $request['Body'] === $body);

    expect($body)->toContain('Lot 938')->and($body)->toContain('Phoenix Park')->and($body)->not->toContain('Headcheck');

    $rows = hosRows($alertId);

    expect($rows->pluck('status')->all())->toBe([SmsResult::SENT, SmsResult::SENT])
        ->and($rows->pluck('user_id')->all())->toBe([$gemini->id, $estate->id])
        ->and($rows->pluck('role')->all())->toBe(['gemini_head_of_security', 'estate_head_of_security'])
        ->and($rows->pluck('label')->all())->toBe(['Gemini Head of Security', 'Estate Head of Security'])
        ->and($rows->pluck('number')->all())->toBe(['+1876•••0171', '+1876•••0172'])
        ->and($rows->pluck('e164')->unique()->all())->toBe([null])
        ->and(DuressAlert::query()->findOrFail($alertId)->staff_sms_delivered)->toBe(2);

    // NO NAME AND NO FULL NUMBER on the central rows.
    $ledger = json_encode($rows->toArray(), JSON_UNESCAPED_UNICODE);

    expect($ledger)->not->toContain((string) $gemini->name)
        ->and($ledger)->not->toContain((string) $estate->name)
        ->and($ledger)->not->toContain('5550171')
        ->and($ledger)->not->toContain('5550172');

    // AN OFFICER'S DURESS reaches the same two people, exactly as the escalation mail does.
    $duressId = (int) hosCall($this, hosGuardToken($this), 'duress.store', ['mode' => 'silent'])->json('id');

    Bus::assertDispatched(SendHeadsOfSecuritySms::class, fn (SendHeadsOfSecuritySms $job): bool => $job->alertId === $duressId);
    expect(DuressAlert::query()->findOrFail($duressId)->staff_sms_recipients)->toBe(2);

    // AND A DRILL SAYS SO FIRST, in the text a Head of Security reads on a lock screen.
    $drill = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Drill (panic:walk)', unitReference: 'Lot 938', isDrill: true);

    expect(PanicSms::body($drill->load('estate')))->toStartWith('GeminiSecure: DRILL PANIC ALARM');
});

/* ── 2 · the log driver: written down, delivered to nobody ───────────── */

it('records every Head of Security\'s text as written down while no provider is connected, and asks no provider', function () {
    hosGemini('+1 876 555 0173', $this->site);
    hosEstate('876 555 0174', $this->site);

    $alertId = (int) hosCall($this, hosResidentToken($this, 'hos.log@example.com'), 'duress.store', ['mode' => 'audible'])->json('id');

    // A gateway that would ACCEPT anything — and is never asked, because nothing can send.
    $spy = hosSpy();
    hosRun($alertId, $spy);

    expect($spy->to)->toBe([]);

    $rows = hosRows($alertId);

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe([SmsResult::RECORDED])
        ->and($rows->pluck('driver')->unique()->all())->toBe([LogSmsGateway::DRIVER])
        ->and(DuressAlert::query()->findOrFail($alertId)->staff_sms_delivered)->toBe(0);

    expect(hosLine($this, $alertId))->toBe('not texted: no SMS provider connected');
});

/* ── 3 · no phone on file ─────────────────────────────────────────────── */

it('records a Head of Security with no phone as nobody to text, with the reason, and a site with none as none on file', function () {
    hosGemini(null, $this->site);
    hosEstate(null, $this->site);

    $alertId = (int) hosCall($this, hosResidentToken($this, 'hos.nophone@example.com'), 'duress.store', ['mode' => 'audible'])->json('id');

    Bus::assertNotDispatched(SendHeadsOfSecuritySms::class);

    $alert = DuressAlert::query()->findOrFail($alertId);

    expect($alert->staff_sms_recipients)->toBe(0)
        ->and($alert->staff_sms_no_phone)->toBe(2)
        ->and($alert->staff_sms_delivered)->toBe(0)
        ->and(hosRows($alertId))->toHaveCount(0);

    $gemini = hosLeg($alertId, DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY);
    $estate = hosLeg($alertId, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY);

    expect($gemini->outcome)->toBe(DuressAlertDelivery::NONE)
        ->and($gemini->recipients)->toBe(0)
        ->and($gemini->note)->toBe(PanicDeliveries::OWN_PHONE.'1 Gemini Head of Security on file, but no phone number to text.')
        ->and($estate->outcome)->toBe(DuressAlertDelivery::NONE)
        ->and($estate->note)->toBe(PanicDeliveries::OWN_PHONE.'1 Head of Security of the estate\'s own on file, but no phone number to text.');

    expect(hosLine($this, $alertId))->toBe('not texted: no phone on file');

    // AND A SITE WITH NO HEAD OF SECURITY AT ALL: none, and a sentence that says who is missing.
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);

    $bare = (int) hosCall($this, hosResidentToken($this, 'hos.nobody@example.com'), 'duress.store', ['mode' => 'audible'])->json('id');

    expect(hosLeg($bare, DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY)->note)->toBe(PanicDeliveries::OWN_PHONE.'no Gemini Head of Security covering this site is on file to text.')
        ->and(hosLeg($bare, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY)->note)->toBe(PanicDeliveries::OWN_PHONE.'the estate has no Head of Security of its own on file to text.')
        ->and(hosLine($this, $bare))->toBe('none on file');
});

/* ── 4 · one number, one text ─────────────────────────────────────────── */

it('texts a number once: a Head of Security whose phone is a site emergency number, or the other\'s, is recorded as deduplicated', function () {
    foreach (SiteEmergencyNumbersSeeder::NUMBERS as $index => $row) {
        app(SiteEmergencyNumbers::class)->put($this->site, $row['role'], $row['label'], $row['number'], $index + 1);
    }

    $siteHead = SiteEmergencyNumber::query()->where('tenant_id', $this->site)->where('role', 'head_of_security')->firstOrFail();

    // The estate's own Head of Security IS the "PPV1 Head of Security" number, written another way.
    $estate = hosEstate('876 845 9422', $this->site);

    // Two Gemini Heads of Security who gave the same phone, written two ways.
    $first = hosGemini('+1 876 555 0181', $this->site);
    $second = hosGemini('(876) 555-0181', $this->site);

    hosTwilio();
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM0000000000000000000000000000abcd', 'status' => 'queued'], 201)]);

    $alertId = (int) hosCall($this, hosResidentToken($this, 'hos.dedupe@example.com'), 'duress.store', ['mode' => 'audible'])->json('id');

    $alert = DuressAlert::query()->findOrFail($alertId);

    expect($alert->staff_sms_recipients)->toBe(1)
        ->and($alert->staff_sms_deduplicated)->toBe(2);

    hosRun($alertId, app(SmsGateway::class));

    // ONE text to one phone, and never a second one to the Head of Security's site number.
    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $request): bool => $request['To'] === '+18765550181');

    $rows = hosRows($alertId)->keyBy('user_id');

    expect($rows[$first->id]->status)->toBe(SmsResult::SENT)
        ->and($rows[$second->id]->status)->toBe(AlertSmsDispatch::DEDUPLICATED)
        ->and($rows[$second->id]->error)->toBe(HeadsOfSecuritySms::SAME_AS_ANOTHER_HEAD)
        ->and($rows[$estate->id]->status)->toBe(AlertSmsDispatch::DEDUPLICATED)
        ->and($rows[$estate->id]->error)->toBe(HeadsOfSecuritySms::SAME_AS_SITE_NUMBER)
        // …and WHICH site number covered it.
        ->and($rows[$estate->id]->site_emergency_number_id)->toBe($siteHead->id)
        ->and(DuressAlert::query()->findOrFail($alertId)->staff_sms_recipients)->toBe(1)
        ->and(DuressAlert::query()->findOrFail($alertId)->staff_sms_delivered)->toBe(1);

    // The hand-off rows say it rather than looking one short.
    expect(hosLeg($alertId, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY)->outcome)->toBe(DuressAlertDelivery::SKIPPED)
        ->and(hosLeg($alertId, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY)->note)->toContain('not texted twice')
        ->and(hosLeg($alertId, DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY)->recipients)->toBe(1)
        ->and(hosLeg($alertId, DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY)->note)->toContain('1 Gemini Head of Security not texted twice');

    expect(hosLine($this, $alertId))->toBe('1 texted; 2 not texted twice: the same number is already being texted');
});

/* ── 5 · another estate's Heads of Security, never ───────────────────── */

it('never texts another estate\'s Heads of Security about this estate\'s alert', function () {
    $other = 'hossecondestate';

    $ours = hosGemini('+1 876 555 0191', $this->site);
    $theirGemini = hosGemini('+1 876 555 0198', $other);
    $theirEstate = hosEstate('+1 876 555 0199', $other);

    hosTwilio();

    $alertId = (int) hosCall($this, hosResidentToken($this, 'hos.isolation@example.com'), 'duress.store', ['mode' => 'audible'])->json('id');

    $planned = array_column(app(HeadsOfSecuritySms::class)->plan(DuressAlert::query()->findOrFail($alertId)), 'user_id');

    expect($planned)->toBe([$ours->id]);

    $spy = hosSpy();
    hosRun($alertId, $spy);

    expect($spy->to)->toBe(['+18765550191'])
        ->and(hosRows($alertId)->pluck('user_id')->all())->not->toContain($theirGemini->id)
        ->and(hosRows($alertId)->pluck('user_id')->all())->not->toContain($theirEstate->id);

    // And the other way round: an alert at the other estate plans only its own two.
    $abroad = DuressAlert::withoutEvents(fn () => DuressAlert::create([
        'tenant_id' => $other, 'kind' => 'panic', 'status' => 'open', 'server_time' => Carbon::now(),
        'clock_skewed' => false, 'captured_offline' => false, 'is_simulated' => false,
    ]));

    expect(array_column(app(HeadsOfSecuritySms::class)->plan($abroad), 'user_id'))->toBe([$theirGemini->id, $theirEstate->id]);
});

/* ── 6 · the hand-off rows and the dispatcher's line ─────────────────── */

it('records both Heads of Security\'s texts as their own destinations, and the dispatcher reads what they were sent', function () {
    hosGemini('+1 876 555 0161', $this->site);
    hosEstate('+1 876 555 0162', $this->site);

    $token = hosResidentToken($this, 'hos.legs@example.com');

    // UNDER THE LOG DRIVER: counted, and `skipped`, because nothing is sent.
    $logged = (int) hosCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');

    $gemini = hosLeg($logged, DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY);

    expect($gemini->recipients)->toBe(1)
        ->and($gemini->outcome)->toBe(DuressAlertDelivery::SKIPPED)
        ->and($gemini->reached())->toBeFalse()
        ->and($gemini->elapsed_ms)->toBeGreaterThanOrEqual(0)->toBeLessThan(10_000)
        ->and($gemini->note)->toBe(PanicDeliveries::OWN_PHONE.'no SMS provider is connected, so the text to 1 Gemini Head of Security is written down and delivered to nobody.')
        ->and(hosLeg($logged, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY)->outcome)->toBe(DuressAlertDelivery::SKIPPED);

    // WITH A PROVIDER: queued on the panic queue, and reached.
    hosTwilio();
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1111111111111111111111111111abcd', 'status' => 'queued'], 201)]);

    $queued = (int) hosCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');

    foreach ([DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY] as $destination) {
        expect(hosLeg($queued, $destination)->outcome)->toBe(DuressAlertDelivery::QUEUED)
            ->and(hosLeg($queued, $destination)->recipients)->toBe(1)
            ->and(hosLeg($queued, $destination)->reached())->toBeTrue();
    }

    expect(hosLeg($queued, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY)->note)->toBe(PanicDeliveries::OWN_PHONE.'texted to 1 Head of Security of the estate\'s own through the panic queue.');

    // The dispatcher's line: honest before the job runs, and a count once Twilio accepted.
    expect(hosLine($this, $queued))->toBe('2 being texted: none confirmed yet');

    hosRun($queued, app(SmsGateway::class));

    expect(hosLine($this, $queued))->toBe('2 texted');

    // An officer's duress has the line too — the Heads of Security are told about both.
    $duress = (int) hosCall($this, hosGuardToken($this), 'duress.store', ['mode' => 'silent'])->json('id');

    expect(hosLine($this, $duress))->toBe('2 being texted: none confirmed yet');
});
