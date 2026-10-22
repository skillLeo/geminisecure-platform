<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A MEETING HAS A LIFE (the user, M56 §2, 8 October 2026): "I cannot edit a meeting. I cannot delete one. I cannot
 * postpone one." Draft, Scheduled, In progress, Held, Minutes published, Archived; and Postponed and Cancelled off
 * Scheduled. What each column is for:
 *
 *   duration_minutes   "In progress" is between the start and the END, and a meeting had only a start. Two hours unless
 *                      the office says otherwise; "Held" is when that end has passed.
 *   description        what the meeting is about, beside its agenda (M56 §2: "Edit venue, link, agenda, description").
 *   notice_given_at    when households were told THIS date. `published_at` stays the first announcement; a new date
 *                      restarts the notice period (§1.3), and this is when that clock restarted.
 *   postponed_*        "Not happening then, and we do not yet know when" (§2.3): who, when, why.
 *   cancelled_*        Terminal, with a reason and a name (§1.2): a meeting the community was told of is never deleted.
 *   archived_*         After the minutes are published.
 *
 * Nothing is dropped, nothing rewritten: every existing published meeting's clock started when it was published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('duration_minutes')->default(120)->after('starts_at');
            $table->text('description')->nullable()->after('title');
            $table->timestamp('notice_given_at')->nullable()->after('published_at');

            $table->timestamp('postponed_at')->nullable();
            $table->string('postponed_by_name', 160)->nullable();
            $table->string('postpone_reason', 500)->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancelled_by_name', 160)->nullable();
            $table->string('cancel_reason', 500)->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->string('archived_by_name', 160)->nullable();
        });

        DB::connection($this->getConnection())->table('meetings')->whereNotNull('published_at')->update(['notice_given_at' => DB::raw('published_at')]);
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropColumn([
                'duration_minutes', 'description', 'notice_given_at',
                'postponed_at', 'postponed_by_name', 'postpone_reason',
                'cancelled_at', 'cancelled_by', 'cancelled_by_name', 'cancel_reason',
                'archived_at', 'archived_by_name',
            ]);
        });
    }
};
