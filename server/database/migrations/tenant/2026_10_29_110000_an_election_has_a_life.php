<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AN ELECTION HAS A LIFE (the user, M56 §3, 8 October 2026): "I cannot stop one." What the acts need on the ballot:
 *
 *   cancelled_*   CANCEL, from any stage before the result is published: terminal, with a reason and a name. Every
 *                 ballot cast is KEPT (the votes are append-only by trigger and grant) and excluded from any tally.
 *   archived_*    ARCHIVE, once the result is published.
 *
 * Pause nominations, Reopen and Extend are stages and dates the table already holds; a formal challenge against a
 * published result is an entry in the audit log, which no screen can edit or remove (§3.1.5: "A dispute is recorded as
 * a challenge against it, not as a correction of it").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ballots', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancelled_by_name', 160)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archived_by_name', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ballots', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancelled_by_name', 'cancel_reason', 'archived_at', 'archived_by_name']);
        });
    }
};
