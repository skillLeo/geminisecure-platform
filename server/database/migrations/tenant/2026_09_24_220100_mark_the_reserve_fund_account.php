<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reserve fund is the account marked as the reserve (bug hunt B035).
 *
 * The chart's "Reserve fund" tile used to be whichever bank account had the
 * highest code. It was right only because 1010 happened to sort last: an estate
 * that added 1020 Petty Cash as a bank account saw its petty cash labelled as
 * the reserve, and an estate with one operating account saw that labelled so.
 *
 * NOW A FLAG, SET ON THE ACCOUNT THAT IS THE RESERVE. Every estate's chart was
 * seeded with 1010 Reserve Fund Account as a bank account, and that is the one
 * account this marks — by its code, its flag AND its name, so an estate that has
 * renumbered or renamed 1010 into something else is not told its operating money
 * is its reserve. Where nothing is marked, the chart draws no reserve tile rather
 * than a wrong one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('is_reserve_fund')->default(false)->after('is_bank_account');
        });

        DB::table('accounts')
            ->where('code', '1010')
            ->where('is_bank_account', true)
            ->where('name', 'like', '%reserve%')
            ->update(['is_reserve_fund' => true]);
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('is_reserve_fund');
        });
    }
};
