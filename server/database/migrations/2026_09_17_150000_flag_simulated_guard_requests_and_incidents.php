<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central tables a handset writes that could not say whether it was real (13 C3).
 *
 * Category B is every screen whose primary records a handset writes, and each
 * carries a badge saying whether those records came from a real device or the
 * simulator. The badge is computed from the rows, never the environment
 * (`App\Support\SourceBadge`), so a table the Guard App will write needs the
 * flag before its screen can carry the badge honestly: guard requests (leave,
 * equipment) and security incidents, both of which get endpoints in 13 D2.
 *
 * Existing rows are false: they were seeded or entered in the console, and
 * nothing simulated them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['guard_requests', 'security_incidents'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('is_simulated')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['guard_requests', 'security_incidents'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('is_simulated');
            });
        }
    }
};
