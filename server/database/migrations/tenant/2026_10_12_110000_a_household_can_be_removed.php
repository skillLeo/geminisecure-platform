<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REMOVING A HOUSEHOLD, RECOVERABLE AS AN ACCOUNT IS (M21 §3.1, under M18H's model).
 *
 *   removed_at          when the office removed it; a removed household is outside the default scope, so its lot
 *                       reads as vacant everywhere — and every charge, payment and gate entry keeps pointing at it
 *   removed_by_name     the officer, for the console's list (the audit entry has the user)
 *   removal_reference   the reference the office and the household quote
 *   removal_reason      why, in the officer's words
 *   removal_of          what this removal did, so a restore undoes exactly that: the register entries it took off and
 *                       the accounts it deleted — never a removal somebody else made
 *   restored_at / restored_by_name, anonymised_at   the two ways it can end
 */
return new class extends Migration
{
    private const COLUMNS = ['removed_at', 'removed_by_name', 'removal_reference', 'removal_reason', 'removal_of', 'restored_at', 'restored_by_name', 'anonymised_at'];

    public function up(): void
    {
        Schema::table('households', function (Blueprint $table): void {
            if (! Schema::hasColumn('households', 'removed_at')) {
                $table->timestamp('removed_at')->nullable()->index();
            }
            if (! Schema::hasColumn('households', 'removed_by_name')) {
                $table->string('removed_by_name', 160)->nullable();
            }
            if (! Schema::hasColumn('households', 'removal_reference')) {
                $table->string('removal_reference', 32)->nullable();
            }
            if (! Schema::hasColumn('households', 'removal_reason')) {
                $table->string('removal_reason', 300)->nullable();
            }
            if (! Schema::hasColumn('households', 'removal_of')) {
                $table->json('removal_of')->nullable();
            }
            if (! Schema::hasColumn('households', 'restored_at')) {
                $table->timestamp('restored_at')->nullable();
            }
            if (! Schema::hasColumn('households', 'restored_by_name')) {
                $table->string('restored_by_name', 160)->nullable();
            }
            if (! Schema::hasColumn('households', 'anonymised_at')) {
                $table->timestamp('anonymised_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(self::COLUMNS, static fn (string $column): bool => Schema::hasColumn('households', $column)));

        if ($columns !== []) {
            Schema::table('households', static function (Blueprint $table) use ($columns): void {
                if (in_array('removed_at', $columns, true)) {
                    $table->dropIndex(['removed_at']);
                }
                $table->dropColumn($columns);
            });
        }
    }
};
