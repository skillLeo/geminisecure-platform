<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A GAP IN AN OFFICER'S POSITIONS IS TOLD ONCE (M22 §1.1).
 *
 * `tracking:watch` looks every minute for an officer on duty, sharing, whose phone has recorded nothing for
 * `tracking.gap_after_seconds`, and tells that phone — once per gap, which is what this table remembers: one row per
 * shift and the moment the gap began (the last position taken, or the clock-in). The gap itself is never stored: it is
 * read from the positions (`TrackingGaps`), so a phone that was only out of signal, and sends its queue later, closes it.
 *
 *   shift_id, guard_id, tenant_id   whose shift
 *   gap_since                       when positions stopped being taken (UTC)
 *   noticed_at                      when the watch found it
 *   handsets                        how many of the officer's phones the push was handed to (0: none registered)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_gap_notices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('guard_id');
            $table->string('tenant_id');
            $table->dateTime('gap_since');
            $table->dateTime('noticed_at');
            $table->unsignedSmallInteger('handsets')->nullable();
            $table->unique(['shift_id', 'gap_since']);
            $table->index('noticed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_gap_notices');
    }
};
