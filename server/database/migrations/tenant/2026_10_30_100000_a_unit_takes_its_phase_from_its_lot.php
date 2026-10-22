<?php

declare(strict_types=1);

use App\Services\Estate\PhasesFromLots;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A PHASE IS A RUN OF LOT NUMBERS, AND A UNIT'S PHASE IS WORKED OUT FROM ITS LOT (M57.5 §4.2, §4.4).
 *
 * Phoenix Park's units were filed under phases by the demonstration seed, five even phases of 400, and the real estate
 * is 356, 242, 620, 359 and 422 (Gore's site plan, 10 April 2018; docs/estate/PHOENIX_BLOCKS.md). Four screens showed
 * "Phase 2 · Lot 47" for a lot in Phase 1, because `units.block` is a stored copy of the phase and nothing ties it to the
 * lot. This migration lays the ground; `estate:structure-from-plan` makes the change on an estate, after its dry run:
 *
 *   estate_phases.first_lot, last_lot   the phase's run of lots, from the site plan. Null on an estate whose phases
 *                                       are not runs of lot numbers (its units keep their stored phase).
 *   estate_phases.blocks                the blocks the plan draws in the phase: letter, units, and the lots in each.
 *                                       A block is a phase AND a letter: letters repeat from phase to phase.
 *   units.lot_number                    the number in "Lot 47", computed by MySQL from the reference and never written
 *                                       (null for a reference that is not "Lot" and a whole number, such as "Lot 1999A").
 *   units.house_type                    one of the plan's three detached two-bedroom homes, or null until recorded:
 *                                       the plan colours lots by block, not by type, so nothing here fills it.
 *
 * The phase itself, `units.block`, is turned into a column MySQL computes from `lot_number` and the estate's ranges by
 * `PhasesFromLots` (as the schema owner), estate by estate, because the ranges are each estate's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_phases', function (Blueprint $table) {
            $table->unsignedInteger('first_lot')->nullable()->after('sequence');
            $table->unsignedInteger('last_lot')->nullable()->after('first_lot');
            $table->json('blocks')->nullable()->after('block_count');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->unsignedInteger('lot_number')
                ->virtualAs("case when `reference` regexp '^Lot [0-9]{1,9}$' then cast(substr(`reference`, 5) as unsigned) end")
                ->nullable()
                ->after('reference');
            $table->string('house_type', 32)->nullable()->after('type');
            $table->index('lot_number', 'units_lot_number_index');
        });
    }

    public function down(): void
    {
        // A phase computed from `lot_number` depends on it: the phase goes back to a stored column first, holding what
        // it computed, so nobody's phase changes on the way down.
        if (PhasesFromLots::derived('tenant')) {
            $tenant = DB::connection('tenant');
            $values = $tenant->table('units')->pluck('block', 'id')->all();
            $tenant->statement(PhasesFromLots::storedSql($tenant));

            foreach ($values as $id => $block) {
                $tenant->table('units')->where('id', $id)->update(['block' => $block]);
            }
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_lot_number_index');
            $table->dropColumn(['lot_number', 'house_type']);
        });

        Schema::table('estate_phases', function (Blueprint $table) {
            $table->dropColumn(['first_lot', 'last_lot', 'blocks']);
        });
    }
};
