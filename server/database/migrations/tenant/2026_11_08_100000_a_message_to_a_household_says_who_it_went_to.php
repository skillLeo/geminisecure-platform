<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO A MESSAGE WENT TO (Hassam, batch 1 item 1, 12 Oct 2026: on a ticket's "Message the resident" nobody could tell who
 * receives it). A message to a household is read by every person on the lot whose Resident App reaches requests, and
 * those people are named on the line of the ticket's history as they were when it was sent: the household changes, the
 * record of who was told does not. A JSON list of names, nullable: every older message keeps reading "Message to the
 * household". A column on an existing table, so no new table and no new grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('maintenance_ticket_activity', 'recipients')) {
            Schema::table('maintenance_ticket_activity', static function (Blueprint $table): void {
                $table->text('recipients')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('maintenance_ticket_activity', 'recipients')) {
            Schema::table('maintenance_ticket_activity', static function (Blueprint $table): void {
                $table->dropColumn('recipients');
            });
        }
    }
};
