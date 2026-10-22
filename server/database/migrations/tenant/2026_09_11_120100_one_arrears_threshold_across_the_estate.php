<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One arrears threshold across the estate, not two — the ruling on Q-008.
 *
 * The amenity module carried its OWN pair of settings:
 * `amenity_arrears_block_enabled`, defaulting to false, and
 * `amenity_arrears_block_days`, defaulting to 90 beside the gate's own 90. That
 * was the cautious reading of a question nobody had answered, and the client
 * answered it the other way: a household is either in arrears or it is not.
 *
 * WHAT THE TWO COLUMNS MADE POSSIBLE was an estate admitting a household's
 * visitors at the gate on Friday and refusing the household itself the Club
 * House on Saturday — or the reverse — with nothing anywhere saying the two
 * rules had drifted. Two numbers that must always agree should not be two
 * numbers.
 *
 * So they are dropped and `Amenities::mayBook()` reads
 * `arrears_restriction_enabled` and `arrears_restriction_days`, which is the
 * single threshold the gate already uses and which an estate configures in one
 * place. Moving it moves both.
 *
 * A CONSEQUENCE WORTH STATING PLAINLY: the block was OFF by default and the gate
 * restriction is ON, so this ruling switches amenity blocking on for every
 * estate. That is what "one threshold" means, and it is the client's decision to
 * take. An active payment plan lifts it exactly as it lifts the gate — see
 * `Amenities::mayBook()`, which calls `Collections::isProtected()` rather than
 * reimplementing the shield.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estate_settings', function (Blueprint $table): void {
            $table->dropColumn(['amenity_arrears_block_enabled', 'amenity_arrears_block_days']);
        });
    }

    public function down(): void
    {
        Schema::table('estate_settings', function (Blueprint $table): void {
            // Restored at the values they shipped with, so a rollback returns
            // the estate to the cautious default rather than to a blocking one.
            $table->boolean('amenity_arrears_block_enabled')->default(false);
            $table->unsignedSmallInteger('amenity_arrears_block_days')->default(90);
        });
    }
};
