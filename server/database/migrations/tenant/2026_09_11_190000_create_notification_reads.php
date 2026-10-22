<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read marks for the notification centre — board 2's bell (12 §2, Wave 2).
 *
 * THE NOTIFICATIONS THEMSELVES ARE NOT STORED, and that is the same discipline
 * the activity feed keeps: a pending unit claim, an overdue ticket, an open
 * payroll run and a booking waiting on a deposit decision are each already a row
 * in the table that owns that fact. Copying them into a notifications table
 * would make a sixth copy that drifts — a claim approved on board 34 would leave
 * a notification standing that says it still needs review, and the reader would
 * believe the notification.
 *
 * SO THE ITEMS ARE DERIVED AND ONLY THE READ MARK IS STORED. `item_key` is the
 * derived key of the thing — "claim:12", "ticket:1042" — and a row here says
 * this viewer has seen it. An item that resolves stops being derived and its
 * read mark simply stops matching anything; nothing has to go back and tidy it.
 *
 * PER VIEWER, not per estate. Two officers do not share an inbox: what the
 * Treasurer has read is not what the Secretary has read, and a bell that cleared
 * for everybody the moment one person looked would hide work from the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->id();

            // The central users table is in another database, so no FK — the
            // same shape every tenant table takes for a platform identity.
            $table->unsignedBigInteger('user_id');

            $table->string('item_key', 64);
            $table->timestamp('read_at');

            $table->timestamps();

            $table->unique(['user_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
    }
};
