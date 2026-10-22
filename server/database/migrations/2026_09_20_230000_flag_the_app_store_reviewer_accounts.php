<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVIEWER MODE (M5 §3) — the flag lives on the SERVER, on the account, never in the build.
 *
 * An App Store or Play reviewer downloads the production binary and hits four walls in a row:
 * no enrolment code, a handset nobody has bound, a geofence three thousand miles away, and a
 * face the phone has never been shown. Guideline 2.1 rejects the app there. The answer is not
 * a debug build or a compile-time switch — the binary a reviewer tests must be the binary
 * everybody else gets — so it is three columns:
 *
 *   `tenants.demo`                    this estate is the DEMONSTRATION community. It holds no
 *                                     real data, the Resident App offers it by name, and
 *                                     tenant isolation applies to it exactly as to any other
 *                                     estate. Nothing here relaxes invariant 1.
 *   `guards.reviewer`                 this Guard App account may use the reviewer bypasses,
 *   `resident_accounts.reviewer`      and this Resident App one. Every use is audited.
 *   `…reviewer_code_hash`             the STATIC sign-in code, as a SHA-256 hash — never in
 *                                     plain text, exactly like `device_enrolment_codes`. It
 *                                     does not expire: a reviewer cannot run an artisan
 *                                     command to mint a one-time code.
 *
 * REVOCABLE AND ADDITIVE. Clearing the flag ends every bypass at once and leaves the account
 * an ordinary one. Nothing is dropped and `down()` puts the schema back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('demo')->default(false)->after('status');
            $table->index('demo');
        });

        Schema::table('guards', function (Blueprint $table): void {
            $table->boolean('reviewer')->default(false)->after('status');
            $table->string('reviewer_code_hash', 64)->nullable()->after('reviewer');
        });

        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->boolean('reviewer')->default(false)->after('status');
            $table->string('reviewer_code_hash', 64)->nullable()->after('reviewer');
        });
    }

    public function down(): void
    {
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->dropColumn(['reviewer', 'reviewer_code_hash']);
        });

        Schema::table('guards', function (Blueprint $table): void {
            $table->dropColumn(['reviewer', 'reviewer_code_hash']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex(['demo']);
            $table->dropColumn('demo');
        });
    }
};
