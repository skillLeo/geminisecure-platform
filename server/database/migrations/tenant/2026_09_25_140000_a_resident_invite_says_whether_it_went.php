<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A RESIDENT INVITE IS SENT, AND SAYS WHETHER IT WENT.
 *
 * `resident_invites` was a record with nothing behind it. The office's Add resident wrote a row with
 * `sent_at` null and no mail or text ever left, and the Resident App's "add a household member"
 * wrote nothing at all. The user sent an invite to a family member, checked the inbox, and found
 * nothing: the screen had said "Sent … Confirmed".
 *
 * An invite is now DELIVERED, by email through the platform's own mail path, and the row says what
 * happened:
 *
 *   email                   the one address it is for. An invite is for one person, not "whatever
 *                           the register holds by the time it is read"
 *   sent_at                 stamped only when the mail server ACCEPTED the message
 *   attempts, last_attempt_at, last_error
 *                           every try, and the last refusal in words (never a credential)
 *   expires_at              an invite lapses; a resend starts it again, with a new token
 *   invited_by_*            who asked for it — the household's primary resident, or the office
 *   cancelled_at            cancelled by whoever invited, and when the member is removed
 *
 * Every column is nullable or defaulted, so the rows the office wrote before this read as invites
 * that were never sent — which is exactly what they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resident_invites', function (Blueprint $table): void {
            if (! Schema::hasColumn('resident_invites', 'email')) {
                $table->string('email', 190)->nullable()->after('channels');
            }

            if (! Schema::hasColumn('resident_invites', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('email');
            }

            if (! Schema::hasColumn('resident_invites', 'invited_by_resident_id')) {
                $table->unsignedBigInteger('invited_by_resident_id')->nullable()->after('expires_at');
            }

            // The office's user lives in the platform database: an id and a name, no foreign key across databases.
            if (! Schema::hasColumn('resident_invites', 'invited_by_user_id')) {
                $table->unsignedBigInteger('invited_by_user_id')->nullable()->after('invited_by_resident_id');
            }

            if (! Schema::hasColumn('resident_invites', 'invited_by_name')) {
                $table->string('invited_by_name', 160)->nullable()->after('invited_by_user_id');
            }

            if (! Schema::hasColumn('resident_invites', 'attempts')) {
                $table->unsignedSmallInteger('attempts')->default(0)->after('sent_at');
            }

            if (! Schema::hasColumn('resident_invites', 'last_attempt_at')) {
                $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            }

            if (! Schema::hasColumn('resident_invites', 'last_error')) {
                $table->string('last_error', 255)->nullable()->after('last_attempt_at');
            }

            if (! Schema::hasColumn('resident_invites', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('claimed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('resident_invites', function (Blueprint $table): void {
            foreach (['email', 'expires_at', 'invited_by_resident_id', 'invited_by_user_id', 'invited_by_name', 'attempts', 'last_attempt_at', 'last_error', 'cancelled_at'] as $column) {
                if (Schema::hasColumn('resident_invites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
