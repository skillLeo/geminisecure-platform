<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Resident App account the person has DELETED (M5 §4 — both stores require this).
 *
 * The row is not dropped: `resident_accounts.id` is what the audit log and the estate's
 * claim records point at, and a deletion that broke those would destroy the community's
 * own history to satisfy a request about one person's login. Instead the row is
 * DE-IDENTIFIED in place — no name, no email address, no phone number, no device — and
 * marked deleted, which is what these two columns are for.
 *
 * `deleted_at` is the fact. `deleted_reference` is what the person is given so they can
 * ask about it afterwards without us keeping anything that identifies them.
 *
 * Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable()->after('verified_at');
            $table->string('deleted_reference', 16)->nullable()->after('deleted_at');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn(['deleted_at', 'deleted_reference']);
        });
    }
};
