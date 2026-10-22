<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing orders - CENTRAL, in gs_platform.
 *
 * WHY THIS IS A SCHEMA CHANGE AND NOT A SCREEN PATCH.
 *
 * The Build Spec's entity list has no standing-order entity, but it specifies
 * the thing from three sides: board 25 is "the per-post instruction set guards
 * must follow, versioned and acknowledged", Guard App screen 11 is a guard
 * reading and acknowledging the orders for their current post, and screen 16
 * is "the record of standing orders read and acknowledged". A written order
 * set with a version and an acknowledgement trail is the entity all three are
 * describing, and there is nowhere in the schema it could otherwise live.
 *
 * It is CENTRAL because a standing order is Gemini Security Limited
 * instructing its own employee. The company-wide sets attach to no estate at
 * all; a post-specific set attaches to a `posts` row, and `posts` is already
 * central for exactly this reason - a guard is posted AT an estate, not
 * employed by one. Nothing here touches an estate database and nothing here
 * holds a resident, a household or an amount.
 *
 * THE ACKNOWLEDGEMENT CARRIES THE VERSION IT WAS GIVEN FOR. The spec's rule is
 * that "a revision requires fresh acknowledgement", and the only way a stored
 * acknowledgement can honour that is to record WHICH text was agreed to.
 * Storing a boolean on the set, or a nullable acknowledged_at, would leave a
 * guard's tick from version 2 still showing green under version 3 - the exact
 * failure the rule exists to prevent, and one that looks correct on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standing_order_sets', function (Blueprint $table) {
            $table->id();

            $table->string('title', 140);

            /*
             * general | emergency | post_specific.
             *
             * Not a boolean "is company-wide": the emergency annex is
             * company-wide too and is a different document with a different
             * reason for existing, and the library lists the three in that
             * order because that is the order a guard is inducted through them.
             */
            $table->string('category', 24);

            // The document's own one-line description - "Applied to every post
            // at every client", "Fire, medical, duress protocols". Written by
            // whoever issues the orders, not derived from the category, because
            // two company-wide sets do not say the same thing about themselves.
            $table->string('summary', 190);

            /*
             * The post these orders attach to, and the estate it stands at.
             * Both null on a company-wide set, and the pair is the difference
             * between "every guard reads this" and "whoever stands this gate
             * reads this".
             */
            $table->string('tenant_id')->nullable();
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();

            /*
             * The version in force. Revisions are published by raising this
             * and rewriting the body; the acknowledgement table below is what
             * remembers who has agreed to which one.
             */
            $table->unsignedSmallInteger('version')->default(1);

            $table->text('body');

            $table->date('effective_on');

            // When a human last read this through and confirmed it still says
            // the right thing. Distinct from effective_on: an order set can
            // stand unchanged for a year and still be reviewed each quarter.
            $table->date('reviewed_on')->nullable();

            $table->timestamps();

            $table->index(['category', 'tenant_id']);
            $table->index('post_id');
        });

        Schema::create('standing_order_acknowledgements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('standing_order_set_id')
                ->constrained('standing_order_sets')
                ->cascadeOnDelete();

            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();

            // The version this guard actually agreed to.
            $table->unsignedSmallInteger('version');

            $table->timestamp('acknowledged_at');

            $table->timestamps();

            /*
             * One acknowledgement per guard per version, enforced by the
             * database rather than remembered by the application. A guard who
             * opens the same orders twice has not acknowledged them twice, and
             * a duplicate row would make the acknowledgement rate on the
             * library screen read above 100%.
             */
            $table->unique(['standing_order_set_id', 'guard_id', 'version'], 'ack_once_per_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standing_order_acknowledgements');
        Schema::dropIfExists('standing_order_sets');
    }
};
