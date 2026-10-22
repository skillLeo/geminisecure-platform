<?php

declare(strict_types=1);

use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\AmenitySlot;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Journal;
use App\Models\Role;
use App\Services\Estate\Amenities;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The booking diary and the rate card behind it — boards 19 and 20
|--------------------------------------------------------------------------
|
| TWO GUARANTEES ARE UNDER TEST HERE AND EVERYTHING ELSE IS SUPPORT.
|
| The first is that A BOOKING CARRIES THE TERMS IT WAS MADE UNDER. The fee, the
| deposit and the cancellation rule are copied off the amenity when the booking
| is made and never read back through the relation. A booking that read the
| amenity live would restate every held deposit in the estate the moment a
| manager typed a new figure into board 20 — and the estate would then owe
| residents an amount its own books no longer showed. So the rate card is edited
| below, under a deposit the estate is already holding, and the deposit must not
| move by one cent.
|
| The second is that AN AMENITY FEE IS MONEY, and money is proven by lines. Not
| "the number renders": the J$3,000 on board 19 equals the debit posted to 1200
| Dues Receivable, equals the credit posted to 4100 Amenity Booking Fees, equals
| the movement in that unit's own sub-ledger. Each of those is summed here in raw
| SQL written out longhand, so the expectation and the application reach the same
| figure by two different routes — and the entry behind them is then attacked
| live, inside rolled-back transactions, the way `gate:ledger` attacks the real
| estate. An audit proves nothing was broken yesterday; a probe proves it cannot
| be broken today.
|
| AND THE PERSONA THE BOARD IS DRAWN FOR MAY NOT DO IT. Board 19 names "record a
| fee" among the Property Manager's actions; recording it posts a charge against
| a unit, which is `estate.dues_ledger.create`, which D-010 locks that role out
| of entirely. The invariant outranks the board, and the assertion below is an
| HTTP 403 rather than a greyed button.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();

    // Committed, and before the transaction below: the permission matrix and
    // the estate itself are the ground these tests stand on, not part of what
    // any one of them writes.
    FacilitiesFixture::platform();

    /*
     * The users each test issues live in the PLATFORM database and are rolled
     * back with it. The estate's own database is not transacted — it is built
     * once per process and these tests read the queue and the diary the seeder
     * left there, exactly as `CollectionsFixture` does.
     */
    DB::connection('mysql')->beginTransaction();

    $this->amenities = app(Amenities::class);
    $this->putBack = [];
});

afterEach(function () {
    try {
        DB::connection('mysql')->rollBack();
    } finally {
        // Run whether the test passed or not, newest first: see `putBack()`.
        FacilitiesFixture::boot();

        foreach (array_reverse($this->putBack ?? []) as $undo) {
            $undo();
        }
    }
});

/**
 * Put an amenity's card back as it is NOW, after this test, whether or not the test passed.
 *
 * THE RATE CARD IS SHARED AND COMMITTED (M10, baseline). This estate's database is not rolled
 * back, and two tests below edit the Gazebo — the one seeded amenity with a deposit — under a
 * booking it is holding. Each already put the card back, but as its LAST statement, which runs
 * only when every assertion above it held. So a single failure in either one left the Gazebo at
 * J$15,000 or J$25,000, and the next three tests to book it failed on a deposit that was never
 * their business: one red test reported as four, and the three that were not the cause looked
 * like the ones to investigate. Registered here, the restore runs in `afterEach`, which PHPUnit
 * calls after a failure as surely as after a pass.
 */
function putBack(object $test, Amenity $amenity): void
{
    $id = $amenity->id;
    $card = $amenity->only([
        'name', 'icon_key', 'capacity', 'booking_fee_minor', 'deposit_minor',
        'opens_at', 'closes_at', 'booking_window_days', 'cancellation_hours', 'is_bookable', 'is_active',
    ]);

    undoAfter($test, static function () use ($id, $card): void {
        Amenity::query()->findOrFail($id)->forceFill($card)->save();
    });
}

/** Queue something for `afterEach` to undo, pass or fail. */
function undoAfter(object $test, Closure $undo): void
{
    $queue = $test->putBack ?? [];
    $queue[] = $undo;
    $test->putBack = $queue;
}

/**
 * One account's balance in the signed form the ledger stores it: debits less
 * credits, summed independently in raw SQL.
 *
 * A receivable is an asset and rises with a debit; income sits on the other
 * side, so 4100 moves DOWN in this form when the estate earns something. The
 * sign is kept rather than normalised because what these tests check is which
 * side each line landed on.
 */
function facilitiesNetDebits(string $code): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', [$code])->net;
}

/** What one unit owes, from that unit's own lines on the control account. */
function facilitiesUnitReceivable(int $unitId): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unitId])->net;
}

/** Every deposit the estate is actually holding, from the bookings themselves. */
function depositsHeld(): int
{
    return (int) AmenityBooking::query()
        ->where('deposit_state', AmenityBooking::DEPOSIT_HELD)
        ->sum('deposit_minor');
}

/* ------------------------------------------------------------------ */
/* board 20 — the rate card */
/* ------------------------------------------------------------------ */

it('draws board 20 four cards with the amounts and with the words', function () {
    $rows = collect($this->amenities->settingsBoard()['rows'])->keyBy('name');

    // The board's own order, which is neither alphabetical nor by capacity nor
    // by fee — so it is somebody's choice and it is stored.
    expect(array_column($this->amenities->settingsBoard()['rows'], 'name'))
        ->toBe(['Gazebo', 'Club House', 'Community Centre', 'Pool Deck']);

    expect($rows['Gazebo']['fee_minor'])->toBe(3_000_00)
        ->and($rows['Gazebo']['fee_label'])->toBe('$3,000')
        ->and($rows['Gazebo']['deposit_minor'])->toBe(5_000_00)
        ->and($rows['Gazebo']['deposit_label'])->toBe('$5,000')
        ->and($rows['Club House']['capacity_label'])->toBe('60 guests');

    /*
     * NULL IS NOT ZERO ON EITHER AMOUNT. "Free for residents" and "None" are
     * statements about the amenity rather than amounts, and the board's own
     * accounting note is explicit that a nil amenity must produce a booking
     * with zero journal lines rather than a zero-amount entry.
     */
    expect($rows['Pool Deck']['fee_minor'])->toBeNull()
        ->and($rows['Pool Deck']['fee_label'])->toBe(Amenity::FREE_LABEL)
        ->and($rows['Pool Deck']['deposit_minor'])->toBeNull()
        ->and($rows['Pool Deck']['deposit_label'])->toBe(Amenity::NO_DEPOSIT_LABEL);
});

it('reads the twenty-fourth hour as midnight rather than as closed all day', function () {
    $centre = FacilitiesFixture::amenity('Community Centre');

    // Stored as 24:00:00, which MySQL's TIME type carries and Carbon cannot
    // parse — which is why nothing casts this column.
    expect($centre->closes_at)->toBe('24:00:00')
        ->and($centre->hoursLabel())->toBe('9:00 AM – Midnight');

    $day = SiteTime::today()->addDays(40);

    // An evening booking, and one that runs right up to midnight. Both are
    // inside the hours of an amenity that closes at the end of the day.
    expect($centre->isWithinHours($day->copy()->addHours(18), $day->copy()->addHours(22)))->toBeTrue()
        ->and($centre->isWithinHours($day->copy()->addHours(18), $day->copy()->addDay()))->toBeTrue();

    /*
     * AND THIS IS WHAT 00:00:00 WOULD HAVE MEANT. The same evening against the
     * same opening time, closing at the twenty-fourth hour written the other
     * way: shut before it opened, and every booking on the board refused.
     */
    $midnightAsZero = new Amenity(['opens_at' => '09:00:00', 'closes_at' => '00:00:00', 'currency' => 'JMD']);

    expect($midnightAsZero->isWithinHours($day->copy()->addHours(18), $day->copy()->addHours(22)))->toBeFalse();

    // The diary agrees with the card: a booking to midnight is accepted.
    $booking = $this->amenities->book(
        amenity: $centre,
        unit: FacilitiesFixture::unit('Lot 88'),
        residentName: 'Sonia Campbell',
        startsAt: $day->copy()->addHours(18),
        endsAt: $day->copy()->addDay(),
    );

    expect($booking->status)->toBe(AmenityBooking::PENDING);

    // A booking past a real closing time is still refused, so the assertion
    // above is about midnight and not about the guard being off.
    expect(fn () => $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Pool Deck'),
        unit: FacilitiesFixture::unit('Lot 88'),
        residentName: 'Sonia Campbell',
        startsAt: $day->copy()->addHours(19),
        endsAt: $day->copy()->addHours(22),
    ))->toThrow(DomainException::class, 'is open');
});

/* ------------------------------------------------------------------ */
/* the snapshot — the assertion this module exists for */
/* ------------------------------------------------------------------ */

it('posts a deposit to the liability account and never to receivables — Q-009, ruled', function () {
    /*
     * THIS TEST USED TO ASSERT THE OPPOSITE, AND THE CLIENT OVERRULED IT.
     *
     * A deposit was recorded as a state on the booking and raised no journal at
     * all, on the reasoning that moving cash belongs to whoever holds `payments`
     * and a facilities route holds neither that nor `accounting_posting`
     * (D-010). The ruling was one sentence: cash moved, so the ledger must say
     * so. 2200 Resident Deposits Held had been in the chart since board 25 was
     * transcribed and read zero while three bookings said "held" — the estate's
     * books disagreeing with its own diary about money it was physically
     * holding.
     *
     * The permission question the old default was protecting is still open, and
     * open harmlessly — see the test below, which asserts there is no route to a
     * deposit at all.
     */
    $gazebo = FacilitiesFixture::amenity('Gazebo');
    $unit = FacilitiesFixture::unit('Lot 9');

    $bankBefore = facilitiesNetDebits('1000');
    $depositsBefore = facilitiesNetDebits('2200');
    $incomeBefore = facilitiesNetDebits('4100');
    $unitBefore = facilitiesUnitReceivable($unit->id);

    $booking = $this->amenities->book(
        amenity: $gazebo,
        unit: $unit,
        residentName: 'Ricardo Hall',
        startsAt: SiteTime::today()->addDays(52)->addHours(11),
        endsAt: SiteTime::today()->addDays(52)->addHours(13),
        guests: 20,
    );

    // TAKEN — Dr 1000 Bank, Cr 2200 Deposits Held. The bank rises by the
    // deposit; the liability rises by the same figure on the other side.
    $this->amenities->holdDeposit($booking);

    expect($booking->refresh()->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($booking->deposit_minor)->toBe(5_000_00)
        ->and($booking->deposit_journal_ref)->not->toBeNull();

    expect(facilitiesNetDebits('1000') - $bankBefore)->toBe(5_000_00)
        ->and(facilitiesNetDebits('2200') - $depositsBefore)->toBe(-5_000_00);

    /*
     * AND NOT ONE CENT AGAINST THE UNIT. The client was explicit: a deposit
     * never touches receivables and never appears in dues. It is the estate
     * holding somebody's money, not somebody owing the estate money — the
     * mirror image of a charge, and the half most easily got wrong.
     */
    expect(facilitiesUnitReceivable($unit->id))->toBe($unitBefore);

    // REFUNDED — the reverse, exactly. Nothing reaches income, because a
    // deposit returned was never the estate's to earn.
    $this->amenities->refundDeposit($booking);

    expect($booking->refresh()->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and(facilitiesNetDebits('1000'))->toBe($bankBefore)
        ->and(facilitiesNetDebits('2200'))->toBe($depositsBefore)
        ->and(facilitiesNetDebits('4100'))->toBe($incomeBefore)
        ->and(facilitiesUnitReceivable($unit->id))->toBe($unitBefore);
});

it('turns a forfeited deposit into income and only then', function () {
    /*
     * THE ONE PATH ON WHICH A DEPOSIT EVER BECOMES THE ESTATE'S OWN MONEY.
     * Dr 2200, Cr 4100 — the liability is discharged because the estate no
     * longer owes it back, and the same figure lands in income. No cash moves:
     * it has been in the bank since the day it was taken, which is why 1000 is
     * absent from this entry and present in the other two.
     */
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Gazebo'),
        unit: FacilitiesFixture::unit('Lot 9'),
        residentName: 'Ricardo Hall',
        startsAt: SiteTime::today()->addDays(58)->addHours(11),
        endsAt: SiteTime::today()->addDays(58)->addHours(13),
        guests: 20,
    );

    $this->amenities->holdDeposit($booking);

    $bankAfterHold = facilitiesNetDebits('1000');
    $depositsAfterHold = facilitiesNetDebits('2200');
    $incomeAfterHold = facilitiesNetDebits('4100');

    $this->amenities->forfeitDeposit($booking, 'Gazebo returned with two broken chairs.');

    expect($booking->refresh()->deposit_state)->toBe(AmenityBooking::DEPOSIT_FORFEITED)
        ->and($booking->deposit_forfeit_reason)->toContain('broken chairs')
        ->and($booking->depositLabel())->toBe('$5,000 forfeited');

    // The liability goes; the income arrives; the bank does not move.
    expect(facilitiesNetDebits('2200') - $depositsAfterHold)->toBe(5_000_00)
        ->and(facilitiesNetDebits('4100') - $incomeAfterHold)->toBe(-5_000_00)
        ->and(facilitiesNetDebits('1000'))->toBe($bankAfterHold);
});

it('gates the deposit door as ruled — update to take and refund, approve to forfeit', function () {
    /*
     * THE HALF OF Q-009 THE FIRST RULING DID NOT SETTLE, SETTLED (D-086).
     *
     * This test used to assert that NO deposit route existed, so that whoever
     * added one had to come here and say which gates they gave it. The client
     * chose them: "gated on amenities · update for hold and refund, and
     * amenities · approve for forfeit — forfeit keeps a resident's money and
     * needs the higher verb plus its required reason." It now fails if a deposit
     * route appears that is not one of these three, or if any of them changes
     * its gate.
     */
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains((string) $route->uri(), 'deposit')
            || str_contains((string) $route->getName(), 'deposit'))
        ->mapWithKeys(fn ($route): array => [(string) $route->getName() => $route->gatherMiddleware()]);

    expect($routes->keys()->sort()->values()->all())->toBe([
        'estate.facilities.booking.deposit.forfeit',
        'estate.facilities.booking.deposit.hold',
        'estate.facilities.booking.deposit.refund',
    ])
        ->and($routes['estate.facilities.booking.deposit.hold'])->toContain('can:estate.facilities.update')
        ->and($routes['estate.facilities.booking.deposit.refund'])->toContain('can:estate.facilities.update')
        ->and($routes['estate.facilities.booking.deposit.forfeit'])->toContain('can:estate.facilities.approve');

    /*
     * AND NOT THE ACCOUNTING GATE THE OLD VERSION OF THIS TEST ASKED FOR. That
     * was my caution on Q-009, not a ruling, and the ruling is incompatible with
     * it: the door is the Property Manager's, and D-010 locks that role out of
     * Accounting. A route carrying both would be one its own persona could
     * never open.
     */
    foreach ($routes as $middleware) {
        expect($middleware)->not->toContain('can:estate.accounting_posting.create');
    }

    // The one money route on these screens that DOES carry a second module's
    // gate still carries it: a fee is a charge on a unit, and a deposit is not.
    $fee = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->getName() === 'estate.facilities.booking.fee');

    expect($fee)->not->toBeNull()
        ->and($fee->gatherMiddleware())->toContain('can:estate.dues_ledger.create');
});

it('refuses to keep a deposit without saying why', function () {
    // Keeping a resident's J$5,000 is the one deposit act somebody will be asked
    // to justify, and "forfeited" with nothing after it is not an answer.
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Gazebo'),
        unit: FacilitiesFixture::unit('Lot 9'),
        residentName: 'Ricardo Hall',
        startsAt: SiteTime::today()->addDays(64)->addHours(11),
        endsAt: SiteTime::today()->addDays(64)->addHours(13),
        guests: 20,
    );

    $this->amenities->holdDeposit($booking);

    expect(fn () => $this->amenities->forfeitDeposit($booking, '   '))
        ->toThrow(DomainException::class);

    // And it did not half-forfeit on the way out.
    expect($booking->refresh()->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD);
});

it('does not move a deposit the estate is holding when the rate card is edited', function () {
    $gazebo = FacilitiesFixture::amenity('Gazebo');

    // Board 20's terms go back after this test even if it fails: see `putBack()`.
    putBack($this, $gazebo);

    // The estate sets its cancellation rule on the edit screen; the seeder
    // leaves it unset rather than inventing one board 20 does not draw.
    $gazebo->forceFill(['cancellation_hours' => 48])->save();

    $booking = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 9'),
        residentName: 'Ricardo Hall',
        startsAt: SiteTime::today()->addDays(45)->addHours(11),
        endsAt: SiteTime::today()->addDays(45)->addHours(13),
        guests: 20,
    );

    // Quoted and not received: it must not reach the deposits-held account
    // until somebody actually pays it.
    expect($booking->deposit_state)->toBe(AmenityBooking::DEPOSIT_AWAITING);

    $this->amenities->holdDeposit($booking);

    expect($booking->refresh()->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($booking->depositLabel())->toBe('$5,000 held');

    $heldBefore = depositsHeld();

    /*
     * THE EDIT. A manager raises the Gazebo's fee, triples its deposit and
     * doubles its cancellation window — on Tuesday, over a booking the estate
     * accepted on Monday and a deposit it is already holding.
     */
    $gazebo->forceFill([
        'booking_fee_minor' => 9_000_00,
        'deposit_minor' => 25_000_00,
        'cancellation_hours' => 96,
    ])->save();

    $reloaded = AmenityBooking::findOrFail($booking->id);

    /*
     * THE ONE ASSERTION THE SNAPSHOT COLUMNS EXIST FOR. Every figure is the one
     * the household agreed to. Read back through the relation instead, board
     * 19's Deposit column would now say $25,000 held — for money nobody paid —
     * and the deposits-held control account would stop agreeing with the sum of
     * open bookings.
     */
    expect($reloaded->fee_minor)->toBe(3_000_00)
        ->and($reloaded->deposit_minor)->toBe(5_000_00)
        ->and($reloaded->cancellation_hours)->toBe(48)
        ->and($reloaded->currency)->toBe('JMD')
        ->and($reloaded->depositLabel())->toBe('$5,000 held')

        // And not one deposit anywhere in the estate moved.
        ->and(depositsHeld())->toBe($heldBefore)

        // While the amenity itself really did change — without which the four
        // assertions above would hold over an edit that never happened.
        ->and($reloaded->amenity->deposit_minor)->toBe(25_000_00);

    // A booking made AFTER the edit carries the new terms, which is what makes
    // this a snapshot at creation rather than a figure frozen forever.
    $later = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 9'),
        residentName: 'Ricardo Hall',
        startsAt: SiteTime::today()->addDays(46)->addHours(11),
        endsAt: SiteTime::today()->addDays(46)->addHours(13),
    );

    expect($later->fee_minor)->toBe(9_000_00)
        ->and($later->deposit_minor)->toBe(25_000_00)
        ->and($later->cancellation_hours)->toBe(96);

    // Board 20's own figures back, because these tests share one estate.
    $gazebo->forceFill([
        'booking_fee_minor' => 3_000_00,
        'deposit_minor' => 5_000_00,
        'cancellation_hours' => null,
    ])->save();

    expect(AmenityBooking::findOrFail($later->id)->fee_minor)->toBe(9_000_00);
});

it('refuses to hold or refund a deposit the estate never asked for', function () {
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Pool Deck'),
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: SiteTime::today()->addDays(50)->addHours(9),
        endsAt: SiteTime::today()->addDays(50)->addHours(11),
    );

    // Recording one held would put a liability on the estate for money nobody
    // was asked for; refunding one would take cash out of the operating account.
    expect($booking->deposit_state)->toBe(AmenityBooking::DEPOSIT_NONE)
        ->and($booking->depositLabel())->toBe('')
        ->and(fn () => $this->amenities->holdDeposit($booking))
        ->toThrow(DomainException::class, 'no deposit')
        ->and(fn () => $this->amenities->refundDeposit($booking))
        ->toThrow(DomainException::class, 'holds no deposit');
});

/* ------------------------------------------------------------------ */
/* the fee, traced to the lines it is made of */
/* ------------------------------------------------------------------ */

it('charges a booking fee as a debit to 1200 and a credit to 4100', function () {
    $booking = FacilitiesFixture::booking('Gazebo', 'Lot 47');

    expect($booking->fee_minor)->toBe(3_000_00)
        ->and($booking->isCharged())->toBeFalse();

    $receivableBefore = facilitiesNetDebits('1200');
    $incomeBefore = facilitiesNetDebits(Amenities::FEE_ACCOUNT);
    $unitBefore = facilitiesUnitReceivable($booking->unit_id);
    $depositsBefore = facilitiesNetDebits('2200');

    $charge = $this->amenities->recordFee($booking);

    /*
     * THE MONEY-MODULE RULE, in full. Every figure a screen shows that is money
     * has to be traceable to posted lines — so the fee is not proven by the
     * charge row saying 3,000, it is proven by the control account moving by
     * exactly 3,000 and the unit's own sub-ledger moving by the same.
     */
    expect(facilitiesNetDebits('1200') - $receivableBefore)->toBe(3_000_00)
        ->and(facilitiesUnitReceivable($booking->unit_id) - $unitBefore)->toBe(3_000_00)

        // Income sits on the credit side, so the signed form moves down by the
        // fee: 4100 was credited with exactly what 1200 was debited.
        ->and(facilitiesNetDebits(Amenities::FEE_ACCOUNT) - $incomeBefore)->toBe(-3_000_00)

        /*
         * AND THE DEPOSIT DID NOT POST ANYWHERE. This booking has J$5,000 held
         * against it and 2200 Resident Deposits Held is untouched — a deposit is
         * a state here and a posting somewhere else, behind `payments`, which no
         * facilities route holds.
         */
        ->and(facilitiesNetDebits('2200'))->toBe($depositsBefore);

    // The charge is a charge on a unit like any other, on the account board 25
    // keeps amenity income in.
    expect($charge->amount_minor)->toBe(3_000_00)
        ->and($charge->currency)->toBe('JMD')
        ->and($charge->type)->toBe(Amenities::CHARGE_TYPE)
        ->and($charge->journal_ref)->not->toBeNull();

    $lines = DB::connection('tenant')->select('
        SELECT a.code, l.debit_minor, l.credit_minor, l.unit_id
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE l.entry_ref = ?
         ORDER BY l.line_no
    ', [$charge->journal_ref]);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->code)->toBe('1200')
        ->and((int) $lines[0]->debit_minor)->toBe(3_000_00)

        // The UNIT is on the receivable line, which is the whole of the
        // sub-ledger: a 1200 line with no unit on it sits inside the control
        // balance and outside every household's statement.
        ->and((int) $lines[0]->unit_id)->toBe($booking->unit_id)
        ->and($lines[1]->code)->toBe(Amenities::FEE_ACCOUNT)
        ->and((int) $lines[1]->credit_minor)->toBe(3_000_00)
        ->and($lines[1]->unit_id)->toBeNull();

    // Debits equal credits, on this entry and across the whole estate.
    expect((int) $lines[0]->debit_minor)->toBe((int) $lines[1]->credit_minor);

    $trial = DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(debit_minor), 0) AS debits, COALESCE(SUM(credit_minor), 0) AS credits
          FROM journal_lines
    ');

    expect((int) $trial->debits)->toBe((int) $trial->credits);

    /*
     * THE POINTER, AND NO AMOUNT BESIDE IT. The booking keeps the charge's id
     * and nothing else, so the fee on the resident's statement and the fee
     * behind their booking are one row read two ways. It can happen once: a
     * household billed twice for one Saturday has to be refunded through a
     * credit note nobody would have raised.
     */
    expect($booking->refresh()->fee_charge_id)->toBe($charge->id)
        ->and($booking->isCharged())->toBeTrue()
        ->and(fn () => $this->amenities->recordFee($booking))
        ->toThrow(DomainException::class, 'already charged');
});

it('raises no entry at all for an amenity that costs nothing', function () {
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Pool Deck'),
        unit: FacilitiesFixture::unit('Lot 63'),
        residentName: 'Keith Walters',
        startsAt: SiteTime::today()->addDays(55)->addHours(9),
        endsAt: SiteTime::today()->addDays(55)->addHours(11),
    );

    $entries = Journal::query()->count();

    // "A booking with zero journal lines rather than a zero-amount entry" —
    // board 20's accounting note, verbatim. A zero-value entry is a row in the
    // ledger claiming something happened.
    expect(fn () => $this->amenities->recordFee($booking))
        ->toThrow(DomainException::class, 'no booking fee');

    expect(Journal::query()->count())->toBe($entries)
        ->and($booking->refresh()->fee_charge_id)->toBeNull();
});

it('refuses to charge a household for a booking the estate turned down', function () {
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Club House'),
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: SiteTime::today()->addDays(60)->addHours(15),
        endsAt: SiteTime::today()->addDays(60)->addHours(19),
    );

    // "Declined" on its own tells the household nothing they can do anything
    // about, and they will ask a guard at a gate about it.
    expect(fn () => $this->amenities->decline($booking, '  '))
        ->toThrow(DomainException::class, 'Say why');

    $this->amenities->decline($booking, 'The Club House is closed for resurfacing that weekend.');

    $entries = Journal::query()->count();

    expect(fn () => $this->amenities->recordFee($booking))
        ->toThrow(DomainException::class, 'declined');

    expect(Journal::query()->count())->toBe($entries)
        ->and($booking->refresh()->declined_reason)
        ->toBe('The Club House is closed for resurfacing that weekend.');
});

it('cannot edit or delete the entry a booking fee posted', function () {
    /*
     * A CONFIRMED BOOKING OF ITS OWN (B052). This used to charge the seeded Club House
     * booking at Lot 88 — which board 19 draws PENDING, and a fee is no longer posted
     * against a booking still waiting on its decision: declined afterwards, it left the
     * household billed. What is under test here is the entry, not whose booking it was.
     */
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Club House'),
        unit: FacilitiesFixture::scratchUnit('AMN-4'),
        residentName: 'Sonia Campbell',
        startsAt: SiteTime::today()->addDays(161)->addHours(15),
        endsAt: SiteTime::today()->addDays(161)->addHours(19),
    );

    $this->amenities->approve($booking);

    $charge = $this->amenities->recordFee($booking->refresh());

    $entry = Journal::query()->where('reference', $charge->journal_ref)->firstOrFail();

    /*
     * PROBED LIVE, INSIDE ROLLED-BACK TRANSACTIONS, the way `gate:ledger` does
     * it — an audit proves nothing was broken yesterday, a probe proves it
     * cannot be broken today. MySQL aborts the offending STATEMENT when a
     * trigger signals rather than the whole transaction, so the rollback is what
     * guarantees each probe changed nothing either way.
     *
     * The estate's own MySQL user also has UPDATE and DELETE revoked on both
     * tables; this suite connects as the schema owner, so what refuses here is
     * the trigger. Both halves are deliberate and `gate:ledger` proves the grant
     * against the real estates.
     */
    $refuses = function (callable $attempt): bool {
        $connection = DB::connection('tenant');
        $connection->beginTransaction();

        try {
            $attempt();

            return false;
        } catch (Throwable) {
            return true;
        } finally {
            $connection->rollBack();
        }
    };

    // POSITIVE CONTROL FIRST. Without it every refusal below passes when the
    // ledger is simply broken, and the test reports an enforcement it never
    // demonstrated.
    expect($refuses(fn () => app(Ledger::class)->post('facilities probe', [
        Posting::debit('1200', 100, 'probe', unitId: $booking->unit_id),
        Posting::credit(Amenities::FEE_ACCOUNT, 100, 'probe'),
    ])))->toBeFalse();

    expect($refuses(fn () => DB::connection('tenant')->table('journals')
        ->where('id', $entry->id)->update(['memo' => 'edited'])))->toBeTrue();

    expect($refuses(fn () => DB::connection('tenant')->table('journals')
        ->where('id', $entry->id)->delete()))->toBeTrue();

    expect($refuses(fn () => DB::connection('tenant')->table('journal_lines')
        ->where('entry_ref', $entry->reference)->update(['debit_minor' => 1])))->toBeTrue();

    expect($refuses(fn () => DB::connection('tenant')->table('journal_lines')
        ->where('entry_ref', $entry->reference)->delete()))->toBeTrue();

    // And a line cannot be slipped into an entry that is already posted, which
    // is how an unbalanced entry would otherwise be built in two steps.
    $account = (int) DB::connection('tenant')->table('journal_lines')
        ->where('entry_ref', $entry->reference)
        ->value('account_id');

    expect($refuses(fn () => DB::connection('tenant')->table('journal_lines')->insert([
        'entry_ref' => $entry->reference,
        'account_id' => $account,
        'line_no' => 99,
        'debit_minor' => 100,
        'credit_minor' => 0,
        'currency' => 'JMD',
        'created_at' => now(),
    ])))->toBeTrue();

    // Nothing the probes attempted survived them.
    $entry->refresh();

    expect($entry->memo)->not->toBe('edited')
        ->and($entry->lines()->count())->toBe(2);

    /*
     * And the tie the control account rests on still holds: every line on 1200
     * carries the unit it belongs to. A receivable line with no unit named sits
     * inside the control balance and on nobody's statement, which is the drift
     * a sub-ledger exists to make visible.
     */
    $orphaned = (int) DB::connection('tenant')->selectOne('
        SELECT COUNT(*) AS n
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id IS NULL
    ', ['1200'])->n;

    expect($orphaned)->toBe(0);
});

/* ------------------------------------------------------------------ */
/* the diary itself */
/* ------------------------------------------------------------------ */

it('refuses two households the same Saturday and says which of the two reasons it is', function () {
    $gazebo = FacilitiesFixture::amenity('Gazebo');
    $day = SiteTime::today()->addDays(70);

    $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 47'),
        residentName: 'Andrea Fletcher',
        startsAt: $day->copy()->addHours(11),
        endsAt: $day->copy()->addHours(15),
    );

    // Two households told the same Saturday is theirs is the one failure a
    // booking diary exists to prevent — and a pending booking holds the slot
    // while it waits, or two of them would each be told it was theirs.
    expect(fn () => $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: $day->copy()->addHours(14),
        endsAt: $day->copy()->addHours(16),
    ))->toThrow(DomainException::class, 'already booked');

    // Half-open on the right: a Gazebo booked until 3:00 PM and another from
    // 3:00 PM are two bookings, which is how a Saturday actually runs.
    $backToBack = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: $day->copy()->addHours(15),
        endsAt: $day->copy()->addHours(17),
    );

    expect($backToBack->status)->toBe(AmenityBooking::PENDING);

    // And a blackout cannot be dropped over a booking that already exists: the
    // household would turn up to a locked gate with a confirmation in hand.
    expect(fn () => $this->amenities->blockSlot(
        amenity: $gazebo,
        startsAt: $day->copy()->addHours(10),
        endsAt: $day->copy()->addHours(12),
        reason: 'Resurfacing',
    ))->toThrow(DomainException::class, 'already covers part of that period');
});

it('draws the diary by when each booking starts and not by when it was taken', function () {
    $gazebo = FacilitiesFixture::amenity('Gazebo');

    // Two bookings in the SAME MONTH, taken in the opposite order to the one
    // they happen in. Same month is where this matters: a reference is
    // sequential within the month a booking falls in, so within one month it
    // records the order the office took the calls in and nothing else.
    $month = SiteTime::today()->startOfMonth()->addMonths(4);

    /*
     * IN THE EVENING, WHERE NO OTHER GAZEBO BOOKING IN THIS ESTATE STANDS (M10, baseline).
     *
     * These are the only two bookings in the estate whose DATE moves with the calendar:
     * month-relative, they land anywhere from about 100 to 143 days out. Every other
     * Gazebo booking is a fixed distance from today — the door tests at +100, +104,
     * +108, +112, +114, the rate-card edit at +120 — and they all used to sit at 11:00,
     * the same hour as these two. The estate is committed, not rolled back, so on some
     * calendar days one of these two landed on another test's slot, and whichever ran
     * second was refused as a double booking. That was date-dependent AND
     * order-dependent: on 23 September the +120 booking and the one below are both
     * 21 January at 11:00, and the rate-card test, refused mid-edit, left the Gazebo
     * at J$15,000 and a Tennis Court on the card for three more tests to trip over.
     *
     * The hour carries nothing the test is about — it is about the order bookings
     * are DRAWN in — so it moves to 19:00, inside the Gazebo's hours and outside every
     * other booking's.
     */
    $later = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 47'),
        residentName: 'Andrea Fletcher',
        startsAt: $month->copy()->addDays(20)->addHours(19),
        endsAt: $month->copy()->addDays(20)->addHours(21),
    );

    $sooner = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: $month->copy()->addDays(10)->addHours(19),
        endsAt: $month->copy()->addDays(10)->addHours(21),
    );

    // The booking that happens FIRST carries the LATER reference, which is what
    // makes this pair the case a reference sort gets wrong.
    expect(strcmp($sooner->reference, $later->reference))->toBeGreaterThan(0);

    // The whole of the Gazebo's diary on one page, in its own order (M32 F5): the past booking is at the far end of it.
    $rows = $this->amenities->bookingsBoard('Gazebo', table: TableQuery::make(Amenities::bookingSorts(), 'when', 'asc', per: 200))['rows'];
    $order = array_flip(array_column($rows, 'reference'));

    expect($order[$sooner->reference])->toBeLessThan($order[$later->reference]);

    /*
     * And the whole diary reads forwards: every booking still to come, in the
     * order it will happen, and the ones that already have at the bottom. A
     * manager scanning board 19 is looking for what they have to do something
     * about, and a Saturday drawn below the Wednesday after it is a Saturday
     * nobody prepared for.
     */
    $bookings = AmenityBooking::query()
        ->whereIn('reference', array_column($rows, 'reference'))
        ->get()
        ->keyBy('reference');

    $previous = null;
    $past = false;

    foreach ($rows as $row) {
        if ($row['is_past']) {
            $past = true;

            continue;
        }

        expect($past)->toBeFalse('a booking still to come was drawn below one that has already happened');

        $starts = $bookings[$row['reference']]->starts_at;

        if ($previous !== null) {
            expect($starts->greaterThanOrEqualTo($previous))->toBeTrue(
                'board 19 drew '.$row['reference'].' out of date order'
            );
        }

        $previous = $starts;
    }

    expect($past)->toBeTrue('the seeded past booking should still be on the board, at the bottom');
});

it('reads one arrears threshold for the estate rather than its own — Q-008, ruled', function () {
    /*
     * THE AMENITY MODULE NO LONGER HAS ITS OWN PAIR OF SETTINGS.
     *
     * It carried `amenity_arrears_block_enabled` (false) and
     * `amenity_arrears_block_days` (90, beside the gate's own 90) — the cautious
     * reading of an unanswered question. The client answered it the other way: a
     * household is either in arrears or it is not, one threshold across the
     * estate, and an active payment plan lifts it the same way it lifts the gate.
     *
     * What those two columns made possible was an estate admitting a household's
     * visitors on Friday and refusing the household itself the Club House on
     * Saturday, with nothing saying the rules had drifted. Two numbers that must
     * always agree should not be two numbers.
     */
    expect(Schema::connection('tenant')->hasColumn('estate_settings', 'amenity_arrears_block_enabled'))
        ->toBeFalse()
        ->and(Schema::connection('tenant')->hasColumn('estate_settings', 'amenity_arrears_block_days'))
        ->toBeFalse();

    /*
     * A LOT OF THIS TEST'S OWN. The charge below is posted to an append-only
     * ledger in an estate database every file shares, so it can never be taken
     * back — and on Lot 3 it refused every later booking this file makes for
     * Lot 3, and cost the Resident App's contract walk its vote whenever this
     * suite ran first (M4 §4, defect 3). Nobody else stands on Lot 971.
     */
    $unit = FacilitiesFixture::scratchUnit('Lot 971');
    $settings = EstateSetting::current();

    // The gate's own rule, which is now the whole rule.
    expect($settings->arrears_restriction_enabled)->toBeTrue()
        ->and($settings->arrears_restriction_days)->toBe(90)
        ->and($this->amenities->mayBook($unit))->toBeTrue();

    app(Dues::class)->charge(
        unit: $unit,
        amount: Money::ofMinor(18_600_00, 'JMD'),
        description: 'Maintenance fee — arrears brought forward',
        dueOn: Carbon::today()->subDays(120),
    );

    $refusal = null;

    try {
        $this->amenities->book(
            amenity: FacilitiesFixture::amenity('Gazebo'),
            unit: $unit,
            residentName: 'Rachel Bennett',
            startsAt: SiteTime::today()->addDays(80)->addHours(11),
            endsAt: SiteTime::today()->addDays(80)->addHours(13),
        );
    } catch (DomainException $thrown) {
        $refusal = $thrown->getMessage();
    }

    /*
     * A BOOLEAN, AND DELIBERATELY NOTHING ELSE. The person reading board 19 is
     * the Property Manager, who is locked out of Dues & ledger — so "this
     * household cannot book" is theirs to know and "this household owes
     * J$18,600" is not, and a refusal naming the amount would be the invariant
     * leaking through an error string.
     */
    expect($this->amenities->mayBook($unit))->toBeFalse()
        ->and($refusal)->not->toBeNull()
        ->and($refusal)->toContain('in arrears')
        ->and(strtolower((string) $refusal))->not->toContain('18,600')
        ->and(strtolower((string) $refusal))->not->toContain('balance')
        ->and((string) $refusal)->not->toMatch('/\d[\d,]*\.\d{2}/');

    // Moving the ESTATE's threshold moves the amenity rule with it, because
    // there is only one figure to move.
    // Put back whether or not the assertion between holds: the threshold is the
    // whole estate's, and every file that reads this fixture reads it.
    try {
        $settings->forceFill(['arrears_restriction_days' => 365])->save();

        expect($this->amenities->mayBook($unit))->toBeTrue();
    } finally {
        $settings->forceFill(['arrears_restriction_days' => 90])->save();
    }

    expect($this->amenities->mayBook($unit))->toBeFalse();
});

it('lets a household on a payment plan book, exactly as the gate admits its visitors', function () {
    /*
     * THE OTHER HALF OF THE RULING. A household that has agreed a schedule and
     * is meeting it is not a household to turn away from a birthday party. The
     * arrears were given a schedule, not forgiven — and board 7's whole purpose
     * is that keeping to one restores normal life.
     *
     * `Amenities::mayBook()` calls `Collections::isProtected()`, which is the
     * same check `RestrictionPolicy` makes at the gate, rather than
     * reimplementing the shield — so the two answers cannot drift apart.
     */
    // Its own lot, for the reason the test above gives: the arrears and the plan
    // over them are permanent, and Lot 63 is where other files stand.
    $unit = FacilitiesFixture::scratchUnit('Lot 972');

    app(Dues::class)->charge(
        unit: $unit,
        amount: Money::ofMinor(24_000_00, 'JMD'),
        description: 'Maintenance fee — arrears brought forward',
        dueOn: Carbon::today()->subDays(150),
    );

    expect($this->amenities->mayBook($unit))->toBeFalse();

    $collections = app(Collections::class);

    $plan = $collections->draft($unit, instalments: 3);
    $collections->agree($plan, 'Rachel Bennett');
    $collections->activate($plan, 'Rachel Bennett', FacilitiesFixture::viewer(Role::TREASURER));

    // Protected at the gate, and now protected here, from one source of truth.
    expect($collections->isProtected($unit))->toBeTrue()
        ->and($this->amenities->mayBook($unit))->toBeTrue();
});

it('draws board 19 without one figure about a household financial position', function () {
    $board = $this->amenities->bookingsBoard();

    $serialised = strtolower((string) json_encode($board));

    /*
     * The Deposit column is an amount and belongs here: it is a fact about the
     * booking, snapshotted onto it. What may never appear is anything about the
     * household's ACCOUNT — D-010 is a platform invariant rather than an estate
     * setting, and the whole reason this module and Dues & ledger are separate
     * permissions is that whoever commissions work must never see what a
     * resident owes.
     */
    foreach (['balance', 'arrear', 'bucket', 'receivable', 'owed', 'outstanding', 'statement', 'ageing'] as $forbidden) {
        expect($serialised)->not->toContain(
            $forbidden,
            "board 19's payload contained the word [{$forbidden}], which is a household's financial position"
        );
    }

    expect($board['amenities'])->toBe(['Gazebo', 'Club House', 'Community Centre', 'Pool Deck'])
        ->and($board['rows'])->not->toBeEmpty();
});

/* ------------------------------------------------------------------ */
/* who may do any of it */
/* ------------------------------------------------------------------ */

it('draws the fee control inert for the Property Manager and refuses the post behind it', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    /*
     * D-010, read off the seeded matrix rather than asserted about it. The
     * Property Manager holds Facilities in full and holds nothing at all on
     * Dues & ledger; the Treasurer is the other way round on the second gate.
     */
    expect($manager->can('estate.facilities.update'))->toBeTrue()
        ->and($manager->can('estate.dues_ledger.create'))->toBeFalse()
        ->and($treasurer->can('estate.dues_ledger.create'))->toBeTrue();

    $this->withoutVite()->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/amenities/bookings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Facilities/Bookings')
            ->where('canUpdate', true)
            ->where('canCreate', true)

            // The one control on these four boards that the board's own persona
            // may not use, drawn inert with the reason on it.
            ->where('canCharge', false)
            ->has('chargeReason')

            // And the Vendors tab stays inert for them: the supplier register is
            // Accounting's, and D-010 locks them out of it.
            ->where('canViewVendors', false));

    /*
     * THE REFUSAL IS PROVEN AS A CHANGE OF NOTHING, NOT AS A STATE (M6 3.1).
     *
     * This used to assert that the seeded Community Centre booking was UNCHARGED before
     * going on — a claim about a row it does not own, in an estate database this file does
     * not roll back. The test below used to charge that same booking, and a fee can be
     * recorded once, so whichever of the two drew first decided whether the other passed.
     * That test now makes a booking of its own, and this one no longer depends on the answer
     * either way: what it is about is that two refused POSTs wrote nothing, so what it
     * asserts is that the fee pointer is exactly what it was before them.
     */
    $booking = FacilitiesFixture::booking('Community Centre', 'Lot 63');
    $feeBefore = $booking->fee_charge_id;

    // AND IT IS NOT MERELY GREYED. The route carries the second gate, so the
    // POST behind the inert button is refused rather than quietly working.
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertForbidden();

    /*
     * AND THE SCREEN AGREES WITH THE ROUTE FROM THE OTHER SIDE TOO. The
     * Treasurer holds Dues & ledger in full and Facilities as VIEW, so they
     * hold one of the two gates this act needs. A `canCharge` that asked only
     * about the ledger would draw them a live button whose POST the route
     * refuses — and the person clicking it would have no way to tell which gate
     * they were missing.
     */
    $this->withoutVite()->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/facilities/amenities/bookings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canUpdate', false)
            ->where('canCharge', false));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->fee_charge_id)->toBe($feeBefore);
});

it('lets a role holding both gates record the fee, and posts it', function () {
    /*
     * The Admin Assistant holds `E` on Facilities and `E` on Dues & ledger —
     * data entry on both — which is what this route asks for. The Treasurer
     * holds the ledger gate and only VIEW on Facilities, so board 19's own
     * committee is refused here as well; recording a booking fee is the one act
     * on these four screens that needs a role holding both modules.
     */
    // A bookkeeper holds both (the seeded Admin Assistant holds no Dues & ledger since M53).
    $assistant = FacilitiesFixture::bookkeeper();

    expect($assistant->can('estate.facilities.update'))->toBeTrue()
        ->and($assistant->can('estate.dues_ledger.create'))->toBeTrue();

    // The screen says so too, which is the same agreement the other way up:
    // the control is drawn live for exactly the viewer the route will accept.
    $this->withoutVite()->actingAs($assistant)
        ->get(FacilitiesFixture::url('/facilities/amenities/bookings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCharge', true)

            // Entry on Accounting carries view, so the Vendors tab is a link for
            // them — the inert twin is for the role that is locked out.
            ->where('canViewVendors', true));

    /*
     * A BOOKING OF ITS OWN, BECAUSE THIS TEST CONSUMES ONE (M6 3.1).
     *
     * This used to charge the seeded Community Centre booking at Lot 63 — the same row the
     * inert-control test above reads. A fee is recorded once and the entry behind it cannot
     * be unposted, and this estate's database is committed rather than rolled back, so the
     * mark is permanent and whichever test drew first decided whether the other passed.
     * Pinning the order would only have hidden that: a test that consumes a shared fixture
     * row has to make its own, which is the rule `FacilitiesFixture::scratchUnit()` exists
     * for and which Lot 971 and Lot 972 above already follow.
     *
     * Nothing is proven away. The booking is made against the same amenity and approved into
     * the same state, and the J$8,000 asserted below is the Community Centre's own fee,
     * snapshotted onto this booking when it was made.
     */
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Community Centre'),
        unit: FacilitiesFixture::scratchUnit('Lot 973'),
        residentName: 'Keith Walters',
        startsAt: SiteTime::today()->addDays(130)->addHours(10),
        endsAt: SiteTime::today()->addDays(130)->addHours(12),
    );

    $this->amenities->approve($booking);

    expect($booking->refresh()->isCharged())->toBeFalse();

    $before = facilitiesUnitReceivable($booking->unit_id);

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/bookings'));

    FacilitiesFixture::boot();

    $charged = AmenityBooking::findOrFail($booking->id);

    expect($charged->fee_charge_id)->not->toBeNull()
        ->and(facilitiesUnitReceivable($booking->unit_id) - $before)->toBe($charged->fee_minor)
        ->and($charged->fee_minor)->toBe(8_000_00);

    // The Treasurer holds the ledger half and not the facilities half.
    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertForbidden();
});

it('needs create rather than update to take a period out of the diary', function () {
    $gazebo = FacilitiesFixture::amenity('Gazebo');
    $day = SiteTime::today()->addDays(90);

    $payload = [
        'starts_at' => $day->copy()->addHours(8)->toDateTimeString(),
        'ends_at' => $day->copy()->addHours(18)->toDateTimeString(),
        'reason' => 'Resurfacing',
    ];

    // The Treasurer reads Facilities and creates nothing in it.
    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$gazebo->id.'/block'), $payload)
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(AmenitySlot::query()->where('amenity_id', $gazebo->id)->count())->toBe(0);

    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$gazebo->id.'/block'), $payload)
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/bookings'));

    FacilitiesFixture::boot();

    $slot = AmenitySlot::query()->where('amenity_id', $gazebo->id)->firstOrFail();

    expect($slot->kind)->toBe(AmenitySlot::BLOCKED)
        ->and($slot->reason)->toBe('Resurfacing');

    // And the diary now refuses that period for a different reason than a clash
    // — the estate closed it, rather than somebody else having taken it.
    expect(fn () => $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 47'),
        residentName: 'Andrea Fletcher',
        startsAt: $day->copy()->addHours(9),
        endsAt: $day->copy()->addHours(11),
    ))->toThrow(DomainException::class, 'unavailable then');
});

it('lets the Property Manager decide a booking that is waiting on one', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $booking = FacilitiesFixture::booking('Club House', 'Lot 88');

    expect($booking->status)->toBe(AmenityBooking::PENDING);

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/approve'))
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/bookings'));

    FacilitiesFixture::boot();

    $decided = AmenityBooking::findOrFail($booking->id);

    expect($decided->status)->toBe(AmenityBooking::CONFIRMED)
        ->and($decided->approved_by_name)->toBe($manager->name)
        ->and($decided->approved_at)->not->toBeNull();

    // Approving it again would restate a date the household has already been
    // told about.
    expect(fn () => $this->amenities->approve($decided))
        ->toThrow(DomainException::class, 'not waiting on a decision');
});

/* ------------------------------------------------------------------ */
/* the deposit door — the booking detail no board draws (D-086) */
/* ------------------------------------------------------------------ */

/**
 * A fresh Gazebo booking at Lot 9, which board 19 draws nothing against, so the
 * door can be walked without moving a deposit the diary tests read. Each test
 * takes its own day: the estate database is not transacted, and two bookings on
 * one Saturday is a clash rather than a fixture.
 */
function doorBooking(Amenities $amenities, int $days): AmenityBooking
{
    return $amenities->book(
        amenity: FacilitiesFixture::amenity('Gazebo'),
        unit: FacilitiesFixture::unit('Lot 9'),
        residentName: 'Marcia Brown',
        startsAt: SiteTime::today()->addDays($days)->addHours(11),
        endsAt: SiteTime::today()->addDays($days)->addHours(13),
    );
}

it('lets the Property Manager take and refund a deposit from the booking detail', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $booking = doorBooking($this->amenities, 100);
    $this->amenities->approve($booking);

    $detail = FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id);
    $bank = facilitiesNetDebits('1000');
    $held = facilitiesNetDebits('2200');

    // Their screen, as the ruling says — both gates, the Approver tag included.
    $this->withoutVite()->actingAs($manager)
        ->get($detail)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Facilities/Booking')
            ->where('booking.reference', $booking->reference)
            ->where('deposit.amount', '$5,000')
            ->where('deposit.state', AmenityBooking::DEPOSIT_AWAITING)
            ->where('deposit.timeline.1.state', 'active')
            ->where('canUpdate', true)
            ->where('canApprove', true));

    $this->actingAs($manager)
        ->post($detail.'/deposit/hold')
        ->assertRedirect($detail);

    FacilitiesFixture::boot();

    // The money arrived: the bank up by the deposit and the liability up by the
    // same figure on the other side — and the rail now carries the entry.
    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and(facilitiesNetDebits('1000') - $bank)->toBe(5_000_00)
        ->and(facilitiesNetDebits('2200') - $held)->toBe(-5_000_00)
        ->and($this->amenities->bookingBoard(AmenityBooking::findOrFail($booking->id))['deposit']['timeline'][1]['state'])
        ->toBe('done');

    $this->actingAs($manager)
        ->post($detail.'/deposit/refund')
        ->assertRedirect($detail);

    FacilitiesFixture::boot();

    // And it went back, to the cent: both accounts where they started.
    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and(facilitiesNetDebits('1000'))->toBe($bank)
        ->and(facilitiesNetDebits('2200'))->toBe($held);
});

it('lets data entry take a deposit and keeps the forfeit for an approver', function () {
    /*
     * THE HIGHER VERB, SEPARATING TWO ROLES THAT BOTH HOLD THE LOWER ONE. The
     * Admin Assistant holds Facilities as Entry — data entry, which includes
     * `update` — so they may record a deposit received. They do not hold the
     * Approver tag, so they may not keep one. The Treasurer holds Facilities as
     * View and moves nothing through it, whatever they hold in the ledger.
     */
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    expect($assistant->can('estate.facilities.update'))->toBeTrue()
        ->and($assistant->can('estate.facilities.approve'))->toBeFalse()
        ->and($treasurer->can('estate.facilities.update'))->toBeFalse();

    $booking = doorBooking($this->amenities, 104);
    $detail = FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id);

    $this->withoutVite()->actingAs($treasurer)
        ->get($detail)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canUpdate', false)
            ->where('canApprove', false));

    $this->actingAs($treasurer)
        ->post($detail.'/deposit/hold')
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_AWAITING);

    $this->actingAs($assistant)
        ->post($detail.'/deposit/hold')
        ->assertRedirect($detail);

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD);

    $this->withoutVite()->actingAs($assistant)
        ->get($detail)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canUpdate', true)
            ->where('canApprove', false)
            ->has('approveReason'));

    // Not merely greyed: the route carries the higher gate.
    $this->actingAs($assistant)
        ->post($detail.'/deposit/forfeit', ['reason' => 'Two chairs broken.'])
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD);
});

it('forfeits a deposit through the door only with a reason, and books it as income', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $booking = doorBooking($this->amenities, 108);
    $this->amenities->holdDeposit($booking);

    $detail = FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id);
    $held = facilitiesNetDebits('2200');
    $income = facilitiesNetDebits('4100');

    // A blank reason is refused before the service is reached, and the money
    // stays exactly where it was.
    $this->actingAs($manager)
        ->post($detail.'/deposit/forfeit', ['reason' => '   '])
        ->assertSessionHasErrors('reason');

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and(facilitiesNetDebits('2200'))->toBe($held);

    $this->actingAs($manager)
        ->post($detail.'/deposit/forfeit', ['reason' => 'Gazebo returned with the lighting rig broken.'])
        ->assertRedirect($detail);

    FacilitiesFixture::boot();

    $kept = AmenityBooking::findOrFail($booking->id);

    // Dr 2200, Cr 4100: the liability discharged and the same figure earned.
    expect($kept->deposit_state)->toBe(AmenityBooking::DEPOSIT_FORFEITED)
        ->and($kept->deposit_forfeit_reason)->toBe('Gazebo returned with the lighting rig broken.')
        ->and(facilitiesNetDebits('2200') - $held)->toBe(5_000_00)
        ->and(facilitiesNetDebits('4100') - $income)->toBe(-5_000_00);

    // And the rail tells it from the entry, reason and all.
    $closed = $this->amenities->bookingBoard($kept)['deposit']['timeline'][2];

    expect($closed['label'])->toBe('Forfeited')
        ->and($closed['state'])->toBe('done')
        ->and($closed['line'])->toContain((string) $kept->deposit_journal_ref)
        ->and($closed['line'])->toContain('lighting rig');
});

it('takes a deposit once, and never for a booking that will not happen', function () {
    $declined = doorBooking($this->amenities, 112);
    $this->amenities->decline($declined, 'The Gazebo is being re-roofed that week.');

    expect(fn () => $this->amenities->holdDeposit($declined->refresh()))
        ->toThrow(DomainException::class, 'was declined');

    $returned = doorBooking($this->amenities, 114);
    $this->amenities->holdDeposit($returned);
    $this->amenities->refundDeposit($returned->refresh());

    // Taking it again would post money to the bank that has already gone back.
    expect(fn () => $this->amenities->holdDeposit($returned->refresh()))
        ->toThrow(DomainException::class, 'has been refunded');
});

/* ------------------------------------------------------------------ */
/* the rate card's own writes — add and edit (12 §2, Wave 2) */
/* ------------------------------------------------------------------ */

it('adds an amenity with its terms together, and edits one without moving a confirmed booking', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $this->withoutVite()->actingAs($president)
        ->get(FacilitiesFixture::url('/facilities/amenities/settings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canUpdate', false)
            ->where('canCreate', false)
            ->has('icons', 4)
            ->has('rows.0.terms'));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/facilities/amenities'), ['name' => 'Tennis Court', 'icon_key' => 'pavilion', 'capacity' => 4, 'opens_at' => '06:00', 'closes_at' => '20:00'])
        ->assertForbidden();

    /*
     * THE COURT COMES BACK OFF THE CARD WHETHER OR NOT THIS TEST GETS THAT FAR (M10, baseline).
     * The retire at the end is under test and still runs; this is the net under it. Board 20
     * draws exactly four cards, and a Tennis Court left active by a failure further down made
     * two more tests fail on a card count that had nothing to do with them.
     */
    undoAfter($this, static function (): void {
        Amenity::query()->where('name', 'Tennis Court')->where('is_active', true)->update(['is_active' => false]);
    });

    // Added: a blank fee is "Free for residents" and a blank deposit "None" —
    // NULL, never zero — and the card lists it last.
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/amenities'), [
            'name' => 'Tennis Court',
            'icon_key' => 'pavilion',
            'capacity' => 4,
            'fee' => '',
            'deposit' => '2500.00',
            'opens_at' => '06:00',
            'closes_at' => '20:00',
            'booking_window_days' => 14,
            'cancellation_hours' => 12,
        ])
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/settings'));

    FacilitiesFixture::boot();

    $court = Amenity::query()->where('name', 'Tennis Court')->firstOrFail();

    expect($court->booking_fee_minor)->toBeNull()
        ->and($court->deposit_minor)->toBe(2_500_00)
        ->and($court->feeLabel())->toBe(Amenity::FREE_LABEL)
        ->and($court->hoursLabel())->toBe('6:00 AM – 8:00 PM')
        ->and($court->is_bookable)->toBeTrue()
        ->and($court->sort_order)->toBe((int) Amenity::query()->where('id', '!=', $court->id)->max('sort_order') + 1);

    // Refused: a second Tennis Court, a closing time before the opening one.
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/amenities'), ['name' => 'tennis court', 'icon_key' => 'pavilion', 'capacity' => 4, 'opens_at' => '06:00', 'closes_at' => '20:00'])
        ->assertSessionHasErrors('name');

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/amenities'), ['name' => 'Sauna', 'icon_key' => 'pool', 'capacity' => 4, 'opens_at' => '20:00', 'closes_at' => '06:00'])
        ->assertSessionHasErrors('name');

    /*
     * THE EDIT. The Gazebo's fee doubles and its deposit triples under a
     * booking whose deposit the estate is already holding; the booking does
     * not move by a cent, and the next one copies the new terms.
     */
    $gazebo = FacilitiesFixture::amenity('Gazebo');
    $held = FacilitiesFixture::booking('Gazebo', 'Lot 47');
    $heldBefore = [$held->fee_minor, $held->deposit_minor, $held->cancellation_hours];

    /*
     * THE CARD GOES BACK AFTERWARDS (M4 §4, defect 3). The Gazebo is the estate's
     * one seeded amenity with a deposit, and this estate's database is committed,
     * not rolled back — so leaving its deposit at J$15,000 restated it for every
     * file that read the Gazebo after this one. The Resident App's contract walk
     * books it and asserts the seeded J$5,000, and was failing on exactly that
     * whenever this suite ran first. The edit below is still made in full and
     * still asserted; the terms are simply put back when it has been.
     */
    $cardBefore = $gazebo->only([
        'name', 'icon_key', 'capacity', 'booking_fee_minor', 'deposit_minor',
        'opens_at', 'closes_at', 'booking_window_days', 'cancellation_hours', 'is_bookable',
    ]);

    // And put back again after the test even if an assertion below fails first: see `putBack()`.
    putBack($this, $gazebo);

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$gazebo->id), [
            'name' => 'Gazebo',
            'icon_key' => 'gazebo',
            'capacity' => 30,
            'fee' => '6000.00',
            'deposit' => '15000.00',
            'opens_at' => '08:00',
            'closes_at' => '22:00',
            'booking_window_days' => 60,
            'cancellation_hours' => 48,
            'is_bookable' => true,
        ])
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/settings'));

    FacilitiesFixture::boot();

    $gazebo = Amenity::query()->findOrFail($gazebo->id);
    $held = AmenityBooking::query()->findOrFail($held->id);

    expect($gazebo->booking_fee_minor)->toBe(6_000_00)
        ->and($gazebo->deposit_minor)->toBe(15_000_00)
        ->and($gazebo->cancellation_hours)->toBe(48)
        ->and([$held->fee_minor, $held->deposit_minor, $held->cancellation_hours])->toBe($heldBefore);

    $next = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 9'),
        residentName: 'Marcia Brown',
        startsAt: SiteTime::today()->addDays(120)->addHours(11),
        endsAt: SiteTime::today()->addDays(120)->addHours(13),
    );

    expect($next->deposit_minor)->toBe(15_000_00)->and($next->fee_minor)->toBe(6_000_00);

    // The card the seeder drew, back on the shared Gazebo. The booking above keeps
    // the terms it was made under — which is the guarantee this whole file exists
    // for — so putting the amenity back proves nothing away.
    $gazebo->forceFill($cardBefore)->save();

    expect(Amenity::query()->findOrFail($gazebo->id)->deposit_minor)->toBe(5_000_00)
        ->and(AmenityBooking::query()->findOrFail($next->id)->deposit_minor)->toBe(15_000_00);

    // Retired: gone from the card, kept with its history, never deleted.
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$court->id), [
            'name' => 'Tennis Court',
            'icon_key' => 'pavilion',
            'capacity' => 4,
            'opens_at' => '06:00',
            'closes_at' => '20:00',
            'retire' => true,
        ])
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/settings'));

    FacilitiesFixture::boot();

    expect(Amenity::query()->findOrFail($court->id)->is_active)->toBeFalse()
        ->and(collect($this->amenities->settingsBoard()['rows'])->contains('name', 'Tennis Court'))->toBeFalse();
});

it('draws the booking detail without one figure about a household financial position', function () {
    $board = $this->amenities->bookingBoard(FacilitiesFixture::booking('Gazebo', 'Lot 47'));

    $serialised = strtolower((string) json_encode($board));

    foreach (['balance', 'arrear', 'bucket', 'receivable', 'owed', 'outstanding', 'statement', 'ageing'] as $forbidden) {
        expect($serialised)->not->toContain(
            $forbidden,
            "the booking detail's payload contained the word [{$forbidden}], which is a household's financial position"
        );
    }

    // Board 19's held Gazebo, told from its entry: quoted, held, and the one
    // step left to take.
    expect($board['deposit']['state'])->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and(array_column($board['deposit']['timeline'], 'state'))->toBe(['done', 'done', 'active'])
        ->and($board['deposit']['timeline'][1]['line'])->toContain('JV-');
});
