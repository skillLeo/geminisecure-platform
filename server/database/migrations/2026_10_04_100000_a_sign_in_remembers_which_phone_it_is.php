<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * WHICH PHONE A SIGN-IN IS (the client, 26 Sep: "i am logged in with 2 different devices on the same account …
 * none of them showing the other device").
 *
 * The Resident App's Security screen could only ever say "this phone", because a token is a hash and an install id
 * (`resident-app:<device_uid>`) — nothing a person would recognise their own phone by. So the sign-in now keeps what
 * the phone calls itself ("SM-S938B", "iPhone 15") and its platform, and `GET /me/sessions` lists every phone signed
 * in to the account, each with a Sign out.
 *
 * NULLABLE, AND NOTHING DEPENDS ON IT. A sign-in made before this, or by an older app, has neither; the list then
 * says "A phone signed in on …", which is still true. The model name is the phone's own marketing string — never an
 * IMEI, a serial or anything else that identifies the handset beyond its model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('device_label', 80)->nullable()->after('name');
            $table->string('device_platform', 16)->nullable()->after('device_label');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['device_label', 'device_platform']);
        });
    }
};
