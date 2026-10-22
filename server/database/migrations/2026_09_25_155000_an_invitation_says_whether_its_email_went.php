<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A COMMITTEE, STAFF OR ONBOARDING INVITATION SAYS WHETHER ITS EMAIL WENT (DESIGN §1.3, §11 rows 1, 2 and 5).
 *
 * The screens said "Invitation sent" whatever happened. A relay that refused the message was a server error
 * page — and on a resend, the link the person already held had been killed before the send was tried. Now the
 * row records every try, with the same four columns a resident invite has (stage 1's `resident_invites`):
 *
 *   sent_at          stamped only when the mail server ACCEPTED the message
 *   attempts         every try, first send and resends alike
 *   last_attempt_at  when the last try was — a resend waits a minute after it
 *   last_error       the last refusal in words (`MailSettings::explain`), never a credential; null once one goes
 *
 * NO BACKFILL. An invitation written before this has `attempts = 0` and no `sent_at`, and it reads as what it
 * is: "Sent Sep 20, before delivery was recorded — resend if it never arrived." Under an undeliverable
 * mailer it may never have left the machine, so calling it "sent" would be the claim this exists to stop.
 *
 * `invitations` is CENTRAL_MUTABLE (`App\Support\GrantedTables`), so no grant changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            if (! Schema::hasColumn('invitations', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('expires_at');
            }

            if (! Schema::hasColumn('invitations', 'attempts')) {
                $table->unsignedSmallInteger('attempts')->default(0)->after('sent_at');
            }

            if (! Schema::hasColumn('invitations', 'last_attempt_at')) {
                $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            }

            if (! Schema::hasColumn('invitations', 'last_error')) {
                $table->string('last_error', 255)->nullable()->after('last_attempt_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            foreach (['sent_at', 'attempts', 'last_attempt_at', 'last_error'] as $column) {
                if (Schema::hasColumn('invitations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
