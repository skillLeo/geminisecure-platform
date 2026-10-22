<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `charge_schedule_rate_changes` — a dues rate the estate can change (M10 §3).
 *
 * THE CLIENT'S RULE: the Community Super Admin edits the estate's dues rates, "with the same
 * rule that issued invoices are not retroactively rewritten". Until now a schedule's amount was
 * fixed the moment it was defined and the only other verb was stopping it, so a rise meant
 * stopping the schedule and defining a second one — two schedules, one charge, and a register
 * that no longer said what the maintenance fee had been.
 *
 * FROM A MONTH, NOT FROM A DATE, and that is the one way this table differs from its central
 * twin `plan_price_changes`. A platform invoice is raised on a day; an estate's dues are billed
 * for a PERIOD, one run per month, and a run may be posted for next month before next month has
 * begun. A change stored as "from the 14th" would leave "does it reach the month already in
 * preview?" to be answered by whichever comparison happened to be written, so the question is
 * not asked: the change names the first month it bills, as `charge_runs.period` names the month
 * a run billed, in the same 'YYYY-MM' shape.
 *
 * NOTHING ALREADY POSTED IS TOUCHED, and nothing here could touch it. A posted month is its own
 * snapshot: every unit's `charges.amount_minor`, the run's `total_minor` and the journal lines
 * behind them were written at the rate in force when it posted, and none of them is read back
 * through the schedule. What was missing was never storage on the charge; it was a month on the
 * amendment, so that the preview and the post of an UNPOSTED month can both ask one resolver
 * what that month bills.
 *
 * `charge_schedules.amount_minor` STAYS AS WHAT IS IN FORCE THIS MONTH, for the reason
 * `plan_price_changes` gives for `plans.price_per_unit_minor`: it is the figure a reader of the
 * schedule expects to find there. `applied_at` records the moment a change became that figure.
 *
 * ONE CHANGE PER SCHEDULE PER MONTH. The unique key is what stops two rates claiming the same
 * month; saving a second change for a month not yet billed corrects the first rather than
 * standing beside it, and the audit log keeps both.
 *
 * RESTRICT, NOT CASCADE, on the schedule — the same choice `charge_runs` makes. A schedule is
 * stopped, never deleted, and the history of what its rate was is not something a delete should
 * be able to take with it.
 *
 * MUTABLE, NOT APPEND-ONLY (App\Support\GrantedTables::ESTATE_MUTABLE): `applied_at` is stamped
 * after the insert, and a correction for a month not yet billed updates its row. The record that
 * can never change is the audit entry each save writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_schedule_rate_changes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('charge_schedule_id')->constrained('charge_schedules')->restrictOnDelete();

            // Both ends, so a reader a year later does not have to reconstruct the old rate from
            // whatever the row before this one happens to say.
            $table->bigInteger('from_minor');
            $table->bigInteger('to_minor');
            $table->char('currency', 3)->default('JMD');

            // "2026-11" — the first month billed at `to_minor`.
            $table->string('effective_period', 7);

            // WHY. Every household billed from the schedule sees the new amount on its statement,
            // and an unexplained change is the entry an auditor stops at.
            $table->string('reason', 300);

            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('changed_by_name', 160)->nullable();

            // Null until the calendar reaches `effective_period`. Stamped when `to_minor` becomes
            // the schedule's rate in force.
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            // NAMED BY HAND: the name Laravel would derive runs to 72 characters, and MySQL
            // refuses an identifier over 64.
            $table->unique(['charge_schedule_id', 'effective_period'], 'rate_changes_one_per_schedule_month');
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_schedule_rate_changes');
    }
};
