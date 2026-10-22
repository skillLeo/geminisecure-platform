<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Account;
use App\Models\Estate\Journal;
use App\Models\Tenant;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The money gate: the extra bar the accounting modules carry.
 *
 *     php artisan gate:ledger
 *
 * Three promises are made about this ledger, and none of them is the kind a
 * reader can check by looking:
 *
 *   1. DEBITS EQUAL CREDITS AT EVERY WRITE
 *   2. EVERY SUB-LEDGER TIES TO ITS CONTROL ACCOUNT
 *   3. A POSTED JOURNAL CANNOT BE EDITED
 *
 * An unbalanced ledger does not look unbalanced. A control account that has
 * drifted from its sub-ledger shows a plausible number on every screen it
 * appears on. An entry somebody edited looks exactly like one nobody did. So
 * each promise is PROVEN here rather than assumed, in two ways: by auditing
 * every row already posted, and by attempting the forbidden write live against
 * the real estate database and requiring it to be refused.
 *
 * The live probes matter more than the audit. An audit proves nothing was
 * broken yesterday; a probe proves it cannot be broken today. `gate:isolation`
 * takes the same position about append-only records and this follows it.
 *
 * Exits non-zero on any failure, so it belongs in CI rather than in a habit.
 *
 * Deliberately pure ASCII, for the reason GateTenantIsolation states: this file
 * is rewritten by tooling on Windows, where a UTF-8 round-trip can place a BOM
 * ahead of the strict_types declaration and break the file outright.
 */
class GateLedger extends Command
{
    protected $signature = 'gate:ledger
        {--estate= : Check one estate only. Default: every provisioned estate.}
        {--no-probe : Audit the posted rows without attempting the forbidden writes.}';

    protected $description = 'Prove double-entry: debits equal credits, sub-ledgers tie, posted entries are immutable';

    /** The triggers that carry the guarantees. A missing one is a silent hole. */
    private const REQUIRED_TRIGGERS = [
        'journals_must_balance',
        'journals_no_update',
        'journals_no_delete',
        'journal_lines_no_late_addition',
        'journal_lines_no_update',
        'journal_lines_no_delete',

        // 13 A2: a financial document is kept seven years, as issued.
        'documents_no_early_delete',
        'documents_retention_fixed',
    ];

    private const REQUIRED_CHECKS = [
        'journal_lines_one_side_only',
        'journal_lines_never_negative',
    ];

    private int $failures = 0;

    /**
     * Assertions that could not run.
     *
     * Counted and reported, never swallowed. A gate that skips every real check
     * and prints PASSED is worse than no gate: it reports a guarantee it did not
     * test, and the reader has no way to tell that from one it did.
     */
    private int $skipped = 0;

    public function handle(): int
    {
        $estates = $this->option('estate') !== null
            ? collect([Tenant::findOrFail((string) $this->option('estate'))])
            : Tenant::estates();

        if ($estates->isEmpty()) {
            $this->error(' No estates provisioned; there is no ledger to check.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('=====================================================================');
        $this->line(' LEDGER GATE - double-entry, control accounts, immutability');
        $this->line('=====================================================================');

        foreach ($estates as $estate) {
            $estate->run(function () use ($estate): void {
                $this->line('');
                $this->line(' ESTATE: '.$estate->getTenantKey().' ('.$estate->name.')');

                $this->enforcementIsInPlace();
                $this->everyEntryBalances();
                $this->noOrphanLines();
                $this->trialBalanceAgrees();
                $this->subLedgersTie();
                $this->reversalsMirror();

                if (! $this->option('no-probe')) {
                    $this->forbiddenWritesAreRefused();
                }
            });
        }

        $this->line('');

        if ($this->failures > 0) {
            $this->error(" LEDGER GATE FAILED - {$this->failures} assertion(s) did not hold");

            return self::FAILURE;
        }

        if ($this->skipped > 0) {
            /*
             * Deliberately not a pass. Every skip above is an assertion the
             * ledger has not been put through - almost always because the
             * estate has no chart of accounts yet - and reporting that as a
             * clean gate would be the platform telling itself its money is
             * proven when it has not been counted.
             */
            $this->warn(
                " LEDGER GATE INCOMPLETE - nothing failed, but {$this->skipped} assertion(s) could not run. ".
                'This is not a pass.'
            );

            return self::FAILURE;
        }

        $this->info(' LEDGER GATE PASSED - every assertion held');

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */
    /* the machinery itself */
    /* ------------------------------------------------------------------ */

    /**
     * The triggers and constraints exist.
     *
     * Checked first, and it is not paperwork. Every other assertion below is
     * only as good as the machinery that enforces it between runs: a later
     * migration that rebuilt one of these tables without restoring its triggers
     * would leave an audit that passes and a database that no longer refuses
     * anything.
     */
    private function enforcementIsInPlace(): void
    {
        $this->section('enforcement');

        $database = DB::connection('tenant')->getDatabaseName();

        $present = collect(DB::connection('tenant')->select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
            [$database],
        ))->pluck('TRIGGER_NAME')->all();

        foreach (self::REQUIRED_TRIGGERS as $trigger) {
            $this->assert(
                in_array($trigger, $present, true),
                "trigger {$trigger} is installed",
                'missing - the guarantee it carries is not being enforced',
            );
        }

        $checks = collect(DB::connection('tenant')->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = ? AND CONSTRAINT_TYPE = ?',
            [$database, 'CHECK'],
        ))->pluck('CONSTRAINT_NAME')->all();

        foreach (self::REQUIRED_CHECKS as $check) {
            $this->assert(
                in_array($check, $checks, true),
                "check constraint {$check} is installed",
                'missing - a line could carry both sides, or a negative one',
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* 1. debits equal credits */
    /* ------------------------------------------------------------------ */

    /**
     * Every entry already posted still balances, and its header still agrees
     * with its own lines.
     *
     * The header total is checked as well as the two sides, because a header
     * that disagrees with its lines is the failure that hides best: the entry
     * balances, the trial balance balances, and every screen that reads the
     * header shows the wrong number.
     */
    private function everyEntryBalances(): void
    {
        $this->section('1. debits equal credits');

        // `line_count`, not `lines`: LINES is reserved in MySQL and the query
        // fails to parse with it as an alias.
        $broken = DB::connection('tenant')->select('
            SELECT j.reference,
                   j.amount_minor,
                   COUNT(l.id)                        AS line_count,
                   COALESCE(SUM(l.debit_minor), 0)    AS debits,
                   COALESCE(SUM(l.credit_minor), 0)   AS credits
              FROM journals j
              LEFT JOIN journal_lines l ON l.entry_ref = j.reference
             GROUP BY j.reference, j.amount_minor
            HAVING debits <> credits
                OR debits <> j.amount_minor
                OR line_count < 2
        ');

        $total = (int) DB::connection('tenant')->table('journals')->count();

        $this->assert(
            $broken === [],
            "all {$total} posted entr(ies) balance, and each header agrees with its own lines",
            count($broken).' do not: '.implode(', ', array_map(
                static fn (object $r): string => sprintf(
                    '%s (%d line(s), Dr %d / Cr %d, header %d)',
                    $r->reference, $r->line_count, $r->debits, $r->credits, $r->amount_minor,
                ),
                array_slice($broken, 0, 5),
            )),
        );
    }

    /**
     * No line belongs to an entry that was never posted.
     *
     * `journal_lines` has no foreign key to `journals` - it cannot, because the
     * lines are written first so that the header's insert can count them. This
     * is the check that stands in for the key it cannot have.
     */
    private function noOrphanLines(): void
    {
        $orphans = DB::connection('tenant')->select('
            SELECT l.entry_ref, COUNT(*) AS line_count
              FROM journal_lines l
              LEFT JOIN journals j ON j.reference = l.entry_ref
             WHERE j.reference IS NULL
             GROUP BY l.entry_ref
        ');

        $this->assert(
            $orphans === [],
            'no journal line belongs to an entry that was never posted',
            count($orphans).' orphaned reference(s): '.implode(', ', array_map(
                static fn (object $r): string => $r->entry_ref,
                array_slice($orphans, 0, 5),
            )).' - an entry was half-written and its transaction did not roll back',
        );
    }

    /**
     * The whole ledger nets to zero.
     *
     * The sum of every debit against the sum of every credit, across every
     * account and every period. It follows from each entry balancing, and it is
     * checked separately anyway: this is the one number an accountant would
     * actually look at, and a check that restates it in the reader's own terms
     * is worth more than one that only a developer can interpret.
     */
    private function trialBalanceAgrees(): void
    {
        $totals = DB::connection('tenant')->selectOne('
            SELECT COALESCE(SUM(debit_minor), 0) AS debits, COALESCE(SUM(credit_minor), 0) AS credits
              FROM journal_lines
        ');

        $debits = (int) ($totals->debits ?? 0);
        $credits = (int) ($totals->credits ?? 0);

        $this->assert(
            $debits === $credits,
            sprintf('the trial balance agrees: %s debit against %s credit', $this->money($debits), $this->money($credits)),
            sprintf('out by %s', $this->money(abs($debits - $credits))),
        );
    }

    /* ------------------------------------------------------------------ */
    /* 2. sub-ledgers tie to control accounts */
    /* ------------------------------------------------------------------ */

    /**
     * Every control account equals the sub-ledger behind it.
     *
     * TWO FAILURES ARE BEING LOOKED FOR, and the second is the one that
     * actually happens. The first is arithmetic - the totals differ. The second
     * is a line posted to a control account with NO sub-ledger link on it: the
     * control balance counts it, every household statement does not, and the
     * estate is chasing a debt that appears on nobody's ledger.
     */
    private function subLedgersTie(): void
    {
        $this->section('2. sub-ledgers tie to their control accounts');

        $controls = Account::query()->where('is_control', true)->whereNotNull('subsidiary')->get();

        if ($controls->isEmpty()) {
            $this->skip('no control accounts in this chart yet');

            return;
        }

        $ledger = app(Ledger::class);

        foreach ($controls as $control) {
            $balance = $ledger->balanceOf($control)->getMinorAmount()->toInt();
            $subsidiary = $ledger->subsidiaryBalances($control);

            // A line with no sub-ledger link groups under the empty key.
            $unlinked = $subsidiary[''] ?? 0;
            unset($subsidiary['']);

            $this->assert(
                $unlinked === 0,
                sprintf('every line on %s %s carries its %s', $control->code, $control->name, rtrim($control->subsidiary, 's')),
                sprintf(
                    '%s sits on the control account with no %s named - it is in the control balance and on nobody\'s statement',
                    $this->money(abs($unlinked)),
                    rtrim($control->subsidiary, 's'),
                ),
            );

            $sum = array_sum($subsidiary) + $unlinked;

            $this->assert(
                $sum === $balance,
                sprintf(
                    '%s %s ties to its sub-ledger: %s across %d %s',
                    $control->code,
                    $control->name,
                    $this->money($balance),
                    count($subsidiary),
                    $control->subsidiary,
                ),
                sprintf('control says %s, sub-ledger says %s', $this->money($balance), $this->money($sum)),
            );
        }
    }

    /**
     * A reversal is the original with both sides swapped.
     *
     * Checked because the shape of a reversal is the thing that decides whether
     * a correction is honest. Every reversing pair must net to nothing on every
     * account it touched - if it nets to nothing overall but leaves two accounts
     * wrong in opposite directions, the trial balance still agrees and the
     * accounts do not.
     */
    private function reversalsMirror(): void
    {
        $this->section('3. reversals mirror what they correct');

        $reversals = Journal::query()->whereNotNull('reverses_journal_id')->with('lines')->get();

        if ($reversals->isEmpty()) {
            $this->skip('nothing has been reversed in this estate');

            return;
        }

        foreach ($reversals as $reversal) {
            $original = $reversal->reverses;

            if ($original === null) {
                $this->assert(false, "reversal {$reversal->reference} names the entry it corrects", 'the original is missing');

                continue;
            }

            $net = [];

            foreach ($original->lines as $line) {
                $net[$line->account_id] = ($net[$line->account_id] ?? 0) + $line->debit_minor - $line->credit_minor;
            }

            foreach ($reversal->lines as $line) {
                $net[$line->account_id] = ($net[$line->account_id] ?? 0) + $line->debit_minor - $line->credit_minor;
            }

            $offending = array_keys(array_filter($net, static fn (int $n): bool => $n !== 0));

            $this->assert(
                $offending === [],
                "{$reversal->reference} cancels {$original->reference} on every account it touched",
                'account id(s) '.implode(', ', $offending).' are left out of balance by the pair',
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* 3. a posted journal cannot be edited */
    /* ------------------------------------------------------------------ */

    /**
     * Attempt each forbidden write against the real estate database.
     *
     * This is the half of the gate that proves something about tomorrow. Each
     * probe runs inside a transaction that is rolled back, so a probe that
     * unexpectedly SUCCEEDS leaves nothing behind - which is exactly the case
     * where leaving something behind would be worst.
     */
    private function forbiddenWritesAreRefused(): void
    {
        $this->section('4. the forbidden writes are refused, live');

        $accounts = Account::query()->where('is_active', true)->orderBy('code')->take(2)->get();

        if ($accounts->count() < 2) {
            $this->skip('this estate has fewer than two active accounts to probe with');

            return;
        }

        [$a, $b] = [$accounts[0], $accounts[1]];

        /*
         * Positive control first, and for the same reason gate:isolation runs
         * one: without it every probe below passes when the ledger is simply
         * broken, and the gate reports an enforcement it never demonstrated.
         */
        $this->probe(
            'CONTROL - a balanced entry CAN be posted',
            expectRefusal: false,
            attempt: function () use ($a, $b): void {
                app(Ledger::class)->post('gate:ledger probe', [
                    Posting::debit($a->code, 1000),
                    Posting::credit($b->code, 1000),
                ]);
            },
        );

        $this->probe(
            'an unbalanced entry is refused',
            expectRefusal: true,
            attempt: function () use ($a, $b): void {
                // Written straight to the tables, bypassing Ledger's own check,
                // so what is being proven is the DATABASE refusing it.
                $ref = 'PROBE-UNBALANCED';
                DB::connection('tenant')->table('journal_lines')->insert([
                    ['entry_ref' => $ref, 'account_id' => $a->id, 'line_no' => 1, 'debit_minor' => 1000, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now()],
                    ['entry_ref' => $ref, 'account_id' => $b->id, 'line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 900, 'currency' => 'JMD', 'created_at' => now()],
                ]);
                DB::connection('tenant')->table('journals')->insert([
                    'reference' => $ref, 'memo' => 'probe', 'source' => 'manual', 'amount_minor' => 1000,
                    'currency' => 'JMD', 'posted_on' => now()->toDateString(), 'created_at' => now(),
                ]);
            },
        );

        $this->probe(
            'a one-sided entry is refused',
            expectRefusal: true,
            attempt: function () use ($a): void {
                $ref = 'PROBE-ONE-LINE';
                DB::connection('tenant')->table('journal_lines')->insert([
                    'entry_ref' => $ref, 'account_id' => $a->id, 'line_no' => 1, 'debit_minor' => 1000, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now(),
                ]);
                DB::connection('tenant')->table('journals')->insert([
                    'reference' => $ref, 'memo' => 'probe', 'source' => 'manual', 'amount_minor' => 1000,
                    'currency' => 'JMD', 'posted_on' => now()->toDateString(), 'created_at' => now(),
                ]);
            },
        );

        $this->probe(
            'a line carrying both a debit and a credit is refused',
            expectRefusal: true,
            attempt: function () use ($a): void {
                DB::connection('tenant')->table('journal_lines')->insert([
                    'entry_ref' => 'PROBE-TWO-SIDED', 'account_id' => $a->id, 'line_no' => 1,
                    'debit_minor' => 500, 'credit_minor' => 500, 'currency' => 'JMD', 'created_at' => now(),
                ]);
            },
        );

        $this->probe(
            'a negative posting is refused',
            expectRefusal: true,
            attempt: function () use ($a): void {
                DB::connection('tenant')->table('journal_lines')->insert([
                    'entry_ref' => 'PROBE-NEGATIVE', 'account_id' => $a->id, 'line_no' => 1,
                    'debit_minor' => -500, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now(),
                ]);
            },
        );

        $posted = Journal::query()->orderByDesc('id')->first();

        if ($posted === null) {
            $this->skip('nothing is posted in this estate to attempt an edit against');

            return;
        }

        $this->probe(
            "a line cannot be added to posted entry {$posted->reference}",
            expectRefusal: true,
            attempt: function () use ($posted, $a): void {
                DB::connection('tenant')->table('journal_lines')->insert([
                    'entry_ref' => $posted->reference, 'account_id' => $a->id, 'line_no' => 99,
                    'debit_minor' => 100, 'credit_minor' => 0, 'currency' => 'JMD', 'created_at' => now(),
                ]);
            },
        );

        $this->probe(
            'a posted journal header cannot be edited',
            expectRefusal: true,
            attempt: function () use ($posted): void {
                DB::connection('tenant')->table('journals')->where('id', $posted->id)->update(['memo' => 'edited']);
            },
        );

        $this->probe(
            'a posted journal line cannot be edited',
            expectRefusal: true,
            attempt: function () use ($posted): void {
                DB::connection('tenant')->table('journal_lines')->where('entry_ref', $posted->reference)->update(['memo' => 'edited']);
            },
        );

        $this->probe(
            'a posted journal line cannot be deleted',
            expectRefusal: true,
            attempt: function () use ($posted): void {
                DB::connection('tenant')->table('journal_lines')->where('entry_ref', $posted->reference)->delete();
            },
        );

        $this->probe(
            'an account with posted lines cannot be deleted',
            expectRefusal: true,
            attempt: function (): void {
                $used = DB::connection('tenant')->table('journal_lines')->value('account_id');

                if ($used === null) {
                    throw new \RuntimeException('no posted line to probe with');
                }

                DB::connection('tenant')->table('accounts')->where('id', $used)->delete();
            },
        );
    }

    /**
     * Run one probe inside a transaction and always roll it back.
     *
     * MySQL aborts the offending STATEMENT when a trigger signals, not the
     * whole transaction, so a refusal leaves the transaction usable and the
     * rollback is what guarantees the probe changed nothing either way.
     */
    private function probe(string $proves, bool $expectRefusal, callable $attempt): void
    {
        $connection = DB::connection('tenant');
        $refused = false;
        $why = '';

        $connection->beginTransaction();

        try {
            $attempt();
        } catch (Throwable $e) {
            $refused = true;
            $why = $e->getMessage();
        } finally {
            $connection->rollBack();
        }

        if ($expectRefusal) {
            $this->assert($refused, $proves, 'IT WAS ACCEPTED - the guarantee does not hold');

            return;
        }

        $this->assert(! $refused, $proves, 'it was refused: '.substr($why, 0, 140));
    }

    /* ------------------------------------------------------------------ */

    private function money(int $minor): string
    {
        return 'J$'.number_format($minor / 100, 2);
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("--- {$title} ".str_repeat('-', max(0, 60 - strlen($title))));
    }

    /**
     * An assertion that could not run, counted rather than passed over.
     */
    private function skip(string $why): void
    {
        $this->skipped++;
        $this->line("  <fg=yellow>SKIP</> {$why}");
    }

    private function assert(bool $held, string $proves, string $failureDetail): void
    {
        if ($held) {
            $this->line("  <fg=green>PASS</> {$proves}");

            return;
        }

        $this->failures++;
        $this->line("  <fg=red>FAIL</> {$proves} - {$failureDetail}");
    }
}
