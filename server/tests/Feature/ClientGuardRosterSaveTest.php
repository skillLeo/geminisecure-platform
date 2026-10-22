<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Saving a client's guard roster (bug hunt B006)
|--------------------------------------------------------------------------
|
| Board 10 saves who stands a client's gates, whole. Three things were wrong
| with it, and each put a false answer on a screen:
|
|   an officer taken off the roster kept their future shifts there, so the
|   coverage board read those gates as covered by somebody who is no longer
|   posted anywhere and cannot clock in;
|
|   an officer whose licence merely expires in a few weeks — still licensed
|   today — was hidden from the picker and refused by the save, and the screen
|   said every unposted officer had a lapsed or unrecorded licence;
|
|   the save never looked at status, so a hand-made POST put a suspended officer
|   on a post, and the picker listed people who have left.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    /*
     * The pool is every unposted officer on the platform, so an officer another
     * test left unposted would decide what this one sees. Inside the transaction
     * they are posted somewhere else, and rolled back afterwards.
     */
    Guard::query()->whereNull('tenant_id')->update(['tenant_id' => 'clientroster-elsewhere']);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** @param  array<string, mixed>  $overrides */
function clientRosterOfficer(array $overrides = []): Guard
{
    static $n = 0;
    $n++;

    return Guard::query()->create([
        'full_name' => 'Client Roster Officer '.$n,
        'employee_number' => 'GS-CRO-'.$n.'-'.uniqid(),
        'psra_number' => 'PSRA-CRO-'.$n.'-'.uniqid(),
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => null,
        ...$overrides,
    ]);
}

/**
 * Everyone at the fixture estate now, as the screen would post them back.
 *
 * @return list<array{guard_id: int, post_id: int|null}>
 */
function clientRosterAsItStands(): array
{
    return Guard::query()
        ->where('tenant_id', FacilitiesFixture::ESTATE)
        ->get()
        ->map(static fn (Guard $guard): array => ['guard_id' => $guard->id, 'post_id' => $guard->post_id])
        ->values()
        ->all();
}

it('opens the future shifts of an officer taken off the roster, and leaves a shift already worked alone', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $post = Post::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'name' => 'Client Roster Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $removed = clientRosterOfficer(['tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id]);

    $ahead = Shift::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'guard_id' => $removed->id,
        'post_id' => $post->id,
        'rostered_start' => now()->addDays(3)->startOfDay()->addHours(12),
        'rostered_end' => now()->addDays(3)->startOfDay()->addHours(23),
        'status' => Shift::ROSTERED,
    ]);

    $worked = Shift::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'guard_id' => $removed->id,
        'post_id' => $post->id,
        'rostered_start' => now()->subDays(2)->startOfDay()->addHours(12),
        'rostered_end' => now()->subDays(2)->startOfDay()->addHours(23),
        'actual_start' => now()->subDays(2)->startOfDay()->addHours(12)->addMinutes(4),
        'status' => 'completed',
    ]);

    $roster = array_values(array_filter(
        clientRosterAsItStands(),
        static fn (array $line): bool => $line['guard_id'] !== $removed->id,
    ));

    $this->actingAs($director)
        ->post('/clients/'.FacilitiesFixture::ESTATE.'/guards', ['roster' => $roster])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($removed->fresh()->tenant_id)->toBeNull()
        ->and($ahead->fresh()->guard_id)->toBeNull()
        ->and($ahead->fresh()->status)->toBe(Shift::OPEN)
        ->and($ahead->fresh()->released_reason)->not->toBeNull()
        ->and($ahead->fresh()->posted_by_name)->toBe($director->name)

        // The evidence that the gate was covered, and by whom, is untouched.
        ->and($worked->fresh()->guard_id)->toBe($removed->id)
        ->and($worked->fresh()->status)->toBe('completed')

        // And the screen says so, rather than only that the roster was saved.
        ->and((string) session('success'))->toContain('1 future shift');

    expect(DB::connection('mysql')->table('audit_log')
        ->where('action', 'roster.shifts_released')
        ->where('entity_id', (string) $removed->id)
        ->exists())->toBeTrue();
});

it('offers and accepts an officer whose licence is only expiring, because it still licenses them today', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $soon = clientRosterOfficer(['psra_expires_on' => now()->addDays(20)->toDateString()]);

    $this->actingAs($director)
        ->get('/clients/'.FacilitiesFixture::ESTATE.'/guards')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'roster.pool',
            fn ($pool) => collect($pool)->pluck('id')->contains($soon->id),
        ));

    $this->actingAs($director)
        ->post('/clients/'.FacilitiesFixture::ESTATE.'/guards', [
            'roster' => [...clientRosterAsItStands(), ['guard_id' => $soon->id, 'post_id' => null]],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($soon->fresh()->tenant_id)->toBe(FacilitiesFixture::ESTATE);
});

it('never offers or accepts a suspended officer or one who has left, and still refuses a lapsed or undated licence', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $suspended = clientRosterOfficer(['status' => 'suspended']);
    $left = clientRosterOfficer(['status' => 'inactive']);
    $lapsed = clientRosterOfficer(['psra_expires_on' => now()->subDays(3)->toDateString()]);
    $undated = clientRosterOfficer(['psra_expires_on' => null]);

    $this->actingAs($director)
        ->get('/clients/'.FacilitiesFixture::ESTATE.'/guards')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'roster.pool',
            fn ($pool) => collect($pool)->pluck('id')->intersect([$suspended->id, $left->id, $lapsed->id, $undated->id])->isEmpty(),
        ));

    foreach ([$suspended, $left, $lapsed, $undated] as $officer) {
        $this->actingAs($director)
            ->post('/clients/'.FacilitiesFixture::ESTATE.'/guards', [
                'roster' => [...clientRosterAsItStands(), ['guard_id' => $officer->id, 'post_id' => null]],
            ])
            ->assertSessionHasErrors('roster');

        expect($officer->fresh()->tenant_id)->toBeNull();
    }
});

it('says why nobody can be added in words that are true of the officers there are', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    // The only unposted officer is suspended; their licence is in order.
    clientRosterOfficer(['status' => 'suspended']);

    $this->actingAs($director)
        ->get('/clients/'.FacilitiesFixture::ESTATE.'/guards')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('roster.pool', [])
            ->where('roster.poolBlockedReason', fn ($reason) => str_contains((string) $reason, 'suspended')
                && ! str_contains((string) $reason, 'lapsed or unrecorded')));
});
