<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arrears restriction - estate database only.
 *
 * The thresholds are ESTATE-CONFIGURABLE with the client's defaults (D-024):
 * 90 days overdue, 14 days' written notice, guest passes only.
 *
 * What is NOT configurable, and deliberately has no column here:
 *   - a resident's own entry is never restricted
 *   - emergency and medical vehicles are never restricted
 *
 * Those are hardcoded in App\Services\Restriction\RestrictionPolicy. Making
 * them settings would mean a misconfigured estate could leave an ambulance at
 * a gate, and no amount of UI copy makes that recoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estate_settings', function (Blueprint $table) {
            $table->id();

            // Days overdue before a household becomes ELIGIBLE for restriction.
            // Eligibility is not restriction: notice must run first.
            $table->unsignedSmallInteger('arrears_restriction_days')->default(90);

            // Written notice before an eligible restriction takes effect.
            $table->unsignedSmallInteger('arrears_notice_days')->default(14);

            // An estate may switch restriction off entirely; it may not widen
            // what restriction covers.
            $table->boolean('arrears_restriction_enabled')->default(true);

            $table->timestamps();
        });

        Schema::create('delinquency_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();

            /*
             * The lifecycle, in order. A household cannot jump to `restricted`
             * without a `notified_at` at least notice_days in the past.
             *
             * eligible | notified | restricted | lifted | cleared
             */
            $table->string('status', 24)->default('eligible');

            // The oldest unpaid charge that made this household eligible.
            $table->date('oldest_unpaid_due_on');
            $table->unsignedSmallInteger('days_overdue');

            $table->timestamp('notified_at')->nullable();
            $table->timestamp('restricted_at')->nullable();
            $table->timestamp('lifted_at')->nullable();

            /*
             * A Property Manager override, with its reason.
             *
             * The reason is NOT NULL when an override exists: an unexplained
             * lift on a money-adjacent control is exactly what an audit needs
             * to be able to question later.
             */
            $table->unsignedBigInteger('overridden_by')->nullable();
            $table->string('override_reason', 400)->nullable();

            $table->timestamps();

            $table->index(['status', 'days_overdue']);
            $table->index('household_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delinquency_flags');
        Schema::dropIfExists('estate_settings');
    }
};
