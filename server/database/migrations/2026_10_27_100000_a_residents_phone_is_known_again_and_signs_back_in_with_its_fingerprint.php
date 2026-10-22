<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A RESIDENT'S PHONE IS KNOWN AGAIN, AND SIGNS BACK IN WITH ITS FINGERPRINT (M49, the user, 8 October: "biometrics on in
 * Settings but unknown at Log in", "seven active sessions across two phones, four of them the same phone"). Additive.
 *
 *   personal_access_tokens.device_hardware   the handset a Resident App sign-in came from — a hash of Android's per-app
 *                                            device id (iOS: the vendor id), which a reinstall of the same app keeps.
 *                                            Signing in again on that handset replaces its earlier sign-in instead of
 *                                            adding one beside it (ResidentSessions::retireHandset).
 *   resident_device_keys                     "Face ID / fingerprint" on a phone: a key the server issued to that one
 *                                            install, kept in the phone's secure store behind its own biometric prompt.
 *                                            Only its hash is here. It signs the phone back in with no emailed code
 *                                            (auth.device_key), and stops working when the switch is turned off, the
 *                                            phone is signed out from another, or the account is deleted, suspended or
 *                                            revoked (ResidentDeviceKeys).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'device_hardware')) {
            Schema::table('personal_access_tokens', function (Blueprint $table): void {
                $table->string('device_hardware', 64)->nullable()->index();
            });
        }

        if (! Schema::hasTable('resident_device_keys')) {
            Schema::create('resident_device_keys', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('resident_account_id')->constrained('resident_accounts')->cascadeOnDelete();
                $table->string('tenant_id', 64)->index();
                $table->string('device_uid', 120);
                $table->string('device_hardware', 64)->nullable();
                $table->string('platform', 16);
                $table->string('device_label', 80)->nullable();
                $table->string('key_hash', 64)->unique();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_reason', 40)->nullable();
                $table->timestamps();

                $table->index(['resident_account_id', 'device_uid']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_device_keys');

        if (Schema::hasColumn('personal_access_tokens', 'device_hardware')) {
            Schema::table('personal_access_tokens', function (Blueprint $table): void {
                $table->dropIndex(['device_hardware']);
                $table->dropColumn('device_hardware');
            });
        }
    }
};
