<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What went wrong on a Gemini-staffed post, across every client.
 *
 * A security company's incident log is the record it is judged on: by an
 * insurer after a claim, by a client deciding whether to renew, and by the
 * PSRA. It is Gemini's own — the incidents are on posts it staffs, involving
 * guards it employs — which is why it lives centrally and is readable across
 * clients without opening a single estate database.
 *
 * OPEN IS A STATE WITH CONSEQUENCES. An incident stays open until somebody
 * records what was done about it, and the board draws that distinction in a
 * badge because an incident nobody closed is the one that gets asked about.
 * `resolution` is required to close and is why `closed_at` and it move
 * together.
 *
 * NOT APPEND-ONLY, and deliberately so — unlike a journal or an invoice. An
 * incident is a live case: severity is reassessed as more is known, and a
 * resolution is written days after the event. What must not be lost is the
 * fact that it happened, so there is no delete route and the audit log records
 * every change.
 *
 * Duress alerts are NOT incidents and are not stored here. They are a
 * life-safety path with their own table, their own broadcast channel and their
 * own response screen; folding them in would put a panic button behind a
 * case-management workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            /*
             * Nullable, and the name kept beside it. A guard can leave the
             * company; the record that they were on post when this happened
             * cannot leave with them.
             */
            $table->foreignId('guard_id')->nullable()->constrained('guards')->nullOnDelete();
            $table->string('guard_name', 160)->nullable();

            // "Attempted unauthorized access", "Equipment fault — barrier arm
            // sensor". Free text, because the board's own examples are.
            $table->string('kind', 160);

            $table->text('detail')->nullable();

            // low | med | high — the board's three badges.
            $table->string('severity', 16)->default('low');

            // open | resolved
            $table->string('status', 16)->default('open');

            $table->timestamp('occurred_at');

            /*
             * A resolution is what CLOSES an incident, so the two are written
             * together. An incident marked resolved with no account of what
             * was done is the same as an open one to anybody reading it later.
             */
            $table->text('resolution')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('logged_by_name', 160)->nullable();

            $table->timestamps();

            $table->index(['occurred_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incidents');
    }
};
