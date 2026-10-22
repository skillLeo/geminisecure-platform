<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two of the client's guard-side rulings (M4 §3.4). Additive only.
 *
 * supervisor_override_pins   "Server sets and verifies the PIN." One row per console
 *                            user who may start a guard's shift when the phone could
 *                            not confirm who is holding it. THE PIN IS STORED ONLY AS
 *                            A PASSWORD HASH — never in the clear, never logged, never
 *                            in an audit entry. `failed_attempts` counts wrong tries
 *                            since the PIN was last set or last used; at five the row
 *                            is `locked_at` and stays locked until its owner sets a
 *                            new PIN in the console. Setting a new one IS the rotation.
 *
 * shifts.overridden_*        "Every use ... flags the shift record." Who vouched for
 *                            the guard (`overridden_by`, a console user), why the
 *                            phone could not (`override_reason`: `biometric_failed` or
 *                            `no_biometrics`) and when (`overridden_at`, a UTC instant
 *                            like every other time on the row). Null on every shift
 *                            the handset confirmed itself.
 *
 * dispatch_message_reads     "Read state is per guard, per phone." A broadcast's
 *                            `dispatch_messages.read_at` was ONE column shared by every
 *                            guard at the estate, set when any one of them replied.
 *                            This is the read mark that belongs to one guard on one
 *                            handset. Nothing is backfilled: nobody can say which guard
 *                            the old shared mark was about. The old column is left
 *                            where it is and is no longer written for a broadcast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_override_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('pin_hash', 255);
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('set_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->unsignedBigInteger('overridden_by')->nullable();
            $table->string('override_reason', 24)->nullable();
            $table->timestamp('overridden_at')->nullable();

            $table->index(['overridden_at'], 'shifts_overridden_at_index');
        });

        Schema::create('dispatch_message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_message_id')->constrained('dispatch_messages')->cascadeOnDelete();
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();

            // The handset that read it — `guards.device_uid` at that moment. Empty for a
            // handset enrolled before a UID was recorded, so the unique key still holds.
            $table->string('device_uid', 120)->default('');
            $table->timestamp('read_at');
            $table->timestamp('device_time')->nullable();
            $table->timestamps();

            $table->unique(['dispatch_message_id', 'guard_id', 'device_uid'], 'dispatch_message_reads_unique');
            $table->index(['guard_id', 'device_uid'], 'dispatch_message_reads_reader_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_message_reads');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex('shifts_overridden_at_index');
            $table->dropColumn(['overridden_by', 'override_reason', 'overridden_at']);
        });

        Schema::dropIfExists('supervisor_override_pins');
    }
};
