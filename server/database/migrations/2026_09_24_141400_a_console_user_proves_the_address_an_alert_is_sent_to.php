<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an alert reaches a console user, and the proof that it does (M10 §1.4).
 *
 * WHY THIS EXISTS. "The alert email is useless if nobody has entered an address."
 * M10 §1 sends a panic to both Heads of Security, the Director and the estate's
 * committee by email as well as on screen — and until now the only address the
 * platform held for any of them was the SIGN-IN address, which nobody was ever
 * asked to confirm and which is often a shared office mailbox or a placeholder the
 * seeder invented. A panic mailed to an address nobody reads is a panic that
 * reached nobody while the platform recorded that it reached somebody.
 *
 * THREE COLUMNS ON `users`, AND NOT A REUSE OF THE TWO ALREADY THERE.
 *
 *   `contact_email` and not `email`: the sign-in address is a credential and the
 *   contact address is a destination. They are usually the same and they need not
 *   be — a committee member may sign in with the estate office's address and want
 *   a panic on their own phone's mailbox. Changing where alerts go must never
 *   change how somebody signs in.
 *
 *   `contact_email_verified_at` and not the framework's `email_verified_at`: that
 *   column already exists, the factory already fills it, and nothing on this
 *   platform has ever proven anything with it. Giving it a meaning now would
 *   silently promote every account that happens to carry a value into "verified".
 *   The timestamp IS the flag — null is unverified — so there is no boolean to
 *   drift out of step with it.
 *
 *   `contact_phone` and not `phone`: the same reasoning as the email. It is stored
 *   ready for SMS and nothing texts it yet, because no provider is connected
 *   (`SmsSettings::canSend()`); the screen says so.
 *
 * `contact_verification_codes` HOLDS ONLY A HASH OF EACH CODE. An HMAC keyed by
 * the application key, the user and the address it was sent to — so a copy of the
 * table proves nothing, and a code sent to one address cannot be replayed against
 * another. The plaintext exists in the queued mail (encrypted on the queue) and in
 * the recipient's inbox, and nowhere else.
 *
 * MUTABLE, NOT APPEND-ONLY. A code is counted against, retired and consumed in
 * normal use, and the record that the address WAS verified lives on the user row
 * and in `audit_log`, which is the append-only record. Listed in
 * `GrantedTables::CENTRAL_MUTABLE`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'contact_email')) {
            Schema::table('users', function (Blueprint $table): void {
                // 191 so a unique or composite index on it can never exceed
                // utf8mb4's key length — the same width the framework uses.
                $table->string('contact_email', 191)->nullable()->after('email');
                $table->timestamp('contact_email_verified_at')->nullable()->after('contact_email');
                $table->string('contact_phone', 32)->nullable()->after('contact_email_verified_at');
            });
        }

        if (Schema::hasTable('contact_verification_codes')) {
            return;
        }

        Schema::create('contact_verification_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // The address this code was sent to. A code proves THIS address and
            // no other, so the verify step refuses one whose address is not the
            // account's current contact address.
            $table->string('email', 191);

            $table->char('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);

            // The resend cooldown is measured from here, on the server, so a
            // page reloaded mid-countdown cannot shorten it.
            $table->timestamp('sent_at');

            // Set when the code is used — and when a newer code retires it.
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_verification_codes');

        if (Schema::hasColumn('users', 'contact_email')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn(['contact_email', 'contact_email_verified_at', 'contact_phone']);
            });
        }
    }
};
