<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hardship and dispute — what the estate has agreed about a household (12 §1).
 *
 * THE RULING, exactly: "a reason and a committee minute reference, it suppresses
 * automated dunning, and it does not change collection behaviour." Every word of
 * that is load-bearing, and the second and third sentences are not in tension:
 *
 *   - the DEBT does not move. No journal is raised, no charge is reversed, no
 *     ageing bucket changes, and the arrears board goes on reporting the
 *     household among those who owe. A flag that quietly shrank a balance would
 *     be a write-off nobody voted for.
 *
 *   - what stops is the ESTATE'S OWN AUTOMATED CHASING. A household in front of
 *     the committee should not also be receiving a machine-generated final
 *     demand at 09:00 on Tuesday. A person may still send one deliberately —
 *     that is a decision with a name against it.
 *
 * NOT A COLUMN ON `units`. A flag is an episode with a beginning, a reason, a
 * minute number and an end, and next year's committee has to be able to read
 * why the estate stopped chasing this household in September. A boolean on the
 * unit would answer none of that, and would be overwritten by the next one.
 *
 * NOTHING HERE IS DELETED. A flag is lifted, and the lifted row stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_collection_flags', function (Blueprint $table) {
            $table->id();

            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            // hardship | dispute. Two different things: hardship is a household
            // that cannot pay, a dispute is one that says it should not have to.
            $table->string('kind', 16);

            /*
             * WHY, in the estate's own words, and the minute it was agreed at.
             * Both required: a flag with no reason is an unexplained silence in
             * the collection record, and one with no minute reference is an
             * office decision wearing a committee's clothes.
             */
            $table->string('reason', 500);
            $table->string('minute_reference', 80);

            $table->unsignedBigInteger('raised_by_id')->nullable();
            $table->string('raised_by_name', 120);
            $table->timestamp('raised_at');

            $table->timestamp('lifted_at')->nullable();
            $table->string('lifted_by_name', 120)->nullable();
            $table->string('lifted_reason', 500)->nullable();

            $table->timestamps();

            // The one query that matters: is this unit flagged right now.
            $table->index(['unit_id', 'lifted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_collection_flags');
    }
};
