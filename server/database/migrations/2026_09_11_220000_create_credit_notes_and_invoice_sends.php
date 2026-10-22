<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 35's two writes — a credit note, and a record of a resend (12 §2).
 *
 * A RAISED INVOICE IS NEVER EDITED. The billing controller's own docblock says
 * it and there is no route that would: "a correction to a raised invoice is a
 * credit note, which is a new posted record of its own." This table is that
 * record. The invoice it corrects keeps its total, its lines and its reference,
 * and the two are read together.
 *
 * A CREDIT NOTE IS POSITIVE AND CARRIES A REASON. A negative invoice would be a
 * correction that reads as a charge; a credit note with no reason is one nobody
 * can review a year later, which is exactly what it exists to be.
 *
 * THE RESEND LOG IS NOT A CONVENIENCE. "Did they get it?" is the question a
 * billing conversation actually turns on, and a resend that left no trace makes
 * the console unable to answer it — so every send records who asked for it, when
 * and to which addresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();

            $table->string('reference', 40)->unique();

            // Positive. What it does to the account is what "credit note"
            // means; a sign column would let one be entered as a charge.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('JMD');

            $table->string('reason', 300);

            $table->unsignedBigInteger('issued_by_id')->nullable();
            $table->string('issued_by_name', 120);
            $table->date('issued_on');

            $table->timestamps();

            $table->index(['tenant_id', 'issued_on']);
        });

        Schema::create('invoice_sends', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            // Who it went to, as it went — a list of addresses rather than a
            // set of user ids, because a person's address can change and the
            // question this answers is where the email actually went.
            $table->string('recipients', 500);

            $table->unsignedBigInteger('sent_by_id')->nullable();
            $table->string('sent_by_name', 120);
            $table->timestamp('sent_at');

            $table->timestamps();

            $table->index(['invoice_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sends');
        Schema::dropIfExists('credit_notes');
    }
};
