<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The documents an estate issues and has to keep — 12 §1.
 *
 * THE RULING, in two parts. "Statement and receipt PDFs: server-rendered,
 * queued, seven-year retention, estate logo." And separately: "document
 * retention is 7 years for anything financial. Not configurable."
 *
 * SERVER-RENDERED AND QUEUED, and the row exists because of the queue. A
 * statement asked for at four o'clock is rendered by a worker, not in the
 * request — so there has to be something for the reader to come back to, and
 * something for a second press to find rather than rendering the same document
 * twice. `status` is what the screen reads while it waits.
 *
 * THE BYTES ARE ON DISK, THE FACTS ARE HERE. `path` is where; `sha256` is what,
 * so a document produced today and read in 2033 can be shown to be the one that
 * was issued. A statement re-rendered from live data years later would show a
 * balance that was never on the paper the resident holds.
 *
 * `retain_until` IS STAMPED AT ISSUE AND IS NOT CONFIGURABLE. Seven years from
 * the day it was made, computed once, stored on the row. Not derived at read
 * time from a setting: a setting can be changed, and a retention period that can
 * be shortened after the fact is not a retention period. Nothing in this system
 * deletes a document before that date, and no screen offers to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // statement | receipt | minutes | agenda | election_certificate
            $table->string('kind', 32);

            /*
             * What it is ABOUT, as a type and an id rather than a foreign key:
             * a statement is about a unit, minutes are about a meeting, a
             * certificate is about an election. One nullable FK per kind would
             * be five columns of which four are always null.
             */
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 40)->nullable();

            $table->string('title', 190);
            $table->string('filename', 190);

            // queued | ready | failed
            $table->string('status', 16)->default('queued');

            // Null until the worker has written it.
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();

            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->string('requested_by_name', 120);

            $table->timestamp('issued_at')->nullable();

            // Seven years from issue. Stamped, never derived — see the head.
            $table->timestamp('retain_until');

            $table->timestamps();

            $table->index(['kind', 'subject_type', 'subject_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
