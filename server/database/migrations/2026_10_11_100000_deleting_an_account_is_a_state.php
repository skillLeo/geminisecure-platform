<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DELETING AN ACCOUNT IS A STATE, NOT AN EVENT (M18H §1). An account moves to `deleted`, keeps everything, and can come
 * back: the person themselves in the first days, the estate office after that, nobody once the window has closed and
 * the account is anonymised. These columns are what the state and its way back are made of:
 *
 *   deleted_by              `resident` or `office` — who pressed it (the Deleted accounts tab says so)
 *   deleted_by_name         the officer, when it was the office
 *   status_before_delete    what a restore puts back (`active`, or `pending` for one still waiting on a claim)
 *   removed_register_at     the register entry this deletion took off the active list, and when — so a restore puts
 *                           back exactly the removal it made, and never one the household made for its own reasons
 *   restored_at, restored_by   the last time it came back, and who brought it
 *   anonymised_at           the day nothing of the person was left: after the window, or deleted permanently sooner
 *   anonymise_reason        why, when the office deleted it permanently (the audit entry holds it too)
 *
 * `deleted_at` and `deleted_reference` already exist, from the first deletion (M5 §4), and keep their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('resident_accounts', 'deleted_by')) {
                $table->string('deleted_by', 20)->nullable()->after('deleted_reference');
            }
            if (! Schema::hasColumn('resident_accounts', 'deleted_by_name')) {
                $table->string('deleted_by_name', 160)->nullable()->after('deleted_by');
            }
            if (! Schema::hasColumn('resident_accounts', 'status_before_delete')) {
                $table->string('status_before_delete', 20)->nullable()->after('deleted_by_name');
            }
            if (! Schema::hasColumn('resident_accounts', 'removed_register_at')) {
                $table->timestamp('removed_register_at')->nullable()->after('status_before_delete');
            }
            if (! Schema::hasColumn('resident_accounts', 'restored_at')) {
                $table->timestamp('restored_at')->nullable()->after('removed_register_at');
            }
            if (! Schema::hasColumn('resident_accounts', 'restored_by')) {
                $table->string('restored_by', 160)->nullable()->after('restored_at');
            }
            if (! Schema::hasColumn('resident_accounts', 'anonymised_at')) {
                $table->timestamp('anonymised_at')->nullable()->after('restored_by');
            }
            if (! Schema::hasColumn('resident_accounts', 'anonymise_reason')) {
                $table->string('anonymise_reason', 300)->nullable()->after('anonymised_at');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['deleted_by', 'deleted_by_name', 'status_before_delete', 'removed_register_at', 'restored_at', 'restored_by', 'anonymised_at', 'anonymise_reason'],
            static fn (string $column): bool => Schema::hasColumn('resident_accounts', $column),
        ));

        if ($columns !== []) {
            Schema::table('resident_accounts', static function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
