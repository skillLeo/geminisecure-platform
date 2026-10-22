<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A household member the primary resident has removed (M4 §3.5).
 *
 * REMOVED, NOT DELETED. The person's row stays, because history points at it:
 * the passes they issued, the notices they read, the claim that put them on
 * the register. What changes is that they are no longer OF the household —
 * `Resident`'s global scope leaves a removed row out of every ordinary read
 * (the household's members, the gate's unit search, the office's register),
 * and `Resident::withRemoved()` is there for the rare screen that wants history.
 *
 * Two additive, nullable columns. Nothing existing is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            $table->timestamp('removed_at')->nullable()->after('status');
            $table->unsignedBigInteger('removed_by_resident_id')->nullable()->after('removed_at');
            $table->index('removed_at');
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            $table->dropIndex(['removed_at']);
            $table->dropColumn(['removed_at', 'removed_by_resident_id']);
        });
    }
};
