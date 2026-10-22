<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the platform sends mail from, held in the database rather than only in `.env`.
 *
 * WHY THIS IS NOT JUST AN ENV KEY. Until 23 September 2026 the platform ran on
 * `MAIL_MAILER=log`, because no relay had been supplied. The consoles survived that; the phones
 * did not. A resident signs in with a six-digit code posted to their mailbox, so with `log` the
 * Resident App could not be signed into by anybody who was not on the VM — and the same was
 * true of guard enrolment, invitations and every password reset.
 *
 * Changing that meant an operator with SSH, a deploy, and a secret pasted into a shell. A relay
 * that can only be changed that way is a relay nobody changes: when the developer's account
 * here is replaced by the client's own, that must be a form somebody fills in, not a ticket.
 *
 * ONE ROW. There is one platform and it sends from one place; a table with a primary key and
 * no natural second row is honest about that, and `MailSettings` reads `first()`.
 *
 * THE PASSWORD IS ENCRYPTED AT REST with APP_KEY, through the model's `encrypted` cast, and is
 * never returned to any interface — `MailSettings::shape()` omits it and reports only whether
 * one is set. A dump of this table on its own yields nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_mail_settings', function (Blueprint $table): void {
            $table->id();

            $table->string('host');
            $table->unsignedSmallInteger('port')->default(587);

            /*
             * Laravel 11 replaced MAIL_ENCRYPTION with MAIL_SCHEME, and the two are not the
             * same field with a new name: port 465 wants `smtps` (implicit TLS from the first
             * byte) and port 587 wants NO scheme at all (STARTTLS, negotiated after connecting).
             * Writing `tls` for 465 connects in the clear and then fails the handshake, which
             * surfaces as a timeout and names nothing. Nullable, because "none" is a real answer.
             */
            $table->string('scheme', 16)->nullable();

            $table->string('username')->nullable();

            // Encrypted by the model's cast. Longer than a password because ciphertext is.
            $table->text('password')->nullable();

            $table->string('from_address');
            $table->string('from_name');

            /*
             * Who last changed it. The audit log holds the full account — actor, time and which
             * fields moved, never their values — and this is the denormalised copy the screen
             * draws without a join.
             */
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_mail_settings');
    }
};
