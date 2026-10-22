<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an estate is offered in the Resident App's pre-sign-in pick list (ESTATES-01, M15 §7.1).
 *
 * The list is shown BEFORE anyone has an account, so it cannot be filtered by "estates you belong to":
 * there is no identity yet. Until now it offered every ACTIVE estate, which meant two internal tenants —
 * a bare "Demo estate" and a developer's "SkillLeo Estats" — reached a real resident's screen.
 *
 * `listed` decides it, and DEFAULTS TRUE on purpose: a real onboarded estate must never be hidden by an
 * oversight. And an estate left off the list is not a resident locked out — the app keeps "type a community
 * code" as the fallback for exactly this — so the cost of excluding one wrongly is small, and the cost of
 * showing a test estate to a client is an embarrassment in front of Omar. Only internal or test tenants are
 * set false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('listed')->default(true)->after('demo');
        });

        /*
         * The two internal tenants on THIS platform that were reaching the public list. Guarded by exact id,
         * so this is a no-op on any environment that does not have them — the client's own future platform will
         * not. The one deliberately labelled demonstration estate (`demoestate`, "Seaview Demo Estate
         * (demonstration data)") stays listed: the Resident App only offers "Explore a demo community" when the
         * list holds a demonstration estate, so hiding it would kill the reviewers' and the presenter's way in.
         */
        DB::table('tenants')->whereIn('id', ['demoestate2', 'skillleoestats'])->update(['listed' => false]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('listed');
        });
    }
};
