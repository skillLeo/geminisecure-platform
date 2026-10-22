<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\PlatformSmsSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\SmsSettings;
use App\Services\ResidentApp\LogSmsGateway;
use App\Services\ResidentApp\ResidentSignIn;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Services\ResidentApp\TwilioSmsGateway;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Which provider the platform texts through — M10 §1
|--------------------------------------------------------------------------
|
| The client asked to connect Twilio without a deploy: "the same way you built
| email settings — provider, encrypted credentials, write-only, audited, with a
| test button". These tests pin the properties that make that safe and honest.
|
|   The token never leaves the server — not in a response, not in an Inertia
|   prop, not in an audit row, not in a session's old input.
|
|   The log driver is not a provider. Nothing reads "connected" over it, and
|   the test button refuses it rather than passing because a message was written
|   to a file.
|
|   Only a provider's 2xx with a message sid is a delivery, and only a delivery
|   stamps "last proved".
|
|   Only the Director reaches it, exactly as with Email.
|
*/

/*
 * ONE ROW, CLEARED BEFORE AND AFTER EVERY TEST. The suite does not refresh the
 * database, and this row is not inert the way the mail row is: `SmsSettings::apply()`
 * runs at every boot and on every resolution of the gateway, so a Twilio row left
 * behind would turn the NEXT file's panic texts into real HTTP calls — and
 * `phpunit.xml` shuffles the files, so "the next file" is anybody's. Cleared after
 * as well as before for exactly that reason.
 *
 * The roles are seeded on the CELL, the rule `FacilitiesFixture::platform()` gives:
 * a database seeded with an older matrix may have a Director without this module.
 */
beforeEach(function () {
    $seeded = Role::query()
        ->where('name', Role::DIRECTOR)
        ->whereHas('permissions', static fn ($query) => $query->where('name', 'gemini.platform_settings.configure_messaging'))
        ->exists();

    if (! $seeded) {
        Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    PlatformSmsSetting::query()->delete();

    // What a server with nothing in `.env` has. Set here so a developer's own
    // environment cannot make a "log" assertion pass or fail.
    config([
        'services.sms.driver' => 'log',
        'services.sms.account_sid' => null,
        'services.sms.token' => null,
        'services.sms.from' => null,
    ]);

    // Nothing in this file may reach the real Twilio.
    Http::preventStrayRequests();

    $this->withoutVite();
});

afterEach(function () {
    PlatformSmsSetting::query()->delete();
});

const SMS_SID = 'AC0123456789abcdef0123456789abcdef';

function smsSettingsUser(string $roleName): User
{
    $user = User::factory()->create([
        'console' => Console::Gemini->value,
        'status' => 'active',
    ]);

    $user->syncRoles([$roleName]);

    return $user->fresh();
}

/** A token nobody else in the suite could produce, so finding it anywhere is proof of a leak. */
function smsSecret(): string
{
    return 'tok-'.Str::lower(Str::random(28));
}

/** A complete Twilio row, written past the service so each test starts from a known state. */
function smsTwilioRow(string $token, array $overrides = []): PlatformSmsSetting
{
    return PlatformSmsSetting::query()->create([
        'driver' => PlatformSmsSetting::TWILIO,
        'account_sid' => SMS_SID,
        'token' => $token,
        'from' => '+1 876 555 0100',
        ...$overrides,
    ]);
}

/** The id of the newest audit entry, so a test can read only what it wrote. */
function smsAuditMark(): int
{
    return (int) DB::connection('mysql')->table('audit_log')->max('id');
}

/** @return list<object> */
function smsAuditSince(int $mark): array
{
    return DB::connection('mysql')->table('audit_log')
        ->where('id', '>', $mark)
        ->where('entity_type', 'platform_sms_setting')
        ->orderBy('id')
        ->get()
        ->all();
}

it('never lets the token out — not in the payload, the page, the audit log or the session', function () {
    $director = smsSettingsUser(Role::DIRECTOR);
    $token = smsSecret();
    $mark = smsAuditMark();

    $this->actingAs($director)->post('/settings/sms', [
        'driver' => 'twilio',
        'account_sid' => SMS_SID,
        'token' => $token,
        'from' => '+1 876 555 0100',
    ])->assertSessionHasNoErrors();

    // Saved, and encrypted at rest: the column holds a ciphertext, not the token.
    $raw = DB::connection('mysql')->table('platform_sms_settings')->value('token');
    expect(app(SmsSettings::class)->current()?->token)->toBe($token)
        ->and((string) $raw)->not->toContain($token);

    // The shape the screen is given.
    $shape = app(SmsSettings::class)->shape();
    expect($shape)->not->toHaveKey('token')
        ->and($shape['has_token'])->toBeTrue()
        ->and(json_encode($shape))->not->toContain($token);

    // The Inertia props, and the rendered page that ships.
    $page = $this->actingAs($director)->get('/settings/sms');

    $page->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('Gemini/Settings/Sms')
        ->where('settings.has_token', true)
        ->missing('settings.token')
        ->where('canConfigure', true));

    expect($page->getContent())->not->toContain($token);

    // A future caller reaching for `toArray()` is protected too.
    expect(json_encode(app(SmsSettings::class)->current()?->toArray()))->not->toContain($token);

    // The audit entry names the field that moved, and not its value.
    $entries = smsAuditSince($mark);
    expect($entries)->not->toBeEmpty()
        ->and(collect($entries)->pluck('action')->all())->toContain('platform.sms_settings_changed')
        ->and(json_encode($entries))->toContain('token')
        ->and(json_encode($entries))->not->toContain($token);

    // A REFUSED save must not leave the token in the session's old input, which is a
    // plain-text column in `sessions`. The sender is one character over its limit.
    $second = smsSecret();

    $this->actingAs($director)->post('/settings/sms', [
        'driver' => 'twilio',
        'account_sid' => SMS_SID,
        'token' => $second,
        'from' => str_repeat('9', 33),
    ])->assertSessionHasErrors('from');

    expect(session()->getOldInput())->not->toHaveKey('token')
        ->and(json_encode(session()->all()))->not->toContain($second);
});

it('keeps the saved token when the field is left blank', function () {
    $token = smsSecret();
    $settings = smsTwilioRow($token);

    // Changing the sender must not require re-typing a secret — which is what makes a
    // write-only field usable rather than an obstacle.
    $this->actingAs(smsSettingsUser(Role::DIRECTOR))->post('/settings/sms', [
        'driver' => 'twilio',
        'account_sid' => SMS_SID,
        'token' => '',
        'from' => 'GEMINI',
    ])->assertSessionHasNoErrors();

    expect($settings->fresh()->token)->toBe($token)
        ->and($settings->fresh()->from)->toBe('GEMINI');
});

it('refuses a driver the platform has no gateway for', function () {
    $director = smsSettingsUser(Role::DIRECTOR);

    // Through the screen: a validation error on the field, and nothing saved.
    $this->actingAs($director)->post('/settings/sms', [
        'driver' => 'vonage',
        'account_sid' => SMS_SID,
        'token' => smsSecret(),
        'from' => '+18765550100',
    ])->assertSessionHasErrors('driver');

    expect(PlatformSmsSetting::query()->count())->toBe(0);

    // And past it: the service refuses on its own, for any caller.
    expect(fn () => app(SmsSettings::class)->save(['driver' => 'vonage', 'token' => 'x', 'from' => 'x'], $director))
        ->toThrow(DomainException::class, 'not a provider this platform can send through');

    // A row that says so anyway — written by hand — is not one that can send: the binding
    // would carry it to the log driver, so nothing may read "connected" over it.
    $stray = PlatformSmsSetting::query()->create(['driver' => 'vonage', 'account_sid' => SMS_SID, 'token' => 'x', 'from' => 'x']);

    expect($stray->canSend())->toBeFalse()
        ->and(app(SmsSettings::class)->canSend())->toBeFalse();

    app(SmsSettings::class)->apply();
    expect(app(SmsGateway::class)->driver())->toBeInstanceOf(LogSmsGateway::class);
});

it('will not save Twilio without an account SID, a token and a sender, and says which is missing', function () {
    $director = smsSettingsUser(Role::DIRECTOR);

    $this->actingAs($director)->post('/settings/sms', [
        'driver' => 'twilio',
        'account_sid' => 'SK0123456789abcdef0123456789abcdef',
        'token' => smsSecret(),
        'from' => '+18765550100',
    ])->assertSessionHasErrors(['settings' => 'That is not a Twilio account SID. An account SID starts with AC and is 34 characters long — an API key starts with SK and a messaging service with MG, and neither works here.']);

    $this->actingAs($director)->post('/settings/sms', [
        'driver' => 'twilio',
        'account_sid' => SMS_SID,
        'token' => '',
        'from' => '+18765550100',
    ])->assertSessionHasErrors('settings');

    expect(PlatformSmsSetting::query()->count())->toBe(0);
});

it('cannot send on the log driver, and the test button refuses rather than passing', function () {
    Http::fake();

    // Nothing saved and nothing in `.env`: the platform cannot text, and says so.
    expect(app(SmsSettings::class)->canSend())->toBeFalse()
        ->and(app(SmsSettings::class)->shape()['note'])->toStartWith('No SMS provider is connected');

    // A saved row on "None", with credentials kept for later — still cannot send.
    PlatformSmsSetting::query()->create(['driver' => 'log', 'account_sid' => SMS_SID, 'token' => smsSecret(), 'from' => '+18765550100']);

    $shape = app(SmsSettings::class)->shape();

    expect(app(SmsSettings::class)->canSend())->toBeFalse()
        ->and($shape['can_send'])->toBeFalse()
        ->and($shape['note'])->not->toBeNull()
        ->and($shape['driver_label'])->toBe(PlatformSmsSetting::DRIVERS['log'])
        ->and($shape['driver_label'])->toContain('delivered to nobody');

    expect(fn () => app(SmsSettings::class)->sendTest('876 555 0147'))
        ->toThrow(DomainException::class, 'delivered to nobody, and a test that passed would prove nothing');

    // Through the screen, the same refusal, in a sentence — and nothing was verified or sent.
    $this->actingAs(smsSettingsUser(Role::DIRECTOR))
        ->post('/settings/sms/test', ['to' => '876 555 0147'])
        ->assertSessionHasErrors('sms')
        ->assertSessionMissing('success');

    expect(PlatformSmsSetting::query()->first()->verified_at)->toBeNull();
    Http::assertNothingSent();
});

it('stamps the settings as proved when Twilio accepts the test, and audits it', function () {
    $token = smsSecret();
    $settings = smsTwilioRow($token);
    $mark = smsAuditMark();

    Http::fake([
        'api.twilio.com/*' => Http::response(['sid' => 'SM0123456789abcdef0123456789abcdef', 'status' => 'queued'], 201),
    ]);

    $this->actingAs(smsSettingsUser(Role::DIRECTOR))
        ->post('/settings/sms/test', ['to' => '876 555 0147'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Twilio accepted the test message')
            && str_contains($message, 'SM0123456789abcdef0123456789abcdef'));

    // The request Twilio documents: basic auth as the account, a form, To/From/Body, and
    // both numbers in E.164 — the sender read the way `JamaicanNumber` reads every number.
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/'.SMS_SID.'/Messages.json'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode(SMS_SID.':'.$token))
        && $request['To'] === '+18765550147'
        && $request['From'] === '+18765550100'
        && str_contains((string) $request['Body'], 'GeminiSecure test message'));

    expect($settings->fresh()->verified_at)->not->toBeNull()
        ->and(app(SmsSettings::class)->shape()['verified_at'])->not->toBeNull();

    $entries = smsAuditSince($mark);

    expect(collect($entries)->pluck('action')->all())->toContain('platform.sms_test_sent')
        ->and(json_encode($entries))->not->toContain($token)
        // The number texted is recorded masked, never in full.
        ->and(json_encode($entries))->not->toContain('8765550147');
});

it('refuses a test Twilio turns down, in words and with its code, and leaves the settings unverified', function () {
    $token = smsSecret();
    $settings = smsTwilioRow($token);
    $mark = smsAuditMark();

    Http::fake([
        'api.twilio.com/*' => Http::response([
            'code' => 21211,
            'message' => "The 'To' number +18765550147 is not a valid phone number.",
            'more_info' => 'https://www.twilio.com/docs/errors/21211',
            'status' => 400,
        ], 400),
    ]);

    $response = $this->actingAs(smsSettingsUser(Role::DIRECTOR))
        ->post('/settings/sms/test', ['to' => '876 555 0147'])
        ->assertSessionHasErrors('sms')
        ->assertSessionMissing('success');

    $refusal = (string) session('errors')->first('sms');

    expect($refusal)->toContain('not a mobile number it can text')
        ->and($refusal)->toContain('Twilio error 21211, HTTP 400')
        ->and($refusal)->toContain('left unverified')
        ->and($refusal)->not->toContain($token);

    expect($settings->fresh()->verified_at)->toBeNull()
        ->and(app(SmsSettings::class)->shape()['verified_at'])->toBeNull();

    $entries = smsAuditSince($mark);

    expect(collect($entries)->pluck('action')->all())->toContain('platform.sms_test_refused')
        ->and(json_encode($entries))->toContain('http_400:21211')
        ->and(json_encode($entries))->not->toContain($token);

    expect($response->getContent())->not->toContain($token);
});

it('withdraws an earlier proof when Twilio says the credentials are wrong', function () {
    $settings = smsTwilioRow(smsSecret(), ['verified_at' => now()->subDay()]);

    Http::fake([
        'api.twilio.com/*' => Http::response(['code' => 20003, 'message' => 'Authenticate', 'status' => 401], 401),
    ]);

    $this->actingAs(smsSettingsUser(Role::DIRECTOR))
        ->post('/settings/sms/test', ['to' => '+1 876 555 0147'])
        ->assertSessionHasErrors('sms');

    // A "last proved" date over credentials Twilio has just refused would be a claim the
    // platform knows to be false.
    expect($settings->fresh()->verified_at)->toBeNull()
        ->and((string) session('errors')->first('sms'))->toContain('refused the account SID or the auth token');
});

it('keeps every role but the Director out of the SMS settings', function (string $role) {
    $colleague = smsSettingsUser($role);

    // The Email screen's rule exactly: reading needs `view`, and only the Director holds it.
    $this->actingAs($colleague)->get('/settings/sms')->assertForbidden();

    $this->actingAs($colleague)->post('/settings/sms', [
        'driver' => 'twilio',
        'account_sid' => SMS_SID,
        'token' => smsSecret(),
        'from' => '+18765550100',
    ])->assertForbidden();

    $this->actingAs($colleague)->post('/settings/sms/test', ['to' => '876 555 0147'])->assertForbidden();

    expect(PlatformSmsSetting::query()->count())->toBe(0);
})->with([
    'dispatcher' => [Role::DISPATCHER],
    'operations manager' => [Role::OPERATIONS_MANAGER],
    'head of security' => [Role::HEAD_OF_SECURITY],
    'admin assistant' => [Role::ADMIN_ASSISTANT],
    'accountant' => [Role::ACCOUNTANT],
]);

it('puts the saved provider in front of the configuration file, and the gateway follows it', function () {
    $token = smsSecret();

    config([
        'services.sms.driver' => 'log',
        'services.sms.account_sid' => 'from-the-env-file',
        'services.sms.from' => '+18760000000',
    ]);

    smsTwilioRow($token);

    app(SmsSettings::class)->apply();

    expect(config('services.sms.driver'))->toBe('twilio')
        ->and(config('services.sms.account_sid'))->toBe(SMS_SID)
        ->and(config('services.sms.token'))->toBe($token)
        ->and(config('services.sms.from'))->toBe('+1 876 555 0100')
        ->and(app(SmsSettings::class)->canSend())->toBeTrue()
        ->and(app(SmsGateway::class)->driver())->toBeInstanceOf(TwilioSmsGateway::class);
});

it('re-reads the saved provider each time the gateway is resolved, so a long-running worker follows the console', function () {
    // The worker booted on "None"...
    expect(app(SmsGateway::class)->driver())->toBeInstanceOf(LogSmsGateway::class);

    // ...and the Director connects Twilio while it is still running.
    smsTwilioRow(smsSecret());

    expect(app(SmsGateway::class)->driver())->toBeInstanceOf(TwilioSmsGateway::class);
});

it('leaves the configuration file alone when nothing has been saved', function () {
    config(['services.sms.driver' => 'log', 'services.sms.account_sid' => 'from-the-env-file']);

    app(SmsSettings::class)->apply();

    expect(config('services.sms.driver'))->toBe('log')
        ->and(config('services.sms.account_sid'))->toBe('from-the-env-file')
        ->and(app(SmsSettings::class)->shape()['source'])->toStartWith('Server configuration file');
});

it('answers a refusal or a dead connection from Twilio with a result, never an exception', function () {
    $gateway = new TwilioSmsGateway(SMS_SID, smsSecret(), '+18765550100');

    Http::fake(['api.twilio.com/*' => Http::response(['code' => 21608, 'status' => 400], 400)]);
    $refused = $gateway->send('+18765550147', 'x');

    expect($refused->delivered())->toBeFalse()
        ->and($refused->status)->toBe(SmsResult::FAILED)
        ->and($refused->driver)->toBe('twilio')
        ->and($refused->error)->toBe('http_400:21608');

    // A 2xx that names no message is not proof that this message was taken.
    Http::fake(['api.twilio.com/*' => Http::response(['status' => 'queued'], 201)]);
    expect($gateway->send('+18765550147', 'x')->delivered())->toBeFalse();

    // A provider that never answers is an ordinary outcome on the panic path.
    Http::fake(['api.twilio.com/*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
    $unreachable = $gateway->send('+18765550147', 'x');

    expect($unreachable->delivered())->toBeFalse()
        ->and($unreachable->error)->toBe('unreachable');
});

it('offers sign-in codes by text only when a provider can send, and never through the log anywhere', function () {
    // The log driver on ANY server: the code would reach no phone while the resident
    // was told "sent" (M10 §1). A developer reads a code with mobile:signin-code instead.
    config(['services.sms.driver' => 'log']);
    expect(ResidentSignIn::smsAvailable())->toBeFalse();

    // Production on the log driver: a code in a log file is a credential as well.
    app()->detectEnvironment(fn (): string => 'production');
    expect(ResidentSignIn::smsAvailable())->toBeFalse();

    // Twilio chosen but with no token cannot send — a code sent there would go nowhere.
    $row = PlatformSmsSetting::query()->create(['driver' => 'twilio', 'account_sid' => SMS_SID, 'from' => '+18765550100']);
    app(SmsSettings::class)->apply();
    expect(ResidentSignIn::smsAvailable())->toBeFalse();

    // Connected: available, in production as anywhere.
    $row->forceFill(['token' => smsSecret()])->save();
    expect(ResidentSignIn::smsAvailable())->toBeTrue();

    app()->detectEnvironment(fn (): string => 'testing');
});

it('sits beside Email in the Platform settings tab strip', function () {
    $this->actingAs(smsSettingsUser(Role::DIRECTOR))
        ->get('/settings/sms')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Settings/Sms')
            ->where('tabs.5.label', 'Email')
            ->where('tabs.6.label', 'SMS')
            ->where('tabs.6.href', '/settings/sms')
            ->where('tabs.6.active', true)
            ->where('drivers.0.value', 'log')
            ->where('drivers.1.value', 'twilio')
            ->where('settings.can_send', false)
            ->where('settings.note', fn ($note): bool => is_string($note) && $note !== ''));
});
