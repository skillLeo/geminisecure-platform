<?php

declare(strict_types=1);

use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Gemini\BillingOverview;
use App\Services\Gemini\ClientDirectory;
use App\Services\Gemini\ClientHealth;
use App\Services\Gemini\PlatformNotifications;
use App\Services\Gemini\PlatformOverview;
use App\Services\Gemini\RevenueReports;
use App\Services\Platform\OperatorEstates;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The demonstration estate is not a client — M5 §3
|--------------------------------------------------------------------------
|
| `tenants.demo` marks the estate an App Store or Play reviewer signs in to.
| Tenant isolation already held for it: `ReviewerModeTest` walks a real estate's
| own row ids with a reviewer's token and proves each one is refused. That is
| about what the REVIEWER can see. This file is about the other direction —
| what the COMPANY sees — which had no rule at all.
|
| THE ONE THAT COSTS SOMETHING. `visibleEstateIds()` returned every tenant, and
| alerts are broadcast on `estate.{id}.alerts` with no global channel, so every
| open console subscribed to the demonstration estate. A reviewer pressing the
| panic button in the Guard App raised a duress alert that landed in the live
| control-room queue, unflagged, and a dispatcher would have responded to it. A
| fake panic costs a real person's attention and can cost a real response.
|
| THE REST COST CREDIBILITY AND TIME. It was counted as an active client, summed
| into revenue and churn, listed on the fill-rate report, chased for a payment
| method it will never have, and published by name on the unauthenticated
| account-deletion page that real customers read.
|
| EVERY TEST BELOW WAS RUN AGAINST THE OLD BEHAVIOUR FIRST, by making
| `OperatorEstates::exclude()` a no-op, and every one of them failed. That is
| the point of the file: it is not a description of what the code does, it is a
| fence around what it must not go back to doing.
|
| No RefreshDatabase: this suite shares gs_platform_test with the rest of the
| console. The fixture's central half is committed once per process; everything
| this file writes happens inside a transaction that is rolled back.
|
*/

beforeEach(function () {
    // Commits the permission matrix and one real estate. Outside the
    // transaction below, because every other console suite needs it too.
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $suffix = random_int(100000, 999999);

    $this->demoId = 'demoop'.$suffix;
    $this->realId = 'realop'.$suffix;

    /*
     * Written straight to the table, not through `Tenant::create()`: creating a
     * tenant through the model fires stancl's provisioning, which builds a
     * database and a MySQL user per estate. Nothing here opens an estate
     * database — dispatch, billing and the platform totals are all central by
     * design — so a row to join against is all that is needed.
     */
    foreach ([[$this->demoId, 'Seaview Demo Estate (demonstration data)', 1], [$this->realId, 'Harbour View Estate', 0]] as [$id, $name, $demo]) {
        DB::connection('mysql')->table('tenants')->insert([
            'id' => $id,
            'name' => $name,
            'status' => 'active',
            'demo' => $demo,
            'parish' => 'St. Ann',
            'provisioned_at' => now()->subMonths(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // THE PANIC. Identical in every respect but the estate it was raised in,
    // which is the whole question this file asks.
    $this->demoAlert = DuressAlert::create([
        'tenant_id' => $this->demoId,
        'kind' => 'panic',
        'raised_by_name' => 'Review Resident (demonstration account)',
        'unit_reference' => 'Lot 1',
        'status' => 'open',
        'server_time' => now()->subMinutes(2),
        'is_simulated' => false,
    ]);

    $this->realAlert = DuressAlert::create([
        'tenant_id' => $this->realId,
        'kind' => 'panic',
        'raised_by_name' => 'Andrea Fletcher',
        'unit_reference' => 'Lot 12',
        'status' => 'open',
        'server_time' => now()->subMinutes(3),
        'is_simulated' => false,
    ]);

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** A guard on a post at one of the two estates. */
function opsGuard(string $tenantId, string $name, int $licenceDays = 365): Guard
{
    $suffix = random_int(100000, 999999);

    $post = Post::create([
        'tenant_id' => $tenantId,
        'name' => 'Gate '.$suffix,
        'type' => 'gate',
        'is_active' => true,
    ]);

    return Guard::create([
        'full_name' => $name,
        'employee_number' => 'GS-OP'.$suffix,
        'psra_number' => 'PSRA-OP'.$suffix,
        'psra_expires_on' => now()->addDays($licenceDays)->toDateString(),
        'employment_type' => 'full_time',
        'status' => $licenceDays < 0 ? 'active' : 'active',
        'tenant_id' => $tenantId,
        'post_id' => $post->id,
    ]);
}

/* -----------------------------------------------------------------
 | 1. The dispatch queue — the one with an operational cost
 |------------------------------------------------------------------ */

it('keeps a demonstration panic out of the live dispatch queue', function () {
    $this->actingAs($this->director)
        ->get('/dispatch/alerts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Dispatch/Alerts')

            // The real one is there. A filter that hid everything would pass a
            // test that only asserted the absence, and would have taken the
            // control room down instead of cleaning it up.
            ->where('alerts', fn ($alerts) => collect($alerts)->contains('key', 'alert-'.$this->realAlert->id))

            ->where('alerts', fn ($alerts) => ! collect($alerts)->contains('key', 'alert-'.$this->demoAlert->id)));
});

it('keeps a demonstration panic out of every tab of the queue, not just the default one', function () {
    // Resolved, so the tab that looks BACK has to exclude it as well: a rule
    // applied to the open queue alone leaves the estate one query string away.
    $this->demoAlert->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

    foreach (['all', 'panic', 'incidents', 'resolved'] as $tab) {
        $this->actingAs($this->director)
            ->get('/dispatch/alerts?tab='.$tab)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('alerts', fn ($alerts) => ! collect($alerts)->contains('key', 'alert-'.$this->demoAlert->id)));
    }
});

it('never subscribes a console to a demonstration estate\'s private alert channel', function () {
    /*
     * THE PUSH, not only the poll.
     *
     * `AlertRaised` broadcasts on `estate.{id}.alerts` and on no global
     * channel, so the estates in this prop are exactly the estates whose panic
     * alerts can cross the wire to this browser. Asserted on all four live
     * screens because all four subscribe, and a rule applied to one of them
     * leaves the other three pushing.
     *
     * THE OLD EXPRESSION IS ASSERTED ALONGSIDE, on purpose. `visibleEstateIds()`
     * used to be exactly `Tenant::query()->orderBy('id')->pluck('id')`, and
     * running it here proves the demonstration estate really is a tenant that
     * really would be in this prop — so the absence below is the rule doing
     * something, not a row that happens not to exist. Without this line the
     * test would go on passing if the fixture ever stopped creating the estate.
     */
    $everyTenant = Tenant::query()->orderBy('id')->pluck('id')->all();

    expect($everyTenant)->toContain($this->demoId)
        ->and($everyTenant)->toContain($this->realId);

    foreach ([
        '/dispatch/alerts',
        '/dispatch/map',
        '/dispatch/coverage',
        '/dispatch/alertness',
    ] as $screen) {
        $this->actingAs($this->director)
            ->get($screen)
            ->assertOk()
            /*
             * `collect($ids)`, never `(array) $ids`: AssertableInertia wraps an
             * array prop in a Collection before handing it to the closure, and
             * casting a Collection with `(array)` yields its object properties
             * rather than its items — an assertion that is quietly false for
             * every value.
             */
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('estateIds', fn ($ids) => collect($ids)->contains($this->realId))
                ->where('estateIds', fn ($ids) => ! collect($ids)->contains($this->demoId)));
    }
});

it('refuses a console a subscription to a demonstration estate\'s alert channel even when it asks for one by name', function () {
    /*
     * THE BOUNDARY, not the filter.
     *
     * The screens above no longer ASK for this channel. This proves they would
     * be refused if they did — a screen built next year that assembles its own
     * list, or a browser left open across a deploy. The dispatch permission is
     * what admits a Gemini dispatcher to every other estate's feed, and it is
     * held here by a viewer who has it.
     *
     * THE DRIVER HAS TO BE SWAPPED FOR THIS TO MEAN ANYTHING. `phpunit.xml`
     * sets `BROADCAST_CONNECTION=null`, and `NullBroadcaster::auth()` is an
     * empty method — it authorises everything and refuses nothing, so against
     * it this test would pass whatever `routes/channels.php` said. So the
     * default is pointed at the pusher protocol the platform actually runs
     * (Reverb speaks it), with invented credentials because `socket_auth()` is
     * pure HMAC and never leaves the process. The channel file is re-run
     * because `Broadcast::channel()` binds its callback to whichever driver was
     * current when it executed, and that was the null one at boot.
     */
    config(['broadcasting.connections.demotest' => [
        'driver' => 'pusher',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'app_id' => 'test-app',
        'options' => ['host' => '127.0.0.1', 'port' => 6001, 'scheme' => 'http', 'useTLS' => false],
    ], 'broadcasting.default' => 'demotest']);

    Broadcast::purge('demotest');

    require base_path('routes/channels.php');

    $this->actingAs($this->director)
        ->post('/broadcasting/auth', ['channel_name' => 'private-estate.'.$this->realId.'.alerts', 'socket_id' => '1234.5678'])
        ->assertOk();

    $this->actingAs($this->director)
        ->post('/broadcasting/auth', ['channel_name' => 'private-estate.'.$this->demoId.'.alerts', 'socket_id' => '1234.5678'])
        ->assertForbidden();
});

it('still opens a demonstration alert by id, and says on the screen that it is one', function () {
    /*
     * The other half of the rule. Somebody at Gemini has to be able to check
     * the demonstration estate works before a reviewer finds out it does not,
     * so the response screen is still reachable — and it says, in the subtitle
     * above the invented figures, which kind of estate this is.
     */
    $this->actingAs($this->director)
        ->get('/dispatch/alerts/'.$this->demoAlert->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Dispatch/Alert')
            ->where('alert.subtitle', fn ($subtitle) => str_contains((string) $subtitle, OperatorEstates::LABEL)));

    // And a real alert is never labelled.
    $this->actingAs($this->director)
        ->get('/dispatch/alerts/'.$this->realAlert->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('alert.subtitle', fn ($subtitle) => ! str_contains((string) $subtitle, OperatorEstates::LABEL)));
});

it('does not raise a demonstration panic in the console\'s notification bell', function () {
    $items = app(PlatformNotifications::class)->attention($this->director)['items'];
    $keys = array_column($items, 'key');

    expect($keys)->toContain('alert:'.$this->realAlert->id.':open')
        ->and($keys)->not->toContain('alert:'.$this->demoAlert->id.':open');
});

/* -----------------------------------------------------------------
 | 2. The reporting surfaces
 |------------------------------------------------------------------ */

it('does not count a demonstration estate as an active client or its officers as guards deployed', function () {
    opsGuard($this->demoId, 'Review Officer (demonstration account)');
    opsGuard($this->realId, 'Marcus Whyte');

    // As the Director: every site, so the only thing between the demonstration estate and the
    // cards is the flag.
    $before = collect(app(PlatformOverview::class)->kpis($this->director, true))->keyBy('key');

    // Take the flag off and the same two figures both move by one. That is the
    // flag doing the work rather than the estate happening to be invisible.
    DB::connection('mysql')->table('tenants')->where('id', $this->demoId)->update(['demo' => false]);

    $after = collect(app(PlatformOverview::class)->kpis($this->director, true))->keyBy('key');

    expect((int) $after['clients']['value'])->toBe((int) $before['clients']['value'] + 1)
        ->and((int) $after['guards']['value'])->toBe((int) $before['guards']['value'] + 1);
});

it('does not name a demonstration estate on the revenue or churn reports', function () {
    $reports = app(RevenueReports::class);

    $contributors = array_column($reports->revenueByTier($this->director)['clients'], 'client');
    $tenures = array_column($reports->churn($this->director)['tenures'] ?? [], 'client');

    $demoName = (string) Tenant::query()->findOrFail($this->demoId)->name;
    $realName = (string) Tenant::query()->findOrFail($this->realId)->name;

    expect($contributors)->toContain($realName)
        ->and($contributors)->not->toContain($demoName)
        ->and($tenures)->not->toContain($demoName);
});

it('does not put a demonstration estate on the list of clients to chase for a payment method', function () {
    $chasing = array_column(app(BillingOverview::class)->paymentMethods(), 'id');

    expect($chasing)->toContain($this->realId)
        ->and($chasing)->not->toContain($this->demoId);
});

it('leaves a demonstration estate off the client health report', function () {
    $clients = app(ClientHealth::class)->forViewer($this->director)['clients'];
    $ids = array_column($clients, 'id');

    expect($ids)->toContain($this->realId)
        ->and($ids)->not->toContain($this->demoId);
});

/* -----------------------------------------------------------------
 | 3. Reachable on purpose, and labelled when it is
 |------------------------------------------------------------------ */

it('hides a demonstration estate from the client directory until somebody names it, and labels it when they do', function () {
    $directory = app(ClientDirectory::class);

    // The search and the chips; the order and the page are the directory's own (M32 F5).
    $criteria = [
        'q' => '',
        'status' => [],
    ];

    $default = $directory->directory($this->director, $criteria);

    expect(array_column($default['clients'], 'id'))->not->toContain($this->demoId)
        ->and(array_column($default['clients'], 'id'))->toContain($this->realId);

    // Searched for by id — a deliberate act — it is there, and it says what it is.
    $named = $directory->directory($this->director, [...$criteria, 'q' => $this->demoId]);
    $row = collect($named['clients'])->firstWhere('id', $this->demoId);

    expect($row)->not->toBeNull()
        ->and($row['sub'])->toContain(OperatorEstates::LABEL)

        // The count and the rows agree. A total computed from a different query
        // than the list is how a directory comes to say "13 clients" over
        // twelve rows.
        ->and($named['total'])->toBe(count($named['clients']));
});

it('opens a demonstration estate\'s own client record, labelled', function () {
    $detail = app(ClientDirectory::class)->detail($this->demoId);

    expect($detail)->not->toBe([])
        ->and($detail['subtitle'])->toContain(OperatorEstates::LABEL);

    expect(app(ClientDirectory::class)->detail($this->realId)['subtitle'])
        ->not->toContain(OperatorEstates::LABEL);
});

/* -----------------------------------------------------------------
 | 4. The public web
 |------------------------------------------------------------------ */

it('offers no estate at all on the public account-deletion page, demonstration or real', function () {
    $response = $this->get('/account/delete')->assertOk();

    $html = $response->getContent();

    // M24 (D-223): the list was the client's customer list on the open web. The person types their community.
    expect($html)->not->toContain('Harbour View Estate')
        ->and($html)->not->toContain('Seaview Demo Estate');
});

/* -----------------------------------------------------------------
 | 5. The rule itself
 |------------------------------------------------------------------ */

it('keeps a row with no estate at all, which is not the same as a demonstration row', function () {
    /*
     * SQL's NOT IN is three-valued: `NULL NOT IN (…)` is NULL, which is not
     * true, so a bare NOT IN would silently delete every unassigned guard from
     * the platform's own headcount. `guards.tenant_id` is nullable because an
     * officer taken on and not yet posted is a real state the workforce screens
     * spend rows on.
     */
    $unassigned = Guard::create([
        'full_name' => 'Newly Hired, Not Yet Posted',
        'employee_number' => 'GS-OPU'.random_int(100000, 999999),
        'psra_number' => 'PSRA-OPU'.random_int(100000, 999999),
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => null,
    ]);

    $query = Guard::query()->whereKey($unassigned->id);
    OperatorEstates::exclude($query, 'guards.tenant_id');

    expect($query->exists())->toBeTrue();
});

it('reads the flag from the column every time, so a flag set after the process started still counts', function () {
    // The exclusion is a subquery, never a cached list of ids: `demo:seed`
    // writes the column through the query builder, so anything memoised in PHP
    // would be right until the moment it mattered.
    expect(OperatorEstates::ids())->toContain($this->realId)
        ->and(OperatorEstates::ids())->not->toContain($this->demoId);

    DB::connection('mysql')->table('tenants')->where('id', $this->demoId)->update(['demo' => false]);

    expect(OperatorEstates::ids())->toContain($this->demoId);
});
