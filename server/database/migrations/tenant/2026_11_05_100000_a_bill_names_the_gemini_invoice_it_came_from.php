<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A BILL DRAFTED FROM ONE OF GEMINI'S INVOICES NAMES IT (EST-S-05, M85 A4). Each invoice Gemini issues raises a draft
 * bill to 5050 in that estate (M82 answer 2), once: the central invoice's id, unique here, is what makes a retry or the
 * command after a raise find the bill already drafted instead of drafting a second. Additive: one nullable column,
 * null on every bill the estate records itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bills') || Schema::hasColumn('bills', 'platform_invoice_id')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table): void {
            $table->unsignedBigInteger('platform_invoice_id')->nullable()->after('ticket_label');
            $table->unique('platform_invoice_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bills') || ! Schema::hasColumn('bills', 'platform_invoice_id')) {
            return;
        }

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropUnique(['platform_invoice_id']);
            $table->dropColumn('platform_invoice_id');
        });
    }
};
