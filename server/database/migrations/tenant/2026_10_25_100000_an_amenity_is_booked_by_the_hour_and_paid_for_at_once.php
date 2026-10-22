<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * BOOK AN AMENITY AS THE DESIGN DOES (M47 §8–§10; the user's answers, 7 October): priced by the hour, described, in
 * two-hour blocks, its house rules accepted — and paid for in the app at once, which confirms it there and then, with a
 * short code a guard can read aloud and a QR the gate accepts.
 *
 *   amenities.description          "garden setting" — what the place is, under its name (board 21, 22)
 *   amenities.place                "Phase 2" — where it is on the estate (board 22's hero)
 *   amenities.hourly_rate_minor    "$2,000/hr": the fee is the rate times the hours booked. Null keeps the one flat
 *                                  fee per booking (`booking_fee_minor`) an amenity has always had
 *   amenities.slot_minutes         the blocks the day is booked in — 120 for "9–11 AM · 11–1 PM · …". Null: one hour
 *   amenities.rules_summary        "noise cut-off at 9:00 PM and no glassware on the lawn" — after "house rules, including"
 *   amenities.rules                the house rules themselves, which the gold link opens
 *
 *   amenity_bookings.confirmation_code   "GZ-2609-A4" — the place, the event's year and month, two characters
 *   amenity_bookings.gate_pass_id        the guests' pass for the event's hours, signed like any visitor pass
 *   amenity_bookings.paid_card           "Visa •••• 4417" — the (test) card it was paid with, so the deposit note can say
 *                                        where the deposit goes back to. Never more than the brand and last four
 *
 * Additive: trailing nullable columns and one unique index on a column every existing row leaves null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amenities', function (Blueprint $table): void {
            if (! Schema::hasColumn('amenities', 'description')) {
                $table->string('description', 120)->nullable();
            }
            if (! Schema::hasColumn('amenities', 'place')) {
                $table->string('place', 60)->nullable();
            }
            if (! Schema::hasColumn('amenities', 'hourly_rate_minor')) {
                $table->bigInteger('hourly_rate_minor')->nullable();
            }
            if (! Schema::hasColumn('amenities', 'slot_minutes')) {
                $table->unsignedSmallInteger('slot_minutes')->nullable();
            }
            if (! Schema::hasColumn('amenities', 'rules_summary')) {
                $table->string('rules_summary', 200)->nullable();
            }
            if (! Schema::hasColumn('amenities', 'rules')) {
                $table->text('rules')->nullable();
            }
        });

        Schema::table('amenity_bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('amenity_bookings', 'confirmation_code')) {
                $table->string('confirmation_code', 16)->nullable()->unique();
            }
            if (! Schema::hasColumn('amenity_bookings', 'gate_pass_id')) {
                $table->string('gate_pass_id', 64)->nullable();
            }
            if (! Schema::hasColumn('amenity_bookings', 'paid_card')) {
                $table->string('paid_card', 60)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('amenity_bookings', function (Blueprint $table): void {
            if (Schema::hasColumn('amenity_bookings', 'confirmation_code')) {
                $table->dropUnique(['confirmation_code']);
            }
            foreach (['confirmation_code', 'gate_pass_id', 'paid_card'] as $column) {
                if (Schema::hasColumn('amenity_bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('amenities', function (Blueprint $table): void {
            foreach (['description', 'place', 'hourly_rate_minor', 'slot_minutes', 'rules_summary', 'rules'] as $column) {
                if (Schema::hasColumn('amenities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
