<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DRILL MODE FOR ONE RESIDENT APP ACCOUNT, FOR A SET TIME (M53 §3A.1, M54 §2.3).
 *
 * "Only the server may raise a drill, switched on for an account for a set time from the office. A phone that could
 * mark its own panic a drill could hide a real one." So the switch is on the account, set by `panic:drill-mode` and
 * nothing a handset sends: while `drill_mode_until` is ahead, that account's Panic is a DRILL that wakes nobody.
 *
 * `duress_alerts.drill_mode` says, on the alert itself, that it was raised that way: a DRILL (`is_drill`, so every
 * screen says DRILL and no figure counts it) that rang no officer, mailed and texted nobody and sounded no siren.
 * Additive: nullable columns and a false default, so every alert before this reads as it always did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('resident_accounts', 'drill_mode_until')) {
                $table->timestamp('drill_mode_until')->nullable();
            }
            if (! Schema::hasColumn('resident_accounts', 'drill_mode_reason')) {
                $table->string('drill_mode_reason', 160)->nullable();
            }
            if (! Schema::hasColumn('resident_accounts', 'drill_mode_by')) {
                $table->string('drill_mode_by', 120)->nullable();
            }
        });

        Schema::table('duress_alerts', function (Blueprint $table): void {
            if (! Schema::hasColumn('duress_alerts', 'drill_mode')) {
                $table->boolean('drill_mode')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('duress_alerts', function (Blueprint $table): void {
            if (Schema::hasColumn('duress_alerts', 'drill_mode')) {
                $table->dropColumn('drill_mode');
            }
        });

        Schema::table('resident_accounts', function (Blueprint $table): void {
            foreach (['drill_mode_until', 'drill_mode_reason', 'drill_mode_by'] as $column) {
                if (Schema::hasColumn('resident_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
