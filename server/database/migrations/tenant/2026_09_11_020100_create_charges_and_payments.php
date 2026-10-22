<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a unit was billed and what it paid — the receivable sub-ledger's own
 * records, sitting beside the journal entries they raise.
 *
 * WHY THESE EXIST AT ALL, when the journal already holds every amount. A journal
 * line says "J$6,200 debited to Dues Receivable for Lot 47". A charge says
 * "September maintenance, due on the 1st, still outstanding, and here is its
 * reference". The ledger records the accounting; these record the BILLING — the
 * due date an ageing bucket is computed from, the receipt number a resident
 * quotes on the phone, the method a payment arrived by. None of that is
 * bookkeeping and none of it belongs on a journal line.
 *
 * EVERY ONE OF THEM RAISES AN ENTRY, and the entry is the money. `journal_ref`
 * points at it. The amount is stored on both — deliberately, because they are
 * not the same fact: the charge's amount is what was billed, and the entry's is
 * what was posted. `gate:ledger` proves they agree, and the day they do not is
 * the day somebody wrote around the ledger.
 *
 * KEYED ON THE UNIT. Dues attach to the property; see the migration that moved
 * the sub-ledger onto `unit_id` for the whole argument.
 *
 * A PAYMENT CARRIES TWO TIMESTAMPS, and the Build Spec says why: "Cash carries
 * TWO timestamps because received and entered are rarely the same." A guard
 * takes cash at the gate on Friday night and the treasurer records it on Monday.
 * The resident's receipt is dated Friday and the bank sees it Monday, and an
 * arrears report that used one for the other would show them in default over a
 * weekend they had already paid for.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * `charges` already exists, keyed on the household and carrying nothing
         * but a description and an amount. It is brought up to the Build Spec's
         * `charge` entity here rather than replaced, so the rows already posted
         * keep their references.
         */
        Schema::table('charges', function (Blueprint $table) {
            $table->unsignedBigInteger('unit_id')->nullable()->after('id');
            $table->index('unit_id');

            // dues | special_assessment | fine | amenity — the Build Spec's four.
            $table->string('type', 24)->default('dues')->after('unit_id');

            /*
             * The period a recurring charge covers, as "2026-09". Not derivable
             * from the due date: September's dues can be raised in August and
             * fall due in October, and a resident querying "which month is this
             * for" is asking about the period, not either date.
             */
            $table->string('period', 16)->nullable()->after('type');

            /*
             * The income account this charge credits. Board 35 draws it as a
             * field the treasurer picks — "4000 — Maintenance Fee Income" — so
             * it is a choice on the charge, not a constant in the code. A fine
             * and an amenity fee credit different accounts.
             */
            $table->foreignId('account_id')->nullable()->after('currency')->constrained('accounts')->restrictOnDelete();

            // The entry this charge raised. Nullable only for the moment between
            // the two writes inside one transaction.
            $table->string('journal_ref', 40)->nullable()->after('status');
            $table->index('journal_ref');

            $table->unsignedBigInteger('posted_by')->nullable()->after('journal_ref');
            $table->string('posted_by_name', 160)->nullable()->after('posted_by');
        });

        DB::statement('
            UPDATE charges c
              JOIN households h ON h.id = c.household_id
               SET c.unit_id = h.unit_id
             WHERE c.household_id IS NOT NULL
        ');

        Schema::table('charges', function (Blueprint $table) {
            $table->dropForeign(['household_id']);
            $table->dropColumn('household_id');

            $table->foreign('unit_id')->references('id')->on('units')->restrictOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            /*
             * The number on the paper the resident holds. Unique, because a
             * receipt number that appears twice is two people holding proof of
             * the same payment.
             */
            $table->string('receipt_no', 32)->unique();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');

            // card | bank | cash | cheque
            $table->string('method', 16);

            /*
             * Received, then entered. Two facts, and the gap between them is
             * itself evidence: a cash payment taken on Friday and keyed on
             * Monday is late paperwork, not a late payment, and only the pair
             * can tell the difference.
             */
            $table->timestamp('received_at');
            $table->timestamp('entered_at')->useCurrent();

            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('received_by_name', 160)->nullable();

            /*
             * What the card processor said. The reference is theirs and the fee
             * is real money the estate never sees — booked as an expense against
             * the gross, so the ledger shows what the resident paid rather than
             * what landed.
             */
            $table->string('gateway_ref', 120)->nullable();
            $table->bigInteger('gateway_fee_minor')->nullable();

            // recorded | reversed. A bounced cheque is a reversal, never a
            // deletion: the resident was given a receipt and the record of it
            // has to survive being wrong.
            $table->string('status', 16)->default('recorded');

            $table->string('journal_ref', 40)->nullable();

            $table->timestamps();

            $table->index(['unit_id', 'received_at']);
            $table->index('journal_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('charges', function (Blueprint $table) {
            $table->unsignedBigInteger('household_id')->nullable()->after('id');
        });

        DB::statement('
            UPDATE charges c
              JOIN households h ON h.unit_id = c.unit_id
               SET c.household_id = h.id
             WHERE c.unit_id IS NOT NULL
        ');

        Schema::table('charges', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['unit_id']);
            $table->dropIndex(['unit_id']);
            $table->dropIndex(['journal_ref']);
            $table->dropColumn(['unit_id', 'type', 'period', 'account_id', 'journal_ref', 'posted_by', 'posted_by_name']);

            $table->foreign('household_id')->references('id')->on('households')->cascadeOnDelete();
        });
    }
};
