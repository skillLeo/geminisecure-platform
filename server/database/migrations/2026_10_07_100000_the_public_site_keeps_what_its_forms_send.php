<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE PUBLIC SITE KEEPS WHAT ITS FORMS SEND (M14, D-194).
 *
 * The client's landing page has a contact form, and its Apply page an application for a community. Both used to go
 * to the client's old backend, which no longer answers. Now each is written here first and then emailed to the
 * address the page gives, so a message is never lost to a mail relay's bad moment: if the email fails, the row is
 * still here, and the failure is in `mail_failures`.
 *
 *   kind       `contact` (the landing page's form) or `application` (the Apply page)
 *   name       who sent it, as typed
 *   email      the address to answer, as typed
 *   phone      the Apply page's optional phone
 *   message    the contact form's message, or the application's description
 *   details    the application's other fields (community name, type, address, city, zip code, state, country), JSON
 *   ip_hash    SHA-256 of the sender's IP with the application key: enough to see one address sending a flood,
 *              and not the address itself
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_enquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);
            $table->string('name', 200);
            $table->string('email', 254);
            $table->string('phone', 40)->nullable();
            $table->text('message');
            $table->json('details')->nullable();
            $table->char('ip_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['kind', 'created_at'], 'site_enquiries_kind_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_enquiries');
    }
};
