<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Forgot password — ruled (12 §2, Wave 1)
|--------------------------------------------------------------------------
|
| Single-use token, 30-minute expiry, verified address, identical confirmation
| whether or not the account exists. Each clause is a test, and the last is
| the one that matters most: the request page must not be an oracle for which
| addresses hold accounts, when the sign-in form already refuses to be one.
|
*/

beforeEach(function () {
    $this->seed(RbacMatrixSeeder::class);
    $this->withoutVite();
    Notification::fake();
});

function resettable(string $email = 'dispatcher@geminisecurity.test', string $status = 'active'): User
{
    $user = User::factory()->create([
        'email' => $email,
        'password' => 'old-password-1',
        'console' => Console::Gemini->value,
        'status' => $status,
    ]);

    $user->syncRoles([Role::DISPATCHER]);

    return $user;
}

it('says the same sentence for a known address, an unknown one and a suspended account', function () {
    $known = resettable();
    resettable('suspended@geminisecurity.test', 'suspended');

    $sentence = PasswordResetController::SENT;

    $this->post('/forgot-password', ['email' => $known->email])
        ->assertRedirect()
        ->assertSessionHas('status', $sentence);

    $this->post('/forgot-password', ['email' => 'nobody@geminisecurity.test'])
        ->assertRedirect()
        ->assertSessionHas('status', $sentence);

    $this->post('/forgot-password', ['email' => 'suspended@geminisecurity.test'])
        ->assertRedirect()
        ->assertSessionHas('status', $sentence);

    // The words are identical; what differs is invisible from the page — only
    // the active account was actually sent a link.
    Notification::assertSentTo($known, ResetPasswordLink::class);
    Notification::assertCount(1);
});

it('resets the password with the link once, and refuses the same link a second time', function () {
    $user = resettable();

    $this->post('/forgot-password', ['email' => $user->email]);

    $token = null;
    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $mail) use (&$token, $user): bool {
        $token = $mail->token;

        // The link carries the address the token is bound to, on the central
        // door for Gemini staff.
        return str_contains($mail->url($user), '/reset-password/'.$token)
            && str_contains($mail->url($user), 'email='.urlencode($user->email));
    });

    $this->get('/reset-password/'.$token.'?email='.$user->email)->assertOk();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertRedirect('/login');

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse()
        ->and(DB::table('audit_log')->where('action', 'user.password_reset')->where('entity_id', (string) $user->id)->exists())->toBeTrue();

    // Single-use: the token is gone, and the same link is refused.
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'another-password-2',
        'password_confirmation' => 'another-password-2',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();
});

it('refuses a token presented with a different address, and one older than thirty minutes', function () {
    $user = resettable();
    $other = resettable('other@geminisecurity.test');

    $this->post('/forgot-password', ['email' => $user->email]);

    $token = null;
    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    // The verified address: a token is bound to the account it was issued
    // for and cannot be moved to another by editing the form.
    $this->post('/reset-password', [
        'token' => $token,
        'email' => $other->email,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('old-password-1', $other->fresh()->password))->toBeTrue();

    // Thirty minutes: the same token, thirty-one minutes later, is expired.
    $this->travel(31)->minutes();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('old-password-1', $user->fresh()->password))->toBeTrue();
});

it('draws the link on both doors and the request page on the central one', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('door.forgot_password', true)
            ->where('door.forgot_href', '/forgot-password'));

    $this->get('/forgot-password')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ForgotPassword')
            ->where('door.key', 'gemini'));
});
