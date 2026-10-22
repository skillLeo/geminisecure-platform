<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * HOW THE SYSTEM KNOWS A GUARD IS AT THE ESTATE (M12 §1).
 *
 * A guard pressed Clock in and was told he was not in the estate's area, and nobody could say how that was decided.
 * It was one GPS fix against the post's circle, with the fix's own accuracy thrown away, a post with no position
 * waved through as if it had been checked, and nothing kept of what the check saw. So:
 *
 *   estate_sites        WHERE THE ESTATE ITSELF IS — its own centre and radius, the fence a clock-in falls back to
 *                       when the rostered post has none (and the audit entry says so) — and the number a guard at
 *                       the gate calls for a supervisor. Placed only in the Gemini console's Sites & geofences, like
 *                       every post; never invented to fill a gap.
 *
 *   shifts.clock_in_*   the fix the start was judged on: where, how sure the phone was, WHICH fence (the post's,
 *                       the estate's, or none configured), its radius at that moment, and what the judgement was.
 *                       The position of a start is duty data, kept with the shift it belongs to.
 *   clock_in_device_at  the phone's own time of the tap, as sent; `actual_start` is that time on the SERVER's
 *                       clock (corrected by the offset between the two on the request that brought it), so a
 *                       clock-in queued with no signal starts when the guard tapped, not when the signal came back.
 *   clock_offset_s      that offset. Past the tolerance the phone's clock is wrong, and the shift says so.
 *   review_reasons      what a supervisor must look at: a mocked location, a start judged outside after it was
 *                       queued, no geofence configured, a phone clock that disagrees or was changed while the act
 *                       waited. Null when there is nothing.
 *
 *   posts.geofence_radius_m   at least 10 m. Five was the column's default and the editor's floor, and no phone's
 *                       fix can put anybody inside a five-metre circle; the posts at five are raised to ten, which
 *                       moves no centre, and the editor now refuses less.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estate_sites', function (Blueprint $table): void {
            $table->string('tenant_id')->primary();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('geofence_radius_m')->nullable();
            $table->string('supervisor_phone', 32)->nullable();
            $table->timestamps();
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->decimal('clock_in_latitude', 10, 7)->nullable()->after('geofence_distance_m');
            $table->decimal('clock_in_longitude', 10, 7)->nullable()->after('clock_in_latitude');
            $table->unsignedSmallInteger('clock_in_accuracy_m')->nullable()->after('clock_in_longitude');
            // post · estate · none
            $table->string('geofence_source', 8)->nullable()->after('clock_in_accuracy_m');
            // inside · inside_margin · outside · poor_fix · no_position · no_geofence
            $table->string('geofence_outcome', 16)->nullable()->after('geofence_source');
            $table->unsignedSmallInteger('geofence_radius_m')->nullable()->after('geofence_outcome');
            $table->timestamp('clock_in_device_at')->nullable()->after('geofence_radius_m');
            $table->integer('clock_offset_s')->nullable()->after('clock_in_device_at');
            $table->json('review_reasons')->nullable()->after('clock_offset_s');
        });

        DB::table('posts')->where('geofence_radius_m', '<', 10)->update(['geofence_radius_m' => 10]);

        Schema::table('posts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('geofence_radius_m')->default(50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('geofence_radius_m')->default(5)->change();
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropColumn([
                'clock_in_latitude', 'clock_in_longitude', 'clock_in_accuracy_m', 'geofence_source', 'geofence_outcome',
                'geofence_radius_m', 'clock_in_device_at', 'clock_offset_s', 'review_reasons',
            ]);
        });

        Schema::dropIfExists('estate_sites');
    }
};
