<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance and amenities — boards 17, 18, 19 and 20.
 *
 * What breaks in an estate, and what the estate lets residents book. They share
 * a migration because they share a module and a permission — `facilities` — and
 * because both are the Property Manager's work: the one thing that role does
 * hold in full, and the one place D-010's lock has to be visible from the schema
 * outwards.
 *
 * MAINTENANCE
 *
 *   maintenance_tickets   the Build Spec's `ticket`. The SLA clock starts at
 *                         `reported_at` AND AT NOTHING ELSE. "SLA breach is
 *                         computed from the reported time, not the assigned
 *                         time, so a ticket that sat unassigned still shows as
 *                         overdue" — which is why there is no `is_overdue`
 *                         column and no `sla_due_at` column either. A stored
 *                         breach flag is wrong from the second after it is
 *                         written; a stored deadline has to be rewritten every
 *                         time the priority changes, and the one rewrite
 *                         somebody forgets is a ticket quietly given a longer
 *                         deadline than its priority. Both are DERIVED from
 *                         `reported_at` + `sla_hours` on read.
 *
 *                         `assigned_at` is recorded, and the SLA arithmetic does
 *                         not touch it. That is the whole point of the column
 *                         being there and unused by the deadline: a queue that
 *                         measured from assignment would hide exactly the
 *                         failure it exists to surface.
 *
 *   maintenance_ticket_activity
 *                         every state change, timestamped and attributed. The
 *                         Build Spec: "The reporting resident can follow every
 *                         state change in the app without asking. State changes
 *                         are timestamped and attributed." So the ticket carries
 *                         the CURRENT state and this carries HOW IT GOT THERE,
 *                         and neither is derivable from the other — a status
 *                         column can be read backwards only if somebody wrote
 *                         down each step on the way.
 *
 * AMENITIES
 *
 *   amenities             board 20's rate card: capacity, booking fee, deposit
 *                         and hours. It SOURCES postings made elsewhere and
 *                         makes none itself.
 *
 *   amenity_slots         holds and blackouts — "block a slot". A slot row is a
 *                         period carved OUT of an amenity's diary, never a
 *                         materialised list of every bookable hour: a table with
 *                         a row per hour per amenity forever is a table that
 *                         grows without bound and still cannot answer a question
 *                         about next year.
 *
 *   amenity_bookings      one reservation, CARRYING THE TERMS IT WAS MADE UNDER.
 *                         `fee_minor`, `deposit_minor` and `cancellation_hours`
 *                         are copied off the amenity at creation and never read
 *                         back through the relation. "Changing a rule does not
 *                         alter bookings already confirmed under the previous
 *                         rule" — a booking that read the amenity live would
 *                         restate every held deposit the moment a manager edited
 *                         the rate card, and the deposit control account would
 *                         stop agreeing with the sum of open bookings.
 *
 * THE TICKET-TO-BILL LINK IS CLOSED HERE. `bills.ticket_id` was added by the
 * collections and payables migration as a plain integer, with its own docblock
 * saying why: "maintenance tickets are board 17 and do not exist yet, and a key
 * to a table that is not there is a migration that cannot run." They exist now,
 * so the key is added — referencing `maintenance_tickets.number`, because 1042
 * is what board 27 prints, what the bill already stores and what a committee
 * asks about. That closes the trace a committee actually walks: ticket to bill
 * to payment to bank line.
 *
 * NOTHING HERE STORES A BALANCE, A FEE TOTAL OR AN AMOUNT OWED BY A RESIDENT. An
 * amenity fee that is charged is a charge on a unit like any other and posts
 * through `Dues::charge`; the booking keeps only `fee_charge_id`, the pointer,
 * under a unique key so the same charge cannot back two bookings and the same
 * booking cannot be charged twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ----------------------------------------------------- maintenance */

        Schema::create('maintenance_tickets', function (Blueprint $table) {
            $table->id();

            /*
             * The number a resident and a committee both say out loud — "#1042".
             * Estate-wide sequential WITH GAPS, which is what board 17's own
             * list shows (1042, 1041, 1039, 1037, 1031): tickets get cancelled
             * and merged, and a renumbered queue would make two people looking
             * at the same job disagree about which one it is.
             *
             * Unique, and therefore usable as the target of `bills.ticket_id`.
             */
            $table->unsignedBigInteger('number')->unique();

            $table->string('title', 160);

            /*
             * Where the fault is, as one label. Board 17 draws two forms in the
             * same column — "Phase 2 · visitor parking" and a bare "Club House"
             * — so a (phase, unit) pair could not express half of them. The unit
             * is beside it and nullable, because a light in the visitor parking
             * belongs to no household.
             */
            $table->string('location_label', 160);
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();

            $table->string('category', 48)->nullable();
            $table->text('description')->nullable();

            // high | medium | low. Drives the SLA target, so changing it moves
            // the deadline — measured, still, from `reported_at`.
            $table->string('priority', 8)->default('medium');

            /*
             * submitted | acknowledged | assigned | in_progress | completed |
             * verified | cancelled.
             *
             * "Overdue" IS NOT IN THIS LIST, and board 17 draws it in the same
             * column as the others. It is an open ticket past its SLA, which is
             * arithmetic against now and would be a lie the moment it was
             * stored. `MaintenanceTicket::boardStatus()` derives it, the same way
             * `Bill::boardStatus()` derives an overdue bill.
             */
            $table->string('status', 16)->default('submitted');

            /*
             * WHO REPORTED IT AND WHEN. `reported_at` is the SLA clock and the
             * Age column, and it is a fact about the resident's report rather
             * than about this row — a ticket keyed in by a manager on Monday
             * from a call taken on Saturday is two days old, not none.
             */
            $table->string('reported_by_name', 160)->nullable();
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->timestamp('reported_at');

            /*
             * The service target for this ticket's priority, in hours, snapshotted
             * from the estate's ladder. Stored rather than looked up on read so a
             * ticket raised under one policy is still judged by it — and so that
             * the breach is arithmetic over two columns of THIS row, which is what
             * makes "measured from the reported time" checkable at a glance.
             */
            $table->unsignedSmallInteger('sla_hours');

            $table->foreignId('assigned_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();

            /*
             * Recorded, and deliberately absent from every SLA calculation. See
             * the class docblock: a ticket that sat unassigned for a week is
             * overdue, and the only way to keep that true is for the deadline to
             * have no term that mentions this column.
             */
            $table->timestamp('assigned_at')->nullable();

            // Board 18's vendor panel: "Tech: Owen Grant · (876) 555 0110 · ETA
            // today 2:00–4:00 PM". The current assignment; every previous one is
            // in the activity log.
            $table->string('technician_name', 160)->nullable();
            $table->string('technician_phone', 40)->nullable();
            $table->timestamp('eta_starts_at')->nullable();
            $table->timestamp('eta_ends_at')->nullable();

            $table->text('resolution')->nullable();
            $table->timestamp('closed_at')->nullable();

            /*
             * Resident sign-off, and a separate fact from completion. Board 18's
             * timeline draws "Verified by resident" as a sixth stage after
             * "Completed", so a job the estate believes is done and one the
             * household agrees is done are two different states — and the gap
             * between them is the one a reopen comes out of.
             */
            $table->timestamp('verified_at')->nullable();
            $table->string('verified_by_name', 160)->nullable();

            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index(['status', 'reported_at']);
            $table->index('assigned_vendor_id');
            $table->index('unit_id');
        });

        Schema::create('maintenance_ticket_activity', function (Blueprint $table) {
            $table->id();

            $table->foreignId('maintenance_ticket_id')->constrained('maintenance_tickets')->cascadeOnDelete();

            /*
             * What happened: reported | acknowledged | assigned | reassigned |
             * priority_changed | started | resolved | reopened | verified |
             * note.
             *
             * Wider than the six stages board 18 draws, because a reassignment
             * and a priority change are state changes a resident is entitled to
             * follow and neither advances the lifecycle.
             */
            $table->string('event', 32);

            /*
             * The lifecycle stage this entry advanced the ticket to, where it
             * advanced one — submitted | acknowledged | assigned | in_progress |
             * completed | verified — and null where it did not.
             *
             * Board 18 renders the six stages in FIXED lifecycle order rather
             * than by timestamp, so the timeline is built by asking each stage
             * for its entry; the events with no stage sit outside that spine and
             * are the ticket's history rather than its progress.
             */
            $table->string('stage', 16)->nullable();

            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();

            // "Technician on site, today 2:00–4:00 PM" — board 18's sub-line on
            // the active stage.
            $table->string('note', 300)->nullable();

            /*
             * ATTRIBUTED, and the name is the fact rather than the id. Board 18
             * attributes one stage to "Patricia Morgan" and the next to "Island
             * Electric Services": a vendor is not a console user, so an actor
             * that could only be a user id would be unable to record half of
             * this ticket's own history.
             */
            $table->string('actor_kind', 16)->default('staff');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 160)->nullable();

            // The time the change happened, which is not always the time the row
            // was written — a job completed on site on Friday can be closed off
            // on Monday.
            $table->timestamp('occurred_at');

            $table->timestamps();

            /*
             * Named explicitly, because Laravel's generated name for the first
             * of these is 67 characters and MySQL's identifier limit is 64. Left
             * to the default it aborts the migration, which takes every
             * tenant-scoped test in the suite down with it — the fixtures build
             * their estates by running this same directory.
             */
            $table->index(['maintenance_ticket_id', 'occurred_at'], 'ticket_activity_occurred_index');
            $table->index(['maintenance_ticket_id', 'stage'], 'ticket_activity_stage_index');
        });

        /*
         * The key `bills.ticket_id` was left waiting for.
         *
         * It references `number` and not `id`. The bill already stores 1042 —
         * the number board 27 prints and a committee asks about — and pointing
         * the constraint at a surrogate id would mean rewriting every bill in
         * the estate to say the same thing in a language nobody uses.
         *
         * Restricting on delete, for the same reason a vendor with bills against
         * it cannot be deleted: a ticket somebody was invoiced for is history the
         * estate has to keep.
         */
        $this->recoverTicketsAlreadyClaimedByBills();

        Schema::table('bills', function (Blueprint $table) {
            $table->foreign('ticket_id')->references('number')->on('maintenance_tickets')->restrictOnDelete();
        });

        /* ------------------------------------------------------- amenities */

        Schema::create('amenities', function (Blueprint $table) {
            $table->id();

            $table->string('name', 80);

            /*
             * Which glyph the cards and the booking rows draw. STORED, not
             * derived from the name: board 19 and board 20 draw four distinct
             * SVGs, and an estate that adds "Tennis Court" would otherwise get
             * whichever icon a string match happened to land on.
             */
            $table->string('icon_key', 32)->default('pavilion');

            $table->unsignedSmallInteger('capacity');

            /*
             * Nullable, and null is not zero. Board 20 draws "Free for residents"
             * against the Pool Deck's fee and "None" against its deposit, which
             * are statements about the amenity rather than amounts — and the
             * board's own accounting note is explicit that a nil amenity must
             * "produce a booking with zero journal lines rather than a
             * zero-amount entry".
             */
            $table->bigInteger('booking_fee_minor')->nullable();
            $table->bigInteger('deposit_minor')->nullable();
            $table->char('currency', 3)->default('JMD');

            /*
             * Opening hours. `closes_at` is a TIME and may legitimately hold
             * 24:00:00 — board 20 draws the Community Centre closing at
             * "Midnight", and 00:00:00 would sort before every opening time and
             * make the amenity closed all day.
             */
            $table->time('opens_at')->default('08:00:00');
            $table->time('closes_at')->default('22:00:00');

            /*
             * Named by the Build Spec's amenity entity and drawn on neither
             * board: "capacity, slot definition, booking window, fee,
             * cancellation rule and deposit". They are here because a booking
             * SNAPSHOTS the cancellation rule, and a term that exists only on the
             * booking could never have been set anywhere.
             */
            $table->unsignedSmallInteger('booking_window_days')->nullable();
            $table->unsignedSmallInteger('cancellation_hours')->nullable();

            /*
             * The card order board 20 draws — Gazebo, Club House, Community
             * Centre, Pool Deck — which is neither alphabetical nor by capacity
             * nor by fee, so it is somebody's choice and has to be stored.
             */
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Retired, never deleted: an amenity with bookings behind it is a
            // record of what the estate let people book.
            $table->boolean('is_active')->default(true);
            $table->boolean('is_bookable')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('amenity_slots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('amenity_id')->constrained('amenities')->cascadeOnDelete();

            /*
             * blocked | hold.
             *
             * A blackout the manager set, or a period held while a booking is
             * decided. Both are periods a new booking may not overlap; they are
             * distinguished because one is the estate's decision about its own
             * diary and the other is a resident's request in flight, and telling
             * somebody the Gazebo is closed when it is merely spoken for is a
             * different sentence.
             */
            $table->string('kind', 16)->default('blocked');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->string('reason', 190)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 160)->nullable();

            $table->timestamps();

            $table->index(['amenity_id', 'starts_at']);
        });

        Schema::create('amenity_bookings', function (Blueprint $table) {
            $table->id();

            $table->string('reference', 32)->unique();

            $table->foreignId('amenity_id')->constrained('amenities')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            /*
             * Who booked it, as a name. Board 19 prints "Andrea Fletcher — Lot
             * 47", and the resident record it came from can be corrected,
             * archived or moved out; the booking has to keep saying who made it.
             */
            $table->string('resident_name', 160);
            $table->unsignedBigInteger('resident_id')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('guests')->nullable();

            // pending | confirmed | declined | cancelled | completed
            $table->string('status', 16)->default('pending');

            /*
             * THE TERMS THIS BOOKING WAS MADE UNDER, copied from the amenity at
             * creation and never re-read through the relation.
             *
             * This is the rule the whole table exists to serve: "Changing a rule
             * does not alter bookings already confirmed under the previous rule."
             * Board 19's Deposit column has to keep agreeing with the deposits
             * actually held after the rate card is edited, and it can only do
             * that if the figure lives here.
             */
            $table->bigInteger('fee_minor')->default(0);
            $table->bigInteger('deposit_minor')->default(0);
            $table->char('currency', 3)->default('JMD');
            $table->unsignedSmallInteger('cancellation_hours')->nullable();

            /*
             * none | awaiting | held | refunded — board 19's three printed
             * states plus the one a nil-deposit amenity has.
             *
             * A STATE, NOT A POSTING. "held" means the estate has the money and
             * carries it as a refundable liability on 2200; "awaiting" means it
             * has nothing and must not touch that account, or the control balance
             * overstates by the quoted amount. The cash side of a deposit is a
             * treasury act and does not belong to a facilities route — see
             * `Amenities` and QUESTIONS.md Q-009.
             */
            $table->string('deposit_state', 16)->default('none');
            $table->date('deposit_refunded_on')->nullable();

            /*
             * The charge this booking's fee raised, and the reason it can only
             * ever raise one.
             *
             * Unique, so the same charge cannot back two bookings; nullable,
             * because a free amenity raises none and a pending booking has not
             * been charged yet. The Build Spec names this field on the booking
             * entity — `fee_charge_id` — and the amount is deliberately NOT
             * stored beside it: the charge is the money, and a second copy of the
             * figure here would be free to disagree with the unit's ledger.
             */
            $table->foreignId('fee_charge_id')->nullable()->unique()->constrained('charges')->restrictOnDelete();

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 160)->nullable();
            $table->timestamp('approved_at')->nullable();

            /*
             * Why it was refused. Not nullable in practice — `Amenities::decline`
             * refuses without one — because a resident told only "declined" has
             * nothing to act on and will ask a guard at a gate about it.
             */
            $table->string('declined_reason', 190)->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->string('notes', 300)->nullable();

            $table->timestamps();

            $table->index(['amenity_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
            $table->index('unit_id');
        });

        /* --------------------------------------------- the estate's own rule */

        Schema::table('estate_settings', function (Blueprint $table) {
            /*
             * "A household in arrears may be blocked from booking an amenity, and
             * that is a CONFIGURABLE ESTATE RULE rather than a platform default."
             *
             * OFF BY DEFAULT, and that is the safe direction. Switched on in
             * error it stops a household holding a birthday party; left off in
             * error the estate collects a booking fee from somebody who owes it
             * money, which it was going to bill anyway. Only one of those is a
             * decision an estate should have to make deliberately.
             *
             * It sits on `estate_settings` beside the arrears restriction
             * thresholds because it is the same kind of fact — one row per
             * estate, in that estate's own database, so no estate can read or
             * change another's.
             */
            $table->boolean('amenity_arrears_block_enabled')->default(false);

            /*
             * How far into arrears a household has to be before the block bites.
             *
             * ASSUMPTION Q-008: neither board nor the Build Spec says. Defaulted
             * to the estate's own arrears restriction threshold (D-024's 90
             * days) so that an amenity block can never be STRICTER than the gate
             * restriction the same estate already configured — a household whose
             * visitors are still admitted must not be turned away from the
             * Gazebo.
             */
            $table->unsignedSmallInteger('amenity_arrears_block_days')->default(90);
        });
    }

    public function down(): void
    {
        Schema::table('estate_settings', function (Blueprint $table) {
            $table->dropColumn(['amenity_arrears_block_enabled', 'amenity_arrears_block_days']);
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['ticket_id']);
        });

        Schema::dropIfExists('amenity_bookings');
        Schema::dropIfExists('amenity_slots');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('maintenance_ticket_activity');
        Schema::dropIfExists('maintenance_tickets');
    }

    /**
     * Give every ticket a bill already names a row of its own, before the
     * constraint is added over them.
     *
     * WITHOUT THIS THE MIGRATION CANNOT RUN ON A SEEDED ESTATE. Board 27's bills
     * were recorded against tickets #1042, #1041, #1037 and #1031 months before
     * this table existed — that is precisely what `bills.ticket_id` was added as
     * a plain integer for — and MySQL validates a new foreign key against the
     * rows already there. The alternatives were both worse: nulling the column
     * throws away the trace it exists to carry, and skipping the constraint
     * leaves the link an honour system.
     *
     * The recovered ticket is deliberately thin and deliberately CLOSED. A bill
     * was raised and approved against it, so the work happened; leaving it open
     * would put a fabricated job into the overdue count on board 17. The
     * facilities seeder writes the real detail over the top, keyed on the same
     * number.
     */
    private function recoverTicketsAlreadyClaimedByBills(): void
    {
        if (! Schema::hasTable('bills')) {
            return;
        }

        $claimed = DB::table('bills')
            ->whereNotNull('ticket_id')
            ->orderBy('id')
            ->get(['ticket_id', 'ticket_label', 'description', 'created_at']);

        $rows = [];

        foreach ($claimed as $bill) {
            $number = (int) $bill->ticket_id;

            if (isset($rows[$number])) {
                continue;
            }

            /*
             * "Ticket #1042 — Gate lighting" carries the title after the em
             * dash. Where a bill has no label the description is the closest
             * thing to one anybody wrote down.
             */
            $label = (string) ($bill->ticket_label ?? '');
            $title = str_contains($label, ' — ')
                ? trim((string) substr($label, (int) strpos($label, ' — ') + strlen(' — ')))
                : trim((string) $bill->description);

            $raised = $bill->created_at ?? now();

            $rows[$number] = [
                'number' => $number,
                'title' => $title !== '' ? mb_substr($title, 0, 160) : 'Work order #'.$number,
                'location_label' => 'Recovered from a supplier invoice',
                'priority' => 'medium',
                'status' => 'completed',
                'reported_at' => $raised,
                'sla_hours' => 72,
                'closed_at' => $raised,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('maintenance_tickets')->insert(array_values($rows));
        }
    }
};
