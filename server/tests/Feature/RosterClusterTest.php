<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Dispatch\PostCoverage;
use App\Services\Gemini\Roster;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The roster cluster — boards 13, 19, 21, 23 and 24 (12 §2, Wave 5)
|--------------------------------------------------------------------------
|
| AN OPEN SHIFT IS A REAL ROW WITH NO OFFICER ON IT. A post needing somebody is
| a fact the operations console has to hold: a placeholder guard would show an
| empty gate as manned, and not recording it would leave the gap in a
| dispatcher's head. Nothing here deletes a shift, and a shift already worked
| is never touched — its actual start is the evidence a post was covered.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function clusterPost(): Post
{
    return Post::query()->create([
        'tenant_id' => FacilitiesFixture::platform()->getTenantKey(),
        'name' => 'Roster Test Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);
}

function clusterGuard(array $overrides = []): Guard
{
    static $n = 0;
    $n++;

    return Guard::query()->create([
        'full_name' => 'Roster Officer '.$n,
        'employee_number' => 'GS-RST-'.$n.'-'.uniqid(),
        'psra_number' => 'PSRA-RST-'.$n,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::platform()->getTenantKey(),
        ...$overrides,
    ]);
}

it('posts an open shift forward only, and the coverage board reads it as open rather than covered', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    // The Accountant holds Guard workforce as View; the Dispatcher holds Full
    // and may post a shift, which is the matrix's own call.
    $reader = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);
    $post = clusterPost();

    /*
     * THE SITE'S TOMORROW AND YESTERDAY, NOT THE SERVER'S (M10, baseline). The roster reads a
     * typed time at the post's estate (M4 §3.1), and "in the past" is before THAT estate's
     * today began. `Carbon::yesterday()` is the server's — UTC — and every evening in Kingston
     * from 7 PM the server's yesterday is the site's today, so the refusal below was not made
     * and the test failed by the clock. The dates are now the site's own.
     */
    $today = SiteTime::today(FacilitiesFixture::ESTATE);
    $tomorrow = $today->copy()->addDay();
    $yesterday = $today->copy()->subDay();

    // A reader of the rota puts no gate on it.
    $this->actingAs($reader)
        ->post('/guards/roster/shifts', [
            'post_id' => $post->id,
            'starts_at' => $tomorrow->copy()->addHours(7)->toDateTimeString(),
            'ends_at' => $tomorrow->copy()->addHours(19)->toDateTimeString(),
        ])
        ->assertForbidden();

    // NEVER INTO THE PAST — it would invent a gap nobody could have filled.
    $this->actingAs($director)
        ->post('/guards/roster/shifts', [
            'post_id' => $post->id,
            'starts_at' => $yesterday->copy()->addHours(7)->toDateTimeString(),
            'ends_at' => $yesterday->copy()->addHours(19)->toDateTimeString(),
        ])
        ->assertSessionHasErrors('starts_at');

    $this->actingAs($director)
        ->post('/guards/roster/shifts', [
            'post_id' => $post->id,
            'starts_at' => $tomorrow->copy()->addHours(7)->toDateTimeString(),
            'ends_at' => $tomorrow->copy()->addHours(19)->toDateTimeString(),
            'reason' => 'Estate asked for day cover during the resurfacing.',
        ])
        ->assertRedirect();

    $shift = Shift::query()->where('post_id', $post->id)->sole();

    expect($shift->guard_id)->toBeNull()
        ->and($shift->status)->toBe(Shift::OPEN)
        ->and($shift->posted_by_name)->toBe($director->name)
        ->and($shift->released_reason)->toContain('resurfacing');

    // Two shifts on one post at one time is two officers told one gate is theirs.
    expect(fn () => app(Roster::class)->postOpenShift(
        $post,
        $tomorrow->copy()->addHours(10)->toDateTimeString(),
        $tomorrow->copy()->addHours(14)->toDateTimeString(),
        $director,
    ))->toThrow(DomainException::class);

    /*
     * THE COVERAGE BOARD, FOR TOMORROW. The open shift is not cover — it reads
     * "Open", which says the gap has been noticed, rather than "Covered".
     */
    $board = app(PostCoverage::class)->forViewer($director, $tomorrow);
    $row = collect($board['rows'])->firstWhere('post', 'Roster Test Gate');

    expect($board['isToday'])->toBeFalse()
        ->and($board['date'])->toBe($tomorrow->toDateString())
        ->and($row['cells'][0]['label'])->toBe('Open')
        ->and($row['cells'][0]['class'])->toBe('uncovered');

    $this->actingAs($director)
        ->get('/dispatch/coverage?date='.$tomorrow->toDateString())
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('date', $tomorrow->toDateString())
            ->where('isToday', false));
});

it('assigns an officer who may stand the post, and refuses one who may not', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $post = clusterPost();
    $roster = app(Roster::class);

    $start = Carbon::tomorrow()->addHours(19);
    $shift = $roster->postOpenShift($post, $start->toDateTimeString(), $start->copy()->addHours(12)->toDateTimeString(), $director);

    // Suspended: posting them would roster somebody who may not stand it.
    $suspended = clusterGuard(['status' => 'suspended']);

    $this->actingAs($director)
        ->post('/guards/roster/shifts/'.$shift->id.'/assign', ['guard_id' => $suspended->id])
        ->assertSessionHasErrors('guard_id');

    // A licence lapsing before the shift starts is the same breach, dated.
    $lapsing = clusterGuard(['psra_expires_on' => Carbon::today()->toDateString()]);

    expect(fn () => $roster->assign($shift->fresh(), $lapsing, $director))->toThrow(DomainException::class);

    $fit = clusterGuard();

    $this->actingAs($director)
        ->post('/guards/roster/shifts/'.$shift->id.'/assign', ['guard_id' => $fit->id])
        ->assertRedirect();

    $shift = $shift->fresh();

    expect($shift->guard_id)->toBe($fit->id)
        ->and($shift->status)->toBe(Shift::ROSTERED)
        ->and($shift->released_reason)->toBeNull();

    // The coverage board, on that day, now reads the night as covered-to-be.
    $row = collect(app(PostCoverage::class)->forViewer($director, Carbon::tomorrow())['rows'])
        ->firstWhere('post', 'Roster Test Gate');

    expect($row['cells'][1]['class'])->toBe('covered');
});

it('releases future shifts only, never one already worked, and says why on each', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $post = clusterPost();
    $guard = clusterGuard();

    $worked = Shift::query()->create([
        'tenant_id' => $post->tenant_id,
        'guard_id' => $guard->id,
        'post_id' => $post->id,
        'rostered_start' => now()->subDay()->startOfDay()->addHours(7),
        'rostered_end' => now()->subDay()->startOfDay()->addHours(19),
        'actual_start' => now()->subDay()->startOfDay()->addHours(7)->addMinutes(3),
        'status' => 'completed',
    ]);

    $ahead = Shift::query()->create([
        'tenant_id' => $post->tenant_id,
        'guard_id' => $guard->id,
        'post_id' => $post->id,
        'rostered_start' => now()->addDays(2)->startOfDay()->addHours(7),
        'rostered_end' => now()->addDays(2)->startOfDay()->addHours(19),
        'status' => Shift::ROSTERED,
    ]);

    $this->actingAs($director)
        ->get('/guards/'.$guard->id.'/compliance')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('future_shifts', 1));

    // The reason is required: a dispatcher picking one up needs to know why.
    $this->actingAs($director)
        ->post('/guards/'.$guard->id.'/release-shifts', ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($director)
        ->post('/guards/'.$guard->id.'/release-shifts', ['reason' => 'PSRA licence lapsed — suspended from duty'])
        ->assertRedirect();

    expect($ahead->fresh()->guard_id)->toBeNull()
        ->and($ahead->fresh()->status)->toBe(Shift::OPEN)
        ->and($ahead->fresh()->released_reason)->toContain('PSRA licence lapsed')

        // UNTOUCHED: the evidence that a post was covered, and by whom.
        ->and($worked->fresh()->guard_id)->toBe($guard->id)
        ->and($worked->fresh()->status)->toBe('completed');
});

it('records a renewed licence as a new date, and never lifts a suspension by the way', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $lapsed = clusterGuard([
        'psra_expires_on' => now()->subWeek()->toDateString(),
        'status' => 'licence_expired',
    ]);

    // A date that does not extend the licence on file is a typo.
    $this->actingAs($director)
        ->post('/guards/'.$lapsed->id.'/licence', [
            'psra_expires_on' => now()->subDay()->toDateString(),
            'psra_number' => $lapsed->psra_number,
        ])
        ->assertSessionHasErrors('psra_expires_on');

    $this->actingAs($director)
        ->post('/guards/'.$lapsed->id.'/licence', [
            'psra_expires_on' => now()->addYears(2)->toDateString(),
            'psra_number' => $lapsed->psra_number,
        ])
        ->assertRedirect();

    $lapsed = $lapsed->fresh();

    // `licence_expired` is the one status the licence itself caused, so it clears.
    expect($lapsed->psra_expires_on->toDateString())->toBe(now()->addYears(2)->toDateString())
        ->and($lapsed->status)->toBe('active');

    // A SUSPENSION IS A DECISION ABOUT CONDUCT, and a renewal does not undo it.
    $suspended = clusterGuard([
        'psra_expires_on' => now()->subWeek()->toDateString(),
        'status' => 'suspended',
    ]);

    $this->actingAs($director)
        ->post('/guards/'.$suspended->id.'/licence', [
            'psra_expires_on' => now()->addYear()->toDateString(),
            'psra_number' => $suspended->psra_number,
        ])
        ->assertRedirect();

    expect($suspended->fresh()->status)->toBe('suspended');

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'guard.licence_renewed')
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and(json_decode((string) $entry->before, true)['psra_expires_on'])->toBe(now()->subWeek()->toDateString());
});

it('reassigns an officer with the post going with the client, and opens their old future shifts', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $oldPost = clusterPost();
    $guard = clusterGuard(['post_id' => $oldPost->id]);

    $ahead = Shift::query()->create([
        'tenant_id' => $oldPost->tenant_id,
        'guard_id' => $guard->id,
        'post_id' => $oldPost->id,
        'rostered_start' => now()->addDays(3)->startOfDay()->addHours(7),
        'rostered_end' => now()->addDays(3)->startOfDay()->addHours(19),
        'status' => Shift::ROSTERED,
    ]);

    // Off posting entirely: no client, no post.
    $this->actingAs($director)
        ->post('/guards/'.$guard->id.'/reassign', ['tenant_id' => '', 'post_id' => ''])
        ->assertRedirect();

    $guard = $guard->fresh();

    /*
     * THE POST GOES WITH THE CLIENT. An officer who kept a post at an estate
     * they no longer work would show on that client's coverage board as
     * standing a gate — D-034's failure, in the other direction.
     */
    expect($guard->tenant_id)->toBeNull()
        ->and($guard->post_id)->toBeNull()
        ->and($ahead->fresh()->guard_id)->toBeNull()
        ->and($ahead->fresh()->status)->toBe(Shift::OPEN);

    // A post that is not the target client's is refused.
    $elsewhere = Post::query()->create([
        'tenant_id' => 'some-other-estate',
        'name' => 'Not Theirs',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $this->actingAs($director)
        ->post('/guards/'.$guard->id.'/reassign', [
            'tenant_id' => FacilitiesFixture::platform()->getTenantKey(),
            'post_id' => $elsewhere->id,
        ])
        ->assertSessionHasErrors('tenant_id');
});
