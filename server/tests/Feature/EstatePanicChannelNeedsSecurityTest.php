<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| THE ESTATE'S PANIC CHANNEL IS FOR THOSE WHO MAY SEE ITS SECURITY SCREEN
| (bug hunt B044)
|--------------------------------------------------------------------------
|
| `estate.{id}.alerts` carries each panic's lot, the resident's name and the kind.
| The estate console only SUBSCRIBES for the people its `panicWatch` prop names —
| those holding `estate.security.view` — but the channel admitted every account
| assigned to the estate, so a Treasurer or a Secretary (Security: "-") could
| subscribe by hand and read every panic. "A boundary the client enforces is not a
| boundary": the socket now asks the same permission the Security screen does.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    /*
     * A broadcaster that really authorises: the suite's `null` driver admits every
     * subscription, and the channel file is re-run so its callbacks bind to this one.
     */
    config(['broadcasting.connections.channelsecurity' => [
        'driver' => 'pusher',
        'key' => 'channel-security-key',
        'secret' => 'channel-security-secret',
        'app_id' => 'channel-security',
        'options' => ['host' => '127.0.0.1', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false],
    ], 'broadcasting.default' => 'channelsecurity']);

    Broadcast::purge('channelsecurity');

    require base_path('routes/channels.php');
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function channelSecuritySubscribe(TestCase $test, User $user, string $tenantId = FacilitiesFixture::ESTATE): TestResponse
{
    app('auth')->forgetGuards();

    return $test->actingAs($user)->post('/broadcasting/auth', [
        'channel_name' => 'private-estate.'.$tenantId.'.alerts',
        'socket_id' => '1234.5678',
    ]);
}

it('refuses the estate roles that may not see Security, and admits the five that may', function () {
    foreach ([Role::TREASURER, Role::SECRETARY, Role::ESTATE_ADMIN_ASSISTANT] as $name) {
        expect(channelSecuritySubscribe($this, FacilitiesFixture::viewer($name))->status())
            ->toBe(403, $name.' may not see Security, so may not hear its panics');
    }

    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::VICE_PRESIDENT, Role::PROPERTY_MANAGER, Role::ESTATE_HEAD_OF_SECURITY] as $name) {
        expect(channelSecuritySubscribe($this, FacilitiesFixture::viewer($name))->status())
            ->toBe(200, $name.' sees Security and hears its panics');
    }
});

it('still refuses an estate account at an estate it is not assigned to, whatever it holds', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    expect(channelSecuritySubscribe($this, $president, 'someotherestate')->status())->toBe(403);
});

it('refuses an estate account whose assignment has ended, even with Security', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    EstateAssignment::query()->where('user_id', $president->id)->update(['is_active' => false]);

    expect(channelSecuritySubscribe($this, $president->fresh())->status())->toBe(403);
});

it('asks the permission, not the role name, so a custom role with no Security cell is refused', function () {
    // A role this estate made, holding nothing on Security (the only way to build one without it).
    $custom = new Role;
    $custom->forceFill([
        'name' => 'estate.custom.'.FacilitiesFixture::ESTATE.'.gatekeeper-'.bin2hex(random_bytes(3)),
        'guard_name' => 'web',
        'label' => 'Gatekeeper',
        'console' => Console::Estate->value,
        'tenant_id' => FacilitiesFixture::ESTATE,
        'is_system' => false,
        'sort' => 90,
        'scope_default' => 'own_records',
    ])->save();

    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$custom->name]);
    EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'role_id' => $custom->id, 'is_active' => true]);

    expect(channelSecuritySubscribe($this, $user->fresh())->status())->toBe(403);
});
