<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use Database\Seeders\Estate\ArrearsPlan;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Every displayed total, traced to the lines it is made of
|--------------------------------------------------------------------------
|
| The rule for the money modules: a screen figure is not proven by rendering.
| It is proven by equalling the sum of specific posted journal lines. A
| dashboard figure that agrees with the ledger by coincidence is the defect
| that surfaces at an audit and nowhere earlier.
|
| SO EVERY EXPECTATION BELOW IS COMPUTED TWICE, BY DIFFERENT ROUTES. The
| application's figure comes through `Dues`, which reads the ledger through
| Eloquent and its own grouping. The expected figure is a raw SQL sum written
| out longhand in the test. If the two agree, the screen's number is the
| ledger's number; if the test simply called the same method it was checking,
| it would prove only that the method is deterministic.
|
| These run against the REAL Phoenix Park estate rather than a fixture, because
| the figures under test are the ones on the boards — 450 units, J$1,840,000 of
| arrears across four buckets and five phases — and a fixture with two units
| could not reach any of them. The suite reads and never writes.
|
*/

/**
 * The estate these tests read, built once per process.
 *
 * A DATABASE OF ITS OWN, not the development estate. The figures under test are
 * the boards' own — 450 units, J$1,840,000 across four buckets and five phases
 * — so a two-unit fixture could not reach any of them, and reading the
 * development estate would make the suite pass or fail on whatever somebody
 * last seeded there.
 *
 * It is built by the same seeder that builds Phoenix Park. That is the point:
 * what is being proven is that the seeder's arithmetic and the ledger's agree,
 * so the test must exercise the real one.
 */
function arrearsEstate(): string
{
    static $built = false;

    // The configured prefix, never the literal: see `FacilitiesFixture::database()`.
    $database = config('tenancy.database.prefix').'arrearstest';

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

    // Through Artisan rather than by constructing the seeder, so it gets the
    // console output its own `$this->call()` writes to.
    Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);

    $built = true;

    return $database;
}

/** Runs a closure inside the estate under test. */
function inEstate(callable $work): mixed
{
    arrearsEstate();

    return $work();
}

/**
 * The receivable balance of one unit, summed in raw SQL.
 *
 * Written out longhand on purpose. This is the second, independent route to
 * every figure the screens show, and it must not share an implementation with
 * the first.
 */
function receivableOf(int $unitId): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS balance
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unitId])->balance;
}

beforeEach(function () {
    $this->dues = fn (): Dues => app(Dues::class);
});

/* ------------------------------------------------------------------ */
/* the boards' own arithmetic */
/* ------------------------------------------------------------------ */

it('states the same arrears total by age and by phase', function () {
    // Both margins are read off boards. If they ever disagree, one of the
    // boards is wrong and the fit cannot be solved at all.
    expect(array_sum(ArrearsPlan::AGEING))->toBe(array_sum(ArrearsPlan::BY_PHASE))
        ->and(array_sum(ArrearsPlan::AGEING))->toBe(1_840_000_00);
});

/* ------------------------------------------------------------------ */
/* every total traced to posted lines */
/* ------------------------------------------------------------------ */

it('ties the arrears total on every screen to the receivables control account', function () {
    inEstate(function () {
        $control = Account::where('code', '1200')->firstOrFail();

        // The application's figure.
        $shown = app(Ledger::class)->balanceOf($control)->getMinorAmount()->toInt();

        // The same figure, summed independently in raw SQL over the lines.
        $traced = (int) DB::connection('tenant')->selectOne('
            SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS balance
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE a.code = ?
        ', ['1200'])->balance;

        expect($shown)->toBe($traced)
            ->and($shown)->toBe(1_840_000_00);
    });
});

it('draws the arrears page\'s own cards off the ledger: the total is its four buckets, and equals the control account', function () {
    /*
     * The money rule (Hassam's universal order, 11 October): a page's total is proved against the rows under it. The
     * case above ties the LEDGER to raw SQL; this one reads the cards the arrears page itself draws, through the method
     * its controller calls, so a page that summed its own cards wrong could not pass.
     */
    inEstate(function () {
        $cards = collect(app(Dues::class)->arrearsPage()['ageing'])->keyBy('key');

        $traced = (int) DB::connection('tenant')->selectOne('
            SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS balance
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE a.code = ?
        ', ['1200'])->balance;

        expect($cards['total']['value_minor'])->toBe($traced)
            ->and($cards['total']['value_minor'])->toBe($cards->except('total')->sum('value_minor'))
            ->and($cards['total']['value_minor'])->toBe(1_840_000_00);
    });
});

it('prints each household\'s balance on the list to the cent, as the ledger holds it', function () {
    /*
     * THE MONEY RULE (M97). The list's balance is the sum a household is about to be asked for, and it rounded to the
     * dollar ("Cents round for display"), so $12,400.50 read $12,401 beside a ledger that says otherwise. The ageing
     * cards stay abbreviated ($1.84M, $612k): they are the estate's magnitudes, not an amount anybody is asked for.
     */
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/Arrears.vue'));
    $at = strpos($page, 'const amount = ');

    expect($at)->not->toBeFalse('Arrears.vue names no amount helper');

    $helper = substr($page, (int) $at, 400);

    expect(str_contains($helper, 'dollars(minor)'))->toBeFalse('the list\'s balance still rounds to the dollar')
        ->and(str_contains($helper, "padStart(2, '0')"))->toBeTrue('the list\'s balance has no cents');
});

it('traces each ageing bucket to the charges it is the sum of, and the units\' badges to the board\'s plan', function () {
    inEstate(function () {
        $dues = app(Dues::class);
        $ageing = $dues->ageing();
        $balances = $dues->unitBalances();
        $buckets = $dues->unitBuckets();
        $today = Dues::siteDate();

        // Board 5's four figures are the units' BADGES: each unit's whole receivable by its oldest open charge, the
        // distribution ArrearsPlan placed. Re-summed from the journal lines below.
        $byBadge = array_fill_keys(array_keys(Dues::BUCKETS), 0);

        foreach ($buckets as $unitId => $unitBucket) {
            $byBadge[$unitBucket] += receivableOf($unitId);
        }

        expect($byBadge)->toBe(ArrearsPlan::AGEING);

        // The ageing puts each charge's share in its own bucket (EST-S-02, M85 A3): each unit's receivable laid over its
        // charges due by today, newest first, re-walked here from the charges and the journal lines.
        $traced = array_fill_keys(array_keys(Dues::BUCKETS), 0);

        foreach (array_keys($buckets) as $unitId) {
            $left = receivableOf($unitId);
            $oldest = 'current';

            $charges = DB::connection('tenant')->table('charges')
                ->where('unit_id', $unitId)->where('due_on', '<=', $today->toDateString())->where('status', '!=', 'reversed')
                ->orderByDesc('due_on')->orderByDesc('id')->get(['due_on', 'amount_minor']);

            foreach ($charges as $charge) {
                if ($left <= 0) {
                    break;
                }

                $oldest = Dues::bucketFor(Carbon::parse((string) $charge->due_on), $today);
                $share = min((int) $charge->amount_minor, $left);
                $traced[$oldest] += $share;
                $left -= $share;
            }

            $traced[$oldest] += max(0, $left);
        }

        expect($ageing)->toBe($traced);

        // And the four buckets account for every unit that owes anything —
        // nothing is quietly outside the ageing.
        expect(array_sum($ageing))->toBe(array_sum(array_filter($balances, static fn (int $b): bool => $b > 0)));
    });
});

it('traces every phase bar on the dashboard to the units in that phase', function () {
    inEstate(function () {
        $balances = app(Dues::class)->unitBalances();
        $units = Unit::query()->get(['id', 'block'])->keyBy('id');

        $byPhase = [];

        foreach ($balances as $unitId => $balance) {
            if ($balance > 0) {
                $phase = (string) $units[$unitId]->block;
                $byPhase[$phase] = ($byPhase[$phase] ?? 0) + $balance;
            }
        }

        ksort($byPhase);

        expect($byPhase)->toBe(ArrearsPlan::BY_PHASE);
    });
});

it('traces each named unit balance to its own posted lines', function () {
    inEstate(function () {
        $dues = app(Dues::class);

        foreach (ArrearsPlan::NAMED as $lot => $named) {
            $unit = Unit::where('reference', 'Lot '.$lot)->firstOrFail();

            $shown = $dues->balanceOf($unit)->getMinorAmount()->toInt();

            expect($shown)->toBe(receivableOf($unit->id))
                ->and($shown)->toBe($named['balance'])
                ->and($unit->block)->toBe($named['phase'])
                ->and($dues->unitBuckets()[$unit->id])->toBe($named['bucket']);
        }
    });
});

it('traces the unit statement running balance to the lines above it', function () {
    inEstate(function () {
        $dues = app(Dues::class);
        $unit = Unit::where('reference', 'Lot 47')->firstOrFail();

        $statement = $dues->statement($unit, limit: 50);

        // Newest first, so the first row's running balance IS the unit's
        // current balance — the figure the screen prints in its hero.
        expect($statement[0]['balance_minor'])->toBe(receivableOf($unit->id))
            ->and($statement[0]['balance_minor'])->toBe(12_400_00);

        // And every row's balance is the one below it plus that row's movement,
        // which is what "running balance" has to mean.
        $rows = array_reverse($statement);
        $running = 0;

        foreach ($rows as $row) {
            $running += ($row['charge_minor'] ?? 0) - ($row['payment_minor'] ?? 0);

            expect($row['balance_minor'])->toBe($running);
        }
    });
});

it('draws a unit\'s ledger page off its own posted lines: the hero, its strip and its statement agree with them', function () {
    /*
     * The money rule (Hassam's universal order, 11 October). The ledger page is built by Dues::unitBoard, which the
     * cases around this one never call (they read balanceOf, statement and ageingOf); this reads the page's own payload
     * and ties each of its figures to the unit's receivable lines, summed in raw SQL.
     */
    inEstate(function () {
        $unit = Unit::where('reference', 'Lot 47')->firstOrFail();
        $board = app(Dues::class)->unitBoard($unit);
        $traced = receivableOf($unit->id);

        expect($board['balance_minor'])->toBe($traced)
            ->and(collect($board['strip'])->sum('value_minor'))->toBe($traced)
            ->and($board['statement'][0]['balance_minor'])->toBe($traced);
    });
});

it('traces a unit ageing strip to that unit balance', function () {
    inEstate(function () {
        $dues = app(Dues::class);
        $unit = Unit::where('reference', 'Lot 47')->firstOrFail();

        $strip = $dues->ageingOf($unit);

        // Board 6 draws four figures and they sum to the total outstanding: its two charges, a month apart, each in its
        // own bucket (EST-S-02, M85 A3), where the whole 12,400.00 used to sit in 30 days.
        expect(array_sum($strip))->toBe(receivableOf($unit->id))
            ->and($strip['current'])->toBe(6_200_00)
            ->and($strip['d30'])->toBe(6_200_00)
            ->and($strip['d60'])->toBe(0)
            ->and($strip['d90'])->toBe(0);
    });
});

/* ------------------------------------------------------------------ */
/* the shape of the data itself */
/* ------------------------------------------------------------------ */

it('bills every unit including the vacant ones, because dues attach to the property', function () {
    inEstate(function () {
        $vacant = Unit::where('status', 'vacant')->first();

        expect(Unit::count())->toBe(450)
            ->and($vacant)->not->toBeNull()

            // A vacant unit has no household and still has a ledger. Keyed on
            // the household it could not have one at all.
            ->and($vacant->household)->toBeNull()
            ->and(receivableOf($vacant->id))->toBeGreaterThanOrEqual(0);

        $billedUnits = (int) DB::connection('tenant')
            ->table('charges')
            ->where('type', 'dues')
            ->distinct()
            ->count('unit_id');

        expect($billedUnits)->toBe(450);
    });
});

it('records when a payment was received separately from when it was entered', function () {
    inEstate(function () {
        $payment = Payment::query()->firstOrFail();

        // Two facts, and the gap between them is evidence: cash taken at a gate
        // on Friday and keyed on Monday is late paperwork, not a late payment.
        expect($payment->received_at)->not->toBeNull()
            ->and($payment->entered_at)->not->toBeNull()
            ->and($payment->received_at->lessThan($payment->entered_at))->toBeTrue();
    });
});

it('gives every receipt its own number', function () {
    inEstate(function () {
        $total = Payment::count();
        $distinct = Payment::query()->distinct()->count('receipt_no');

        // A receipt number appearing twice is two people holding proof of the
        // same payment.
        expect($distinct)->toBe($total)->and($total)->toBeGreaterThan(2_000);
    });
});

it('raises a journal entry for every charge and every payment', function () {
    inEstate(function () {
        $chargesWithout = (int) DB::connection('tenant')->table('charges')->whereNull('journal_ref')->count();
        $paymentsWithout = (int) DB::connection('tenant')->table('payments')->whereNull('journal_ref')->count();

        // A billing record with no entry behind it is money the accounts have
        // never heard of.
        expect($chargesWithout)->toBe(0)->and($paymentsWithout)->toBe(0);
    });
});

it('keeps a reversed charge and its correction both visible on the ledger', function () {
    inEstate(function () {
        $reversal = DB::connection('tenant')
            ->table('journals')
            ->whereNotNull('reverses_journal_id')
            ->first();

        expect($reversal)->not->toBeNull();

        $original = DB::connection('tenant')
            ->table('journals')
            ->where('id', $reversal->reverses_journal_id)
            ->first();

        // Both stand. The pair IS the audit trail — a deleted row cannot show
        // anyone that a mistake was made and put right.
        expect($original)->not->toBeNull()
            ->and((int) $reversal->amount_minor)->toBe((int) $original->amount_minor);
    });
});
