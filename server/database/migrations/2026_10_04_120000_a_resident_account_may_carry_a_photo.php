<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A RESIDENT APP ACCOUNT MAY CARRY A PROFILE PHOTO (the client, 26 Sep: "user can change his profile dp in the mobile
 * app"). The Profile screen said "there is no photo to change (the server keeps none)".
 *
 * The file itself is in the ESTATE's own private storage (`account-photos/{account}/…`, the tenant-rooted `local`
 * disk, as ticket photos are), never a public URL: the app fetches it with its own token (`me.photo`). These three
 * columns say where it is, which version it is (so a phone refetches only when it changes) and when it changed.
 * Nullable: an account without a photo shows its initials, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('resident_accounts', 'photo_path')) {
            return;
        }

        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->string('photo_path', 255)->nullable();
            $table->char('photo_sha256', 64)->nullable();
            $table->timestamp('photo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('resident_accounts', 'photo_path')) {
            return;
        }

        Schema::table('resident_accounts', function (Blueprint $table): void {
            $table->dropColumn(['photo_path', 'photo_sha256', 'photo_updated_at']);
        });
    }
};
