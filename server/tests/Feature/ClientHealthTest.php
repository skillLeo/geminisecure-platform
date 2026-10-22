<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\ClientAdoption;
use App\Models\EstateAssignment;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gemini\ClientHealth;
use App\Services\Platform\AdoptionRollup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Client health, and the roll-up that lets it exist
|--------------------------------------------------------------------------
|
| Board super-admin-07 is the report that tested this console's hardest rule: a
| cross-tenant report reads gs_platform and never opens an estate database.
| Adoption is recorded inside each estate, so the report waited until the owner
| of each fact could roll it up centrally. Four things are worth asserting:
|
|   1. NOTHING TO MEASURE IS NOT NOUGHT PER CENT. A client with no posts yet has
|      not failed at anything, and a bar at zero would put them at the bottom of
|      a health list for having done nothing wrong.
|
|   2. NOT REPORTED IS NOT ZERO EITHER. A capability nobody has counted has no
|      score, and the chip says so rather than guessing.
|
|   3. THE ROLL-UP COUNTS COVERAGE, NOT HEADCOUNT. A post worked by an
|      unlicensed guard, or by one with no bound handset, is not the Guard App
|      being used — it is a gate the client is paying for and not getting.
|
|   4. SCOPE NARROWS IN SQL, as everywhere else in this console.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console and dropping the schema mid-run would take other
| tests with it. Each test runs inside a transaction and leaves nothing behind.
|
*/

uses(DatabaseTransactions::class);

/**
 * An estate row, written straight to the table.
 *
 * Not Tenant::create(): creating a tenant through the model fires stancl's
 * provisioning, which builds a database and a MySQL user per estate. These
 * tests need a row to join against, not an estate.
 */
function healthEstate(string $name, string $status = 'active'): Tenant
{
    $id = 'health'.random_int(100000, 999999);

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Tenant::find($id);
}

function healthPost(Tenant $estate, string $name, string $type = 'gate'): Post
{
    return Post::create([
        'tenant_id' => $estate->getTenantKey(),
        'name' => $name,
        'type' => $type,
        'is_active' => true,
    ]);
}

/** A guard, optionally unlicensed or carrying no bound handset. */
function healthGuard(Post $post, string $name, int $licenceDays = 365, bool $bound = true): Guard
{
    $suffix = random_int(100000, 999999);

    return Guard::create([
        'full_name' => $name,
        'employee_number' => 'GS-H'.$suffix,
        'psra_number' => 'PSRA-H'.$suffix,
        'psra_expires_on' => now()->addDays($licenceDays)->toDateString(),
        'employment_type' => 'full_time',
        'status' => $licenceDays < 0 ? 'licence_expired' : 'active',
        'tenant_id' => $post->tenant_id,
        'post_id' => $post->id,
        'device_id' => $bound ? 'DEV-H'.$suffix : null,
        'device_label' => $bound ? 'Company Pixel 7a' : null,
    ]);
}

function healthShift(Post $post, Guard $guard, int $daysAgo = 0): Shift
{
    return Shift::create([
        'tenant_id' => $post->tenant_id,
        'guard_id' => $guard->id,
        'post_id' => $post->id,
        'rostered_start' => now()->subDays($daysAgo)->setTime(7, 0),
        'rostered_end' => now()->subDays($daysAgo)->setTime(19, 0),
        'actual_start' => now()->subDays($daysAgo)->setTime(7, 2),
        'geofence_distance_m' => 12,
        'status' => 'completed',
    ]);
}

function healthAdmission(Post $post, Guard $guard, ?string $basis, int $minutesAgo = 5): GateEvent
{
    return GateEvent::create([
        'tenant_id' => $post->tenant_id,
        'guard_id' => $guard->id,
        'guard_name' => $guard->full_name,
        'post_id' => $post->id,
        'post_name' => $post->name,
        'verdict' => GateEvent::ADMIT,
        'subject' => 'Visitor admitted',
        'basis' => $basis,
        'occurred_at' => now()->subMinutes($minutesAgo),
        'is_simulated' => true,
    ]);
}

/** A Gemini user whose single role carries the given scope. */
function healthViewer(AccessScope $scope, ?string $assignedTo = null): User
{
    $role = Role::findOrCreate('test.health_'.$scope->value.random_int(100000, 999999), 'web');
    $role->forceFill(['console' => Console::Gemini->value, 'scope_default' => $scope->value])->save();

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    if ($assignedTo !== null) {
        EstateAssignment::create([
            'user_id' => $user->id,
            'tenant_id' => $assignedTo,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    return $user->fresh();
}

beforeEach(function () {
    $this->health = new ClientHealth;
    $this->rollup = new AdoptionRollup;
    $this->estate = healthEstate('Health Test Estate');
});

it('counts a post as covered only when a licensed guard on a bound handset worked it', function () {
    $covered = healthPost($this->estate, 'Main Gate');
    healthShift($covered, healthGuard($covered, 'Marcus Whyte'), daysAgo: 1);

    // Worked, but by a guard whose licence has lapsed. Not coverage.
    $unlicensed = healthPost($this->estate, 'Service Gate');
    healthShift($unlicensed, healthGuard($unlicensed, 'Devon Palmer', licenceDays: -14), daysAgo: 1);

    // Worked, but nobody signed on from a bound handset. Not the Guard App.
    $unbound = healthPost($this->estate, 'Rear Gate');
    healthShift($unbound, healthGuard($unbound, 'Marlon Bailey', bound: false), daysAgo: 1);

    // Nobody has worked it at all.
    healthPost($this->estate, 'Beach Gate');

    $this->rollup->refreshEstate($this->estate);

    $row = ClientAdoption::query()
        ->where('tenant_id', $this->estate->getTenantKey())
        ->where('module_key', 'guard_app')
        ->first();

    expect($row->adopted)->toBe(1)
        ->and($row->eligible)->toBe(4)
        ->and($row->percentage())->toBe(25)
        ->and($row->reported_by)->toBe(ClientAdoption::BY_GEMINI)

        // The hover has to say what the two integers count, or the bar is a
        // number an account manager cannot defend in a renewal conversation.
        ->and($row->detail)->toContain('1 of 4 active posts');
});

it('does not count a shift worked outside the window', function () {
    $post = healthPost($this->estate, 'Main Gate');
    healthShift($post, healthGuard($post, 'Marcus Whyte'), daysAgo: 30);

    $this->rollup->refreshEstate($this->estate);

    expect(ClientAdoption::query()
        ->where('tenant_id', $this->estate->getTenantKey())
        ->where('module_key', 'guard_app')
        ->value('adopted'))->toBe(0);
});

it('measures pass take-up against admissions rather than against every event', function () {
    $post = healthPost($this->estate, 'Main Gate');
    $guard = healthGuard($post, 'Marcus Whyte');

    healthAdmission($post, $guard, 'QR pass · Lot 47');
    healthAdmission($post, $guard, 'pre-approved');
    healthAdmission($post, $guard, 'resident confirmed by phone');

    // A denial is not an admission and belongs in neither half of the ratio.
    GateEvent::create([
        'tenant_id' => $this->estate->getTenantKey(),
        'guard_id' => $guard->id,
        'guard_name' => $guard->full_name,
        'post_id' => $post->id,
        'post_name' => $post->name,
        'verdict' => GateEvent::DENY,
        'subject' => 'Visitor denied',
        'basis' => 'no pass on file',
        'occurred_at' => now()->subMinutes(9),
        'is_simulated' => true,
    ]);

    $this->rollup->refreshEstate($this->estate);

    $row = ClientAdoption::query()
        ->where('tenant_id', $this->estate->getTenantKey())
        ->where('module_key', 'visitor_passes')
        ->first();

    expect($row->adopted)->toBe(2)
        ->and($row->eligible)->toBe(3)
        ->and($row->percentage())->toBe(67);
});

it('treats a client with nothing to take up as unmeasured rather than as nought', function () {
    // No posts, no gate traffic. Nothing has gone wrong here.
    $this->rollup->refreshEstate($this->estate);

    $card = collect($this->health->forViewer(healthViewer(AccessScope::All))['clients'])
        ->firstWhere('id', $this->estate->getTenantKey());

    expect($card['bars'])->toHaveCount(0)
        ->and($card['score'])->toBeNull()
        ->and($card['unmeasured'])->toHaveCount(2)
        ->and($card['unmeasured'][0]['reason'])->toContain('Nothing to measure yet')
        ->and($card['score_title'])->toContain('no score to give');
});

it('says a client is onboarding rather than failing when nothing is reported', function () {
    $onboarding = healthEstate('Brand New Estate', status: 'onboarding');

    $card = collect($this->health->forViewer(healthViewer(AccessScope::All))['clients'])
        ->firstWhere('id', $onboarding->getTenantKey());

    // No roll-up row at all — not even a zero one.
    expect($card['bars'])->toHaveCount(0)
        ->and($card['unmeasured'])->toHaveCount(0)
        ->and($card['score_label'])->toBe('Onboarding');
});

it('scores a client on the mean of what was measured, and says how many that is', function () {
    $post = healthPost($this->estate, 'Main Gate');
    $guard = healthGuard($post, 'Marcus Whyte');
    healthShift($post, $guard, daysAgo: 1);
    healthAdmission($post, $guard, 'QR pass · Lot 47');

    $this->rollup->refreshEstate($this->estate);

    $card = collect($this->health->forViewer(healthViewer(AccessScope::All))['clients'])
        ->firstWhere('id', $this->estate->getTenantKey());

    // One post covered of one, and one admission on a pass of one.
    expect($card['score'])->toBe(100)
        ->and($card['score_class'])->toBe('high')
        ->and($card['score_label'])->toBe('Healthy')

        // A chip reading "Healthy" off two measurements must not be mistaken
        // for one reading it off the client's whole platform.
        ->and($card['score_title'])->toContain('2 measured capabilities');
});

it('calls a client at risk when what is measured is mostly unused', function () {
    foreach (['Main Gate', 'Service Gate', 'Rear Gate', 'Beach Gate'] as $name) {
        healthPost($this->estate, $name);
    }

    $post = Post::query()->where('tenant_id', $this->estate->getTenantKey())->first();
    $guard = healthGuard($post, 'Marcus Whyte');
    healthShift($post, $guard, daysAgo: 1);
    healthAdmission($post, $guard, 'no pass on file');

    $this->rollup->refreshEstate($this->estate);

    $card = collect($this->health->forViewer(healthViewer(AccessScope::All))['clients'])
        ->firstWhere('id', $this->estate->getTenantKey());

    // 25% coverage and no pass take-up at all.
    expect($card['score'])->toBe(13)
        ->and($card['score_class'])->toBe('low')
        ->and($card['score_label'])->toBe('At risk');
});

it('keeps a scoped viewer out of a client they are not assigned to', function () {
    $theirs = healthEstate('Unassigned Estate');

    $names = collect($this->health->forViewer(healthViewer(AccessScope::AssignedSites, $this->estate->getTenantKey()))['clients'])
        ->pluck('name')
        ->all();

    expect($names)->toContain('Health Test Estate')
        ->and($names)->not->toContain('Unassigned Estate');
});

it('carries no money, household or resident out of the report', function () {
    $post = healthPost($this->estate, 'Main Gate');
    healthShift($post, healthGuard($post, 'Marcus Whyte'), daysAgo: 1);

    $this->rollup->refreshEstate($this->estate);

    /*
     * Scoped to this test's own estate. The shared test database also holds the
     * adoption fixture's estate, whose governance roll-up reads "318 of 450
     * eligible households voted" — a count, rightly shown — and scanning every
     * client made this test's result depend on which files ran before it.
     */
    $serialised = strtolower((string) json_encode($this->health->forViewer(healthViewer(AccessScope::AssignedSites, $this->estate->getTenantKey()))));

    // The roll-up holds two integers and a label per capability. It cannot
    // become a way to read an estate's ledger from outside it, and this is the
    // assertion that says so.
    foreach (['balance', 'arrear', 'owed', 'amount', 'minor', 'jmd', 'household', 'resident'] as $forbidden) {
        expect($serialised)->not->toContain($forbidden);
    }
});
