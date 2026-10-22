<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a push to a CLOSED app is sent (M4 §3.2).
 *
 * CENTRAL, NOT PER ESTATE. Both mobile principals — a guard's handset (`guards`)
 * and a Resident App account (`resident_accounts`) — and the Sanctum tokens they
 * speak with live in the central database, so the queued sender finds a phone
 * without opening an estate; and an Expo token is unique across the PLATFORM: a
 * handset handed from one estate's guard to another's must stop receiving the
 * first guard's alerts, which a per-estate table could not see.
 *
 * device_push_tokens   one row per Expo push token. `tenant_id` + `principal_type`
 *                      + `principal_id` say whose phone it is NOW; registering the
 *                      same token again moves it. `access_token_id` is the API
 *                      token (`personal_access_tokens.id`) the phone registered
 *                      WITH — the sign-in this push address belongs to. When that
 *                      API token is gone (sign-out, a revoked or re-bound handset,
 *                      a household member removed), the push address is dead too,
 *                      whichever code path deleted it: the sender checks.
 *                      `muted` is THIS PHONE's switched-off categories — the
 *                      phones keep their notification switches per phone, and the
 *                      server has to respect them for a push the phone never sees
 *                      arrive. `revoked_at` is set, never deleted: sign-out, a
 *                      revoked handset, or Expo answering DeviceNotRegistered.
 *
 * push_coalescing      the last time one (recipient, type, record) was pushed, so
 *                      six changes to one maintenance ticket inside a few minutes
 *                      are one push. One row per key, updated in place — it cannot
 *                      grow past the number of records. It holds no title, no body
 *                      and no token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('principal_type', 16);              // guard | resident
            $table->unsignedBigInteger('principal_id');
            $table->string('app', 16);                         // guard | resident
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->string('expo_token', 190)->unique();
            $table->string('platform', 16);                    // ios | android
            $table->json('muted')->nullable();                 // categories switched off on this phone
            $table->timestamp('last_registered_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable();  // signed_out | access_revoked | replaced | moved | device_not_registered
            $table->timestamps();

            $table->index(['tenant_id', 'principal_type', 'principal_id', 'revoked_at'], 'device_push_tokens_owner_index');
            $table->index('access_token_id', 'device_push_tokens_access_token_index');
        });

        Schema::create('push_coalescing', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('principal_type', 16);
            $table->unsignedBigInteger('principal_id');
            $table->string('type', 40);
            $table->string('record', 64)->default('');
            $table->timestamp('last_pushed_at');
            $table->unsignedInteger('suppressed')->default(0);

            $table->unique(['tenant_id', 'principal_type', 'principal_id', 'type', 'record'], 'push_coalescing_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_coalescing');
        Schema::dropIfExists('device_push_tokens');
    }
};
