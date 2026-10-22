<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A HANDSET SAYS WHETHER IT CAN RING LIKE A CALL (the guard ring, spec item 3).
 *
 * The Guard App reads its own readiness — notifications on, full-screen alerts allowed,
 * Do Not Disturb bypass, battery optimisation, alarm volume — and reports it with every
 * `push.register`. The SERVER decides what it adds up to (`RingReadiness::state()`), and
 * the dispatcher's screen counts "call-style ready" and "partly ready" from it, apart.
 *
 *   ring_version       the app's ring contract, or NULL: an older app, iOS, or a report
 *                      that could not be read. NULL is the safe direction — that phone
 *                      is sent the ordinary visible alert every round.
 *   ring_readiness     the report as parsed, unknown keys dropped
 *   ring_state         ready | partial | not_ready
 *   ring_checked_at    when the phone last reported it; every claim on the console says so
 *   ring_last_shown_at the phone's own receipt that it put a ring on screen
 *   ring_proven_at     a TEST ring the phone said it showed — the chain proven end to end
 *
 * The table is already mutable (GrantedTables::CENTRAL_MUTABLE). Nullable columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->unsignedSmallInteger('ring_version')->nullable();
            $table->json('ring_readiness')->nullable();
            $table->string('ring_state', 12)->nullable();
            $table->timestamp('ring_checked_at')->nullable();
            $table->timestamp('ring_last_shown_at')->nullable();
            $table->timestamp('ring_proven_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_push_tokens', function (Blueprint $table) {
            $table->dropColumn(['ring_version', 'ring_readiness', 'ring_state', 'ring_checked_at', 'ring_last_shown_at', 'ring_proven_at']);
        });
    }
};
