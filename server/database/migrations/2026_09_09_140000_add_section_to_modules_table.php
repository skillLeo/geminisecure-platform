<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sidebar grouping.
 *
 * The approved sidebar splits navigation under two uppercase headings,
 * "Platform" and "System", with Dashboard sitting above both and outside
 * either. A null section means exactly that: rendered before the first
 * heading rather than under a heading of its own.
 *
 * Stored rather than derived, because the grouping is a design decision made
 * in the wireframe and not something the module key implies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->string('section', 32)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn('section');
        });
    }
};
