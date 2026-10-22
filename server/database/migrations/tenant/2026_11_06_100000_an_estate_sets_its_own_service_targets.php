<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE SERVICE TARGETS, SET BY EACH ESTATE (M98, maintenance and vendors): how many hours a maintenance ticket of each
 * priority has before it is overdue. The defaults are the ladder every estate has had until now (Maintenance::SLA_HOURS):
 *
 *   sla_hours_high     24   a day
 *   sla_hours_medium   72   three days
 *   sla_hours_low     168   seven days
 *
 * A ticket keeps the target it was raised with (`maintenance_tickets.sla_hours`); these decide the next one, and the one
 * whose priority is changed next. Columns on the estate's one settings row, so no new table and no new grant.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'sla_hours_high' => 24,
        'sla_hours_medium' => 72,
        'sla_hours_low' => 168,
    ];

    public function up(): void
    {
        Schema::table('estate_settings', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column => $hours) {
                if (! Schema::hasColumn('estate_settings', $column)) {
                    $table->unsignedSmallInteger($column)->default($hours);
                }
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            array_keys(self::COLUMNS),
            static fn (string $column): bool => Schema::hasColumn('estate_settings', $column),
        ));

        if ($columns !== []) {
            Schema::table('estate_settings', static function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
