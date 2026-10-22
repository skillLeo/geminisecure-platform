<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A PANIC PRESS IS A PANIC, WHATEVER THE RESIDENT MANAGED TO LABEL IT (bug hunt B086).
 *
 * M10 §1.3: "let the resident pick if they can — medical, fire, security, other — but
 * never require it. An unlabelled panic goes to everyone exactly the same." The label
 * is stored as the alert's `kind` (`medical`, `fire`), which is what the responder
 * reads — and every urgency rule asked the kind: the broadcast's `is_urgent`, the
 * siren, the "Panic alerts" tab, the red row and the queue's order. A press labelled
 * "Medical" went out quiet, filed under Incidents, below every other panic.
 *
 * duress_alerts.from_panic_button   the alert came from the panic or duress BUTTON
 *                                   (`POST /api/v1/duress`, and `panic:walk`, which
 *                                   walks that path) rather than being reported as an
 *                                   incident on `POST /alerts`. Written on the first
 *                                   insert, so the broadcast that goes out from the
 *                                   `created` event already knows. Urgency asks this
 *                                   (`DuressAlert::isPanic()`), never the label.
 *
 * BACKFILLED FROM `mode`, which only the button path has ever written (silent or
 * audible; `panic:walk` and the demonstration seed write it too, for the same reason).
 * An open medical or fire press already on a queue at deploy is a panic from then on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duress_alerts', function (Blueprint $table) {
            $table->boolean('from_panic_button')->default(false)->after('kind');
        });

        DB::table('duress_alerts')->whereNotNull('mode')->update(['from_panic_button' => true]);
    }

    public function down(): void
    {
        Schema::table('duress_alerts', function (Blueprint $table) {
            $table->dropColumn('from_panic_button');
        });
    }
};
