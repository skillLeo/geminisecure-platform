<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Alerts\SendPanicSms;
use App\Jobs\Push\SendExpoPush;
use App\Models\Estate\Household;
use App\Models\Estate\NotificationDefault;
use App\Models\Estate\Resident;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\PlatformSmsSetting;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Shift;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Services\Dispatch\PanicSmsOutcome;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Services\Estate\Collections;
use App\Services\Estate\Residents;
use App\Services\Gemini\GuardWorkforce;
use App\Services\Platform\SmsSettings;
use App\Services\ResidentApp\LogSmsGateway;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Support\SiteTime;
use Database\Seeders\SiteEmergencyNumbersSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| NOTHING CLAIMS AN SMS WAS SENT — M10 §1
|--------------------------------------------------------------------------
|
| The client's requirement, in his words: "audit every screen and string for
| any claim that an SMS was sent and remove it; nothing may tell a resident in
| an emergency that a text went out when none did."
|
| There is no SMS provider. `SmsSettings::canSend()` is the one question every
| surface asks, and it is false while the log driver is in use: the log driver
| writes a message down and delivers it to NOBODY (`SmsResult::RECORDED`). Only
| `SmsResult::SENT` is a delivery.
|
| So every claim this file touches is proved BOTH WAYS — the honest version
| under the log driver, and the original claim once a (faked) provider has
| really accepted the message. A test that only proved the honest half would
| pass on a platform that simply never mentioned texts at all, which is not
| what was asked: a claim backed by a real delivery stays.
|
|   1  the estate's notification settings: the SMS column, and the refusal
|   2  the resident's panic API: the `sms` field, from the ledger
|   3  the Resident App's sign-in by text
|   4  the operator's emergency-number listing
|   5  the dunning editor's SMS channels
|   6  two sentences that promised a text on acts that send nothing at all
|   7  a sweep of every page, component, mail and view for the phrases
|
| NO TEST REACHES A NETWORK. The "provider" is a row that `canSend()` accepts
| and a gateway spy bound in place of the log driver; `Http::preventStrayRequests()`
| fails anything that tried to go further.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // The framework's TESTING transaction manager, so the "after commit" work a
    // panic does (the fan-out, the SMS count) runs here as it does in production.
    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Http::preventStrayRequests();

    /*
     * NO PROVIDER, ASSERTED RATHER THAN ASSUMED. Another file may have saved a
     * settings row; inside this transaction it is removed, and the rollback puts
     * it back.
     */
    PlatformSmsSetting::query()->delete();
    expect(app(SmsSettings::class)->canSend())->toBeFalse();

    $this->site = FacilitiesFixture::ESTATE;

    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 917');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Honesty household', 'access_restricted' => false]);
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Nell Honesty'],
        ['relationship' => 'owner', 'is_primary' => true],
    );
    $this->resident->forceFill(['is_primary' => true, 'removed_at' => null])->save();

    /*
     * THE ESTATE DATABASE IS COMMITTED AND SHARED, so the notification defaults
     * this file writes are put back exactly as they were found.
     */
    $this->savedDefaults = NotificationDefault::query()->get(['event_key', 'channel', 'enabled'])
        ->map(fn (NotificationDefault $row): array => ['event_key' => $row->event_key, 'channel' => $row->channel, 'enabled' => (bool) $row->enabled])
        ->all();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    NotificationDefault::query()->delete();

    foreach ($this->savedDefaults as $row) {
        NotificationDefault::query()->create($row);
    }

    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

/* ── helpers ─────────────────────────────────────────────────────────── */

/**
 * A provider `canSend()` accepts, and a gateway that answers however the test says.
 *
 * THE ROW AND THE SPY TOGETHER, because they answer different questions: the row
 * is what every screen asks ("can the platform send?"), the spy is what actually
 * happened to each message. The token is a placeholder, not a credential.
 */
function honestProvider(string $answer = SmsResult::SENT): object
{
    PlatformSmsSetting::query()->create([
        'driver' => 'twilio',
        'account_sid' => 'AC-placeholder',
        'token' => 'placeholder-not-a-credential',
        'from' => '+18765550000',
        'verified_at' => Carbon::now(),
    ]);

    expect(app(SmsSettings::class)->canSend())->toBeTrue();

    $spy = new class($answer) implements SmsGateway
    {
        /** @var list<string> */
        public array $to = [];

        public function __construct(private readonly string $answer) {}

        public function send(string $to, string $message): SmsResult
        {
            $this->to[] = $to;

            return $this->answer === SmsResult::SENT
                ? SmsResult::sent('twilio', 'ref-'.count($this->to))
                : SmsResult::failed('twilio', 'ProviderRefused');
        }
    };

    app()->instance(SmsGateway::class, $spy);

    return $spy;
}

/** The client's four numbers on this estate (Q-M09). */
function honestFourNumbers(string $tenantId): void
{
    SiteEmergencyNumber::query()->where('tenant_id', $tenantId)->delete();

    foreach (SiteEmergencyNumbersSeeder::NUMBERS as $index => $row) {
        app(SiteEmergencyNumbers::class)->put($tenantId, $row['role'], $row['label'], $row['number'], $index + 1);
    }
}

/** Nobody clocked on, rostered or assigned — a panic whose only other leg is the texts. */
function honestBareSite(string $tenantId): void
{
    Shift::query()->where('tenant_id', $tenantId)->delete();
    Guard::query()->where('tenant_id', $tenantId)->update(['status' => 'inactive', 'device_id' => null]);
    EstateAssignment::query()->where('tenant_id', $tenantId)->update(['is_active' => false]);
}

function honestResidentToken(TestCase $test, string $email): string
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => $test->site, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Nell Honesty',
        'resident_id' => $test->resident->id, 'unit_id' => $test->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return $account->createToken('resident-app:'.$email, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
}

/** One catalogued endpoint, held to its allowlist — the new `sms.*` paths included. */
function honestCall(TestCase $test, string $token, string $name, array $body = [], array $path = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = $entry['uri'];

    foreach ($path as $key => $value) {
        $uri = str_replace('{'.$key.'}', (string) $value, $uri);
    }

    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($entry['method'], '/api/v1/'.$uri, $body, [
        'Idempotency-Key' => 'honest-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    $response->assertStatus($entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

/** Set one saved switch on the estate, directly. */
function honestDefault(string $event, string $channel, bool $enabled): void
{
    NotificationDefault::query()->updateOrCreate(['event_key' => $event, 'channel' => $channel], ['enabled' => $enabled]);
}

function honestSaved(string $event, string $channel): ?bool
{
    $row = NotificationDefault::query()->where('event_key', $event)->where('channel', $channel)->first();

    return $row === null ? null : (bool) $row->enabled;
}

/* ── 1 · the estate's notification settings ──────────────────────────── */

it('draws the SMS column disabled with the reason on the payload, and keeps what was saved, while no provider is connected', function () {
    honestDefault('dues_reminders', 'sms', true);
    honestDefault('payment_plan_updates', 'sms', false);

    $note = app(SmsSettings::class)->shape()['note'];

    expect($note)->toBeString()->not->toBe('');

    $this->actingAs(FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN))
        ->get(FacilitiesFixture::url('/settings/notifications'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($note) {
            $props = $page->toArray()['props'];

            // The two facts the screen needs, said once for the whole column.
            expect($props['sms_available'])->toBeFalse()
                ->and($props['sms_note'])->toBe($note);

            $channels = collect($props['groups'])->flatMap(fn (array $g): array => $g['rows'])
                ->mapWithKeys(fn (array $row): array => [$row['key'] => collect($row['channels'])->keyBy('key')]);

            foreach ($channels as $row) {
                // SMS: disabled, with the sentence on it.
                expect($row['sms']['available'])->toBeFalse()
                    ->and($row['sms']['unavailable_reason'])->toBe($note)
                    ->and($row['sms']['label'])->toBe('SMS');

                // Email and push are untouched by any of this.
                expect($row['email']['available'])->toBeTrue()
                    ->and($row['push']['available'])->toBeTrue()
                    ->and($row['email']['unavailable_reason'])->toBeNull();
            }

            // WHAT WAS SAVED IS STILL WHAT IS SAVED. Disabling the column is not
            // the same as turning every switch in it off.
            expect($channels['dues_reminders']['sms']['enabled'])->toBeTrue()
                ->and($channels['payment_plan_updates']['sms']['enabled'])->toBeFalse();

            return $page;
        });
});

it('refuses to switch SMS on while no provider is connected, says why, and saves nothing at all', function () {
    honestDefault('dues_reminders', 'sms', false);
    honestDefault('dues_reminders', 'email', true);

    $this->actingAs(FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN))
        ->from(FacilitiesFixture::url('/settings/notifications'))
        ->post(FacilitiesFixture::url('/settings/notifications'), [
            'defaults' => ['dues_reminders' => ['email' => false, 'sms' => true, 'push' => true]],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('defaults');

    $error = session('errors')->first('defaults');

    // A plain sentence: what was refused, why, and what IS allowed.
    expect($error)->toContain('SMS was not switched on')
        ->and($error)->toContain('No SMS provider is connected')
        ->and($error)->toContain('Switching SMS off is allowed');

    // NOTHING WAS WRITTEN — not the SMS switch and not the email switch that
    // rode in the same request.
    expect(honestSaved('dues_reminders', 'sms'))->toBeFalse()
        ->and(honestSaved('dues_reminders', 'email'))->toBeTrue();
});

it('lets SMS be switched off, and leaves a saved SMS value alone when a save does not carry it', function () {
    honestDefault('dues_reminders', 'sms', true);
    honestDefault('meetings_elections', 'sms', true);
    honestDefault('meetings_elections', 'email', true);

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    $this->actingAs($admin)
        ->from(FacilitiesFixture::url('/settings/notifications'))
        ->post(FacilitiesFixture::url('/settings/notifications'), [
            'defaults' => [
                // Off: always allowed — it asks for less.
                'dues_reminders' => ['sms' => false],

                // No SMS key at all, which is exactly what the page posts while the
                // column is disabled. The saved value must survive it.
                'meetings_elections' => ['email' => false],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(honestSaved('dues_reminders', 'sms'))->toBeFalse()
        ->and(honestSaved('meetings_elections', 'email'))->toBeFalse()
        ->and(honestSaved('meetings_elections', 'sms'))->toBeTrue();

    // And a save that carries an already-on SMS value unchanged is not refused:
    // nobody switched anything on.
    $this->actingAs($admin)
        ->from(FacilitiesFixture::url('/settings/notifications'))
        ->post(FacilitiesFixture::url('/settings/notifications'), [
            'defaults' => ['meetings_elections' => ['sms' => true]],
        ])
        ->assertSessionHasNoErrors();

    expect(honestSaved('meetings_elections', 'sms'))->toBeTrue();
});

it('behaves exactly as before once a provider is connected', function () {
    honestProvider();
    honestDefault('dues_reminders', 'sms', false);

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/notifications'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $props = $page->toArray()['props'];

            expect($props['sms_available'])->toBeTrue()
                ->and($props['sms_note'])->toBeNull();

            foreach (collect($props['groups'])->flatMap(fn (array $g): array => $g['rows']) as $row) {
                $sms = collect($row['channels'])->firstWhere('key', 'sms');

                expect($sms['available'])->toBeTrue()
                    ->and($sms['unavailable_reason'])->toBeNull();
            }

            return $page;
        });

    $this->actingAs($admin)
        ->from(FacilitiesFixture::url('/settings/notifications'))
        ->post(FacilitiesFixture::url('/settings/notifications'), [
            'defaults' => ['dues_reminders' => ['sms' => true]],
        ])
        ->assertSessionHasNoErrors();

    expect(honestSaved('dues_reminders', 'sms'))->toBeTrue();
});

/* ── 2 · the resident's panic API ────────────────────────────────────── */

it('tells the resident no text was sent under the log driver, from the ledger, without touching the old fields', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    honestBareSite($this->site);
    honestFourNumbers($this->site);

    $token = honestResidentToken($this, 'honest.log@example.com');

    $raised = honestCall($this, $token, 'duress.store', ['mode' => 'audible']);

    // THE EXISTING FIELDS, EXACTLY AS THE APPS READ THEM TODAY.
    expect($raised->json('sms_recipients'))->toBe(4)
        ->and($raised->json('sms_delivered'))->toBeNull()
        ->and($raised->json('console_alerted'))->toBeTrue();

    // THE NEW ONE: four numbers on file is not four people texted. `household` is the
    // resident's own contacts (M10 §1.2 item 7); this household nominated nobody, so it
    // is finished at once and the note is the estate's sentence, word for word.
    expect($raised->json('sms'))->toBe([
        'provider_connected' => false,
        'attempted' => 0,
        'delivered' => 0,
        'finished' => false,
        'note' => PanicSmsOutcome::NO_PROVIDER,
        'household' => ['attempted' => 0, 'delivered' => 0, 'finished' => true],
    ]);

    // The job runs against the REAL bound gateway — the log driver.
    expect(app(SmsGateway::class)->driver())->toBeInstanceOf(LogSmsGateway::class);
    (new SendPanicSms((int) $raised->json('id')))->handle(app(SiteEmergencyNumbers::class), app(SmsGateway::class));

    $read = honestCall($this, $token, 'duress.show', path: ['alert' => $raised->json('id')]);

    // Four rows, every one `recorded` — written down, delivered to nobody —
    // and none of them is an attempt or a delivery.
    expect($read->json('sms_recipients'))->toBe(4)
        ->and($read->json('sms_delivered'))->toBe(0)
        ->and($read->json('sms'))->toBe([
            'provider_connected' => false,
            'attempted' => 0,
            'delivered' => 0,
            'finished' => true,
            'note' => PanicSmsOutcome::NO_PROVIDER,
            'household' => ['attempted' => 0, 'delivered' => 0, 'finished' => true],
        ]);

    // And nowhere in the answer is there a sentence saying otherwise.
    expect(strtolower((string) $read->getContent()))->not->toContain('texted')
        ->and(strtolower((string) $read->getContent()))->not->toContain('accepted');
});

it('says a text went out once a provider has really accepted it — and says so only then', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    honestBareSite($this->site);
    honestFourNumbers($this->site);

    $spy = honestProvider(SmsResult::SENT);
    $token = honestResidentToken($this, 'honest.sent@example.com');

    $raised = honestCall($this, $token, 'duress.store', ['mode' => 'audible']);

    // Before the job has run, nothing is claimed — not even with a provider.
    expect($raised->json('sms.provider_connected'))->toBeTrue()
        ->and($raised->json('sms.delivered'))->toBe(0)
        ->and($raised->json('sms.finished'))->toBeFalse()
        ->and($raised->json('sms.note'))->toBe('Texting this estate\'s emergency numbers: no text has been confirmed yet.');

    (new SendPanicSms((int) $raised->json('id')))->handle(app(SiteEmergencyNumbers::class), $spy);

    $read = honestCall($this, $token, 'duress.show', path: ['alert' => $raised->json('id')]);

    expect($spy->to)->toHaveCount(4)
        ->and($read->json('sms_delivered'))->toBe(4)
        ->and($read->json('sms'))->toBe([
            'provider_connected' => true,
            'attempted' => 4,
            'delivered' => 4,
            'finished' => true,
            'note' => 'The SMS provider accepted 4 of 4 texts to this estate\'s emergency numbers.',
            'household' => ['attempted' => 0, 'delivered' => 0, 'finished' => true],
        ]);
});

it('says plainly that no text was delivered when a connected provider refused every one', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class]);
    Notification::fake();
    honestBareSite($this->site);
    honestFourNumbers($this->site);

    $spy = honestProvider(SmsResult::FAILED);
    $token = honestResidentToken($this, 'honest.refused@example.com');

    $raised = honestCall($this, $token, 'duress.store', ['mode' => 'audible']);
    (new SendPanicSms((int) $raised->json('id')))->handle(app(SiteEmergencyNumbers::class), $spy);

    $read = honestCall($this, $token, 'duress.show', path: ['alert' => $raised->json('id')]);

    expect($read->json('sms'))->toBe([
        'provider_connected' => true,
        'attempted' => 4,
        'delivered' => 0,
        'finished' => true,
        'note' => 'No text was delivered: the SMS provider did not accept any of them.',
        'household' => ['attempted' => 0, 'delivered' => 0, 'finished' => true],
    ]);
});

/* ── 3 · the Resident App's sign-in by text ──────────────────────────── */

it('refuses a sign-in code by text under the log driver rather than answering "sent"', function () {
    Notification::fake();

    /*
     * THE CLAIM THIS REMOVES. Outside production the log driver was "available",
     * so this answered `202 sent: true` and the app told the resident to watch for
     * a text — while the code went into a log file and reached no phone.
     */
    $response = $this->postJson('/api/v1/auth/otp/request', [
        'estate' => $this->site, 'channel' => 'sms', 'destination' => '+1 876 555 0171',
    ]);

    $response->assertStatus(503);

    expect($response->json('error.code'))->toBe('sms_unavailable')
        ->and($response->json('sent'))->toBeNull();

    // And no code is left live for a message that never went.
    expect(DB::connection('mysql')->table('resident_otps')
        ->where('tenant_id', $this->site)->where('channel', 'sms')
        ->where('destination_hash', hash_hmac('sha256', '+18765550171', (string) config('app.key')))
        ->whereNull('consumed_at')->count())->toBe(0);
});

it('answers "sent" for a code by text only when a provider accepted it', function () {
    Notification::fake();

    $spy = honestProvider(SmsResult::SENT);

    $response = $this->postJson('/api/v1/auth/otp/request', [
        'estate' => $this->site, 'channel' => 'sms', 'destination' => '+1 876 555 0172',
    ]);

    $response->assertStatus(202);
    ApiContract::assertMatches($response, 'auth.otp.request');

    expect($response->json('sent'))->toBeTrue()
        ->and($spy->to)->toBe(['+18765550172']);
});

it('does not answer "sent" for a code by text when a connected provider refuses it, and retires the code', function () {
    Notification::fake();

    /*
     * A TEST OF ITS OWN, and it has to be: the router keeps the controller it
     * built for the first request, gateway and all, so a second spy bound later
     * in the same test would never be asked.
     */
    $refusing = honestProvider(SmsResult::FAILED);

    $refused = $this->postJson('/api/v1/auth/otp/request', [
        'estate' => $this->site, 'channel' => 'sms', 'destination' => '+1 876 555 0173',
    ]);

    $refused->assertStatus(503);

    expect($refused->json('error.code'))->toBe('sms_unavailable')
        ->and($refusing->to)->toBe(['+18765550173'])
        ->and(DB::connection('mysql')->table('resident_otps')
            ->where('tenant_id', $this->site)->where('channel', 'sms')
            ->where('destination_hash', hash_hmac('sha256', '+18765550173', (string) config('app.key')))
            ->whereNull('consumed_at')->count())->toBe(0);
});

/* ── 4 · the operator's emergency-number listing ─────────────────────── */

it('lists the emergency numbers as not texted while no provider is connected, and as texted once one is', function () {
    honestFourNumbers($this->site);

    Artisan::call('site:emergency-numbers', ['tenant' => $this->site]);
    $honest = Artisan::output();

    // "Texted? yes" four times, on a platform with no provider, is what this replaces.
    expect($honest)->toContain('no — no SMS provider is connected')
        ->and($honest)->toContain(PanicSmsOutcome::NO_PROVIDER)
        ->and(preg_match('/\|\s+yes\s+\|/', $honest))->toBe(0);

    honestProvider();

    Artisan::call('site:emergency-numbers', ['tenant' => $this->site]);
    $connected = Artisan::output();

    expect(preg_match_all('/\|\s+yes\s+\|/', $connected))->toBe(4)
        ->and($connected)->not->toContain('no SMS provider is connected');
});

/* ── 5 · the dunning editor's SMS channels ───────────────────────────── */

it('tells the dunning editor an SMS step texts nobody while no provider is connected', function () {
    $board = app(Collections::class)->dunningBoard();

    // The choices stay — a step is the estate's own — and the screen is told why
    // an SMS one does not text anybody yet.
    expect($board['channels'])->toHaveKey('push+email+sms')
        ->and($board['sms_available'])->toBeFalse()
        ->and($board['sms_note'])->toContain('No SMS provider is connected');

    honestProvider();

    $connected = app(Collections::class)->dunningBoard();

    expect($connected['sms_available'])->toBeTrue()
        ->and($connected['sms_note'])->toBeNull();
});

/* ── 6 · two acts that send nothing, whatever is connected ───────────── */

it('never promises a new resident a text, and promises an email only as far as one is sent — with or without a provider', function () {
    /*
     * NO TEXT, UNCONDITIONALLY. Adding a resident emails their invite when there
     * is an address (ResidentInvites — the message after saving says whether the
     * mail server took it) and texts nothing: this act has no SMS path, and
     * connecting a provider does not give it one.
     */
    foreach ([false, true] as $withProvider) {
        if ($withProvider) {
            honestProvider();
        }

        $board = app(Residents::class)->newResidentBoard();

        expect($board['preview']['template'])->not->toContain('will get an SMS')
            ->and($board['preview']['template'])->toContain('No text message is sent')
            ->and($board['preview']['template'])->toContain('With an email address, they are emailed an invite')
            ->and($board['preview']['template'])->toContain('says whether it went')
            ->and(array_column($board['verification'], 'label'))->not->toContain('Send self-verification invite')
            ->and(array_column($board['verification'], 'value'))->toBe(['invite', 'manual']);
    }
});

it('no longer tells the office that a guard\'s enrolment invite reaches their phone', function () {
    try {
        app(GuardWorkforce::class)->add(FacilitiesFixture::geminiViewer(Role::DIRECTOR), []);

        $this->fail('A guard with no details at all was accepted.');
    } catch (ValidationException $refused) {
        $message = $refused->errors()['phone'][0] ?? '';
    }

    // The code is read to the officer; nothing on guard creation texts anybody.
    expect($message)->not->toContain('invite reaches')
        ->and($message)->toContain('Nothing is texted to it');
});

/* ── 7 · the sweep ───────────────────────────────────────────────────── */

it('finds no unconditional claim of a text in any page, component, mail or view', function () {
    /*
     * THE PHRASES THE AUDIT LOOKED FOR, as a tripwire for the next one. Comments
     * are stripped first — this repository explains its old mistakes in them,
     * quoting the very sentence it removed — so what is searched is what a reader
     * could actually be shown.
     */
    $claims = [
        '/\bsms (was |were |has been )?sent\b/i',
        '/\bsent (you |them )?(an? )?(sms|text)\b/i',
        '/\b(via|by) sms\b/i',
        '/\bwe have (also )?sent (you )?a text\b/i',
        '/\b(was|were|has been|have been) texted\b/i',
        '/\bwill (get|receive) an? (sms|text)\b/i',
    ];

    $files = [
        ...glob(resource_path('js/Pages/**/*.vue')) ?: [],
        ...glob(resource_path('js/Pages/*/*/*.vue')) ?: [],
        ...glob(resource_path('js/Pages/*/*/*/*.vue')) ?: [],
        ...glob(resource_path('js/Components/*.vue')) ?: [],
        ...glob(resource_path('js/Layouts/*.vue')) ?: [],
        ...glob(resource_path('js/composables/*.js')) ?: [],
        ...glob(resource_path('views/*/*.blade.php')) ?: [],
        ...glob(app_path('Notifications/*.php')) ?: [],
    ];

    $files = array_values(array_unique($files));

    expect(count($files))->toBeGreaterThan(50);

    $found = [];

    foreach ($files as $file) {
        $text = (string) file_get_contents($file);

        // HTML comments, block comments, line comments — in that order.
        $text = (string) preg_replace('/<!--.*?-->/s', '', $text);
        $text = (string) preg_replace('#/\*.*?\*/#s', '', $text);
        $text = (string) preg_replace('#^\s*//.*$#m', '', $text);

        foreach ($claims as $claim) {
            if (preg_match($claim, $text, $match) === 1) {
                $found[] = basename($file).': "'.$match[0].'"';
            }
        }
    }

    expect($found)->toBe([]);
});
