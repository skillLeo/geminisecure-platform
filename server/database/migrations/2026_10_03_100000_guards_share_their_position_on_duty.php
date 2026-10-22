<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WHERE A GUARD IS WHILE ON DUTY (M11 §3; D-174).
 *
 * Until now the platform held no guard position at all: a presence ping compared its coordinates with the post's
 * geofence and threw them away. The client now wants the control room to see guards on a live map, also while
 * the app is closed. So a position is KEPT — but only one taken between this officer's clock-in and clock-out,
 * only with their consent to the current policy, and only for `config('tracking.retention_days')` (90 by
 * default): `gs:guard-positions-purge` deletes the rest every night.
 *
 * OWNED BY THE SECURITY COMPANY, so central, beside the shifts they belong to. `tenant_id` is the estate the
 * shift was at — the estate console never reads this table unless its contract allows it
 * (`subscriptions.estate_sees_guard_positions`, default off; QUESTIONS TRACK-01).
 *
 *   captured_at          when the phone took the fix, on the SERVER's clock: the phone's own time corrected by the
 *                        offset between its clock and the server's on the request that brought it
 *   device_captured_at   the phone's own time, as sent — kept so a correction can always be checked
 *   received_at          when it arrived. A fix queued offline for forty minutes is forty minutes old, and the map
 *                        says so from `captured_at`, never from this
 *   client_id            the phone's own id for the fix: a resent batch stores nothing twice
 *   source               interval · movement · clock_in · checkpoint · gate · duress · clock_out
 *
 * `guard_tracking_consents` is the officer's answer to each version of the policy, append-only: the latest row
 * for the current version is the answer. A refusal is recorded like a consent — it is an HR matter, and the
 * control room says "position not shared" for that officer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guard_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->string('client_id', 64)->unique();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->string('source', 16);
            $table->unsignedTinyInteger('battery_pct')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('device_captured_at')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->boolean('is_simulated')->default(false);

            $table->index(['guard_id', 'captured_at']);
            $table->index(['shift_id', 'captured_at']);
            $table->index(['tenant_id', 'captured_at']);
            $table->index('captured_at');
        });

        Schema::create('guard_tracking_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();
            $table->string('policy_version', 20);
            // granted | refused
            $table->string('decision', 8);
            $table->timestamp('decided_at')->useCurrent();
            $table->timestamp('device_time')->nullable();

            $table->index(['guard_id', 'policy_version', 'id']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->boolean('estate_sees_guard_positions')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('estate_sees_guard_positions');
        });
        Schema::dropIfExists('guard_tracking_consents');
        Schema::dropIfExists('guard_positions');
    }
};
