<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `duress_alerts.fanout_ms` — how long the fan-out took (M10 §1).
 *
 * The change set asks for delivery time to each destination to be measured and
 * recorded, against the PRD's target of under three seconds. Nothing measured
 * anything: the only timing fact on an alert was `acknowledged_at`, which is how
 * long a HUMAN took to answer, not how long the platform took to tell them.
 *
 * WHAT THIS HONESTLY MEASURES, and it is worth being exact because the number
 * will be quoted. It is the wall time from the alert row being committed to the
 * fan-out returning: every push queued, every mail queued, the escalation
 * contacts resolved and the row updated. It is the platform's own share.
 *
 * IT IS NOT DELIVERY TO A PHONE, and must never be presented as if it were.
 * Expo, the carrier, the mail server and the handset are all downstream, none of
 * them reports back synchronously, and a number that claimed otherwise would be
 * the same class of false statement as `alerted_handsets` counting a person
 * twice. `CheckExpoPushReceipts` is what learns, later, whether Expo accepted a
 * batch; this column is not that.
 *
 * `unsignedInteger` and nullable. Nullable because every alert already in the
 * table predates it, and because a fan-out that threw before the timer stopped
 * should leave no figure at all rather than a misleading one — the whole point
 * is a number that can be trusted.
 *
 * Central, on `duress_alerts`, which is CENTRAL_MUTABLE — the same table and the
 * same write as `alerted_handsets`, so no grant changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('duress_alerts', 'fanout_ms')) {
            return;
        }

        Schema::table('duress_alerts', function (Blueprint $table): void {
            $table->unsignedInteger('fanout_ms')->nullable()->after('escalated_contacts');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('duress_alerts', 'fanout_ms')) {
            return;
        }

        Schema::table('duress_alerts', function (Blueprint $table): void {
            $table->dropColumn('fanout_ms');
        });
    }
};
