<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One entry per bill, bill payment, pay run and return, and one reversal per
 * entry — held by the database (bug hunt B020, B075).
 *
 * `Payables`, `Payroll` and `Ledger::reverse()` now claim their row under a lock
 * before they post, which is what stops a double-click posting twice. This is the
 * second layer, for the same reason the ledger has triggers as well as a service:
 * a rule that holds only while every caller remembers to take a lock is one
 * forgotten caller away from false, and a doubled posting on an append-only
 * ledger is money nobody can take back.
 *
 *   journals_one_reversal_per_entry     `reverses_journal_id` is unique. A second
 *                                       mirror of one entry posts the original
 *                                       amount again rather than cancelling it.
 *
 *   journals_one_entry_per_source_row   a functional unique index over
 *                                       "source:source_id" for the four sources
 *                                       that raise exactly one entry for the row
 *                                       they name: a bill's liability, a bill
 *                                       payment, a pay run's disbursement and a
 *                                       return's remittance. Every other source is
 *                                       NULL in the index and unconstrained — an
 *                                       entry typed by hand names no row at all.
 *
 * AN ESTATE THAT IS ALREADY DOUBLED IS REFUSED, NOT PAPERED OVER. If the bug has
 * already struck, adding the index would fail with a bare "Duplicate entry"; this
 * names the entries instead. The remedy for one is a reversing entry somebody
 * decides to post, which is not a migration's call.
 */
return new class extends Migration
{
    /** The sources that raise exactly one entry for the row they name. */
    private const ONCE_PER_ROW = ['bill', 'bill_payment', 'payroll', 'payroll_remittance'];

    public function up(): void
    {
        $this->refuseWhatIsAlreadyDoubled();

        Schema::table('journals', function (Blueprint $table): void {
            $table->dropIndex(['reverses_journal_id']);
            $table->unique('reverses_journal_id', 'journals_one_reversal_per_entry');
        });

        $sources = implode(', ', array_map(static fn (string $source): string => "'{$source}'", self::ONCE_PER_ROW));

        DB::statement(
            'ALTER TABLE journals ADD UNIQUE INDEX journals_one_entry_per_source_row '.
            "((CASE WHEN source IN ({$sources}) THEN CONCAT(source, ':', source_id) END))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE journals DROP INDEX journals_one_entry_per_source_row');

        Schema::table('journals', function (Blueprint $table): void {
            $table->dropUnique('journals_one_reversal_per_entry');
            $table->index('reverses_journal_id');
        });
    }

    private function refuseWhatIsAlreadyDoubled(): void
    {
        $reversals = DB::table('journals')
            ->whereNotNull('reverses_journal_id')
            ->groupBy('reverses_journal_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('reverses_journal_id')
            ->map(static fn (mixed $id): string => 'entry #'.$id.' is reversed more than once')
            ->all();

        $rows = DB::table('journals')
            ->whereIn('source', self::ONCE_PER_ROW)
            ->whereNotNull('source_id')
            ->groupBy('source', 'source_id')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw("source, source_id, GROUP_CONCAT(reference ORDER BY reference SEPARATOR ', ') AS refs")
            ->get()
            ->map(static fn (stdClass $row): string => sprintf('%s #%s is posted as %s', $row->source, $row->source_id, $row->refs))
            ->all();

        $doubled = [...$reversals, ...$rows];

        if ($doubled === []) {
            return;
        }

        throw new RuntimeException(
            'This estate\'s ledger already carries an entry posted twice, so one entry per row cannot be '.
            'enforced until somebody decides which copy to reverse: '.implode('; ', $doubled).'.'
        );
    }
};
