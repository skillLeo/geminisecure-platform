<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A flag is lifted the way it was raised: with a reason and a minute (13 A4).
 *
 * "Require reason AND minute reference on both raise and lift." A committee
 * agreed to stop chasing a household, so a committee agrees to start again —
 * a lift with no minute is an office decision to resume final demands on a
 * household the committee protected.
 *
 * Nullable in the schema only because flags lifted before this migration were
 * lifted without one, and none is invented for them. The service refuses a
 * lift without it from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_collection_flags', function (Blueprint $table) {
            $table->string('lifted_minute_reference', 80)->nullable()->after('lifted_reason');
        });
    }

    public function down(): void
    {
        Schema::table('unit_collection_flags', function (Blueprint $table) {
            $table->dropColumn('lifted_minute_reference');
        });
    }
};
