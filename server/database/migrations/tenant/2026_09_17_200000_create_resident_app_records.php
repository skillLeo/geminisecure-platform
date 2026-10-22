<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Resident App writes that no estate table held yet (13 D3).
 *
 * household_vehicles            a household's registered vehicles — the plate a
 *                               guard reads at the barrier
 * household_emergency_contacts  who to call for a household; never shown to a guard
 * meeting_rsvps                 whether a household means to attend. NOT attendance:
 *                               the register is taken at the meeting, and quorum is
 *                               counted from the register (board 36), never from an RSVP
 * ticket_media                  photos a resident attaches to a maintenance ticket,
 *                               on the estate's private disk, hashed on arrival
 * estate_settings.dues_payment_instructions
 *                               how this estate is paid by hand — bank, branch,
 *                               account, what to quote — while dues are manual (Q-012)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->string('plate', 16);
            $table->string('make', 40)->nullable();
            $table->string('model', 40)->nullable();
            $table->string('colour', 24)->nullable();
            $table->unsignedBigInteger('added_by_account_id')->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->timestamps();

            $table->unique(['household_id', 'plate']);
            $table->index('plate');
        });

        Schema::create('household_emergency_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('relationship', 40)->nullable();
            $table->string('phone', 40);
            $table->unsignedBigInteger('added_by_account_id')->nullable();
            $table->timestamps();
        });

        Schema::create('meeting_rsvps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->unsignedBigInteger('resident_id')->nullable();
            $table->string('responded_by_name', 160)->nullable();

            // attending | apologies | not_attending
            $table->string('response', 16);
            $table->timestamp('responded_at');
            $table->timestamps();

            $table->unique(['meeting_id', 'unit_id']);
        });

        Schema::create('ticket_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('maintenance_ticket_id')->constrained('maintenance_tickets')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('filename', 190);
            $table->string('content_type', 100);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('uploaded_by_account_id')->nullable();
            $table->timestamps();
        });

        Schema::table('estate_settings', function (Blueprint $table): void {
            $table->text('dues_payment_instructions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('estate_settings', function (Blueprint $table): void {
            $table->dropColumn('dues_payment_instructions');
        });

        Schema::dropIfExists('ticket_media');
        Schema::dropIfExists('meeting_rsvps');
        Schema::dropIfExists('household_emergency_contacts');
        Schema::dropIfExists('household_vehicles');
    }
};
