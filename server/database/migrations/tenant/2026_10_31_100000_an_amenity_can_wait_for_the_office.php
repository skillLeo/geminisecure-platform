<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A BOOKING CAN WAIT FOR THE OFFICE, AMENITY BY AMENITY (M53 §2, Hassam's Sunday order, 9 October 2026).
 *
 * Every booking made in the app was confirmed at once when paid for, so nothing ever waited on the console's own
 * Approve and Decline, and the QA run found no booking it could approve. `requires_approval` is the switch, on by
 * default for every amenity, existing ones included: a booking of it is a request, its fee is charged when the office
 * approves it, and a request still undecided when its time comes expires (`Amenities::settle()`). Off, an amenity keeps
 * the old flow: paid for in the app, confirmed at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(true)->after('is_bookable');
        });
    }

    public function down(): void
    {
        Schema::table('amenities', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });
    }
};
