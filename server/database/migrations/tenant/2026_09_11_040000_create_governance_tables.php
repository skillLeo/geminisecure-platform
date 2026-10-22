<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Governance — boards 9, 10, 11, 12 and 36.
 *
 * THE SECRET BALLOT IS A SCHEMA, NOT A POLICY
 * ===========================================
 *
 * Platform invariant 3: "Ballot choices and voter participation are stored in
 * separate tables with no join key and no shared surrogate that could
 * reconstruct one. Turnout and quorum are provable. How a specific household
 * voted is not recoverable by the secretary, by platform staff, or by a database
 * administrator."
 *
 * Two tables carry that, and the guarantee is in what they DO NOT have:
 *
 *   ballot_receipts   THAT a household voted. id, ballot_id, unit_id, voted_on.
 *                     The unique index on (ballot_id, unit_id) is what refuses a
 *                     second vote, and the row count is what proves turnout.
 *
 *   ballot_marks      A CHOICE. mark_id, ballot_option_id. TWO COLUMNS, AND
 *                     THERE WILL NEVER BE A THIRD.
 *
 * THE TWO TABLES SHARE NO COLUMN NAME AT ALL. Not even `ballot_id` — a mark
 * reaches its ballot through its option, so there is nothing on the choice side
 * for the voter side to be joined to. `EstateGovernanceTest` asserts the
 * intersection of the two column lists is empty, and asserts it before anything
 * else in the file.
 *
 * WHY EACH ABSENT COLUMN IS ABSENT. Every one of these was available and is
 * refused, because a separation that any of them undoes is decorative:
 *
 *   no `id` on marks          An auto-increment on both sides means row 7 of one
 *                             is row 7 of the other. Sequential keys are a join
 *                             key in practice even though no constraint says so.
 *                             `mark_id` is 128 random bits, so InnoDB clusters
 *                             the marks in an order that carries no information
 *                             about the order they arrived in, and NOTHING in
 *                             the table can recover that order.
 *
 *   no timestamps on marks    A clock is an ordinal with extra steps. Two rows
 *                             written in one transaction share a microsecond,
 *                             and `created_at` on both sides would pair 318
 *                             households with 318 choices in a single query.
 *                             The marks table therefore carries no temporal
 *                             column of any kind — not created_at, not cast_at,
 *                             not a sequence, not a batch number.
 *
 *   no clock on receipts      `voted_on` is a DATE. Turnout is a daily fact and
 *                             a date is all any turnout figure has ever needed.
 *                             This is defence in depth: if some future migration
 *                             put a clock on the marks after all, a second-
 *                             precision clock over here would pair with it
 *                             immediately, and a date will not.
 *
 *   no unit, household or     The obvious breach, and the least likely. It is
 *   resident on marks         listed because the test that would catch it also
 *                             catches the three above, and a reader needs to see
 *                             that the deny-list is not just this one.
 *
 * WHAT A BREACH WOULD LOOK LIKE, so it is recognisable when someone proposes it:
 * a "so we can let a resident check their own vote was counted" receipt token
 * stored on both sides; an `id` added to marks "for Eloquent"; a `voted_at`
 * added "for the audit trail"; a `unit_id` added "nullable, only used for
 * spoiled ballots". Each of those is one column, each has a plausible sentence
 * behind it, and each ends the secret ballot.
 *
 * THERE IS DELIBERATELY NO `BallotMark` MODEL. An Eloquent model invites
 * `$table->timestamps()`, `->with('voter')` and a `hasMany` from somewhere; the
 * marks are reached only through the query builder inside `App\Services\Estate\
 * Governance`, which is the one door, and that class never returns a mark id to
 * anything.
 *
 * BOTH TABLES ARE APPEND-ONLY, at the database, by the same two layers the
 * ledger uses (D-017): the grant is withheld and the trigger refuses anyway.
 * Turnout that can be edited is not proof of anything, and a marks table that
 * can be deleted from row by row de-anonymises itself — delete 317 of 318 and
 * the survivor is identified by elimination.
 *
 * CERTIFICATION IS IRREVERSIBLE
 * =============================
 *
 * The Build Spec, board 11: "Certification is irreversible. A certified ballot
 * cannot be reopened or edited." `certified_at` is the whole mechanism — the
 * service refuses every transition on a ballot that has one, and there is no
 * path in this module that clears it.
 *
 * NOTICE PERIODS ARE VALIDATED BEFORE PUBLICATION
 * ===============================================
 *
 * Board 12: "Statutory notice periods for an AGM are validated before
 * publication, and the system refuses to publish a meeting inside the required
 * period." The period itself is the estate's own and lives on `estate_settings`
 * beside the arrears thresholds. See ASSUMPTION Q-010 there.
 *
 * NOTHING HERE STORES A TALLY, A TURNOUT OR A QUORUM. Every figure boards 9, 11
 * and 36 draw is counted from rows when the screen is drawn — marks per option,
 * receipts per ballot, attendance per meeting. A stored tally would agree with
 * the marks by coincidence, and a ballot is the one record where coincidence is
 * indistinguishable from fraud.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------------------------------------------------------- ballots */

        Schema::create('ballots', function (Blueprint $table) {
            $table->id();

            /*
             * The year, because that is what the route is keyed on — board 9
             * lives at /governance/elections/2026 and board 11 at
             * /governance/elections/2026/results. An election is a year's worth
             * of ballots, not a row of its own: board 9 draws one lifecycle and
             * one stat row over "Ballot A (Community Executive) & Ballot B
             * (Phase Leadership)", so the year is the grouping and the ballot is
             * the thing that gets certified.
             */
            $table->unsignedSmallInteger('year');

            // "A", "B". Null for a resolution ballot, which stands alone.
            $table->char('code', 1)->nullable();

            $table->string('title', 160);

            // election | resolution. The Build Spec: "Run a committee election
            // or a resolution ballot from open to certified." One lifecycle,
            // two shapes of question.
            $table->string('kind', 16)->default('election');

            /*
             * The Build Spec's `question` and `description`. A resolution ballot
             * IS its question; an election ballot's question is the seats, which
             * live in `ballot_positions`. Nullable rather than duplicated.
             */
            $table->text('question')->nullable();
            $table->text('description')->nullable();

            // Board 9's nine-step stepper, as one of nine keys. See
            // App\Models\Estate\Ballot::STAGES for the order and the labels.
            $table->string('stage', 32)->default('draft');

            $table->timestamp('nominations_open_at')->nullable();
            $table->timestamp('nominations_close_at')->nullable();

            // The voting window the Build Spec names `opens_at` / `closes_at`.
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();

            // The Build Spec's `quorum_rule`, as the only rule any board states:
            // a percentage of eligible households.
            $table->unsignedTinyInteger('quorum_percent')->default(25);

            /*
             * THE DENOMINATOR, SNAPSHOTTED. Boards 9 and 11 both print 450, and
             * board 11 prints "318 of 450 households (71%)" as a certified fact.
             * Counting `units` at draw time would let a unit added next March
             * restate a turnout that was certified last September, which is the
             * one number in this module nobody may move.
             *
             * It is refreshed while the ballot is still in nominations and
             * frozen when voting opens — see Governance::open().
             */
            $table->unsignedInteger('eligible_households')->default(0);

            /*
             * The Returning Officer. Board 9 names Delroy Samuels and board 11
             * addresses the certifying screen to him — "As Returning Officer,
             * review the tally below then certify".
             *
             * The id is a plain integer and not a foreign key: `users` is the
             * CENTRAL database and MySQL cannot constrain across databases. The
             * name beside it is what the screen prints, so the record still
             * reads if the account is ever removed.
             */
            $table->unsignedBigInteger('returning_officer_id')->nullable();
            $table->string('returning_officer_name', 160)->nullable();

            /*
             * THE IRREVERSIBLE FACT. Once this is set the ballot is closed to
             * every transition in `Governance`, including certification itself.
             * There is no `decertify`, no `reopen`, and no path that writes null
             * back here.
             */
            $table->timestamp('certified_at')->nullable();
            $table->unsignedBigInteger('certified_by')->nullable();
            $table->string('certified_by_name', 160)->nullable();

            // "Final tallies, turnout and quorum determination, OUTCOME
            // STATEMENT" — board 11. Written at certification and never after.
            $table->text('outcome_statement')->nullable();

            // Certification and publication are two acts. A ballot is certified
            // as the estate's formal record; publishing pushes it estate-wide.
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique(['year', 'code']);
            $table->index(['year', 'stage']);
        });

        Schema::create('ballot_positions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ballot_id')->constrained('ballots')->cascadeOnDelete();

            $table->string('name', 120);

            /*
             * How many are elected to it. Board 9 prints "1 seat" and "3 seats";
             * board 11 puts it in the result card heading only when it is more
             * than one — "Vice Chairman · 3 seats" against a bare "Chairman".
             * The pluralisation is the screen's; the number is here.
             */
            $table->unsignedSmallInteger('seat_count')->default(1);

            /*
             * estate | phase. A phase-scoped seat is voted on by one phase and
             * board 9 draws it with an amber "Phase-scoped" chip INSTEAD of a
             * seat count, so the scope is not cosmetic — it changes who is
             * entitled to mark that part of the paper.
             */
            $table->string('scope', 16)->default('estate');
            $table->string('phase', 32)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['ballot_id', 'sort_order']);
        });

        /*
         * WHAT A MARK CAN BE CAST FOR, and the only table `ballot_marks` points
         * at.
         *
         * Separate from `nominations` on purpose, and board 11 is the proof:
         * Andre Thompson appears in the results and in no nomination row on
         * board 10. A ballot paper is settled when nominations close and stops
         * depending on the vetting record that produced it — a nomination
         * withdrawn or re-decided afterwards must not silently rewrite a paper
         * people have already voted on.
         *
         * It is also what lets a resolution ballot exist at all: For, Against
         * and Abstain are options with no candidate behind them.
         */
        Schema::create('ballot_options', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ballot_id')->constrained('ballots')->cascadeOnDelete();
            $table->foreignId('ballot_position_id')->nullable()->constrained('ballot_positions')->cascadeOnDelete();

            $table->string('label', 160);

            /*
             * THE CANDIDATE'S OWN UNIT, which is not a voter's. It is here so
             * board 11 can print "Phase 1" under a winner's name and so an
             * eligibility check has something to run against, and it identifies
             * the person standing — the one household on a ballot paper that is
             * meant to be identifiable.
             */
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('phase', 32)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['ballot_id', 'sort_order']);
        });

        /* ------------------------------------------------- turnout: THAT you voted */

        /**
         * One row per household that has voted. Nothing about what they chose.
         *
         * The unique index is the double-vote refusal, and it is at the database
         * because two tabs open at once is not an unusual thing for a person to
         * do. A service check alone would lose that race.
         */
        Schema::create('ballot_receipts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ballot_id')->constrained('ballots')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();

            /*
             * A DATE, NEVER A CLOCK. See the class docblock: the marks carry no
             * temporal column, and a second-precision timestamp here would be
             * half of a join the moment anybody put one there. Turnout has never
             * needed better than a day, and board 11 draws no time at all.
             *
             * `timestamps()` is omitted for exactly the same reason — `created_at`
             * is a clock whatever it is called.
             */
            $table->date('voted_on');

            $table->unique(['ballot_id', 'unit_id']);
            $table->index('ballot_id');
        });

        /* --------------------------------------------- choices: WHAT was chosen */

        /**
         * One row per mark on a paper. Two columns, and there will never be a
         * third.
         *
         * Rows are deliberately indistinguishable from one another: two people
         * voting for the same candidate produce two rows differing only in a
         * random key. That is not a modelling shortcut, it is the definition of
         * an anonymous ballot — if any column could tell two identical votes
         * apart, that column is the thing that identifies a voter.
         */
        Schema::create('ballot_marks', function (Blueprint $table) {
            /*
             * 128 random bits, hex-encoded. NOT an auto-increment, and that is
             * the single most important line in this migration: an auto-
             * increment here would make the nth mark the nth receipt, which is a
             * join key that no foreign key declares and every DBA can use.
             *
             * InnoDB clusters on the primary key, so a random key also scrambles
             * the physical order — there is no ORDER BY over this table that
             * recovers the order the votes were cast in, because no column in it
             * knows.
             */
            $table->char('mark_id', 32)->primary();

            /*
             * The ONLY other column. It reaches the ballot through the option,
             * which is why there is no `ballot_id` here: a column shared with
             * `ballot_receipts` is a column somebody will eventually join on,
             * and `EstateGovernanceTest` asserts the two tables share none.
             */
            $table->foreignId('ballot_option_id')->constrained('ballot_options')->restrictOnDelete();
        });

        /*
         * Layer 2 of append-only on the vote, mirroring the ledger's (D-017).
         * Layer 1 is the withheld grant in ApplyAppendOnlyGrants; this is what
         * survives a later migration that forgets to re-apply it.
         *
         * A DELETE is the attack worth naming: remove 317 of 318 marks and the
         * survivor's choice is attributable by elimination to the one household
         * whose receipt has no partner. Secrecy needs the crowd to stay intact.
         */
        foreach (['ballot_marks', 'ballot_receipts'] as $table) {
            DB::unprepared("
                CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table}
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A cast vote is never edited. Correcting a ballot means running another one.';
                END
            ");

            DB::unprepared("
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table}
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'A cast vote is never removed. Thinning the record de-anonymises what is left.';
                END
            ");
        }

        /* ------------------------------------------------------ nominations */

        Schema::create('nominations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ballot_id')->constrained('ballots')->cascadeOnDelete();
            $table->foreignId('ballot_position_id')->constrained('ballot_positions')->cascadeOnDelete();

            /*
             * Candidate, nominator and seconder are all RESIDENTS OF UNITS, not
             * free text. Board 10 makes the point itself: Sonia Campbell,
             * Ricardo Hall and Michelle Palmer each appear as both a candidate
             * and somebody else's nominator, and Keith Walters seconds one row
             * while standing in another. Free text would let the same person be
             * two people.
             *
             * The names are stored beside the keys because a nomination is a
             * historical record of who put whose name forward, and a resident
             * moving out must not blank a line on a certified election's paper.
             */
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('candidate_name', 160);

            $table->foreignId('nominator_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('nominator_name', 160);

            $table->foreignId('seconder_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('seconder_name', 160);

            // pending | approved | rejected | more_info. Board 10 draws the
            // first three; "request more information" is the third decision the
            // Build Spec names and needs a state of its own, because a candidate
            // waiting on paperwork is not a candidate who has been refused.
            $table->string('status', 16)->default('pending');

            /*
             * "Rejection always carries a recorded reason" — the Build Spec, and
             * board 10 prints it inside the badge: "Rejected — arrears >90 days".
             * The service refuses a rejection without one.
             */
            $table->string('decision_reason', 190)->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decided_by_name', 160)->nullable();

            /*
             * THE ELIGIBILITY CHECK, SNAPSHOTTED AT THE MOMENT IT WAS RUN.
             *
             * Board 10's accounting note is explicit: the rejection references
             * the 90+ day ageing bucket, and "the check needs a snapshot date so
             * the ageing that justified the rejection can be reproduced later".
             * A candidate who pays their arrears the following week must not be
             * able to make the record say they were never in arrears — and the
             * ledger, correctly, would say exactly that if the screen re-derived
             * the bucket at read time.
             */
            $table->date('eligibility_checked_on')->nullable();
            $table->string('arrears_bucket_at_check', 16)->nullable();
            $table->bigInteger('arrears_minor_at_check')->nullable();
            $table->unsignedSmallInteger('tenure_months_at_check')->nullable();

            $table->timestamps();

            $table->index(['ballot_id', 'status']);
            $table->index('ballot_position_id');
        });

        /* -------------------------------------------------------- meetings */

        Schema::create('meetings', function (Blueprint $table) {
            $table->id();

            // agm | egm | committee | phase. Board 12's segmented control, and
            // the type is what decides the notice period below.
            $table->string('type', 16);

            $table->string('title', 160);
            $table->timestamp('starts_at');
            $table->string('venue', 190)->nullable();
            $table->string('virtual_link', 255)->nullable();

            /*
             * whole_estate | phase | committee. Board 36 draws all three —
             * "Whole estate", "Phase 2 only", "Committee members" — and board 12
             * says why it matters: "Only verified households in the selected
             * audience can join — there's no shareable link."
             */
            $table->string('audience_scope', 24)->default('whole_estate');
            $table->string('phase', 32)->nullable();

            /*
             * Board 12: "25% of eligible households". The Build Spec, board 36:
             * "Quorum counts households, not individuals."
             *
             * `quorum_basis` exists because board 36 draws two different
             * measures in one column — "Quorum met · 6/7" for a committee and
             * "Quorum met · 34%" for an estate-wide meeting. It names the
             * DENOMINATOR, not the rule: a committee's quorum is a proportion of
             * its seven members and a general meeting's is a proportion of 450
             * households. `quorum_percent` is the rule in both cases, which is
             * why there is one of it.
             *
             * `quorum_required_total` is that committee's SIZE — the number the
             * badge's "6/7" divides by, not the six. Null for a general meeting,
             * which counts against `eligible_households` instead. The name is
             * the one board 36's brief gives it and the one `Meeting` and
             * `Governance` both read; it was `quorum_member_total` here alone,
             * which meant a fresh `tenants:migrate` produced a database the
             * model could not read. See D-052.
             */
            $table->unsignedTinyInteger('quorum_percent')->default(25);
            $table->string('quorum_basis', 16)->default('households');
            $table->unsignedSmallInteger('quorum_required_total')->nullable();

            /*
             * The denominator, snapshotted for the same reason a ballot's is: a
             * quorum determination recorded in the minutes of a meeting held in
             * July must not move because a unit was added in September.
             */
            $table->unsignedInteger('eligible_households')->default(0);

            // Board 12 renders these as one value, "Enabled, with consent
            // notice". They are two facts: a recording may be made, and the
            // people on it were told. The second is the one a dispute turns on.
            $table->boolean('recording_enabled')->default(false);
            $table->boolean('recording_consent_notice')->default(false);

            // draft | scheduled | held | cancelled. Board 12's action moves
            // draft -> scheduled, and that is the transition the notice period
            // is validated against.
            $table->string('status', 16)->default('draft');

            /*
             * HOW MUCH NOTICE THIS MEETING ACTUALLY NEEDED, copied from the
             * estate's setting at publication.
             *
             * Copied rather than read back through the setting, because the
             * setting is editable and the question a member asks two years later
             * is "was this meeting properly convened" — which is a question
             * about the rule that was in force on the day, not today's.
             */
            $table->unsignedSmallInteger('notice_days_required')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_by_name', 160)->nullable();

            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index('type');
        });

        Schema::create('meeting_agenda_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            /*
             * A TIME, not a duration. Board 12 draws 10:00, 10:15, 10:45, 11:15
             * and the gaps between them are the durations — storing both would
             * be two numbers that disagree the first time somebody moves an item
             * up the list.
             */
            $table->time('start_time')->nullable();
            $table->string('text', 200);
            $table->unsignedSmallInteger('sort_order')->default(0);

            /*
             * What an item BINDS TO, where it binds to anything. Board 12's
             * accounting note: "Treasurer's report — FY2025/26" needs a fiscal
             * year and "Motion: approve 2026/27 budget" is a motion tied to the
             * next year's budget. Both are free-text labels here rather than
             * foreign keys — the fiscal calendar and the budget register are not
             * built, and a key to a table that does not exist is a migration
             * that cannot run.
             */
            $table->string('fiscal_year', 16)->nullable();
            $table->string('motion_reference', 64)->nullable();

            $table->timestamps();

            $table->index(['meeting_id', 'sort_order']);
        });

        /**
         * The attendance register, one row per household or member present.
         *
         * QUORUM IS COUNTED FROM THESE ROWS AND IS NEVER STORED. Board 36 prints
         * "Quorum met · 6/7" and "Quorum met · 41%" and both are counts of this
         * table against the meeting's denominator. A `quorum_met` boolean beside
         * a register that disagreed with it would be the estate's minutes
         * arguing with the estate's own attendance sheet.
         */
        Schema::create('meeting_attendance', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            /*
             * The household, where the meeting counts households. Null for a
             * committee meeting, where the seven people in the room are members
             * and not units — which is exactly why board 36 needs two quorum
             * measures.
             */
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();

            $table->string('attendee_name', 160)->nullable();

            /*
             * A proxy is present FOR a household, and the household is what
             * counts toward quorum. Recorded by name so the minutes can say who
             * actually stood there.
             */
            $table->string('represented_by', 160)->nullable();

            // present | apologies | absent. Apologies are not attendance and are
            // not absence either — a general meeting's minutes record them, and
            // a register that only held the people in the room could not.
            $table->string('state', 16)->default('present');

            $table->timestamps();

            $table->unique(['meeting_id', 'unit_id']);
            $table->index(['meeting_id', 'state']);
        });

        /**
         * The minutes document. One per meeting, and the reason board 36's row
         * action reads "View agenda" before a meeting and "View minutes" after.
         */
        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('meeting_id')->unique()->constrained('meetings')->cascadeOnDelete();

            $table->longText('body');

            $table->string('recorded_by_name', 160)->nullable();

            /*
             * Minutes are a DRAFT until a subsequent meeting adopts them, and
             * that is not a formality — an unadopted set of minutes is one
             * person's account and an adopted set is the estate's record. The
             * timestamp is what tells them apart.
             */
            $table->timestamp('adopted_at')->nullable();
            $table->unsignedBigInteger('adopted_by')->nullable();
            $table->string('adopted_by_name', 160)->nullable();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();
        });

        /* ------------------------------------------- the estate's own rules */

        Schema::table('estate_settings', function (Blueprint $table) {
            /*
             * "Eligibility rules are the estate's own and are configurable" —
             * the Build Spec, board 10.
             *
             * A SEPARATE THRESHOLD FROM THE GATE'S, even though both are 90 days
             * today. `arrears_restriction_days` decides whether a household's
             * guests get through a gate (D-024); this decides whether a member
             * may stand for office. They are the same number by coincidence and
             * an estate that softened one must not silently soften the other —
             * one is about a visitor on a Friday night and the other is about
             * who may hold the estate's chequebook.
             */
            $table->unsignedSmallInteger('governance_arrears_days')->default(90);

            /*
             * ASSUMPTION Q-011 — candidate tenure.
             *
             * The Build Spec names "tenure" as an eligibility check and no board
             * gives a figure, so the SAFEST reading is applied: the check is OFF
             * and the threshold is zero. The harm of guessing runs one way here —
             * an invented tenure rule disqualifies a member from standing for
             * office in their own community, and there is no undoing that after
             * a ballot has closed. Turn it on and set the months when the estate
             * states its own rule; `EstateGovernanceTest` already asserts that
             * the check bites when it is enabled.
             */
            $table->boolean('governance_tenure_check_enabled')->default(false);
            $table->unsignedSmallInteger('governance_min_tenure_months')->default(0);

            /*
             * ASSUMPTION Q-010 — statutory notice periods.
             *
             * No board states one, and the Build Spec only says the system
             * refuses to publish inside it. The SAFEST option is applied in both
             * directions: enforcement is ON by default, and the AGM period is
             * the LONGER of the two Jamaican readings — 21 days under the
             * Companies Act rather than 14 under the Registration (Strata Titles)
             * Act. Refusing a meeting that could lawfully have been called costs
             * the estate a week; publishing one that could not costs it the
             * meeting, and every decision taken at it.
             */
            $table->boolean('meeting_notice_enforced')->default(true);
            $table->unsignedSmallInteger('agm_notice_days')->default(21);
            $table->unsignedSmallInteger('egm_notice_days')->default(14);
            $table->unsignedSmallInteger('meeting_notice_days')->default(7);

            // Board 12's "25% of eligible households", as the estate's default
            // rather than a constant in a form.
            $table->unsignedTinyInteger('meeting_quorum_percent')->default(25);
        });
    }

    public function down(): void
    {
        Schema::table('estate_settings', function (Blueprint $table) {
            $table->dropColumn([
                'governance_arrears_days',
                'governance_tenure_check_enabled',
                'governance_min_tenure_months',
                'meeting_notice_enforced',
                'agm_notice_days',
                'egm_notice_days',
                'meeting_notice_days',
                'meeting_quorum_percent',
            ]);
        });

        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('meeting_attendance');
        Schema::dropIfExists('meeting_agenda_items');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('nominations');

        // The triggers go with their tables, but only if the tables are still
        // there — a half-run migration must not leave a trigger pointing at
        // nothing, and MySQL will not let one exist without its table anyway.
        foreach (['ballot_marks', 'ballot_receipts'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
        }

        Schema::dropIfExists('ballot_marks');
        Schema::dropIfExists('ballot_receipts');
        Schema::dropIfExists('ballot_options');
        Schema::dropIfExists('ballot_positions');
        Schema::dropIfExists('ballots');
    }
};
