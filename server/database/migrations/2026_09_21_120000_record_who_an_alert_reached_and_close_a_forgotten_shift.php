<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6 §2.1 — panic when nobody is clocked on. Additive only; no table is created, so
 * the grant commands need no re-run (`duress_alerts` and `shifts` are already
 * declared CENTRAL_MUTABLE in `App\Support\GrantedTables`).
 *
 * duress_alerts.alerted_*    WHAT THE ALERT ACTUALLY REACHED, written the moment the
 *                            fan-out finishes and never afterwards. `alerted_handsets`
 *                            is the number of security HANDSETS the alert was queued
 *                            to; `alerted_via` is which tier answered — `on_duty`,
 *                            `rostered`, `escalation` or `none`; `escalated_contacts`
 *                            is how many people were told off-app when no handset was
 *                            reached at all.
 *
 *                            It exists so the RESIDENT can be told the truth. Before
 *                            this the panic screen said "Alert sent to gatehouse &
 *                            dispatch" whether or not a single phone had rung, and a
 *                            panic that silently reaches nobody is the worst failure
 *                            this product can have (M6 §2.1 part 5).
 *
 *                            NULL means the fan-out has not finished (or predates this
 *                            migration) — deliberately distinct from 0, which is the
 *                            positive statement "no handset". The apps must not read a
 *                            null as a zero, and `DuressController::shape()` sends the
 *                            null through rather than guessing.
 *
 * shifts.auto_closed_*       A shift the platform closed itself because nobody clocked
 *                            out (M6 §2.1 part 4). `auto_closed_at` is when the closure
 *                            was made; `auto_close_hours` is the number of hours past
 *                            the rostered end that was in force AT THAT MOMENT, kept on
 *                            the row so a later settings change cannot rewrite what
 *                            happened. Null on every shift a person ended.
 *
 *                            The flag is what makes the closure an ATTENDANCE EXCEPTION
 *                            rather than a silent correction: the Head of Security's
 *                            bell derives its item from this column, exactly as it does
 *                            for `overridden_at`, and reading it does not clear it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duress_alerts', function (Blueprint $table) {
            $table->unsignedSmallInteger('alerted_handsets')->nullable()->after('is_simulated');
            $table->string('alerted_via', 16)->nullable()->after('alerted_handsets');
            $table->unsignedSmallInteger('escalated_contacts')->nullable()->after('alerted_via');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('auto_closed_at')->nullable();
            $table->unsignedTinyInteger('auto_close_hours')->nullable();

            $table->index(['auto_closed_at'], 'shifts_auto_closed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex('shifts_auto_closed_at_index');
            $table->dropColumn(['auto_closed_at', 'auto_close_hours']);
        });

        Schema::table('duress_alerts', function (Blueprint $table) {
            $table->dropColumn(['alerted_handsets', 'alerted_via', 'escalated_contacts']);
        });
    }
};
