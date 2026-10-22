<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estate notices and who has read them — board 32.
 *
 * TWO TABLES, BECAUSE THE SEEN PERCENTAGE IS THE POINT OF THE SCREEN. Board 32
 * draws a filled bar against every notice — 47%, 71%, 88% — and that figure is
 * the whole reason a committee posts one and comes back to look. It is a COUNT
 * of receipts over the size of the audience, and neither half can be stored on
 * the notice: a stored numerator goes stale the moment somebody else opens it,
 * and a stored denominator goes stale the moment a household moves in.
 *
 * `notice_reads` IS ONE ROW PER RESIDENT PER NOTICE, with a unique key across
 * the pair. That is what makes the numerator a fact rather than a counter: a
 * resident who opens the same notice four times is one person who has read it,
 * and a counter incremented on each view would show 130% seen and be believed.
 *
 * THE BYLINE CARRIES BOTH A PERSON AND A ROLE, and board 32 is why. Its first
 * notice is posted by "Property Manager" and its second and third by "Delroy
 * Samuels" and "Patricia Morgan" — a role on one and a name on the others. That
 * is not an inconsistency to normalise away: a gate closure is announced by the
 * OFFICE, because whoever is managing the estate next week owns it too, while an
 * AGM notice is signed by the Secretary personally. So `author_name` records who
 * actually posted it, always, and `posted_as_role` optionally overrides what the
 * estate is shown — and the audit trail keeps the person either way.
 *
 * AUDIENCE IS A SCOPE, NOT A LIST. The composer offers "Phase 3 only", so a
 * notice is addressed to everybody in a phase rather than to a set of people
 * chosen at the time. Storing the resolved list would freeze the audience at the
 * moment of posting, and a household that moved in yesterday would never be told
 * about the water going off tomorrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notices', function (Blueprint $table) {
            $table->id();

            /*
             * "Urgent" or "General". Board 32 draws them as two different pills
             * and the notification defaults on board 30 treat them alike, so
             * this is presentation and priority rather than a channel decision —
             * for now. Kept as a string rather than a boolean because a third
             * kind is the sort of thing an estate asks for.
             */
            $table->string('kind', 16)->default('general');

            $table->string('title', 190);
            $table->text('body');

            /*
             * Estate-wide, or one phase. Null is estate-wide — the notification
             * default board 30 names is "Estate-wide announcements from
             * Governance", so that is the ordinary case and a phase is the
             * narrowing.
             */
            $table->string('audience_scope', 24)->default('estate');
            $table->string('audience_phase', 40)->nullable();

            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name', 160);

            /*
             * What the estate is shown instead of the author's name, where the
             * notice is the office's rather than the person's. Null means the
             * person is named.
             */
            $table->string('posted_as_role', 60)->nullable();

            /*
             * Null until it is published. A draft is not a notice anybody has
             * been told about, and the list draws published ones only — so this
             * column is what separates "written" from "sent".
             */
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['published_at', 'id'], 'notices_published_index');
        });

        Schema::create('notice_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->timestamp('read_at');

            /*
             * ONE ROW PER RESIDENT PER NOTICE. Without this a resident who opens
             * the same notice four times counts four times, and the bar on board
             * 32 reads over 100% — a figure a committee would notice and stop
             * trusting the screen over.
             */
            $table->unique(['notice_id', 'resident_id'], 'notice_reads_once_each');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reads');
        Schema::dropIfExists('notices');
    }
};
