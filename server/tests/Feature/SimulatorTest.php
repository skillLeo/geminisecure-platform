<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\DuressAlert;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\PostCoverage;
use App\Services\Simulation\Simulator;
use App\Support\SiteTime;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /simulator — Part C of the web deliverable
|--------------------------------------------------------------------------
|
| THREE THINGS ARE UNDER TEST. That the simulator is ABSENT — not refused,
| absent — on a host without the flag. That with the flag on it is the
| Director's and nobody else's. And that every event it fires goes through
| the real /api/v1 endpoint: the row that appears is one the endpoint made,
| flagged simulated, and a seeded burst fired twice makes its rows once.
|
*/

function simulatorEstate(): string
{
    $id = 'simtest';

    DB::table('tenants')->updateOrInsert(
        ['id' => $id],
        [
            'name' => 'Simulator Test Estate',
            'data' => json_encode(['name' => 'Simulator Test Estate']),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    return $id;
}

function simulatorGuard(): Guard
{
    static $n = 0;
    $n++;

    return Guard::create([
        'full_name' => 'Sim Guard '.$n,
        'employee_number' => 'GS-SIM-'.$n,
        'psra_number' => 'PSRA-SIM-'.$n,
        'psra_expires_on' => now()->addYear(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => simulatorEstate(),
    ]);
}

function simulatorUser(string $role): User
{
    $user = User::factory()->create([
        'console' => Console::Gemini->value,
        'status' => 'active',
    ]);

    $user->syncRoles([$role]);

    return $user->fresh();
}

beforeEach(function () {
    $this->seed(RbacMatrixSeeder::class);
    $this->withoutVite();
});

it('is the Director\'s, and refuses every other role', function () {
    simulatorEstate();

    $this->actingAs(simulatorUser(Role::DIRECTOR))
        ->get('/simulator')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Simulator/Index')
            ->where('results', [])
            // Other suites leave their own committed estates behind in the
            // central database, so "at least this one" rather than "one".
            ->where('estates', fn ($estates) => collect($estates)->contains('id', simulatorEstate()))
            ->has('kinds', 5));

    // The Dispatcher runs the queue the simulator fills, and still may not
    // fill it: putting a panic into a client's live queue is a platform act.
    $this->actingAs(simulatorUser(Role::DISPATCHER))->get('/simulator')->assertForbidden();

    $this->actingAs(simulatorUser(Role::DISPATCHER))
        ->post('/simulator/alert', ['tenant_id' => simulatorEstate(), 'kind' => 'panic'])
        ->assertForbidden();

    expect(DuressAlert::query()->count())->toBe(0);
});

it('raises an alert through the real endpoint, flagged simulated, on a handset it hands back', function () {
    simulatorGuard();
    $director = simulatorUser(Role::DIRECTOR);

    $this->actingAs($director)
        ->post('/simulator/alert', ['tenant_id' => simulatorEstate(), 'kind' => 'medical', 'offline' => true])
        ->assertRedirect('/simulator');

    $alert = DuressAlert::query()->sole();

    // The endpoint made it — kind, estate and the offline capture all came through
    // validation — and it says it is not real. Captured nine minutes before it reached
    // the server, it is a queued act and not a wrong clock (bug hunt B066).
    expect($alert->kind)->toBe('medical')
        ->and($alert->tenant_id)->toBe(simulatorEstate())
        ->and((bool) $alert->is_simulated)->toBeTrue()
        ->and((bool) $alert->captured_offline)->toBeTrue()
        ->and((bool) $alert->clock_skewed)->toBeFalse()
        ->and($alert->device_time?->lessThan($alert->server_time))->toBeTrue();

    // And the handset it borrowed is gone: a working token left behind would
    // be a credential on the platform, and a guard shown carrying a device.
    expect(Guard::query()->sole()->tokens()->count())->toBe(0);

    $this->actingAs($director)
        ->get('/simulator')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('results', 1)
            ->where('results.0.outcome', 'created')
            ->where('results.0.status', 201));
});

it('records an arrival and clocks a shift through the endpoints, and the coverage board says so', function () {
    /*
     * AT NOON ON THE SITE'S OWN DAY (M10, baseline). The coverage board draws the site's today
     * as two windows on the site's clock, 7 AM – 7 PM and 7 PM – 7 AM, and the assertion below
     * reads the first. A shift from an hour ago to eleven hours on is inside that window only
     * when "now" is daytime in Kingston: run in the evening there — 01:31 UTC, when this was
     * found — the shift sits in the night window and the day cell is honestly empty. Frozen at
     * the site's noon, the test asserts the rule it was written for on every clock.
     */
    $this->travelTo(SiteTime::today(simulatorEstate())->setTime(12, 0));

    $guard = simulatorGuard();
    $post = Post::create(['tenant_id' => simulatorEstate(), 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $shift = Shift::create([
        'tenant_id' => simulatorEstate(),
        'guard_id' => $guard->id,
        'post_id' => $post->id,
        'rostered_start' => now()->subHour(),
        'rostered_end' => now()->addHours(11),
        'status' => 'rostered',
    ]);
    $director = simulatorUser(Role::DIRECTOR);

    // The override is the arrival that carries a reason; the endpoint refuses
    // one without, so this proves the catalogue entry reaches it whole.
    $this->actingAs($director)
        ->post('/simulator/gate-event', ['tenant_id' => simulatorEstate(), 'arrival' => 7, 'subject' => 'Gate2 Contractor Ltd'])
        ->assertRedirect('/simulator');

    $event = GateEvent::query()->sole();

    expect($event->verdict)->toBe('override')
        ->and($event->subject)->toBe('Gate2 Contractor Ltd')
        ->and((bool) $event->is_simulated)->toBeTrue()
        ->and($event->post_id)->toBe($post->id);

    $this->actingAs($director)
        ->post('/simulator/shifts', ['tenant_id' => simulatorEstate()])
        ->assertRedirect('/simulator');

    $shift->refresh();

    expect($shift->actual_start)->not->toBeNull()
        ->and((bool) $shift->is_simulated)->toBeTrue();

    // The post reads as manned, and the board carries the badge that says by
    // whom — Part C's gap, closed.
    $board = app(PostCoverage::class)->forViewer($director);
    $row = collect($board['rows'])->firstWhere('post', 'Main Gate');

    expect($board['sourceBadge'])->toBe(['source' => 'guard', 'simulated' => true])
        ->and($row['cells'][0]['class'])->toBe('covered');
});

it('runs a seeded night once, however many times the same seed is pressed', function () {
    simulatorGuard();
    Post::create(['tenant_id' => simulatorEstate(), 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $director = simulatorUser(Role::DIRECTOR);

    /*
     * WHY THIS TEST DOES NOT COUNT ROWS ANY MORE.
     *
     * The burst deals its twelve events across EVERY estate the platform holds, because that is
     * what `Tenant::estates()` hands the route. Other suites commit estates to the central database
     * and leave them there, and M5 added a demonstration estate for the app-store reviewers. So how
     * many of the twelve reach an estate with a guard to borrow a handset from — and therefore how
     * many rows appear anywhere — depends entirely on which suites ran first. That is what made
     * this test fail in a full run and pass alone: adding ONE estate was enough for all twelve to
     * be dealt elsewhere and refused, honestly, writing nothing.
     *
     * So the route is pressed for what only the route can prove (the ability, the validation, the
     * redirect, the summary the console draws), and the seed's real promise is proved against the
     * service with the estate list pinned to THIS estate — which no other suite can touch.
     */
    $screen = function (): array {
        $props = [];
        $this->actingAs(simulatorUser(Role::DIRECTOR))
            ->get('/simulator')
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $props = $page->toArray()['props'];
            });

        return ['summary' => (string) ($props['summary'] ?? ''), 'results' => (array) ($props['results'] ?? [])];
    };

    $this->actingAs($director)
        ->post('/simulator/ambient', ['seed' => 7, 'count' => 12])
        ->assertRedirect('/simulator');

    ['summary' => $first, 'results' => $drawn] = $screen();

    // Twelve events, every one of them accounted for, and nothing pretending to be real.
    expect($first)->toStartWith('Seed 7, 12 event(s): ')
        ->and($drawn)->toHaveCount(12)
        ->and(DuressAlert::query()->where('is_simulated', false)->count())->toBe(0)
        ->and(GateEvent::query()->where('is_simulated', false)->count())->toBe(0);

    foreach ($drawn as $i => $row) {
        expect(in_array($row['outcome'], ['created', 'replayed', 'refused', 'idle'], true))
            ->toBeTrue('event '.$i.' came back with an outcome nobody accounts for: '.json_encode($row['outcome'] ?? null));
    }

    // The console's two figures are the rows' own arithmetic, not a second opinion.
    expect(preg_match('/(\d+) new, (\d+) replayed/', $first, $counted))->toBe(1, $first);

    $created = count(array_filter($drawn, static fn (array $row): bool => $row['outcome'] === 'created'));

    expect((int) $counted[1] + (int) $counted[2])->toBe($created, $first);

    /*
     * THE SEED, on an estate list that is only ever this one estate — so the result is the same
     * whether this file runs alone or last. A guard and a post were made above, so every one of the
     * twelve has a handset to borrow and lands.
     */
    $onlyThisEstate = fn () => Tenant::estates(fn ($query) => $query->whereKey(simulatorEstate()));

    $night = app(Simulator::class)->ambient($onlyThisEstate(), 21, 12);

    expect($night['rows'])->toHaveCount(12)
        ->and($night['new'])->toBe(12, 'twelve events on an estate with a guard should all have landed')
        ->and($night['replayed'])->toBe(0);

    // Same seed, same idempotency keys: the endpoints hand back what they already made.
    $encore = app(Simulator::class)->ambient($onlyThisEstate(), 21, 12);

    expect($encore['new'])->toBe(0, 'pressing the same seed twice must not make a second night')
        ->and($encore['replayed'])->toBe(12);

    // And the night itself is identical — same kinds, same labels, in the same order.
    expect(array_column($encore['rows'], 'label'))->toBe(array_column($night['rows'], 'label'));

    // A different seed is a different night: its keys have never been used, so nothing can replay.
    $other = app(Simulator::class)->ambient($onlyThisEstate(), 22, 12);

    expect($other['new'])->toBe(12)
        ->and($other['replayed'])->toBe(0)
        ->and(array_column($other['rows'], 'label'))->not->toBe(array_column($night['rows'], 'label'));

    // Back through the route: the same seed pressed again reports nothing new, whatever the estates.
    $this->actingAs($director)
        ->post('/simulator/ambient', ['seed' => 7, 'count' => 12])
        ->assertRedirect('/simulator');

    $this->actingAs($director)
        ->get('/simulator')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('results', 12)
            ->where('summary', fn ($text) => str_starts_with((string) $text, 'Seed 7, 12 event(s): 0 new, ')));
});

it('refuses honestly when the estate has nobody to borrow a handset from', function () {
    simulatorEstate();
    $director = simulatorUser(Role::DIRECTOR);

    $this->actingAs($director)
        ->post('/simulator/alert', ['tenant_id' => simulatorEstate(), 'kind' => 'panic'])
        ->assertRedirect('/simulator');

    // Nothing was written by any other route, and the screen says why.
    expect(DuressAlert::query()->count())->toBe(0);

    $this->actingAs($director)
        ->get('/simulator')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.0.outcome', 'refused')
            ->where('results.0.status', null));
});

it('is absent — not refused, absent — on a host without the flag', function () {
    /*
     * The routes are registered at boot from `config('simulator.enabled')`, so
     * turning the flag off means booting again. Environment first, then a
     * fresh application; the suite's own setting is restored afterwards so the
     * tests that run after this one still reach the routes.
     */
    $set = static function (string $value): void {
        putenv('GS_SIMULATOR_ENABLED='.$value);
        $_ENV['GS_SIMULATOR_ENABLED'] = $value;
        $_SERVER['GS_SIMULATOR_ENABLED'] = $value;
    };

    $set('false');

    try {
        $this->refreshApplication();

        expect(config('simulator.enabled'))->toBeFalse();

        // 404 to a guest, where a registered route would answer with a
        // redirect to sign in: the route is not there to be gated.
        $this->get('/simulator')->assertNotFound();
        $this->post('/simulator/alert')->assertNotFound();
        $this->post('/simulator/ambient')->assertNotFound();
    } finally {
        $set('true');
    }
});
