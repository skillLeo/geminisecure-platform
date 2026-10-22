<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor passes, signed (13 D1, D3).
 *
 * A resident issues a pass from the Resident App; the visitor shows its QR code
 * at the gate; a guard's handset verifies the Ed25519 signature, the window and
 * the site with no network at all. The row here is the server's record of what
 * was signed, and the only place a cancellation or a use is known — which is
 * why an offline verdict is "valid, not checked against cancellations".
 *
 * `token` is the exact signed string the QR code carries, stored so a resident
 * who asks for their pass again gets the same bytes rather than a new signature.
 * `code` is the short manual code the guard types when a QR code will not scan
 * (board guard-app-08: four letters, a phase number, four digits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_passes', function (Blueprint $table) {
            $table->id();
            $table->uuid('pass_id')->unique();

            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('household_id')->nullable()->constrained('households')->nullOnDelete();

            // The resident who issued it, by id within this estate.
            $table->unsignedBigInteger('issued_by_resident_id')->nullable();
            $table->string('issued_by_name', 160);

            // single | recurring | contractor | delivery
            $table->string('category', 16);
            $table->string('visitor_name', 160);
            $table->string('visitor_phone', 32)->nullable();
            $table->string('purpose', 160)->nullable();
            $table->string('vehicle_plate', 16)->nullable();

            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->boolean('single_use')->default(true);

            $table->char('nonce', 32);
            $table->unsignedInteger('key_version');
            $table->text('token');
            $table->string('code', 16)->unique();

            // active | used | cancelled
            $table->string('status', 16)->default('active');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedSmallInteger('share_count')->default(0);

            $table->string('idempotency_key', 64)->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->timestamps();

            $table->index(['unit_id', 'status']);
            $table->index(['valid_from', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_passes');
    }
};
