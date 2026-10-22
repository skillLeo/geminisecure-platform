<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PragmaRX\Google2FA\Google2FA;

/*
|--------------------------------------------------------------------------
| THE GEMINI CONSOLE'S SECOND FACTOR (M11 §0.3)
|--------------------------------------------------------------------------
|
| The Gemini door said "Two-factor authentication is required for every sign-in, no exceptions" and nothing
| asked for one. Now a proven password signs nobody in: the account enrols an authenticator at its first
| sign-in and gives its code every time after, a code is never accepted twice, recovery codes work once, and
| the door's words follow whether it is on. Rolled back, as the other door tests are.
|
*/

const TWO_FACTOR_PASSWORD = 'correct-horse-battery-staple';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    // The doors' own per-IP limit is FrontDoorTest's and AuthenticationTest's to prove; this file proves the
    // second factor's per-account limit, which is the controller's and is not middleware.
    $this->withoutMiddleware(ThrottleRequests::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function geminiAccount(): User
{
    $role = Role::findOrCreate('test.two_factor_gemini', 'web');
    $role->forceFill(['console' => Console::Gemini->value])->save();
    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active', 'password' => bcrypt(TWO_FACTOR_PASSWORD)]);
    $user->syncRoles([$role->name]);

    return $user->fresh();
}

function passwordAtTheGeminiDoor($test, User $user)
{
    return $test->post('/login', ['email' => $user->email, 'password' => TWO_FACTOR_PASSWORD]);
}

/** Through the password and the enrolment, as a first sign-in is. Returns the key the account enrolled. */
function enrolled($test, User $user): string
{
    passwordAtTheGeminiDoor($test, $user)->assertRedirect(route('two-factor.show'));
    $test->get('/two-factor')->assertOk();
    $secret = (string) session('two_factor.enrolling');
    $test->post('/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect(route('two-factor.codes'));
    auth()->logout();

    return $secret;
}

/** A code from the step after the last one used — what the app shows next. */
function nextCode(User $user, string $secret): string
{
    return app(Google2FA::class)->oathTotp($secret, ((int) $user->fresh()->mfa_last_step) + 1);
}

describe('a proven password signs nobody in', function () {
    it('sends a Gemini account to its second factor, still a guest', function () {
        $user = geminiAccount();

        passwordAtTheGeminiDoor($this, $user)->assertRedirect(route('two-factor.show'));
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect(route('login'));
    });

    it('asks for it at the front door too', function () {
        $user = geminiAccount();
        $this->post('/sign-in/email', ['email' => $user->email]);

        $this->post('/sign-in', ['email' => $user->email, 'password' => TWO_FACTOR_PASSWORD])->assertRedirect(route('two-factor.show'));
        $this->assertGuest();
    });

    it('asks nothing of an estate account, whose door promises nothing', function () {
        DB::table('tenants')->insert(['id' => 'twofactorestate', 'name' => 'Two Factor Estate', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('domains')->insert(['domain' => 'twofactorestate', 'tenant_id' => 'twofactorestate', 'created_at' => now(), 'updated_at' => now()]);
        $role = Role::findOrCreate('test.two_factor_estate', 'web');
        $role->forceFill(['console' => Console::Estate->value])->save();
        $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active', 'password' => bcrypt(TWO_FACTOR_PASSWORD)]);
        $user->syncRoles([$role->name]);
        EstateAssignment::updateOrCreate(['user_id' => $user->id, 'tenant_id' => 'twofactorestate'], ['role_id' => $role->id, 'is_active' => true]);

        $this->post('http://twofactorestate.'.config('app.estate_domain').'/login', ['email' => $user->email, 'password' => TWO_FACTOR_PASSWORD])
            ->assertRedirectContains('twofactorestate');
        $this->assertAuthenticatedAs($user->fresh());
    });
});

describe('the first sign-in sets the authenticator up', function () {
    it('shows a QR code made here and the key as text, and saves nothing until a code proves it', function () {
        $user = geminiAccount();
        passwordAtTheGeminiDoor($this, $user);

        $this->get('/two-factor')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/TwoFactor')
            ->where('mode', 'enrol')
            ->where('email', $user->email)
            ->where('qr', fn (string $qr) => str_starts_with($qr, 'data:image/svg+xml;base64,'))
            ->where('secret', fn (string $secret) => strlen(str_replace(' ', '', $secret)) === 32));

        expect($user->fresh()->mfa_secret)->toBeNull();
    });

    it('refuses a wrong first code and saves nothing', function () {
        $user = geminiAccount();
        passwordAtTheGeminiDoor($this, $user);
        $this->get('/two-factor');

        $this->post('/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
        expect($user->fresh()->mfa_confirmed_at)->toBeNull();
    });

    it('turns on with the right code, signs in, and shows eight recovery codes once', function () {
        $user = geminiAccount();
        enrolled($this, $user);
        $user->refresh();

        expect($user->mfa_enabled)->toBeTrue()
            ->and($user->mfa_confirmed_at)->not->toBeNull()
            ->and($user->mfa_recovery_codes)->toHaveCount(8);

        // The key is encrypted at rest: what the column holds is not the key.
        $raw = DB::table('users')->where('id', $user->id)->value('mfa_secret');
        expect($raw)->not->toBe($user->mfa_secret)->and(strlen((string) $raw))->toBeGreaterThan(64);

        expect(AuditEntry::query()->where('action', 'user.two_factor_enrolled')->where('entity_id', (string) $user->id)->exists())->toBeTrue();
    });

    it('shows the recovery codes on the page after enrolling, and never again', function () {
        $user = geminiAccount();
        passwordAtTheGeminiDoor($this, $user);
        $this->get('/two-factor');
        $secret = (string) session('two_factor.enrolling');
        $this->post('/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);

        $this->get('/two-factor/recovery-codes')->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'codes')->has('codes', 8));
        $this->get('/two-factor/recovery-codes')->assertRedirect();
    });
});

describe('every sign-in after', function () {
    it('asks for the code, and signs in with the right one', function () {
        $user = geminiAccount();
        $secret = enrolled($this, $user);

        passwordAtTheGeminiDoor($this, $user)->assertRedirect(route('two-factor.show'));
        $this->get('/two-factor')->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'challenge'));

        $this->post('/two-factor', ['code' => nextCode($user, $secret)])->assertRedirect(route('gemini.dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
    });

    it('never accepts the same code twice', function () {
        $user = geminiAccount();
        $secret = enrolled($this, $user);
        $code = nextCode($user, $secret);

        passwordAtTheGeminiDoor($this, $user);
        $this->post('/two-factor', ['code' => $code])->assertRedirect(route('gemini.dashboard'));
        auth()->logout();

        passwordAtTheGeminiDoor($this, $user);
        $this->post('/two-factor', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('lets a recovery code in once, and not twice', function () {
        $user = geminiAccount();
        passwordAtTheGeminiDoor($this, $user);
        $this->get('/two-factor');
        $secret = (string) session('two_factor.enrolling');
        $this->post('/two-factor/confirm', ['code' => app(Google2FA::class)->getCurrentOtp($secret)]);
        $codes = $this->get('/two-factor/recovery-codes')->viewData('page')['props']['codes'];
        auth()->logout();

        passwordAtTheGeminiDoor($this, $user);
        $this->post('/two-factor/recovery', ['recovery_code' => strtolower($codes[0])])->assertRedirect(route('gemini.dashboard'));
        auth()->logout();

        passwordAtTheGeminiDoor($this, $user);
        $this->post('/two-factor/recovery', ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
        expect($user->fresh()->mfa_recovery_codes)->toHaveCount(7);
    });

    it('allows five codes a minute to one account, whatever address they come from', function () {
        $user = geminiAccount();
        enrolled($this, $user);
        passwordAtTheGeminiDoor($this, $user);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/two-factor', ['code' => '000000']);
        }

        $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors(['code' => 'Too many codes. Wait a minute, then enter the code your app shows.']);
    });

    it('forgets a password proven more than ten minutes ago', function () {
        $user = geminiAccount();
        passwordAtTheGeminiDoor($this, $user);

        Carbon::setTestNow(Carbon::now()->addMinutes(11));

        try {
            $this->get('/two-factor')->assertRedirect(route('login'));
            $this->post('/two-factor', ['code' => '123456'])->assertRedirect(route('login'));
            $this->assertGuest();
        } finally {
            Carbon::setTestNow();
        }
    });
});

describe('the door says what the server does', function () {
    it('promises two-factor while it is on', function () {
        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('door.submit_label', 'Continue with 2FA')
            ->where('door.mfa_note', fn (?string $note) => str_contains((string) $note, 'Two-factor authentication is required')));
    });

    it('stops promising it, and stops asking, when it is off', function () {
        config(['auth.gemini_two_factor' => false]);
        $user = geminiAccount();

        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('door.submit_label', 'Log in')->where('door.mfa_note', null));
        passwordAtTheGeminiDoor($this, $user)->assertRedirect(route('gemini.dashboard'));
        $this->assertAuthenticatedAs($user->fresh());
    });
});

it('resets a lost authenticator from the command line, audited, and the account enrols again', function () {
    $user = geminiAccount();
    enrolled($this, $user);

    expect(Artisan::call('accounts:reset-two-factor', ['email' => $user->email]))->toBe(0);
    $user->refresh();

    expect($user->mfa_enabled)->toBeFalse()->and($user->mfa_secret)->toBeNull()->and($user->mfa_recovery_codes)->toBeNull();
    expect(AuditEntry::query()->where('action', 'user.two_factor_reset')->where('entity_id', (string) $user->id)->exists())->toBeTrue();

    passwordAtTheGeminiDoor($this, $user);
    $this->get('/two-factor')->assertInertia(fn (AssertableInertia $page) => $page->where('mode', 'enrol'));
});
