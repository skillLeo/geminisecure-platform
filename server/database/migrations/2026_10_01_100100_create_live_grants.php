<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO WAS GRANTED WHICH LIVE CHANNEL, FOR THE RECONCILE BACKSTOP (M11 Part C, DESIGN.md §1.2, §7.4).
 *
 * A permission change evicts the sockets it affects at once (§7.1), but only where the code that
 * changed the permission remembered to say so. `live:reconcile` is the backstop for the site that
 * forgot: every five minutes it re-asks the channel rules for each (principal, channel) granted in
 * the last 26 hours, and evicts any principal who would now be refused. This is the list it asks
 * about. `App\Live\Auth\Grants::record` upserts a row per grant (f-web-auth).
 *
 * `principal` is the key signed into the subscription's `channel_data`: `u-12`, `g-5` or `r-9`
 * (§3.6), never a name. Bytes, not a case-folding collation, for the same reason as the log.
 *
 * Central only: authorisation is a central act for every door, and nothing here is estate data.
 * MUTABLE in GrantedTables, because an upsert updates and the reconcile prunes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_grants', function (Blueprint $table) {
            $table->string('principal', 24)->collation('utf8mb4_bin');
            $table->string('channel', 160)->collation('utf8mb4_bin');
            $table->string('socket_id', 32)->collation('utf8mb4_bin');
            $table->dateTime('granted_at', 6);

            $table->unique(['principal', 'channel', 'socket_id'], 'live_grants_unique');
            $table->index('principal', 'live_grants_principal');
            $table->index('granted_at', 'live_grants_granted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_grants');
    }
};
