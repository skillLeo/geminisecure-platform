<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Journal;
use App\Models\Estate\JournalLine;
use App\Models\User;
use App\Support\MoneyFormatter;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use Brick\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one door every journal entry goes through.
 *
 * Nothing else in this application writes to `journals` or `journal_lines`. Not
 * a controller, not a seeder, not a model. A charge posts through here, a
 * payment posts through here, a bill posts through here — so there is exactly
 * one place where the rules of double-entry are expressed, and exactly one
 * place to read to know what they are.
 *
 * THE GUARANTEES ARE NOT IN THIS CLASS. That is the point of it. Debits equal
 * credits because a database trigger refuses a header whose lines do not sum;
 * a posted entry cannot be edited because four more triggers and a revoked
 * grant say so. This class is the ergonomics and the reference numbering. If
 * somebody bypasses it tomorrow with raw SQL, the ledger still cannot be made
 * to lie — it will simply be less pleasant to write to.
 *
 * WRITE ORDER IS LINES FIRST, HEADER LAST, and that is not an implementation
 * detail to tidy up later: the header's insert is what counts the lines and
 * refuses an unbalanced entry. Reversing the order would move the balance
 * guarantee from the database into this file, where a future caller could
 * forget it.
 *
 * A REVERSAL IS EVERY LINE MIRRORED, never a negative amount. Debiting what was
 * credited is what undoes an entry; negating a total is what undoes a number.
 */
class Ledger
{
    /**
     * The prefix on a hand-posted entry's reference.
     *
     * Entries raised by a charge, a payment or a bill carry their own prefixes,
     * so a reference says at a glance what caused the entry — which is the
     * first question anyone asks of a ledger row they do not recognise.
     */
    public const MANUAL = 'JV';

    /** Where an entry came from. Recorded so a reversal can find everything. */
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CHARGE = 'charge';

    public const SOURCE_PAYMENT = 'payment';

    public const SOURCE_BILL = 'bill';

    public const SOURCE_BILL_PAYMENT = 'bill_payment';

    /** A pay run disbursed: Dr salaries expense, Cr the statutory payable and the bank. */
    public const SOURCE_PAYROLL = 'payroll';

    /** A statutory remittance filed: Dr the payable it clears, Cr the bank. */
    public const SOURCE_PAYROLL_REMITTANCE = 'payroll_remittance';

    /**
     * An amenity deposit taken, returned, or kept — Q-008's sibling ruling.
     *
     * THREE SOURCES RATHER THAN ONE, because they are three different events
     * and a ledger somebody reads in five years should not have to infer which
     * happened from the accounts alone. Taking a deposit is Dr 1000 / Cr 2200;
     * returning it reverses that; keeping it is Dr 2200 / Cr 4100 and is the
     * only one of the three that ever becomes income.
     *
     * A DEPOSIT NEVER TOUCHES RECEIVABLES. It is the estate holding somebody's
     * money, not somebody owing the estate money, so it appears in no dues
     * statement and on no arrears report. The client ruled this explicitly and
     * `EstateFacilitiesAmenitiesTest` asserts the receivable does not move.
     */
    public const SOURCE_DEPOSIT_HELD = 'amenity_deposit';

    public const SOURCE_DEPOSIT_REFUND = 'amenity_deposit_refund';

    public const SOURCE_DEPOSIT_FORFEIT = 'amenity_deposit_forfeit';

    public const SOURCE_OPENING = 'opening';

    public const SOURCE_REVERSAL = 'reversal';

    public const SOURCE_ADJUSTMENT = 'adjustment';

    /** A card provider's payout: what was in transit (1080) to the bank, net, and its fee to 5910 (CardGateway). */
    public const SOURCE_CARD_SETTLEMENT = 'card_settlement';

    /** Card money sent back — a refund or a chargeback: the household owes it again (CardGateway). */
    public const SOURCE_CARD_REFUND = 'card_refund';

    /**
     * How many journal lines one INSERT carries (M11 A2).
     *
     * Ten columns a line, so 500 lines is 5,000 placeholders — well inside
     * MySQL's 65,535 per statement — and a 2,000-unit dues run is four statements.
     */
    private const LINES_PER_INSERT = 500;

    /**
     * The estate's business date: the site's calendar day (bug hunt B023, B082).
     *
     * NOT `Carbon::today()`, which is the UTC calendar. Kingston is five hours
     * behind it, so from 7 PM every evening UTC is already tomorrow — and on the
     * last evening of a month, next month. An entry posted, a bill approved or a
     * return filed then was dated the next day, and at month end it landed in the
     * next period with the next month's reference. The office works in the site's
     * zone (M4 §3.1), and so does every date the books give an entry.
     *
     * Returned at midnight UTC — the shape a `date` column is cast to — so it
     * compares with one as a calendar date and not as a moment five hours off.
     */
    public static function today(): Carbon
    {
        return Carbon::parse(SiteTime::today()->toDateString());
    }

    /**
     * Post one balanced entry.
     *
     * @param  list<Posting>  $postings  at least two, summing to zero across the two sides
     *
     * @throws DomainException when the entry does not balance, or an account is missing or archived
     */
    public function post(
        string $memo,
        array $postings,
        Carbon|string|null $on = null,
        string $source = self::SOURCE_MANUAL,
        ?int $sourceId = null,
        ?User $by = null,
        ?string $prefix = null,
        ?int $reversesJournalId = null,
    ): Journal {
        if (count($postings) < 2) {
            throw new DomainException(
                'A journal entry needs at least two postings. One side of an entry is not an entry: '.
                'it is half a statement about where money went.'
            );
        }

        $accounts = $this->resolveAccounts($postings);
        $this->assertBalanced($postings);

        $currency = $this->singleCurrency($postings);
        $total = array_sum(array_map(static fn (Posting $p): int => $p->debitMinor, $postings));

        return DB::connection('tenant')->transaction(function () use (
            $memo, $postings, $on, $source, $sourceId, $by, $prefix, $reversesJournalId, $accounts, $currency, $total
        ): Journal {
            $postedOn = $this->postedOn($on);
            $this->refuseOverdraft($postings, $accounts, $postedOn);
            $reference = $this->allocateReference($prefix ?? self::MANUAL, $postedOn);

            /*
             * Lines first. The header insert below is what checks that these
             * balance, so they have to exist before it runs.
             *
             * IN BATCHES, NOT ONE STATEMENT PER LINE (M11 A2). A month's dues run
             * is one entry with a line per unit — two thousand lines at Phoenix
             * Park — and writing them one INSERT at a time made posting or
             * reversing a run thousands of round trips. The rows are exactly the
             * ones `JournalLine::create()` wrote (the model has no creating hook
             * to skip: only the updating and deleting refusals), the CHECK
             * constraint and every trigger still fire per row, and the whole
             * entry stays inside this transaction, so an entry is still written
             * whole or not at all.
             */
            $createdAt = now();
            $rows = [];

            foreach ($postings as $i => $posting) {
                $rows[] = [
                    'entry_ref' => $reference,
                    'account_id' => $accounts[$posting->accountCode]->id,
                    'line_no' => $i + 1,
                    'debit_minor' => $posting->debitMinor,
                    'credit_minor' => $posting->creditMinor,
                    'currency' => $posting->currency,
                    'memo' => $posting->memo,
                    'unit_id' => $posting->unitId,
                    'vendor_id' => $posting->vendorId,
                    'created_at' => $createdAt,
                ];
            }

            foreach (array_chunk($rows, self::LINES_PER_INSERT) as $chunk) {
                DB::connection('tenant')->table('journal_lines')->insert($chunk);
            }

            return Journal::create([
                'reference' => $reference,
                'memo' => $memo,
                'source' => $source,
                'source_id' => $sourceId,
                'amount_minor' => $total,
                'currency' => $currency,
                'posted_on' => $postedOn->toDateString(),
                'posted_by' => $by?->getKey(),
                'posted_by_name' => $by?->name,
                'reverses_journal_id' => $reversesJournalId,
            ]);
        });
    }

    /**
     * Post many balanced entries at once — each exactly as `post()` would post it.
     *
     * FOR A BULK CHARGE (M11 A2), where every unit gets ITS OWN entry: a charge is a
     * unit's receivable and a household disputing theirs has to be able to point
     * at one entry, so a whole-estate levy is two thousand entries, not one. Posted
     * through `post()` that was two thousand transactions' worth of statements —
     * roughly eight per unit. This writes the same entries in a handful:
     *
     *   - every check `post()` makes, made first and in the same order, with the
     *     same sentences: two postings at least, every account real and open,
     *     every entry balanced, one currency an entry;
     *   - the references allocated once, under the same lock, and advanced in
     *     memory — `CHG-2026-09-0007`, `-0008`, … — which is the sequence one
     *     `post()` after another hands out inside one transaction;
     *   - EVERY ENTRY'S LINES, THEN EVERY HEADER. The database's own triggers
     *     still decide: a line is refused once its header exists, and each header
     *     counts its own lines and refuses to post unless they balance and sum to
     *     its total. Both fire row by row, exactly as they do for a single entry.
     *
     * All of it or none of it: one transaction, as the caller's would be.
     *
     * @param  list<array{memo: string, postings: list<Posting>, source_id: int|null}>  $entries
     * @return list<string> each entry's reference, in the order given
     *
     * @throws DomainException as `post()` would, for the first entry it would refuse
     */
    public function postEach(array $entries, Carbon|string|null $on, string $source, ?User $by, string $prefix): array
    {
        if ($entries === []) {
            return [];
        }

        /*
         * Every account any entry names, read once. Then each entry is checked in
         * turn exactly as `post()` checks one — its postings, its accounts, its
         * balance, its currency, in that order — so the first entry `post()` would
         * have refused is the one refused here, in the same words.
         */
        $codes = [];

        foreach ($entries as $entry) {
            foreach ($entry['postings'] as $posting) {
                $codes[$posting->accountCode] = true;
            }
        }

        $known = Account::query()->whereIn('code', array_keys($codes))->get()->keyBy('code');
        $accounts = [];
        $headers = [];

        foreach ($entries as $k => $entry) {
            if (count($entry['postings']) < 2) {
                throw new DomainException(
                    'A journal entry needs at least two postings. One side of an entry is not an entry: '.
                    'it is half a statement about where money went.'
                );
            }

            $accounts += $this->checkAccounts($entry['postings'], $known);
            $this->assertBalanced($entry['postings']);

            $headers[$k] = [
                'currency' => $this->singleCurrency($entry['postings']),
                'total' => array_sum(array_map(static fn (Posting $p): int => $p->debitMinor, $entry['postings'])),
            ];
        }

        return DB::connection('tenant')->transaction(function () use ($entries, $on, $source, $by, $prefix, $accounts, $headers): array {
            $postedOn = $this->postedOn($on);
            $this->refuseOverdraft(array_merge(...array_column($entries, 'postings')), $accounts, $postedOn);
            $sequence = $this->allocateSequence($prefix, $postedOn);
            $createdAt = now();

            $references = [];
            $lines = [];
            $journals = [];

            foreach ($entries as $k => $entry) {
                $reference = $this->stampReference($prefix, $postedOn, $sequence + $k);
                $references[] = $reference;

                foreach ($entry['postings'] as $i => $posting) {
                    $lines[] = [
                        'entry_ref' => $reference,
                        'account_id' => $accounts[$posting->accountCode]->id,
                        'line_no' => $i + 1,
                        'debit_minor' => $posting->debitMinor,
                        'credit_minor' => $posting->creditMinor,
                        'currency' => $posting->currency,
                        'memo' => $posting->memo,
                        'unit_id' => $posting->unitId,
                        'vendor_id' => $posting->vendorId,
                        'created_at' => $createdAt,
                    ];
                }

                $journals[] = [
                    'reference' => $reference,
                    'memo' => $entry['memo'],
                    'source' => $source,
                    'source_id' => $entry['source_id'],
                    'amount_minor' => $headers[$k]['total'],
                    'currency' => $headers[$k]['currency'],
                    'posted_on' => $postedOn->toDateString(),
                    'posted_by' => $by?->getKey(),
                    'posted_by_name' => $by?->name,
                    'reverses_journal_id' => null,
                    'created_at' => $createdAt,
                ];
            }

            foreach (array_chunk($lines, self::LINES_PER_INSERT) as $chunk) {
                DB::connection('tenant')->table('journal_lines')->insert($chunk);
            }

            foreach (array_chunk($journals, self::LINES_PER_INSERT) as $chunk) {
                DB::connection('tenant')->table('journals')->insert($chunk);
            }

            return $references;
        });
    }

    /**
     * Undo a posted entry the only way it can be undone.
     *
     * Every line comes back mirrored — what was debited is credited and what
     * was credited is debited — so the two entries together net to nothing and
     * both remain visible. That pair IS the audit trail: an accountant looking
     * at this account in a year has to be able to see that a mistake was made
     * and that it was corrected, which a deleted row cannot show them.
     *
     * ONE REVERSAL, HOWEVER MANY PRESSES (bug hunt B020). Reversing a 450-unit
     * charge run writes 451 lines and takes long enough for a second click to
     * arrive while the first is still writing. Both used to pass the "already
     * reversed?" check before either had committed, and both posted a full
     * mirror — every household credited twice. The check is now asked again
     * inside the transaction, with the entry held under `FOR UPDATE` and the
     * reversing entry looked for with a locking read, so the second request waits
     * for the first to commit and then sees what it posted. The unique
     * `reverses_journal_id` is the database's own copy of the rule.
     *
     * DATED TODAY UNLESS TOLD OTHERWISE. `$on` is for an entry that should never
     * have been made (M34 §2, `ledger:reverse-entries`): its mirror is dated the
     * day the entry was, so every month's figures read as if it had not been
     * posted, while both rows stay on the ledger and the mirror's own creation
     * time says when the correction was made.
     */
    public function reverse(Journal $original, string $memo, ?User $by = null, ?Carbon $on = null): Journal
    {
        $this->refuseReversal($original, $original->isReversed());

        return DB::connection('tenant')->transaction(function () use ($original, $memo, $by, $on): Journal {
            $locked = Journal::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();

            $reversed = Journal::query()
                ->where('reverses_journal_id', $locked->id)
                ->lockForUpdate()
                ->first(['id']) !== null;

            $this->refuseReversal($locked, $reversed);

            // Every line with its account in two queries, not one query per line:
            // on a 450-unit run the lazy loads were most of the time a second
            // click had to land in.
            $postings = $locked->lines()
                ->with('account')
                ->get()
                ->map(fn (JournalLine $line): Posting => $this->postingFrom($line)->mirrored())
                ->all();

            return $this->post(
                memo: $memo,
                postings: $postings,
                on: $on ?? self::today(),
                source: self::SOURCE_REVERSAL,
                sourceId: $locked->id,
                by: $by,
                prefix: 'REV',
                reversesJournalId: $locked->id,
            );
        });
    }

    /**
     * NO BANK OR RESERVE ACCOUNT GOES BELOW NOTHING (EST-B-02, M81 §3.7, M85 A6).
     *
     * Reserve Fund 1010 read -$100 with nothing stopping it. Money cannot leave an account that does not hold it, so an
     * entry taking money out of a bank account or the reserve (`is_bank_account`, `is_reserve_fund`) is refused, before
     * a line is written, when the account would stand below nothing at the end of the entry's day OR OF ANY LATER DAY:
     * a back-dated payment checks the day it is dated, and one dated before a later withdrawal already posted checks
     * that the withdrawal is still covered. Money coming in is never refused, even into an account an older entry left
     * below nothing. Every other account is the entries' business: a household paying ahead takes its receivable below
     * nothing, and that is a prepayment.
     *
     * The account's row is held FOR UPDATE first, so two entries taking from one account cannot both read the balance
     * the other is about to spend: the second waits for the first to commit and then reads what it left.
     *
     * @param  list<Posting>  $postings
     * @param  array<string, Account>  $accounts
     */
    private function refuseOverdraft(array $postings, array $accounts, Carbon $postedOn): void
    {
        $taken = [];
        $currencies = [];

        foreach ($postings as $posting) {
            $account = $accounts[$posting->accountCode];

            if ($account->is_bank_account || $account->is_reserve_fund) {
                $taken[$account->code] = ($taken[$account->code] ?? 0) + $posting->creditMinor - $posting->debitMinor;
                $currencies[$account->code] = $posting->currency;
            }
        }

        foreach ($taken as $code => $minor) {
            if ($minor <= 0) {
                continue;
            }

            $account = $accounts[(string) $code];

            Account::query()->whereKey($account->id)->lockForUpdate()->first(['id']);

            // The account's movement by day, oldest first; the date an entry belongs to is its header's.
            $days = DB::connection('tenant')->table('journal_lines')
                ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
                ->where('journal_lines.account_id', $account->id)
                ->groupBy('journals.posted_on')
                ->orderBy('journals.posted_on')
                ->selectRaw('journals.posted_on as day, SUM(journal_lines.debit_minor) - SUM(journal_lines.credit_minor) as net')
                ->get();

            // What it holds at the end of the entry's day, then the lowest it stands at the end of any later day.
            $held = 0;
            $later = [];

            foreach ($days as $day) {
                $date = Carbon::parse((string) $day->day)->startOfDay();

                if ($date->lessThanOrEqualTo($postedOn)) {
                    $held += (int) $day->net;
                } else {
                    $later[] = [$date, (int) $day->net];
                }
            }

            [$lowest, $lowestOn, $running] = [$held, $postedOn, $held];

            foreach ($later as [$date, $net]) {
                $running += $net;

                if ($running < $lowest) {
                    [$lowest, $lowestOn] = [$running, $date];
                }
            }

            if ($lowest - $minor < 0) {
                throw new DomainException(sprintf(
                    '%s %s cannot go below nothing: it holds %s on %s, and this takes %s from it.',
                    $account->code,
                    $account->name,
                    MoneyFormatter::fromMinor($lowest, $currencies[$code]),
                    $lowestOn->format('j M Y'),
                    MoneyFormatter::fromMinor($minor, $currencies[$code]),
                ));
            }
        }
    }

    /** Why this entry cannot be reversed — asked of the caller's copy, then under the lock. */
    private function refuseReversal(Journal $entry, bool $reversed): void
    {
        if ($reversed) {
            throw new DomainException(sprintf(
                'Entry [%s] has already been reversed. Reversing it twice would post the original '.
                'amount a second time rather than cancelling it.',
                $entry->reference,
            ));
        }

        if ($entry->reverses_journal_id !== null) {
            throw new DomainException(sprintf(
                'Entry [%s] is itself a reversal. Reversing a reversal re-posts what was corrected; '.
                'if that is genuinely intended, post it as a new entry so the ledger says so.',
                $entry->reference,
            ));
        }
    }

    /* ------------------------------------------------------------------ */
    /* reading */
    /* ------------------------------------------------------------------ */

    /**
     * Every account with a posted balance, in code order.
     *
     * THE TRIAL BALANCE IS THE PROOF. If the debit column and the credit column
     * do not agree, the ledger is broken — and because the database refuses an
     * unbalanced entry, they can only disagree if something wrote around it.
     * `gate:ledger` checks exactly that.
     *
     * @return Collection<int, array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     type: string,
     *     is_active: bool,
     *     debit_minor: int,
     *     credit_minor: int,
     *     balance_minor: int,
     * }>
     */
    public function trialBalance(?Carbon $asAt = null): Collection
    {
        $totals = $this->accountTotals($asAt);

        return Account::query()
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($totals): array {
                $row = $totals[$account->id] ?? ['debit' => 0, 'credit' => 0];

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'is_active' => $account->is_active,
                    'debit_minor' => (int) $row['debit'],
                    'credit_minor' => (int) $row['credit'],
                    'balance_minor' => $account->signedMinor((int) $row['debit'], (int) $row['credit']),
                ];
            })
            ->values();
    }

    /**
     * The chart of accounts as board community-admin-25 draws it.
     *
     * BALANCE-SHEET ACCOUNTS ARE CUMULATIVE; INCOME AND EXPENSE ARE FOR THE
     * PERIOD. That is not a presentation choice, it is what the two kinds of
     * account mean: a bank balance is everything that ever happened to it,
     * while "Maintenance Fee Income" without a period stated is a number nobody
     * can act on. The board's own figures say the same — J$2,790,000 of
     * maintenance income is exactly 450 units at J$6,200, one month's
     * assessment (D-042) — so the period is the current month and the screen
     * says so rather than leaving a reader to assume it is the year.
     *
     * Grouped in the board's order: Assets, Liabilities, Equity, Income,
     * Expenses. Equity is there and the board does not draw it; see D-041.
     *
     * THE BALANCES ARE THE ESTATE'S WHOLE POSITION, AND THE CODES ARE NOT (M10
     * §5). 1200 Dues Receivable is the estate's total arrears to the cent; 4000
     * is the month's dues; the tiles above are cash, receivable, payable and the
     * reserve. An Admin Assistant holding Entry on Accounting posts bills
     * against these accounts and needs every code, name and type to do it —
     * and needs none of the figures. For a viewer who may not read the estate's
     * position (`Dues::maySeeEstatePosition()` on Accounting, asked by the
     * controller) every `balance_minor` is null, the tiles are an empty list,
     * and neither total is read from the ledger at all.
     *
     * ONE MONTH, AND NOTHING DATED AFTER TODAY (bug hunt B079). The period used
     * to run from the first of the month with no end at all, and the balances
     * were read with no date either. So on 25 September, with October's run
     * already posted (the preview offers it, dated 1 October), the chart said
     * "September 2026" and carried two months of maintenance income, and 1200
     * counted a month of dues that is not owed until October. Every figure is
     * now read AS AT one day — the estate's today, or the last day of a month
     * that is already over — and the period's income and expense stop there
     * too. An entry dated later is in no figure here until its day comes, and
     * the screen says which day that is.
     *
     * THE SURPLUS IS IN EQUITY, AND EVERY GROUP HAS ITS TOTAL (EST-S-07, M85 A5).
     * Nothing closes income and expense into 3000, so 3000 read nought and the
     * surplus sat in them: assets did not equal liabilities plus equity on the
     * screen, though the books balanced. Equity now carries the surplus for the
     * year so far (income less expense from 1 January to the as-at day) and,
     * when it is not nought, what earlier years left that no close has moved
     * into the fund; a deficit is named one. They are rows, not accounts: no
     * code, nothing to open or archive. With them, Assets' total is Liabilities'
     * plus Equity's, to the cent, and `balance` carries both sides for the page.
     *
     * @param  bool  $maySeePosition  whether the viewer may read the estate's balances — see `Dues::maySeeEstatePosition()`
     * @return array<string, mixed>
     */
    public function chartOfAccounts(?Carbon $periodStart = null, bool $maySeePosition = true): array
    {
        $today = self::today();
        $from = $periodStart?->copy() ?? $today->copy()->startOfMonth();

        $monthEnd = $from->copy()->endOfMonth()->startOfDay();
        $asAt = $monthEnd->lessThan($today) ? $monthEnd : $today;

        $cumulative = $maySeePosition ? $this->accountTotals(asAt: $asAt) : [];
        $period = $maySeePosition ? $this->accountTotals(asAt: $asAt, from: $from) : [];
        $year = $maySeePosition ? $this->accountTotals(asAt: $asAt, from: $asAt->copy()->startOfYear()) : [];

        // Income less expense: to the as-at day, and from 1 January of its year.
        $surplusToDate = 0;
        $surplusThisYear = 0;

        $groups = [
            Account::ASSET => ['label' => 'Assets', 'rows' => []],
            Account::LIABILITY => ['label' => 'Liabilities', 'rows' => []],
            Account::EQUITY => ['label' => 'Equity', 'rows' => []],
            Account::INCOME => ['label' => 'Income', 'rows' => []],
            Account::EXPENSE => ['label' => 'Expenses', 'rows' => []],
        ];

        foreach (Account::query()->orderBy('code')->get() as $account) {
            $forPeriod = in_array($account->type, [Account::INCOME, Account::EXPENSE], true);
            $totals = ($forPeriod ? $period : $cumulative)[$account->id] ?? ['debit' => 0, 'credit' => 0];

            if ($forPeriod) {
                $surplusToDate += (int) ($cumulative[$account->id]['credit'] ?? 0) - (int) ($cumulative[$account->id]['debit'] ?? 0);
                $surplusThisYear += (int) ($year[$account->id]['credit'] ?? 0) - (int) ($year[$account->id]['debit'] ?? 0);
            }

            $groups[$account->type]['rows'][] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => ucfirst($account->type),

                // Null, never nought: "$0" against 1200 would be a false
                // statement about the estate rather than a withheld one.
                'balance_minor' => $maySeePosition
                    ? $account->signedMinor((int) $totals['debit'], (int) $totals['credit'])
                    : null,
                'is_active' => $account->is_active,
                // A control account is never archived (`archiveAccount()` refuses it), so the chart offers no Archive on it.
                'is_control' => (bool) $account->is_control,
                'for_period' => $forPeriod,
            ];
        }

        if ($maySeePosition) {
            $earlier = $surplusToDate - $surplusThisYear;

            if ($earlier !== 0) {
                $groups[Account::EQUITY]['rows'][] = $this->surplusRow('surplus-earlier', ($earlier < 0 ? 'Deficit' : 'Surplus').' from earlier years, not yet closed to the fund', $earlier);
            }

            $groups[Account::EQUITY]['rows'][] = $this->surplusRow('surplus-year', ($surplusThisYear < 0 ? 'Deficit' : 'Surplus').' for '.$asAt->year.' so far', $surplusThisYear);
        }

        foreach ($groups as $type => $group) {
            $groups[$type]['total_minor'] = $maySeePosition ? (int) array_sum(array_column($group['rows'], 'balance_minor')) : null;
        }

        return [
            'groups' => array_values(array_filter(
                $groups,
                static fn (array $group): bool => $group['rows'] !== [],
            )),
            // Both sides of the balance sheet, each summed from its own groups' rows.
            'balance' => $maySeePosition ? [
                'assets_minor' => $groups[Account::ASSET]['total_minor'],
                'liabilities_and_equity_minor' => $groups[Account::LIABILITY]['total_minor'] + $groups[Account::EQUITY]['total_minor'],
            ] : null,
            'period' => $from->format('F Y'),

            // The day every figure above is read as at, which the note beneath the chart prints.
            'asAt' => $asAt->format('j M Y'),
            'kpis' => $maySeePosition ? $this->chartKpis($cumulative) : [],
            'positionWithheld' => ! $maySeePosition,
            'positionReason' => Dues::POSITION_WITHHELD.' Every account\'s code, name and type are here for you to post against.',
        ];
    }

    /**
     * A surplus row in the chart's Equity group (EST-S-07): not an account, so no code, nothing to open or archive.
     *
     * @return array<string, mixed>
     */
    private function surplusRow(string $id, string $name, int $minor): array
    {
        return [
            'id' => $id,
            'code' => '',
            'name' => $name,
            'type' => ucfirst(Account::EQUITY),
            'balance_minor' => $minor,
            'is_active' => true,
            'for_period' => false,
            'derived' => true,
        ];
    }

    /**
     * The four tiles above the chart.
     *
     * "Cash on hand" is the total of the accounts FLAGGED as bank accounts, not
     * a range of codes. Every estate numbers its chart differently and a
     * hardcoded "1000 to 1099" would be wrong the first time one renumbered;
     * `is_bank_account` is also what bank reconciliation reads, so the flag
     * earns its place twice.
     *
     * "Reserve fund" is the account MARKED as the reserve (bug hunt B035), and
     * nothing else. It used to be whichever bank account had the highest code,
     * which was 1010 only by the accident of sorting: add 1020 Petty Cash as a
     * bank account and the tile called the petty cash the estate's reserve. Where
     * no account is marked there is no reserve tile — a missing tile is a gap a
     * treasurer can see, where a wrong one is a figure they would trust.
     *
     * @param  array<int, array{debit: int, credit: int}>  $cumulative
     * @return list<array<string, mixed>>
     */
    private function chartKpis(array $cumulative): array
    {
        $balance = function (Account $account) use ($cumulative): int {
            $totals = $cumulative[$account->id] ?? ['debit' => 0, 'credit' => 0];

            return $account->signedMinor((int) $totals['debit'], (int) $totals['credit']);
        };

        $cash = Account::query()->where('is_bank_account', true)->get()->sum($balance);
        $reserves = Account::query()->where('is_reserve_fund', true)->get();

        $receivable = Account::query()->where('code', '1200')->first();
        $payable = Account::query()->where('code', '2000')->first();

        return [
            ['key' => 'cash', 'icon' => 'dues', 'value_minor' => (int) $cash, 'label' => 'Cash on hand'],
            ['key' => 'receivable', 'icon' => 'estate', 'value_minor' => $receivable === null ? 0 : $balance($receivable), 'label' => 'Dues receivable'],
            ['key' => 'payable', 'icon' => 'residents', 'value_minor' => $payable === null ? 0 : $balance($payable), 'label' => 'Bills payable'],
            ...($reserves->isEmpty() ? [] : [
                ['key' => 'reserve', 'icon' => 'accounting', 'value_minor' => (int) $reserves->sum($balance), 'label' => 'Reserve fund'],
            ]),
        ];
    }

    /**
     * Add an account to the chart — board 25's "Add account" (12 §2, Wave 1).
     *
     * THE REVIEW STEP IS THE CALLER'S: the screen makes the person adding it
     * confirm the code, the name and the type before the press, because an
     * account added by mistake cannot be removed once anything has posted to
     * it. This method refuses the two things a review cannot catch by eye — a
     * code already in use, and a parent of a different type — and creates the
     * row active, with no balance, because a balance is what the entries say.
     *
     * A parent of the same type only: "1250 Deposits receivable" under "1200
     * Dues Receivable" is a tree that means something, and an expense filed
     * under an asset is a chart that lies about what it holds.
     */
    public function addAccount(string $code, string $name, string $type, ?Account $parent = null, bool $isBankAccount = false): Account
    {
        $code = trim($code);
        $name = trim($name);

        if (! in_array($type, [Account::ASSET, Account::LIABILITY, Account::EQUITY, Account::INCOME, Account::EXPENSE], true)) {
            throw new DomainException('An account is an asset, a liability, equity, income or an expense. "'.$type.'" is none of those.');
        }

        if ($code === '' || preg_match('/^[0-9][0-9A-Za-z\-]{1,15}$/', $code) !== 1) {
            throw new DomainException('An account code starts with a digit and runs to sixteen characters, as 5300 or 5300-01.');
        }

        if (Account::query()->where('code', $code)->exists()) {
            throw new DomainException(sprintf(
                'Account %s already exists in this chart. Every code names one account; a second under the '.
                'same number would make every entry against it ambiguous.',
                $code,
            ));
        }

        if ($parent !== null && $parent->type !== $type) {
            throw new DomainException(sprintf(
                '%s %s is %s, and an account filed under it has to be the same. A %s under a %s is a chart '.
                'that says the wrong thing about what it holds.',
                $parent->code,
                $parent->name,
                $parent->type,
                $type,
                $parent->type,
            ));
        }

        if ($isBankAccount && $type !== Account::ASSET) {
            throw new DomainException('Only an asset can be money at a bank.');
        }

        return Account::create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'parent_id' => $parent?->id,
            'is_control' => false,
            'is_bank_account' => $isBankAccount,
            'is_active' => true,
        ]);
    }

    /**
     * Archive an account. NEVER DELETED — an account with posted journals is
     * history the chart has to keep, and the restricting foreign key on
     * `journal_lines.account_id` says so at the database. Archived means no
     * new entry may post to it; everything already posted stands.
     */
    public function archiveAccount(Account $account): Account
    {
        if ($account->is_control) {
            throw new DomainException(sprintf(
                '%s %s is a control account: its balance is what a sub-ledger has to tie to, and the chart '.
                'cannot lose it.',
                $account->code,
                $account->name,
            ));
        }

        if (! $account->is_active) {
            return $account;
        }

        $account->archive();

        return $account;
    }

    /** The balance of one account, on the side it normally sits. */
    public function balanceOf(Account $account, ?Carbon $asAt = null): Money
    {
        // This account's lines, not every account's (M11 A2) — the same sums.
        $row = $this->accountTotals($asAt, accountId: $account->id)[$account->id] ?? ['debit' => 0, 'credit' => 0];

        return $account->balanceFrom((int) $row['debit'], (int) $row['credit']);
    }

    /**
     * What the sub-ledger behind a control account adds up to.
     *
     * This is the other half of the tie. A control account's balance is the sum
     * of the lines posted to it; the subsidiary total is the same sum grouped
     * by the household or vendor each line belongs to. They are computed from
     * the same rows on purpose — what the tie actually proves is that every
     * line hitting a control account CARRIES its sub-ledger link, because a
     * line that does not would appear in the first total and vanish from the
     * second.
     *
     * @return array<int|string, int> subsidiary id => signed minor units
     */
    public function subsidiaryBalances(Account $control): array
    {
        if (! $control->is_control || $control->subsidiary === null) {
            throw new InvalidArgumentException(
                "Account [{$control->code}] is not a control account, so it has no sub-ledger to total."
            );
        }

        $column = $control->subsidiary === Account::SUBSIDIARY_VENDORS ? 'vendor_id' : 'unit_id';

        /*
         * The query builder, not the model. These rows are aggregates — a sum
         * and a grouping key — not journal lines, and asking Eloquent to hydrate
         * a JournalLine out of them would produce a model whose own columns are
         * absent and whose totals are not properties of anything.
         */
        $rows = DB::connection('tenant')
            ->table('journal_lines')
            ->where('account_id', $control->id)
            ->selectRaw(
                $column.' as subject, SUM(debit_minor) as debits, SUM(credit_minor) as credits'
            )
            ->groupBy($column)
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            // A line with no sub-ledger link groups under the empty key, which
            // is what makes it visible instead of silently lost.
            $key = $row->subject === null ? '' : (int) $row->subject;

            $balances[$key] = $control->signedMinor((int) $row->debits, (int) $row->credits);
        }

        return $balances;
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Debit and credit totals per account, in one query.
     *
     * @return array<int, array{debit: int, credit: int}>
     */
    private function accountTotals(?Carbon $asAt = null, ?Carbon $from = null, ?int $accountId = null): array
    {
        // The query builder rather than the model, for the reason given in
        // subsidiaryBalances(): these are aggregate rows, not journal lines.
        $query = DB::connection('tenant')
            ->table('journal_lines')
            ->selectRaw('account_id, SUM(debit_minor) as debit, SUM(credit_minor) as credit')
            ->groupBy('account_id');

        if ($accountId !== null) {
            $query->where('journal_lines.account_id', $accountId);
        }

        if ($asAt !== null || $from !== null) {
            // Joined to the header, because the date an entry BELONGS to is the
            // posting date on the header, not the moment the row was written.
            $query->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref');

            if ($asAt !== null) {
                $query->where('journals.posted_on', '<=', $asAt->toDateString());
            }

            // `from` narrows to a PERIOD, which is what an income or expense
            // account means. A cumulative income figure is a number nobody can
            // act on.
            if ($from !== null) {
                $query->where('journals.posted_on', '>=', $from->toDateString());
            }
        }

        $totals = [];

        foreach ($query->get() as $row) {
            $totals[(int) $row->account_id] = [
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
            ];
        }

        return $totals;
    }

    /**
     * @param  list<Posting>  $postings
     * @return array<string, Account>
     */
    private function resolveAccounts(array $postings): array
    {
        $codes = array_values(array_unique(array_map(
            static fn (Posting $p): string => $p->accountCode,
            $postings,
        )));

        return $this->checkAccounts($postings, Account::query()->whereIn('code', $codes)->get()->keyBy('code'));
    }

    /**
     * The accounts these postings name, refusing one that is missing or archived —
     * `resolveAccounts()`'s checks over accounts already read, so a batch reads the
     * chart once and still refuses each entry in the same words.
     *
     * @param  list<Posting>  $postings
     * @param  Collection<string, Account>  $accounts  keyed by code
     * @return array<string, Account>
     */
    private function checkAccounts(array $postings, Collection $accounts): array
    {
        $codes = array_values(array_unique(array_map(
            static fn (Posting $p): string => $p->accountCode,
            $postings,
        )));

        foreach ($codes as $code) {
            $account = $accounts->get($code);

            if ($account === null) {
                throw new DomainException(
                    "No account [{$code}] in this estate's chart. An entry cannot post against an ".
                    'account that does not exist, and creating one silently would let a typo become '.
                    'a permanent line in the chart.'
                );
            }

            /*
             * An archived account still holds its history and still appears on
             * a trial balance. What it cannot do is take new postings — that is
             * the whole difference between archiving and deleting.
             */
            if (! $account->is_active) {
                throw new DomainException(
                    "Account [{$account->code} {$account->name}] is archived and cannot take new postings. ".
                    'Its history remains; reactivate it deliberately if it is still in use.'
                );
            }
        }

        /** @var array<string, Account> */
        return $accounts->all();
    }

    /**
     * @param  list<Posting>  $postings
     */
    private function assertBalanced(array $postings): void
    {
        $debits = array_sum(array_map(static fn (Posting $p): int => $p->debitMinor, $postings));
        $credits = array_sum(array_map(static fn (Posting $p): int => $p->creditMinor, $postings));

        if ($debits !== $credits) {
            /*
             * The database would refuse this a moment later. It is caught here
             * so the message can name both totals and the difference — a raw
             * SQLSTATE 45000 tells whoever hits it that something is wrong and
             * nothing about what.
             */
            throw new DomainException(sprintf(
                'This entry does not balance: %s debited against %s credited, a difference of %s. '.
                'Every entry states where an amount came from and where it went, so the two sides '.
                'are the same number by construction.',
                number_format($debits / 100, 2),
                number_format($credits / 100, 2),
                number_format(abs($debits - $credits) / 100, 2),
            ));
        }
    }

    /**
     * @param  list<Posting>  $postings
     */
    private function singleCurrency(array $postings): string
    {
        $currencies = array_values(array_unique(array_map(
            static fn (Posting $p): string => $p->currency,
            $postings,
        )));

        if (count($currencies) > 1) {
            throw new DomainException(
                'One entry, one currency: '.implode(' and ', $currencies).' cannot be added together.'
            );
        }

        return $currencies[0];
    }

    /**
     * The date an entry belongs to: the one it was given, read for the calendar
     * date it carries, or the estate's today — never UTC's (bug hunt B023).
     */
    private function postedOn(Carbon|string|null $on): Carbon
    {
        return match (true) {
            $on instanceof Carbon => $on->copy()->startOfDay(),
            is_string($on) => Carbon::parse($on)->startOfDay(),
            default => self::today(),
        };
    }

    /**
     * The next reference in this prefix and month — "JV-2026-09-0007".
     *
     * Allocated inside the caller's transaction with the month's rows locked,
     * so two committee members posting at once cannot be handed the same
     * number. Derived from the entries themselves rather than from a counter
     * table: a counter is a second thing to keep in step, and the one time it
     * falls behind it hands out a reference that already exists.
     */
    private function allocateReference(string $prefix, Carbon $postedOn): string
    {
        return $this->stampReference($prefix, $postedOn, $this->allocateSequence($prefix, $postedOn));
    }

    /**
     * The next number in this prefix and month, under the month's lock.
     *
     * Separated from the formatting (M11 A2) so `postEach()` can take it once and
     * advance it in memory — the same split `Dues::nextSequence()` already makes
     * for a dues run's charge references.
     */
    private function allocateSequence(string $prefix, Carbon $postedOn): int
    {
        $stem = sprintf('%s-%s-', $prefix, $postedOn->format('Y-m'));

        // Past every reference the unique index holds, a retired sample journal's too (M33 §5).
        $last = Retirements::seeingEverything(static fn (): mixed => Journal::query()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference'));

        return $last === null ? 1 : ((int) substr($last, strlen($stem))) + 1;
    }

    /** "JV-2026-09-0007". */
    private function stampReference(string $prefix, Carbon $postedOn, int $sequence): string
    {
        return sprintf('%s-%s-', $prefix, $postedOn->format('Y-m')).str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function postingFrom(JournalLine $line): Posting
    {
        return $line->isDebit()
            ? Posting::debit($line->account->code, $line->debit_minor, $line->memo, $line->unit_id, $line->vendor_id, $line->currency)
            : Posting::credit($line->account->code, $line->credit_minor, $line->memo, $line->unit_id, $line->vendor_id, $line->currency);
    }
}
