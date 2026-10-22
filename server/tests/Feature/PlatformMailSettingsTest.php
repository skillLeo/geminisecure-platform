<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\PlatformMailSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\MailSettings;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Where the platform sends mail from
|--------------------------------------------------------------------------
|
| Until 23 September 2026 the platform ran on MAIL_MAILER=log, because no
| relay had been supplied. The consoles survived that. The phones did not: a
| resident signs in with a six-digit code posted to their mailbox, so the
| Resident App could not be signed into by anybody who was not on the VM.
| Guard enrolment, invitations and every password reset were the same.
|
| The relay now lives in the console. These tests pin the three properties
| that make that safe: the password never leaves the server, only the Director
| can reach the screen, and a change is audited by FIELD NAME and never by
| value.
|
*/

/*
 * THIS SUITE DOES NOT REFRESH THE DATABASE between tests — `tests/Pest.php` leaves
 * `RefreshDatabase` commented out and runs against a persistent MySQL schema, because the
 * append-only model rests on real triggers and real grants. So a test that writes the single
 * mail-settings row leaves it there for the next one, and four of these failed on each other
 * the first time they were run together: "nothing has been saved" found a row, and the
 * write-only password test found a from-name another test had set.
 *
 * One row, cleared before each test. Nothing else in the suite touches this table.
 *
 * THE ROLES ARE SEEDED HERE TOO (M10 §1). Every test below acts as a Director or a
 * Dispatcher, and this file only ever found them because some other file had seeded
 * the matrix first. On a freshly built database, or on a seed that runs this file
 * first, seven of its ten tests failed on "No query results for model Role" — a
 * missing fixture reported as a broken settings screen. Seeded on the CELL, the rule
 * `FacilitiesFixture::platform()` gives, so an older matrix is brought up to date.
 *
 * …AND THE ROW IS CLEARED AFTER EACH ONE, BECAUSE THE ROW IS LIVE (M10, baseline). `MailSettings::apply()` puts
 * a saved relay in front of the configuration on EVERY request, which is the point of it —
 * so the `smtp.example.com` row whichever of these ran last left behind was applied to every
 * request of every file that ran after this one. `MAIL_MAILER=array` became SMTP to a host
 * that does not exist, and a resident's sign-in code, sent in `ResidentAccessRevocationTest`,
 * came back as a 500 about DNS. Clearing only on the way in protected these tests from each
 * other and nobody else from them.
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

    PlatformMailSetting::query()->delete();
});

/*
 * AND CLEARED AFTER, because the row is not inert. `AppServiceProvider::boot()` runs
 * `MailSettings::apply()`, which puts a saved relay in front of `.env` — so a row this
 * file left behind turned `mail.default` into `smtp` for whichever file ran next, and
 * `ProductionMailTest`, asserting that production refuses to boot on the `log` mailer,
 * failed on every seed that ran it after this one: the refusal it tests never fired,
 * because a relay "was configured".
 */
afterEach(function () {
    PlatformMailSetting::query()->delete();
});

function director(): User
{
    $role = Role::query()->where('name', Role::DIRECTOR)->firstOrFail();

    $user = User::factory()->create([
        'console' => Console::Gemini->value,
        'status' => 'active',
    ]);

    $user->syncRoles([$role->name]);

    return $user->fresh();
}

function dispatcher(): User
{
    $role = Role::query()->where('name', Role::DISPATCHER)->firstOrFail();

    $user = User::factory()->create([
        'console' => Console::Gemini->value,
        'status' => 'active',
    ]);

    $user->syncRoles([$role->name]);

    return $user->fresh();
}

it('never returns the password to the screen', function () {
    PlatformMailSetting::query()->create([
        'host' => 'smtp.example.com',
        'port' => 465,
        'scheme' => 'smtps',
        'username' => 'postmaster@example.com',
        'password' => 'the-actual-secret',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'GeminiSecure',
    ]);

    $shape = app(MailSettings::class)->shape();

    // The one assertion this whole file exists for.
    expect($shape)->not->toHaveKey('password')
        ->and($shape['password_set'])->toBeTrue()
        ->and(json_encode($shape))->not->toContain('the-actual-secret');
});

it('does not leak the password through the page payload either', function () {
    PlatformMailSetting::query()->create([
        'host' => 'smtp.example.com',
        'port' => 465,
        'scheme' => 'smtps',
        'username' => 'postmaster@example.com',
        'password' => 'the-actual-secret',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'GeminiSecure',
    ]);

    // The shape is one door; the rendered page is the one that ships.
    $body = $this->actingAs(director())->get('/settings/email')->getContent();

    expect($body)->not->toContain('the-actual-secret');
});

it('keeps the stored password when the field is left blank', function () {
    $settings = PlatformMailSetting::query()->create([
        'host' => 'smtp.example.com',
        'port' => 465,
        'scheme' => 'smtps',
        'username' => 'postmaster@example.com',
        'password' => 'the-actual-secret',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'GeminiSecure',
    ]);

    // Correcting the from-name must not require re-typing a secret — which is
    // what makes a write-only field usable rather than an obstacle.
    $this->actingAs(director())->post('/settings/email', [
        'host' => 'smtp.example.com',
        'port' => 465,
        'scheme' => 'smtps',
        'username' => 'postmaster@example.com',
        'password' => '',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'Gemini Security Limited',
    ]);

    expect($settings->fresh()->password)->toBe('the-actual-secret')
        ->and($settings->fresh()->from_name)->toBe('Gemini Security Limited');
});

it('records which fields changed and never their values', function () {
    $this->actingAs(director())->post('/settings/email', [
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'scheme' => 'smtps',
        'username' => 'info@example.com',
        'password' => 'brand-new-secret',
        'from_address' => 'info@example.com',
        'from_name' => 'GeminiSecure',
    ]);

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'platform.mail_settings_changed')
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->after)->toContain('password')      // the field moved...
        ->and($entry->after)->not->toContain('brand-new-secret'); // ...and the value is not there
});

it('clears the verified mark whenever the relay changes', function () {
    $settings = PlatformMailSetting::query()->create([
        'host' => 'smtp.example.com',
        'port' => 465,
        'scheme' => 'smtps',
        'username' => 'postmaster@example.com',
        'password' => 'secret',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'GeminiSecure',
        'verified_at' => now(),
    ]);

    $this->actingAs(director())->post('/settings/email', [
        'host' => 'smtp.elsewhere.com',
        'port' => 587,
        'scheme' => '',
        'username' => 'postmaster@example.com',
        'password' => '',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'GeminiSecure',
    ]);

    // A relay nobody has sent through is a relay nobody knows works.
    expect($settings->fresh()->verified_at)->toBeNull();
});

it('refuses an encryption setting that does not match how the port works', function () {
    // `tls` and `ssl` are the pre-Laravel-11 words. Accepting them here would let
    // somebody save a setting that connects in the clear and then fails a
    // handshake — which surfaces as a timeout naming nothing.
    $this->actingAs(director())
        ->post('/settings/email', [
            'host' => 'smtp.example.com',
            'port' => 465,
            'scheme' => 'tls',
            'username' => 'postmaster@example.com',
            'password' => 'secret',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'GeminiSecure',
        ])
        ->assertSessionHasErrors('scheme');
});

it('keeps every role but the Director out of the mail settings', function () {
    // "No community role, ever" is already covered by RedirectToOwnConsole, which
    // carries an estate account off the Gemini console entirely. This is the
    // narrower claim: a GEMINI colleague who is not the Director cannot reach it.
    $this->actingAs(dispatcher())->get('/settings/email')->assertForbidden();

    $this->actingAs(dispatcher())->post('/settings/email', [
        'host' => 'smtp.attacker.example',
        'port' => 465,
        'scheme' => 'smtps',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'GeminiSecure',
    ])->assertForbidden();
});

it('puts the saved relay in front of the configuration file', function () {
    config(['mail.mailers.smtp.host' => 'from-the-env-file.example']);

    PlatformMailSetting::query()->create([
        'host' => 'saved-in-the-console.example',
        'port' => 465,
        'scheme' => 'smtps',
        'username' => 'postmaster@example.com',
        'password' => 'secret',
        'from_address' => 'no-reply@example.com',
        'from_name' => 'GeminiSecure',
    ]);

    app(MailSettings::class)->apply();

    expect(config('mail.mailers.smtp.host'))->toBe('saved-in-the-console.example')
        ->and(config('mail.default'))->toBe('smtp');
});

it('leaves the configuration file alone when nothing has been saved', function () {
    config(['mail.mailers.smtp.host' => 'from-the-env-file.example', 'mail.default' => 'log']);

    // A server configured the old way must keep working exactly as before, and
    // deleting the row must be a way back rather than a way to no mail at all.
    app(MailSettings::class)->apply();

    expect(config('mail.mailers.smtp.host'))->toBe('from-the-env-file.example')
        ->and(config('mail.default'))->toBe('log');
});

it('says plainly what happened when a test message is sent', function () {
    Mail::fake();

    $this->actingAs(director())
        ->post('/settings/email/test', ['to' => 'somebody@example.com'])
        ->assertSessionHas('success');
});

/*
 * THE CLIENT'S STOPGAP RELAY (M25 §4): a free Gmail account, entered exactly as CLIENT_ACTIONS tells the Director to —
 * smtp.gmail.com, port 587 with the scheme left empty (it upgrades to TLS by itself), the full address as the
 * username, a 16-character app password, and the Gmail address as the from-address. The value below is a stand-in
 * typed for this test; it is nobody's password.
 */
it('takes a Gmail relay entered as the steps say, keeps its password to itself, and proves it with a test message', function () {
    Mail::fake();
    $director = director();

    $this->actingAs($director)->post('/settings/email', [
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'scheme' => '',
        'username' => 'gemisecja@gmail.com',
        'password' => 'abcd efgh ijkl mnop',
        'from_address' => 'gemisecja@gmail.com',
        'from_name' => 'Gemini Security Limited',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $shape = app(MailSettings::class)->shape();
    expect($shape['host'])->toBe('smtp.gmail.com')
        ->and($shape['port'])->toBe(587)
        ->and($shape['scheme'])->toBeEmpty()
        ->and($shape['from_address'])->toBe('gemisecja@gmail.com')
        ->and($shape['password_set'])->toBeTrue()
        ->and(json_encode($shape))->not->toContain('abcd efgh');

    // Port 587 with no scheme is STARTTLS: the connection is upgraded after it opens, as Gmail requires.
    app(MailSettings::class)->apply();
    expect(config('mail.mailers.smtp.host'))->toBe('smtp.gmail.com')
        ->and(config('mail.mailers.smtp.port'))->toBe(587)
        ->and(config('mail.mailers.smtp.scheme'))->toBeEmpty()
        ->and(config('mail.from.address'))->toBe('gemisecja@gmail.com');

    $this->actingAs($director)->post('/settings/email/test', ['to' => 'somebody@example.com'])->assertSessionHas('success');
    expect(PlatformMailSetting::query()->firstOrFail()->verified_at)->not->toBeNull();
});

it('says in Gmail\'s case what the daily limit is, and what an app password is, rather than blaming the from-address', function (string $relaySaid, string $says, string $notSays) {
    $explained = app(MailSettings::class)->explain(new RuntimeException($relaySaid));

    expect($explained)->toContain($says)->not->toContain($notSays)
        // The relay's own words carry the username; the explanation never does.
        ->and($explained)->not->toContain('gemisecja@gmail.com');
})->with([
    'the daily limit' => [
        'Expected response code "250" but got code "550", with message "550-5.4.5 Daily user sending limit exceeded. For more information on Gmail'."\r\n".'550-5.4.5 sending limits go to'."\r\n".'550 5.4.5  https://support.google.com/a/answer/166852 d9443c01a7336-21e0e0e5 - gsmtp".',
        'about 500 emails a day',
        'from-address',
    ],
    'a password that is not an app password' => [
        'Failed to authenticate on SMTP server with username "gemisecja@gmail.com" using the following authenticators: "LOGIN", "PLAIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535-5.7.8 Username and Password not accepted. For more information, go to'."\r\n".'535 5.7.8  https://support.google.com/mail/?p=BadCredentials 5b1f17b1804b1 - gsmtp".',
        'app password',
        'Check both',
    ],
    'an app password required' => [
        'Expected response code "235" but got code "534", with message "534-5.7.9 Application-specific password required. For more information, go to'."\r\n".'534 5.7.9  https://support.google.com/mail/?p=InvalidSecondFactor 98e67ed59e1d1 - gsmtp".',
        '2-Step Verification',
        'different kind of authentication',
    ],
]);

it('keeps its own words for every other relay', function () {
    $mail = app(MailSettings::class);

    expect($mail->explain(new RuntimeException('Expected response code "235" but got code "535", with message "535 Authentication failed".')))
        ->toContain('Check both')
        ->and($mail->explain(new RuntimeException('Expected response code "250" but got code "550", with message "550 Sender address rejected".')))
        ->toContain('from-address');
});
