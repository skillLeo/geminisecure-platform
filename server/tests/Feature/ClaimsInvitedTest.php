<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\UnitClaim;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\ResidentApp\ResidentAccounts;
use App\Services\ResidentApp\ResidentInviteAcceptance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Unit claims: an invited claim says so, and is approved in one tap (household invites, stage 2)
|--------------------------------------------------------------------------
|
| Board 31's card carries `notes`: the Invited block (who, when, the access offered, what the sign-in proved,
| and the approve button's own words), the access the approval gives, and — G3 — a warning when the office cut
| off somebody of that name here. A refusal comes back with the claim's id, so it is shown inside that card.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 8876');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Claims household', 'access_restricted' => false]);

    $primary = Resident::query()->where('household_id', $this->household->id)->where('full_name', 'Claims Andrea')->first();
    if ($primary === null) {
        $primary = new Resident;
        $primary->forceFill(['household_id' => $this->household->id, 'full_name' => 'Claims Andrea', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    }
    DB::connection('tenant')->table('residents')->where('household_id', $this->household->id)->where('id', '!=', $primary->id)->update(['is_primary' => false]);
    $this->primary = $primary;
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    DB::connection('tenant')->table('unit_claims')->where('unit_id', $this->unit->id)->delete();
    DB::connection('tenant')->table('resident_invites')->where('unit_id', $this->unit->id)->delete();
    $added = Resident::withRemoved()->where('household_id', $this->household->id)->whereKeyNot($this->primary->id)->pluck('id');
    DB::connection('tenant')->table('residents')->whereIn('id', $added)->delete();
});

/** A pending member the primary invited, their invite live, and their sign-in matched to it: the claim. */
function claimsInvitedClaim(object $test, string $name): UnitClaim
{
    $email = Str::slug($name).'.'.bin2hex(random_bytes(3)).'@claims.test';

    $member = new Resident;
    $member->forceFill(['household_id' => $test->household->id, 'full_name' => $name, 'email' => $email, 'relationship' => 'child', 'is_primary' => false, 'status' => Resident::PENDING])->save();

    (new ResidentInvite(['resident_id' => $member->id, 'unit_id' => $test->unit->id, 'channels' => 'email']))->forceFill([
        'token' => (string) Str::uuid(), 'email' => $email, 'expires_at' => now()->addDays(14), 'attempts' => 1, 'sent_at' => now(), 'last_attempt_at' => now(),
        'invited_by_resident_id' => $test->primary->id, 'invited_by_name' => 'Claims Andrea',
    ])->save();

    $account = ResidentAccount::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'status' => ResidentAccount::PENDING]);

    $claim = Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->run(fn () => app(ResidentInviteAcceptance::class)->accept($account));
    FacilitiesFixture::boot();

    expect($claim)->not->toBeNull();

    return $claim;
}

/** The claims board as the office sees it, one card. */
function claimsCard(object $test, object $user, int $claimId): array
{
    $card = null;

    $test->actingAs($user)->get(FacilitiesFixture::url('/residents/claims'))->assertOk()
        ->assertInertia(function ($page) use ($claimId, &$card): void {
            $card = collect($page->toArray()['props']['claims'])->firstWhere('id', $claimId);
        });
    FacilitiesFixture::boot();

    expect($card)->not->toBeNull();

    return $card;
}

it('marks an invited claim, with who invited and when, the access offered, and what the sign-in proved', function () {
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $claim = claimsInvitedClaim($this, 'Invited Dev');

    $card = claimsCard($this, $office, $claim->id);
    $invited = $card['notes']['invited'];

    expect($invited['via'])->toBe('primary')
        ->and($invited['by'])->toBe('Claims Andrea')
        ->and($invited['by_line'])->toStartWith('Invited by Claims Andrea (primary) on ')
        ->and($invited['level_label'])->toBe('Member')
        ->and($invited['proof_line'])->toContain('Signed in with the invited address')
        ->and($invited['proof_line'])->toContain('proven by a one-time code')
        ->and($invited['proof_line'])->toContain('•••@claims.test')             // masked as the app masks it
        ->and($invited['proof_line'])->not->toContain('invited-dev.')           // never the whole address
        ->and($invited['approve_label'])->toBe('Approve · invited by Claims Andrea')
        ->and($card['notes']['access'])->toMatchArray(['level' => 'member', 'label' => 'Member', 'locked' => false, 'new_entry' => false])
        ->and($card['notes']['revoked_name_on'])->toBeNull();
});

it('says on an ordinary claim what access the approval will give, and draws no Invited block', function () {
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $account = ResidentAccount::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'ordinary.'.bin2hex(random_bytes(3)).'@claims.test', 'status' => ResidentAccount::PENDING]);
    $claim = Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)->run(fn () => app(ResidentAccounts::class)->claim($account, ['full_name' => 'Brand New Person', 'lot' => 'Lot 8876'])['claim']);
    FacilitiesFixture::boot();

    $card = claimsCard($this, $office, $claim->id);

    expect($card['notes']['invited'])->toBeNull()
        ->and($card['notes']['access'])->toMatchArray(['level' => 'member', 'label' => 'Member · a new register entry', 'locked' => false, 'new_entry' => true]);
});

it('approves an invited claim in one press, binding the invite\'s own entry', function () {
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $claim = claimsInvitedClaim($this, 'One Tap Dev');

    $this->actingAs($office)->post(FacilitiesFixture::url('/residents/claims/'.$claim->id.'/approve'))
        ->assertRedirect(FacilitiesFixture::url('/residents/claims'))
        ->assertSessionHasNoErrors();
    FacilitiesFixture::boot();

    $approved = UnitClaim::query()->findOrFail($claim->id);
    expect($approved->status)->toBe(UnitClaim::APPROVED)
        ->and($approved->resolved_resident_id)->toBe(ResidentInvite::query()->findOrFail($claim->invite_id)->resident_id);
});

it('flashes a refused approval with the claim\'s id, so the card that was pressed says why', function () {
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $claim = claimsInvitedClaim($this, 'Left Dev');

    // The invite's person left the household before the office got to it.
    DB::connection('tenant')->table('residents')->where('id', ResidentInvite::query()->findOrFail($claim->invite_id)->resident_id)->update(['removed_at' => now()]);

    $this->actingAs($office)->post(FacilitiesFixture::url('/residents/claims/'.$claim->id.'/approve'))
        ->assertRedirect()
        ->assertSessionHasErrors(['claim_id' => (string) $claim->id]);

    expect(session('errors')->first('claim'))->toContain('Left Dev was removed from the household');
    FacilitiesFixture::boot();
    expect(UnitClaim::query()->findOrFail($claim->id)->status)->toBe(UnitClaim::PENDING);
});
