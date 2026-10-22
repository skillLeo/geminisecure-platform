<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH ROWS ARE SAMPLE DATA, SO THEY CAN BE TAKEN OUT AGAIN (M8 §1 rule 2, §4.4).
 *
 * Phoenix Park Village 1 is a REAL estate that is about to be filled with invented history so the
 * committee can be shown the product working. Real residents must never inherit an invented arrears
 * balance, somebody else's visitor at their gate, or a vote they did not cast — so every seeded row
 * has to be removable, exactly, and without taking the estate's structure and configuration with it.
 *
 * HOW: A RANGE, NOT A FLAG ON EVERY TABLE. Tagging would mean a column on forty-odd tables, a
 * migration for each, and a flag that a later insert could forget to set. Instead the seeder records
 * the highest id in every table BEFORE it runs and again AFTER, and stores the interval. Anything in
 * that interval was written by that seeding run and by nothing else, because ids only go up.
 *
 * It is exact while the estate is not yet in real use — which is precisely when a demonstration seed
 * is run — and `phoenix:demo-purge` says so plainly rather than assuming it: it prints what it will
 * delete and refuses to guess at rows outside its own ranges.
 *
 * WHAT IT CANNOT UNDO, and the purge names them: the append-only tables. The gate log, the ledger
 * journals, the ballot receipts and marks, the audit log and the SMS ledger refuse DELETE to
 * everyone including the schema owner — that refusal is the point of them (D-M148). Their rows carry
 * `is_simulated` where the column exists, so the consoles badge them as simulated for ever after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_data_ranges', function (Blueprint $table): void {
            $table->id();

            /** Which seeding run this belongs to — `phoenix-demo` today, another estate's tomorrow. */
            $table->string('tag', 40);

            /** The estate whose data this is. Null for rows that are not an estate's (a platform user). */
            $table->string('tenant_id', 64)->nullable();

            /** `mysql` (the platform database) or `tenant` (the estate's own). */
            $table->string('connection', 16);
            $table->string('table_name', 64);

            /** Ids strictly ABOVE `from_id` and up to `to_id` were written by this run. */
            $table->unsignedBigInteger('from_id');
            $table->unsignedBigInteger('to_id');

            /** True where the table refuses DELETE to everyone — recorded so the purge can say so. */
            $table->boolean('append_only')->default(false);

            $table->timestamp('seeded_at')->useCurrent();

            $table->index(['tag', 'connection']);
            $table->index(['tenant_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_data_ranges');
    }
};
