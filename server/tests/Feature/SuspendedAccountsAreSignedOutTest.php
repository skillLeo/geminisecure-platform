<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Services\Gemini\PlatformAdmins;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A suspended account's session ends on its next request (B068)
|--------------------------------------------------------------------------
|
| `PlatformAdmins::manage($target, $role, 'suspended', ...)` keeps the role and
| only writes `users.status`. Status was read at sign-in and in
| `canAccessEstate()`, and nowhere on the Gemini Console's own routes — every
| `can:gemini.*` gate is answered from the role — so a Director suspended while
| signed in kept Platform settings until the session idled out: they could
| repoint the mail relay, change the SMS credentials, and invite a new Director
| to a mailbox of their own, who would keep access for good.
|
| Every web request now asks, before `auth` does, whether the account behind the
| session is still active, and signs it out when it is not.
|
| THE SESSION IS REAL HERE, NOT `actingAs()`. `actingAs()` hands the guard a user
| object that lives for the whole test, so a suspension saved through another
| copy of the row would never be seen by it — which is not how a browser works.
| These tests sign in through the form, and forget the guard between requests
| the way a new request would, so the account is read back from the database
| each time exactly as it is in production.
|
| WRITTEN AGAINST THE OLD BEHAVIOUR FIRST: the suspended Director's invitation
| was accepted and Platform settings answered 200.
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

/** Sign in through the real form, so the session — and nothing else — carries the account. */
function signInThroughTheForm(User $user): void
{
    // A browser of its own: whoever this test signed in before is not still here.
    test()->flushSession();
    app('auth')->forgetGuards();

    // At the account's OWN door: the central door hands an estate account to its estate rather than making a
    // session on the central host (M11 §5.2), so a committee member signs in where their console is.
    $door = $user->console === Console::Estate ? FacilitiesFixture::url('/login') : '/login';

    test()->post($door, ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();

    app('auth')->forgetGuards();

    test()->assertAuthenticatedAs($user->fresh());

    app('auth')->forgetGuards();
}

/** The next request, as a browser makes it: a fresh guard, which reads the account from the session. */
function nextRequest(): void
{
    app('auth')->forgetGuards();
}

it('ends a suspended Director\'s session before they can use Platform settings again', function () {
    $victim = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $other = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    signInThroughTheForm($victim);

    // Signed in, and holding Platform settings.
    $this->get('/settings')->assertOk();
    nextRequest();

    app(PlatformAdmins::class)->manage($victim->fresh(), Role::DIRECTOR, 'suspended', [], $other);

    // Still holding the role — suspension keeps it — so only status can refuse this.
    expect($victim->fresh()->hasRole(Role::DIRECTOR))->toBeTrue()
        ->and($victim->fresh()->status)->toBe('suspended');

    nextRequest();

    // The dangerous one first: inviting a new Director to a mailbox of their own.
    $this->post('/settings/admins/invitations', ['email' => 'a.new.director.b068@elsewhere.test', 'role' => Role::DIRECTOR])
        ->assertRedirect('/login');

    $this->assertGuest();

    expect(Invitation::query()->where('email', 'a.new.director.b068@elsewhere.test')->exists())->toBeFalse();

    /*
     * And the sign-in page they are sent to says why, in the words the form itself uses.
     *
     * Read off the page rather than with `assertSessionHasErrors()` on the redirect: that
     * assertion re-starts the test's one in-memory session store, which under this app's
     * JSON session serialisation turns the error bag back into an object, and the next
     * request's start then reads an object where it expects the stored array — an empty
     * bag, in tests only. A browser starts a fresh store from the stored JSON every time.
     */
    nextRequest();

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/Login')
            ->where('errors.email', 'This account is suspended.'));

    // Nothing reached afterwards either: the session is gone, not merely refused once.
    nextRequest();

    $this->get('/settings')->assertRedirect('/login');
    $this->get('/settings/email')->assertRedirect('/login');
});

it('answers a suspended account\'s XHR with 401 rather than a sign-in page, and carries on for an active one', function () {
    $victim = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $colleague = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    // The Gemini pop-up's poll: an XHR every console page makes.
    signInThroughTheForm($colleague);
    $this->getJson('/dispatch/panic-watch')->assertOk();
    nextRequest();

    signInThroughTheForm($victim);
    $this->getJson('/dispatch/panic-watch')->assertOk();
    nextRequest();

    $victim->forceFill(['status' => 'suspended'])->save();
    nextRequest();

    $this->getJson('/dispatch/panic-watch')->assertUnauthorized();
    $this->assertGuest();
});

it('signs out a suspended committee member on the estate console too, instead of leaving the session alive behind a 404', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    signInThroughTheForm($president);

    $this->get(FacilitiesFixture::url('/'))->assertOk();
    nextRequest();

    // However the estate suspended them — Users & roles writes this same column.
    $president->forceFill(['status' => 'suspended'])->save();
    nextRequest();

    $this->get(FacilitiesFixture::url('/'))->assertRedirect('/login');
    $this->assertGuest();
});
