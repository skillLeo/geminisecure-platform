<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The sign-in limit is per ACCOUNT as well as per address (B070)
|--------------------------------------------------------------------------
|
| The `login` limiter had two keys, `ip` and `email|ip`. Both contain the
| address, so on any one address the second is never stricter than the first,
| and nothing limited the account itself: N source addresses gave 5N password
| guesses a minute at one Director's known address — the account the console
| calls "data for every client estate". The docblocks said the second key was
| there to stop exactly that.
|
| The second key is now the account alone: the typed address as the database
| will match it — `users.email` is compared under utf8mb4_unicode_ci, which folds
| case, width and accents, so `ｊáne@x.com` signs in to `jane@x.com` and must
| share its bucket — or, where the form asks a signed-in person for their
| password again, who they are. A form that names no account (an invitation's
| token is its credential) is limited per address only, rather than every such
| form on the platform sharing one bucket.
|
| WRITTEN AGAINST THE OLD BEHAVIOUR FIRST: the sixth guess from a sixth address
| was answered 302, not 429.
|
*/

beforeEach(function () {
    // The second factor is GeminiTwoFactorTest's to prove; this file signs Gemini accounts in by password to test
    // something else, so it asks for none (M11 §0.3, config auth.gemini_two_factor).
    config(['auth.gemini_two_factor' => false]);
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** One sign-in attempt, as a browser at this address would make it. */
function signInFrom(string $ip, string $email, string $password = 'not-the-password'): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', ['email' => $email, 'password' => $password]);
}

it('stops a guess at one account from its sixth address inside a minute', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    foreach (range(1, 5) as $n) {
        signInFrom('198.51.100.'.$n, (string) $director->email)->assertSessionHasErrors('email');
    }

    signInFrom('198.51.100.6', (string) $director->email)->assertStatus(429);

    // However the address is typed: case and surrounding spaces name the same account.
    signInFrom('198.51.100.7', '  '.strtoupper((string) $director->email).' ')->assertStatus(429);

    // And so do the spellings `users.email`'s collation folds together, which lower-casing does not.
    signInFrom('198.51.100.8', fullWidthOf((string) $director->email))->assertStatus(429);
    signInFrom('198.51.100.9', accentedOf((string) $director->email))->assertStatus(429);
});

/** The address with its local part in full-width letters: `ｄｉｒｅｃｔｏｒ@…`. */
function fullWidthOf(string $email): string
{
    [$local, $domain] = explode('@', $email, 2);

    return (string) preg_replace_callback('/[A-Za-z]/', static fn (array $m): string => (string) mb_chr(ord($m[0]) + 0xFEE0), $local).'@'.$domain;
}

/** The address with every vowel of its local part accented: `dírèctör@…`. */
function accentedOf(string $email): string
{
    [$local, $domain] = explode('@', $email, 2);

    return strtr($local, ['a' => 'á', 'e' => 'è', 'i' => 'í', 'o' => 'ö', 'u' => 'ú']).'@'.$domain;
}

it('treats every spelling the database signs in as one account, not one bucket each', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    /*
     * Proof that these ARE the account, not merely look like it: the full-width
     * spelling, with the right password, signs the Director in. `users.email` is
     * compared under utf8mb4_unicode_ci, which ignores width and accents as well as
     * case, and the `email` rule accepts both spellings.
     */
    signInFrom('192.0.2.201', fullWidthOf((string) $director->email), 'password')->assertSessionHasNoErrors()->assertRedirect();
    $this->assertAuthenticatedAs($director->fresh());

    app('auth')->guard('web')->logout();

    // Four wrong guesses, each spelled differently and from an address of its own …
    signInFrom('192.0.2.202', accentedOf((string) $director->email))->assertSessionHasErrors('email');
    signInFrom('192.0.2.203', strtoupper((string) $director->email))->assertSessionHasErrors('email');
    signInFrom('192.0.2.204', fullWidthOf(strtoupper((string) $director->email)))->assertSessionHasErrors('email');
    signInFrom('192.0.2.205', (string) $director->email)->assertSessionHasErrors('email');

    // … and the account's five are spent, however the sixth is spelled.
    signInFrom('192.0.2.206', mb_strtoupper(accentedOf((string) $director->email)))->assertStatus(429);
    signInFrom('192.0.2.207', (string) $director->email, 'password')->assertStatus(429);
});

it('keeps every other account signing in while one is being guessed at', function () {
    $target = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $colleague = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    foreach (range(1, 6) as $n) {
        signInFrom('203.0.113.'.$n, (string) $target->email);
    }

    signInFrom('203.0.113.50', (string) $colleague->email, 'password')
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($colleague->fresh());
});

it('counts the reset-password door against the same account', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    foreach (range(1, 5) as $n) {
        signInFrom('192.0.2.'.$n, (string) $director->email);
    }

    // The forgot-password form is the same oracle if it leaks, so it shares the bucket.
    app('auth')->forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
        ->post('/forgot-password', ['email' => (string) $director->email])
        ->assertStatus(429);
});

it('limits a signed-in supervisor re-typing their password per person, from however many addresses', function () {
    $supervisor = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    foreach (range(1, 5) as $n) {
        app('auth')->forgetGuards();
        $this->actingAs($supervisor)
            ->withServerVariables(['REMOTE_ADDR' => '100.64.0.'.$n])
            ->post('/account/override-pin', ['current_password' => 'wrong', 'pin' => '482915', 'pin_confirmation' => '482915'])
            ->assertSessionHasErrors('current_password');
    }

    app('auth')->forgetGuards();
    $this->actingAs($supervisor)
        ->withServerVariables(['REMOTE_ADDR' => '100.64.0.6'])
        ->post('/account/override-pin', ['current_password' => 'wrong', 'pin' => '482915', 'pin_confirmation' => '482915'])
        ->assertStatus(429);
});

it('does not pool every form that names no account into one bucket', function () {
    /*
     * An invitation's form carries a token and a password and no address. Were the
     * account key built from an absent address, every invitation on the platform
     * would share one five-a-minute bucket, and one person could stop everybody else
     * accepting theirs. Six different addresses each get their own answer.
     */
    foreach (range(1, 6) as $n) {
        app('auth')->forgetGuards();
        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.16.0.'.$n])
            ->post('/invitations/'.str_repeat('a', 63).$n, ['name' => 'Nobody', 'password' => 'a-long-password-here', 'password_confirmation' => 'a-long-password-here']);

        expect($response->status())->not->toBe(429);
    }
});
