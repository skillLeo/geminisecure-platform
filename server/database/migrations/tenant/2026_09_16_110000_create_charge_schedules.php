<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The charge schedule — board 5's "Charge schedule" tab (12 §2, item 17).
 *
 * TWO TABLES, BECAUSE A SCHEDULE AND A RUN ARE DIFFERENT FACTS. A schedule is
 * the standing decision — "the maintenance fee is J$6,200 a unit, due on the
 * 1st" — and a run is one month of it actually posted. Keeping runs as their
 * own rows is what lets a month be previewed before it posts, refused if it has
 * already posted, and reversed as a whole if it was wrong.
 *
 * A RUN IS NEVER DELETED. Reversing one posts the mirror entry through the
 * ledger and records who reversed it, when and why, on the run itself — so the
 * month reads as "posted, then reversed", which is what happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_schedules', function (Blueprint $table) {
            $table->id();

            // "Maintenance fee" — printed on every charge the schedule raises,
            // with the month after it.
            $table->string('description', 120);

            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');

            // The income account every run credits. A schedule is the same
            // choice board 35 offers a single charge, made once.
            $table->string('account_code', 8)->default('4000');

            // estate | phase
            $table->string('scope', 16)->default('estate');
            $table->string('phase', 64)->nullable();

            // 1 to 28, so every month has the day.
            $table->unsignedTinyInteger('due_day')->default(1);

            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 160)->nullable();

            $table->timestamps();
        });

        Schema::create('charge_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('charge_schedule_id')->constrained('charge_schedules')->restrictOnDelete();

            // "2026-09" — the period the run bills.
            $table->string('period', 7);
            $table->date('due_on');

            $table->unsignedInteger('unit_count');
            $table->bigInteger('total_minor');
            $table->char('currency', 3)->default('JMD');

            // The one entry the run posted, and — once reversed — its mirror.
            $table->string('journal_ref', 40);
            $table->string('reversal_journal_ref', 40)->nullable();

            $table->unsignedBigInteger('posted_by')->nullable();
            $table->string('posted_by_name', 160)->nullable();
            $table->timestamp('posted_at');

            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->string('reversed_by_name', 160)->nullable();
            $table->string('reversal_reason', 300)->nullable();

            $table->timestamps();

            $table->index(['charge_schedule_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_runs');
        Schema::dropIfExists('charge_schedules');
    }
};
