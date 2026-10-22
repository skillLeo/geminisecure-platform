<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 30's eighteen switches — one row per (event, channel), and the
 * events themselves are NOT a table.
 *
 * WHY EIGHTEEN ROWS RATHER THAN SIX. The board's own grouping is fixed —
 * three groups, two events each, three channels every time — and nothing on
 * this platform lets an estate invent a seventh event or a fourth channel.
 * `App\Services\Estate\Settings::NOTIFICATION_EVENTS` holds the six events,
 * their group, their label and their description as a compile-time constant,
 * on the same reasoning `FEATURE_COPY` already uses for board 23: the copy is
 * this SCREEN's, not a catalogue an estate administers, and a table that let
 * an estate add or rename an event would be a settings screen offering to
 * redesign its own sidebar. This table holds only what genuinely varies per
 * estate — whether one (event, channel) pair is on — which is the smallest
 * true state a committee's decision can be.
 *
 * `enabled` HAS NO "NOT SET" STATE, unlike `estate_features`. A feature
 * override is meaningful in its absence — no row means the plan's answer
 * stands. A notification default has no plan to fall back to: every estate
 * decides its own eighteen switches from the day it is provisioned, so the
 * row is seeded for every estate rather than resolved on read. See
 * `Database\Seeders\Estate\SettingsSeeder`, which writes the board's own
 * starting values once and never again on a later run — the same write-once
 * rule it already applies to the estate's contact details, for the same
 * reason: a seeder that reset a committee's own choice on every run would be
 * a very slow way to switch a household's dues reminders back on behind
 * their treasurer's back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_defaults', function (Blueprint $table) {
            $table->id();

            // "dues_reminders", "meetings_elections" — a key into
            // `Settings::NOTIFICATION_EVENTS`, not a foreign key, for the same
            // reason `estate_features.feature_key` carries the central
            // catalogue's key as a string rather than a constraint: the six
            // events are PHP, not a row in this database, so there is nothing
            // here for a foreign key to point at.
            $table->string('event_key', 48);

            // email | sms | push, always rendered in that order.
            $table->string('channel', 8);

            $table->boolean('enabled');

            $table->timestamps();

            // One answer per (event, channel). Two rows for the same pair
            // would mean this estate had switched dues-reminder emails both on
            // and off, and whichever the query read first would win.
            $table->unique(['event_key', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_defaults');
    }
};
