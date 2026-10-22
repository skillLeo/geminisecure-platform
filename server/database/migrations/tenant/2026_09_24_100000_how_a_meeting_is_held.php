<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `meetings.held_as` — how a meeting is actually held (M10 §8).
 *
 * The table has carried `venue` and `virtual_link` since it was created, and
 * board 12's form drew NEITHER. So a secretary could schedule an AGM and no
 * household could be told where to go or what to click: the notice went out with
 * a date, a time and an audience, and nothing about the place.
 *
 * What was missing underneath was the question those two columns answer between
 * them. A venue and a link are not alternatives the form can infer from — a
 * meeting with only a venue is in person, one with only a link is online, and
 * one with both is hybrid, which is a real and common arrangement rather than an
 * accident. Storing the intent explicitly means the resident's screen can say
 * "Zoom" rather than guessing it from a URL, and a meeting can be moved online
 * without its old venue reading as a place to turn up to.
 *
 * FIVE VALUES, WHICH ARE THE CLIENT'S OWN LIST: in person, Zoom, Google Meet,
 * Microsoft Teams, or other. Hybrid is NOT a sixth: it is any online mode that
 * also carries a venue, so the two facts stay independent and the form does not
 * have to keep a sixth option in step with the two fields it governs. D-135.
 *
 * `in_person` is the default, because that is what every meeting already in the
 * table was: none of them has a `virtual_link`, since no form could set one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('meetings', 'held_as')) {
            return;
        }

        Schema::table('meetings', function (Blueprint $table): void {
            $table->string('held_as', 16)->default('in_person')->after('starts_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('meetings', 'held_as')) {
            return;
        }

        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropColumn('held_as');
        });
    }
};
