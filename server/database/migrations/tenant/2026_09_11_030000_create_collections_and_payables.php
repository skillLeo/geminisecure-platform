<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collections and payables — boards 7, 8, 26, 27 and 28.
 *
 * Two halves of the same idea: what the estate is owed and cannot simply
 * demand, and what the estate owes and must actually pay.
 *
 * COLLECTIONS
 *
 *   payment_plans        the Build Spec's `payment_plan`. A household in
 *   payment_plan_instalments  arrears agrees a schedule, and while they are
 *                        meeting it the arrears restriction is LIFTED — that is
 *                        the whole point of the screen, and it means a plan is
 *                        a live thing a gate decision depends on, not a note.
 *                        Missing an instalment reinstates the restriction.
 *
 *   dunning_templates    "Every dunning notice is logged with its exact sent
 *   dunning_notices      content, so a dispute can be settled from the record."
 *                        The template can be edited; the notice keeps the body
 *                        AS SENT. A log that re-rendered from the current
 *                        template would show the resident something they were
 *                        never sent, which is the one thing it exists to
 *                        prevent.
 *
 * PAYABLES
 *
 *   vendors              the supplier register. `trn` is required before a bill
 *                        can be PAID, not before it can be recorded — a bill
 *                        arrives whether or not the paperwork is in order, and
 *                        refusing to record it would leave the estate's
 *                        liabilities understated.
 *
 *   bills                what the estate owes a supplier. Every one raises a
 *   bill_payments        journal on approval: Dr the expense account, Cr 2000
 *                        Accounts Payable. Paying it posts Dr 2000, Cr the
 *                        bank. The vendor is named on every 2000 line so the
 *                        payables sub-ledger ties to its control account, the
 *                        same way units tie receivables.
 *
 *   bank_statement_lines  board 28 matches the ledger against the statement and
 *   bank_reconciliations  "cannot be completed while a difference remains".
 *                        A statement line is what the BANK said; a match is an
 *                        assertion that one of ours is the same event. Storing
 *                        the match rather than mutating either side is what
 *                        lets a reconciliation be unpicked without touching a
 *                        posted entry.
 *
 * NOTHING HERE STORES A BALANCE. Every total on all five screens is summed from
 * posted journal lines, and `EstateArrearsTest` proves it by re-summing in raw
 * SQL. These tables carry what bookkeeping has no place for — a due date, a
 * TRN, a delivery state, a statement reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ------------------------------------------------------ collections */

        Schema::create('payment_plans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('reference', 32)->unique();

            // What the plan covers. Snapshotted at agreement, because the unit's
            // balance moves on and the agreement does not.
            $table->bigInteger('total_minor');
            $table->char('currency', 3)->default('JMD');

            $table->unsignedSmallInteger('instalments');
            $table->date('starts_on');

            // draft | active | completed | defaulted | cancelled
            $table->string('status', 16)->default('draft');

            /*
             * The resident's agreement, recorded as a fact with a time on it.
             * A plan the household never agreed to is a demand, and lifting a
             * restriction on the strength of one would be the estate deciding
             * on their behalf.
             */
            $table->timestamp('agreed_at')->nullable();
            $table->string('agreed_by_name', 160)->nullable();

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 160)->nullable();
            $table->text('terms')->nullable();

            $table->timestamps();

            $table->index(['unit_id', 'status']);
        });

        Schema::create('payment_plan_instalments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_plan_id')->constrained('payment_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');
            $table->date('due_on');

            // due | met | missed. DERIVED on read from the unit's payments
            // would be cheaper, but a plan's instalment is met by a decision as
            // well as by an amount — a treasurer can accept a short payment as
            // meeting one — so the state is recorded.
            $table->string('status', 16)->default('due');
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->unique(['payment_plan_id', 'sequence']);
        });

        Schema::create('dunning_templates', function (Blueprint $table) {
            $table->id();

            $table->string('key', 32)->unique();
            $table->string('label', 80);

            /*
             * Which escalation this is. A first reminder and a final demand
             * differ in law as well as in tone, and the stage is what an
             * arrears process is audited against.
             */
            $table->unsignedSmallInteger('stage');

            // email | sms | push | letter
            $table->string('channel', 16)->default('email');

            $table->string('subject', 190);
            $table->text('body');

            // How many days past due this stage fires at.
            $table->unsignedSmallInteger('days_overdue')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        Schema::create('dunning_notices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('dunning_template_id')->nullable()->constrained('dunning_templates')->nullOnDelete();

            // Denormalised beside the id, and not for convenience: a template
            // can be edited or archived, and the log has to keep saying which
            // stage was sent.
            $table->string('template_label', 80);
            $table->unsignedSmallInteger('stage');
            $table->string('channel', 16);

            /*
             * THE BODY AS SENT, not a reference to one that can change. The
             * Build Spec: "logged with its exact sent content, so a dispute can
             * be settled from the record". A log that re-rendered from today's
             * template would show a resident something they were never sent.
             */
            $table->string('subject', 190);
            $table->text('body');

            // queued | sent | delivered | failed | bounced
            $table->string('delivery_state', 16)->default('queued');
            $table->string('delivery_detail', 190)->nullable();

            $table->timestamp('sent_at');
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->string('sent_by_name', 160)->nullable();

            $table->timestamps();

            $table->index(['unit_id', 'sent_at']);
        });

        /* -------------------------------------------------------- payables */

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();

            $table->string('name', 160);
            $table->string('category', 64)->nullable();

            /*
             * The taxpayer registration number. Nullable, and required only at
             * PAYMENT: a bill arrives whether or not the paperwork is in order,
             * and refusing to record it would understate what the estate owes.
             * Withholding compliance bites when money moves.
             */
            $table->string('trn', 24)->nullable();

            $table->string('contact_name', 160)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 190)->nullable();

            // active | inactive. Deactivated, never deleted — a vendor with
            // bills against them is history the estate has to keep.
            $table->string('status', 16)->default('active');

            $table->timestamps();

            $table->index('status');
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('description', 200);

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');
            $table->date('due_on');

            // Which expense account this bill hits. A choice, like a charge's
            // income account, because a plumber and an electricity bill are
            // different costs.
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            /*
             * The work order this bill is for, where there is one. Board 27
             * prints "Ticket #1042 — Gate lighting" against three of its five
             * bills, and that trace — ticket to bill to payment to bank line —
             * is what lets a committee ask what a job actually cost.
             *
             * A plain integer rather than a foreign key: maintenance tickets are
             * board 17 and do not exist yet, and a key to a table that is not
             * there is a migration that cannot run.
             */
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->string('ticket_label', 120)->nullable();

            // draft | approved | paid | void
            $table->string('status', 16)->default('draft');

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 160)->nullable();
            $table->timestamp('approved_at')->nullable();

            // The entry raised on approval: Dr expense, Cr 2000.
            $table->string('journal_ref', 40)->nullable();

            $table->timestamps();

            $table->index(['status', 'due_on']);
            $table->index('vendor_id');
        });

        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bill_id')->constrained('bills')->restrictOnDelete();

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');

            // cheque | bank | card | cash
            $table->string('method', 16);
            $table->string('reference', 64)->nullable();

            $table->date('paid_on');
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->string('paid_by_name', 160)->nullable();

            /*
             * Why a payment exceeded the bill, where one did. The Build Spec:
             * "A payment cannot exceed the bill without an explicit over-payment
             * reason." Nullable, and the service refuses the payment without it.
             */
            $table->string('overpayment_reason', 190)->nullable();

            // The entry raised: Dr 2000, Cr the bank.
            $table->string('journal_ref', 40)->nullable();

            $table->timestamps();

            $table->index('bill_id');
        });

        /* -------------------------------------------------- reconciliation */

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('statement_date');

            $table->bigInteger('opening_minor');
            $table->bigInteger('closing_minor');
            $table->char('currency', 3)->default('JMD');

            // open | completed. A reconciliation cannot be completed while a
            // difference remains, and the service is what refuses it.
            $table->string('status', 16)->default('open');

            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('reconciled_by')->nullable();
            $table->string('reconciled_by_name', 160)->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'statement_date']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bank_reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();

            $table->date('value_date');
            $table->string('description', 200);

            /*
             * Signed, and deliberately unlike a journal line. This is what the
             * BANK said, transcribed: a statement shows money in and money out
             * on one column with a sign, and re-expressing it as debits and
             * credits would be interpreting it before it has been matched.
             */
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');

            $table->string('bank_reference', 64)->nullable();

            /*
             * The ledger entry this line was matched to, if any.
             *
             * THE MATCH IS STORED HERE, on the statement side, and nothing on
             * the ledger side is touched. A posted entry is immutable, so a
             * reconciliation that wrote to it could not exist — and unmatching
             * has to be possible, because a treasurer will match the wrong pair.
             */
            $table->string('matched_entry_ref', 40)->nullable();
            $table->timestamp('matched_at')->nullable();

            $table->timestamps();

            $table->index(['bank_reconciliation_id', 'value_date']);
            $table->index('matched_entry_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bill_payments');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('dunning_notices');
        Schema::dropIfExists('dunning_templates');
        Schema::dropIfExists('payment_plan_instalments');
        Schema::dropIfExists('payment_plans');
    }
};
