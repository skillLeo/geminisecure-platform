<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "LIVE CHAT" ON HELP & SUPPORT (board resident-app-39; M50 §4.3): a resident writes to Gemini Security, and the control
 * room answers from the Gemini console (Dispatch → Live chat). Central, because the control room answers every estate it
 * covers from one screen.
 *
 * ONE CONVERSATION PER ACCOUNT, as a messaging app keeps one thread with a business: `support_chats` is that thread,
 * marked resolved by the control room and opened again by the resident's next message. Nothing in it is edited or
 * deleted by either side.
 *
 *   support_chats           the thread: whose, whether it is open, and when each side last wrote (the console's order)
 *   support_chat_messages   author `resident` or `staff`; `client_key` is the phone's own id for a message, so one
 *                           sent twice by a phone that lost its signal is one row; `seen_at` is when the OTHER side was
 *                           shown it — "Delivered"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_chats', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 64);
            $table->unsignedBigInteger('resident_account_id');
            $table->string('status', 16)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'resident_account_id'], 'support_chats_account_unique');
            $table->index(['status', 'last_message_at'], 'support_chats_queue_index');
        });

        Schema::create('support_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_chat_id')->constrained('support_chats')->cascadeOnDelete();
            $table->string('author', 16);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('author_name', 80)->nullable();
            $table->text('body');
            $table->string('client_key', 64)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['support_chat_id', 'client_key'], 'support_chat_messages_client_key_unique');
            $table->index(['support_chat_id', 'id'], 'support_chat_messages_thread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_chat_messages');
        Schema::dropIfExists('support_chats');
    }
};
