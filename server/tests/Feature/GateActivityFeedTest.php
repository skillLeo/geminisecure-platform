<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\CheckpointScan;
use App\Models\EstateAssignment;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Gemini\SecurityOperations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The cross-client activity feed, and the three tables it reads
|--------------------------------------------------------------------------
|
| Board super-admin-26 shows what every gate is reporting, across every client.
| It is the screen with the strongest architectural constraint on it, and three
| things about it are worth asserting:
|
|   1. IT READS THREE TABLES AND MERGES THEM. A gate decision has no central
|      home, so `gate_events` holds it. A checkpoint scan and a shift start
|      already have one. Copying those into a fourth table so this could be a
|      single query would create two records of one event that are free to
|      disagree — and the one that disagrees is the one an insurer reads.
|
|   2. NOTHING HERE OPENS AN ESTATE DATABASE. A cross-client feed built by
|      querying each estate in turn would be an isolation breach wearing a
|      report's clothes, and it would look identical on the screen.
|
|   3. "GUARDS ON POST" MEANS STANDING ONE. Counting standing assignments would
|      include a guard whose licence has lapsed and who is therefore not
|      rostered anywhere — while the coverage board two screens away reports
|      their gate as uncovered.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console and dropping the schema mid-run would take other
| tests with it. Each test runs inside a transaction and leaves nothing behind.
|
*/

uses(DatabaseTransactions::class);

/** An estate row, written straight to the table — see CrossClientRosterTest. */
function feedEstate(string $name): string
{
    $id = 'feed'.random_int(100000, 999999);

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function feedPost(string $tenantId, string $name, string $type = 'gate'): Post
{
    return Post::create([
        'tenant_id' => $tenantId,
        'name' => $name,
        'type' => $type,
        'is_active' => true,
    ]);
}

function feedGuard(Post $post, string $name, int $licenceDays = 365): Guard
{
    $suffix = random_int(100000, 999999);

    return Guard::create([
        'full_name' => $name,
        'employee_number' => 'GS-F'.$suffix,
        'psra_number' => 'PSRA-F'.$suffix,
        'psra_expires_on' => now()->addDays($licenceDays)->toDateString(),
        'employment_type' => 'full_time',
        'status' => $licenceDays < 0 ? 'licence_expired' : 'active',
        'tenant_id' => $post->tenant_id,
        'post_id' => $post->id,
    ]);
}

/** A Gemini user whose single role carries the given scope. */
function feedViewer(AccessScope $scope, ?string $assignedTo = null): User
{
    $role = Role::findOrCreate('test.feed_'.$scope->value.random_int(100000, 999999), 'web');
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
    $this->estate = feedEstate('Feed Test Estate');
    $this->post = feedPost($this->estate, 'Main Gate');
    $this->guard = feedGuard($this->post, 'Marcus Whyte');
});

it('merges gate decisions, checkpoint scans and shift starts into one feed', function () {
    GateEvent::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'guard_name' => $this->guard->full_name,
        'post_id' => $this->post->id,
        'post_name' => $this->post->name,
        'verdict' => GateEvent::ADMIT,
        'subject' => 'Marcia James admitted',
        'basis' => 'QR pass · Lot 47',
        'occurred_at' => now()->subMinutes(2),
        'is_simulated' => true,
    ]);

    $patrol = feedPost($this->estate, 'Patrol', 'patrol');
    $walker = feedGuard($patrol, 'Renae Cross');

    $checkpoint = PatrolCheckpoint::create([
        'tenant_id' => $this->estate,
        'post_id' => $patrol->id,
        'label' => 'Phase 2 playfield',
        'code' => 'FEED-CP-'.random_int(100000, 999999),
        'sequence' => 1,
        'is_active' => true,
    ]);

    CheckpointScan::create([
        'patrol_checkpoint_id' => $checkpoint->id,
        'guard_id' => $walker->id,
        'device_time' => now()->subMinutes(5),
        'server_time' => now()->subMinutes(5),
        'is_simulated' => true,
    ]);

    Shift::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'post_id' => $this->post->id,
        'rostered_start' => now()->startOfDay()->addHours(7),
        'rostered_end' => now()->startOfDay()->addHours(19),
        'actual_start' => now()->subMinutes(9),
        'geofence_distance_m' => 12,
        'mock_location_flag' => false,
        'status' => 'active',
    ]);

    $feed = collect($this->operations->gateActivity(feedViewer(AccessScope::All))['feed']);

    // Newest first, and all three sources present. Joined with a colon, never an em dash (M53 Law 3, M98 §3.6).
    expect($feed->pluck('headline')->take(3)->all())->toBe([
        'Marcia James admitted: QR pass · Lot 47',
        'Checkpoint scanned: Phase 2 playfield',
        'Shift clocked in: Main Gate, Day Shift',
    ]);

    expect($feed[0]['verdict'])->toBe('admit')
        ->and($feed[0]['detail'])->toBe('Main Gate · Marcus Whyte')
        ->and($feed[1]['verdict'])->toBe('patrol')
        ->and($feed[1]['detail'])->toBe('Patrol · Renae Cross')

        // The geofence line comes from the shift, which is the whole reason
        // this branch reads `shifts` instead of a copy of it.
        ->and($feed[2]['detail'])->toBe('Marcus Whyte · geofence verified');
});

it('says so when a handset reported a mock location', function () {
    Shift::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'post_id' => $this->post->id,
        'rostered_start' => now()->startOfDay()->addHours(19),
        'rostered_end' => now()->startOfDay()->addHours(31),
        'actual_start' => now()->subMinutes(3),
        'geofence_distance_m' => 0,
        'mock_location_flag' => true,
        'status' => 'active',
    ]);

    $feed = collect($this->operations->gateActivity(feedViewer(AccessScope::All))['feed']);

    // A spoofed handset can report nought metres from anywhere on earth, so the
    // flag outranks the distance.
    expect($feed[0]['detail'])->toContain('mock location reported')
        ->and($feed[0]['headline'])->toContain('Night Shift');
});

it('leaves a shift that has not started out of the feed', function () {
    Shift::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'post_id' => $this->post->id,
        'rostered_start' => now()->addHours(4),
        'rostered_end' => now()->addHours(16),
        'actual_start' => null,
        'status' => 'rostered',
    ]);

    $feed = collect($this->operations->gateActivity(feedViewer(AccessScope::All))['feed'])
        ->where('estate', 'Feed Test Estate');

    // A roster is an intention. Reporting it as a clock-in would put a guard on
    // post hours before they arrived.
    expect($feed)->toHaveCount(0);
});

it('draws an override as an admission and says what it was', function () {
    GateEvent::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'guard_name' => $this->guard->full_name,
        'post_id' => $this->post->id,
        'post_name' => $this->post->name,
        'verdict' => GateEvent::OVERRIDE,
        'subject' => 'Ambulance admitted',
        'basis' => 'override · medical emergency',
        'occurred_at' => now()->subMinute(),
        'is_simulated' => true,
    ]);

    $row = collect($this->operations->gateActivity(feedViewer(AccessScope::All))['feed'])->first();

    // Somebody was let in, so the board's admit colour is the honest one. The
    // basis is what says it was against standing orders.
    expect($row['verdict'])->toBe('admit')
        ->and($row['headline'])->toContain('override · medical emergency');
});

it('counts guards standing a post, not guards assigned to one', function () {
    // Posted to a gate, licence lapsed, therefore not rostered anywhere.
    feedGuard(feedPost($this->estate, 'Service Gate'), 'Devon Palmer', licenceDays: -14);

    Shift::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'post_id' => $this->post->id,
        'rostered_start' => now()->subHours(2),
        'rostered_end' => now()->addHours(10),
        'actual_start' => now()->subHours(2),
        'geofence_distance_m' => 8,
        'status' => 'active',
    ]);

    $kpis = collect($this->operations->gateActivity(feedViewer(AccessScope::All))['kpis']);

    // One, not two. The coverage board says Service Gate is uncovered, and two
    // screens disagreeing about whether a post is manned is worse than either
    // number alone.
    expect((int) $kpis->firstWhere('key', 'on_post')['value'])->toBe(1);
});

it('counts today admissions and denials separately', function () {
    foreach ([['admit', 3], ['admit', 4], ['deny', 5]] as $i => [$verdict, $minutes]) {
        GateEvent::create([
            'tenant_id' => $this->estate,
            'guard_id' => $this->guard->id,
            'guard_name' => $this->guard->full_name,
            'post_id' => $this->post->id,
            'post_name' => $this->post->name,
            'verdict' => $verdict,
            'subject' => 'Visitor '.$i,
            'occurred_at' => now()->subMinutes($minutes),
            'is_simulated' => true,
        ]);
    }

    // Yesterday's traffic is not today's.
    GateEvent::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'guard_name' => $this->guard->full_name,
        'post_id' => $this->post->id,
        'post_name' => $this->post->name,
        'verdict' => 'admit',
        'subject' => 'Yesterday visitor',
        'occurred_at' => now()->subDay(),
        'is_simulated' => true,
    ]);

    $viewer = feedViewer(AccessScope::AssignedSites, $this->estate);
    $kpis = collect($this->operations->gateActivity($viewer)['kpis']);

    expect((int) $kpis->firstWhere('key', 'admits')['value'])->toBe(2)
        ->and((int) $kpis->firstWhere('key', 'denies')['value'])->toBe(1);
});

it('keeps a scoped viewer out of a client they are not assigned to', function () {
    $theirs = feedEstate('Unassigned Estate');
    $theirPost = feedPost($theirs, 'Main Gate');
    $theirGuard = feedGuard($theirPost, 'Other Officer');

    foreach ([[$this->estate, $this->post, $this->guard], [$theirs, $theirPost, $theirGuard]] as [$estate, $post, $guard]) {
        GateEvent::create([
            'tenant_id' => $estate,
            'guard_id' => $guard->id,
            'guard_name' => $guard->full_name,
            'post_id' => $post->id,
            'post_name' => $post->name,
            'verdict' => GateEvent::ADMIT,
            'subject' => 'Visitor admitted at '.$estate,
            'occurred_at' => now()->subMinute(),
            'is_simulated' => true,
        ]);
    }

    $estates = collect($this->operations->gateActivity(feedViewer(AccessScope::AssignedSites, $this->estate))['feed'])
        ->pluck('estate')
        ->all();

    // Narrowed in SQL. Filtering a rendered list would leave the other client's
    // gate log one URL away.
    expect($estates)->toContain('Feed Test Estate')
        ->and($estates)->not->toContain('Unassigned Estate');
});

it('carries no household, resident, position or money out of the feed', function () {
    GateEvent::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'guard_name' => $this->guard->full_name,
        'post_id' => $this->post->id,
        'post_name' => $this->post->name,
        'verdict' => GateEvent::ADMIT,
        'subject' => 'Marcia James admitted',
        'basis' => 'QR pass · Lot 47',
        'occurred_at' => now()->subMinute(),
        'is_simulated' => true,
    ]);

    $payload = $this->operations->gateActivity(feedViewer(AccessScope::All));
    $keys = collect($payload['feed'])->flatMap(static fn (array $row): array => array_keys($row))->unique()->values();

    // An allowlist, not a denylist: a column added to `gate_events` later
    // should have to be admitted here deliberately rather than arrive by
    // default because nobody thought to forbid it.
    expect($keys->all())->toBe(['verdict', 'headline', 'detail', 'estate', 'time']);

    $serialised = strtolower((string) json_encode($payload));

    foreach (['latitude', 'longitude', 'household', 'resident_id', 'balance', 'amount', 'minor', 'jmd'] as $forbidden) {
        expect($serialised)->not->toContain($forbidden);
    }
});
