<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the mobile API stands on (13 D1).
 *
 * pass_signing_keys        one Ed25519 key pair per site per version. The public
 *                          half is served to handsets; the secret half is stored
 *                          ENCRYPTED with the application key and no endpoint,
 *                          screen or export ever reads it back out.
 * api_idempotency_keys     every write a handset makes, keyed by the handset and
 *                          the key it chose, with the response it was given — so
 *                          a retry is answered with the same response rather
 *                          than acted on twice.
 * device_enrolment_codes   the one-time code Gemini's office gives a guard to
 *                          enrol a handset. Stored hashed.
 * device_rebind_requests   a guard who already has a handset asking to bind
 *                          another. Needs a supervisor, and the approval is
 *                          recorded against the guard's shift.
 * guards.device_*          what the bound handset said about itself at
 *                          enrolment: its UID, platform and public key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pass_signing_keys', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 64);
            $table->unsignedInteger('key_version');
            $table->string('public_key', 64);        // base64url, 32 bytes
            $table->text('secret_key_encrypted');    // Crypt::encryptString(base64url 64 bytes)
            $table->timestamp('activated_at');
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'key_version']);
            $table->index(['site_id', 'retired_at']);
        });

        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type', 120);
            $table->unsignedBigInteger('tokenable_id');
            $table->string('idempotency_key', 64);
            $table->string('route', 120);
            $table->char('request_hash', 64);

            // Null while the first attempt is still being handled.
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();

            $table->unique(['tokenable_type', 'tokenable_id', 'idempotency_key'], 'api_idempotency_unique');
        });

        Schema::create('device_enrolment_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->string('issued_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('device_rebind_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();
            $table->foreignId('enrolment_code_id')->constrained('device_enrolment_codes')->cascadeOnDelete();
            $table->string('device_uid', 120);
            $table->string('platform', 16);
            $table->string('public_key', 64);
            $table->string('label', 120)->nullable();

            // What the handset presents to collect its token once approved. Hashed.
            $table->char('claim_hash', 64);

            // pending | approved | denied | collected
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_by_name', 120)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 190)->nullable();

            // The shift the approval is recorded against, where the guard has one.
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamps();

            $table->index(['guard_id', 'status']);
        });

        Schema::table('guards', function (Blueprint $table) {
            $table->string('device_uid', 120)->nullable();
            $table->string('device_platform', 16)->nullable();
            $table->string('device_public_key', 64)->nullable();
            $table->timestamp('device_enrolled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            $table->dropColumn(['device_uid', 'device_platform', 'device_public_key', 'device_enrolled_at']);
        });

        Schema::dropIfExists('device_rebind_requests');
        Schema::dropIfExists('device_enrolment_codes');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('pass_signing_keys');
    }
};
