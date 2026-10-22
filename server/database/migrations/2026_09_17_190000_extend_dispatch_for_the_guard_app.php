<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Guard App's endpoints write that nothing held yet (13 D2).
 *
 * posts.latitude/longitude/geofence_radius_m   where a post is, and how close
 *                                              "on post" is — board guard-app-01's
 *                                              "within 5 metres". Null coordinates
 *                                              mean the post has not been surveyed,
 *                                              and pre-flight says so rather than
 *                                              refusing a guard over missing data.
 * shift_breaks                                 a break taken on a shift, with the
 *                                              handset's time beside the server's.
 * shift_claims                                 a guard claiming an open shift; a
 *                                              supervisor decides (board guard-app-06).
 * alertness_checks.shift_id/issued_at/…        a check is ISSUED and then answered or
 *                                              missed — the table only held outcomes.
 * guard_presence_pings                         the app reporting on-post activity.
 * security_incidents.idempotency_key/…         an incident filed from a handset.
 * incident_media                               photos and video attached to one, kept
 *                                              on the private disk with a hash.
 * duress_alerts.mode/cancelled_at              silent or audible; cancelled within the
 *                                              grace period.
 * dispatch_messages.direction `outbound`       dispatch writing to one guard (the
 *                                              table held broadcasts and inbound).
 * dispatch_messages.idempotency_key/device_time
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('geofence_radius_m')->default(5);
        });

        Schema::create('shift_breaks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('device_started_at')->nullable();
            $table->timestamp('device_ended_at')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'ended_at']);
        });

        Schema::create('shift_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();

            // pending | approved | declined | withdrawn
            $table->string('status', 16)->default('pending');
            $table->timestamp('claimed_at');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_by_name', 120)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 190)->nullable();
            $table->timestamps();

            $table->unique(['shift_id', 'guard_id']);
        });

        Schema::table('alertness_checks', function (Blueprint $table): void {
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->boolean('clock_skewed')->default(false);
        });

        Schema::create('guard_presence_pings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            // on_post | patrolling | on_break | away
            $table->string('state', 16);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->unsignedTinyInteger('battery_pct')->nullable();
            $table->boolean('within_geofence')->nullable();
            $table->timestamp('device_time')->nullable();
            $table->timestamp('server_time')->useCurrent();
            $table->boolean('is_simulated')->default(false);

            $table->index(['guard_id', 'server_time']);
        });

        Schema::table('security_incidents', function (Blueprint $table): void {
            $table->string('location', 160)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamp('device_time')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
        });

        Schema::create('incident_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('security_incident_id')->constrained('security_incidents')->cascadeOnDelete();
            $table->foreignId('guard_id')->nullable()->constrained('guards')->nullOnDelete();
            $table->string('path', 255);
            $table->string('filename', 190);
            $table->string('content_type', 100);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->timestamps();
        });

        Schema::table('duress_alerts', function (Blueprint $table): void {
            // silent | audible — a guard's duress. Null for a resident panic.
            $table->string('mode', 8)->nullable()->after('kind');
            $table->timestamp('cancelled_at')->nullable();

            // The Resident App account that pressed panic, so only it can cancel.
            $table->unsignedBigInteger('raised_by_account_id')->nullable()->after('raised_by_name');
        });

        Schema::table('dispatch_messages', function (Blueprint $table): void {
            $table->timestamp('device_time')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_messages', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['device_time', 'idempotency_key', 'read_at']);
        });

        Schema::table('duress_alerts', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'cancelled_at', 'raised_by_account_id']);
        });

        Schema::dropIfExists('incident_media');

        Schema::table('security_incidents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['location', 'latitude', 'longitude', 'device_time', 'idempotency_key']);
        });

        Schema::dropIfExists('guard_presence_pings');

        Schema::table('alertness_checks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropColumn(['issued_at', 'respond_by', 'responded_at', 'clock_skewed']);
        });

        Schema::dropIfExists('shift_claims');
        Schema::dropIfExists('shift_breaks');

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude', 'geofence_radius_m']);
        });
    }
};
