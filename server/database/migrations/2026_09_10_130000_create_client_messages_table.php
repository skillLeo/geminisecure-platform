<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Gemini Security said to a client, and when.
 *
 * A security company tells a committee things that matter: a guard suspended
 * for a lapsed licence, an invoice falling due, an incident overnight. Those
 * conversations decide what a client believes about their own estate, and a
 * conversation nobody can produce afterwards is the one that turns into a
 * dispute.
 *
 * CENTRAL, NOT PER-ESTATE, and that is the whole design. The message travels
 * FROM the platform TO one estate, so it belongs to neither database alone —
 * but a Gemini operator has to be able to see what was sent to every client
 * from one screen, and that is impossible if each message lives inside the
 * estate it was sent to. The Estate Console reads its own by tenant_id.
 *
 * `read_at` is a fact about the recipient, not a flag the sender sets. It
 * answers the only question that matters when a client says they were never
 * told.
 *
 * There is no delete. A sent message is a posted record: it has left, the
 * recipient has it, and removing this row would only remove our copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_messages', function (Blueprint $table) {
            $table->id();

            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            /*
             * Who it was addressed to. Nullable because a committee changes:
             * the person is deleted, the message is not, and a message whose
             * recipient row is gone still has to say what was sent and to
             * which estate.
             */
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_name', 160);
            $table->string('recipient_role', 120)->nullable();

            // general | billing | compliance — the board's three segments.
            $table->string('category', 24)->default('general');

            $table->string('subject', 200);
            $table->text('body');

            /*
             * Who sent it, kept by name as well as by id. An operator can
             * leave the company; the record of what they told a client on
             * behalf of it cannot leave with them.
             */
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_by_name', 160);

            $table->timestamp('sent_at');
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_messages');
    }
};
