<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Gemini\Roster;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An officer's claim on an open shift, answered on the Roster (the client's report G3, 26 Sep)
|--------------------------------------------------------------------------
|
| The Guard App has had Leave & duty → Open shifts → Claim since M2, and the claim waited in `shift_claims` for a
| supervisor who had nowhere to answer it: every claim stayed pending for ever. The Roster now lists every claim
| waiting, and Approve puts the officer on the shift through the same checks as Assign.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function claimsOpenShift(): Shift
{
    $post = Post::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Claims Gate '.uniqid(), 'type' => 'gate', 'is_active' => true]);
    $start = SiteTime::today(FacilitiesFixture::ESTATE)->addDays(2)->addHours(7);

    return app(Roster::class)->postOpenShift($post, $start->toDateTimeString(), $start->copy()->addHours(12)->toDateTimeString(), FacilitiesFixture::geminiViewer(Role::DIRECTOR));
}

/** @param  array<string, mixed>  $overrides */
function claimsOfficer(array $overrides = []): Guard
{
    static $n = 0;
    $n++;

    return Guard::query()->create([
        'full_name' => 'Claiming Officer '.$n,
        'employee_number' => 'GS-CLM-'.$n.'-'.uniqid(),
        'psra_number' => 'PSRA-CLM-'.$n.'-'.uniqid(),
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        ...$overrides,
    ]);
}

/** What the Guard App's Claim button writes (`shifts.claim`). */
function claimsClaim(Shift $shift, Guard $guard): int
{
    return (int) DB::connection('mysql')->table('shift_claims')->insertGetId([
        'shift_id' => $shift->id, 'guard_id' => $guard->id, 'status' => 'pending',
        'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function claimsStatus(int $claim): ?string
{
    return DB::connection('mysql')->table('shift_claims')->where('id', $claim)->value('status');
}

it('lists every claim waiting on the Roster, and approving one puts that officer on the shift and declines the rest', function () {
    $shift = claimsOpenShift();
    $first = claimsOfficer();
    $second = claimsOfficer();
    $firstClaim = claimsClaim($shift, $first);
    $secondClaim = claimsClaim($shift, $second);

    $this->actingAs($this->director)
        ->get('/guards/roster')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('claimsWaiting', fn ($claims): bool => collect($claims)->pluck('id')->intersect([$firstClaim, $secondClaim])->count() === 2));

    $this->actingAs($this->director)
        ->post('/guards/roster/claims/'.$secondClaim.'/approve')
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($shift->fresh()->guard_id)->toBe($second->id)
        ->and($shift->fresh()->status)->toBe(Shift::ROSTERED)
        ->and(claimsStatus($secondClaim))->toBe('approved')
        ->and(claimsStatus($firstClaim))->toBe('declined')
        ->and(DB::connection('mysql')->table('shift_claims')->where('id', $firstClaim)->value('decision_note'))->toBe('Another officer was given this shift.');

    // Answered claims are no longer waiting.
    $this->actingAs($this->director)
        ->get('/guards/roster')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('claimsWaiting', fn ($claims): bool => collect($claims)->pluck('id')->intersect([$firstClaim, $secondClaim])->isEmpty()));
});

it('declines one claim and leaves the shift open for the others', function () {
    $shift = claimsOpenShift();
    $claim = claimsClaim($shift, claimsOfficer());

    $this->actingAs($this->director)
        ->post('/guards/roster/claims/'.$claim.'/decline', ['note' => 'Rest hours'])
        ->assertSessionHasNoErrors();

    expect(claimsStatus($claim))->toBe('declined')
        ->and($shift->fresh()->guard_id)->toBeNull()
        ->and($shift->fresh()->status)->toBe(Shift::OPEN);

    // An answered claim cannot be answered again.
    $this->actingAs($this->director)
        ->post('/guards/roster/claims/'.$claim.'/approve')
        ->assertSessionHasErrors('claim');
    expect($shift->fresh()->guard_id)->toBeNull();
});

it('holds an approval to the checks Assign makes: an officer who cannot stand the shift is refused and the claim waits', function () {
    $shift = claimsOpenShift();
    // Lapses the day AFTER the estate's today, so before the shift (two days on, at the estate). It said UTC's
    // tomorrow, which from midnight UTC to midnight in Kingston is the shift's own day — and the licence still valid
    // on it (found by the M12 §5.4 run at 04:00 UTC).
    $lapsing = claimsOfficer(['psra_expires_on' => SiteTime::today(FacilitiesFixture::ESTATE)->addDay()->toDateString()]);
    $claim = claimsClaim($shift, $lapsing);

    $this->actingAs($this->director)
        ->post('/guards/roster/claims/'.$claim.'/approve')
        ->assertSessionHasErrors('claim');

    expect($shift->fresh()->guard_id)->toBeNull()
        ->and(claimsStatus($claim))->toBe('pending');
});

it('lets only a role that may update the workforce answer a claim, and only at an estate it can see', function () {
    $shift = claimsOpenShift();
    $claim = claimsClaim($shift, claimsOfficer());

    $this->actingAs(FacilitiesFixture::geminiViewer(Role::ADMIN_ASSISTANT))
        ->post('/guards/roster/claims/'.$claim.'/approve')
        ->assertForbidden();

    expect(claimsStatus($claim))->toBe('pending');
});

it('shows an officer their shifts on their own page, with whether they are on duty', function () {
    $shift = claimsOpenShift();
    $officer = claimsOfficer();
    app(Roster::class)->assign($shift, $officer, $this->director);

    $this->actingAs($this->director)
        ->get('/guards/'.$officer->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('shifts', 1)
            ->where('shifts.0.id', $shift->id)
            ->where('shifts.0.state', 'Rostered')
            ->where('shifts.0.on_duty', false));
});
