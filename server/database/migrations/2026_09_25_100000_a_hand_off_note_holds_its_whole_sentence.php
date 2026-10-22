<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A HAND-OFF NOTE HOLDS ITS WHOLE SENTENCE (bug hunt B091).
 *
 * `duress_alert_deliveries.note` was 240 characters. The Heads of Security's own
 * sentence — one texted, one not texted twice because the site's number is the same
 * phone, no provider connected — is 255, and 317 with a head who gave no phone. MySQL
 * runs strict, so the ONE insert that writes every leg of an alert was refused, the
 * error was reported and swallowed, and the dispatcher's screen and `panic:walk` said
 * "No hand-off was recorded for this alert" for all seven of §1.2's destinations.
 *
 * 1000, which is four times the longest sentence `PanicDeliveries` can write today and
 * what it now trims to (`DuressAlertDelivery::NOTE_LENGTH`), so the column can never be
 * the reason a record is lost. A widening only: every row already written fits, the
 * append-only triggers and the withheld grant are untouched — an ALTER is not a row
 * UPDATE and fires neither trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duress_alert_deliveries', function (Blueprint $table) {
            $table->string('note', 1000)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing back would refuse, or cut, any note longer than 240 already written.
        // Deliberately left wide: the record of who was told is not shortened on rollback.
    }
};
