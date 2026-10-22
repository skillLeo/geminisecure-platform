<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a forfeited deposit the estate actually kept (M4 §4, defect 2).
 *
 * A DEPOSIT IS A LIABILITY HELD, AND IT HAS TO CLEAR. Until now it could only be
 * kept whole or returned whole, and only by somebody remembering to press a
 * button — so a deposit on a booking long over, or cancelled, sat on 2200
 * Resident Deposits Held for good. `Amenities` now moves every held deposit on:
 * released when the booking is cancelled inside its terms or finishes with no
 * claim, or forfeited IN WHOLE OR IN PART by staff, with a reason.
 *
 * A part-forfeit is one balanced entry — Dr 2200 for the whole deposit, Cr 4100
 * for what the estate keeps, Cr 1000 for what goes back — and the state is
 * `forfeited`. This column is the figure that state alone cannot say: NULL until
 * a deposit is forfeited, then the amount kept, in minor units. The deposit
 * itself (`deposit_minor`) is the term the household agreed to and never moves.
 *
 * ADDITIVE. One nullable column; nothing is rewritten and nothing is dropped
 * but the column itself on the way down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenity_bookings', function (Blueprint $table): void {
            $table->bigInteger('deposit_forfeited_minor')->nullable()->after('deposit_forfeit_reason');
        });
    }

    public function down(): void
    {
        Schema::table('amenity_bookings', function (Blueprint $table): void {
            $table->dropColumn('deposit_forfeited_minor');
        });
    }
};
