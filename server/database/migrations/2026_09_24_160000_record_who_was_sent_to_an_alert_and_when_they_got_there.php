<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO WAS SENT TO AN ALERT, AND WHEN THEY GOT THERE (M10 §1.3).
 *
 * "The resident's screen still shows the honest state: alerting → acknowledged →
 * dispatched → en route → on scene → resolved." The row could say three of those six.
 * `status` listed `responding` and nothing ever wrote it, and there was no column at
 * all for an officer being SENT, being ON THE WAY or being THERE — so the resident's
 * panic screen could not draw those steps honestly, and deliberately did not draw
 * them. The dispatcher's own timeline drew "<guard> has not yet confirmed response",
 * a step no action anywhere could complete.
 *
 * THREE MOMENTS AND WHO MADE EACH ONE:
 *
 *   dispatched_at, dispatched_by    the control room sent an officer; the console user
 *                                   who did it (a foreign key, like `acknowledged_by`)
 *   responding_guard_id             the officer sent — or, when nobody was sent, the
 *                                   officer on shift who took it from their own handset
 *   en_route_at, en_route_recorded_by
 *   on_scene_at, on_scene_recorded_by
 *                                   when the officer was on the way, and was there. The
 *                                   `_recorded_by` is the CONSOLE USER who recorded it
 *                                   on the officer's behalf after a radio call, and NULL
 *                                   when the officer's own handset said so.
 *
 * THE TWO `_recorded_by` COLUMNS ARE NOT FOREIGN KEYS, AND THAT IS THE POINT. Null in
 * them MEANS "from the officer's handset". A `nullOnDelete` key would quietly turn
 * "a dispatcher recorded this from a radio call" into "the officer's phone said so"
 * the day an account was removed — rewriting who made a claim about a life-safety
 * response. A bare id cannot be rewritten by anything but a deliberate update.
 *
 * NULL IS "NOT RECORDED", NEVER "SKIPPED OVER". An officer who ran straight to the lot
 * may have an `on_scene_at` and no `en_route_at`, and that is what the row says:
 * nothing is back-filled, because a time nobody recorded is not a time.
 *
 * THE HISTORY IS THE AUDIT LOG'S. These columns hold where the response stands; every
 * transition — actor, before, after and when — is an `alert.dispatched`,
 * `alert.en_route` or `alert.on_scene` row in `audit_log`, which is append-only.
 *
 * Central, on `duress_alerts`, which is CENTRAL_MUTABLE (`App\Support\GrantedTables`)
 * precisely because an alert is worked after it is raised. No new table, so no grant
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('duress_alerts', 'dispatched_at')) {
            return;
        }

        Schema::table('duress_alerts', function (Blueprint $table): void {
            $table->timestamp('dispatched_at')->nullable()->after('acknowledged_at');
            $table->foreignId('dispatched_by')->nullable()->after('dispatched_at')->constrained('users')->nullOnDelete();
            $table->foreignId('responding_guard_id')->nullable()->after('dispatched_by')->constrained('guards')->nullOnDelete();

            $table->timestamp('en_route_at')->nullable()->after('responding_guard_id');
            $table->unsignedBigInteger('en_route_recorded_by')->nullable()->after('en_route_at');

            $table->timestamp('on_scene_at')->nullable()->after('en_route_recorded_by');
            $table->unsignedBigInteger('on_scene_recorded_by')->nullable()->after('on_scene_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('duress_alerts', 'dispatched_at')) {
            return;
        }

        Schema::table('duress_alerts', function (Blueprint $table): void {
            $table->dropForeign(['dispatched_by']);
            $table->dropForeign(['responding_guard_id']);
            $table->dropColumn([
                'dispatched_at', 'dispatched_by', 'responding_guard_id',
                'en_route_at', 'en_route_recorded_by',
                'on_scene_at', 'on_scene_recorded_by',
            ]);
        });
    }
};
