<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a client is committed for.
 *
 * The activate-plan board asks for a contract term alongside the tier and the
 * unit count, and it is a commercial fact rather than a form field: a
 * twenty-four month term is what a discount is priced against, what a renewal
 * date is derived from, and what someone has to be told before they cancel.
 *
 * `renews_on` already exists and is the NEXT renewal — a moving date that is
 * recomputed each cycle. The term is the length of the commitment behind it,
 * and neither can be derived from the other: a monthly rolling client renews
 * every month with no term at all, which is why this is nullable and means
 * exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('term_months')->nullable()->after('renews_on');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('term_months');
        });
    }
};
