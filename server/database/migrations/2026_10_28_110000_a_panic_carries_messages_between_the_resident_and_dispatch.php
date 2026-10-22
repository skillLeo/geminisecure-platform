<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "MESSAGE DISPATCH…" ON THE PANIC SCREEN (board resident-app-06; M50 §4.2 #2): the resident and the control room write
 * to each other while the alert is open. Central, beside the alert it belongs to (`duress_alerts` is central: dispatch
 * reads every estate it covers). Nothing here is ever edited or deleted by either side — a message in an emergency is a
 * record, like the alert itself.
 *
 *   author        `resident` or `dispatch`
 *   client_key    the phone's own id for a message, so a message sent twice by a phone that lost its signal is one row
 *   seen_at       when the OTHER side was shown it: the console drew it, or the phone read it back — "delivered"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duress_alert_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('duress_alert_id')->constrained('duress_alerts')->cascadeOnDelete();
            $table->string('tenant_id', 64);
            $table->string('author', 16);
            $table->unsignedBigInteger('resident_account_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('author_name', 80)->nullable();
            $table->text('body');
            $table->string('client_key', 64)->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['duress_alert_id', 'client_key'], 'duress_alert_messages_client_key_unique');
            $table->index(['duress_alert_id', 'id'], 'duress_alert_messages_thread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duress_alert_messages');
    }
};
