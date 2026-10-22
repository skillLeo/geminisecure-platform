<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE PHONE IS ON A SITE'S EMERGENCY LIST ONCE (bug hunt B101).
 *
 * `site_emergency_numbers` was unique on (tenant_id, number) — on the string the office
 * TYPED. "1-876-508-8082" and "876 508 8082" are one phone, both resolve to the same
 * `e164`, and both rows were active: every panic texted the President twice (two
 * emergency texts read as two emergencies) and told the resident a fifth number was
 * tried. The key's own comment says it exists to stop a duplicate texting somebody
 * twice; keyed on the typed string, it did not.
 *
 * ONE ACTIVE ROW PER PHONE, PER SITE — on the diallable form. A generated column holds
 * `e164` while the row is active and NULL once it is retired, and the unique key is on
 * that: a retired row stays on file (nothing here is deleted — `alert_sms_dispatches`
 * copies what it texted, but the history of the list is kept too) without blocking the
 * phone's return, and an unroutable row (no `e164`) is never a duplicate of anything.
 *
 * AN EXISTING DUPLICATE IS RETIRED FIRST, not deleted: of the active rows sharing one
 * phone at one site, the one texted first (lowest `sort`, then the oldest) stays, and
 * the others are deactivated — which is what the office meant by typing it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seen = [];

        $active = DB::table('site_emergency_numbers')
            ->where('is_active', true)
            ->whereNotNull('e164')
            ->orderBy('tenant_id')->orderBy('e164')->orderBy('sort')->orderBy('id')
            ->get(['id', 'tenant_id', 'e164']);

        foreach ($active as $row) {
            $phone = $row->tenant_id."\n".$row->e164;

            if (isset($seen[$phone])) {
                DB::table('site_emergency_numbers')->where('id', $row->id)->update(['is_active' => false, 'updated_at' => Carbon::now()]);

                continue;
            }

            $seen[$phone] = true;
        }

        Schema::table('site_emergency_numbers', function (Blueprint $table) {
            $table->string('active_e164', 20)->nullable()->storedAs('IF(`is_active`, `e164`, NULL)');
            $table->unique(['tenant_id', 'active_e164'], 'site_emergency_numbers_one_active_phone');
        });
    }

    public function down(): void
    {
        Schema::table('site_emergency_numbers', function (Blueprint $table) {
            $table->dropUnique('site_emergency_numbers_one_active_phone');
            $table->dropColumn('active_e164');
        });
    }
};
