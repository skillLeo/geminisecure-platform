<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * NOTHING IS OPTIONAL (M18C). Every field a person fills in is required; where one genuinely may not apply, it is an
 * explicit choice the person makes, recorded, never a blank. These are the two choices the estate's own records hold:
 *
 *   residents.alerts_to_primary          "This person has no email — alerts go to me instead" (§3.2): a young child,
 *                                        an elderly relative. The primary resident is who their alerts reach.
 *   visitor_passes.no_vehicle            "Arriving on foot or by taxi — no vehicle" (§3.1): either a plate, or this.
 *   gate_approval_requests.no_vehicle    the same choice at the gate, for a walk-up the guard records.
 *
 * Every row already saved predates the rule and reads false: nothing about it is claimed. The chase list and the
 * app's "we need a phone number and an email" screen are what fill the blanks (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('residents', 'alerts_to_primary')) {
            Schema::table('residents', function (Blueprint $table): void {
                $table->boolean('alerts_to_primary')->default(false)->after('email');
            });
        }

        if (! Schema::hasColumn('visitor_passes', 'no_vehicle')) {
            Schema::table('visitor_passes', function (Blueprint $table): void {
                $table->boolean('no_vehicle')->default(false)->after('vehicle_plate');
            });
        }

        if (Schema::hasTable('gate_approval_requests') && ! Schema::hasColumn('gate_approval_requests', 'no_vehicle')) {
            Schema::table('gate_approval_requests', function (Blueprint $table): void {
                $table->boolean('no_vehicle')->default(false)->after('vehicle_plate');
            });
        }
    }

    public function down(): void
    {
        foreach (['residents' => 'alerts_to_primary', 'visitor_passes' => 'no_vehicle', 'gate_approval_requests' => 'no_vehicle'] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, static function (Blueprint $t) use ($column): void {
                    $t->dropColumn($column);
                });
            }
        }
    }
};
