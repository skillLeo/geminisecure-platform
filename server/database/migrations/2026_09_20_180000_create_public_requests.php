<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody with NO sign-in sends from the public pages (M5 §4, §10):
 * a request to delete their account, or a message to support.
 *
 * Central, because the person may not know (or may mistype) their estate, and
 * because nothing here is an estate's record — it is the company's inbox.
 *
 * A deletion REQUEST deletes nothing by itself. Anyone can type anybody's email
 * address into a public form, so staff confirm with the account's own contact
 * details before acting; the deletion itself is the same audited service the
 * in-app "Delete my account" runs. The row is removed once it has been handled
 * and the retention period in config/legal.php has passed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24);                 // account_deletion | support
            $table->string('app', 16)->nullable();      // resident | guard
            $table->string('estate', 160)->nullable();  // as the person typed it
            $table->string('full_name', 160);
            $table->string('contact', 190);             // the email or phone their account uses
            $table->text('message')->nullable();
            $table->string('status', 24)->default('received'); // received | confirmed | done | declined
            $table->timestamp('handled_at')->nullable();
            $table->string('handled_by_name', 160)->nullable();
            $table->string('reference', 16)->unique();  // what the person is shown, to quote
            $table->timestamps();

            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_requests');
    }
};
