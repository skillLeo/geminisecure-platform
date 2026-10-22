<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SITE TIME OR MY TIME, REMEMBERED PER USER (M19A §7). A Gemini dispatcher watching estates in several zones reads the
 * console on each site's own clock by default; the switch in the header puts the whole console on their own clock.
 *
 *   console_time_view   `site` (the default, and null) or `mine`
 *   console_zone        the viewer's own IANA zone, as their browser gave it when they switched
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'console_time_view')) {
                $table->string('console_time_view', 8)->nullable();
            }
            if (! Schema::hasColumn('users', 'console_zone')) {
                $table->string('console_zone', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(['console_time_view', 'console_zone'], static fn (string $c): bool => Schema::hasColumn('users', $c)));

        if ($columns !== []) {
            Schema::table('users', static function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
