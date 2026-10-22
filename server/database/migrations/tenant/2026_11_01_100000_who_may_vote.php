<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO MAY VOTE: THE HOUSEHOLDS THAT EXIST, UNLESS THE ESTATE SAYS EVERY LOT (Hassam, 9 October 2026).
 *
 * Phoenix Park has 1,999 lots and about 443 households. Counted by lot, a 25% quorum is 500 and can never be met; counted
 * by household it is reachable, and that is how the demonstration's own meetings and elections were seeded. Whether an
 * absent owner (a lot with nobody living on it) votes is the client's to say (CONFIRM-01); until then, households.
 * `estate:voting-eligibility` changes it, with a reason, never a settings form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_settings', function (Blueprint $table) {
            $table->string('voting_eligibility', 16)->default('households')->after('meeting_quorum_percent');
        });
    }

    public function down(): void
    {
        Schema::table('estate_settings', function (Blueprint $table) {
            $table->dropColumn('voting_eligibility');
        });
    }
};
