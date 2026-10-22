<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\User;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one door into Gemini's own books (13 B1).
 *
 * The estate `Ledger`'s rules, on the central connection: lines first, header
 * last, the database refusing an entry that does not balance and any edit to one
 * that does. This class is the ergonomics and the references; the guarantees are
 * the triggers in `create_platform_ledger`.
 *
 * TWO ACCOUNTS, BY NAME. An invoice debits the client's receivable and credits
 * revenue; a credit note does the opposite. Nothing else posts here, and a
 * method per event rather than a general `post(array)` keeps it so.
 */
class PlatformLedger
{
    public const RECEIVABLE = '1100';

    public const REVENUE = '4000';

    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_CREDIT_NOTE = 'credit_note';

    /** Where the next reference per prefix and month is counted (B085). Mutable; the books are not. */
    public const SEQUENCES = 'platform_journal_sequences';

    /** Dr 1100 (this client) / Cr 4000. Returns the entry's reference. */
    public function invoiceRaised(string $tenantId, int $invoiceId, string $invoiceReference, int $amountMinor, string $currency, Carbon $on, ?User $by): string
    {
        return $this->post(
            prefix: 'INV',
            memo: 'Invoice '.$invoiceReference,
            source: self::SOURCE_INVOICE,
            sourceId: $invoiceId,
            lines: [
                ['account' => self::RECEIVABLE, 'debit' => $amountMinor, 'credit' => 0, 'tenant' => $tenantId],
                ['account' => self::REVENUE, 'debit' => 0, 'credit' => $amountMinor, 'tenant' => null],
            ],
            currency: $currency,
            on: $on,
            by: $by,
        );
    }

    /** Dr 4000 / Cr 1100 (this client). Returns the entry's reference. */
    public function creditNoteIssued(string $tenantId, int $creditNoteId, string $noteReference, int $amountMinor, string $currency, Carbon $on, ?User $by): string
    {
        return $this->post(
            prefix: 'CN',
            memo: 'Credit note '.$noteReference,
            source: self::SOURCE_CREDIT_NOTE,
            sourceId: $creditNoteId,
            lines: [
                ['account' => self::REVENUE, 'debit' => $amountMinor, 'credit' => 0, 'tenant' => null],
                ['account' => self::RECEIVABLE, 'debit' => 0, 'credit' => $amountMinor, 'tenant' => $tenantId],
            ],
            currency: $currency,
            on: $on,
            by: $by,
        );
    }

    /** What one client owes on the ledger: debits less credits on 1100 for them. */
    public function receivableFor(string $tenantId): int
    {
        $row = DB::connection('mysql')
            ->table('platform_journal_lines as l')
            ->join('platform_accounts as a', 'a.id', '=', 'l.platform_account_id')
            ->where('a.code', self::RECEIVABLE)
            ->where('l.tenant_id', $tenantId)
            ->selectRaw('COALESCE(SUM(l.debit_minor), 0) AS debits, COALESCE(SUM(l.credit_minor), 0) AS credits')
            ->first();

        return (int) ($row->debits ?? 0) - (int) ($row->credits ?? 0);
    }

    /**
     * @param  list<array{account: string, debit: int, credit: int, tenant: string|null}>  $lines
     */
    private function post(string $prefix, string $memo, string $source, int $sourceId, array $lines, string $currency, Carbon $on, ?User $by): string
    {
        $debits = array_sum(array_column($lines, 'debit'));

        if ($debits <= 0 || $debits !== array_sum(array_column($lines, 'credit'))) {
            throw new DomainException('An entry in Gemini\'s books balances and moves something. This one does not.');
        }

        $connection = DB::connection('mysql');

        /*
         * WHY THE NEXT REFERENCE IS NOT TAKEN UNDER A ROW LOCK, AND CANNOT BE.
         *
         * This read used to carry `->lockForUpdate()`. MySQL implements
         * `SELECT … FOR UPDATE` as a write intention and demands the UPDATE
         * privilege on the table — and `platform_journals` is in
         * `GrantedTables::CENTRAL_APPEND_ONLY`, so `gs_app` deliberately holds
         * no UPDATE on it. The two facts are irreconcilable: every attempt died
         * with
         *
         *     SQLSTATE[42000]: 1142 SELECT with locking clause command denied
         *     to user 'gs_app'@'localhost' for table 'platform_journals'
         *
         * which reached the operator as a 500 on "Raise invoice". Gemini could
         * not bill a single client from its own console, and the cause was two
         * correct decisions meeting: the books are append-only, and the lock
         * asked for permission to write.
         *
         * THE GRANT IS NOT THE THING TO CHANGE. Append-only is an invariant
         * here, locked twice over — the withheld privilege and the
         * `platform_journals_no_update` trigger — and handing the application
         * UPDATE on the ledger to make a sequence allocator work would trade a
         * guarantee about Gemini's books for a convenience.
         *
         * `platform_journals.reference` is UNIQUE in the schema, and so is
         * `(entry_ref, line_no)` on the lines, so the DATABASE refuses a duplicate
         * reference whatever this class does.
         *
         * AND A RETRY COULD NEVER TAKE THE NEXT NUMBER (B085). The reference used
         * to be the month's last one read from `platform_journals`, plus one, with
         * the whole post retried on a collision "reading a sequence that now
         * includes the entry that beat it". It never did. Both callers post inside
         * a transaction of their own — `InvoiceRun::raise()` around the invoice and
         * its lines, `InvoiceActions::creditNote()` around the note — and under
         * REPEATABLE READ that transaction goes on reading the snapshot it took:
         * every retry chose the same stale reference again. In practice the first
         * attempt already died on the `no_late_addition` trigger's 1644 ("that
         * journal is posted"), which the retry did not recognise, so whenever
         * another operator posted in the same month after the caller's transaction
         * began, "Raise and post" answered 500.
         *
         * SO THE NUMBER COMES FROM A COUNTER, NOT FROM THE BOOKS. One row per stem
         * in `SEQUENCES`, a mutable table, bumped by an upsert. A write locks the
         * row and works from its latest COMMITTED value whatever the caller's
         * snapshot says, and the caller's own read of it sees its own write: a
         * second poster in the same month waits for the first to commit and takes
         * the next number, and a posting that rolls back gives its number back.
         * The append-only books are neither locked nor granted anything more.
         */
        return $connection->transaction(function () use ($connection, $prefix, $memo, $source, $sourceId, $lines, $currency, $on, $by, $debits): string {
            $accounts = $connection->table('platform_accounts')->pluck('id', 'code');

            $reference = $this->nextReference($connection, sprintf('%s-%s-', $prefix, $on->format('Y-m')));

            foreach ($lines as $i => $line) {
                if (! isset($accounts[$line['account']])) {
                    throw new DomainException("No account [{$line['account']}] in Gemini's chart.");
                }

                $connection->table('platform_journal_lines')->insert([
                    'entry_ref' => $reference,
                    'platform_account_id' => $accounts[$line['account']],
                    'line_no' => $i + 1,
                    'debit_minor' => $line['debit'],
                    'credit_minor' => $line['credit'],
                    'currency' => $currency,
                    'memo' => $memo,
                    'tenant_id' => $line['tenant'],
                    'created_at' => Carbon::now(),
                ]);
            }

            // The header last: its insert is what checks the lines balance.
            $connection->table('platform_journals')->insert([
                'reference' => $reference,
                'memo' => $memo,
                'source' => $source,
                'source_id' => $sourceId,
                'amount_minor' => $debits,
                'currency' => $currency,
                'posted_on' => $on->toDateString(),
                'posted_by' => $by?->getKey(),
                'posted_by_name' => $by?->name,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return $reference;
        });
    }

    /**
     * The next reference for a stem ("INV-2026-09-"), taken from its counter (B085).
     *
     * The upsert is the lock: it creates the stem's row or bumps it, and either
     * way holds it until the caller's transaction ends, reading the latest
     * committed number rather than the caller's snapshot. The read that follows
     * is this transaction's own write. The number never goes below the highest
     * reference the books already show for the stem — a floor, read without a
     * lock, for a counter that was never seeded or was lost; the counter is what
     * makes two posters take two numbers.
     */
    private function nextReference(ConnectionInterface $connection, string $stem): string
    {
        $floor = 0;

        foreach ($connection->table('platform_journals')->where('reference', 'like', $stem.'%')->pluck('reference') as $reference) {
            $floor = max($floor, (int) substr((string) $reference, strlen($stem)));
        }

        $now = Carbon::now();

        $connection->statement(
            'INSERT INTO '.self::SEQUENCES.' (stem, last_number, created_at, updated_at) VALUES (?, ?, ?, ?)'
            .' ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number + 1, ?), updated_at = ?',
            [$stem, $floor + 1, $now, $now, $floor + 1, $now],
        );

        $number = (int) $connection->table(self::SEQUENCES)->where('stem', $stem)->value('last_number');

        return $stem.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }
}
