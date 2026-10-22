<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * BOARD 20'S TWO SWITCHES THAT HAD NOTHING BEHIND THEM (M50 §4.2 #4, #5). Additive and nullable: an older phone, and
 * every account until its resident says otherwise, behaves exactly as before.
 *
 *   QUIET HOURS belong to a PHONE, like the categories muted on it (`muted`): it is that phone that must not buzz at
 *   night. HH:MM in the estate's own clock; both null = no quiet hours. The push decision reads them
 *   (`PushNotifier`), and an urgent type — panic, somebody at the gate — ignores them, as the board says.
 *
 *   EMAIL belongs to the ACCOUNT: an email reaches the person whichever phone they hold, or none. Off until they turn
 *   it on (these are a real estate's residents — nobody is emailed by a default). `notify_email_muted` is the
 *   categories they switched off, sent with the switch, so an email never says what a push would not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_push_tokens', function (Blueprint $table): void {
            $table->char('quiet_from', 5)->nullable()->after('muted');
            $table->char('quiet_to', 5)->nullable()->after('quiet_from');
        });

        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->boolean('notify_email')->default(false)->after('device_platform');
            $table->json('notify_email_muted')->nullable()->after('notify_email');
        });
    }

    public function down(): void
    {
        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->dropColumn(['notify_email', 'notify_email_muted']);
        });

        Schema::table('device_push_tokens', function (Blueprint $table): void {
            $table->dropColumn(['quiet_from', 'quiet_to']);
        });
    }
};
