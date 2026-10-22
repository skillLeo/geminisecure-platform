<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A CONSOLE USER MAY CARRY A PROFILE PICTURE (M12 §4.8: "Profile picture on both consoles"). The sidebar and the
 * top bar drew two initials and nothing else.
 *
 * The file is in the platform's own private storage (the `console_photos` disk — central, so the one picture follows
 * a person into every estate console they are allowed into, and never a public URL): each console serves the
 * signed-in person their own. These three columns say where it is, which version it is (a changed picture is a new
 * address, an unchanged one comes from the browser's cache) and when it changed. Nullable: no picture, the initials.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'photo_path')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('photo_path', 255)->nullable();
            $table->char('photo_sha256', 64)->nullable();
            $table->timestamp('photo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'photo_path')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['photo_path', 'photo_sha256', 'photo_updated_at']);
        });
    }
};
