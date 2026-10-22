<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An estate is a tenant. The tenant id IS the subdomain, so stancl's
 * `prefix + id` yields exactly `gs_estate_phoenixpark`.
 *
 * Tenant resolution is by subdomain only. It is never read from a request
 * parameter, hidden field or query string — that rule is absolute and is
 * asserted by a test rather than left to review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Display name, e.g. "Phoenix Park". The id carries the subdomain.
            $table->string('name')->after('id');

            // active | onboarding | dunning | suspended
            // Access is never withheld over a billing dispute, so `dunning`
            // and `suspended` gate billing features, never entry or safety.
            $table->string('status', 24)->default('onboarding')->after('name');

            $table->timestamp('provisioned_at')->nullable()->after('status');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['name', 'status', 'provisioned_at']);
        });
    }
};
