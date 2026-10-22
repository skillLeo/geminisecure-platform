<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `nominations.statement` — the candidate's own words (M10 §7).
 *
 * A nomination records who stands, for which seat, proposed and seconded by
 * whom, and whether the returning officer accepted them. It has never recorded
 * WHY they are standing, so a household opening the ballot in the Resident App
 * saw a list of names and nothing else to choose between them on.
 *
 * On the NOMINATION rather than on `ballot_options`, because the statement is
 * the candidate's and belongs beside the rest of what they submitted. An option
 * is the ballot paper's row for them, created when the returning officer accepts
 * the nomination, and it carries the label a voter marks — not the argument.
 *
 * Nullable, and it stays nullable. Some estates will run elections where nobody
 * writes anything, and a required statement would turn "I want to stand" into a
 * writing exercise. A candidate who says nothing appears with their name alone,
 * which is how every election on this platform has worked so far.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('nominations', 'statement')) {
            return;
        }

        Schema::table('nominations', function (Blueprint $table): void {
            $table->text('statement')->nullable()->after('candidate_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('nominations', 'statement')) {
            return;
        }

        Schema::table('nominations', function (Blueprint $table): void {
            $table->dropColumn('statement');
        });
    }
};
