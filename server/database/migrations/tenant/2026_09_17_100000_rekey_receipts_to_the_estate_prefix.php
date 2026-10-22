<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts move from the subdomain prefix to the estate's own (13 A1).
 *
 * `PHOENIXPARK-R-04471` becomes `PPV-R-04471`. The integer is untouched — it is
 * the part a resident has on paper and the part the sequence counts — and the
 * sequence row is renamed rather than restarted, so the next receipt continues
 * from the same high-water mark and every gap stays where it was.
 *
 * DONE ONCE, BY MIGRATION, BEFORE ANY PILOT RECEIPT. The prefix is immutable
 * from the first receipt, and this is the single exception: the receipts it
 * renames were issued under a reading of 12 §1 that the client has since
 * restated, on seeded estates, before a live estate printed one.
 *
 * JOURNAL MEMOS ARE NOT REWRITTEN. The ledger is append-only by grant, and a
 * memo reading "receipt PHOENIXPARK-R-04471" still names the same receipt by
 * the same number.
 *
 * Needs the central migration that adds `tenants.receipt_prefix` to have run
 * first; it refuses rather than guesses when it has not. A database no estate
 * record names — a test fixture — is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $central = (string) config('tenancy.database.central_connection');

        if (! Schema::connection($central)->hasColumn('tenants', 'receipt_prefix')) {
            throw new RuntimeException('Run the central migrations first: tenants.receipt_prefix does not exist yet.');
        }

        $database = (string) $connection->getDatabaseName();
        $key = (string) preg_replace('/^'.preg_quote((string) config('tenancy.database.prefix'), '/').'/', '', $database);
        $new = DB::connection($central)->table('tenants')->where('id', $key)->value('receipt_prefix');
        $old = strtoupper($key);

        if ($new === null || $new === $old) {
            return;
        }

        $connection->transaction(function () use ($connection, $old, $new): void {
            $connection->update(
                'UPDATE payments SET receipt_no = CONCAT(?, SUBSTRING(receipt_no, ?)) WHERE receipt_no LIKE ?',
                [$new, strlen($old) + 1, $old.'-R-%'],
            );

            // A row under the new prefix can only be an empty one; the old row
            // carries the high-water mark and takes its place.
            $connection->table('receipt_sequences')->where('prefix', $new)->where('last_no', 0)->delete();
            $connection->table('receipt_sequences')->where('prefix', $old)->update(['prefix' => $new, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        // Irreversible by intent: the estate's prefix is the one it prints now.
    }
};
