<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seeded_recipient_drops` — every message the platform did NOT send, because the person it was addressed to is one a
 * seeder invented (M35 §5.2, layer 1).
 *
 * The demonstration seed gave residents addresses on `email.com`, a live public mail service, and a panic emailed one of
 * them twice. `SeededRecipients` now stops any email or text to a seeded person before it reaches the mail server or the
 * SMS provider, and writes one row here for each: the channel, what kind of message, which row it was addressed to and
 * in which estate. NEVER the address or the number — the row it was addressed to is enough to find out why, and the
 * address is the one thing this table exists to keep away from.
 *
 * Central and machinery (`SeededTables::CENTRAL_MACHINERY`): written by the sending layer and read by the console and
 * the report; nothing edits a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seeded_recipient_drops')) {
            return;
        }

        Schema::create('seeded_recipient_drops', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 8);                // mail | sms
            $table->string('message', 80);               // "Panic contact alerted", "Text message"
            $table->string('row_connection', 8);         // tenant (an estate's own table) | mysql (the platform's)
            $table->string('row_table', 64);
            $table->unsignedBigInteger('row_id');
            $table->string('tenant_id', 64)->nullable(); // the estate the row belongs to
            $table->timestamp('dropped_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seeded_recipient_drops');
    }
};
