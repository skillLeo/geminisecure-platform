<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A walk-up visitor at the gate, waiting on the household (13 D2, D3).
 *
 * Board guard-app-08 "Walk-up Visitor Entry" → guard-app-02 "Waiting on
 * Resident" → resident-app-07 "Visitor Approval". The guard records who is at
 * the gate; the resident approves or denies from their phone; if nobody answers
 * by `respond_by`, the estate's standing gate policy for unannounced visitors
 * applies and the guard decides. Per estate, because it names a unit and its
 * household.
 *
 * NO PHOTO COLUMN. The board draws "Photo captured by guard"; storing a photo of
 * a member of the public at a gate is a data decision nobody has ruled on, and
 * the approval works without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('household_id')->nullable()->constrained('households')->nullOnDelete();

            // The guard and post, central ids and names copied.
            $table->unsignedBigInteger('guard_id');
            $table->string('guard_name', 160);
            $table->unsignedBigInteger('post_id')->nullable();
            $table->string('post_name', 120)->nullable();

            $table->string('visitor_name', 160);
            $table->string('id_type', 40)->nullable();
            $table->string('id_number', 40)->nullable();
            $table->string('purpose', 160)->nullable();
            $table->string('vehicle_plate', 16)->nullable();

            // pending | approved | denied | expired
            $table->string('status', 16)->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('respond_by');
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('responded_by_account_id')->nullable();
            $table->string('responded_by_name', 160)->nullable();

            $table->timestamp('device_time')->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->timestamps();

            $table->index(['unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_approval_requests');
    }
};
