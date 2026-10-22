<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Estate\Dues;
use App\Services\Estate\HouseholdRemoval;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Removing a household: recoverable, not gone (M21 §3.1, under M18H's model)
|--------------------------------------------------------------------------
|
| A family moves out. Before M21 the office could end each person's app access one at a time and had no way to take
| the household off its lot. Now it is one act, built from account deletion: every member's account is deleted as the
| office deletes one, every member leaves the register, unused passes and future bookings stop, and the lot reads as
| vacant. The ledger and the gate log keep every row — the debt stays with the lot. It can be undone for the estate's
| office window; after that nothing of the people is left, and no row is dropped.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the lot is this file's own and what it makes is taken away afterwards
| (a charge stays: the ledger is append-only, as FacilitiesFixture::scratchUnit says). Lot 989, not Lot 981: 981 is
| ResidentApiContractTest's, whose "Morgan household" stays with ledger lines on it, so this file's firstOrCreate took it
| over, removed it, could not delete it, and the nightly pass then found two (seed 1790905329, 2 October 2026).
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 989');
    Household::withRemoved()->where('unit_id', $this->unit->id)->whereNull('removed_at')->get()
        ->each(fn (Household $h) => Resident::withRemoved()->where('household_id', $h->id)->delete());
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Moving household', 'access_restricted' => false]);

    // A member with an app account, and one without.
    $this->holder = new Resident;
    $this->holder->forceFill(['household_id' => $this->household->id, 'full_name' => 'Pat Moving', 'email' => 'pat.moving.'.bin2hex(random_bytes(3)).'@example.com', 'phone' => '+1 876 555 0981', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    $this->member = new Resident;
    $this->member->forceFill(['household_id' => $this->household->id, 'full_name' => 'Sam Moving', 'email' => null, 'phone' => '+1 876 555 0982', 'relationship' => 'spouse', 'is_primary' => false, 'status' => Resident::VERIFIED])->save();

    $this->account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $this->holder->email, 'full_name' => 'Pat Moving',
        'resident_id' => $this->holder->id, 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
        'device_uid' => 'moving-install-1', 'device_platform' => 'android',
    ]);

    // A pass the member WITHOUT an account sent, still to be used.
    $this->pass = VisitorPass::query()->create([
        'pass_id' => (string) Str::uuid(), 'unit_id' => $this->unit->id, 'household_id' => $this->household->id,
        'issued_by_resident_id' => $this->member->id, 'issued_by_name' => 'Sam Moving', 'category' => 'single',
        'visitor_name' => 'Marcia James', 'visitor_phone' => '876-555-0123', 'purpose' => 'Family visit',
        'valid_from' => Carbon::now()->subHour(), 'valid_to' => Carbon::now()->addDays(3), 'single_use' => true,
        'nonce' => bin2hex(random_bytes(16)), 'key_version' => 1, 'token' => 'move-test-'.bin2hex(random_bytes(6)),
        'code' => 'MOVE-'.random_int(1000, 9999), 'status' => VisitorPass::ACTIVE,
    ]);

    $this->office = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $this->removeUrl = FacilitiesFixture::url('/residents/'.$this->unit->slug().'/household/remove');
});

afterEach(function () {
    Carbon::setTestNow();
    FacilitiesFixture::boot();

    $households = Household::withRemoved()->where('unit_id', $this->unit->id)->pluck('id');
    VisitorPass::query()->whereIn('household_id', $households)->delete();
    Resident::withRemoved()->whereIn('household_id', $households)->delete();
    foreach ($households as $id) {
        try {
            Household::withRemoved()->whereKey($id)->delete();
        } catch (Throwable) {
            // A household a charge points at stays, removed: the ledger is append-only.
        }
    }

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

it('removes the household in one act — accounts deleted, members off the register, passes stopped, the lot vacant — and keeps the debt with the lot', function () {
    app(Dues::class)->charge($this->unit, Money::of('2100.00', 'JMD'), 'Maintenance fee — household removal test', Carbon::now()->subDays(20)->toDateString());
    $owed = app(Dues::class)->unitBalances(null, [$this->unit->id])[$this->unit->id] ?? 0;
    expect($owed)->toBeGreaterThan(0);

    // The lot typed wrong, or no reason: nothing happens.
    $this->actingAs($this->office)->post($this->removeUrl, ['confirm_lot' => 'Lot 98', 'reason' => 'Sold the property; keys handed over.'])->assertSessionHasErrors('confirm_lot');
    $this->actingAs($this->office)->post($this->removeUrl, ['confirm_lot' => 'Lot 989', 'reason' => 'Sold'])->assertSessionHasErrors('reason');
    FacilitiesFixture::boot();
    expect(Household::query()->where('unit_id', $this->unit->id)->exists())->toBeTrue();

    $this->actingAs($this->office)->post($this->removeUrl, ['confirm_lot' => ' lot 989 ', 'reason' => 'Sold the property; keys handed over on the 14th.'])
        ->assertRedirect()->assertSessionHasNoErrors();
    FacilitiesFixture::boot();

    // The lot reads as vacant; the row is still there, removed.
    $row = Household::withRemoved()->findOrFail($this->household->id);
    expect(Household::query()->where('unit_id', $this->unit->id)->exists())->toBeFalse()
        ->and($row->isRemoved())->toBeTrue()
        ->and($row->removal_reference)->not->toBeNull()
        ->and($row->removal_of['accounts'])->toBe([$this->account->id])
        ->and($row->removal_of['residents'])->toEqualCanonicalizing([$this->holder->id, $this->member->id]);

    // The account is deleted as the office deletes one; both members are off the register; the pass is refused.
    $account = ResidentAccount::query()->findOrFail($this->account->id);
    expect($account->isDeleted())->toBeTrue()->and($account->deleted_by)->toBe('office')
        ->and(Resident::query()->whereIn('id', [$this->holder->id, $this->member->id])->count())->toBe(0)
        ->and(Resident::withRemoved()->whereIn('id', [$this->holder->id, $this->member->id])->count())->toBe(2)
        ->and(VisitorPass::query()->findOrFail($this->pass->id)->status)->not->toBe(VisitorPass::ACTIVE);

    // What the lot owes did not move.
    expect(app(Dues::class)->unitBalances(null, [$this->unit->id])[$this->unit->id] ?? 0)->toBe($owed);

    // Audited with what stopped and why — and no person's name.
    $entry = AuditEntry::query()->where('action', 'household.removed')->where('entity_id', (string) $this->household->id)->latest('id')->firstOrFail();
    expect($entry->after['accounts_deleted'])->toBe(1)
        ->and($entry->after['register_entries_removed'])->toBe(2)
        ->and($entry->after['passes_cancelled'])->toBe(1)
        ->and(json_encode([$entry->before, $entry->after]))->not->toContain('Moving');

    // The lot's own page is the vacant lot's.
    $this->actingAs($this->office)->get(FacilitiesFixture::url('/residents/'.$this->unit->slug()))->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('resident.household_name', null));
});

it('lists the removed household on Deleted accounts and restores it — people and accounts back, the lot occupied again', function () {
    $this->actingAs($this->office)->post($this->removeUrl, ['confirm_lot' => 'Lot 989', 'reason' => 'Moved to Montego Bay at the end of the month.'])->assertSessionHasNoErrors();

    $this->actingAs($this->office)->get(FacilitiesFixture::url('/residents/deleted-accounts'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('removedHouseholds', fn ($rows) => collect($rows)->contains(fn (array $r): bool => $r['id'] === $this->household->id && $r['lot'] === 'Lot 989' && $r['members'] === 2 && $r['lot_taken'] === false && $r['days_left'] === 30)));

    $this->actingAs($this->office)->post(FacilitiesFixture::url('/residents/removed-households/'.$this->household->id.'/restore'))->assertRedirect()->assertSessionHas('success');
    FacilitiesFixture::boot();

    expect(Household::query()->where('unit_id', $this->unit->id)->value('id'))->toBe($this->household->id)
        ->and(Resident::query()->whereIn('id', [$this->holder->id, $this->member->id])->count())->toBe(2)
        ->and(ResidentAccount::query()->findOrFail($this->account->id)->isDeleted())->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'household.restored')->where('entity_id', (string) $this->household->id)->exists())->toBeTrue();
});

it('will not put a removed household back on a lot another household has taken, nor after its window — and then nothing of its people is left', function () {
    $this->actingAs($this->office)->post($this->removeUrl, ['confirm_lot' => 'Lot 989', 'reason' => 'Sold the property; keys handed over on the 14th.'])->assertSessionHasNoErrors();
    FacilitiesFixture::boot();

    // A new family on the lot: the old one cannot go back to it.
    $newcomers = Household::query()->create(['unit_id' => $this->unit->id, 'name' => 'Newcomer household', 'access_restricted' => false]);
    // In the restore dialog's band, not a flash that closes it (Hassam, 12 Oct).
    $this->actingAs($this->office)->post(FacilitiesFixture::url('/residents/removed-households/'.$this->household->id.'/restore'))->assertSessionHasErrors('restore')->assertSessionMissing('error');
    FacilitiesFixture::boot();
    expect(Household::withRemoved()->findOrFail($this->household->id)->isRemoved())->toBeTrue();
    $newcomers->delete();

    // Thirty-one days on, the window has closed: the nightly pass leaves nothing of the people, and drops no row.
    Carbon::setTestNow(Carbon::now()->addDays(31));
    expect(app(HouseholdRemoval::class)->anonymiseExpired())->toBe(1);

    $row = Household::withRemoved()->findOrFail($this->household->id);
    $member = Resident::withRemoved()->findOrFail($this->member->id);
    expect($row->name)->toStartWith(HouseholdRemoval::REMOVED_NAME.' · ')
        ->and($row->anonymised_at)->not->toBeNull()
        ->and($member->full_name)->not->toContain('Sam')
        ->and($member->phone)->toBeNull()
        ->and(app(HouseholdRemoval::class)->canRestore($row))->toBeFalse()
        ->and(VisitorPass::query()->whereKey($this->pass->id)->exists())->toBeTrue();

    $this->actingAs($this->office)->post(FacilitiesFixture::url('/residents/removed-households/'.$this->household->id.'/restore'))->assertSessionHasErrors('restore')->assertSessionMissing('error');
});

it('lets only a role that may update Residents remove a household', function () {
    $reader = collect([Role::TREASURER, Role::ESTATE_HEAD_OF_SECURITY, Role::ESTATE_ADMIN_ASSISTANT])
        ->map(fn (string $role) => FacilitiesFixture::viewer($role))
        ->first(fn ($user) => $user->can('estate.residents.view') && ! $user->can('estate.residents.update'));

    if ($reader === null) {
        $this->markTestSkipped('No estate role in this matrix reads Residents without updating it.');
    }

    $this->actingAs($reader)->post($this->removeUrl, ['confirm_lot' => 'Lot 989', 'reason' => 'Sold the property; keys handed over.'])->assertForbidden();
    FacilitiesFixture::boot();
    expect(Household::query()->where('unit_id', $this->unit->id)->exists())->toBeTrue();
});
