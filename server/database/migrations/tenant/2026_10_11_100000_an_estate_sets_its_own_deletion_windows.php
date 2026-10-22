<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE TWO WINDOWS A DELETED ACCOUNT CAN COME BACK IN, SET BY EACH ESTATE (M18H §1.2), with the client's defaults:
 *
 *   account_self_restore_days     7    the person restores it themselves, by signing in
 *   account_office_restore_days   30   the estate office restores it from the console; after this it is anonymised
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('estate_settings', 'account_self_restore_days')) {
                $table->unsignedSmallInteger('account_self_restore_days')->default(7);
            }
            if (! Schema::hasColumn('estate_settings', 'account_office_restore_days')) {
                $table->unsignedSmallInteger('account_office_restore_days')->default(30);
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['account_self_restore_days', 'account_office_restore_days'],
            static fn (string $column): bool => Schema::hasColumn('estate_settings', $column),
        ));

        if ($columns !== []) {
            Schema::table('estate_settings', static function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
