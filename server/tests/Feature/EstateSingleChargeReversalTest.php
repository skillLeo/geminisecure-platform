<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Charge;
use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\Journal;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Models\User;
use App\Services\Estate\ChargeSchedules;
use App\Services\Estate\Dues;
use App\Support\Seeded\Seeding;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| ONE CHARGE, REVERSED FROM THE CONSOLE WITH A REASON (M53 §2, the Sunday rehearsal's dues step)
|--------------------------------------------------------------------------
|
| The rehearsal raised a charge, saw it on the household's ledger and could go no further: only a whole month's run
| had Reverse, and one charge needed the server command `ledger:reverse-entries`. Now a charge that owns its entry is
| reversed from its line, in two presses and with a reason, by a role holding the approval reversing a month needs.
| Both entries stay on the statement, the balance comes back, the reason is on the reversing entry and in the audit
| log. A charge inside a month's run, the demonstration's sample and a charge already reversed are refused, and their
| lines carry no Reverse. On this file's own lots, so nobody else's ledger moves.
|
*/

const REVERSAL_LOT = 'Lot 9482';

const REVERSAL_RUN_LOT = 'Lot 9483';

const REVERSAL_PHASE = 'Phase 9482';

const REVERSAL_SCHEDULE = 'Single charge reversal test schedule';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // A site-local morning, when the UTC and Kingston calendars agree (D-143).
    $this->travelTo(Carbon::parse('2026-09-24 10:00', 'America/Jamaica'));
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    $ids = ChargeSchedule::query()->where('description', REVERSAL_SCHEDULE)->pluck('id');
    ChargeRun::query()->whereIn('charge_schedule_id', $ids)->delete();
    ChargeSchedule::query()->whereIn('id', $ids)->delete();
});

/** What a unit owes on the receivable, from its journal lines. */
function reversalUnitNet(Unit $unit): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unit->id])->net;
}

/** A charge on its own, as "Post charge" raises one. */
function reversalCharge(User $by, string $amount = '1500.00'): Charge
{
    return app(Dues::class)->charge(
        unit: FacilitiesFixture::scratchUnit(REVERSAL_LOT),
        amount: Money::of($amount, 'JMD'),
        description: 'Rehearsal fine',
        dueOn: Carbon::parse('2026-09-24'),
        type: 'fine',
        account: '4000',
        by: $by,
    );
}

/** The statement line a charge's entry wrote on its unit's ledger page, as the page receives it. */
function reversalLine(AssertableInertia $page, Charge $charge): array
{
    $line = collect($page->toArray()['props']['statement'])->firstWhere('reference', $charge->journal_ref);
    expect($line)->not->toBeNull();

    return $line;
}

function reversalLedger(Unit $unit): string
{
    return FacilitiesFixture::url('/finance/units/'.$unit->id);
}

it('reverses one charge with a reason: both entries stay, the balance comes back, and the reason is on record', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit(REVERSAL_LOT);
    $before = reversalUnitNet($unit);

    $charge = reversalCharge($treasurer);
    expect(reversalUnitNet($unit))->toBe($before + 150000);

    // Its line offers Reverse, naming the charge for the confirmation.
    $this->actingAs($treasurer)->get(reversalLedger($unit))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($charge): void {
            $page->where('canReverse', true);
            expect(reversalLine($page, $charge)['reverse'])->toMatchArray(['charge' => $charge->id, 'reference' => $charge->reference, 'amount_minor' => 150000]);
        });

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/'.$charge->id.'/reverse'), ['reason' => 'Raised in the rehearsal by mistake'])
        ->assertRedirect(reversalLedger($unit))
        ->assertSessionHas('success');

    $original = Journal::query()->where('reference', $charge->journal_ref)->firstOrFail();
    $mirror = Journal::query()->where('reverses_journal_id', $original->id)->first();

    expect($charge->fresh()->status)->toBe('reversed')
        ->and($mirror)->not->toBeNull()
        ->and($mirror->memo)->toContain($charge->reference)->toContain('Raised in the rehearsal by mistake')->not->toContain('—')
        ->and(reversalUnitNet($unit))->toBe($before)
        ->and(AuditEntry::query()->where('action', 'estate.charge_reversed')->where('entity_id', (string) $charge->id)->exists())->toBeTrue();

    // The line now says Reversed and offers nothing; the mirror is a line of its own beside it.
    $this->actingAs($treasurer)->get(reversalLedger($unit))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($charge, $mirror): void {
            expect(reversalLine($page, $charge))->toMatchArray(['reversed' => true, 'reverse' => null])
                ->and(collect($page->toArray()['props']['statement'])->firstWhere('reference', $mirror->reference))->not->toBeNull();
        });
});

it('refuses without a reason, and refuses a second press', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $charge = reversalCharge($treasurer);
    $reverse = FacilitiesFixture::url('/finance/charges/'.$charge->id.'/reverse');

    $this->actingAs($treasurer)->post($reverse, ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($treasurer)->post($reverse, ['reason' => 'mistake'])->assertSessionHasErrors('reason');
    expect($charge->fresh()->status)->not->toBe('reversed');

    $this->actingAs($treasurer)->post($reverse, ['reason' => 'Raised in the rehearsal by mistake'])->assertSessionHas('success');
    $this->actingAs($treasurer)->post($reverse, ['reason' => 'Raised in the rehearsal by mistake'])->assertSessionHasErrors('reason');

    expect(Journal::query()->where('reverses_journal_id', Journal::query()->where('reference', $charge->journal_ref)->value('id'))->count())->toBe(1);
});

it('leaves a month\'s run to the charge schedule: its charge is refused alone, and its line offers no Reverse', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit(REVERSAL_RUN_LOT, REVERSAL_PHASE);
    $schedules = app(ChargeSchedules::class);

    $schedule = $schedules->create([
        'description' => REVERSAL_SCHEDULE,
        'amount' => '6200.00',
        'scope' => 'phase',
        'phase' => REVERSAL_PHASE,
        'due_day' => 1,
        'account_code' => '4000',
    ], $treasurer);
    $preview = $schedules->preview($schedule, $schedules->nextPeriod($schedule));
    $run = $schedules->post($schedule, $preview['period'], $preview['count'], $preview['total_minor'], $treasurer);

    $charge = Charge::query()->where('journal_ref', $run->journal_ref)->where('unit_id', $unit->id)->firstOrFail();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/'.$charge->id.'/reverse'), ['reason' => 'Billed at the wrong rate this month'])
        ->assertSessionHasErrors(['reason' => $charge->reference.' was posted in a month\'s run, in one entry with other households. A run is reversed whole, from the charge schedule.']);

    expect($charge->fresh()->status)->not->toBe('reversed');

    $this->actingAs($treasurer)->get(reversalLedger($unit))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(reversalLine($page, $charge))->toMatchArray(['reverse' => null, 'reversed' => false]));
});

it('never reverses the demonstration\'s sample, and its line offers no Reverse', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    // Raised as a seeder raises the sample: inside `Seeding::run`, which stamps the row's `seeded_at`.
    $charge = Seeding::run('EstateSingleChargeReversalTest', fn (): Charge => reversalCharge($treasurer));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/'.$charge->id.'/reverse'), ['reason' => 'Raised in the rehearsal by mistake'])
        ->assertSessionHasErrors(['reason' => $charge->reference.' is part of the demonstration\'s sample, which is never reversed.']);

    $this->actingAs($treasurer)->get(reversalLedger(FacilitiesFixture::scratchUnit(REVERSAL_LOT)))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(reversalLine($page, $charge)['reverse'])->toBeNull());
});

it('gives Reverse only to a role holding the approval: absent for the President, who reads the ledger, and refused by URL', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $charge = reversalCharge($treasurer);

    $this->actingAs($president)->get(reversalLedger(FacilitiesFixture::scratchUnit(REVERSAL_LOT)))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canReverse', false));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/finance/charges/'.$charge->id.'/reverse'), ['reason' => 'Raised in the rehearsal by mistake'])
        ->assertForbidden();

    expect($charge->fresh()->status)->not->toBe('reversed');
});
