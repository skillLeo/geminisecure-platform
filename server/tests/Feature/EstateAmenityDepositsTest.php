<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Journal;
use App\Models\Role;
use App\Services\Estate\Amenities;
use App\Services\Estate\Ledger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A deposit always moves on — M4 §4, defect 2
|--------------------------------------------------------------------------
|
| "A deposit is a liability held, not income. A state that never clears means
| money sits in the wrong place on the books forever."
|
| It used to leave `held` only when somebody remembered to press Refund, nothing
| ever completed a booking, and a cancellation moved no money — so 2200 Resident
| Deposits Held carried deposits for parties long over. What is under test here:
|
|   THE STATE MACHINE   awaiting → held → refunded | forfeited, awaiting → none.
|                       Nothing leaves refunded, forfeited or none; nothing
|                       happens twice, even from a stale copy of the booking.
|   THE TRIGGERS        a cancellation inside the terms and a decline give a held
|                       deposit back at once; the morning run completes a
|                       finished booking and gives its deposit back once the
|                       claim window has passed — and never forfeits.
|   THE BOOKS           every entry balances; a release is Dr 2200 / Cr 1000 and
|                       never reaches income; only what the estate KEEPS reaches
|                       4100, and a part kept returns the rest in the same entry.
|   THE RECORD          every move is in the audit log.
|
| EVERY BOOKING HERE IS THIS FILE'S OWN, on the Club House and on a lot no other
| file stands on, at hours typed the way the office types them. The estate
| database is shared by every file in the run and is not rolled back, so nothing
| below counts rows or reads a total: each assertion is about a booking this file
| made, or about the DIFFERENCE its own act made to an account.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // The audit log and the users live in the platform database and are rolled
    // back with it; the estate's own database is not transacted.
    DB::connection('mysql')->beginTransaction();

    $this->amenities = app(Amenities::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** One account's debits less credits, summed longhand — the way `gate:ledger` reads it. */
function depositNet(string $code): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', [$code])->net;
}

/**
 * One entry's lines by account — [code => [debit, credit]] — and proof it balances.
 *
 * @return array<string, array{0: int, 1: int}>
 */
function depositEntry(string $reference): array
{
    $lines = collect(DB::connection('tenant')->select('
        SELECT a.code, SUM(l.debit_minor) AS dr, SUM(l.credit_minor) AS cr
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE l.entry_ref = ?
         GROUP BY a.code
         ORDER BY a.code
    ', [$reference]));

    expect($lines)->not->toBeEmpty('no lines were posted under '.$reference)
        ->and((int) $lines->sum('dr'))->toBe((int) $lines->sum('cr'), $reference.' does not balance');

    return $lines->mapWithKeys(static fn ($line): array => [(string) $line->code => [(int) $line->dr, (int) $line->cr]])->all();
}

/**
 * A Club House booking of this file's own — J$6,000 deposit, awaiting.
 *
 * `$days` from today, negative for a booking that is already over: the service
 * takes a booking for any day (it is the Resident App's controller that refuses
 * the past), which is how an office back-enters a booking made by phone. The
 * hours are typed without a zone, as the office types them.
 */
function depositBooking(Amenities $amenities, int $days, bool $confirmed = true): AmenityBooking
{
    $day = Carbon::today()->addDays($days)->format('Y-m-d');

    $booking = $amenities->book(
        amenity: FacilitiesFixture::amenity('Club House'),
        unit: FacilitiesFixture::scratchUnit('Lot 961'),
        residentName: 'Deposit Walker',
        startsAt: $day.' 11:00',
        endsAt: $day.' 13:00',
        guests: 12,
    );

    return $confirmed ? $amenities->approve($booking) : $booking;
}

/** The audit entries written about one booking's deposit, oldest first. */
function depositAudit(AmenityBooking $booking): array
{
    return AuditEntry::query()
        ->where('entity_type', 'AmenityBooking')
        ->where('entity_id', (string) $booking->id)
        ->orderBy('id')
        ->pluck('action')
        ->all();
}

/* ------------------------------------------------------------------ */
/* released — cancelled inside the terms, or declined */
/* ------------------------------------------------------------------ */

it('gives a held deposit back the moment the household cancels inside its terms', function () {
    $booking = depositBooking($this->amenities, 301);
    $this->amenities->holdDeposit($booking);

    $bank = depositNet('1000');
    $held = depositNet('2200');
    $income = depositNet('4100');

    $this->amenities->cancel($booking);

    $cancelled = AmenityBooking::findOrFail($booking->id);

    expect($cancelled->status)->toBe(AmenityBooking::CANCELLED)
        ->and($cancelled->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and($cancelled->deposit_refunded_on)->not->toBeNull()
        ->and($cancelled->depositKeptMinor())->toBeNull();

    // Liability → cleared: Dr 2200, Cr 1000, the whole deposit, and not a cent of income.
    expect(depositEntry((string) $cancelled->deposit_journal_ref))->toBe([
        '1000' => [0, 6_000_00],
        '2200' => [6_000_00, 0],
    ])
        ->and(depositNet('2200') - $held)->toBe(6_000_00)
        ->and(depositNet('1000') - $bank)->toBe(-6_000_00)
        ->and(depositNet('4100'))->toBe($income);

    $entry = Journal::query()->where('reference', $cancelled->deposit_journal_ref)->firstOrFail();

    expect($entry->source)->toBe(Ledger::SOURCE_DEPOSIT_REFUND)
        ->and($entry->source_id)->toBe($booking->id)
        ->and($entry->memo)->toContain('cancelled the booking inside its terms');

    expect(depositAudit($booking))->toBe(['estate.amenity_deposit_held', 'estate.amenity_deposit_refunded']);

    // Nothing is left to do, by anybody: a second cancel is refused, the office
    // cannot refund it again, and the morning run does not look at it.
    expect(fn () => $this->amenities->cancel($cancelled))->toThrow(DomainException::class, 'nothing to cancel')
        ->and(fn () => $this->amenities->refundDeposit($cancelled))->toThrow(DomainException::class, 'holds no deposit');

    $this->amenities->settle();

    expect(depositNet('2200') - $held)->toBe(6_000_00)
        ->and(Journal::query()->where('source', Ledger::SOURCE_DEPOSIT_REFUND)->where('source_id', $booking->id)->count())->toBe(1);
});

it('refuses a household cancellation inside the notice its booking was taken on, and moves no money', function () {
    // The notice is the booking's own copy of the terms — M22 §1.2 watched the Community Centre's 48 hours refuse it.
    $booking = depositBooking($this->amenities, 327);
    $booking->forceFill(['cancellation_hours' => 48])->save();
    $this->amenities->holdDeposit($booking);

    $held = depositNet('2200');

    expect(fn () => $this->amenities->cancel($booking, $booking->starts_at->copy()->subHours(47)))
        ->toThrow(DomainException::class, 'Bookings are cancelled at least 48 hours ahead');

    $kept = AmenityBooking::findOrFail($booking->id);

    expect($kept->status)->toBe(AmenityBooking::CONFIRMED)
        ->and($kept->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and(depositNet('2200'))->toBe($held);

    // Two hours earlier it was inside the terms: accepted, and the deposit goes back with it.
    $this->amenities->cancel($kept, $booking->starts_at->copy()->subHours(49));

    expect(AmenityBooking::findOrFail($booking->id)->status)->toBe(AmenityBooking::CANCELLED)
        ->and(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED);
});

it('gives a held deposit back when the estate declines the booking', function () {
    // A deposit may be paid while a booking still waits on its decision.
    $booking = depositBooking($this->amenities, 303, confirmed: false);
    $this->amenities->holdDeposit($booking);

    $held = depositNet('2200');
    $income = depositNet('4100');

    $this->amenities->decline($booking, 'The Club House is being re-floored that week.');

    $declined = AmenityBooking::findOrFail($booking->id);

    expect($declined->status)->toBe(AmenityBooking::DECLINED)
        ->and($declined->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and(depositNet('2200') - $held)->toBe(6_000_00)
        ->and(depositNet('4100'))->toBe($income)
        ->and(depositAudit($booking))->toContain('estate.amenity_deposit_refunded');
});

it('stops awaiting a deposit nobody paid once the booking is over, and posts nothing for it', function () {
    $cancelled = depositBooking($this->amenities, 305);
    $declined = depositBooking($this->amenities, 307, confirmed: false);

    expect($cancelled->deposit_state)->toBe(AmenityBooking::DEPOSIT_AWAITING);

    $bank = depositNet('1000');
    $held = depositNet('2200');

    $this->amenities->cancel($cancelled);
    $this->amenities->decline($declined, 'Two bookings for one evening.');

    // "Awaiting payment" under "Cancelled" read as money the household still
    // owed. Nothing was ever posted for it, so there is nothing to reverse.
    expect(AmenityBooking::findOrFail($cancelled->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_NONE)
        ->and(AmenityBooking::findOrFail($declined->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_NONE)
        ->and(AmenityBooking::findOrFail($cancelled->id)->deposit_minor)->toBe(6_000_00)
        ->and(depositNet('1000'))->toBe($bank)
        ->and(depositNet('2200'))->toBe($held)
        ->and(Journal::query()->whereIn('source_id', [$cancelled->id, $declined->id])
            ->whereIn('source', [Ledger::SOURCE_DEPOSIT_HELD, Ledger::SOURCE_DEPOSIT_REFUND, Ledger::SOURCE_DEPOSIT_FORFEIT])
            ->count())->toBe(0)
        ->and(depositAudit($cancelled))->toBe(['estate.amenity_deposit_cleared']);

    // And it is over: a deposit cannot be taken on a booking that will not happen.
    expect(fn () => $this->amenities->holdDeposit(AmenityBooking::findOrFail($cancelled->id)))
        ->toThrow(DomainException::class, 'was cancelled');
});

/* ------------------------------------------------------------------ */
/* forfeited — in whole or in part, by a person, with a reason */
/* ------------------------------------------------------------------ */

it('keeps part of a deposit and returns the rest in one balanced entry', function () {
    $booking = depositBooking($this->amenities, 309);
    $this->amenities->holdDeposit($booking);

    $bank = depositNet('1000');
    $held = depositNet('2200');
    $income = depositNet('4100');

    // Nothing, and more than is held, are both refused — and move nothing.
    expect(fn () => $this->amenities->forfeitDeposit($booking, 'Two chairs broken.', keepMinor: 0))
        ->toThrow(DomainException::class, 'Keeping none of it is a refund')
        ->and(fn () => $this->amenities->forfeitDeposit($booking, 'Two chairs broken.', keepMinor: 6_000_01))
        ->toThrow(DomainException::class, 'no more')
        ->and(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and(depositNet('2200'))->toBe($held);

    $this->amenities->forfeitDeposit($booking, 'Two chairs broken.', keepMinor: 2_000_00);

    $kept = AmenityBooking::findOrFail($booking->id);

    expect($kept->deposit_state)->toBe(AmenityBooking::DEPOSIT_FORFEITED)
        ->and($kept->deposit_forfeited_minor)->toBe(2_000_00)
        ->and($kept->depositKeptMinor())->toBe(2_000_00)
        ->and($kept->deposit_minor)->toBe(6_000_00)
        ->and($kept->deposit_refunded_on)->not->toBeNull()
        ->and($kept->depositLabel())->toBe('$2,000 of $6,000 forfeited');

    // ONE entry: the whole liability gone, only what was kept in income, the rest back out of the bank.
    expect(depositEntry((string) $kept->deposit_journal_ref))->toBe([
        '1000' => [0, 4_000_00],
        '2200' => [6_000_00, 0],
        '4100' => [0, 2_000_00],
    ])
        ->and(depositNet('2200') - $held)->toBe(6_000_00)
        ->and(depositNet('4100') - $income)->toBe(-2_000_00)
        ->and(depositNet('1000') - $bank)->toBe(-4_000_00);

    $closed = $this->amenities->bookingBoard($kept)['deposit']['timeline'][2];

    expect($closed['label'])->toBe('Forfeited · $2,000 kept, $4,000 returned')
        ->and($closed['line'])->toContain('Two chairs broken.');

    $audit = AuditEntry::query()->where('action', 'estate.amenity_deposit_forfeited')->where('entity_id', (string) $booking->id)->firstOrFail();

    expect($audit->after['kept_minor'])->toBe(2_000_00)
        ->and($audit->after['refunded_minor'])->toBe(4_000_00)
        ->and($audit->after['reason'])->toBe('Two chairs broken.')
        ->and($audit->before['deposit_state'])->toBe(AmenityBooking::DEPOSIT_HELD);
});

it('keeps the whole deposit when no part is named, exactly as before — and says how much', function () {
    $booking = depositBooking($this->amenities, 311);
    $this->amenities->holdDeposit($booking);

    $bank = depositNet('1000');

    $this->amenities->forfeitDeposit($booking, 'Club House left unusable for the next booking.');

    $kept = AmenityBooking::findOrFail($booking->id);

    expect($kept->depositKeptMinor())->toBe(6_000_00)
        ->and($kept->deposit_refunded_on)->toBeNull()
        ->and($kept->depositLabel())->toBe('$6,000 forfeited')
        ->and(depositEntry((string) $kept->deposit_journal_ref))->toBe([
            '2200' => [6_000_00, 0],
            '4100' => [0, 6_000_00],
        ])
        // No cash moves: it has been in the bank since it was taken.
        ->and(depositNet('1000'))->toBe($bank);
});

it('forfeits part of a deposit through the door, behind the approver gate', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);

    $booking = depositBooking($this->amenities, 313);
    $this->amenities->holdDeposit($booking);

    $detail = FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id);
    $income = depositNet('4100');

    // Update without approve may not keep any of it, part or whole.
    $this->actingAs($assistant)
        ->post($detail.'/deposit/forfeit', ['reason' => 'Stage curtain torn.', 'amount' => '1500'])
        ->assertForbidden();

    FacilitiesFixture::boot();

    // More than is held is the service's refusal, back beside the reason.
    $this->actingAs($manager)
        ->post($detail.'/deposit/forfeit', ['reason' => 'Stage curtain torn.', 'amount' => '6000.01'])
        ->assertSessionHasErrors('reason');

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD);

    $this->actingAs($manager)
        ->post($detail.'/deposit/forfeit', ['reason' => 'Stage curtain torn.', 'amount' => '1500.50'])
        ->assertRedirect($detail)
        // The cents are said (the amenity money proof, 12 Oct): the flash read "$1,500 of the $6,000" for $1,500.50 kept.
        ->assertSessionHas('success', fn (string $flash): bool => str_contains($flash, '$1,500.50 of the $6,000'));

    FacilitiesFixture::boot();

    $kept = AmenityBooking::findOrFail($booking->id);

    expect($kept->deposit_state)->toBe(AmenityBooking::DEPOSIT_FORFEITED)
        ->and($kept->deposit_forfeited_minor)->toBe(1_500_50)
        ->and(depositNet('4100') - $income)->toBe(-1_500_50)
        ->and(depositEntry((string) $kept->deposit_journal_ref)['1000'])->toBe([0, 4_499_50]);

    // The audit entry names the person who decided it.
    $audit = AuditEntry::query()->where('action', 'estate.amenity_deposit_forfeited')->where('entity_id', (string) $booking->id)->firstOrFail();

    expect($audit->actor_id)->toBe($manager->id);
});

/* ------------------------------------------------------------------ */
/* the state machine */
/* ------------------------------------------------------------------ */

it('never moves a deposit out of a state it ends in', function () {
    $refunded = depositBooking($this->amenities, 315);
    $this->amenities->holdDeposit($refunded);
    $this->amenities->refundDeposit($refunded);

    $forfeited = depositBooking($this->amenities, 317);
    $this->amenities->holdDeposit($forfeited);
    $this->amenities->forfeitDeposit($forfeited, 'Glass doors cracked.', keepMinor: 1_000_00);

    $cleared = depositBooking($this->amenities, 319);
    $this->amenities->cancel($cleared);

    $bank = depositNet('1000');
    $held = depositNet('2200');
    $income = depositNet('4100');

    foreach ([$refunded, $forfeited, $cleared] as $booking) {
        $booking = AmenityBooking::findOrFail($booking->id);

        expect(AmenityBooking::DEPOSIT_TERMINAL)->toContain($booking->deposit_state)
            ->and(fn () => $this->amenities->holdDeposit($booking))->toThrow(DomainException::class)
            ->and(fn () => $this->amenities->refundDeposit($booking))->toThrow(DomainException::class)
            ->and(fn () => $this->amenities->forfeitDeposit($booking, 'Again.'))->toThrow(DomainException::class);
    }

    // The morning run leaves all three alone as well, today and a year from now.
    $this->amenities->settle(dryRun: true);

    $report = $this->amenities->settle(Carbon::now()->addYear(), dryRun: true);
    $lines = implode("\n", [...$report['released'], ...$report['cleared'], ...$report['failed']]);

    foreach ([$refunded, $forfeited, $cleared] as $booking) {
        expect($lines)->not->toContain($booking->reference);
    }

    expect(depositNet('1000'))->toBe($bank)
        ->and(depositNet('2200'))->toBe($held)
        ->and(depositNet('4100'))->toBe($income);
});

it('releases a deposit once, even from two copies of the booking that both read "held"', function () {
    $booking = depositBooking($this->amenities, 321);
    $this->amenities->holdDeposit($booking);

    // Two requests that each loaded the booking before the other finished: the
    // office pressing Refund while the morning run is on the same row.
    $first = AmenityBooking::findOrFail($booking->id);
    $second = AmenityBooking::findOrFail($booking->id);

    $held = depositNet('2200');

    $this->amenities->refundDeposit($first);

    expect($second->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD);

    // The row is read again under a lock, so the stale copy is refused …
    expect(fn () => $this->amenities->refundDeposit($second))->toThrow(DomainException::class, 'A deposit moves on once')
        ->and(fn () => $this->amenities->forfeitDeposit($second, 'Late claim.'))->toThrow(DomainException::class, 'A deposit moves on once');

    // … and the liability moved once, by one deposit, in one entry.
    expect(depositNet('2200') - $held)->toBe(6_000_00)
        ->and(Journal::query()->where('source_id', $booking->id)
            ->whereIn('source', [Ledger::SOURCE_DEPOSIT_REFUND, Ledger::SOURCE_DEPOSIT_FORFEIT])->count())->toBe(1)
        ->and(depositAudit($booking))->toBe(['estate.amenity_deposit_held', 'estate.amenity_deposit_refunded']);
});

it('will not cancel or complete a party that has already happened, and moves no money trying', function () {
    $booking = depositBooking($this->amenities, -14);
    $this->amenities->holdDeposit($booking);

    $bank = depositNet('1000');
    $held = depositNet('2200');
    $income = depositNet('4100');

    $this->amenities->complete($booking);

    // A SECOND COMPLETION DESCRIBES AN EVENT THAT HAS ALREADY ENDED, and the
    // morning run reaches this method on every booking whose time is up.
    expect(fn () => $this->amenities->complete($booking))
        ->toThrow(DomainException::class, 'Only a confirmed booking can complete')

        /*
         * AND THE HOUSEHOLD CANNOT CANCEL A PARTY THAT HAS HAPPENED. This
         * mattered less when a cancellation moved no money; now that one
         * releases the deposit in the same breath (M4 §4, defect 2), a cancel
         * accepted after the event would end the estate's claim window early
         * and hand back money it may still be owed for the damage.
         */
        ->and(fn () => $this->amenities->cancel($booking))
        ->toThrow(DomainException::class, 'so there is nothing to cancel');

    $after = AmenityBooking::findOrFail($booking->id);

    expect($after->status)->toBe(AmenityBooking::COMPLETED)
        ->and($after->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($after->cancelled_at)->toBeNull()
        ->and(depositNet('1000'))->toBe($bank)
        ->and(depositNet('2200'))->toBe($held)
        ->and(depositNet('4100'))->toBe($income);

    // The claim window is the estate's to use; when it ends the money goes back,
    // and a refusal on the way never sent any of it to income.
    $this->amenities->refundDeposit($booking);

    expect(depositNet('4100'))->toBe($income)
        ->and(depositNet('2200') - $held)->toBe(6_000_00)
        ->and(depositAudit($booking))->toBe(['estate.amenity_deposit_held', 'estate.amenity_deposit_refunded']);
});

/* ------------------------------------------------------------------ */
/* the morning run */
/* ------------------------------------------------------------------ */

it('completes a finished booking, holds its deposit for the claim window, then gives it back — once', function () {
    // Yesterday's party: over, and still inside the three days the estate has to
    // find the broken chairs. Last week's: over, and nobody claimed anything.
    $yesterday = depositBooking($this->amenities, -1);
    $lastWeek = depositBooking($this->amenities, -6);

    // Last week's other party, where somebody DID claim — a person, with a reason.
    $claimed = depositBooking($this->amenities, -8);

    // And one that is still to come, which the run has no business touching.
    $upcoming = depositBooking($this->amenities, 323);

    foreach ([$yesterday, $lastWeek, $claimed, $upcoming] as $booking) {
        $this->amenities->holdDeposit($booking);
    }

    $this->amenities->complete($claimed);
    $this->amenities->forfeitDeposit($claimed, 'Kitchen left unusable.', keepMinor: 3_000_00);

    $bank = depositNet('1000');
    $held = depositNet('2200');
    $income = depositNet('4100');

    // A DRY RUN SAYS WHAT WOULD MOVE AND MOVES NOTHING.
    $dry = $this->amenities->settle(dryRun: true);

    expect(implode("\n", $dry['completed']))->toContain($yesterday->reference)->toContain($lastWeek->reference)
        ->and(implode("\n", $dry['released']))->toContain($lastWeek->reference)->not->toContain($yesterday->reference)
        ->and(AmenityBooking::findOrFail($lastWeek->id)->status)->toBe(AmenityBooking::CONFIRMED)
        ->and(AmenityBooking::findOrFail($lastWeek->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and(depositNet('2200'))->toBe($held);

    $report = $this->amenities->settle();

    expect($report['failed'])->toBe([])
        ->and(implode("\n", $report['completed']))->toContain($yesterday->reference)->toContain($lastWeek->reference)
        ->and(implode("\n", $report['released']))->toContain($lastWeek->reference)
        ->not->toContain($yesterday->reference)
        ->not->toContain($claimed->reference)
        ->not->toContain($upcoming->reference);

    $yesterday = AmenityBooking::findOrFail($yesterday->id);
    $lastWeek = AmenityBooking::findOrFail($lastWeek->id);

    // Completed, both. Only the one past its claim window is released.
    expect($yesterday->status)->toBe(AmenityBooking::COMPLETED)
        ->and($yesterday->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($lastWeek->status)->toBe(AmenityBooking::COMPLETED)
        ->and($lastWeek->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and(AmenityBooking::findOrFail($claimed->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_FORFEITED)
        ->and(AmenityBooking::findOrFail($upcoming->id)->status)->toBe(AmenityBooking::CONFIRMED)
        ->and(AmenityBooking::findOrFail($upcoming->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD);

    // Liability → cleared, and NEVER income: the machine only ever gives money back.
    expect(depositEntry((string) $lastWeek->deposit_journal_ref))->toBe([
        '1000' => [0, 6_000_00],
        '2200' => [6_000_00, 0],
    ])
        ->and(depositNet('2200') - $held)->toBe(6_000_00)
        ->and(depositNet('1000') - $bank)->toBe(-6_000_00)
        ->and(depositNet('4100'))->toBe($income)
        ->and(Journal::query()->where('reference', $lastWeek->deposit_journal_ref)->firstOrFail()->memo)->toContain('no claim was made within 3 days')
        ->and(depositAudit($lastWeek))->toBe(['estate.amenity_deposit_held', 'estate.amenity_deposit_refunded']);

    // AGAIN, AND NOTHING HAPPENS: no second completion, no second refund.
    $again = $this->amenities->settle();
    $lines = implode("\n", [...$again['completed'], ...$again['released'], ...$again['cleared'], ...$again['failed']]);

    expect($lines)->not->toContain($lastWeek->reference)->not->toContain($yesterday->reference)
        ->and(depositNet('2200') - $held)->toBe(6_000_00)
        ->and(Journal::query()->where('source', Ledger::SOURCE_DEPOSIT_REFUND)->where('source_id', $lastWeek->id)->count())->toBe(1);

    // Yesterday's is given back when ITS window has passed — asked of the rule
    // with the clock moved on, as a dry run, so no other file's booking is swept.
    $later = $this->amenities->settle(Carbon::now()->addDays(Amenities::CLAIM_WINDOW_DAYS), dryRun: true);

    expect(implode("\n", $later['released']))->toContain($yesterday->reference);

    // Leave nothing held in the past behind for the files that run after this one.
    $this->amenities->refundDeposit($yesterday);
    $this->amenities->refundDeposit(AmenityBooking::findOrFail($upcoming->id));
});

it('clears an unpaid deposit on a booking that ran without one', function () {
    $booking = depositBooking($this->amenities, -10);

    $held = depositNet('2200');

    $report = $this->amenities->settle();

    expect(implode("\n", $report['cleared']))->toContain($booking->reference)
        ->and(AmenityBooking::findOrFail($booking->id)->status)->toBe(AmenityBooking::COMPLETED)
        ->and(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_NONE)
        ->and(depositNet('2200'))->toBe($held);
});

/*
 * THE TWO WAYS OUT NOBODY PRESSES A BUTTON FOR.
 *
 * Every test above moves a deposit on through an act somebody performed: a cancel, a
 * decline, a refund, a forfeit, or a booking that was confirmed and then ran. The two cases
 * below are the ones where NOBODY EVER DID ANYTHING, which is exactly how a deposit used to
 * get stuck (M4 §4, defect 2), and the only remaining way a state could have no way out.
 */

it('moves the deposit on a booking nobody ever decided, once its day has gone', function () {
    // Never approved, never declined — the office simply never answered. The day passed anyway.
    $held = depositBooking($this->amenities, -4, confirmed: false);
    $this->amenities->holdDeposit($held);

    $awaiting = depositBooking($this->amenities, -5, confirmed: false);

    expect(AmenityBooking::findOrFail($held->id)->status)->toBe(AmenityBooking::PENDING)
        ->and(AmenityBooking::findOrFail($held->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($awaiting->status)->toBe(AmenityBooking::PENDING)
        ->and($awaiting->deposit_state)->toBe(AmenityBooking::DEPOSIT_AWAITING);

    // Income never moves on a release, whatever else the morning run sweeps up alongside these two.
    $income = depositNet('4100');

    $report = $this->amenities->settle();

    /*
     * The run does not COMPLETE a booking nobody confirmed — completing an event the estate
     * never agreed to would be a lie on the record. It EXPIRES it (M53 §2): a request nobody
     * decided before its time, never called declined. And the money moves on regardless: the
     * held one goes back (its claim window is long past), the quoted one stops being awaited.
     */
    expect(implode("\n", $report['expired']))->toContain($held->reference)->toContain($awaiting->reference)
        ->and(implode("\n", $report['released']))->toContain($held->reference)
        ->and(implode("\n", $report['cleared']))->toContain($awaiting->reference)
        ->and(AmenityBooking::findOrFail($held->id)->status)->toBe(AmenityBooking::EXPIRED)
        ->and(AmenityBooking::findOrFail($held->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and(AmenityBooking::findOrFail($awaiting->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_NONE)
        ->and(depositNet('4100'))->toBe($income);

    // The liability cleared in one balanced entry, and not a cent of it to income.
    expect(depositEntry((string) AmenityBooking::findOrFail($held->id)->deposit_journal_ref))->toBe([
        '1000' => [0, 6_000_00],
        '2200' => [6_000_00, 0],
    ]);

    // Nothing was ever posted for the one that was only quoted, so there is nothing to reverse.
    expect(Journal::query()->where('source_id', $awaiting->id)
        ->whereIn('source', [Ledger::SOURCE_DEPOSIT_HELD, Ledger::SOURCE_DEPOSIT_REFUND, Ledger::SOURCE_DEPOSIT_FORFEIT])
        ->count())->toBe(0);

    // And again: both are in a state nothing leaves, so a second run says nothing about either.
    $again = $this->amenities->settle();
    $lines = implode("\n", [...$again['released'], ...$again['cleared'], ...$again['failed']]);

    expect($lines)->not->toContain($held->reference)->not->toContain($awaiting->reference)
        ->and(Journal::query()->where('source', Ledger::SOURCE_DEPOSIT_REFUND)->where('source_id', $held->id)->count())->toBe(1);
});

it('will not let an amenity be deleted out from under a deposit it is still holding', function () {
    $booking = depositBooking($this->amenities, 325);
    $this->amenities->holdDeposit($booking);

    $liability = depositNet('2200');

    /*
     * Nothing in the console deletes an amenity, and the estate's own schema refuses it as
     * well — `amenity_bookings.amenity_id` is RESTRICT, not CASCADE. A booking that vanished
     * with its amenity would take a live liability off the books with no entry behind it, and
     * 2200 Resident Deposits Held would be overstated for ever with nothing left to explain it.
     */
    expect(fn () => DB::connection('tenant')->table('amenities')->where('id', $booking->amenity_id)->delete())
        ->toThrow(QueryException::class);

    expect(AmenityBooking::query()->whereKey($booking->id)->exists())->toBeTrue()
        ->and(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and(depositNet('2200'))->toBe($liability);

    // Left in a state it can leave, for the files that run after this one.
    $this->amenities->refundDeposit(AmenityBooking::findOrFail($booking->id));
});

it('runs from the scheduler every morning, one estate at a time, and a dry run moves nothing', function () {
    $booking = depositBooking($this->amenities, -12);
    $this->amenities->holdDeposit($booking);

    // ONE ESTATE, NAMED. The platform's test database may list other estates
    // whose databases this suite has no business walking.
    $this->artisan('amenities:settle', ['--estate' => FacilitiesFixture::ESTATE, '--dry-run' => true])
        ->expectsOutputToContain('would be released')
        ->assertSuccessful();

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and(AmenityBooking::findOrFail($booking->id)->status)->toBe(AmenityBooking::CONFIRMED);

    $this->artisan('amenities:settle', ['--estate' => FacilitiesFixture::ESTATE])
        ->expectsOutputToContain($booking->reference)
        ->assertSuccessful();

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and(AmenityBooking::findOrFail($booking->id)->status)->toBe(AmenityBooking::COMPLETED);

    $scheduled = collect(app(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains((string) $event->command, 'amenities:settle'));

    // Every fifteen minutes, taking each estate at 06:30 on its OWN clock, once per its date (M11 §4.2,
    // LocalSchedule) — not one server hour for every estate; TwoEstatesAcrossDaylightSavingTest proves the hour.
    expect($scheduled)->not->toBeNull('amenities:settle is not on the scheduler')
        ->and($scheduled->expression)->toBe('*/15 * * * *')
        ->and((string) $scheduled->command)->toContain('--at=06:30');
});

it('gives a suspended client\'s households their deposits back, because a deposit is not a billing feature', function () {
    $booking = depositBooking($this->amenities, -13);
    $this->amenities->holdDeposit($booking);

    /*
     * THE CLIENT HAS STOPPED PAYING US. That is a commercial state and it says nothing
     * about whose money this is: the platform's own rule is that `dunning` and `suspended`
     * "gate BILLING features and never entry or a safety function" (`ClientDirectory`,
     * `Subscription`). The household's J$6,000 is still the household's.
     *
     * The morning run used to select `status = 'active'` only — copied from `dunning:run`,
     * where it is right — so a suspended estate's deposits sat on 2200 Resident Deposits
     * Held for as long as the dispute lasted. That is the defect of M4 §4 with a different
     * cause, and this is the test that fails if the selection is narrowed back.
     *
     * The estate row is central and rolls back with this file's transaction.
     */
    DB::connection('mysql')->table('tenants')
        ->where('id', FacilitiesFixture::ESTATE)
        ->update(['status' => 'suspended']);

    FacilitiesFixture::boot();

    $this->artisan('amenities:settle', ['--estate' => FacilitiesFixture::ESTATE])
        ->expectsOutputToContain($booking->reference)
        ->assertSuccessful();

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and(AmenityBooking::findOrFail($booking->id)->status)->toBe(AmenityBooking::COMPLETED)

        // And settling it did not quietly put the client back in good standing.
        ->and(DB::connection('mysql')->table('tenants')->where('id', FacilitiesFixture::ESTATE)->value('status'))->toBe('suspended');
});

it('passes over an estate whose database was never built, rather than failing the whole run', function () {
    /*
     * The widened selection can now reach a row recorded without the provisioning pipeline
     * having run. A nightly job that exits non-zero for ever because of one half-made record
     * is a job people stop reading, so it is named and passed over.
     */
    App\Models\Tenant::withoutEvents(function (): void {
        (new App\Models\Tenant)->forceFill([
            'id' => 'nodatabasetest', 'name' => 'No Database Estate', 'receipt_prefix' => 'NDB', 'status' => 'active',
        ])->save();
    });

    FacilitiesFixture::boot();

    $this->artisan('amenities:settle', ['--estate' => 'nodatabasetest'])
        ->expectsOutputToContain('no database yet')
        ->assertSuccessful();

    FacilitiesFixture::boot();
});
