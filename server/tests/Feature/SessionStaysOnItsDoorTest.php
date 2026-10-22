<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Http\Middleware\BindSessionToItsDoor;
use App\Models\Role;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| A SESSION IS GOOD ONLY ON THE DOOR THAT MADE IT (M59)
|--------------------------------------------------------------------------
|
| The M58 browser run copied a Phoenix Park session cookie onto the other estates' hosts, and 55 of 69 pages there knew
| the Super Admin by name and role: nothing leaked, only because every page carries its own membership check. Here,
| for each of the seven estate roles and for a Gemini console account, a session is made by signing in for real at its
| own door, and the same cookie is then presented at the other doors: another estate's, and the Gemini console's.
| Each is refused at the door (nobody signed in, the sign-in page, no console page reached), and the session goes on
| working where it was made. The membership check still stands behind it, and the front door still carries an
| account to its estate.
|
*/

function sessionDoorA(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('sessiondoora', 'SDA', 'Session Door A Estate');
}

function sessionDoorB(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('sessiondoorb', 'SDB', 'Session Door B Estate');
}

beforeEach(function () {
    sessionDoorB()->boot();
    sessionDoorA()->boot();

    DB::connection('mysql')->beginTransaction();

    // The doors' own per-IP limit is AuthenticationTest's and FrontDoorTest's to prove.
    $this->withoutMiddleware(ThrottleRequests::class);

    // The Gemini console's second factor is GeminiTwoFactorTest's; here a Gemini account signs in on its password.
    config(['auth.gemini_two_factor' => false]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    sessionDoorA()->boot();
});

/** A page of the Gemini console, on the root host: never relative, which would resolve to the last host asked. */
function centralDoor(string $path): string
{
    return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
}

/** Signs in for real at a door's own sign-in page; returns the session's id, which is all its cookie carries. */
function signInAtTheDoor(string $loginUrl, User $user): string
{
    test()->post($loginUrl, ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    expect(Auth::guard('web')->id())->toBe($user->id);

    return session()->getId();
}

/** A request carrying that session's cookie, as a copied or stolen cookie would be carried to any host. */
function withTheSessionOf(string $id, string $url): TestResponse
{
    return test()->withCookie(config('session.cookie'), $id)->get($url);
}

/** Refused at the door: sent to this door's own sign-in page, with nobody signed in. */
function refusedAtTheDoor(TestResponse $response, string $loginUrl): void
{
    $response->assertRedirect($loginUrl);

    expect(Auth::guard('web')->check())->toBeFalse();
}

/** Accepted where it was made: whatever page answers, the request is the account's, not a stranger's. */
function stillSignedInAs(TestResponse $response, User $user): void
{
    expect($response->headers->get('Location') ?? '')->not->toEndWith('/login')
        ->and(Auth::guard('web')->id())->toBe($user->id);
}

function sessionDoorGeminiAccount(): User
{
    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([Role::DIRECTOR]);

    return $user->fresh();
}

dataset('the seven estate roles', [
    'Community Super Admin' => [Role::COMMUNITY_SUPER_ADMIN],
    'President' => [Role::PRESIDENT],
    'Vice President' => [Role::VICE_PRESIDENT],
    'Secretary' => [Role::SECRETARY],
    'Treasurer' => [Role::TREASURER],
    'Property Manager' => [Role::PROPERTY_MANAGER],
    'Admin Assistant' => [Role::ESTATE_ADMIN_ASSISTANT],
]);

it('refuses a session made at one estate at another estate\'s door and at the Gemini console\'s, and keeps it at its own', function (string $role) {
    $user = sessionDoorA()->viewer($role);
    $session = signInAtTheDoor(sessionDoorA()->url('login'), $user);

    // Another estate's console, front page and deep pages: the door refuses before any page runs.
    foreach (['/', 'residents', 'finance/arrears', 'governance/meetings', 'facilities/maintenance/1', 'settings/users'] as $path) {
        refusedAtTheDoor(withTheSessionOf($session, sessionDoorB()->url($path)), sessionDoorB()->url('login'));
    }

    // Its sign-in page answers as it does to anybody: nobody is signed in there, and nobody is named.
    withTheSessionOf($session, sessionDoorB()->url('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user', null));
    expect(Auth::guard('web')->check())->toBeFalse();

    // The Gemini console's door refuses it the same way.
    refusedAtTheDoor(withTheSessionOf($session, centralDoor(route('gemini.dashboard', [], false))), centralDoor('login'));

    // And at its own door the same cookie is still this account's: nothing of it was ended or written over.
    stillSignedInAs(withTheSessionOf($session, sessionDoorA()->url('residents')), $user);
})->with('the seven estate roles');

it('refuses a Gemini console session at an estate\'s door, and the account still reaches the estate through its own door', function () {
    $user = sessionDoorGeminiAccount();
    expect($user->canAccessEstate(sessionDoorA()->key))->toBeTrue();

    $console = signInAtTheDoor(centralDoor('login'), $user);

    // Even an account allowed into every estate is refused there on the console's session.
    refusedAtTheDoor(withTheSessionOf($console, sessionDoorA()->url('residents')), sessionDoorA()->url('login'));

    stillSignedInAs(withTheSessionOf($console, centralDoor(route('gemini.dashboard', [], false))), $user);

    // Its legitimate way in is that estate's own door, which makes that estate's own session. A browser there carries
    // none of the console's cookie (it is host-only), so neither does this request; nor does the test runner's memory
    // of who was signed in, which a real request never has.
    $this->defaultCookies = [];
    $this->flushSession();
    app('auth')->forgetGuards();
    $estate = signInAtTheDoor(sessionDoorA()->url('login'), $user);

    expect($estate)->not->toBe($console)
        ->and(session()->get(Auth::guard('web')->getName()))->toBe($user->id);
    stillSignedInAs(withTheSessionOf($estate, sessionDoorA()->url('residents')), $user);
    refusedAtTheDoor(withTheSessionOf($estate, sessionDoorB()->url('residents')), sessionDoorB()->url('login'));
});

it('still carries an estate account from the central door to its estate with the front door\'s single-use token', function () {
    $user = sessionDoorA()->viewer(Role::TREASURER);

    $handOff = test()->post(centralDoor('login'), ['email' => $user->email, 'password' => 'password'])->assertStatus(303);
    $redeem = (string) $handOff->headers->get('Location');
    expect($redeem)->toStartWith(sessionDoorA()->url('front-door/'));

    test()->get($redeem)->assertRedirect();
    $session = session()->getId();

    stillSignedInAs(withTheSessionOf($session, sessionDoorA()->url('residents')), $user);
    refusedAtTheDoor(withTheSessionOf($session, sessionDoorB()->url('residents')), sessionDoorB()->url('login'));
});

it('keeps the membership check as the second lock: a session made at an estate\'s own door still cannot open it for a non-member', function () {
    $user = sessionDoorA()->viewer(Role::PRESIDENT);

    // A session estate B's own door made, then holding A's President as if some other fault had signed them in there:
    // the door lets it through, and the estate's own check refuses it as it always has.
    test()->get(sessionDoorB()->url('login'))->assertOk();
    $session = session()->getId();
    session()->put(Auth::guard('web')->getName(), $user->id);
    session()->save();

    withTheSessionOf($session, sessionDoorB()->url('residents'))->assertNotFound();
});

/** Signed in at estate A before the rule existed: a session that cannot say which door made it. Returns its id. */
function signedInBeforeTheRule(User $user): string
{
    $session = signInAtTheDoor(sessionDoorA()->url('login'), $user);

    session()->forget(BindSessionToItsDoor::DOOR);
    session()->save();

    return $session;
}

describe('somebody already signed in when the deploy lands (Hassam: "signed out cleanly, or an error?")', function () {
    it('sends their next page to the sign-in page, which says to sign in again, and opens that page once they have', function () {
        $user = sessionDoorA()->viewer(Role::SECRETARY);
        $session = signedInBeforeTheRule($user);

        refusedAtTheDoor(withTheSessionOf($session, sessionDoorA()->url('residents')), sessionDoorA()->url('login'));
        $fresh = session()->getId();

        withTheSessionOf($fresh, sessionDoorA()->url('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('errors.email', BindSessionToItsDoor::SIGN_IN_AGAIN)
                ->where('auth.user', null));

        test()->withCookie(config('session.cookie'), $fresh)
            ->post(sessionDoorA()->url('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(sessionDoorA()->url('residents'));
        expect(Auth::guard('web')->id())->toBe($user->id);
    });

    it('sends a form sent at that moment to the sign-in page, never to the session-expired error, and back to the form after', function () {
        $user = sessionDoorA()->viewer(Role::SECRETARY);
        $session = signedInBeforeTheRule($user);
        $form = sessionDoorA()->url('account');

        test()->withCookie(config('session.cookie'), $session)
            ->withHeader('Referer', $form)
            ->post(sessionDoorA()->url('account/details'), ['name' => 'Kept out'])
            ->assertStatus(303)
            ->assertRedirect(sessionDoorA()->url('login'));

        expect(Auth::guard('web')->check())->toBeFalse()
            ->and(session('url.intended'))->toBe($form)
            ->and($user->fresh()->name)->not->toBe('Kept out');
    });

    it('answers a request in the background at that moment with 401 and the same sentence', function () {
        $session = signedInBeforeTheRule(sessionDoorA()->viewer(Role::SECRETARY));

        test()->withCookie(config('session.cookie'), $session)
            ->postJson(sessionDoorA()->url('live/auth'), ['socket_id' => '1234.5678', 'channel_names' => []])
            ->assertStatus(401)
            ->assertJson(['message' => BindSessionToItsDoor::SIGN_IN_AGAIN]);
    });
});
