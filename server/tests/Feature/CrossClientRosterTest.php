<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Gemini\SecurityOperations;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The cross-client rota is the week's shifts, and the scope narrows it
|--------------------------------------------------------------------------
|
| Board super-admin-24 draws every post at every client in one grid. Since 3
| October it draws THE WEEK'S SHIFTS: a line per post, a column per day with its
| date, and in each cell the shifts that start there that day, each with its time
| and the officer on it or "Open". It used to repeat each officer's usual post in
| all seven columns, with no date and no time, and was read as a schedule.
|
| What is worth asserting rather than looking at:
|
|   1. A shift whose officer cannot stand it reads Open, with the reason. An
|      expired PSRA licence is a hard block, and the failure mode is silent: a
|      rota that quietly showed that officer covering the shift would be the
|      platform asserting coverage that legally does not exist.
|
|   2. A Head of Security is scoped to assigned sites, in the QUERY.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console. Each test runs inside a transaction.
|
*/

uses(DatabaseTransactions::class);

/**
 * An estate row, written straight to the table (not Tenant::create(), which provisions a database).
 */
function rosterEstate(string $id, string $name, string $status = 'active'): string
{
    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function rosterPost(string $tenantId, string $name, string $type = 'gate'): Post
{
    return Post::create(['tenant_id' => $tenantId, 'name' => $name, 'type' => $type, 'is_active' => true]);
}

function rosterGuard(string $name, Post $post, string $status = 'active'): Guard
{
    $suffix = random_int(100000, 999999);

    return Guard::create([
        'full_name' => $name,
        'employee_number' => 'GS-R'.$suffix,
        'psra_number' => 'PSRA-R'.$suffix,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => $status,
        'tenant_id' => $post->tenant_id,
        'post_id' => $post->id,
    ]);
}

/** A shift at noon in Kingston (17:00 UTC) on the given date, eight hours long. */
function rosterShift(Post $post, string $date, ?Guard $guard = null, string $duty = 'post'): Shift
{
    $start = Carbon::parse($date.' 17:00:00', 'UTC');

    return Shift::create([
        'tenant_id' => $post->tenant_id,
        'post_id' => $post->id,
        'guard_id' => $guard?->id,
        'duty' => $duty,
        'rostered_start' => $start,
        'rostered_end' => $start->copy()->addHours(8),
        'status' => $guard === null ? Shift::OPEN : Shift::ROSTERED,
    ]);
}

/** A Gemini user whose single role carries the given scope. */
function rosterViewer(AccessScope $scope, ?string $assignedTo = null): User
{
    $role = Role::findOrCreate('test.roster_'.$scope->value.random_int(1000, 9999), 'web');
    $role->forceFill(['console' => Console::Gemini->value, 'scope_default' => $scope->value])->save();

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    if ($assignedTo !== null) {
        EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => $assignedTo, 'role_id' => $role->id, 'is_active' => true]);
    }

    return $user->fresh();
}

/** The line for a post on the estate's group, from a roster answer. */
function rosterLine(array $roster, string $estate, string $post): array
{
    $group = collect($roster['groups'])->firstWhere('id', $estate);

    return collect($group['lines'])->firstWhere('post', $post);
}

beforeEach(function () {
    $this->operations = new SecurityOperations;
    $this->estate = rosterEstate('rostertest'.random_int(100, 999), 'Roster Test Estate');

    // A Wednesday two weeks ahead, so nothing else on the platform's roster is in that week by accident.
    $this->monday = SiteTime::today()->addWeeks(2)->startOfWeek(Carbon::MONDAY);
    $this->wednesday = $this->monday->copy()->addDays(2)->toDateString();
});

it('draws a week with a date under every day, and the weeks either side', function () {
    rosterGuard('Marcia Grant', $post = rosterPost($this->estate, 'Main Gate'));
    rosterShift($post, $this->wednesday);

    $roster = $this->operations->roster(rosterViewer(AccessScope::All), $this->wednesday);

    expect($roster['weekDates'])->toHaveCount(7)
        ->and($roster['weekDates'][0])->toBe($this->monday->toDateString())
        ->and($roster['days'][2])->toBe(Carbon::parse($this->wednesday)->format('D j M'))
        ->and($roster['previousWeek'])->toBe($this->monday->copy()->subWeek()->toDateString())
        ->and($roster['nextWeek'])->toBe($this->monday->copy()->addWeek()->toDateString());
});

it('puts each shift on the day it starts, with its time and the officer on it, and says No shift elsewhere', function () {
    $post = rosterPost($this->estate, 'Main Gate');
    rosterShift($post, $this->wednesday, rosterGuard('Marcia Grant', $post));

    $line = rosterLine($this->operations->roster(rosterViewer(AccessScope::All), $this->wednesday), $this->estate, 'Main Gate');

    expect($line['cells'])->toHaveCount(7)
        ->and($line['cells'][0])->toBe([])
        ->and($line['cells'][2])->toHaveCount(1)
        ->and($line['cells'][2][0]['label'])->toBe('M. Grant')
        ->and($line['cells'][2][0]['tone'])->toBe('day')
        ->and($line['cells'][2][0]['time'])->toContain('12:00 PM')
        ->and($line['cells'][2][0]['time'])->toContain('8:00 PM');
});

it('reads Open for a shift with nobody on it, and for one whose officer has a lapsed licence, with the reason', function () {
    $post = rosterPost($this->estate, 'Main Gate');
    rosterShift($post, $this->wednesday);
    rosterShift($post, $this->monday->copy()->addDays(3)->toDateString(), rosterGuard('Lapsed Officer', $post, 'licence_expired'));

    $line = rosterLine($this->operations->roster(rosterViewer(AccessScope::All), $this->wednesday), $this->estate, 'Main Gate');

    expect($line['cells'][2][0]['label'])->toBe('Open')
        ->and($line['cells'][2][0]['tone'])->toBe('open')
        ->and($line['cells'][3][0]['label'])->toBe('Open')
        ->and($line['cells'][3][0]['tone'])->toBe('open')
        // The reason, not just the colour: an empty shift and an unlicensed officer are different problems.
        ->and($line['cells'][3][0]['title'])->toContain('licence has expired');
});

it('draws a patrol shift as a night chip that says patrol', function () {
    $post = rosterPost($this->estate, 'Main Gate');
    rosterShift($post, $this->wednesday, rosterGuard('Nightly Walker', $post), 'patrol');

    $cell = rosterLine($this->operations->roster(rosterViewer(AccessScope::All), $this->wednesday), $this->estate, 'Main Gate')['cells'][2][0];

    expect($cell['tone'])->toBe('night')
        ->and($cell['time'])->toStartWith('Patrol · ');
});

it('gives every active post a line, with nothing rostered on it, and leaves out a shift from another week', function () {
    $quiet = rosterPost($this->estate, 'Service Gate');
    rosterShift($quiet, $this->monday->copy()->addWeek()->addDay()->toDateString());

    $roster = $this->operations->roster(rosterViewer(AccessScope::All), $this->wednesday);
    $line = rosterLine($roster, $this->estate, 'Service Gate');

    expect($line)->not->toBeNull()
        ->and(collect($line['cells'])->flatten(1)->all())->toBe([]);

    $posts = (int) collect($roster['kpis'])->firstWhere('label', 'Posts, platform-wide')['value'];
    expect($posts)->toBeGreaterThanOrEqual(1);
});

it('keeps a scoped role out of a client they are not assigned to', function () {
    $mine = rosterEstate('rostermine'.random_int(100, 999), 'Assigned Estate');
    $theirs = rosterEstate('rosterother'.random_int(100, 999), 'Unassigned Estate');

    rosterPost($mine, 'Main Gate');
    rosterPost($theirs, 'Main Gate');

    $roster = $this->operations->roster(rosterViewer(AccessScope::AssignedSites, $mine), $this->wednesday);

    $ids = collect($roster['groups'])->pluck('id')->all();

    expect($ids)->toContain($mine)
        ->and($ids)->not->toContain($theirs)
        // The screen is told it is narrowed, so an empty rota can say "none at your sites".
        ->and($roster['scoped'])->toBeTrue();
});

it('shows a role with every site both clients', function () {
    $mine = rosterEstate('rosterboth'.random_int(100, 999), 'First Estate');
    $theirs = rosterEstate('rosterboth2'.random_int(100, 999), 'Second Estate');

    rosterPost($mine, 'Main Gate');
    rosterPost($theirs, 'Main Gate');

    $ids = collect($this->operations->roster(rosterViewer(AccessScope::All), $this->wednesday)['groups'])->pluck('id')->all();

    expect($ids)->toContain($mine)->and($ids)->toContain($theirs);
});
