<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MANUAL JOURNAL ENTRY (M84 §3.4): a journal written by hand is a DRAFT until it posts.
 *
 *   journal_drafts   a date, a reference, a memo and its lines (account, debit, credit, an optional lot), saved at any
 *                    time, balanced or not. Mutable while a draft (ESTATE_MUTABLE). Posting goes through Ledger::post,
 *                    which writes the append-only journal and its lines; the draft then carries the journal's reference
 *                    and is never edited again. `bank_statement_line_id` is the bank-only line it was started from on
 *                    bank reconciliation, matched to the journal when it posts.
 *
 * Behind FEATURE_MANUAL_JOURNALS (config/features.php). An additive table: the release before this one never reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journal_drafts')) {
            return;
        }

        Schema::create('journal_drafts', function (Blueprint $table): void {
            $table->id();
            $table->date('posted_on');
            $table->string('reference', 40)->nullable();
            $table->string('memo', 255);
            $table->json('lines');
            $table->unsignedBigInteger('bank_statement_line_id')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('journal_reference', 40)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 120)->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->string('posted_by_name', 120)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_drafts');
    }
};
