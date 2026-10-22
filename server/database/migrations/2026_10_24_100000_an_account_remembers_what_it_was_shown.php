<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT A RESIDENT APP ACCOUNT HAS BEEN SHOWN ONCE (M47 §3).
 *
 * The first-run tour was recorded as seen on the PHONE alone — its key-value store — and every sign-out the person chose,
 * every reinstall and every new phone wiped it, so the tour came back at every sign-in. Shown once means once for the
 * account: the record belongs here, beside the account, and survives all of those.
 *
 *   resident_accounts.shown   JSON: each thing shown once, and when it was first finished or skipped
 *                             ({"tour": "2026-10-07T14:02:11+00:00"}). Null for an account shown nothing yet. Read and
 *                             written by `ShownOnce` alone; cleared when the account is anonymised.
 *
 * ADDITIVE: one nullable column; nothing existing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resident_accounts', 'shown')) {
            Schema::table('resident_accounts', function (Blueprint $table): void {
                $table->json('shown')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('resident_accounts', 'shown')) {
            Schema::table('resident_accounts', function (Blueprint $table): void {
                $table->dropColumn('shown');
            });
        }
    }
};
