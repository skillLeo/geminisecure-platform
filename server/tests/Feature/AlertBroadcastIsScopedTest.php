<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Events\AlertRaised;
use App\Models\DuressAlert;
use App\Models\EstateAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| An alert reaches one estate's console and no other
|--------------------------------------------------------------------------
|
| The alert feed is the one place where a single mistake fans a record out to
| every connected client at once. Two things have to hold:
|
|   1. the channel is PRIVATE, so subscription goes through authorization at
|      all. It was briefly a public Channel, which meant the per-estate
|      boundary its own docblock described did not exist — a public channel is
|      subscribable by anyone who can guess its name, and the names are estate
|      subdomains.
|
|   2. the authorization callback admits exactly the right people.
|
| Testing (1) without (2) proves nothing, and vice versa.
|
*/

/*
 * Every row this file makes is rolled back. Its `test.estate_member` role is a
 * shared (no-estate) estate-console role, and committed it was counted by every
 * later file that lists the estate console's shared roles.
 */
beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function alertOn(string $tenantId): DuressAlert
{
    return new DuressAlert([
        'tenant_id' => $tenantId,
        'kind' => 'panic',
        'server_time' => now(),
        'is_simulated' => true,
    ]);
}

/** The authorization callback registered in routes/channels.php. */
function alertChannelCallback(): callable
{
    $channels = app(Factory::class);

    // Ask the broadcaster for the registered callback rather than
    // reimplementing the rule here — a copy would pass while the real one rots.
    $reflection = new ReflectionObject($broadcaster = $channels->connection());
    $property = $reflection->getProperty('channels');
    $property->setAccessible(true);

    /** @var array<string, callable> $registered */
    $registered = $property->getValue($broadcaster);

    return $registered['estate.{tenantId}.alerts']
        ?? throw new RuntimeException('estate.{tenantId}.alerts has no authorization callback registered.');
}

it('broadcasts on a private per-estate channel', function () {
    $event = new AlertRaised(alertOn('phoenixpark'));

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class);

    // The "private-" prefix is what routes the subscription through
    // authorization. Without it the callback below is never consulted.
    expect($channels[0]->name)->toBe('private-estate.phoenixpark.alerts');
});

it('names a different channel for a different estate', function () {
    expect((new AlertRaised(alertOn('phoenixpark')))->broadcastOn()[0]->name)
        ->not->toBe((new AlertRaised(alertOn('oceanview')))->broadcastOn()[0]->name);
});

it('carries no monetary value in the payload', function () {
    $payload = (new AlertRaised(alertOn('phoenixpark')))->broadcastWith();

    // A broadcast is just another way for a figure to escape, and it escapes
    // to every connected client at once.
    $serialised = strtolower((string) json_encode($payload));

    foreach (['balance', 'owed', 'arrear', 'amount', 'minor', 'jmd', 'j$', 'total'] as $forbidden) {
        expect($serialised)->not->toContain($forbidden);
    }
});

it('admits a dispatcher and refuses an accountant', function () {
    $callback = alertChannelCallback();

    // Built here rather than read from seeded data: this test asserts the
    // CALLBACK's rule, and it should fail because the rule changed, never
    // because a seeder did.
    $view = Permission::findOrCreate('gemini.dispatch.view', 'web');
    $watches = Role::findOrCreate('test.watches_dispatch', 'web')->givePermissionTo($view);
    $doesNot = Role::findOrCreate('test.no_dispatch', 'web');

    $dispatcher = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $dispatcher->syncRoles([$watches->name]);

    $accountant = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $accountant->syncRoles([$doesNot->name]);

    // Dispatch is a platform-wide function — one control room watches every
    // client — so it is the PERMISSION that scopes Gemini staff, not an estate
    // assignment. Someone with no operational reason to watch a live alert
    // queue holds no dispatch permission and is refused.
    expect($callback($dispatcher->fresh(), 'phoenixpark'))->toBeTrue()
        ->and($callback($accountant->fresh(), 'phoenixpark'))->toBeFalse();
});

it('admits an estate user only for their own estate', function () {
    $callback = alertChannelCallback();

    /*
     * No provisioned estate needed.
     *
     * canAccessEstate reads the assignments table and the user's status; it
     * never opens the estate's database. Two arbitrary tenant ids are enough
     * to prove separation, and provisioning a real estate here would create a
     * MySQL database and user as a side effect of a broadcasting test.
     *
     * The role may see the estate's Security screen (bug hunt B044): the feed is
     * that screen's, and an estate role without it is refused below. It is an
     * ESTATE role, as a committee member's always is: `findOrCreate()` leaves the
     * console at its default, Gemini's, and an estate account holding a
     * Gemini-console role is the crossed state the isolation probe names (B067).
     */
    $security = Permission::findOrCreate('estate.security.view', 'web');
    $role = Role::findOrCreate('test.estate_security_member', 'web')->givePermissionTo($security);
    $role->forceFill(['console' => Console::Estate->value])->save();

    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    EstateAssignment::updateOrCreate(
        ['user_id' => $user->id, 'tenant_id' => 'mine'],
        ['role_id' => $role->id, 'is_active' => true],
    );

    expect($callback($user->fresh(), 'mine'))->toBeTrue()
        ->and($callback($user->fresh(), 'theirs'))->toBeFalse();
});

it('refuses an estate user their own estate when their role may not see its Security screen', function () {
    $callback = alertChannelCallback();

    // Assigned to the estate and active — a Treasurer, say — but holding nothing on Security.
    $role = Role::findOrCreate('test.estate_member', 'web');
    $role->forceFill(['console' => Console::Estate->value])->save();

    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    EstateAssignment::updateOrCreate(
        ['user_id' => $user->id, 'tenant_id' => 'mine'],
        ['role_id' => $role->id, 'is_active' => true],
    );

    expect($callback($user->fresh(), 'mine'))->toBeFalse();
});

it('refuses a suspended user their own estate', function () {
    $callback = alertChannelCallback();

    // Holding Security, so it is the suspension alone that refuses them. An estate role (B067).
    $role = Role::findOrCreate('test.estate_security_member', 'web')
        ->givePermissionTo(Permission::findOrCreate('estate.security.view', 'web'));
    $role->forceFill(['console' => Console::Estate->value])->save();

    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'suspended']);
    $user->syncRoles([$role->name]);

    EstateAssignment::updateOrCreate(
        ['user_id' => $user->id, 'tenant_id' => 'mine'],
        ['role_id' => $role->id, 'is_active' => true],
    );

    // A suspended account keeps its assignment row. Suspension has to be
    // checked at the point of use, and a live socket is a point of use that
    // outlives the request the suspension happened in.
    expect($callback($user->fresh(), 'mine'))->toBeFalse();
});
