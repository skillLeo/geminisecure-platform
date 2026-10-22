<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The estate's own structure and the people in it — boards 3, 4, 31, 34 and 38.
 *
 * THREE NEW TABLES AND TWO WIDENED ONES. `units`, `households` and `residents`
 * shipped in Phase 1 and hold the physical estate; what they never held was how
 * a person GOT onto the register, which is the whole subject of boards 31 and
 * 34, and how the estate is laid out above a unit, which is board 3.
 *
 *   estate_phases    Board 3 draws five cards and prints six numbers on each.
 *                    Four of the six are DERIVED from `units` and are not
 *                    stored here: the unit count, the occupied count, the
 *                    vacant count and the occupancy bar are all `units` grouped
 *                    by `block`, which is where a unit already records its
 *                    phase. Only the two the estate knows and the unit table
 *                    cannot answer are columns — how many blocks the phase is
 *                    laid out in, and how many officers are assigned to it.
 *
 *   unit_claims      A person asserting they belong to a unit. Board 4 counts
 *                    them, board 31 reviews them, and approving one binds a
 *                    person to a household — which is why the review carries a
 *                    reviewer, a time and, on a refusal, a reason.
 *
 *   resident_invites Board 34's "Send self-verification invite". The invite is
 *                    a record and not a side effect: a resident who never
 *                    claimed their unit is a fact somebody has to chase, and an
 *                    estate that only sent an email has nothing to chase from.
 *
 * NO BLOCK TABLE, AND THE COUNT IS STORED INSTEAD. Board 3's foot line reads
 * "6 blocks · 3 phase officers assigned", and the domain plainly has a block
 * between a phase and a unit. It is not modelled here because a unit records its
 * phase in `units.block` and nothing anywhere records which block it stands in —
 * introducing the table would mean assigning 450 existing units to blocks that
 * no source names, which is inventing an estate's layout to fill a column. The
 * count is stored, the derivation is not faked, and the residual is recorded in
 * DECISIONS.md rather than resolved by fabrication.
 *
 * `officers_assigned` IS NULLABLE AND THAT IS THE POINT. Board 3 gives Phases 1
 * to 4 a number and Phase 5 the words "new phase, officers pending" — which is
 * not zero. A phase with nobody appointed yet and a phase where nobody has been
 * appointed are different facts, and the second is what a null says. `footnote`
 * carries the words when there are words.
 *
 * `residents.status` DEFAULTS TO `pending`, AND EXISTING ROWS ARE BACKFILLED.
 * The safe default for "has this person's identity been established" is no. But
 * every resident already on an estate's register was put there by the estate
 * itself, from its own roll, which IS an establishment of identity — so the
 * backfill below marks them verified once, explicitly, rather than leaving 433
 * households reading "Pending review" and drowning the three claims that
 * genuinely need it. A row inserted after this migration and forgetting to say
 * gets the safe answer.
 *
 * NOTHING HERE STORES A BALANCE, AN AGEING BUCKET OR A RESTRICTION. A
 * household's standing is `households.access_restricted` and its balance is the
 * sum of its unit's lines on 1200 — both already exist, and a second copy beside
 * a resident is exactly how a screen comes to show a figure the ledger has moved
 * on from.
 *
 * AFTER MIGRATING AN ESTATE THAT ALREADY EXISTS, RUN `php artisan grants:estates`.
 * The estate's own MySQL user is granted UPDATE and DELETE per table rather than
 * per database (D-017), and that grant is issued at provisioning — so a table
 * created by this migration is insert-only to the estate user until the grants
 * are re-applied, and the seeder's second, idempotent run would fail on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estate_phases', function (Blueprint $table) {
            $table->id();

            /*
             * The name is the join. `units.block` holds "Phase 2" as a string
             * and has since Phase 1, with 450 rows and six months of dues
             * posted against them; a foreign key here would mean renumbering
             * every unit in an estate whose ledger cannot be unposted. So the
             * name is unique and the counts are derived by grouping on it.
             */
            $table->string('name', 64)->unique();

            // Board 3 draws the cards in ascending phase order and always puts
            // the dashed "Add another phase" placeholder last.
            $table->unsignedSmallInteger('sequence')->unique();

            $table->unsignedSmallInteger('block_count')->default(0);

            // Null is "nobody has been appointed yet", which is not zero.
            $table->unsignedSmallInteger('officers_assigned')->nullable();

            // active | new. Board 3's Phase 5 is a phase the estate has only
            // just taken on, and it reads differently because it is different.
            $table->string('status', 16)->default('active');

            // The words a phase shows instead of an officer count.
            $table->string('footnote', 120)->nullable();

            $table->timestamps();
        });

        Schema::create('unit_claims', function (Blueprint $table) {
            $table->id();

            /*
             * NULLABLE, because a claim is what a person TYPED and the estate
             * may not be able to match it to anything. Board 31's third card is
             * titled "Unknown claimant" for exactly this reason. A claim that
             * cannot be resolved to a unit is still a claim the estate has to
             * answer, and refusing to record it would lose it.
             */
            $table->foreignId('unit_id')->nullable()->constrained('units')->restrictOnDelete();

            // The household being claimed INTO, where the claim is a member
            // claim rather than a claim on the unit itself — board 31's second
            // card, "Andrea Fletcher's household".
            $table->foreignId('household_id')->nullable()->constrained('households')->restrictOnDelete();

            // unit | household_member | unverified — board 31's three shapes.
            $table->string('claim_type', 24)->default('unit');

            /*
             * What the claimant submitted, stored VERBATIM and never
             * normalised against the register. The whole value of this screen
             * is the difference between the two columns: "Keith Walters"
             * against "K. A. Walters" is what a reviewer is being asked about,
             * and tidying the submission into the record's shape would erase
             * the question.
             */
            $table->string('submitted_name', 160);
            $table->string('submitted_phase', 64)->nullable();
            $table->string('submitted_lot', 32)->nullable();
            $table->string('submitted_phone', 40)->nullable();
            $table->string('submitted_relationship', 64)->nullable();

            // exact | partial | none. The estate's own match, recorded at
            // submission: recomputing it on read would let a later edit to the
            // register silently change what a reviewer was told.
            $table->string('match_result', 16)->default('none');

            // Free text, and deliberately not an enum. "Verify identity before
            // approving" is a sentence a person wrote about one claim.
            $table->string('review_flag', 190)->nullable();

            // pending | approved | rejected
            $table->string('status', 16)->default('pending');

            /*
             * What approving actually produced. A claim with no resident behind
             * it that says it was approved is a decision nobody can trace to a
             * person, which is the one thing an approval log exists to prevent.
             */
            $table->foreignId('resolved_resident_id')->nullable()->constrained('residents')->nullOnDelete();

            /*
             * The reviewer is a CENTRAL user and this is deliberately not a
             * foreign key: it crosses a database boundary, which MySQL cannot
             * constrain (D-012). The name is denormalised beside it so the log
             * still reads after a committee member leaves the estate.
             */
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('reviewed_by_name', 160)->nullable();
            $table->timestamp('reviewed_at')->nullable();

            // Why it was refused. A rejection with no reason is a decision the
            // claimant cannot answer and the estate cannot defend.
            $table->string('decision_reason', 190)->nullable();

            // Board 31's "Request ID document" — the request is recorded with
            // what was asked for, because "we asked" and "we asked for a
            // passport" are different things to a claimant chasing it.
            $table->timestamp('document_requested_at')->nullable();
            $table->string('document_requested_kind', 64)->nullable();
            $table->unsignedBigInteger('document_requested_by')->nullable();
            $table->string('document_requested_by_name', 160)->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['unit_id', 'status']);
        });

        Schema::create('resident_invites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            /*
             * The token is what the link carries and it is unique across the
             * estate. It is stored rather than derived so an invite can be
             * revoked by deleting the row — a token computed from the resident
             * id would be reissued by any code path that rebuilt it.
             */
            $table->string('token', 64)->unique();

            // "an SMS and email" — both, on board 34, and stored as what was
            // actually sent rather than as what the estate usually sends.
            $table->string('channels', 64)->default('sms,email');

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->timestamps();

            $table->index('resident_id');
        });

        Schema::table('residents', function (Blueprint $table) {
            // pending | verified. See the class docblock for why the default is
            // the pessimistic one and why existing rows are backfilled.
            $table->string('status', 16)->default('pending')->after('is_primary');

            $table->timestamp('verified_at')->nullable()->after('status');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verified_at');
            $table->string('verified_by_name', 160)->nullable()->after('verified_by');

            /*
             * SHIPS OFF, AND NOT BY CONFIGURATION (D-022, Q-003). Board 34 may
             * collect it; nothing enrols a fingerprint without it, and the
             * refusal lives in `Residents::enrolBiometrics()` rather than in a
             * settings table an estate could switch.
             */
            $table->boolean('biometric_consent')->default(false)->after('verified_by_name');

            // "Resident since Sep 2, 2024" — board 38's fourth hero stat. Not
            // `created_at`: when the estate keyed the row and when the person
            // moved in are different dates, and an estate that imported its
            // register last month did not house everybody last month.
            $table->date('moved_in_on')->nullable()->after('biometric_consent');

            $table->index('status');
        });

        Schema::table('households', function (Blueprint $table) {
            /*
             * Board 4's "Last active" column. Stored rather than derived,
             * because what it measures — a resident opening the app, booking an
             * amenity, being admitted at a gate — is spread across three
             * systems and two databases, one of which is Gemini's. A read that
             * fanned out across all of them would put three joins behind a list
             * screen; the writers stamp it instead.
             */
            $table->timestamp('last_active_at')->nullable()->after('access_restricted');
        });

        /*
         * The backfill. Every resident already on the register was put there by
         * the estate from its own roll, which is an establishment of identity —
         * so they are verified, once, explicitly. A row inserted after this
         * point and forgetting to say gets `pending`, which is the safe answer.
         */
        DB::table('residents')->update(['status' => 'verified']);
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_invites');
        Schema::dropIfExists('unit_claims');
        Schema::dropIfExists('estate_phases');

        Schema::table('residents', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status',
                'verified_at',
                'verified_by',
                'verified_by_name',
                'biometric_consent',
                'moved_in_on',
            ]);
        });

        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};
