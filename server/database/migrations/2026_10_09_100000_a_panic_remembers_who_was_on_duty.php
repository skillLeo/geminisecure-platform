<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `duress_alerts.on_duty_officers` — how many officers were clocked on at the site when the alert was raised (M18A §1.2).
 *
 * The resident's panic screen has to tell two situations apart, because they call for different words: nobody on duty
 * at the community, and officers on duty whose phones could not be reached. `alerted_handsets` = 0 is both of them.
 * The fan-out already knows which (`PanicFanOut::forAlert` asks for the guards clocked on before it pushes); it did not
 * write it down. This is that one number, written with the reach, never updated after: it is the answer AT THE RAISE.
 *
 * Nullable: every alert already in the table predates it, and the phone reads null as "not known" and claims neither.
 * Central, on `duress_alerts`, which is CENTRAL_MUTABLE — the same write as `alerted_handsets`, so no grant changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('duress_alerts', 'on_duty_officers')) {
            return;
        }

        Schema::table('duress_alerts', function (Blueprint $table): void {
            $table->unsignedInteger('on_duty_officers')->nullable()->after('escalated_contacts');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('duress_alerts', 'on_duty_officers')) {
            return;
        }

        Schema::table('duress_alerts', function (Blueprint $table): void {
            $table->dropColumn('on_duty_officers');
        });
    }
};
