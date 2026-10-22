<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * DEPENDENTS AND HELPERS, BY THE ESTATE'S OWN RULES (the user, 8 Oct 2026: "every rule in one settings place so I can
 * change any of them later from admin without a rebuild").
 *
 *   estate_settings.resident_access_rules   what a Dependent and a Helper may each do in the Resident App, beyond what
 *                                           nobody can change (panic, their own e-Pass; a Member has everything; only
 *                                           the primary manages the household's people) — and a Helper's default days
 *                                           and hours, and who is told when a Helper or a Dependent comes in. JSON; a
 *                                           key left out is the platform's default (App\Api\AccessRules).
 *   residents.access_*                      ONE helper's own days, hours and end date, set by the household's primary
 *                                           resident; and `access_paused_at`, the household's "block now".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_settings', function (Blueprint $table): void {
            $table->json('resident_access_rules')->nullable();
        });

        Schema::table('residents', function (Blueprint $table): void {
            $table->json('access_days')->nullable();
            $table->string('access_from', 5)->nullable();
            $table->string('access_to', 5)->nullable();
            $table->date('access_until')->nullable();
            $table->timestamp('access_paused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('residents', function (Blueprint $table): void {
            $table->dropColumn(['access_days', 'access_from', 'access_to', 'access_until', 'access_paused_at']);
        });

        Schema::table('estate_settings', function (Blueprint $table): void {
            $table->dropColumn('resident_access_rules');
        });
    }
};
