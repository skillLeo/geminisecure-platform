<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\ConsoleHome;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Signing in lands you on YOUR console
|--------------------------------------------------------------------------
|
| The two consoles are not on the same URL. Sending an estate committee member
| to /dashboard lands them on the Gemini dashboard, where the permission gate
| correctly refuses them — a 403 that reads as a broken login and is in fact
| the gate working.
|
| That fault was fixed once, in the local quick-login bypass, and left in place
| on the real sign-in form where it mattered far more: EVERY estate user
| signing in through the actual login page hit it. Two copies of the same rule
| is how that happened, so ConsoleHome is now the only copy and these tests
| pin it.
|
*/

/*
 * EVERY ROW THIS FILE MAKES IS ROLLED BACK. Its roles are shared ones (no
 * estate), and a committed `test.estate_person` or `test.estate_orphan` was
 * counted by every later file that lists the estate console's shared roles —
 * EstateSettingsTest found eleven where there are eight, under some shuffled
 * orders only. The sign-in form is posted in the same process and connection,
 * so it sees the rows the transaction holds.
 */
beforeEach(function () {
    DB::connection('mysql')->beginTransaction();

    /*
     * The estate these accounts belong to, as a real row. An assignment to an estate that does not exist
     * is an account that holds no estate, and since the front door (M11 §5.2) hands a member to their
     * estate rather than redirecting them there, it asks — so the estate must be there to be found.
     */
    DB::table('tenants')->insert(['id' => 'harbourview', 'name' => 'Harbour View', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('domains')->insert(['domain' => 'harbourview', 'tenant_id' => 'harbourview', 'created_at' => now(), 'updated_at' => now()]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/**
 * A role of THIS console, as every real one is. `findOrCreate()` alone leaves
 * the column at its default, which is Gemini's: an estate account holding a
 * Gemini-console role is the crossed state the isolation probe names (bug hunt
 * B067), and it would name these.
 */
function roleOnConsole(Console $console, string $roleName): Role
{
    $role = Role::findOrCreate($roleName, 'web');
    $role->forceFill(['console' => $console->value])->save();

    return $role;
}

function userOnConsole(Console $console, string $roleName): User
{
    $role = roleOnConsole($console, $roleName);

    $user = User::factory()->create([
        'console' => $console->value,
        'status' => 'active',
        'password' => bcrypt('correct-horse-battery-staple'),
    ]);

    $user->syncRoles([$role->name]);

    if ($console === Console::Estate) {
        EstateAssignment::updateOrCreate(
            ['user_id' => $user->id, 'tenant_id' => 'harbourview'],
            ['role_id' => $role->id, 'is_active' => true],
        );
    }

    return $user->fresh();
}

it('sends a Gemini user to the Gemini dashboard', function () {
    $user = userOnConsole(Console::Gemini, 'test.gemini_person');

    expect(ConsoleHome::for($user))->toBe(route('gemini.dashboard'));
});

it('sends an estate user to their own estate, not the Gemini dashboard', function () {
    $user = userOnConsole(Console::Estate, 'test.estate_person');

    $home = ConsoleHome::for($user);

    expect($home)->toContain('harbourview')
        ->and($home)->not->toContain('dashboard');
});

it('sends an estate user with no estate back to sign-in with a reason', function () {
    $role = roleOnConsole(Console::Estate, 'test.estate_orphan');
    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    // No assignment. Saying so beats bouncing them into a console they hold
    // no assignment for and letting the gate refuse them without explanation.
    expect(ConsoleHome::for($user->fresh()))->toContain('reason=no-estate');
});

it('redirects an estate user to their estate through the real sign-in form', function () {
    $user = userOnConsole(Console::Estate, 'test.estate_person');

    // The end-to-end version. The unit assertions above can pass while the
    // controller still hardcodes gemini.dashboard, which is exactly what
    // happened.
    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirectContains('harbourview');
});

/*
|--------------------------------------------------------------------------
| ...and being on the WRONG console is never a dead end
|--------------------------------------------------------------------------
|
| Everything above proves where a sign-in SENDS you. None of it proves what
| happens when you are already on the wrong console, and on 23 September 2026
| that gap cost a client demonstration.
|
| The two consoles are on different hostnames and the session cookie is
| host-only, so the redirect above lands a committee member on their estate as
| a stranger. They are bounced to a sign-in page, and they go back to the only
| address they were given — the central one — where they are still signed in.
| Every Gemini page then answered 403 "Not available to your role". Seven
| accounts, each individually correct: active, roled, permissioned, assigned.
| Nothing in the matrix was broken; the journey was.
|
| So: an estate account on a Gemini route is CARRIED, never refused.
|
*/

it('carries an estate user off the Gemini dashboard instead of refusing them', function () {
    $user = userOnConsole(Console::Estate, 'test.estate_person');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirectContains('harbourview');
});

it('carries an estate user off the central root instead of refusing them', function () {
    $user = userOnConsole(Console::Estate, 'test.estate_person');

    $this->actingAs($user)
        ->get('/')
        ->assertRedirectContains('harbourview');
});

it('never answers 403 to an estate account anywhere on the Gemini console', function () {
    $user = userOnConsole(Console::Estate, 'test.estate_person');

    // The whole surface, not one page. A 403 on any of these is the fault
    // returning by another door.
    foreach (['/', '/dashboard', '/clients', '/guards', '/reports', '/settings'] as $path) {
        $response = $this->actingAs($user)->get($path);

        expect($response->status())
            ->not->toBe(403, "{$path} refused an estate account instead of carrying it home");
    }
});

it('leaves a Gemini user alone', function () {
    $user = userOnConsole(Console::Gemini, 'test.gemini_person');

    /*
     * NOT `assertOk()`. This invented role holds no permissions, so the dashboard's own gate
     * refuses it — correctly, and that refusal is the matrix working. What this test is about
     * is narrower: the middleware must not touch the console it is not about. A Gemini account
     * is never carried anywhere, whatever the gate then decides.
     */
    $response = $this->actingAs($user)->get('/dashboard');

    // `?? ''` because no redirect at all is the commonest pass here, and
    // `toContain` on null is an error rather than a passing assertion.
    expect($response->headers->get('Location') ?? '')->not->toContain('harbourview');
});

it('signs out an estate account that holds no estate rather than looping it', function () {
    $role = roleOnConsole(Console::Estate, 'test.estate_orphan');
    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    // ConsoleHome answers the sign-in page for this case, and redirecting an
    // authenticated user to a `guest` route is a loop, not a destination.
    $this->actingAs($user->fresh())
        ->get('/dashboard')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('leaves logout reachable for an estate account', function () {
    $user = userOnConsole(Console::Estate, 'test.estate_person');

    // Registered above the Gemini group deliberately. If it were inside it, an
    // estate account that landed here would have no way out.
    $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});
