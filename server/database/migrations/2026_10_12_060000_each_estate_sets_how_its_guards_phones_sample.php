<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * HOW OFTEN AN ON-DUTY OFFICER'S PHONE SENDS ITS POSITION, AND HOW LONG A SILENCE IS TOLERATED, ESTATE BY ESTATE
 * (Hassam, 12 October 2026: guard live tracking).
 *
 *   site_tracking_settings   one row per estate (a shift's tenant_id is its site): how often the phone sends while the
 *                            officer moves, while they stand still and below the low battery line; that line; and how
 *                            long an officer on duty may go without a position before the control room is told "No
 *                            signal" (no_signal_after_s). Each value is NULL until the estate chooses one, and NULL
 *                            means the platform's default in config/tracking.php, so a default moved later reaches
 *                            every estate that never chose. Written only from the estate console's Settings page,
 *                            each save audited; read only through App\Services\Tracking\SiteTrackingSettings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_tracking_settings', function (Blueprint $table): void {
            $table->string('tenant_id')->primary();
            $table->unsignedSmallInteger('moving_interval_s')->nullable();
            $table->unsignedSmallInteger('stationary_interval_s')->nullable();
            $table->unsignedTinyInteger('low_battery_pct')->nullable();
            $table->unsignedSmallInteger('low_battery_interval_s')->nullable();
            $table->unsignedSmallInteger('no_signal_after_s')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_tracking_settings');
    }
};
