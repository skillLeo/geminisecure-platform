<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Services\Estate\Dues;
use App\Services\Estate\Residents;
use App\Services\Restriction\RestrictionPolicy;
use App\Support\TableQuery;
use Brick\Money\Money;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\Estate\ResidentsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The register, the structure, and the one figure these screens may not print
|--------------------------------------------------------------------------
|
| TWO THINGS ARE BEING PROVEN HERE AND THEY ARE NOT THE SAME KIND OF THING.
|
| The first is arithmetic. Board 3's phase cards and board 4's Balance column are
| both derived — the counts from `units` and the balances from posted lines on
| 1200 Dues Receivable — and both are re-summed below in raw SQL written out
| longhand, so the expectation and the application reach the same number by two
| different routes. A test that called the method it was checking would prove
| only that the method is deterministic.
|
| The second is a REFUSAL, and it is the reason this file exists. A household in
| arrears past the threshold is restricted, and what a screen may say about that
| is settled: amber and the word. Never the balance, never the number of days,
| never an ageing bucket. Three screens draw a household's standing — the
| register, the claim review and the resident detail — and all three read it out
| of one function, so the assertions below scan the whole serialised standing for
| a digit and for the vocabulary of money rather than checking one remembered
| field. A leak that arrived as a new key would fail this file.
|
| AND THE SAME WORDS AS THE GATE. `Residents::RESTRICTED_LABEL` is asserted equal
| to what `RestrictionPolicy` hands a guard, because a resident told one thing at
| a gate and another at the estate office is the failure invariant 2 is written
| against.
|
| NOTHING HERE POSTS TO THE LEDGER, and one test proves it of the one act that
| could: approving a unit claim changes who is authorised against a unit and
| changes nothing about what that unit owes.
|
*/

/**
 * The estate these tests read, built once per process.
 *
 * A DATABASE OF ITS OWN, for the same reason `payablesEstate()` has one: the
 * figures under test are the boards' own, and reading the development estate
 * would make the suite pass or fail on whatever somebody last seeded there. It
 * is built by the seeder that builds Phoenix Park, because what is being proven
 * includes that the register extends that estate rather than rebuilding it.
 */
function residentsEstate(): string
{
    static $built = false;

    // The configured prefix, never the literal: see `FacilitiesFixture::database()`.
    $database = config('tenancy.database.prefix').'residentstest';

    /*
     * ALREADY POINTED AT IT? RETURN, AND ABOVE ALL DO NOT PURGE.
     *
     * `DB::purge` disconnects, and a disconnect throws away the per-test transaction opened in
     * `beforeEach` below along with everything the test has written inside it. This used to purge
     * on every call, which was harmless only because nothing was ever rolled back — and that is
     * precisely why the fixture accumulated rows from one test to the next.
     */
    if (config('database.default') === 'tenant' && config('database.connections.tenant.database') === $database) {
        return $database;
    }

    config([
        'database.connections.tenant' => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        ),
        'database.default' => 'tenant',
    ]);

    DB::purge('tenant');

    if ($built) {
        return $database;
    }

    $owner = DB::connection('mysql_owner');
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::purge('tenant');

    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--database' => 'tenant',
        '--force' => true,
    ]);

    Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);

    $built = true;

    return $database;
}

/** Runs a closure inside the estate under test. */
function inResidentsEstate(callable $work): mixed
{
    residentsEstate();

    return $work();
}

/**
 * What one unit owes, summed in raw SQL.
 *
 * Written out longhand on purpose, and debits less credits because 1200 is an
 * asset: a charge debits it and a receipt credits it back down. This is the
 * second, independent route to every figure board 4 draws in its Balance column.
 */
function residentUnitBalance(int $unitId): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unitId])->owed;
}

/**
 * A restricted household of its own, at a unit nothing else touches, WITH A REAL
 * BALANCE BEHIND IT.
 *
 * ITS OWN UNIT, EVERY TIME. These assertions restrict a household and then read
 * three screens about it; sharing one household between them would make the
 * order the tests run in decide what a screen says about somebody's arrears.
 * The lot numbers are high enough to sit outside the estate's own 450.
 *
 * THE CHARGE MATTERS AS MUCH AS THE FLAG. A restricted household with an empty
 * ledger would let every assertion below pass while the withholding did nothing
 * — there would be no figure to withhold. It is posted through `Dues` like any
 * other charge, so the balance the screens are refusing to print is a real one
 * the accounts actually hold.
 */
function restrictedHouseholdAt(string $reference, int $balanceMinor = 18_600_00): Household
{
    $unit = Unit::query()->firstOrCreate(
        ['reference' => $reference],
        ['block' => 'Phase 1', 'street' => 'Phase 1 Drive', 'type' => 'residential', 'status' => 'occupied'],
    );

    $household = Household::query()->firstOrCreate(
        ['unit_id' => $unit->id],
        ['name' => $reference.' household', 'access_restricted' => true],
    );

    $fresh = $household->wasRecentlyCreated;

    $household->forceFill(['access_restricted' => true, 'last_active_at' => now()])->save();

    Resident::query()->firstOrCreate(
        ['household_id' => $household->id, 'full_name' => 'Restricted Householder'],
        ['relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED],
    );

    if ($fresh) {
        app(Dues::class)->charge(
            unit: $unit,
            amount: Money::ofMinor($balanceMinor, 'JMD'),
            description: 'Maintenance fee — arrears brought forward',
            dueOn: Carbon::today()->startOfMonth()->subMonths(3),
        );
    }

    return $household->refresh();
}

/**
 * Every word and every digit a restricted household's payload must not contain.
 *
 * SCANNED OVER THE VALUES AND NOT THE KEYS. The shape itself carries the words
 * `balance_minor` and `bucket`, which is correct — a payload that answered
 * "restricted" by omitting the keys would leak the answer by its shape, and a
 * page reading `standing.balance_minor` must get an explicit null rather than an
 * undefined. What must never appear is a VALUE that says any of it.
 *
 * The wording list is `RestrictionPolicyTest`'s, because a screen and a gate are
 * answering the same question and must not diverge. THE DIGIT CHECK IS THE
 * STRONGER HALF: an amount that arrived under a key nobody thought of is still a
 * number on a screen, and this catches it whatever it is called.
 *
 * @param  array<string, mixed>  $payload
 */
function expectNoFigureIn(array $payload): void
{
    $values = [];

    array_walk_recursive($payload, function ($value) use (&$values): void {
        if ($value !== null && ! is_bool($value)) {
            $values[] = (string) $value;
        }
    });

    $serialised = strtolower(implode(' | ', $values));

    foreach (['balance', 'owed', 'arrear', 'amount', 'overdue', 'debt', 'invoice', 'jmd', 'j$'] as $forbidden) {
        expect($serialised)->not->toContain($forbidden);
    }

    expect(preg_match('/\d/', $serialised))->toBe(0);
}

/**
 * EVERY TEST HERE READS THE ESTATE AS THE SEEDER LEFT IT, whatever order they run in.
 *
 * This is the fix for M4 §4 defect 3, applied at the place that caused it. The fixture is one
 * committed database built once per process, and half the tests below WRITE to it — a restricted
 * household needs a lot of its own, a claim review needs a claim. The counting tests ("450 units",
 * "433 occupied", "3 pending claims", "J$1,840,000 on 1200") used to be true only because they
 * were declared above the writing ones, and a comment here said so. `phpunit.xml` now runs the
 * suite in RANDOM order, so a comment about declaration order guarantees nothing at all.
 *
 * So every test runs inside a transaction on the estate connection and none of it is kept. The
 * counts below are the seeder's, permanently, and a test may write whatever it needs to without
 * deciding what the next one sees.
 *
 * `beginTransaction` is safe to nest here because nothing in this file issues DDL, and the estate
 * is read through the `tenant` connection only — there is no second connection to this database
 * that could miss the transaction.
 *
 * AND THE SECOND HALF OF THE REPAIR (M6 §3.1): the totals themselves moved. A transaction stops one
 * test's writes reaching another's, but it does not make "450 units" the right assertion for a test
 * about whether a phase card is derived or stored — that test would still go red the day somebody
 * adds a lot to the demonstration estate, which three other work packages are doing right now. Every
 * total of the whole estate is asserted once, in `reads the demonstration estate exactly as its
 * seeders build it`; everything else ties a board to the estate in front of it at whatever size it
 * is. Please do not put a bare 450, 433, 3 or 1_840_000_00 back into a test about something else.
 */
beforeEach(function (): void {
    residentsEstate();

    DB::connection('tenant')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('tenant')->rollBack();
});

/*
 * AND THE WRAPPER ABOVE IS ITSELF UNDER TEST (M4 §4, defect 3).
 *
 * Everything this file claims about the estate rests on one `beginTransaction` that no
 * individual test mentions, so removing it breaks the file in a way that shows up as three
 * arithmetic failures somewhere else on some seeds and on no others — which is exactly how
 * this was found. Asserted here so that deleting the wrapper fails immediately, in words
 * that say what was deleted.
 */
it('runs every test in this file inside a transaction on the estate, so nothing it writes outlives it', function (): void {
    expect(DB::connection('tenant')->transactionLevel())
        ->toBeGreaterThan(0, 'The per-test transaction in beforeEach is gone. Without it every write below is kept in a database shared by the whole run, and the seeded counts this file asserts become true only in one order.')
        ->and(config('database.connections.tenant.database'))
        ->toBe(residentsEstate(), 'This file must read its own estate, not whichever fixture booted last.');
});

/*
 * THE FIXTURE'S OWN TOTALS, ASSERTED IN ONE PLACE AND NOWHERE ELSE (M6 §3.1).
 *
 * Three tests below used to assert a total of the whole estate in passing — 450 units, 433
 * occupied, three pending claims, J$1,840,000 on 1200 — while their real subject was something
 * else entirely. Those are the assertions that went red at 454 units and four pending claims when
 * a test above had kept its writes, and the per-test transaction is what stopped that.
 *
 * A total is still the wrong thing for those tests to carry, in ANY order. None of them is about
 * how big this estate is, and every one of them would go red the day somebody adds a lot to the
 * demonstration estate — in a test whose name promises nothing about lots. So the totals live here,
 * once, in the test whose whole subject IS the fixture, saying in its own failure message what a
 * mismatch means. Every test below now ties the boards to the estate it is actually reading,
 * whatever size that estate is.
 */
it('reads the demonstration estate exactly as its seeders build it', function (): void {
    $counts = DB::connection('tenant')->selectOne("
        SELECT (SELECT COUNT(*) FROM units) AS units,
               (SELECT COUNT(*) FROM units WHERE status = 'occupied') AS occupied,
               (SELECT COUNT(*) FROM unit_claims WHERE status = ?) AS pending,
               (SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0)
                  FROM journal_lines l
                  JOIN accounts a ON a.id = l.account_id
                 WHERE a.code = '1200') AS receivable
    ", [UnitClaim::PENDING]);

    $moved = 'The fixture estate is not what its seeders build. Either a test before this one kept its '
        .'writes, which the transaction in beforeEach exists to prevent, or the seeders themselves have '
        .'changed — in which case the board figures this file checks need re-reading against the boards. '
        .'Nothing else in this file asserts a total of the whole estate, so this is the only place to fix.';

    expect((int) $counts->units)->toBe(450, $moved)
        ->and((int) $counts->occupied)->toBe(433, $moved)
        ->and((int) $counts->pending)->toBe(3, $moved)
        ->and((int) $counts->receivable)->toBe(1_840_000_00, $moved);
});

/* ------------------------------------------------------------------ */
/* board 3 — every count on the structure screen is the units, regrouped */
/* ------------------------------------------------------------------ */

it('derives every phase card from the units rather than from a stored count', function () {
    inResidentsEstate(function () {
        $board = app(Residents::class)->structureBoard();
        $cards = collect($board['phases'])->keyBy('name');

        expect($cards)->toHaveCount(5);

        foreach ($cards as $name => $card) {
            // The same two figures, counted independently in raw SQL. A phase
            // row that carried its own unit total would agree with this by
            // coincidence and would stop agreeing the first time a lot moved.
            $counted = DB::connection('tenant')->selectOne("
                SELECT COUNT(*) AS units, SUM(status = 'occupied') AS occupied
                  FROM units WHERE block = ?
            ", [$name]);

            expect($card['unit_count'])->toBe((int) $counted->units)
                ->and($card['occupied'])->toBe((int) $counted->occupied)
                ->and($card['vacant'])->toBe($card['unit_count'] - $card['occupied']);
        }

        /*
         * The cross-screen tie board 3 states outright: the five cards sum to
         * the units and the households the dashboard reports, and nothing
         * stands outside a phase — which is the failure a name-keyed join can
         * have and a foreign key cannot.
         *
         * COUNTED FROM THE ESTATE, NOT WRITTEN DOWN. This read `toBe(450)` and
         * `toBe(433)`, and that is what went red at 454 when a test above kept
         * the lots it had created. The claim here is that the cards ARE the
         * units regrouped — a claim that has to hold at any size — so the
         * expectation is re-derived in SQL beside the board rather than being a
         * figure that happens to be true of one seeding. The fixture's own 450
         * and 433 are asserted once, in the test that is about the fixture.
         */
        $estate = DB::connection('tenant')->selectOne("
            SELECT COUNT(*) AS units, COALESCE(SUM(status = 'occupied'), 0) AS occupied FROM units
        ");

        expect((int) $estate->units)->toBeGreaterThan(0)
            ->and($board['totals']['units'])->toBe((int) $estate->units)
            ->and($board['totals']['occupied'])->toBe((int) $estate->occupied)
            ->and($board['unplaced_units'])->toBe(0)
            ->and(array_sum(array_column($board['phases'], 'unit_count')))->toBe((int) $estate->units)
            ->and(array_sum(array_column($board['phases'], 'occupied')))->toBe((int) $estate->occupied);
    });
});

it('draws board 3 with the blocks and officers the board prints, and Phase 5 with words', function () {
    inResidentsEstate(function () {
        $cards = collect(app(Residents::class)->structureBoard()['phases'])->keyBy('name');

        // M78 §3: Hassam's words, "19 blocks · 3 officers" (it read "6 blocks · 3 phase officers assigned").
        expect($cards['Phase 1']['foot'])->toBe('6 blocks · 3 officers')
            ->and($cards['Phase 2']['tag'])->toBe('104 units')
            ->and($cards['Phase 4']['foot'])->toBe('6 blocks · 2 officers')

            /*
             * Phase 5 has no officer count and words instead — null is "nobody
             * has been appointed yet", which is a different fact from nobody
             * being appointed and is the only one a zero could not say. M78 §3:
             * the words are "officers pending" (they read "new phase, officers
             * pending", the seeded footnote).
             */
            ->and($cards['Phase 5']['officers_assigned'])->toBeNull()
            ->and($cards['Phase 5']['foot'])->toBe('4 blocks · officers pending')

            /*
             * CONTENT RESIDUAL, asserted rather than reproduced. Board 3 gives
             * Phase 5 seventy units and seventy-eight occupied, which is not
             * something one estate can be. The occupancy is clamped where the
             * estate is built, so the card reads 70 of 70 and the bar reads
             * 100% — a card printing more households than houses would be a
             * screen contradicting itself in public.
             */
            ->and($cards['Phase 5']['unit_count'])->toBe(70)
            ->and($cards['Phase 5']['occupied'])->toBe(70)
            ->and($cards['Phase 5']['occupancy_pct'])->toBe(100);

        expect(Phase::query()->orderBy('sequence')->pluck('name')->all())
            ->toBe(['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5']);
    });
});

/* ------------------------------------------------------------------ */
/* board 4 — the Balance column is the ledger, read back */
/* ------------------------------------------------------------------ */

it('traces every balance on the register to that unit\'s own lines on 1200', function () {
    inResidentsEstate(function () {
        $rows = app(Residents::class)->listBoard(maySeeMoney: true)['rows'];

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            // A restricted household has no figure to trace, and that is the
            // subject of its own tests below rather than an exception to this
            // one.
            if ($row['standing']['restricted']) {
                expect($row['standing']['balance_minor'])->toBeNull();

                continue;
            }

            // Nothing here is summed from `charges` less `payments`. A screen
            // with its own arithmetic over the billing tables agrees with the
            // accounts by coincidence, and coincidence fails at an audit.
            expect($row['standing']['balance_minor'])->toBe(residentUnitBalance($row['unit_id']));
        }

        /*
         * AND NOTHING ON 1200 IS STRANDED OFF A UNIT.
         *
         * This asserted the estate's whole receivable as a figure — J$1,840,000
         * — and that is the assertion that went wrong by exactly the leak: a
         * fixture household charged by a test above added its arrears to a total
         * a test below was reading. A total of the estate is now asserted once,
         * where the fixture is, and 1200 is checked HERE for the thing this
         * test is actually about: every cent on the account belongs to some
         * unit's Balance column. A charge posted against no unit at all would
         * sit inside the estate's arrears and appear on nobody's row, which is
         * the way a resident's debt goes missing from the only screen that
         * would show it. It holds at any size and at any total.
         */
        $account = DB::connection('tenant')->selectOne('
            SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed,
                   COALESCE(SUM(CASE WHEN l.unit_id IS NULL THEN 0
                                     ELSE l.debit_minor - l.credit_minor END), 0) AS on_units,
                   COUNT(*) AS posted
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE a.code = ?
        ', ['1200']);

        // `posted`, not `lines`: LINES is a reserved word in MySQL 8 and the
        // alias is a syntax error at the server, not in PHP.
        expect((int) $account->posted)->toBeGreaterThan(0)
            ->and((int) $account->on_units)->toBe((int) $account->owed);

        $andrea = collect($rows)->firstWhere('name', 'Andrea Fletcher');

        // Board 38's hero figure and board 6's unit ledger, on the register.
        expect($andrea['standing']['balance_minor'])->toBe(12_400_00)
            ->and($andrea['standing']['label'])->toBe('$12,400')
            ->and($andrea['standing']['bucket'])->toBe('d30')
            ->and($andrea['unit'])->toBe('Phase 2 · Lot 47');
    });
});

it('counts the pending claims once, so the banner and the review screen agree', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        $banner = $residents->listBoard(maySeeMoney: true)['pending'];
        $claims = $residents->claimsBoard(maySeeMoney: true);

        /*
         * Two counts of one thing is how a banner comes to promise three claims
         * to a screen holding two.
         *
         * AND THE ONE THING IS COUNTED IN THE DATABASE, not written down here.
         * All five of these read `3`, and a literal is the wrong shape for this
         * test twice over: it went red at four the day a test above left a claim
         * behind, and it would go red again the day the demonstration estate is
         * given another claim on purpose — in a test whose subject is that the
         * two screens AGREE, not how many there are. The register's own total is
         * asserted once, where the fixture is.
         */
        $pending = (int) DB::connection('tenant')->selectOne(
            'SELECT COUNT(*) AS n FROM unit_claims WHERE status = ?',
            [UnitClaim::PENDING],
        )->n;

        // More than one, so the plural wording below is the wording under test.
        expect($pending)->toBeGreaterThan(1);

        expect($banner['count'])->toBe($pending)
            ->and($banner['title'])->toBe($pending.' unit claims need review')
            ->and($claims['pending_count'])->toBe($pending)
            ->and($claims['title'])->toBe('Unit claims · '.$pending.' pending')
            ->and($claims['claims'])->toHaveCount($pending);
    });
});

it('flags a household under review only where somebody disputes who holds it', function () {
    inResidentsEstate(function () {
        $rows = collect(app(Residents::class)->listBoard(maySeeMoney: true)['rows'])->keyBy('name');

        /*
         * Board 38 settles this: Marlon Fletcher reads "Added Sep 4" in the
         * members panel while the hero pill above him reads "Verified". His
         * membership claim is in the queue and nobody is disputing that Lot 47
         * is Andrea's, so her row is not amber. Keith Walters's is — somebody
         * the register does not recognise is claiming to be the resident there.
         */
        expect($rows['Andrea Fletcher']['verification_label'])->toBe('Verified')
            ->and($rows['K. A. Walters']['verification_label'])->toBe('Pending review')
            ->and($rows['Dwayne Robinson']['verification_label'])->toBe('Verified');
    });
});

/* ------------------------------------------------------------------ */
/* the invariant — amber and the word, on all three screens that draw it */
/* ------------------------------------------------------------------ */

it('gives a restricted household the same words a guard is given, and no figure', function () {
    inResidentsEstate(function () {
        $household = restrictedHouseholdAt('Lot 901');

        $standing = app(Residents::class)->standingOf($household, maySeeMoney: true);

        /*
         * `maySeeMoney` is TRUE here on purpose. This is a Treasurer — the role
         * that may read every balance in the estate — and the figure is still
         * withheld, because restriction is answered before the ledger is
         * consulted at all. A test that passed a viewer who could not see money
         * anyway would prove nothing about restriction.
         */
        expect($standing['restricted'])->toBeTrue()
            ->and($standing['tone'])->toBe('amber')
            ->and($standing['label'])->toBe(Residents::RESTRICTED_LABEL)
            ->and($standing['balance_minor'])->toBeNull()
            ->and($standing['bucket'])->toBeNull()
            ->and($standing['bucket_label'])->toBeNull();

        // The same sentence the gate uses. A resident told one thing at a gate
        // and another at the office is what invariant 2 is written against.
        expect(Residents::RESTRICTED_LABEL)
            ->toBe((new RestrictionPolicy)->decide($household, 'guest')['reason']);

        expectNoFigureIn($standing);
    });
});

it('withholds the figure from the register, the detail screen and the claim review alike', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $household = restrictedHouseholdAt('Lot 902');
        $unit = $household->unit;

        // A REAL BALANCE BEHIND IT. Every assertion below is withholding
        // something the accounts actually hold, rather than reporting an empty
        // ledger and passing for the wrong reason.
        expect(residentUnitBalance($unit->id))->toBe(18_600_00);

        /* -------- board 4, the register -------- */
        $row = collect($residents->listBoard(maySeeMoney: true)['rows'])
            ->firstWhere('unit_id', $unit->id);

        expect($row)->not->toBeNull()
            ->and($row['standing']['label'])->toBe(Residents::RESTRICTED_LABEL);

        expectNoFigureIn($row['standing']);

        /* -------- board 38, the resident detail -------- */
        $detail = $residents->residentBoard($unit, maySeeMoney: true);
        $balanceStat = collect($detail['stats'])->firstWhere('key', 'balance');

        expect($balanceStat['value'])->toBe(Residents::RESTRICTED_LABEL)
            ->and($balanceStat['value_minor'])->toBeNull()
            ->and($balanceStat['tone'])->toBe('amber');

        /*
         * And the cross-link is ABSENT rather than blanked. A "Dues & ledger"
         * row with its figure struck out still says there are arrears, which is
         * the one thing this household's screen may not say.
         */
        expect(array_column($detail['linked'], 'type'))->not->toContain('ledger');

        expectNoFigureIn($detail['standing']);

        /* -------- board 31, the claim review -------- */
        UnitClaim::query()->firstOrCreate(
            ['unit_id' => $unit->id, 'submitted_name' => 'Restricted Claimant'],
            [
                'claim_type' => UnitClaim::TYPE_UNVERIFIED,
                'submitted_phase' => $unit->block,
                'submitted_lot' => $unit->reference,
                'match_result' => 'none',
                'review_flag' => 'Verify identity before approving',
                'status' => UnitClaim::PENDING,
            ],
        );

        $card = collect($residents->claimsBoard(maySeeMoney: true)['claims'])
            ->firstWhere('title', 'Unknown claimant · '.$unit->block.' · '.$unit->reference);

        expect($card)->not->toBeNull();

        // The On-record column names the resident and appends no ageing to it.
        // "90+ days" is the number of days, and days are what a restricted
        // household never shows.
        foreach ($card['record']['rows'] as $recordRow) {
            expect($recordRow['value'])->not->toContain('days')
                ->and($recordRow['value'])->not->toContain('arrears');
        }

        expectNoFigureIn($card['standing']);
    });
});

it('shows a role locked out of the ledger no balance anywhere on the register', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        /*
         * D-010: the Property Manager holds Full on Residents and nothing at
         * all on Dues & ledger — "whoever commissions work must never be able
         * to pay for it, nor see a resident's financial position". A resident
         * detail screen that printed a balance would be a side door into
         * exactly that.
         */
        /*
         * WHAT THE CONTROLLER ACTUALLY PASSES FOR THEM: no ledger, and so not
         * the estate's position either (`Dues::maySeeEstatePosition()`).
         *
         * ON THE LIST, NOT EVEN THE VERDICT (M10 §5). A household is restricted
         * because it is behind, so amber on some rows of a register is a list of
         * who is behind, and every row reads "Balance not shown" alike. The
         * verdict is still theirs one household at a time — board 38, below.
         */
        $restricted = restrictedHouseholdAt('Lot 909');

        foreach ($residents->listBoard(maySeeMoney: false, maySeePosition: false)['rows'] as $row) {
            expect($row['standing']['balance_minor'])->toBeNull()
                ->and($row['standing']['bucket'])->toBeNull()
                ->and($row['standing']['restricted'])->toBeFalse()
                ->and($row['standing']['label'])->toBe(Residents::WITHHELD_LABEL);
        }

        /*
         * One household in front of them, and they ARE told the gate is shut:
         * restriction is answered before the ledger gate, because it is a fact
         * about the household and a viewer who may not see money still needs it.
         */
        $verdict = $residents->residentBoard($restricted->unit, maySeeMoney: false);

        expect($verdict['standing']['restricted'])->toBeTrue()
            ->and($verdict['standing']['label'])->toBe(Residents::RESTRICTED_LABEL);

        $unit = Unit::query()->where('reference', 'Lot 47')->firstOrFail();
        $detail = $residents->residentBoard($unit, maySeeMoney: false);

        expect($detail['standing']['balance_minor'])->toBeNull()
            ->and(collect($detail['stats'])->firstWhere('key', 'balance')['value_minor'])->toBeNull()
            ->and(array_column($detail['linked'], 'type'))->not->toContain('ledger')

            /*
             * And the open-item count follows the links this viewer can
             * actually see. Counting a link that is not drawn would let
             * somebody learn a withheld balance was non-zero by reading a
             * number that was one higher than the rows beneath it.
             */
            ->and(collect($detail['stats'])->firstWhere('key', 'open_items')['value'])
            ->toBe(count(array_filter($detail['linked'], static fn (array $l): bool => $l['is_open_item'])));
    });
});

it('keeps the ageing flag on a claim behind ledger access while Q-014 stands open', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        // The flag names the assumption. When the client rules the other way
        // this constant goes and so does the second half of this test.
        expect(Residents::ARREARS_FLAG_NEEDS_LEDGER_ACCESS)->toBeTrue();

        // Found by the claimant's own name rather than by position, so an
        // unrelated claim added by another test cannot silently change what
        // this one is asserting about.
        $simms = function (bool $money) use ($residents): string {
            $card = collect($residents->claimsBoard($money)['claims'])
                ->first(fn (array $c): bool => str_starts_with($c['submitted']['rows'][0]['value'], 'Tanya Simms'));

            return $card['record']['rows'][0]['value'];
        };

        /*
         * Board 31's own persona is the Property Manager and its third card
         * prints "Tanya Simms — 90+ days arrears". An ageing bucket is a
         * financial position, which D-010 locks that role out of, so the safe
         * reading is that the bucket needs ledger access — a Treasurer sees it
         * and a Property Manager sees the identity flag alone.
         */
        // The board's em dash is the console's middot now (M53 Law 3).
        expect($simms(true))->toBe('Tanya Simms · 90+ days arrears')
            ->and($simms(false))->toBe('Tanya Simms');
    });
});

/* ------------------------------------------------------------------ */
/* board 31 — the comparison is the screen */
/* ------------------------------------------------------------------ */

it('draws the three claims with the columns and the amber the board draws', function () {
    inResidentsEstate(function () {
        // Keyed on the CLAIMANT, not on the claim type. Other tests in this file
        // queue claims of their own, and keying on a type that repeats would
        // quietly swap which card each assertion below was reading.
        $cards = collect(app(Residents::class)->claimsBoard(maySeeMoney: true)['claims'])
            ->keyBy(fn (array $c): string => $c['submitted']['rows'][0]['value']);

        $unit = $cards['Keith Walters'];
        $member = $cards['Marlon Fletcher'];
        $unknown = $cards['Tanya Simms'];

        /*
         * Card 1 flags only the On-record column: the claimant wrote his name
         * in full where the register holds initials, and gave a phone the
         * estate does not hold at all. Two differences, neither of them a
         * reason to refuse him — which is why the outline action asks for a
         * document rather than rejecting.
         */
        expect($unit['submitted']['mismatch'])->toBeFalse()
            ->and($unit['record']['mismatch'])->toBeTrue()
            ->and($unit['record']['rows'][0]['value'])->toBe('K. A. Walters')
            ->and($unit['record']['rows'][2]['value'])->toBe('Not on file')
            ->and(array_column($unit['actions'], 'label'))->toBe(['Request ID document', 'Approve claim']);

        /*
         * Card 2 flags neither. The estate matched it exactly — Marlon Fletcher
         * is already on the register as a member of this household — so the
         * only question left is whether the estate agrees, and the outline
         * action is a refusal rather than another verification step.
         */
        expect($member['submitted']['mismatch'])->toBeFalse()
            ->and($member['record']['mismatch'])->toBeFalse()
            ->and($member['record']['rows'][2]['value'])->toBe('Exact')
            ->and(array_column($member['actions'], 'label'))->toBe(['Reject', 'Approve claim']);

        // Card 3 flags both: a claimant the estate cannot place, against a lot
        // somebody has a reason to want authorisation on.
        expect($unknown['submitted']['mismatch'])->toBeTrue()
            ->and($unknown['record']['mismatch'])->toBeTrue()
            ->and($unknown['title'])->toContain('Unknown claimant')
            ->and($unknown['record']['rows'][1]['value'])->toBe('Verify identity before approving');
    });
});

/* ------------------------------------------------------------------ */
/* board 38 — one household in full */
/* ------------------------------------------------------------------ */

it('draws Andrea Fletcher\'s household as board 38 draws it', function () {
    inResidentsEstate(function () {
        $unit = Unit::query()->where('reference', 'Lot 47')->firstOrFail();
        $board = app(Residents::class)->residentBoard($unit, maySeeMoney: true);

        $stats = collect($board['stats'])->keyBy('key');

        expect($board['resident']['name'])->toBe('Andrea Fletcher')
            ->and($board['resident']['sub'])->toBe('Phase 2 · Lot 47 · Fletcher household')
            ->and($board['resident']['verification_label'])->toBe('Verified')
            ->and($stats['members']['value'])->toBe(3)
            ->and($stats['balance']['value_minor'])->toBe(12_400_00)
            // Day, month, year (M53 Law 5).
            ->and($stats['since']['value'])->toBe('2 Sep 2024')

            /*
             * TWO OPEN ITEMS, and the rule is stated rather than counted off a
             * screen: an arrears balance and an unfinished ticket each need
             * somebody to act. The gazebo booking does not — the deposit is in
             * the right place and the date is in the diary — which is exactly
             * the arithmetic that makes the board's own "2" reachable.
             */
            ->and($stats['open_items']['value'])->toBe(2);

        // The panel names the primary and the brother and rolls the third up.
        // The roll-up is a privacy affordance: a detail screen naming every
        // occupant of a house is a roster of who lives where.
        expect($board['members'][0]['label'])->toBe('Andrea Fletcher (primary)')
            ->and($board['members'][0]['value'])->toBe('Verified')
            ->and($board['members'][1]['label'])->toBe('Marlon Fletcher (brother)')
            ->and($board['members'][1]['value'])->toStartWith('Added ')
            ->and($board['members'][2]['label'])->toBe('1 additional member');

        expect(array_column($board['linked'], 'type'))->toBe(['ledger', 'ticket', 'booking']);
    });
});

/* ------------------------------------------------------------------ */
/* the acts */
/* ------------------------------------------------------------------ */

it('binds a person to a household when a claim is approved, and posts nothing', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $household = restrictedHouseholdAt('Lot 903');
        $unit = $household->unit;

        $claim = UnitClaim::create([
            'unit_id' => $unit->id,
            'claim_type' => UnitClaim::TYPE_UNIT,
            'submitted_name' => 'Approved Claimant',
            'submitted_phase' => $unit->block,
            'submitted_lot' => $unit->reference,
            'match_result' => 'partial',
            'status' => UnitClaim::PENDING,
        ]);

        $entriesBefore = Journal::query()->count();

        $residents->approveClaim($claim);

        $resident = $claim->refresh()->resolvedResident;

        expect($claim->status)->toBe(UnitClaim::APPROVED)
            ->and($claim->reviewed_at)->not->toBeNull()
            ->and($resident)->not->toBeNull()
            ->and($resident->household_id)->toBe($household->id)
            ->and($resident->isVerified())->toBeTrue();

        /*
         * NOT ONE JOURNAL ENTRY. Approving a claim decides who is authorised
         * against a unit and decides nothing about what that unit owes — board
         * 31's own note says so, and an approval that quietly posted would put
         * a resident's authorisation and the estate's accounts on the same
         * button.
         */
        expect(Journal::query()->count())->toBe($entriesBefore);

        /*
         * AND THE RESTRICTION IS UNTOUCHED. The household's arrears did not go
         * away because somebody proved who they were; a restriction cleared as
         * a side effect of an identity check would open a gate nobody decided
         * to open.
         */
        expect($household->refresh()->access_restricted)->toBeTrue();
    });
});

it('refuses to decide a claim twice, so the record of who decided it survives', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $household = restrictedHouseholdAt('Lot 904');

        $claim = UnitClaim::create([
            'unit_id' => $household->unit_id,
            'claim_type' => UnitClaim::TYPE_UNIT,
            'submitted_name' => 'Twice Decided',
            'match_result' => 'partial',
            'status' => UnitClaim::PENDING,
        ]);

        $residents->approveClaim($claim);

        expect(fn () => $residents->approveClaim($claim->refresh()))
            ->toThrow(DomainException::class, 'already approved');

        expect(fn () => $residents->requestDocument($claim->refresh()))
            ->toThrow(DomainException::class, 'nothing left to verify');
    });
});

it('refuses to reject a claim without a reason the claimant can be given', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $household = restrictedHouseholdAt('Lot 905');

        $claim = UnitClaim::create([
            'unit_id' => $household->unit_id,
            'claim_type' => UnitClaim::TYPE_UNIT,
            'submitted_name' => 'Refused Claimant',
            'match_result' => 'none',
            'status' => UnitClaim::PENDING,
        ]);

        expect(fn () => $residents->rejectClaim($claim, '   '))
            ->toThrow(DomainException::class, 'needs a reason');

        // And nothing was written on the way to being refused.
        expect($claim->refresh()->status)->toBe(UnitClaim::PENDING);

        $residents->rejectClaim($claim, 'The lot is occupied by a household we have verified.');

        expect($claim->refresh()->status)->toBe(UnitClaim::REJECTED)
            ->and($claim->decision_reason)->toContain('verified');
    });
});

it('adds a resident at an address the estate has, and refuses one at an address it does not', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        /*
         * A lot typed in error must not become a house. This form files a
         * person at an address; it does not create one, and the refusal names
         * both halves because both are what the person can fix — the unit as it
         * was typed, not a "Lot" the form made up (B041).
         */
        expect(fn () => $residents->add('Phase 5', '9999', 'Nobody At All'))
            ->toThrow(DomainException::class, 'No unit "9999" in Phase 5');

        $unit = Unit::query()->where('status', 'vacant')->orderBy('id')->firstOrFail();

        $resident = $residents->add(
            phase: (string) $unit->block,
            lot: (string) $unit->reference,
            fullName: 'Simone Barrett',
            email: 'simone.barrett@email.com',
            phone: '876 555 0388',
        );

        expect($resident->full_name)->toBe('Simone Barrett')
            ->and($resident->is_primary)->toBeTrue()

            // The invite path leaves them PENDING. Board 34's own copy says the
            // transition to Verified happens when they prove who they are.
            ->and($resident->status)->toBe(Resident::PENDING)

            // And a lot with somebody living at it is not vacant, or board 3's
            // occupancy would count a household that exists as an empty house.
            ->and($unit->refresh()->status)->toBe('occupied');

        $invite = ResidentInvite::query()->where('resident_id', $resident->id)->firstOrFail();

        /*
         * AND THE INVITE IS SENT — BY EMAIL, NEVER BY TEXT. It used to be a row with
         * `sent_at` null and nothing behind it ("no mail driver on this path yet"),
         * and an invited resident never heard. It is emailed now, and `sent_at` is
         * stamped because the mail server (here, the test mailer) accepted it; a
         * refusal would leave it null and say why (ResidentInvitesAreSentTest).
         */
        expect($invite->sent_at)->not->toBeNull()
            ->and($invite->email)->toBe('simone.barrett@email.com')
            ->and($invite->attempts)->toBe(1)
            ->and($invite->channels)->toBe('email');
    });
});

it('ships the biometric consent flag off and refuses enrolment without it', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $unit = Unit::query()->where('status', 'vacant')->orderBy('id')->firstOrFail();

        // Q-003 / D-022. The form may collect it; the default is off, and no
        // estate setting grants it on anybody's behalf.
        expect($residents->newResidentBoard()['biometric_consent']['default'])->toBeFalse();

        $resident = $residents->add(
            phase: (string) $unit->block,
            lot: (string) $unit->reference,
            fullName: 'Unconsenting Resident',
            verification: 'manual',
        );

        expect($resident->biometric_consent)->toBeFalse()
            ->and($resident->status)->toBe(Resident::VERIFIED);

        expect(fn () => $residents->enrolBiometrics($resident))
            ->toThrow(DomainException::class, 'has not consented');

        // And with consent recorded, the gate opens — so the refusal above is
        // the consent doing its job rather than the method never working.
        $resident->forceFill(['biometric_consent' => true])->save();

        $residents->enrolBiometrics($resident);

        expect($resident->biometric_consent)->toBeTrue();
    });
});

/* ------------------------------------------------------------------ */
/* the seeder — it extends the estate and never rebuilds it */
/* ------------------------------------------------------------------ */

it('changes nothing at all when the register seeder runs a second time', function () {
    inResidentsEstate(function () {
        $arrears = fn (): int => (int) DB::connection('tenant')->selectOne('
            SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE a.code = ?
        ', ['1200'])->owed;

        $arrearsBefore = $arrears();

        $before = [
            'units' => Unit::query()->count(),
            'households' => Household::query()->count(),
            'residents' => Resident::query()->count(),
            'claims' => UnitClaim::query()->count(),
            'phases' => Phase::query()->count(),
            'entries' => Journal::query()->count(),
        ];

        /*
         * THE SEEDER IS THE ONLY THING THAT COULD DOUBLE ANY OF THIS. It renames
         * households by finding a free unit, and a second run that went looking
         * again would find the NEXT free one and put a second Natalie Wong at a
         * second address — added silently by a routine `db:seed`. Every
         * household is found by the resident already on it, and this is the test
         * that keeps that true.
         */
        Artisan::call('db:seed', ['--class' => ResidentsSeeder::class, '--force' => true]);

        expect([
            'units' => Unit::query()->count(),
            'households' => Household::query()->count(),
            'residents' => Resident::query()->count(),
            'claims' => UnitClaim::query()->count(),
            'phases' => Phase::query()->count(),
            'entries' => Journal::query()->count(),
        ])->toBe($before);

        /*
         * AND THE RECEIVABLE HAS NOT MOVED. Compared against itself rather than
         * against the boards' J$1,840,000, because the restriction assertions
         * above have deliberately charged fixture households of their own and
         * that total is asserted before any of them run. What matters here is
         * the delta: this seeder posts nothing, so a second run must leave 1200
         * exactly where it found it.
         */
        expect($arrears())->toBe($arrearsBefore)

            // The one thing a re-run could plausibly duplicate: a household
            // placed by searching for a free unit rather than by finding the
            // person already on it.
            ->and(Resident::query()->where('full_name', 'Natalie Wong')->count())->toBe(1);
    });
});

/*
|--------------------------------------------------------------------------
| Every household on the register is reachable
|--------------------------------------------------------------------------
|
| The register used to end at `LIST_LIMIT` (50) of an estate's households,
| ordered newest-active first, with no pagination control anywhere. On the
| client's estate that is 50 of 441: three hundred and ninety-one households
| existed, were billed, appeared in the arrears total, and could not be opened
| from the one screen whose entire job is to list them. Looking for a named
| resident left nothing but the browser's own find, which searches the rows
| already rendered and truthfully answers "0/0".
|
| The cap was not wrong -- a page's props are not a place to put 441 rows. What
| was wrong was that it truncated instead of paginating.
|
| THE LAST TEST IS THE ONE THAT MATTERS: it walks every page and asserts the
| union is the whole register, with nothing seen twice. That is the property
| pagination must have and the one a naive limit/offset quietly breaks -- an
| ORDER BY with ties is free to order them differently between two queries, so a
| household can appear on page two and again on page three while another is never
| shown at all.
|
*/

/** The Households tab as an address asks for it (M32 F5). */
function householdsAsked(array $query = []): TableQuery
{
    return Residents::householdsTable(Request::create('/residents', 'GET', $query));
}

it('ships a page of the register rather than the whole of it', function () {
    inResidentsEstate(function () {
        $board = app(Residents::class)->listBoard(maySeeMoney: true);

        expect($board['table']['total'])->toBeGreaterThan(0)
            ->and(count($board['rows']))->toBeLessThanOrEqual(Residents::PER_PAGE)
            ->and($board['table']['page'])->toBe(1);
    });
});

it('offers only columns the server can actually order by', function () {
    inResidentsEstate(function () {
        /*
         * Balance and Status are built per row in PHP -- from Dues::unitBalances()
         * and the pending-claim list -- so there is no column to ORDER BY. A
         * header that sorted only the rows already fetched would let a reader
         * page through the estate seeing each page "sorted by balance" and never
         * reach the largest debt on it.
         */
        $board = app(Residents::class)->listBoard(maySeeMoney: true);

        expect($board['table']['sortable'])->not->toContain('balance')
            ->and($board['table']['sortable'])->not->toContain('status')
            ->and($board['table']['sortable'])->toContain('household');
    });
});

it('drops a sort column nobody offered rather than failing on it', function () {
    inResidentsEstate(function () {
        $board = app(Residents::class)->listBoard(maySeeMoney: true, table: householdsAsked(['sort' => 'drop table', 'dir' => 'sideways']));

        expect($board['table']['sort'])->toBeNull()
            ->and($board['table']['dir'])->toBeNull()
            ->and($board['table']['default'])->toBe(['sort' => 'active', 'dir' => 'desc', 'per' => Residents::PER_PAGE])
            ->and(count($board['rows']))->toBeGreaterThan(0);
    });
});

it('sorts the register by a real column, and reverses it', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        $up = $residents->listBoard(maySeeMoney: true, table: householdsAsked(['sort' => 'household', 'dir' => 'asc']));
        $down = $residents->listBoard(maySeeMoney: true, table: householdsAsked(['sort' => 'household', 'dir' => 'desc']));

        expect($up['table']['sort'])->toBe('household')
            ->and($down['table']['dir'])->toBe('desc')

            // Ascending and descending cannot both start at the same household
            // unless the sort did nothing at all.
            ->and($up['rows'][0]['household'])->not->toBe($down['rows'][0]['household']);
    });
});

it('reaches every household across its pages, each exactly once', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        $board = $residents->listBoard(maySeeMoney: true);
        $pages = $board['table']['last'];

        expect($pages)->toBeGreaterThan(1, 'The fixture holds one page, so this would prove nothing.');

        $seen = [];

        for ($page = 1; $page <= $pages; $page++) {
            foreach ($residents->listBoard(maySeeMoney: true, table: householdsAsked(['page' => $page]))['rows'] as $row) {
                $seen[] = $row['id'];
            }
        }

        expect(count($seen))->toBe($board['total'])
            ->and(count(array_unique($seen)))->toBe($board['total']);
    });
});
