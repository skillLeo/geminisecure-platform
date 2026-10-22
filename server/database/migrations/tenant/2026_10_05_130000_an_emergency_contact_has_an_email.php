<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An emergency contact takes an email beside the phone (M12 §2.5): a panic emails them at once, and the number is
 * there for the dispatcher to call. Optional — a relative with no email is still a contact — and validated when
 * given. A private person's address, so it stays here, in the estate's own database, and the central ledger holds
 * it masked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('household_emergency_contacts') && ! Schema::hasColumn('household_emergency_contacts', 'email')) {
            Schema::table('household_emergency_contacts', function (Blueprint $table): void {
                $table->string('email', 190)->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('household_emergency_contacts', 'email')) {
            Schema::table('household_emergency_contacts', function (Blueprint $table): void {
                $table->dropColumn('email');
            });
        }
    }
};
