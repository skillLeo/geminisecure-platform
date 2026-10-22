<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which Gemini Console notifications a person has seen — CENTRAL (12 §2, item 41).
 *
 * THE READ MARK AND NOTHING ELSE, for the same reason as the estate's own
 * `notification_reads`: an open alert, a pending request, a lapsing licence and
 * an overdue invoice are each already a row in the table that owns the fact.
 * Copying them into a notifications table would leave a notification standing
 * after the thing it describes was dealt with, and the reader would believe it.
 * So the items are derived each time the bell is drawn, and only "this person
 * has seen this item" is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // "alert:12", "licence:7:2026-10-02" — the item, as the service keys it.
            $table->string('item_key', 64);

            $table->timestamp('read_at');
            $table->timestamps();

            $table->unique(['user_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notification_reads');
    }
};
