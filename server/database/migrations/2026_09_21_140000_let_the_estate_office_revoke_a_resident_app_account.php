<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE ESTATE OFFICE TAKING A RESIDENT APP ACCOUNT AWAY (Q-M44).
 *
 * Until now the only ways a Resident App account could lose its reach into an estate
 * were the primary resident removing a member and the person deleting themselves. When
 * an owner sold or a tenant's lease ended, the office had no button at all: the former
 * resident kept the household's balance and statement, the power to issue signed visitor
 * passes to the gate, their own e-pass, the roster, the ballot and the walk-up approval.
 *
 * A REVOCATION IS NOT A DELETION, so nothing here drops anything. The account row stays,
 * its `resident_id` and `unit_id` stay — they are how the gate knows WHICH unit this
 * person is no longer a resident of, and they are what an undo restores from — and the
 * register entry, the gate history, the household's charges and the audit trail are not
 * this migration's business at all.
 *
 *   revoked_at      the fact, and what `status = 'revoked'` is dated by
 *   revoked_reason  why, in the officer's own words — an explicit act, never a side effect
 *   revoked_by      who did it, by name, so the office can answer for it
 *
 * Additive, reversible, and adds no table — so no `grants:*` run and no `GrantedTables`
 * entry is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable()->after('deleted_reference');
            $table->string('revoked_reason', 190)->nullable()->after('revoked_at');
            $table->string('revoked_by', 160)->nullable()->after('revoked_reason');
            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'revoked_at']);
            $table->dropColumn(['revoked_at', 'revoked_reason', 'revoked_by']);
        });
    }
};
