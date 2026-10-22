<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Household;
use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A phase, and its lots, go only when nothing hangs off either (M18H §7.1, M33 §4.1)
|--------------------------------------------------------------------------
|
| "asdc" was somebody trying Structure → Add phase on Phoenix Park. A phase like that, and the lots it was given, can be
| removed — deliberately, by a command, dry-run first, and audited — when no lot has a household, a resident, a charge,
| a pass or any other record on it, and nothing else names the phase. Anything that does is listed and stays.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->phases = [];
    $this->lots = [];
});

afterEach(function () {
    FacilitiesFixture::boot();
    $units = Unit::query()->whereIn('reference', $this->lots)->pluck('id')->all();
    $households = Household::withRemoved()->whereIn('unit_id', $units)->pluck('id')->all();
    DB::connection('tenant')->table('residents')->whereIn('household_id', $households)->delete();
    DB::connection('tenant')->table('charges')->whereIn('unit_id', $units)->delete();
    DB::connection('tenant')->table('unit_claims')->whereIn('unit_id', $units)->delete();
    DB::connection('tenant')->table('households')->whereIn('id', $households)->delete();
    Unit::query()->whereIn('id', $units)->delete();
    Phase::query()->whereIn('name', $this->phases)->delete();
    DB::connection('mysql')->rollBack();
});

function scratchPhase(object $test, string $name): Phase
{
    FacilitiesFixture::boot();
    $test->phases[] = $name;

    return Phase::query()->create(['name' => $name, 'sequence' => 900 + count($test->phases), 'block_count' => 0, 'status' => Phase::NEW]);
}

/** @return list<Unit> lots of this phase, numbered from $from, with nothing on them */
function scratchLots(object $test, Phase $phase, int $from, int $count): array
{
    $lots = [];
    for ($i = 0; $i < $count; $i++) {
        $reference = 'Lot '.($from + $i);
        $test->lots[] = $reference;
        $lots[] = FacilitiesFixture::scratchUnit($reference, $phase->name);
    }

    return $lots;
}

it('removes a phase nothing names, only with --confirm, and audits it', function () {
    $phase = scratchPhase($this, 'zz asdc '.bin2hex(random_bytes(2)));

    $this->artisan('estate:remove-phase', ['--estate' => FacilitiesFixture::ESTATE, '--phase' => $phase->name])
        ->expectsOutputToContain('it has no lots')
        ->expectsOutputToContain('Nothing was changed')
        ->assertSuccessful();
    FacilitiesFixture::boot();
    expect(Phase::query()->whereKey($phase->id)->exists())->toBeTrue('a dry run changes nothing');

    $this->artisan('estate:remove-phase', ['--estate' => FacilitiesFixture::ESTATE, '--phase' => $phase->name, '--confirm' => true])
        ->expectsOutputToContain('Removed')
        ->assertSuccessful();
    FacilitiesFixture::boot();

    expect(Phase::query()->whereKey($phase->id)->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'estate.phase_removed')->where('entity_id', (string) $phase->id)->exists())->toBeTrue();
});

it('takes a phase\'s bare lots with it, printing every lot and the units it gives back, and only with --confirm', function () {
    $phase = scratchPhase($this, 'zz bare '.bin2hex(random_bytes(2)));
    $base = 70_000 + random_int(0, 9_000);
    $lots = scratchLots($this, $phase, $base, 4);
    FacilitiesFixture::boot();
    $inUse = Unit::query()->count();
    // A contract with room for nothing more: the four lots it takes back are the four the estate then has left.
    $plan = Plan::query()->firstOrCreate(['key' => 'm33-phase'], ['name' => 'Standard', 'price_per_unit_minor' => 260_00, 'currency' => 'JMD', 'min_units' => 1, 'is_active' => true, 'sort' => 96]);
    Subscription::query()->updateOrCreate(['tenant_id' => FacilitiesFixture::ESTATE], ['plan_id' => $plan->id, 'unit_count' => $inUse, 'status' => 'active']);

    $this->artisan('estate:remove-phase', ['--estate' => FacilitiesFixture::ESTATE, '--phase' => $phase->name])
        ->expectsOutputToContain('its 4 lots have nothing on them')
        ->expectsOutputToContain('The lots it would remove: Lot '.$base.' to Lot '.($base + 3).' (4 lots)')
        ->expectsOutputToContain(number_format($inUse).' contracted; in use '.number_format($inUse).' → '.number_format($inUse - 4).'; left 0 → 4. It gives back 4 units.')
        ->expectsOutputToContain('Not reversible by this command')
        ->expectsOutputToContain('Nothing was changed')
        ->assertSuccessful();
    FacilitiesFixture::boot();
    expect(Unit::query()->whereIn('id', array_map(static fn (Unit $u): int => $u->id, $lots))->count())->toBe(4);

    $this->artisan('estate:remove-phase', ['--estate' => FacilitiesFixture::ESTATE, '--phase' => $phase->name, '--confirm' => true])
        ->expectsOutputToContain('with its 4 lots')
        ->assertSuccessful();
    FacilitiesFixture::boot();

    $audit = AuditEntry::query()->where('action', 'estate.phase_removed')->where('entity_id', (string) $phase->id)->firstOrFail();
    expect(Unit::query()->where('block', $phase->name)->count())->toBe(0)
        ->and(Phase::query()->whereKey($phase->id)->exists())->toBeFalse()
        ->and(Unit::query()->count())->toBe($inUse - 4)
        ->and(collect($audit->before['lots'])->pluck('reference')->all())->toBe(array_map(static fn (int $n): string => 'Lot '.$n, range($base, $base + 3)))
        ->and($audit->after['units_given_back'])->toBe(4);
});

it('refuses a phase whose lot has anything on it, naming the lot and what, and changes nothing', function (string $what, Closure $put, string $said) {
    $phase = scratchPhase($this, 'zz used '.bin2hex(random_bytes(2)));
    [$bare, $used] = scratchLots($this, $phase, 80_000 + random_int(0, 9_000), 2);
    FacilitiesFixture::boot();
    $put($used);
    FacilitiesFixture::boot();

    $this->artisan('estate:remove-phase', ['--estate' => FacilitiesFixture::ESTATE, '--phase' => $phase->name, '--confirm' => true])
        ->expectsOutputToContain('its lots are in use. Nothing was changed.')
        ->expectsOutputToContain($used->reference.': '.$said)
        ->assertFailed();
    FacilitiesFixture::boot();

    expect(Phase::query()->whereKey($phase->id)->exists())->toBeTrue()
        ->and(Unit::query()->whereKey([$bare->id, $used->id])->count())->toBe(2, 'not even the bare lot goes');
})->with([
    'a household' => ['household', fn (Unit $lot) => Household::query()->create(['unit_id' => $lot->id, 'name' => 'Phase test household', 'access_restricted' => false]), '1 household'],
    'a resident' => ['resident', function (Unit $lot) {
        $household = Household::query()->create(['unit_id' => $lot->id, 'name' => 'Phase test household', 'access_restricted' => false]);
        $person = new Resident;
        $person->forceFill(['household_id' => $household->id, 'full_name' => 'Phase Test Person', 'email' => 'phase.test@m33.test', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    }, '1 household, 1 resident'],
    'a charge' => ['charge', fn (Unit $lot) => DB::connection('tenant')->table('charges')->insert([
        'unit_id' => $lot->id, 'type' => 'dues', 'reference' => 'M33-PHASE-'.bin2hex(random_bytes(4)), 'description' => 'A test charge',
        'amount_minor' => 1_000_00, 'currency' => 'JMD', 'due_on' => now()->toDateString(), 'status' => 'outstanding', 'created_at' => now(), 'updated_at' => now(),
    ]), '1 charge'],
    'any other record that points at a lot' => ['claim', fn (Unit $lot) => UnitClaim::query()->create([
        'unit_id' => $lot->id, 'claim_type' => UnitClaim::TYPE_UNIT, 'submitted_name' => 'Phase Test Claimant', 'submitted_phone' => '876 555 0999', 'match_result' => 'none', 'status' => UnitClaim::PENDING,
    ]), '1 row in unit_claims'],
]);

it('keeps a phase that a notice was for, whatever its lots', function () {
    $phase = scratchPhase($this, 'zz named '.bin2hex(random_bytes(2)));
    FacilitiesFixture::boot();
    DB::connection('tenant')->table('notices')->insert(['title' => 'For the phase', 'body' => 'A test notice.', 'audience_scope' => 'phase', 'audience_phase' => $phase->name, 'author_name' => 'Phase Test', 'kind' => 'general', 'created_at' => now(), 'updated_at' => now()]);

    try {
        $this->artisan('estate:remove-phase', ['--estate' => FacilitiesFixture::ESTATE, '--phase' => $phase->name, '--confirm' => true])
            ->expectsOutputToContain('1 notice name it')
            ->assertFailed();
    } finally {
        FacilitiesFixture::boot();
        DB::connection('tenant')->table('notices')->where('audience_phase', $phase->name)->delete();
    }

    expect(Phase::query()->whereKey($phase->id)->exists())->toBeTrue();
});

it('lists every phase with its lots and what names it, refuses a name it does not have, and refuses to guess', function () {
    scratchPhase($this, 'zz listed '.bin2hex(random_bytes(2)));

    $this->artisan('estate:remove-phase', ['--estate' => FacilitiesFixture::ESTATE, '--list' => true])->assertSuccessful();
    $this->artisan('estate:remove-phase', ['--estate' => FacilitiesFixture::ESTATE, '--phase' => 'no such phase '.bin2hex(random_bytes(3))])->assertFailed();
    $this->artisan('estate:remove-phase', ['--estate' => FacilitiesFixture::ESTATE])->expectsOutputToContain('Name the phase')->assertFailed();
    $this->artisan('estate:remove-phase', [])->expectsOutputToContain('Say which estate')->assertFailed();
});

it('still takes the estate and the phase in order, as M18H wrote it', function () {
    $phase = scratchPhase($this, 'zz order '.bin2hex(random_bytes(2)));

    $this->artisan('estate:remove-phase', ['estate' => FacilitiesFixture::ESTATE, 'phase' => $phase->name])
        ->expectsOutputToContain('Nothing was changed')
        ->assertSuccessful();
});
