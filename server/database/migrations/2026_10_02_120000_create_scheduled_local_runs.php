<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EVERY ESTATE'S MORNING JOBS AT ITS OWN MORNING (M11 §4.2).
 *
 * The arrears ladder ran at 09:00 and the deposits at 06:30 — Kingston's, for every estate. An estate in New York
 * would be chased an hour early half the year, one in Trinidad an hour late, one in Europe in the middle of its
 * night. Now each job ticks every fifteen minutes and runs, for each estate, once its OWN clock has reached the
 * hour, once per its OWN calendar date. This table is that "once": one row per (job, estate, local date), claimed
 * by an insert the unique key lets only one worker win. A run that fails gives its claim back, so the next tick
 * that day tries again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_local_runs', function (Blueprint $table) {
            $table->string('job', 64);
            $table->string('tenant_id');
            $table->date('local_date');
            $table->dateTime('claimed_at');

            $table->unique(['job', 'tenant_id', 'local_date'], 'scheduled_local_runs_once');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_local_runs');
    }
};
