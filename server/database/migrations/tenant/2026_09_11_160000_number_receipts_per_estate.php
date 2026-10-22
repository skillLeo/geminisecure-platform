<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt numbering, as ruled (12 §1): `{PREFIX}-R-{00001}`, sequential per
 * estate, never reused, gaps recorded and visible. Prefix from the estate's
 * subdomain, uppercased. Allocated at posting, never at draft.
 *
 * THE SEQUENCE IS A ROW, NOT A MAX. `MAX(receipt_no) + 1` cannot tell a gap from
 * a full run — it only ever sees what is there — and "gaps recorded" needs a
 * high-water mark to compare the register against. `receipt_sequences` holds
 * one row per prefix with the last number handed out; the register draws every
 * number up to it that no receipt carries, and says so.
 *
 * THE PREFIX IS THE DATABASE NAME'S TAIL, which is the subdomain: every estate
 * database is `gs_estate_<subdomain>`, and this migration runs inside the
 * estate's own connection. That is one source for the prefix that is true in
 * production, in the seeded estates and in every test fixture, with no tenancy
 * context needed.
 *
 * EXISTING RECEIPTS ARE RENUMBERED INTO THE FORMAT, KEEPING THEIR NUMBER.
 * Receipt 4471 becomes `PHOENIXPARK-R-04471`: the integer a resident has on
 * paper survives, the sequence continues from where it was, and the journal
 * memos — append-only, and reading "receipt #4471" — still name it. Nothing
 * here touches the ledger.
 *
 * `payments.reference` is new: the cheque number or the bank's transfer
 * reference, as written on the paper. A manual payment is keyed from one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 40)->unique();

            /*
             * Where the sequence starts and where it has reached. The floor
             * exists because an estate carried over from an earlier numbering
             * — the seeded estates begin at 4001 — has no receipts below it,
             * and those are not gaps: nothing was ever allocated there.
             */
            $table->unsignedBigInteger('first_no')->default(1);
            $table->unsignedBigInteger('last_no')->default(0);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('reference', 60)->nullable()->after('method');
        });

        $prefix = $this->prefix();
        $first = null;
        $last = 0;

        $rows = DB::connection($this->getConnection())
            ->table('payments')
            ->select(['id', 'receipt_no'])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if (! ctype_digit((string) $row->receipt_no)) {
                continue;
            }

            $number = (int) $row->receipt_no;
            $first = $first === null ? $number : min($first, $number);
            $last = max($last, $number);

            DB::connection($this->getConnection())
                ->table('payments')
                ->where('id', $row->id)
                ->update(['receipt_no' => sprintf('%s-R-%05d', $prefix, $number)]);
        }

        DB::connection($this->getConnection())->table('receipt_sequences')->insert([
            'prefix' => $prefix,
            'first_no' => $first ?? 1,
            'last_no' => $last,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $prefix = $this->prefix();

        $rows = DB::connection($this->getConnection())
            ->table('payments')
            ->select(['id', 'receipt_no'])
            ->where('receipt_no', 'like', $prefix.'-R-%')
            ->get();

        foreach ($rows as $row) {
            DB::connection($this->getConnection())
                ->table('payments')
                ->where('id', $row->id)
                ->update(['receipt_no' => (string) (int) substr((string) $row->receipt_no, strlen($prefix) + 3)]);
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('reference');
        });

        Schema::dropIfExists('receipt_sequences');
    }

    /** The estate's subdomain, uppercased, from its own database name. */
    private function prefix(): string
    {
        $database = (string) DB::connection($this->getConnection())->getDatabaseName();

        return strtoupper((string) preg_replace('/^gs_estate_/', '', $database));
    }
};
