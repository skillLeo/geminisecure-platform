<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dunning step is drafted, and the committee puts it in force (12 §1).
 *
 * THE RULING: "a new or edited stage saves as a draft, needs a committee
 * resolution reference, and sent notices are never altered." The third of those
 * is already structural — `dunning_notices` stores the subject and body AS
 * SENT, and nothing in this migration or the editor above it touches that
 * table. The first two are what this adds.
 *
 * A SEPARATE TABLE, NOT A STATUS COLUMN ON THE LIVE ROW. What `dunning_templates`
 * holds is what the estate is sending TODAY, and the collections run reads it
 * without asking any further question. Putting a draft in that table behind a
 * status flag would mean every read of the ladder — the run, the board, a future
 * report — has to remember to exclude it, and the one that forgets sends a
 * resident wording no committee ever agreed. A draft lives beside the ladder
 * instead, and reaches it only when it is adopted.
 *
 * `dunning_template_id` IS NULLABLE, and null is the interesting case: a brand
 * new step that the estate does not yet have. Adopting one creates the live row;
 * adopting a draft against an existing step overwrites that row's wording.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_template_drafts', function (Blueprint $table) {
            $table->id();

            // Null for a step the estate does not have yet.
            $table->foreignId('dunning_template_id')->nullable()->constrained('dunning_templates')->cascadeOnDelete();

            $table->string('label', 80);
            $table->unsignedSmallInteger('stage');
            $table->string('channel', 16);
            $table->string('subject', 190);
            $table->text('body');
            $table->unsignedSmallInteger('days_overdue')->default(0);

            /*
             * THE COMMITTEE'S OWN REFERENCE — a minute number, a resolution
             * number, whatever the estate's own minutes call it. Nullable
             * because a draft is saved before the committee has met; NOT NULL
             * is enforced at adoption, where the ruling puts it. A free string
             * rather than a foreign key: the resolution lives in the estate's
             * minute book, and a system that demanded a row for it would make
             * the paper record unusable.
             */
            $table->string('resolution_reference', 80)->nullable();

            $table->unsignedBigInteger('drafted_by_id')->nullable();
            $table->string('drafted_by_name', 120);

            $table->timestamp('adopted_at')->nullable();
            $table->string('adopted_by_name', 120)->nullable();

            $table->timestamps();

            $table->index(['adopted_at', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_template_drafts');
    }
};
