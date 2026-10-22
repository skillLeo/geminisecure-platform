<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingAttendance;
use App\Models\Estate\MeetingMinutes;
use App\Models\Estate\Nomination;
use App\Models\Estate\Unit;
use App\Services\Estate\Governance;
use App\Support\SiteTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The estate's own elections and its meeting register — boards 9, 10, 11, 12, 36.
 *
 * HOW THE RECEIPTS AND THE MARKS ARE KEPT UNLINKABLE
 * ==================================================
 *
 * This is the one thing in this file that is not a matter of taste, so it is
 * first. Platform invariant 3: turnout is provable and how a household voted is
 * not recoverable — not by the secretary, not by platform staff, not by a
 * database administrator with the whole estate in front of them.
 *
 * THE SEEDER NEVER FORMS THE PAIRING IN THE FIRST PLACE. It does not build 318
 * ballot papers and then write them out; it builds TWO INDEPENDENT STRUCTURES
 * that are never joined even in memory:
 *
 *   the electorate   which households voted — a list of unit ids, chosen per
 *                    phase so board 11's five turnout bars come out.
 *
 *   the count        what the 318 papers chose, IN AGGREGATE — one row per mark,
 *                    generated from the per-option totals board 11 prints.
 *
 * There is no third structure tying a unit to an option, and no loop that ever
 * holds both a unit and a mark at the same time. `Governance::castVote()` writes
 * one household's paper at a time because a resident casting a vote necessarily
 * knows their own choice; a seeder has no such excuse, and the aggregate is all
 * the boards ever draw.
 *
 * FOUR CORRELATIONS ARE REFUSED, EACH DELIBERATELY:
 *
 *   row order        Both arrays are shuffled before insert, SEPARATELY. The
 *                    receipts go in in one random order and the marks in
 *                    another, so the nth row of one is nothing to do with the
 *                    nth row of the other.
 *
 *   id order         `ballot_receipts.id` is an auto-increment and `mark_id` is
 *                    128 bits from `random_bytes`. Sorting the marks by their
 *                    key sorts them by nothing. There is also no arithmetic
 *                    relation between the two counts: 318 receipts against 1,670
 *                    marks, because a three-seat race takes three marks off one
 *                    paper.
 *
 *   clock            `ballot_marks` has no temporal column at all — the
 *                    migration refuses one — and `voted_on` here is a DATE
 *                    spread across the polling week. Even if a clock appeared on
 *                    the marks tomorrow, a date is too coarse to pair with it.
 *
 *   payload          Nothing on a mark names a household, a unit, a resident or
 *                    a phase. The only column beside the random key is the
 *                    option, and an option reaches its ballot through the paper.
 *
 * WHAT IS TRUE IS TRUE ONLY AS A TOTAL: 318 receipts and 318 Chairman marks
 * agree because every household that voted marked one Chairman, and that is the
 * whole of the relationship. Nothing finer is recoverable, and nothing finer was
 * ever computed.
 *
 * BOTH TABLES ARE APPEND-ONLY AT THE DATABASE (see ApplyAppendOnlyGrants), so a
 * re-run cannot correct a half-written poll row by row — deleting marks one at a
 * time is itself the de-anonymising attack the grant exists to stop. When the
 * poll on disk does not match the poll this file describes, it is TRUNCATED
 * whole, through the schema owner, exactly as `EstateFinanceSeeder::resetLedger`
 * does and under the same local-and-testing-only guard.
 *
 * THE ELECTION IS SEEDED AT TWO MOMENTS, BECAUSE ITS BOARDS DRAW TWO
 * =================================================================
 *
 * Board 9 draws the 2026 election at "Nominations Open" with three days left to
 * run. Board 11 draws the same election at "Tally", voted and awaiting
 * certification. One ballot cannot be at both, and neither board is wrong: an
 * election is a year's worth of PAPERS, and board 9's own subtitle says there
 * are two of them.
 *
 * So Ballot A (Community Executive) has run its course — nominations closed,
 * candidates vetted, poll opened and shut, 318 papers counted — and sits at
 * Tally. Ballot B (Phase Leadership) opened its nominations later and is still
 * taking them. `Governance::leadBallot()` was built for exactly this: the
 * control room speaks for the LEAST advanced paper, so board 9's badge reads
 * "Nominations Open", and the results screen speaks for the most advanced, so
 * board 11 has a tally to draw.
 *
 * IT ALSO PUTS BOTH OFFICERS' CONTROLS IN REACH, which is the standing ruling on
 * this module. The Secretary RUNS an election, and Ballot B gives them a live
 * "Close nominations early" and five nominations to vet. The President and Vice
 * President CERTIFY one, and Ballot A gives them a tally to certify that nobody
 * else may. Seeding a single paper would have left one of those two roles with a
 * screen full of controls that refuse.
 *
 * EVERY DATE IS AN OFFSET FROM TODAY. Board 9's "3 days until nominations close"
 * and board 36's split between two upcoming meetings and three held ones are
 * arithmetic against the day the screen is drawn, not against September 2026. A
 * meeting seeded on a fixed date reads as "held" a fortnight later and the
 * register silently loses its two upcoming rows. `FacilitiesSeeder` places its
 * tickets the same way and for the same reason.
 *
 * CONTENT RESIDUALS, recorded rather than resolved by invention. See D-051.
 *
 *   - Board 11's five phase bars (80/65/74/58/69) and its "318 of 450" headline
 *     cannot both be true of 92, 104, 88, 96 and 70 units: the bars add to 311.
 *     The headline wins, because it also drives the turnout percentage and all
 *     four vote shares on the same screen — 187/318 is 59% and 187/311 is 60%.
 *     The bars come out within two points of the board's.
 *
 *   - Board 10 draws Michelle Palmer's nomination as "Pending review" and board
 *     11 gives her 201 votes and a Vice Chairman seat. A candidate cannot be on
 *     a paper nobody vetted, so hers is seeded approved. Keith Walters is the
 *     pending one the board's own arithmetic allows: board 9 counts three
 *     Chairman nominations and board 11 draws two Chairman candidates.
 *
 *   - Board 10 places Sonia Campbell at "Phase 4 · Lot 88" and Keith Walters at
 *     "Phase 2 · Lot 63"; the estate's map puts both lots in Phase 1. Board 4
 *     puts two different households at Lot 12, in two different phases, so the
 *     boards' phase labels cannot all be true of one estate. The UNIT wins —
 *     `Governance::nominationsBoard()` reads the phase off the property, because
 *     one estate has one map.
 *
 *   - Board 10 rejects Ricardo Hall for "arrears >90 days" and boards 5 and 6
 *     put Lot 9 in the 60-day bucket owing J$18,600. The reason is stored
 *     verbatim — it is the returning officer's recorded decision, not a computed
 *     value — and the eligibility snapshot written beside it is the ageing the
 *     ledger actually reports on the day the decision was taken. D-042 already
 *     rules that the sub-ledger wins.
 *
 *   - Board 36 dates the AGM "Sat, Sep 27" and the phase meeting "Sat, Oct 4";
 *     both fall on a Sunday. The DATES are seeded and the weekday is derived, so
 *     the screen is never internally wrong.
 */
class GovernanceSeeder extends Seeder
{
    /**
     * Board 9's first four position rows — the community executive, Ballot A.
     *
     * Vice Chairman takes three seats and that is not decoration: it is what
     * makes board 11's percentages mean two different things. Chairman's 59% and
     * 41% sum to 100 because one seat means one mark; Vice Chairman's 63% and
     * 55% sum to 118 because three seats mean a voter marks up to three names.
     *
     * @var list<array{name: string, seats: int}>
     */
    private const EXECUTIVE = [
        ['name' => 'Chairman', 'seats' => 1],
        ['name' => 'Vice Chairman', 'seats' => 3],
        ['name' => 'Secretary', 'seats' => 1],
        ['name' => 'Asst. Secretary', 'seats' => 1],
        ['name' => 'Treasurer', 'seats' => 1],
        ['name' => 'Asst. Treasurer', 'seats' => 1],
        ['name' => 'Security Coordinator', 'seats' => 1],
        ['name' => 'PRO', 'seats' => 1],
        ['name' => 'Asst. PRO', 'seats' => 1],
        ['name' => 'Welfare & Events', 'seats' => 1],
    ];

    /**
     * THE SIX THE CONSOLE BOARD NEVER LISTED, AND THE RESIDENT APP DRAWS BY NAME.
     *
     * Ballot A carried four positions until 23 September 2026, inferred from the console board's
     * "14 POSITIONS CONFIGURED" over a list its own brief called partial. The client's complaint —
     * that the election is missing committee positions — sent us back to the boards, and the
     * Resident App's "Review your vote" screen (resident-app-34) DRAWS THE WHOLE PAPER: Chairman,
     * Vice Chairman (3), Secretary, Asst. Secretary, Treasurer, Asst. Treasurer, Security
     * Coordinator, PRO, Asst. PRO, Welfare & Events. An explicit list outranks an aggregate count.
     *
     * Each of the six new ones gets the holder that screen names, as an approved nomination, so the
     * paper a resident opens has somebody to vote for on every line rather than six empty sections.
     *
     * @var array<string, string>
     */
    private const DRAWN_HOLDERS = [
        'Asst. Secretary' => 'Keith Walters',
        'Asst. Treasurer' => 'Patrice Reid',
        'Security Coordinator' => 'Owen Grant',
        'PRO' => 'Natalie Wong',
        'Asst. PRO' => 'Omar Brown',
        'Welfare & Events' => 'Tanya Simms',
    ];

    /**
     * Ballot B — two phase-scoped seats in every phase.
     *
     * BOARD 9 NAMES ONE OF THEM AND COUNTS FOURTEEN. It draws "Phase 2 · Phase
     * Lead" and prints "14 POSITIONS CONFIGURED" over a list its own brief calls
     * partial, so nine positions are counted and not shown. Four executive seats
     * and ten phase seats is the only reading that reaches fourteen without
     * inventing executive posts nobody drew, and it follows the one phase-scoped
     * row the board does draw: every phase elects a lead, and every phase sends
     * a representative.
     *
     * Seeding only the five drawn rows would leave board 9's headline stat
     * unreachable, which is the same argument that makes the estate 450 units
     * rather than four.
     *
     * @var list<string>
     */
    private const PHASE_SEATS = ['Phase Lead', 'Phase Representative'];

    /**
     * The five nominations board 10 draws, verbatim, in its own row order.
     *
     * `lot` is the candidate's own household. Nominator and seconder carry a lot
     * only where a board gives them one — board 4's directory puts Rachel
     * Bennett at Lot 3 and Natalie Wong at Lot 12, but Lot 12 is already Dwayne
     * Robinson's, so hers is left unresolved rather than assigned to a household
     * that is somebody else's. The name is stored either way, which is what
     * board 10 prints.
     *
     * @var list<array{position: string, candidate: string, lot: string|null, nominator: string, nominator_lot: string|null, seconder: string, seconder_lot: string|null, status: string, reason: string|null}>
     */
    private const DRAWN = [
        [
            'position' => 'Chairman',
            'candidate' => 'Dwayne Robinson', 'lot' => 'Lot 12',
            'nominator' => 'Rachel Bennett', 'nominator_lot' => 'Lot 3',
            'seconder' => 'Owen Grant', 'seconder_lot' => null,
            'status' => Nomination::APPROVED, 'reason' => null,
        ],
        [
            'position' => 'Chairman',
            'candidate' => 'Sonia Campbell', 'lot' => 'Lot 88',
            'nominator' => 'Natalie Wong', 'nominator_lot' => null,
            'seconder' => 'Keith Walters', 'seconder_lot' => 'Lot 63',
            'status' => Nomination::APPROVED, 'reason' => null,
        ],

        /*
         * THE ONE NOMINATION THAT IS GENUINELY STILL PENDING, and the boards'
         * own arithmetic is what identifies it: board 9 counts three Chairman
         * nominations, board 11 draws two Chairman candidates, so exactly one of
         * the three never made the paper. A nomination nobody has ruled on is a
         * nomination whose candidate is not standing.
         */
        [
            'position' => 'Chairman',
            'candidate' => 'Keith Walters', 'lot' => 'Lot 63',
            'nominator' => 'Sonia Campbell', 'nominator_lot' => 'Lot 88',
            'seconder' => 'Andrea Fletcher', 'seconder_lot' => 'Lot 47',
            'status' => Nomination::PENDING, 'reason' => null,
        ],

        // Board 10 draws this one pending too. It cannot be: she takes a Vice
        // Chairman seat on board 11 with 201 votes, and a paper cannot carry a
        // candidate nobody vetted. Approved, and the residual recorded.
        [
            'position' => 'Vice Chairman',
            'candidate' => 'Michelle Palmer', 'lot' => 'Lot 55',
            'nominator' => 'Ricardo Hall', 'nominator_lot' => 'Lot 9',
            'seconder' => 'Tanya Simms', 'seconder_lot' => 'Lot 21',
            'status' => Nomination::APPROVED, 'reason' => null,
        ],

        /*
         * "Rejection always carries a recorded reason" — the Build Spec, and
         * board 10 prints this one inside the badge. It is stored exactly as the
         * board writes it because it is what the returning officer decided and
         * what the candidate is entitled to be told; the arithmetic that was
         * supposed to justify it is snapshotted separately, and disagrees. See
         * the class docblock.
         */
        [
            'position' => 'Vice Chairman',
            'candidate' => 'Ricardo Hall', 'lot' => 'Lot 9',
            'nominator' => 'Michelle Palmer', 'nominator_lot' => 'Lot 55',
            'seconder' => 'Devon Grant', 'seconder_lot' => null,
            'status' => Nomination::REJECTED, 'reason' => 'arrears >90 days',
        ],
    ];

    /**
     * The executive nominations board 9 counts and board 10 does not draw.
     *
     * Board 9's position rows give Chairman 3, Vice Chairman 5, Secretary 2 and
     * Treasurer 2. Three of those fourteen are drawn against Chairman and two
     * against Vice Chairman, so these are the rest.
     *
     * @var array<string, int>
     */
    private const EXECUTIVE_FILLERS = [
        'Vice Chairman' => 3,
        'Secretary' => 2,
        'Treasurer' => 2,
    ];

    /**
     * How many candidates stood for each phase seat, by the phase's position in
     * the estate's own ordered list of phases.
     *
     * Board 9 gives one figure here — "Phase 2 · Phase Lead · 2 nominated" — and
     * a total of 22 across the whole election. Twelve of those are the executive
     * above, so ten are phase nominations, and Phase 2's two are placed where
     * the board puts them.
     *
     * @var array<string, list<int>>
     */
    private const PHASE_NOMINATIONS = [
        'Phase Lead' => [1, 2, 1, 1, 1],
        'Phase Representative' => [1, 1, 1, 1, 0],
    ];

    /**
     * How many of Ballot B's nominations are still waiting on the officer.
     *
     * Board 9's button reads "Review nominations (5 pending)" and board 10's
     * amber chip agrees. One of the five is Keith Walters on the executive
     * paper; these four are the rest, and they are the four most recently
     * lodged — nominations are vetted in the order they arrive, and the officer
     * has not reached the newest batch.
     */
    private const PENDING_ON_PHASE_BALLOT = 4;

    /**
     * The candidates on Ballot A's paper that no nomination row produces.
     *
     * ONE NAME, AND THE MIGRATION USES HIM AS ITS PROOF: Andre Thompson wins a
     * Vice Chairman seat on board 11 and appears in no nomination row anywhere.
     * A paper is settled when nominations close and stops depending on the
     * vetting record that produced it, which is exactly why `ballot_options` is
     * a table of its own. He carries a phase and no unit, because Phase 5 is all
     * board 11 says about him and a lot number would be invented.
     *
     * @var array<string, list<array{label: string, phase: string}>>
     */
    private const OFF_REGISTER = [
        'Vice Chairman' => [
            ['label' => 'Andre Thompson', 'phase' => 'Phase 5'],
        ],
    ];

    /**
     * Where each name sits on the paper before the count is applied.
     *
     * Board 11 ranks its cards by votes descending, so this is that order for
     * the candidates it draws; everyone else follows in nomination order. Only
     * the drawn names are fixed here — the rest are the estate's own
     * householders and are resolved when the nominations are written.
     *
     * @var array<string, list<string>>
     */
    private const PAPER_HEAD = [
        'Chairman' => ['Dwayne Robinson', 'Sonia Campbell'],
        'Vice Chairman' => ['Michelle Palmer', 'Andre Thompson'],
    ];

    /**
     * The marks each option carries, in paper order — board 11's result cards.
     *
     * FOUR OF THESE ELEVEN FIGURES ARE THE BOARD'S: 187 and 131 for Chairman,
     * 201 and 176 for the first two Vice Chairman seats. The rest are the
     * counts board 11 does not draw, and every one of them is constrained rather
     * than chosen freely — an option cannot exceed the 318 papers cast, and a
     * position cannot exceed its seats times 318, because that is what a paper
     * physically permits.
     *
     * Chairman's two sum to exactly 318, which is the board telling us every
     * household that voted marked a Chairman. Vice Chairman's five sum to 731
     * against a ceiling of 954. The third Vice Chairman seat goes to the 149,
     * which is board 11's own note that its card shows "only 2 of 3 seats filled
     * in this slice".
     *
     * @var array<string, list<int>>
     */
    private const COUNT = [
        'Chairman' => [187, 131],
        'Vice Chairman' => [201, 176, 149, 118, 87],
        'Secretary' => [174, 137],
        'Treasurer' => [189, 121],
    ];

    /**
     * How many households voted, by phase — board 11's five bars.
     *
     * THE BARS AND THE HEADLINE DISAGREE, and this is the arithmetic. The
     * board's own percentages against the board's own phase sizes give 74, 68,
     * 65, 56 and 48 households: 311, not the 318 the headline states, and no
     * other whole number of households renders as 80%, 65%, 74%, 58% or 69% of
     * 92, 104, 88, 96 and 70. The seven-household difference is not a rounding
     * error, it is two figures that cannot both be true.
     *
     * The headline wins — it is the numerator of the turnout percentage AND the
     * denominator of all four vote shares on the same screen, so 311 would move
     * five figures to keep five captions. The seven extra households are placed
     * where one household moves a printed bar least: every phase but Phase 1
     * shifts by a single point per household, while Phase 1 sits at 80.4% and
     * would jump straight to 82%. So Phase 1 keeps the board's figure exactly
     * and the other four take two, two, two and one.
     *
     * @var array<string, int>
     */
    private const TURNOUT = [
        'Phase 1' => 74,
        'Phase 2' => 70,
        'Phase 3' => 67,
        'Phase 4' => 58,
        'Phase 5' => 49,
    ];

    /** Board 11's headline numerator. The sum of TURNOUT, asserted before a row is written. */
    private const BALLOTS_CAST = 318;

    /**
     * The lifecycle, as days before or after today.
     *
     * Board 9's "3 days" is the only one of these the screen prints as
     * arithmetic, and it is the reason none of them is a date: seeded on a fixed
     * September afternoon it would read "-84 days" by Christmas.
     */
    private const A_NOMINATIONS_OPENED = -60;

    private const A_NOMINATIONS_CLOSED = -46;

    private const A_VETTED = -44;

    private const A_POLL_OPENED = -12;

    private const A_POLL_CLOSED = -5;

    private const B_NOMINATIONS_OPENED = -11;

    private const B_VETTED = -4;

    /** Board 9's "3 days" stat, and the whole reason the close is an offset. */
    private const B_NOMINATIONS_CLOSE = 3;

    /**
     * Board 36's register, in the order it draws it.
     *
     * `days` is the offset from today that reproduces the board's own dates when
     * this is seeded in early September; `hour` and `minute` are the start time
     * the board prints for the two upcoming rows. `present` is how many of the
     * register were in the room, chosen so `Governance::quorumOf()` renders the
     * board's own badge — 34% of 450 households is 153, 41% is 185, and the
     * committee's is a head count rather than a proportion.
     *
     * Titles carrying a year or a month are DERIVED from the seeded date rather
     * than written here, so "AGM 2026" is still the right name for the AGM
     * seventeen days out whenever this runs.
     *
     * @var list<array{key: string, type: string, scope: string, phase: string|null, days: int, hour: int, minute: int, present: int, apologies: int, members: int|null, recording: bool}>
     */
    private const MEETINGS = [
        [
            'key' => 'agm-next', 'type' => Meeting::AGM, 'scope' => Meeting::WHOLE_ESTATE, 'phase' => null,
            'days' => 17, 'hour' => 10, 'minute' => 0,
            'present' => 0, 'apologies' => 0, 'members' => null, 'recording' => true,
        ],
        [
            'key' => 'phase-wall', 'type' => Meeting::PHASE, 'scope' => Meeting::PHASE_SUBSET, 'phase' => 'Phase 2',
            'days' => 24, 'hour' => 14, 'minute' => 0,
            'present' => 0, 'apologies' => 0, 'members' => null, 'recording' => false,
        ],
        [
            'key' => 'committee', 'type' => Meeting::COMMITTEE, 'scope' => Meeting::COMMITTEE_ONLY, 'phase' => null,
            'days' => -2, 'hour' => 18, 'minute' => 0,
            'present' => 6, 'apologies' => 1, 'members' => 7, 'recording' => false,
        ],
        [
            'key' => 'egm-security', 'type' => Meeting::EGM, 'scope' => Meeting::WHOLE_ESTATE, 'phase' => null,
            'days' => -53, 'hour' => 18, 'minute' => 30,
            'present' => 153, 'apologies' => 0, 'members' => null, 'recording' => false,
        ],
        [
            'key' => 'agm-last', 'type' => Meeting::AGM, 'scope' => Meeting::WHOLE_ESTATE, 'phase' => null,
            'days' => -354, 'hour' => 10, 'minute' => 0,
            'present' => 185, 'apologies' => 0, 'members' => null, 'recording' => false,
        ],
    ];

    /**
     * The seven the committee register holds, six of them in the room.
     *
     * REAL PEOPLE FROM THE BOARDS AND NOWHERE ELSE. Board 36's sidebar names
     * Delroy Samuels the Secretary; `DemoDataSeeder` seats Patrice Campbell,
     * Tracey Reid and Patricia Morgan on Phoenix Park's committee; the rest are
     * residents the estate's own directory names. Inventing three committee
     * members would put three people who do not exist into a set of minutes.
     *
     * @var list<array{name: string, present: bool}>
     */
    private const COMMITTEE_REGISTER = [
        ['name' => 'Patrice Campbell', 'present' => true],
        ['name' => 'Tracey Reid', 'present' => true],
        ['name' => 'Delroy Samuels', 'present' => true],
        ['name' => 'Patricia Morgan', 'present' => true],
        ['name' => 'Dwayne Robinson', 'present' => true],
        ['name' => 'Michelle Palmer', 'present' => true],
        ['name' => 'Andrea Fletcher', 'present' => false],
    ];

    /**
     * The Returning Officer, named on boards 9 and 36.
     *
     * Stored as a NAME WITH NO ID, and the migration says why: `users` is the
     * central database and MySQL cannot constrain a key across databases. There
     * is also no Delroy Samuels account — `DemoDataSeeder` seats a President, a
     * Treasurer and a Property Manager on this estate and no Secretary — and
     * this seeder runs inside tenancy, where the central `users` table is not
     * even on the connection. The name is what both boards print.
     */
    private const RETURNING_OFFICER = 'Delroy Samuels';

    /**
     * The lots the boards name, which the fillers below must not take.
     *
     * @var list<string>
     */
    private const RESERVED_LOTS = [
        'Lot 3', 'Lot 9', 'Lot 12', 'Lot 21', 'Lot 31', 'Lot 47', 'Lot 55', 'Lot 63', 'Lot 88',
    ];

    /**
     * Occupied units per phase that no board has spoken for, in lot order.
     *
     * @var array<string, list<Unit>>
     */
    private array $pool = [];

    /** @var array<string, int> how far into each phase's pool this run has drawn */
    private array $drawn = [];

    /** @var array<int, string> unit id => the householder's name, as the estate records it */
    private array $householders = [];

    /** @var list<string> the estate's phases, in its own order */
    private array $phases = [];

    /** Which phase the next estate-wide filler comes from, so the executive is not all one phase. */
    private int $rotation = 0;

    public function run(): void
    {
        $this->phases = $this->phases();

        /*
         * AN ESTATE THAT IS NOT THE ESTATE THESE BOARDS DRAW IS LEFT ALONE.
         *
         * Board 11 names five phases and states how many households in each of
         * them voted; board 9 states 450 eligible; every figure downstream is a
         * fraction of one of those. An estate whose map is a different shape is
         * not a degraded version of that election — it is a screen nobody can
         * review, and half of one is worse than none.
         *
         * TWO CHECKS, AND THE FIRST IS THE STRICT ONE. The phases must be
         * exactly the five the turnout bars name, in the estate's own order:
         * Ocean View currently carries four "Block A"–"Block D" units alongside
         * a full five-phase estate, which would configure twenty-two positions
         * against board 9's fourteen and leave four phase pools holding a single
         * household each. The second check is per phase rather than on the
         * total, because each bar needs its own households and a phase that is
         * short would silently draw at zero.
         */
        if ($this->phases !== array_keys(self::TURNOUT)) {
            return;
        }

        foreach (self::TURNOUT as $phase => $needed) {
            if (count($this->occupied($phase)) < $needed) {
                return;
            }
        }

        $this->householders = $this->householders();
        $this->buildPool();

        $this->seedElection();
        $this->seedMeetings();
    }

    /* ------------------------------------------------------------------ */
    /* the election — boards 9, 10 and 11 */
    /* ------------------------------------------------------------------ */

    private function seedElection(): void
    {
        // ONE TIME RULE (M4 §3.1): "nominations close 5 PM", "the poll closes 8 PM" are ON THE ESTATE'S
        // CLOCK — built from the estate's own today in its zone; the model stores the UTC moment each means.
        $today = SiteTime::today();
        $year = $today->year;
        // ONE VOTE PER HOUSEHOLD, so eligibility counts HOUSEHOLDS. It counted units until
        // 23 September 2026, which was nearly right on a 450-lot estate where almost every lot
        // was occupied, and badly wrong on Phoenix Park's real 2,000: quorum would have needed
        // 25% of 2,000 = 500 households when the estate has 441, so quorum could never be met.
        // A vacant lot has nobody on it and casts nothing.
        $eligible = Household::query()->count();

        /*
         * BALLOT A IS THE ONE THAT HAS BEEN VOTED. Its window is behind us, its
         * denominator was frozen when the poll opened, and it sits at Tally
         * because board 11's own row-label says the stage advances there the
         * moment voting closes — there is nothing a human does in between.
         *
         * It is deliberately NOT certified. Certification is the President's or
         * Vice President's act and it is irreversible; seeding it done would
         * take board 11's only button away from the two roles it belongs to and
         * leave a screen whose every control refuses.
         */
        $executive = Ballot::updateOrCreate(
            ['year' => $year, 'code' => 'A'],
            [
                'title' => 'Ballot A (Community Executive)',
                'kind' => Ballot::ELECTION,
                'stage' => Ballot::TALLY,
                'nominations_open_at' => $today->copy()->addDays(self::A_NOMINATIONS_OPENED),
                'nominations_close_at' => $today->copy()->addDays(self::A_NOMINATIONS_CLOSED)->addHours(17),
                'opens_at' => $today->copy()->addDays(self::A_POLL_OPENED)->addHours(6),
                'closes_at' => $today->copy()->addDays(self::A_POLL_CLOSED)->addHours(20),

                // Frozen at the moment the poll opened. Board 11 prints "318 of
                // 450 households (71%)" as a certified fact, and a unit added
                // next March must not restate it.
                'eligible_households' => $eligible,
                'quorum_percent' => EstateSetting::current()->meeting_quorum_percent,
                'returning_officer_name' => self::RETURNING_OFFICER,
            ],
        );

        /*
         * BALLOT B IS STILL TAKING NOMINATIONS, and it is what board 9 speaks
         * for: `Governance::controlRoom()` takes the least advanced paper,
         * because an election is not at "Voting Open" while one of its papers is
         * still open to candidates.
         *
         * Its close is three days out to the hour, which is where board 9's "3
         * days" stat comes from — `controlRoom()` takes the whole days between
         * midnight today and that moment, so an afternoon close reads as three
         * days rather than tipping to two.
         */
        $leadership = Ballot::updateOrCreate(
            ['year' => $year, 'code' => 'B'],
            [
                'title' => 'Ballot B (Phase Leadership)',
                'kind' => Ballot::ELECTION,
                'stage' => Ballot::NOMINATIONS_OPEN,
                'nominations_open_at' => $today->copy()->addDays(self::B_NOMINATIONS_OPENED),
                'nominations_close_at' => $today->copy()->addDays(self::B_NOMINATIONS_CLOSE)->addHours(17),

                // Kept current rather than frozen: the denominator only stops
                // moving when a poll opens, and this one has not.
                'eligible_households' => $eligible,
                'quorum_percent' => EstateSetting::current()->meeting_quorum_percent,
                'returning_officer_name' => self::RETURNING_OFFICER,
            ],
        );

        $positions = $this->seedPositions($executive, $leadership);

        $this->seedNominations($executive, $leadership, $positions);
        $this->seedPoll($executive, $this->seedPaper($executive, $positions));
    }

    /**
     * Board 9's fourteen position rows: four executive seats and ten phase ones.
     *
     * ONE SORT ORDER ACROSS BOTH PAPERS, not one per ballot. `controlRoom()`
     * lists every position of the year in a single table and orders it by this
     * column, so two ballots each starting at 1 would interleave the executive
     * with the phase seats — and board 9 draws the executive first with the
     * phase-scoped rows last.
     *
     * @return array<string, BallotPosition> display name => the seat
     */
    private function seedPositions(Ballot $executive, Ballot $leadership): array
    {
        $positions = [];
        $order = 0;

        foreach (self::EXECUTIVE as $seat) {
            $order++;

            $positions[$seat['name']] = BallotPosition::updateOrCreate(
                ['ballot_id' => $executive->id, 'name' => $seat['name'], 'phase' => null],
                [
                    'seat_count' => $seat['seats'],
                    'scope' => BallotPosition::ESTATE,
                    'sort_order' => $order,
                ],
            );
        }

        foreach (self::PHASE_SEATS as $seat) {
            foreach ($this->phases as $phase) {
                $order++;

                /*
                 * The phase is a column and not part of the name, so that two
                 * phases electing a lead are two rows of the same seat. Board 9
                 * prints "Phase 2 · Phase Lead" by joining them, and swaps the
                 * seat-count chip for an amber "Phase-scoped" one, because who
                 * may mark that part of the paper is a different question from
                 * how many are elected to it.
                 */
                $positions[$phase.' · '.$seat] = BallotPosition::updateOrCreate(
                    ['ballot_id' => $leadership->id, 'name' => $seat, 'phase' => $phase],
                    [
                        'seat_count' => 1,
                        'scope' => BallotPosition::PHASE,
                        'sort_order' => $order,
                    ],
                );
            }
        }

        return $positions;
    }

    /**
     * Board 9's twenty-two candidates, five of which board 10 draws.
     *
     * THE SEVENTEEN THE BOARDS DO NOT NAME ARE THE ESTATE'S OWN HOUSEHOLDERS,
     * named exactly as the residents table names them. Inventing seventeen
     * Jamaican names would put seventeen people who do not exist onto a screen a
     * client reviews, and `EstateFinanceSeeder` already refused that for the
     * estate's 440 unnamed residents — a nomination reading "Lot 214
     * householder" is this estate telling the truth about which of its residents
     * have been named, and it becomes a real name the moment one is recorded.
     *
     * Nobody nominates or seconds themselves: candidate, nominator and seconder
     * are drawn from one advancing cursor over each phase's households.
     *
     * @param  array<string, BallotPosition>  $positions
     */
    private function seedNominations(Ballot $executive, Ballot $leadership, array $positions): void
    {
        $today = Carbon::today();

        foreach (self::DRAWN as $row) {
            $position = $positions[$row['position']] ?? null;

            if (! $position instanceof BallotPosition) {
                continue;
            }

            $this->writeNomination(
                ballot: $executive,
                position: $position,
                candidate: $row['candidate'],
                candidateUnit: $this->lot($row['lot']),
                nominator: $row['nominator'],
                nominatorUnit: $this->person($row['nominator'], $row['nominator_lot']),
                seconder: $row['seconder'],
                seconderUnit: $this->person($row['seconder'], $row['seconder_lot']),
                status: $row['status'],
                reason: $row['reason'],
                decidedOn: $today->copy()->addDays(self::A_VETTED),
            );
        }

        /*
         * The six positions the Resident App's review screen draws by name, each with the holder it
         * draws. Written the same way as the ones the console board listed — an approved nomination
         * against the position — so a resident opening the paper finds every line votable.
         */
        foreach (self::DRAWN_HOLDERS as $name => $candidate) {
            $position = $positions[$name] ?? null;

            if (! $position instanceof BallotPosition) {
                continue;
            }

            $this->writeNomination(
                ballot: $executive,
                position: $position,
                candidate: $candidate,
                candidateUnit: null,
                nominator: 'Andrea Fletcher',
                nominatorUnit: $this->person('Andrea Fletcher', 'Lot 47'),
                seconder: 'Dwayne Robinson',
                seconderUnit: $this->person('Dwayne Robinson', 'Lot 12'),
                status: Nomination::APPROVED,
                reason: null,
                decidedOn: $today->copy()->addDays(self::A_VETTED),
            );
        }

        foreach (self::EXECUTIVE_FILLERS as $name => $count) {
            $position = $positions[$name] ?? null;

            if (! $position instanceof BallotPosition) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                /*
                 * An executive seat is voted on estate-wide, so its candidates
                 * come from the estate rather than from one phase — taken in
                 * rotation so the committee is not drawn entirely out of Phase 1.
                 */
                $phase = $this->nextPhase();

                $this->writeFiller(
                    ballot: $executive,
                    position: $position,
                    phase: $phase,
                    status: Nomination::APPROVED,
                    decidedOn: $today->copy()->addDays(self::A_VETTED),
                );
            }
        }

        /*
         * Ballot B's ten, and the last four of them are the pending ones board
         * 9's button counts. Built as a list first so "the four most recently
         * lodged" is a fact about the order rather than a status hardcoded onto
         * particular seats.
         *
         * @var list<array{position: BallotPosition, phase: string}> $lodged
         */
        $lodged = [];

        foreach (self::PHASE_SEATS as $seat) {
            foreach ($this->phases as $index => $phase) {
                $position = $positions[$phase.' · '.$seat] ?? null;

                if (! $position instanceof BallotPosition) {
                    continue;
                }

                $count = self::PHASE_NOMINATIONS[$seat][$index] ?? 0;

                for ($i = 0; $i < $count; $i++) {
                    $lodged[] = ['position' => $position, 'phase' => $phase];
                }
            }
        }

        $undecidedFrom = count($lodged) - self::PENDING_ON_PHASE_BALLOT;

        foreach ($lodged as $index => $entry) {
            $pending = $index >= $undecidedFrom;

            $this->writeFiller(
                ballot: $leadership,
                position: $entry['position'],

                // A phase seat is voted on by its own phase, so its candidates
                // live in it. A Phase 3 Representative from Phase 1 would be a
                // household standing for a phase it cannot vote in.
                phase: $entry['phase'],
                status: $pending ? Nomination::PENDING : Nomination::APPROVED,
                decidedOn: $pending ? null : $today->copy()->addDays(self::B_VETTED),
            );
        }
    }

    /**
     * One nomination from the estate's own roll, for a seat no board draws.
     */
    private function writeFiller(
        Ballot $ballot,
        BallotPosition $position,
        string $phase,
        string $status,
        ?Carbon $decidedOn,
    ): void {
        $candidate = $this->nextUnit($phase);
        $nominator = $this->nextUnit($phase);
        $seconder = $this->nextUnit($phase);

        /*
         * LOUD RATHER THAN SHORT. A phase that runs out of households mid-way
         * would leave a ballot paper with some of its candidates on it and board
         * 9's stat row quietly wrong — and the poll below would then find a
         * count it did not write, truncate the whole vote and rewrite it on
         * every single run. The guard in `run()` is what makes this unreachable;
         * this is what says so if it ever is.
         */
        if ($candidate === null || $nominator === null || $seconder === null) {
            throw new RuntimeException(
                'Phase ['.$phase.'] has run out of households to put forward for '.$position->displayName().
                '. The election these boards draw needs more of them than this estate has, and half an '.
                'election is worse than none.'
            );
        }

        $this->writeNomination(
            ballot: $ballot,
            position: $position,
            candidate: $this->nameOf($candidate),
            candidateUnit: $candidate,
            nominator: $this->nameOf($nominator),
            nominatorUnit: $nominator,
            seconder: $this->nameOf($seconder),
            seconderUnit: $seconder,
            status: $status,
            reason: null,
            decidedOn: $decidedOn,
        );
    }

    /**
     * Write one nomination and, where it has been decided, the eligibility
     * snapshot behind the decision.
     *
     * THE SNAPSHOT IS TAKEN AS AT THE DAY THE DECISION WAS MADE, not today.
     * Board 10's accounting note asks for exactly this: the check "needs a
     * snapshot date so the ageing that justified the rejection can be reproduced
     * later". A candidate who clears their arrears the following week must not
     * be able to open this screen and find the ledger saying they were never in
     * arrears — which is true today and was not true on the day the officer
     * ruled.
     *
     * It is written on an ACCEPTANCE as well as a refusal, because a record that
     * only kept the arithmetic behind refusals could not answer the more awkward
     * question — why this candidate was let through — and that is the one a
     * losing candidate asks.
     *
     * The rows are written through the model rather than through
     * `Governance::accept()` and `::reject()` for the same reason
     * `FacilitiesSeeder` does not replay `Maintenance`: those methods stamp the
     * moment they run and take a `User`, and these decisions were taken six
     * weeks ago by a Secretary this platform has no account for.
     */
    private function writeNomination(
        Ballot $ballot,
        BallotPosition $position,
        string $candidate,
        ?Unit $candidateUnit,
        string $nominator,
        ?Unit $nominatorUnit,
        string $seconder,
        ?Unit $seconderUnit,
        string $status,
        ?string $reason,
        ?Carbon $decidedOn,
    ): void {
        $check = $decidedOn !== null && $candidateUnit !== null
            ? app(Governance::class)->eligibility($candidateUnit, null, $decidedOn)
            : null;

        /*
         * KEYED ON THE SEAT AND THE HOUSEHOLD, NOT ON THE NAME.
         *
         * A household is the stable identity here and a name is not: seventeen
         * of these candidates are the estate's own householders, and the moment
         * the residents register learns that Lot 97 is Natalie Wong, a
         * name-keyed row stops matching and a second nomination appears for the
         * same seat and the same household. That is precisely the stacking a
         * seeder must be unable to do, and it happened once — see D-052.
         */
        Nomination::updateOrCreate(
            [
                'ballot_id' => $ballot->id,
                'ballot_position_id' => $position->id,
                'unit_id' => $candidateUnit?->id,
            ],
            [
                'candidate_name' => $candidate,
                'nominator_unit_id' => $nominatorUnit?->id,
                'nominator_name' => $nominator,
                'seconder_unit_id' => $seconderUnit?->id,
                'seconder_name' => $seconder,
                'status' => $status,
                'decision_reason' => $reason,
                'decided_at' => $decidedOn,
                'decided_by_name' => $decidedOn === null ? null : self::RETURNING_OFFICER,
                'eligibility_checked_on' => $check['checked_on'] ?? null,
                'arrears_bucket_at_check' => $check['arrears_bucket'] ?? null,
                'arrears_minor_at_check' => $check['arrears_minor'] ?? null,
                'tenure_months_at_check' => $check['tenure_months'] ?? null,
            ],
        );
    }

    /**
     * Ballot A's paper: who could be marked, and how many marks each carries.
     *
     * THE PAPER IS NOT THE NOMINATION REGISTER, and board 11 proves it — Andre
     * Thompson wins a seat and stands in no nomination row. A paper is settled
     * when nominations close and stops depending on the vetting record that
     * produced it, because a nomination re-decided afterwards would otherwise
     * silently rewrite a paper people had already voted on, and there is no way
     * to un-cast the votes made against the old one.
     *
     * @param  array<string, BallotPosition>  $positions
     * @return array<int, int> option id => marks it carries
     */
    private function seedPaper(Ballot $ballot, array $positions): array
    {
        $votes = [];

        foreach (self::EXECUTIVE as $seat) {
            $position = $positions[$seat['name']] ?? null;

            if (! $position instanceof BallotPosition) {
                continue;
            }

            foreach ($this->paperFor($position, $seat['name']) as $index => $candidate) {
                $count = self::COUNT[$seat['name']][$index] ?? 0;

                /*
                 * KEYED ON THE SEAT AND THE PLACE ON THE PAPER, for the same
                 * reason the nomination above is not keyed on a name — and the
                 * unit cannot serve here either, because Andre Thompson carries
                 * none. The paper's order is settled by the vetting record and
                 * does not move when a resident is renamed.
                 */
                $option = BallotOption::updateOrCreate(
                    [
                        'ballot_id' => $ballot->id,
                        'ballot_position_id' => $position->id,
                        'sort_order' => $index + 1,
                    ],
                    [
                        'label' => $candidate['label'],
                        'unit_id' => $candidate['unit_id'],

                        /*
                         * Board 11 prints "Phase 1" under a winner's name and it
                         * is read off the candidate's own property, not off the
                         * label the board wrote — board 10 places Sonia Campbell
                         * in Phase 4 and Lot 88 is in Phase 1. One estate has one
                         * map.
                         */
                        'phase' => $candidate['phase'],
                        'sort_order' => $index + 1,
                    ],
                );

                $votes[$option->id] = $count;
            }
        }

        return $votes;
    }

    /**
     * Who is on the paper for one seat, in the order board 11 ranks them.
     *
     * The names board 11 draws come first and in its order; the approved
     * nominations it does not draw follow, oldest first; a candidate who was
     * never nominated is inserted where the board puts him.
     *
     * @return list<array{label: string, unit_id: int|null, phase: string|null}>
     */
    private function paperFor(BallotPosition $position, string $seat): array
    {
        /** @var array<string, array{label: string, unit_id: int|null, phase: string|null}> $paper */
        $paper = [];

        foreach (self::OFF_REGISTER[$seat] ?? [] as $stranger) {
            $paper[$stranger['label']] = [
                'label' => $stranger['label'],
                'unit_id' => null,
                'phase' => $stranger['phase'],
            ];
        }

        $approved = Nomination::query()
            ->where('ballot_position_id', $position->id)
            ->where('status', Nomination::APPROVED)
            ->orderBy('id')
            ->get();

        foreach ($approved as $nomination) {
            $paper[$nomination->candidate_name] = [
                'label' => $nomination->candidate_name,
                'unit_id' => $nomination->unit_id,
                'phase' => $nomination->unit?->block,
            ];
        }

        $ordered = [];

        foreach (self::PAPER_HEAD[$seat] ?? [] as $name) {
            if (isset($paper[$name])) {
                $ordered[] = $paper[$name];
                unset($paper[$name]);
            }
        }

        foreach ($paper as $row) {
            $ordered[] = $row;
        }

        return $ordered;
    }

    /**
     * The poll: who voted, and what the papers said. NEVER BOTH AT ONCE.
     *
     * Read the class docblock before changing a line of this method. The two
     * arrays below are built independently, shuffled independently and written
     * independently, and no code path anywhere holds a unit id and an option id
     * in the same expression.
     *
     * @param  array<int, int>  $votes  option id => marks
     */
    private function seedPoll(Ballot $ballot, array $votes): void
    {
        $electorate = $this->electorate();
        $marks = array_sum($votes);

        if (count($electorate) !== self::BALLOTS_CAST) {
            throw new RuntimeException(sprintf(
                'The seeded electorate came out at %d households against board 11\'s %d. The phase '.
                'turnout and the headline no longer agree, and a turnout figure that is a little bit '.
                'wrong is worse than none at all.',
                count($electorate),
                self::BALLOTS_CAST,
            ));
        }

        $onDisk = DB::connection('tenant')->table('ballot_receipts')->where('ballot_id', $ballot->id)->count();

        $counted = DB::connection('tenant')
            ->table('ballot_marks')
            ->join('ballot_options', 'ballot_options.id', '=', 'ballot_marks.ballot_option_id')
            ->where('ballot_options.ballot_id', $ballot->id)
            ->count();

        // Already polled, and correctly. A second run must not add a second
        // turnout on top of the first — and could not, since neither table
        // accepts an UPDATE or a DELETE.
        if ($onDisk === count($electorate) && $counted === $marks) {
            return;
        }

        if ($onDisk > 0 || $counted > 0) {
            $this->clearPoll();
        }

        $this->writeReceipts($ballot, $electorate);
        $this->writeMarks($votes);
    }

    /**
     * Which households voted — and nothing whatever about what they chose.
     *
     * @return list<int> unit ids
     */
    private function electorate(): array
    {
        $units = [];

        foreach (self::TURNOUT as $phase => $voted) {
            foreach (array_slice($this->occupied($phase), 0, $voted) as $unit) {
                $units[] = $unit->id;
            }
        }

        return $units;
    }

    /**
     * Turnout, written in a shuffled order over a spread of dates.
     *
     * The shuffle is not decoration. Insert these in unit order and the receipt
     * ids run parallel to the estate's own lot numbering, which is one half of a
     * correspondence — and the marks are shuffled separately, so the halves
     * cannot be brought back together.
     *
     * `voted_on` is a DATE and the polling week is spread across it, because
     * turnout is a daily fact and a date is all any turnout figure has ever
     * needed. A second-precision clock here would pair with anything anybody
     * ever put on the marks.
     *
     * @param  list<int>  $units
     */
    private function writeReceipts(Ballot $ballot, array $units): void
    {
        $opened = Carbon::today()->addDays(self::A_POLL_OPENED);
        $days = self::A_POLL_CLOSED - self::A_POLL_OPENED;

        $rows = [];

        foreach ($units as $index => $unitId) {
            $rows[] = [
                'ballot_id' => $ballot->id,
                'unit_id' => $unitId,
                'voted_on' => $opened->copy()->addDays($index % max(1, $days))->toDateString(),
            ];
        }

        shuffle($rows);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('tenant')->table('ballot_receipts')->insert($chunk);
        }
    }

    /**
     * The count, written in its own shuffled order and with no clock at all.
     *
     * Two rows for the same candidate differ only in 128 random bits, which is
     * not a modelling shortcut — it is the definition of an anonymous ballot. If
     * any column could tell two identical votes apart, that column is the thing
     * that identifies a voter.
     *
     * `random_bytes` rather than a UUIDv7: a v7 carries a timestamp in its high
     * bits, which would put the cast order back into the table by the front door.
     *
     * @param  array<int, int>  $votes  option id => marks
     */
    private function writeMarks(array $votes): void
    {
        $rows = [];

        foreach ($votes as $optionId => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'mark_id' => bin2hex(random_bytes(16)),
                    'ballot_option_id' => $optionId,
                ];
            }
        }

        shuffle($rows);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::connection('tenant')->table('ballot_marks')->insert($chunk);
        }
    }

    /**
     * Empty the vote so it can be rebuilt from nothing.
     *
     * NEEDED BECAUSE A CAST VOTE CANNOT BE UNDONE, which is the point of it. A
     * seed that failed half way through leaves a poll that is neither empty nor
     * correct, and no application path can put it right: the estate's own MySQL
     * user holds no UPDATE or DELETE on either table, and a trigger refuses
     * anyway. That refusal is not an inconvenience to work around — deleting 317
     * of 318 marks identifies the survivor by elimination, and a secret ballot
     * needs the crowd to stay intact.
     *
     * TRUNCATE, not DELETE, and by the schema owner: it is DDL, so it steps past
     * both layers, and it takes the WHOLE table rather than a ballot's share of
     * it. That is the only shape of clearance that cannot thin a crowd.
     *
     * The environment guard is `EstateFinanceSeeder::resetLedger`'s, word for
     * word in intent: anywhere but local and testing these rows are a
     * community's actual votes.
     */
    private function clearPoll(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'GovernanceSeeder rebuilds a poll from empty and runs only in local and testing. On any '.
                'other environment these tables hold votes a community actually cast, and they are '.
                'append-only for a reason.'
            );
        }

        $database = DB::connection('tenant')->getDatabaseName();
        $owner = DB::connection('mysql_owner');

        $owner->statement('SET FOREIGN_KEY_CHECKS = 0');

        // Marks before receipts, child before parent, exactly as the reset list
        // in EstateFinanceSeeder is ordered.
        foreach (['ballot_marks', 'ballot_receipts'] as $table) {
            $owner->statement("TRUNCATE TABLE `{$database}`.`{$table}`");
        }

        $owner->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /* ------------------------------------------------------------------ */
    /* meetings — boards 12 and 36 */
    /* ------------------------------------------------------------------ */

    /**
     * Board 36's five rows, and board 12's agenda under the first of them.
     *
     * BOARD 12 NEEDS NO MEETING OF ITS OWN. It is `/governance/meetings/new` —
     * a form over a draft nobody has saved — and `Governance::schedulerDefaults()`
     * composes every value it draws out of the estate's settings and its phase
     * list. What board 12 does contribute is the AGM's agenda, which is a real
     * meeting on board 36's register and carries the four items verbatim.
     *
     * The meetings are written through the model rather than through
     * `Governance::scheduleMeeting()` and `::publishMeeting()`, and the notice
     * period is why. The AGM is seventeen days out and an AGM needs twenty-one
     * days' notice, so publishing it TODAY is refused — correctly, and that
     * refusal is ASSUMPTION Q-010 doing its job. It was published seven weeks
     * ago when it was still outside the period, which is a fact about the past
     * that no method called now can express.
     */
    private function seedMeetings(): void
    {
        // ONE TIME RULE (M4 §3.1): a 10:00 AM meeting is 10:00 ON THE ESTATE'S CLOCK — built in the
        // estate's zone, stored as the UTC moment it means.
        $today = SiteTime::today();
        $settings = EstateSetting::current();
        // ONE VOTE PER HOUSEHOLD, so eligibility counts HOUSEHOLDS. It counted units until
        // 23 September 2026, which was nearly right on a 450-lot estate where almost every lot
        // was occupied, and badly wrong on Phoenix Park's real 2,000: quorum would have needed
        // 25% of 2,000 = 500 households when the estate has 441, so quorum could never be met.
        // A vacant lot has nobody on it and casts nothing.
        $eligible = Household::query()->count();

        foreach (self::MEETINGS as $row) {
            $startsAt = $today->copy()
                ->addDays($row['days'])
                ->addHours($row['hour'])
                ->addMinutes($row['minute']);

            $title = $this->meetingTitle($row['key'], $startsAt);
            $notice = $settings->noticeDaysFor($row['type']);
            $held = $startsAt->lessThan(Carbon::now());

            /*
             * MATCHED ON THE ROW'S IDENTITY, NOT ON ITS TITLE. Three of these
             * five titles carry a year or a month derived from the seeded date,
             * so keying on the title would create a SIXTH meeting the first time
             * this ran a week later, or in January — a register that grows by one
             * AGM every time somebody re-seeds is exactly the stacking a seeder
             * is supposed to be unable to do.
             *
             * The pattern is still checked, so this can only ever adopt a row
             * this file wrote: it will rename "AGM 2025" to "AGM 2026" and it
             * will not touch a meeting a secretary scheduled on board 12.
             */
            $meeting = $this->existingMeeting($row['key'], $held) ?? new Meeting;

            $meeting->fill(
                [
                    'title' => $title,
                    'type' => $row['type'],
                    'starts_at' => $startsAt,
                    'audience_scope' => $row['scope'],
                    'phase' => $row['phase'],
                    'quorum_percent' => $settings->meeting_quorum_percent,

                    /*
                     * Board 36 draws two different measures in one column —
                     * "Quorum met · 6/7" for the committee and "Quorum met ·
                     * 34%" for a general meeting — and this is which
                     * denominator each counts against. A committee's quorum is a
                     * proportion of its seven members; a general meeting's is a
                     * proportion of 450 households.
                     */
                    'quorum_basis' => $row['members'] === null ? Meeting::HOUSEHOLDS : Meeting::MEMBERS,
                    'quorum_required_total' => $row['members'],

                    // Snapshotted for the same reason a ballot's is: a quorum
                    // determination recorded in July's minutes must not move
                    // because a unit was added in September.
                    'eligible_households' => $eligible,
                    'recording_enabled' => $row['recording'],
                    'recording_consent_notice' => $row['recording'],
                    'status' => $held ? Meeting::HELD : Meeting::SCHEDULED,

                    // The rule that was in force on the day it was published,
                    // copied rather than looked up. The question a member asks
                    // two years on is whether THIS meeting was properly
                    // convened, and that is a question about the day's rule.
                    'notice_days_required' => $notice,
                    'published_at' => $startsAt->copy()->subDays($notice + 14),
                    'created_by_name' => self::RETURNING_OFFICER,
                ]
            )->save();

            $this->seedAgenda($meeting);
            $this->seedRegister($meeting, $row['present'], $row['apologies'], $row['members'] !== null);
            $this->seedMinutes($meeting, $held, $row['present'], $row['apologies']);
        }
    }

    /**
     * The row on this register that this entry already wrote, if it wrote one.
     *
     * Each of the five is identified by something that does not move when the
     * calendar does: an AGM is told from an AGM by whether it is still ahead,
     * and the other three are the only meeting of their type on the register.
     * The title pattern is a guard rather than the key — it keeps this from ever
     * adopting a meeting somebody scheduled themselves.
     */
    private function existingMeeting(string $key, bool $held): ?Meeting
    {
        $query = Meeting::query();

        match ($key) {
            'agm-next', 'agm-last' => $query
                ->where('title', 'like', 'AGM %')
                ->where('starts_at', $held ? '<' : '>=', Carbon::now()),
            'committee' => $query->where('title', 'like', 'Executive Committee %'),
            'phase-wall' => $query->where('title', 'Phase 2 boundary wall discussion'),
            // The title it had until 12 Oct too (batch 1 item 6), so an estate seeded before gets no second EGM.
            default => $query->whereIn('title', ['EGM: Security budget increase', 'EGM — Security budget increase']),
        };

        return $query->orderBy('id')->first();
    }

    /**
     * The title board 36 prints, with any year or month derived from the date.
     *
     * "AGM 2026" seeded as a literal is the wrong name for the AGM seventeen
     * days out the moment this runs in 2027, and board 36 draws the title beside
     * the date it belongs to. Only the titles carrying a calendar reference are
     * composed; the other two are the board's words and have no date in them.
     */
    private function meetingTitle(string $key, Carbon $startsAt): string
    {
        return match ($key) {
            'agm-next', 'agm-last' => 'AGM '.$startsAt->year,
            // No em dash in what a person reads (Law 3; Hassam, batch 1 item 6, seen on live).
            'committee' => 'Executive Committee, '.$startsAt->format('F'),
            'phase-wall' => 'Phase 2 boundary wall discussion',
            default => 'EGM: Security budget increase',
        };
    }

    /**
     * Board 12's four agenda items, under the AGM they belong to.
     *
     * WRITTEN ONCE. A second run must not stack a second copy of an agenda — the
     * items are ordered by `sort_order` and a duplicated list would draw each
     * item twice with nothing on the screen to say why.
     *
     * The fiscal years are DERIVED from the meeting's own date. Board 12 draws
     * "Treasurer's report — FY2025/26" and "Motion: approve 2026/27 budget"
     * against a September 2026 AGM: the treasurer reports on the year that has
     * closed and the meeting votes the year that is running. Written as literals
     * they would be a year stale the moment the date moved.
     *
     * The other four meetings get no agenda. Three of them are held and board 36
     * offers their minutes rather than their agenda; the phase meeting takes the
     * two items its own title and its own quorum rule already state, and nothing
     * more is invented about business the boards do not describe.
     */
    private function seedAgenda(Meeting $meeting): void
    {
        if ($meeting->agenda()->count() > 0) {
            return;
        }

        $items = [];

        if ($meeting->type === Meeting::AGM && $meeting->isUpcoming()) {
            $closed = $meeting->starts_at->year - 1;
            $running = $meeting->starts_at->year;

            $items = [
                ['start_time' => '10:00:00', 'text' => 'Welcome & confirmation of quorum', 'fiscal_year' => null, 'motion_reference' => null],
                ['start_time' => '10:15:00', 'text' => 'Treasurer\'s report, FY'.$closed.'/'.substr((string) $running, 2), 'fiscal_year' => $closed.'/'.substr((string) $running, 2), 'motion_reference' => null],
                ['start_time' => '10:45:00', 'text' => 'Security update from Gemini Security Ltd', 'fiscal_year' => null, 'motion_reference' => null],
                ['start_time' => '11:15:00', 'text' => 'Motion: approve '.$running.'/'.substr((string) ($running + 1), 2).' budget', 'fiscal_year' => $running.'/'.substr((string) ($running + 1), 2), 'motion_reference' => 'BUDGET-'.$running.'/'.substr((string) ($running + 1), 2)],
            ];
        }

        if ($meeting->type === Meeting::PHASE) {
            $items = [
                ['start_time' => $meeting->starts_at->format('H:i:s'), 'text' => 'Welcome & confirmation of quorum', 'fiscal_year' => null, 'motion_reference' => null],
                ['start_time' => $meeting->starts_at->copy()->addMinutes(15)->format('H:i:s'), 'text' => $meeting->title, 'fiscal_year' => null, 'motion_reference' => null],
            ];
        }

        foreach ($items as $index => $item) {
            $meeting->agenda()->create([...$item, 'sort_order' => $index + 1]);
        }
    }

    /**
     * The attendance register, which is the only thing quorum is ever counted
     * from.
     *
     * Board 36 prints "Quorum met · 6/7" and "Quorum met · 41%" and both are
     * counts of these rows against the meeting's own denominator. Nothing stores
     * whether quorum was met, because a stored flag sitting beside a register
     * that disagreed with it would be the estate's minutes arguing with the
     * estate's own attendance sheet.
     *
     * A COMMITTEE'S ROWS CARRY NO UNIT, and that is the difference board 36's
     * two measures exist for: the seven people in that room are members, not
     * households. Apologies are recorded as their own state — a member who sent
     * them was neither present nor simply absent, and a register that only held
     * the people in the room could not say so.
     */
    private function seedRegister(Meeting $meeting, int $present, int $apologies, bool $committee): void
    {
        if ($present === 0 || $meeting->attendance()->count() > 0) {
            return;
        }

        if ($committee) {
            foreach (self::COMMITTEE_REGISTER as $member) {
                MeetingAttendance::create([
                    'meeting_id' => $meeting->id,
                    'attendee_name' => $member['name'],
                    'state' => $member['present'] ? MeetingAttendance::PRESENT : MeetingAttendance::APOLOGIES,
                ]);
            }

            return;
        }

        $units = Unit::query()
            ->where('status', 'occupied')
            ->orderBy('id')
            ->limit($present + $apologies)
            ->get();

        $rows = [];

        foreach ($units as $index => $unit) {
            $rows[] = [
                'meeting_id' => $meeting->id,
                'unit_id' => $unit->id,
                'attendee_name' => $this->nameOf($unit),
                'state' => $index < $present ? MeetingAttendance::PRESENT : MeetingAttendance::APOLOGIES,
                'created_at' => $meeting->starts_at->copy()->utc(),
                'updated_at' => $meeting->starts_at->copy()->utc(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::connection('tenant')->table('meeting_attendance')->insert($chunk);
        }
    }

    /**
     * The minutes, which are what turns board 36's row action from "View agenda"
     * into "View minutes".
     *
     * THE BODY STATES THE REGISTER AND NOTHING ELSE. No board records what was
     * decided at any of these three meetings, and minutes are the estate's own
     * words about its own business — inventing a set of resolutions would put
     * decisions a community never took into a document it would be entitled to
     * rely on. What can honestly be written is the quorum determination, because
     * every figure in it is counted from rows that exist.
     *
     * MINUTES ARE A DRAFT UNTIL A LATER MEETING ADOPTS THEM, and that is not a
     * formality: an unadopted set is one person's account and an adopted set is
     * the estate's record. Last year's AGM was adopted at the EGM that followed
     * it; the EGM's own minutes wait on the AGM that has not happened yet, and
     * the committee's wait on the next committee meeting. All three states are
     * therefore reachable, which is the point of seeding three.
     */
    private function seedMinutes(Meeting $meeting, bool $held, int $present, int $apologies): void
    {
        if (! $held) {
            return;
        }

        $quorum = app(Governance::class)->quorumOf($meeting);
        $basis = $meeting->quorum_basis === Meeting::MEMBERS ? 'committee members' : 'eligible households';

        /*
         * Adopted at the next meeting of the same body that has ACTUALLY BEEN
         * HELD, and by nothing else. Last year's AGM qualifies — the EGM seven
         * months later was the next general meeting of the estate — while the
         * EGM's own minutes wait on an AGM that has not happened and the
         * committee's wait on a committee meeting nobody has called. That is one
         * adopted set and two drafts, which is every state the column has.
         *
         * Derived rather than dated, because "adopted 301 days later" is a
         * number that means nothing on its own and would keep meaning nothing
         * after somebody moved one of the meetings.
         */
        $adopting = Meeting::query()
            ->where('audience_scope', $meeting->audience_scope)
            ->where('starts_at', '>', $meeting->starts_at)
            ->where('starts_at', '<', Carbon::now())
            ->orderBy('starts_at')
            ->first();

        MeetingMinutes::updateOrCreate(
            ['meeting_id' => $meeting->id],
            [
                // The register's facts and nothing else: residents read minutes (M68 §2.6, GV-5). The business taken is
                // the community's own words to write, never a seeder's.
                'body' => sprintf(
                    "%s held %s, %s.\n\nThe register records %d present and %d apolog%s against %d %s. ".
                    'A quorum of %d was required and was %s; the meeting proceeded.',
                    $meeting->typeLabel(),
                    $meeting->starts_at->format('M j, Y'),
                    strtolower($meeting->audienceLabel()),
                    $present,
                    $apologies,
                    $apologies === 1 ? 'y' : 'ies',
                    $meeting->quorum_basis === Meeting::MEMBERS
                        ? ($meeting->quorum_required_total ?? 0)
                        : $meeting->eligible_households,
                    $basis,
                    $quorum['required'],
                    $quorum['met'] ? 'met' : 'not met',
                ),
                'recorded_by_name' => self::RETURNING_OFFICER,
                // `starts_at` is handed back on the estate's clock; a plain timestamp column is written
                // from the digits it is given, so the moment is put on UTC first (M4 §3.1).
                'adopted_at' => $adopting?->starts_at->copy()->utc(),
                'adopted_by_name' => $adopting === null ? null : self::RETURNING_OFFICER,
                'published_at' => $meeting->starts_at->copy()->utc()->addDays(7),
            ],
        );
    }

    /* ------------------------------------------------------------------ */
    /* the estate's own roll */
    /* ------------------------------------------------------------------ */

    /**
     * The estate's phases, in its own order.
     *
     * Read from the units rather than from a constant, because the phase list is
     * a fact about the property and `Governance::turnoutByPhase()` groups board
     * 11's bars off the same column.
     *
     * @return list<string>
     */
    private function phases(): array
    {
        $phases = [];

        foreach (Unit::query()->distinct()->orderBy('block')->pluck('block') as $block) {
            if (is_string($block) && $block !== '') {
                $phases[] = $block;
            }
        }

        return $phases;
    }

    /**
     * @return list<Unit>
     */
    private function occupied(string $phase): array
    {
        return Unit::query()
            ->where('block', $phase)
            ->where('status', 'occupied')
            ->orderBy('id')
            ->get()
            ->values()
            ->all();
    }

    /**
     * Households no board has spoken for, per phase, in the estate's own order.
     *
     * The nine lots the boards name are held back so a filler nomination can
     * never quietly reassign Andrea Fletcher's household to a seat she is not
     * standing for.
     */
    private function buildPool(): void
    {
        foreach ($this->phases as $phase) {
            $this->pool[$phase] = array_values(array_filter(
                $this->occupied($phase),
                static fn (Unit $unit): bool => ! in_array($unit->reference, self::RESERVED_LOTS, true),
            ));

            $this->drawn[$phase] = 0;
        }
    }

    private function nextUnit(string $phase): ?Unit
    {
        $at = $this->drawn[$phase] ?? 0;
        $unit = $this->pool[$phase][$at] ?? null;

        if ($unit === null) {
            return null;
        }

        $this->drawn[$phase] = $at + 1;

        return $unit;
    }

    private function nextPhase(): string
    {
        if ($this->phases === []) {
            return '';
        }

        return $this->phases[$this->rotation++ % count($this->phases)];
    }

    /** @return array<int, string> unit id => the householder's name */
    private function householders(): array
    {
        $rows = DB::connection('tenant')
            ->table('residents')
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->where('residents.is_primary', true)
            ->select('households.unit_id as unit_id', 'residents.full_name as full_name')
            ->get();

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row->unit_id] = (string) $row->full_name;
        }

        return $names;
    }

    /**
     * What this household is called, as the estate records it.
     *
     * The lot reference is the fallback rather than an invented name, and it is
     * a truthful one: an estate that has not recorded who lives at Lot 214 says
     * so on every screen that draws Lot 214.
     */
    private function nameOf(Unit $unit): string
    {
        return $this->householders[$unit->id] ?? $unit->reference;
    }

    private function lot(?string $reference): ?Unit
    {
        if ($reference === null) {
            return null;
        }

        return Unit::query()->where('reference', $reference)->first();
    }

    /**
     * The household a nominator or a seconder belongs to.
     *
     * THE LOT THE BOARDS GIVE FIRST, THE REGISTER SECOND, AND NULL RATHER THAN A
     * GUESS. The migration's warning is that free text lets one person be two
     * people, and board 10 is its own example — Sonia Campbell, Ricardo Hall and
     * Michelle Palmer each stand in one row and propose somebody in another. So
     * where the estate's own residents register knows the name, the key is
     * written and the two records are one person.
     *
     * Where it does not, the name is stored alone. Board 4 puts Natalie Wong at
     * "Phase 2 · Lot 12" and Dwayne Robinson at "Phase 1 · Lot 12", which cannot
     * both be true of one estate; assigning her the lot the board gives would
     * put her in somebody else's household, and that is worse than an unresolved
     * key on a line whose only rendered value is the name.
     */
    private function person(string $name, ?string $reference): ?Unit
    {
        $unit = $this->lot($reference);

        if ($unit !== null) {
            return $unit;
        }

        $unitId = array_search($name, $this->householders, true);

        return $unitId === false ? null : Unit::query()->find($unitId);
    }
}
