<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A HOUSEHOLD MEMBER HAS AN ACCESS LEVEL IN THE RESIDENT APP — Member, Dependent or Helper.
 *
 * The user asked how a resident adds their family "and assigns them app credentials, like son, wife,
 * father" (24 September 2026). A son and a housekeeper signed in to the same household's app could
 * both pay its dues, issue its visitor passes and cast its one ballot, because the app knew nobody's
 * place in the household but the primary resident's.
 *
 *   member     an adult of the household: everything a resident may do, except managing its members,
 *              which stays the primary resident's
 *   dependent  a child or an elderly relative: panic, their own e-pass, notices and maintenance
 *   helper     domestic staff: panic and their own e-pass to come and go
 *
 * `App\Api\AppMatrix::LEVELS` is what each level may reach, and the server checks it on every request
 * (`EnforceAccessLevel`). The primary resident is Member whatever this column says.
 *
 * THE DEFAULT IS THE BACKFILL. Every row already on the register becomes `member` — exactly what it
 * could do yesterday. No level is guessed from a relationship: reading `domestic_staff` as a Helper
 * would take rights away overnight from people who have them, on nobody's decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('residents', 'access_level')) {
            return;
        }

        Schema::table('residents', function (Blueprint $table): void {
            $table->string('access_level', 16)->default('member')->after('is_primary');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('residents', 'access_level')) {
            return;
        }

        Schema::table('residents', function (Blueprint $table): void {
            $table->dropColumn('access_level');
        });
    }
};
