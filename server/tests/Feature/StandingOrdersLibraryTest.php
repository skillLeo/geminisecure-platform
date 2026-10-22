<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Gemini\SecurityOperations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The standing orders library, and the incident log
|--------------------------------------------------------------------------
|
| Boards super-admin-25 and super-admin-27. Three things worth asserting:
|
|   1. AN UNACKNOWLEDGED SET IS COMPUTED, NEVER STORED. A set is unacknowledged
|      because no acknowledgement exists for its CURRENT version, and it becomes
|      unacknowledged again the moment the version is bumped. A stored flag has
|      to be cleared on every revision, and the revision nobody cleared it on is
|      the one that matters.
|
|   2. "UNASSIGNED POST" IS NOT "NOT YET READ". An order set in force at a gate
|      nobody can legally stand is a different problem from one a guard has not
|      got round to signing, and an expired licence is what separates them.
|
|   3. A MASTER TEMPLATE SURVIVES SCOPING. It belongs to no estate, so a Head of
|      Security scoped to assigned sites keeps it — hiding the company's own
|      general orders from the person enforcing them would be the wrong kind of
|      correct.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console and dropping the schema mid-run would take other
| tests with it. Each test runs inside a transaction and leaves nothing behind.
|
*/

uses(DatabaseTransactions::class);

/** An estate row, written straight to the table — see CrossClientRosterTest. */
function ordersEstate(string $name): string
{
    $id = 'orders'.random_int(100000, 999999);

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function ordersGuard(Post $post, string $name, int $licenceDays = 365): Guard
{
    $suffix = random_int(100000, 999999);

    return Guard::create([
        'full_name' => $name,
        'employee_number' => 'GS-O'.$suffix,
        'psra_number' => 'PSRA-O'.$suffix,
        'psra_expires_on' => now()->addDays($licenceDays)->toDateString(),
        'employment_type' => 'full_time',
        'status' => $licenceDays < 0 ? 'licence_expired' : 'active',
        'tenant_id' => $post->tenant_id,
        'post_id' => $post->id,
    ]);
}

/**
 * One order set, and its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function orderSet(array $overrides = []): int
{
    // The override wins, and the lookup below has to use the SAME title the
    // insert used — reading back the generated one would find nothing.
    $title = (string) ($overrides['title'] ?? 'Test Orders '.random_int(100000, 999999));

    DB::connection('mysql')->table('standing_order_sets')->insert([
        'title' => $title,
        'category' => 'post_specific',
        'summary' => 'Post-specific orders',
        'tenant_id' => null,
        'post_id' => null,
        'version' => 1,
        'body' => 'Keep the barrier down between vehicles.',
        'effective_on' => now()->subMonths(2)->toDateString(),
        'reviewed_on' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
        'title' => $title,
    ]);

    return (int) DB::connection('mysql')->table('standing_order_sets')->where('title', $title)->value('id');
}

function acknowledge(int $setId, Guard $guard, int $version): void
{
    DB::connection('mysql')->table('standing_order_acknowledgements')->insert([
        'standing_order_set_id' => $setId,
        'guard_id' => $guard->id,
        'version' => $version,
        'acknowledged_at' => now()->subDays(3),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** A Gemini user whose single role carries the given scope. */
function ordersViewer(AccessScope $scope, ?string $assignedTo = null): User
{
    $role = Role::findOrCreate('test.orders_'.$scope->value.random_int(100000, 999999), 'web');
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
    $this->operations = new SecurityOperations;
    $this->estate = ordersEstate('Orders Test Estate');
    $this->post = Post::create([
        'tenant_id' => $this->estate,
        'name' => 'Main Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);
});

it('names the guard who acknowledged a post set, and marks it active', function () {
    $guard = ordersGuard($this->post, 'Marcus Whyte');
    $id = orderSet(['tenant_id' => $this->estate, 'post_id' => $this->post->id, 'version' => 3]);
    acknowledge($id, $guard, 3);

    $set = collect($this->operations->standingOrders(ordersViewer(AccessScope::All))['sets'])
        ->firstWhere('id', $id);

    expect($set['name'])->toBe('Orders Test Estate — Main Gate')
        ->and($set['badge'])->toBe('Active')
        ->and($set['variant'])->toBe('active')
        ->and($set['icon'])->toBe('shield')
        ->and($set['meta'])->toContain('Version 3')
        ->and($set['meta'])->toContain('Acknowledged by Marcus Whyte');
});

it('stops treating a set as acknowledged when its version is bumped', function () {
    $guard = ordersGuard($this->post, 'Marcus Whyte');
    $id = orderSet(['tenant_id' => $this->estate, 'post_id' => $this->post->id, 'version' => 4]);

    // Signed for version 3. The orders have since been revised.
    acknowledge($id, $guard, 3);

    $set = collect($this->operations->standingOrders(ordersViewer(AccessScope::All))['sets'])
        ->firstWhere('id', $id);

    // A guard working to orders they have not read. A stored flag would still
    // say Active here, because nobody cleared it on the revision.
    expect($set['meta'])->toContain('Not yet acknowledged by the guard on post')
        ->and($set['variant'])->toBe('active');
});

it('separates a gate nobody can stand from a guard who has not signed', function () {
    // Posted to the gate, licence lapsed, therefore un-rosterable.
    ordersGuard($this->post, 'Devon Palmer', licenceDays: -14);

    $id = orderSet(['tenant_id' => $this->estate, 'post_id' => $this->post->id, 'version' => 2]);

    $set = collect($this->operations->standingOrders(ordersViewer(AccessScope::All))['sets'])
        ->firstWhere('id', $id);

    expect($set['badge'])->toBe('Unassigned post')
        ->and($set['variant'])->toBe('unassigned')
        ->and($set['meta'])->toContain('No guard currently assigned to acknowledge');
});

it('keeps the company general orders in view for a scoped role', function () {
    $general = orderSet([
        'title' => 'Company-wide General Orders '.random_int(100000, 999999),
        'category' => 'general',
        'summary' => 'Applied to every post at every client',
        'version' => 4,
        'reviewed_on' => '2026-08-01',
    ]);

    $emergency = orderSet([
        'title' => 'Emergency Procedures '.random_int(100000, 999999),
        'category' => 'emergency',
        'summary' => 'Fire, medical, duress protocols',
        'version' => 2,
        'reviewed_on' => '2026-06-15',
    ]);

    $theirs = ordersEstate('Unassigned Estate');
    $theirPost = Post::create([
        'tenant_id' => $theirs,
        'name' => 'Main Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);
    $theirSet = orderSet(['tenant_id' => $theirs, 'post_id' => $theirPost->id]);

    $sets = collect($this->operations->standingOrders(ordersViewer(AccessScope::AssignedSites, $this->estate))['sets']);
    $ids = $sets->pluck('id')->all();

    expect($ids)->toContain($general)
        ->and($ids)->toContain($emergency)
        ->and($ids)->not->toContain($theirSet);

    // Three glyphs, three meanings: a ledger for the general orders, a speaker
    // for the protocols that get broadcast, a shield for one gate's own set.
    expect($sets->firstWhere('id', $general)['icon'])->toBe('ledger')
        ->and($sets->firstWhere('id', $general)['badge'])->toBe('Master template')
        ->and($sets->firstWhere('id', $general)['meta'])->toContain('Reviewed Aug 1, 2026')
        ->and($sets->firstWhere('id', $emergency)['icon'])->toBe('broadcast');
});

it('leaves duress alerts out of the incident log and counts them instead', function () {
    SecurityIncident::create([
        'tenant_id' => $this->estate,
        'guard_name' => 'Devon Palmer',
        'kind' => 'Compliance — licence expiry',
        'severity' => 'med',
        'status' => SecurityIncident::OPEN,
        'occurred_at' => now()->subDays(11),
    ]);

    $log = $this->operations->incidents(ordersViewer(AccessScope::AssignedSites, $this->estate));

    expect($log['incidents'])->toHaveCount(1)
        ->and($log['incidents'][0]['severity'])->toBe('med')
        ->and($log['incidents'][0]['severity_label'])->toBe('Medium')
        ->and($log['incidents'][0]['status'])->toBe('open')
        // In the vocabulary's words (M98 §3.5): an incident nobody has closed waits on somebody, Action required.
        ->and($log['incidents'][0]['status_label'])->toBe('Action required')

        // Counted, never listed. A panic alert has its own table, its own
        // channel and its own response screen; folding it in here would put a
        // life-safety event behind case management.
        ->and($log['duress_count'])->toBe(0);
});

it('does not treat an incident as closed until somebody records what was done', function () {
    $incident = SecurityIncident::create([
        'tenant_id' => $this->estate,
        'kind' => 'Attempted unauthorized access',
        'severity' => 'low',

        // Marked resolved by somebody who wrote nothing down.
        'status' => SecurityIncident::RESOLVED,
        'resolution' => null,
        'occurred_at' => now()->subMonths(2),
    ]);

    expect($incident->isSettled())->toBeFalse();

    $incident->forceFill([
        'resolution' => 'Challenged and escorted off the property. No entry gained.',
        'closed_at' => now()->subMonths(2)->addDays(2),
    ])->save();

    expect($incident->fresh()->isSettled())->toBeTrue();
});
