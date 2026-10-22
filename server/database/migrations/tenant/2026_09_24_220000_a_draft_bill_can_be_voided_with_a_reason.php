<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A draft bill can be voided, and a void says why and who (bug hunt B036).
 *
 * `bills.status` always had `void` in its vocabulary and nothing could reach it.
 * A draft recorded against the wrong supplier sat on Bills & payments for ever,
 * and the supplier could never be made inactive — while the refusal told the
 * office to "void" it.
 *
 * A DRAFT HAS RAISED NO JOURNAL, so voiding one posts nothing and reverses
 * nothing. What the row needs is the record of the decision: the reason, the
 * person, the moment. An approved bill is never voided — its liability is on
 * 2000 — and `Payables::void()` refuses one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->string('void_reason', 190)->nullable()->after('journal_ref');
            $table->unsignedBigInteger('voided_by')->nullable()->after('void_reason');
            $table->string('voided_by_name', 160)->nullable()->after('voided_by');
            $table->timestamp('voided_at')->nullable()->after('voided_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn(['void_reason', 'voided_by', 'voided_by_name', 'voided_at']);
        });
    }
};
