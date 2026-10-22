<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every version of an order set, as it was published — CENTRAL (12 §2, item 28).
 *
 * AN ACKNOWLEDGEMENT IS ONLY WORTH THE TEXT IT WAS GIVEN FOR. The set row holds
 * the version in force and its body is rewritten on revision, so until now a
 * guard's acknowledgement of version 2 pointed at words nobody could read any
 * more. A dispute about what a guard was instructed to do on a given night needs
 * the instruction, not only its number. So each published version is kept here,
 * whole, and never updated.
 *
 * BACKFILLED from the sets already in force: their current text becomes their
 * current version's row. Earlier versions of those sets were never kept, and
 * nothing here pretends otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standing_order_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('standing_order_set_id')->constrained('standing_order_sets')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');

            $table->string('title', 140);
            $table->string('summary', 190);
            $table->text('body');
            $table->date('effective_on');

            // What changed, in the issuer's words. Null on a first version.
            $table->string('change_note', 300)->nullable();

            $table->unsignedBigInteger('published_by')->nullable();
            $table->string('published_by_name', 160)->nullable();

            $table->timestamps();

            $table->unique(['standing_order_set_id', 'version'], 'order_version_once');
        });

        $now = now();

        foreach (DB::table('standing_order_sets')->get() as $set) {
            DB::table('standing_order_versions')->insert([
                'standing_order_set_id' => $set->id,
                'version' => $set->version,
                'title' => $set->title,
                'summary' => $set->summary,
                'body' => $set->body,
                'effective_on' => $set->effective_on,
                'change_note' => null,
                'published_by' => null,
                'published_by_name' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('standing_order_versions');
    }
};
