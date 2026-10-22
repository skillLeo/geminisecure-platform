<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guard's standard hourly rate - CENTRAL, on `guards`.
 *
 * WHY THIS IS A SCHEMA CHANGE AND NOT A SCREEN PATCH.
 *
 * Board super-admin-22 draws a "Standard rate" field on the Add Guard form, and
 * the Build Spec's own description of that screen lists the field outright:
 * "Form: name, contact, employee number, TRN, NIS, PSRA licence and expiry,
 * base site, PAY RATE, start date." A rate typed into a form that stores it
 * nowhere is the worst of the three options - the operator believes they have
 * recorded an employment term, and nothing holds it.
 *
 * It is an EMPLOYMENT TERM, not a metric. That is the test the console applies
 * before adding a column: a number that only moves a figure on a card gets
 * derived, never stored, but what Gemini Security agreed to pay an employee per
 * hour is a fact about the employment, agreed once at hire, and there is nowhere
 * else in the schema it could live. `payslips` holds what was actually paid for
 * a period, which is the OUTCOME of the rate and cannot stand in for it: a
 * guard who worked no shifts has a rate and no payslip.
 *
 * MINOR UNITS AND AN EXPLICIT CURRENCY, like every other amount here. $425.00
 * is 42500 and 'JMD', never 425.0 - a float rate multiplied by hours is how a
 * payroll ends up a cent out per guard per fortnight and nobody can say why.
 *
 * NULLABLE, because the six seeded guards were hired before this column
 * existed and inventing a rate for them would put a number on the record that
 * no agreement stands behind. The Add Guard form requires one from today on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            $table->bigInteger('standard_rate_minor')->nullable()->after('employment_type');
            $table->char('standard_rate_currency', 3)->default('JMD')->after('standard_rate_minor');
        });
    }

    public function down(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            $table->dropColumn(['standard_rate_minor', 'standard_rate_currency']);
        });
    }
};
