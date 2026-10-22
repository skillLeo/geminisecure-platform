<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove `estate_settings.security_payroll_routing` (M10 §9).
 *
 * The estate's settings screen drew two payroll routings side by side: its own
 * staff's, which it may set, and the guards', which it may not. The second was
 * rendered as a locked segmented control with a paragraph explaining why it
 * could not be moved — guards are Gemini Security Limited's employees, paid by
 * Gemini, with their statutory deductions filed under Gemini's TRN.
 *
 * The client has removed it. The reasoning is that a locked control is still a
 * question: it puts the choice in front of a committee, invites them to ask why
 * they cannot make it, and has to be answered on every estate's settings screen
 * for ever. The estate has no say in how somebody else's employees are paid, so
 * the choice does not belong there at all.
 *
 * THE COLUMN NEVER HELD ANYTHING ELSE. It defaulted to `gemini_managed`, the
 * service refused every attempt to change it by name, and `EstateSettingsTest`
 * proved that refusal. So there is no estate anywhere whose value differs from
 * the default, and nothing downstream reads it: the guards' pay is computed in
 * the Gemini console from `guards` and posts to Gemini's own ledger, never to an
 * estate's.
 *
 * `down()` restores it with the same default, so an estate rolled back is in the
 * state it was always in rather than a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('estate_settings', 'security_payroll_routing')) {
            return;
        }

        Schema::table('estate_settings', function (Blueprint $table): void {
            $table->dropColumn('security_payroll_routing');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('estate_settings', 'security_payroll_routing')) {
            return;
        }

        Schema::table('estate_settings', function (Blueprint $table): void {
            $table->string('security_payroll_routing', 24)
                ->default('gemini_managed')
                ->after('staff_payroll_routing');
        });
    }
};
