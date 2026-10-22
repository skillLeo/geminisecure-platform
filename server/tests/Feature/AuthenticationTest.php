<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The second factor is GeminiTwoFactorTest's to prove; this file signs Gemini accounts in by password to test
    // something else, so it asks for none (M11 §0.3, config auth.gemini_two_factor).
    config(['auth.gemini_two_factor' => false]);
    $this->seed(RbacMatrixSeeder::class);
});

it('redirects an anonymous visitor to sign in', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('has no public registration endpoint', function () {
    // Accounts are issued by invitation and never self-created. This asserts
    // the absence deliberately, so adding a registration route breaks a test
    // rather than passing review unnoticed.
    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();
});

it('signs in an active user', function () {
    $user = User::factory()->create([
        'email' => 'director@geminisecurity.test',
        'password' => 'correct-horse',
        'console' => Console::Gemini->value,
        'status' => 'active',
    ]);
    $user->syncRoles([Role::DIRECTOR]);

    $this->post('/login', [
        'email' => 'director@geminisecurity.test',
        'password' => 'correct-horse',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

it('refuses a user whose invitation has not been accepted', function () {
    User::factory()->create([
        'email' => 'pending@geminisecurity.test',
        'password' => 'correct-horse',
        'status' => 'invited',
    ]);

    $this->post('/login', [
        'email' => 'pending@geminisecurity.test',
        'password' => 'correct-horse',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('refuses a suspended user', function () {
    User::factory()->create([
        'email' => 'gone@geminisecurity.test',
        'password' => 'correct-horse',
        'status' => 'suspended',
    ]);

    $this->post('/login', [
        'email' => 'gone@geminisecurity.test',
        'password' => 'correct-horse',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('gives the same message for a wrong password and an unknown address', function () {
    User::factory()->create([
        'email' => 'real@geminisecurity.test',
        'password' => 'correct-horse',
        'status' => 'active',
    ]);

    $wrongPassword = $this->post('/login', [
        'email' => 'real@geminisecurity.test',
        'password' => 'wrong',
    ])->assertSessionHasErrors('email');

    $this->flushSession();

    $unknownAddress = $this->post('/login', [
        'email' => 'nobody@geminisecurity.test',
        'password' => 'wrong',
    ])->assertSessionHasErrors('email');

    // Distinguishing the two would turn the sign-in form into an oracle for
    // which addresses hold accounts on the platform.
    expect(session()->get('errors')?->first('email'))
        ->toBe('Those credentials do not match our records.');
});
