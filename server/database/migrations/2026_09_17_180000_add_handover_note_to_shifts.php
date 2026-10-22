<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a guard leaves for whoever relieves the post (13 D1, board guard-app-05's
 * "Handover note added for next shift"). Written at clock-out, read by the next
 * shift on the same post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->string('handover_note', 500)->nullable()->after('mock_location_flag');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropColumn('handover_note');
        });
    }
};
