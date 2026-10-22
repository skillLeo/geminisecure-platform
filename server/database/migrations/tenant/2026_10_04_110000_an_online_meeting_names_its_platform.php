<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `meetings.platform_name` — WHICH platform an "Other online" meeting is on (the client, 26 Sep: "there is no option
 * to add the meeting link and the meeting platform name").
 *
 * Zoom, Google Meet and Microsoft Teams are named by `held_as` itself. "Other online" said only that, so a household
 * told to join on Webex or Jitsi read "Other online" on the notice. Nullable, and read only when `held_as` is
 * `other`: with no name the notice still says "Other online", as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('meetings', 'platform_name')) {
            return;
        }

        Schema::table('meetings', function (Blueprint $table): void {
            $table->string('platform_name', 60)->nullable()->after('held_as');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('meetings', 'platform_name')) {
            return;
        }

        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropColumn('platform_name');
        });
    }
};
