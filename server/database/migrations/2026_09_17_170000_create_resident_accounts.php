<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Resident App's principal, and its one-time codes (13 D1, D3).
 *
 * A RESIDENT ACCOUNT IS CENTRAL, THE RESIDENT IS NOT. The person on the estate's
 * register lives in that estate's own database (`residents`), and a Sanctum
 * token needs a central tokenable to resolve before any estate is known — the
 * same reason a guard handset's token belongs to the central `guards` row. So a
 * resident account holds the estate, the contact the code was sent to, and —
 * once the estate approves their unit claim — the id of their register entry.
 *
 * pending   signed in with a one-time code, has not claimed a unit, or the
 *           claim is waiting on the estate; the token reaches only the claim
 *           and the account's own status
 * active    the claim was approved; the token carries the Resident App's abilities
 * suspended the estate withdrew access
 *
 * One-time codes are stored hashed, expire in ten minutes, and allow five tries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('channel', 8);                     // email | sms
            $table->string('destination', 190);               // the verified address or number
            $table->string('full_name', 160)->nullable();

            // Within the estate's own database, once the claim is approved.
            $table->unsignedBigInteger('resident_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('claim_id')->nullable();

            $table->string('status', 16)->default('pending');
            $table->timestamp('verified_at')->nullable();

            $table->string('device_uid', 120)->nullable();
            $table->string('device_platform', 16)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'destination']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('resident_otps', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 64);
            $table->string('channel', 8);
            $table->char('destination_hash', 64);
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'destination_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_otps');
        Schema::dropIfExists('resident_accounts');
    }
};
