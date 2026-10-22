<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * THE HOUSEHOLD'S SIDE OF AN ADMISSION (M11 §2, DESIGN.md §13.3 (1), D-169).
 *
 * A single-use pass records its admission — it is used — so the resident's pass screen can say "Arrived". A
 * recurring pass and an approved walk-up recorded nothing on the household's side at all: the gate's log had the
 * entry, the household's phone had no way to know the visitor was in, and a live update would have had nothing
 * new to show. So the gate now writes the moment onto the pass and onto the approval, as it admits:
 *
 *   visitor_passes.last_admitted_at          the latest admission on this pass (every kind; a recurring pass is
 *                                            admitted again and again, and the latest is what the household asks)
 *   gate_approval_requests.admitted_at       when the approved walk-up was let in
 *
 * Both are UTC instants, read on the estate's clock. Neither changes who may come in: the verdict is the gate's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_passes', function (Blueprint $table): void {
            $table->timestamp('last_admitted_at')->nullable();
        });

        Schema::table('gate_approval_requests', function (Blueprint $table): void {
            $table->timestamp('admitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitor_passes', function (Blueprint $table): void {
            $table->dropColumn('last_admitted_at');
        });

        Schema::table('gate_approval_requests', function (Blueprint $table): void {
            $table->dropColumn('admitted_at');
        });
    }
};
