<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT A GUARD CAN CHECK THEIR OWN WAGE AGAINST (M8 §5.3).
 *
 * The payslip held a gross, five deductions, a net and the employer's four contributions. Every
 * figure was right and none of them could be CHECKED: an officer who worked forty-eight hours with
 * six of them at overtime had no way to see either number, the rate each was paid at, or what each
 * deduction was actually taken on. The client put it plainly — "overtime, their payments, how it is
 * calculated — all of that is on those screens".
 *
 * So the slip now carries the arithmetic as well as the answer:
 *
 *   the hours        regular and overtime, kept apart, each with the rate it was paid at
 *   the earnings     basic, overtime and allowances — which add up to the gross that was already here
 *   the bases        what each statutory deduction was calculated ON, which is not the same figure
 *                    for any two of them: NIS on gross capped at the period's ceiling, Education Tax
 *                    and PAYE on statutory income (gross less NIS less an approved pension), NHT on
 *                    gross uncapped. A slip that showed a rate without its base would let an officer
 *                    "check" 3% of the wrong number and conclude the platform is wrong.
 *
 * EVERY COLUMN IS NULLABLE, and that is deliberate rather than lazy: a payslip written before this
 * migration cannot have its hours invented now, and a screen that drew a confident 0.00 hours over a
 * real wage would be worse than one that says the breakdown is not on record for that period.
 *
 * Money stays integer minor units; hours are the one decimal here, at two places, because a quarter
 * of an hour is a real thing on a roster and 0.25 is exact in DECIMAL where it is not in a float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            // The hours, and what each was paid at.
            $table->decimal('regular_hours', 8, 2)->nullable()->after('tenant_id');
            $table->decimal('overtime_hours', 8, 2)->nullable()->after('regular_hours');
            $table->bigInteger('regular_rate_minor')->nullable()->after('overtime_hours');
            $table->bigInteger('overtime_rate_minor')->nullable()->after('regular_rate_minor');

            // The earnings that make up the gross already stored beside them.
            $table->bigInteger('basic_minor')->nullable()->after('overtime_rate_minor');
            $table->bigInteger('overtime_minor')->nullable()->after('basic_minor');
            $table->bigInteger('allowances_minor')->nullable()->after('overtime_minor');

            // What each statutory deduction was taken ON. Four different figures, deliberately.
            $table->bigInteger('nis_base_minor')->nullable()->after('nis_minor');
            $table->bigInteger('nht_base_minor')->nullable()->after('nht_minor');
            $table->bigInteger('education_tax_base_minor')->nullable()->after('education_tax_minor');
            $table->bigInteger('paye_base_minor')->nullable()->after('paye_minor');
            $table->bigInteger('paye_threshold_minor')->nullable()->after('paye_base_minor');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table): void {
            $table->dropColumn([
                'regular_hours', 'overtime_hours', 'regular_rate_minor', 'overtime_rate_minor',
                'basic_minor', 'overtime_minor', 'allowances_minor',
                'nis_base_minor', 'nht_base_minor', 'education_tax_base_minor', 'paye_base_minor', 'paye_threshold_minor',
            ]);
        });
    }
};
