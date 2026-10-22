<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\AccountSession;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountPasswordCode;
use App\Rules\NotAnObviousPassword;
use App\Services\Account\AccountSessions;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| MY ACCOUNT — M32 F9
|--------------------------------------------------------------------------
|
| Neither console had a page where a person manages their own account. Now one page, the same in both, changes the
| name, phone and photo; changes the password with the old one AND a code emailed to the account; turns two-step
| verification on (the authenticator, a code typed back, the recovery codes shown once) and off (the password and a
| current code), except for a role that requires it; and lists where the account is signed in, signing one or every
| other out. The email is never changed with the details (M34 §1 gave it two steps of its own, in
| M34SignInEmailTest). Every act is in the audit log, with the address and the browser.
|
*/

const M32_ACCOUNT_DOOR = 'm32accountdoor';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    // The routes' own per-address limits are the doors' tests to prove; this file proves what the controller does.
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** The code the last "Email me a code" sent, read off the faked mail. */
function m32EmailedCode(): string
{
    $code = '';

    Notification::assertSentOnDemand(AccountPasswordCode::class, function (AccountPasswordCode $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    return $code;
}

/** Two-step verification on, with a key the test knows; one recovery code, "AAAA-BBBB". Returns the key. */
function m32TwoStepOn(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey(32);

    $user->forceFill([
        'mfa_secret' => $secret,
        'mfa_enabled' => true,
        'mfa_confirmed_at' => now(),
        'mfa_recovery_codes' => [hash('sha256', 'AAAABBBB')],
        'mfa_last_step' => null,
    ])->save();

    return $secret;
}

/** The code the app shows now — as if the next 30 seconds had passed since any code was last used. */
function m32CodeNow(User $user, string $secret): string
{
    $user->forceFill(['mfa_last_step' => null])->save();

    return app(Google2FA::class)->getCurrentOtp($secret);
}

/** Another place this account is signed in: a session kept where sessions are, and its row on the list. */
function m32AnotherSession(User $user, string $id = 'm32-another-session'): AccountSession
{
    app('session')->driver()->getHandler()->write($id, serialize(['marker' => 'another browser']));

    return AccountSession::query()->create([
        'user_id' => $user->id,
        'session_hash' => AccountSession::hashOf($id),
        'session_key' => $id,
        'console' => $user->console?->value ?? 'gemini',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1',
        'ip_address' => '198.51.100.4',
        'last_seen_at' => now()->subHour(),
    ]);
}

function m32SessionKept(string $id = 'm32-another-session'): bool
{
    return app('session')->driver()->getHandler()->read($id) !== '';
}

/** An estate the doors know — its central row and its domain, no database (as FrontDoorTest's). */
function m32DoorEstate(): User
{
    // The platform's own rows, on its own connection — inside this file's transaction — whatever the default is now.
    DB::connection('mysql')->table('tenants')->insert(['id' => M32_ACCOUNT_DOOR, 'name' => 'M32 Account Estate', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    DB::connection('mysql')->table('domains')->insert(['domain' => M32_ACCOUNT_DOOR, 'tenant_id' => M32_ACCOUNT_DOOR, 'created_at' => now(), 'updated_at' => now()]);

    $role = Role::findOrCreate('test.m32_account_estate', 'web');
    $role->forceFill(['console' => Console::Estate->value])->save();
    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);
    EstateAssignment::updateOrCreate(['user_id' => $user->id, 'tenant_id' => M32_ACCOUNT_DOOR], ['role_id' => $role->id, 'is_active' => true]);

    return $user->fresh();
}

function m32DoorHost(): string
{
    return 'http://'.M32_ACCOUNT_DOOR.'.'.config('app.estate_domain');
}

describe('the page', function () {
    it('is the same page in both consoles, with the person\'s own details and nobody else\'s', function () {
        $gemini = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

        $this->actingAs($gemini)->get('/account')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Account/Show')
            ->where('console', 'gemini')
            ->where('details.email', $gemini->email)
            ->where('details.email_note', 'The address you sign in with. Changing it needs your password and a code we email to the new address.')
            ->where('details.role_note', 'Set by GeminiSecure. Not changeable here.')
            ->where('password.code_to', $gemini->email)
            ->where('twoFactor.codes_total', TwoFactor::RECOVERY_CODES)
            ->where('sessions.0.current', true));

        app('auth')->forgetGuards();
        $president = FacilitiesFixture::viewer(Role::PRESIDENT);

        $this->actingAs($president)->get(FacilitiesFixture::url('/account'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Account/Show')
            ->where('console', 'estate')
            ->where('details.email', $president->email)
            ->where('details.email_note', 'The address you sign in with. Changing it needs your password and a code we email to the new address.')
            ->where('details.role_note', 'Set by your estate. Not changeable here.')
            ->where('twoFactor.on', false)
            ->where('twoFactor.required_because', null));
    });

    it('is reached from the person\'s name in both shells', function () {
        foreach (['resources/js/Layouts/GeminiConsole.vue', 'resources/js/Layouts/EstateConsole.vue'] as $shell) {
            expect((string) file_get_contents(base_path($shell)))->toContain('@click.stop="openAccount">My account</button>');
        }
    });
});

describe('your details', function () {
    it('saves the name and the phone, audited with what they were', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

        $this->actingAs($user)->from('/account')->post('/account/details', ['name' => 'Dana R. Dispatcher', 'phone' => '658 555 0199'])
            ->assertRedirect('/account')->assertSessionHasNoErrors()->assertSessionHas('success', 'Your details are saved.');

        expect($user->fresh()->name)->toBe('Dana R. Dispatcher')->and($user->fresh()->contact_phone)->not->toBeNull();

        $entry = AuditEntry::query()->where('action', 'account.details_changed')->where('entity_id', (string) $user->id)->firstOrFail();
        expect($entry->after['name'])->toBe('Dana R. Dispatcher');
    });

    it('never changes the email with the details, whatever the request carries — one made by hand included', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        $email = $user->email;

        $this->actingAs($user)->from('/account')->post('/account/details', ['name' => 'Somebody Else', 'phone' => '658 555 0199', 'email' => 'taken-over@example.test'])
            ->assertSessionHasErrors(['email' => 'Your email is how you sign in, so it is not changed with your name and phone. Use "Change my sign-in email": it needs your password and a code sent to the new address.']);

        // Refused whole: not the email, and not the name sent with it.
        expect($user->fresh()->email)->toBe($email)->and($user->fresh()->name)->not->toBe('Somebody Else');

        // No other route of this page takes one either.
        $this->actingAs($user)->post('/account/password/code', ['email' => 'taken-over@example.test']);
        $this->actingAs($user)->post('/account/two-factor/start', ['email' => 'taken-over@example.test', 'current_password' => 'password']);
        expect($user->fresh()->email)->toBe($email);
        // The one code sent went to the account's own address, not the one the request named.
        Notification::assertSentOnDemandTimes(AccountPasswordCode::class, 1);
        Notification::assertSentOnDemand(AccountPasswordCode::class, fn ($mail, $channels, $notifiable): bool => $notifiable->routes['mail'] === $email);
    });

    it('takes a photo of up to 5 MB, and says so beyond it', function () {
        Storage::fake('console_photos');
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

        $this->actingAs($user)->from('/account')->post('/account/photo', ['photo' => UploadedFile::fake()->create('big.jpg', 5121, 'image/jpeg')])
            ->assertSessionHasErrors(['photo' => 'That picture is larger than 5 MB. Choose a smaller one.']);
    });
});

describe('changing the password', function () {
    it('refuses a wrong old password, and changes nothing', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        $this->actingAs($user)->post('/account/password/code')->assertSessionHasNoErrors();

        $this->actingAs($user)->from('/account')->post('/account/password', [
            'current_password' => 'not-my-password',
            'code' => m32EmailedCode(),
            'password' => 'a quiet morning in Portmore',
            'password_confirmation' => 'a quiet morning in Portmore',
        ])->assertSessionHasErrors(['current_password' => 'That is not your current password.']);

        expect(Hash::check('password', (string) $user->fresh()->password))->toBeTrue()
            ->and($user->fresh()->password_changed_at)->toBeNull()
            ->and(AuditEntry::query()->where('action', 'account.password_change_refused')->where('entity_id', (string) $user->id)->exists())->toBeTrue();
    });

    it('refuses a change without the emailed code: none typed, none sent, or the wrong one', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        $change = fn (string $code) => $this->actingAs($user)->from('/account')->post('/account/password', [
            'current_password' => 'password',
            'code' => $code,
            'password' => 'a quiet morning in Portmore',
            'password_confirmation' => 'a quiet morning in Portmore',
        ]);

        $change('')->assertSessionHasErrors(['code' => 'Enter the code from the email. Press "Email me a code" if it has not come.']);
        $change('123456')->assertSessionHasErrors(['code' => 'That code has expired, or none was sent. Press "Email me a code" for a new one.']);

        $this->actingAs($user)->post('/account/password/code');
        $wrong = m32EmailedCode() === '000000' ? '000001' : '000000';
        $change($wrong)->assertSessionHasErrors(['code' => 'That code is not right. Check the email and enter the six digits it gives.']);

        expect(Hash::check('password', (string) $user->fresh()->password))->toBeTrue();
    });

    it('signs every other session out and not this one, says so, and the code works once', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        $other = m32AnotherSession($user);

        $this->actingAs($user)->get('/account')->assertOk();
        $this->actingAs($user)->post('/account/password/code');
        $code = m32EmailedCode();

        $this->actingAs($user)->from('/account')->post('/account/password', [
            'current_password' => 'password',
            'code' => $code,
            'password' => 'a quiet morning in Portmore',
            'password_confirmation' => 'a quiet morning in Portmore',
        ])->assertRedirect('/account')->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Your password is changed. You are signed out everywhere else: 1 other place.');

        $user->refresh();
        expect(Hash::check('a quiet morning in Portmore', (string) $user->password))->toBeTrue()
            ->and($user->password_changed_at)->not->toBeNull()
            ->and((int) $user->sign_in_generation)->toBe(1)
            ->and(AccountSession::query()->whereKey($other->id)->exists())->toBeFalse()
            ->and(m32SessionKept())->toBeFalse();

        // This browser is still signed in…
        $this->get('/account')->assertOk();

        // …and a session made before the change, wherever it is kept, ends at its next request.
        $this->withSession([AccountSessions::GENERATION => 0])->get('/account')->assertRedirect(route('login'));
        $this->assertGuest();

        expect(AuditEntry::query()->where('action', 'account.password_changed')->where('entity_id', (string) $user->id)->value('after'))
            ->toBe(['other_sessions_signed_out' => 1]);
    });

    it('will not take the old password again, or one of the obvious', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        $user->forceFill(['password' => 'my own old sentence'])->save();
        $this->actingAs($user)->post('/account/password/code');
        $code = m32EmailedCode();

        $try = fn (string $new) => $this->actingAs($user)->from('/account')->post('/account/password', [
            'current_password' => 'my own old sentence', 'code' => $code, 'password' => $new, 'password_confirmation' => $new,
        ]);

        $try('Password2026!')->assertSessionHasErrors(['password' => NotAnObviousPassword::MESSAGE]);
        $try('1234567890')->assertSessionHasErrors(['password' => NotAnObviousPassword::MESSAGE]);
        $try('short')->assertSessionHasErrors(['password' => 'A password is ten characters at least.']);
        $try('my own old sentence')->assertSessionHasErrors(['password' => 'That is the password you have now. Choose a new one.']);

        expect(Hash::check('my own old sentence', (string) $user->fresh()->password))->toBeTrue();
    });

    it('sends the code to the account\'s own address, one a minute, and five wrong tries retire it', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

        $this->actingAs($user)->post('/account/password/code')->assertSessionHasNoErrors();
        $this->actingAs($user)->post('/account/password/code')->assertSessionHasErrors(['code' => 'A code was sent less than a minute ago. Check your inbox, or ask again in a minute.']);
        Notification::assertSentOnDemandTimes(AccountPasswordCode::class, 1);

        $code = m32EmailedCode();
        $wrong = $code === '000000' ? '000001' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post('/account/password', ['current_password' => 'password', 'code' => $wrong, 'password' => 'a quiet morning in Portmore', 'password_confirmation' => 'a quiet morning in Portmore']);
        }

        $this->actingAs($user)->from('/account')->post('/account/password', ['current_password' => 'password', 'code' => $code, 'password' => 'a quiet morning in Portmore', 'password_confirmation' => 'a quiet morning in Portmore'])
            ->assertSessionHasErrors(['code' => 'That code has expired, or none was sent. Press "Email me a code" for a new one.']);
        expect(Hash::check('password', (string) $user->fresh()->password))->toBeTrue();
    });

    it('holds a reset and an invitation to the same rule against the obvious', function () {
        expect(NotAnObviousPassword::obvious('password'))->toBeTrue()
            ->and(NotAnObviousPassword::obvious('Welcome123!'))->toBeTrue()
            ->and(NotAnObviousPassword::obvious('aaaaaaaaaaaa'))->toBeTrue()
            ->and(NotAnObviousPassword::obvious('qwertyuiop'))->toBeTrue()
            ->and(NotAnObviousPassword::obvious('a quiet morning in Portmore'))->toBeFalse();

        // The person's own name or address, however it is dressed up, and a sentence that only mentions them is not that.
        $user = new User(['name' => 'Dahlia Reid', 'email' => 'dahlia.reid@example.test']);
        expect(NotAnObviousPassword::obvious('DahliaReid2026', $user))->toBeTrue()
            ->and(NotAnObviousPassword::obvious('dahlia2026!', $user))->toBeTrue()
            ->and(NotAnObviousPassword::obvious('dahlia.reid', $user))->toBeTrue()
            ->and(NotAnObviousPassword::obvious('Dahlia keeps the gate at Portmore', $user))->toBeFalse()
            ->and(NotAnObviousPassword::obvious('1234567890'))->toBeTrue();

        // An address of digits alone does not refuse every password of digits.
        expect(NotAnObviousPassword::obvious('8472619305', new User(['name' => 'X', 'email' => '5551234@example.test'])))->toBeFalse();

        foreach (['app/Http/Controllers/Auth/PasswordResetController.php', 'app/Http/Controllers/Auth/InvitationController.php'] as $file) {
            expect((string) file_get_contents(base_path($file)))->toContain('new NotAnObviousPassword');
        }
    });
});

describe('two-step verification', function () {
    it('turns on only through the authenticator, and not until a code is typed back', function () {
        $user = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

        $this->actingAs($user)->from(FacilitiesFixture::url('/account'))->post(FacilitiesFixture::url('/account/two-factor/start'), ['current_password' => 'not-it'])
            ->assertSessionHasErrors(['current_password' => 'That is not your password.']);

        $this->actingAs($user)->post(FacilitiesFixture::url('/account/two-factor/start'), ['current_password' => 'password'])->assertSessionHasNoErrors();

        $this->actingAs($user)->get(FacilitiesFixture::url('/account'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('twoFactor.on', false)
            ->where('twoFactor.setup.mode', 'on')
            ->where('twoFactor.setup.qr', fn (string $qr) => str_starts_with($qr, 'data:image/svg+xml;base64,'))
            ->where('twoFactor.setup.secret', fn (string $secret) => strlen(str_replace(' ', '', $secret)) === 32));

        $secret = (string) session('two_factor.enrolling');

        $this->actingAs($user)->post(FacilitiesFixture::url('/account/two-factor/confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        expect(app(TwoFactor::class)->enrolled($user->fresh()))->toBeFalse();

        $this->actingAs($user)->post(FacilitiesFixture::url('/account/two-factor/confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertSessionHasNoErrors();
        expect(app(TwoFactor::class)->enrolled($user->fresh()))->toBeTrue();
    });

    it('shows the recovery codes in one response, and never in any after it', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        $this->actingAs($user)->post('/account/two-factor/start', ['current_password' => 'password']);
        $this->actingAs($user)->get('/account');
        $this->actingAs($user)->post('/account/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp((string) session('two_factor.enrolling'))]);

        $first = $this->actingAs($user)->get('/account');
        $codes = $first->viewData('page')['props']['twoFactor']['new_codes'];
        expect($codes)->toHaveCount(TwoFactor::RECOVERY_CODES);

        $second = $this->actingAs($user)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('twoFactor.new_codes', null));

        foreach ($codes as $code) {
            $second->assertDontSee($code);
        }

        // And never in the audit log, which counts them.
        $logged = AuditEntry::query()->where('entity_id', (string) $user->id)->get()->map(fn (AuditEntry $e) => json_encode([$e->before, $e->after]))->implode(' ');
        foreach ($codes as $code) {
            expect($logged)->not->toContain($code)->not->toContain(str_replace('-', '', $code));
        }
    });

    it('makes new recovery codes with the password and a current code, and the old ones stop working', function () {
        $user = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
        $secret = m32TwoStepOn($user);

        $this->actingAs($user)->post(FacilitiesFixture::url('/account/two-factor/codes'), ['current_password' => 'password'])->assertSessionHasErrors('code');

        $this->actingAs($user)->post(FacilitiesFixture::url('/account/two-factor/codes'), ['current_password' => 'password', 'code' => m32CodeNow($user, $secret)])
            ->assertSessionHasNoErrors();

        expect(app(TwoFactor::class)->useRecoveryCode($user->fresh(), 'AAAA-BBBB'))->toBeFalse()
            ->and(app(TwoFactor::class)->recoveryCodesLeft($user->fresh()))->toBe(TwoFactor::RECOVERY_CODES);
    });

    it('refuses to turn it off without a current code, and turns it off with one', function () {
        $user = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
        $secret = m32TwoStepOn($user);
        $off = fn (array $with) => $this->actingAs($user)->from(FacilitiesFixture::url('/account'))->post(FacilitiesFixture::url('/account/two-factor/off'), $with);

        $off(['current_password' => 'password'])->assertSessionHasErrors(['code' => 'Enter the code your app shows now.']);
        $off(['current_password' => 'password', 'code' => '000000'])->assertSessionHasErrors('code');
        $off(['current_password' => 'not-it', 'code' => m32CodeNow($user, $secret)])->assertSessionHasErrors(['current_password' => 'That is not your password.']);
        expect(app(TwoFactor::class)->enrolled($user->fresh()))->toBeTrue();

        $off(['current_password' => 'password', 'code' => m32CodeNow($user, $secret)])->assertSessionHasNoErrors();
        expect(app(TwoFactor::class)->enrolled($user->fresh()))->toBeFalse()
            ->and(AuditEntry::query()->where('action', 'user.two_factor_disabled')->where('entity_id', (string) $user->id)->exists())->toBeTrue();
    });

    it('cannot be turned off by a role that requires it — the page says so, and the server refuses it by hand too', function () {
        $refused = function (User $user, string $url, string $because): void {
            $secret = m32TwoStepOn($user);

            $this->actingAs($user)->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('twoFactor.required_because', $because));
            $this->actingAs($user)->from($url)->post($url.'/two-factor/off', ['current_password' => 'password', 'code' => m32CodeNow($user, $secret)])
                ->assertSessionHasErrors(['code' => $because]);

            expect(app(TwoFactor::class)->enrolled($user->fresh()))->toBeTrue()
                ->and(AuditEntry::query()->where('action', 'user.two_factor_disable_refused')->where('entity_id', (string) $user->id)->exists())->toBeTrue();
            app('auth')->forgetGuards();
        };

        $gemini = 'Every Gemini console account uses two-step verification at every sign-in, so it cannot be turned off.';
        $refused(FacilitiesFixture::geminiViewer(Role::DIRECTOR), '/account', $gemini);
        $refused(FacilitiesFixture::geminiViewer(Role::ACCOUNTANT), '/account', $gemini);
        $refused(FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN), FacilitiesFixture::url('/account'), 'Your role, Community Super Admin, requires two-step verification, so it cannot be turned off.');
        $refused(FacilitiesFixture::viewer(Role::TREASURER), FacilitiesFixture::url('/account'), 'Your role, Treasurer, requires two-step verification, so it cannot be turned off.');
    });

    it('replaces the app with the password and a code from the old one; the old one works until the new one is proven', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        $old = m32TwoStepOn($user);

        $this->actingAs($user)->post('/account/two-factor/replace', ['current_password' => 'password', 'code' => m32CodeNow($user, $old)])->assertSessionHasNoErrors();
        $this->actingAs($user)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('twoFactor.setup.mode', 'replace'));
        $new = (string) session('two_factor.enrolling');

        expect($new)->not->toBe($old)->and((string) $user->fresh()->mfa_secret)->toBe($old);

        $this->actingAs($user)->post('/account/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($new)])->assertSessionHasNoErrors();

        expect((string) $user->fresh()->mfa_secret)->toBe($new)
            ->and(AuditEntry::query()->where('action', 'user.two_factor_replaced')->where('entity_id', (string) $user->id)->exists())->toBeTrue();
    });

    it('asks an estate account that turned it on for its code at its own door, and at the front door\'s hand-off', function () {
        $user = m32DoorEstate();
        $secret = m32TwoStepOn($user);

        $this->post(m32DoorHost().'/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(m32DoorHost().'/two-factor');
        $this->assertGuest();

        $this->get(m32DoorHost().'/two-factor')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/TwoFactor')
            ->where('mode', 'challenge')
            ->where('door.key', 'estate')
            ->where('door.foot', 'M32 Account Estate · Powered by Gemini Security Limited'));

        $this->post(m32DoorHost().'/two-factor', ['code' => m32CodeNow($user, $secret)])->assertRedirect(m32DoorHost());
        $this->assertAuthenticatedAs($user->fresh());

        auth()->logout();
        app('auth')->forgetGuards();

        // The front door hands the account to its estate, and the estate asks for the code before any session is made.
        $this->post('/sign-in/email', ['email' => $user->email]);
        $handOff = $this->post('/sign-in', ['email' => $user->email, 'password' => 'password'])->assertStatus(303);
        $this->assertGuest();

        $this->get((string) $handOff->headers->get('Location'))->assertRedirect(m32DoorHost().'/two-factor')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertGuest();
    });
});

describe('where you are signed in', function () {
    it('lists this browser first and the others after it, with the device, the address and when', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        m32AnotherSession($user);

        $this->actingAs($user)->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36')
            ->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('sessions', 2)
            ->where('sessions.0.current', true)
            ->where('sessions.0.device', 'Chrome on Windows')
            ->where('sessions.0.id', null)
            ->where('sessions.1.current', false)
            ->where('sessions.1.device', 'Safari on iPhone or iPad')
            ->where('sessions.1.address', '198.51.100.4')
            ->where('sessions.1.seen', '1 hour ago'));
    });

    it('signs one other session out, and refuses one that is not this account\'s', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        $mine = m32AnotherSession($user);
        $theirs = m32AnotherSession(FacilitiesFixture::geminiViewer(Role::DIRECTOR), 'm32-their-session');

        $this->actingAs($user)->from('/account')->post('/account/sessions/'.$theirs->id.'/sign-out')
            ->assertSessionHas('error', 'That is not one of your other sessions.');
        expect(AccountSession::query()->whereKey($theirs->id)->exists())->toBeTrue()->and(m32SessionKept('m32-their-session'))->toBeTrue();

        $this->actingAs($user)->from('/account')->post('/account/sessions/'.$mine->id.'/sign-out')->assertSessionHas('success', 'That session is signed out.');
        expect(AccountSession::query()->whereKey($mine->id)->exists())->toBeFalse()->and(m32SessionKept())->toBeFalse();
    });

    it('signs out everywhere else — sessions the list cannot see included — and this browser stays', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        m32AnotherSession($user);

        $this->actingAs($user)->get('/account')->assertOk();
        $this->actingAs($user)->from('/account')->post('/account/sessions/sign-out-others')
            ->assertSessionHas('success', 'You are signed out everywhere else: 1 other place. This browser stays signed in.');

        expect((int) $user->fresh()->sign_in_generation)->toBe(1)->and(m32SessionKept())->toBeFalse();
        $this->get('/account')->assertOk();

        $this->withSession([AccountSessions::GENERATION => 0])->get('/account')->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('writes this session to the list on the first request after a real sign-in, and takes it off at sign-out', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        config(['auth.gemini_two_factor' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->get('/account')->assertOk();

        $row = AccountSession::query()->where('user_id', $user->id)->sole();
        expect($row->console)->toBe('gemini');

        $this->post('/logout');
        expect(AccountSession::query()->whereKey($row->id)->exists())->toBeFalse();
    });
});

it('puts every one of these acts in the audit log, with who, the address and the browser', function () {
    Storage::fake('console_photos');
    $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $other = m32AnotherSession($user);
    $as = fn () => $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeader('User-Agent', 'M32 audit browser')->from('/account');

    $as()->post('/account/details', ['name' => 'Dana Audited', 'phone' => '658 555 0198']);
    $as()->post('/account/password/code');
    $as()->post('/account/password', ['current_password' => 'password', 'code' => m32EmailedCode(), 'password' => 'a quiet morning in Portmore', 'password_confirmation' => 'a quiet morning in Portmore']);

    $as()->post('/account/two-factor/start', ['current_password' => 'a quiet morning in Portmore']);
    $as()->get('/account');
    $first = (string) session('two_factor.enrolling');
    $as()->post('/account/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($first)]);
    $as()->post('/account/two-factor/codes', ['current_password' => 'a quiet morning in Portmore', 'code' => m32CodeNow($user, $first)]);
    $as()->post('/account/two-factor/replace', ['current_password' => 'a quiet morning in Portmore', 'code' => m32CodeNow($user, $first)]);
    $as()->get('/account');
    $as()->post('/account/two-factor/confirm', ['code' => m32CodeNow($user, (string) session('two_factor.enrolling'))]);
    $as()->post('/account/two-factor/off', ['current_password' => 'a quiet morning in Portmore', 'code' => '000000']);

    $again = m32AnotherSession($user, 'm32-yet-another');
    $as()->post('/account/sessions/'.$again->id.'/sign-out');
    $as()->post('/account/sessions/sign-out-others');

    $entries = AuditEntry::query()->where('entity_type', 'User')->where('entity_id', (string) $user->id)->get();

    foreach ([
        'account.details_changed', 'account.password_code_sent', 'account.password_changed', 'user.two_factor_enrolled',
        'user.two_factor_recovery_codes_made', 'user.two_factor_replaced', 'user.two_factor_disable_refused',
        'account.session_signed_out', 'account.signed_out_elsewhere',
    ] as $action) {
        $entry = $entries->firstWhere('action', $action);

        expect($entry)->not->toBeNull($action.' is not in the audit log');
        expect((int) $entry->actor_id)->toBe((int) $user->id)
            ->and($entry->ip)->toBe('203.0.113.9')
            ->and($entry->user_agent)->toBe('M32 audit browser');
    }

    expect($other->fresh())->toBeNull();
});
