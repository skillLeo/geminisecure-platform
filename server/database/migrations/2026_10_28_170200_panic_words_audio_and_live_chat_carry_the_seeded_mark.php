<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT A RESIDENT SAYS ON A PANIC, ITS LIVE AUDIO, AND LIVE CHAT CARRY THE SEEDED MARK (M33 §5.2, KI-25).
 *
 * `duress_alert_messages` and `duress_alert_audio` (M50 §4.2 #1–#2) and `support_chats` (M50 §4.3) name an estate by
 * `tenant_id` and arrived without `seeded_at` and `seeded_by`; SeededMarksTest holds every such platform table to
 * SeededTables' lists. They are records, like `dispatch_messages`, so they are marked — no seeder writes them, and a
 * person's rows stay null. Additive: two nullable columns each.
 */
return new class extends Migration
{
    private const TABLES = ['duress_alert_messages', 'duress_alert_audio', 'support_chats'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (! Schema::hasColumn($name, 'seeded_at')) {
                    $table->timestamp('seeded_at')->nullable();
                }
                if (! Schema::hasColumn($name, 'seeded_by')) {
                    $table->string('seeded_by', 64)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                foreach (['seeded_at', 'seeded_by'] as $column) {
                    if (Schema::hasColumn($name, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
