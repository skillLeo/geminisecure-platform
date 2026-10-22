<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `alert_email_dispatches.household_member_id` — a panic emails the household's MEMBERS too, not only its emergency
 * contacts (M18C §4.1: "on a panic, every stored email is used"). One row per person, as before; a member's row
 * carries the ESTATE database's resident id here and leaves `household_emergency_contact_id` null, a contact's the
 * other way round. The table stays append-only: this adds a column and changes no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('alert_email_dispatches', 'household_member_id')) {
            return;
        }

        Schema::table('alert_email_dispatches', function (Blueprint $table): void {
            $table->unsignedBigInteger('household_member_id')->nullable()->after('household_emergency_contact_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('alert_email_dispatches', 'household_member_id')) {
            return;
        }

        Schema::table('alert_email_dispatches', function (Blueprint $table): void {
            $table->dropColumn('household_member_id');
        });
    }
};
