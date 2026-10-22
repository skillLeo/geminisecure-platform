<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\BallotReceipt;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingAgendaItem;
use App\Models\Estate\MeetingAttendance;
use App\Models\Estate\MeetingMinutes;
use App\Models\Estate\Nomination;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Push\PushAnnouncer;
use App\Support\MoneyFormatter;
use App\Support\SiteTime;
use App\Support\TableQuery;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Elections, nominations, certification and meetings — boards 9, 10, 11, 12, 36.
 *
 * THE ONE DOOR. Every write in this module goes through a method here, and that
 * matters more than it usually does: `castVote()` is the only code in the
 * application that touches `ballot_marks`, and it is the reason there is no
 * `BallotMark` model to touch it with. A second path to those rows is a second
 * chance to write a column onto them.
 *
 * WHAT THIS CLASS WILL NOT DO, EVER
 * =================================
 *
 * It will not return a mark. `castVote()` returns void — not the mark ids, not a
 * receipt token, not a confirmation object with a choice in it. Anything handed
 * back to a caller can be logged, and a log line pairing a session with a mark
 * is the breach the schema was shaped to prevent.
 *
 * It will not answer "how did Lot 47 vote". There is no method for it, and there
 * is no query that could implement one: `ballot_receipts` and `ballot_marks`
 * share no column. `EstateGovernanceTest` asserts that before it asserts
 * anything else.
 *
 * It will not decertify. The Build Spec: "Certification is irreversible. A
 * certified ballot cannot be reopened or edited." Every transition below asks
 * `isCertified()` first, and nothing anywhere writes null back to that column. A
 * certified result that turns out to be wrong is corrected by running another
 * ballot, exactly as a posted journal is corrected by another entry.
 *
 * NOTHING HERE STORES A TALLY, A TURNOUT OR A QUORUM. Every figure boards 9, 11
 * and 36 draw is counted from rows at the moment the screen is drawn — marks per
 * option, receipts per ballot, attendance per meeting. The one thing that IS
 * snapshotted is the denominator, because board 11 prints "318 of 450 households
 * (71%)" as a certified fact and a unit added next March must not restate it.
 *
 * ELIGIBILITY IS THE ESTATE'S OWN RULE. Board 10's rejection reads "arrears >90
 * days" and D-024's 90 days is estate-configurable; the tenure rule beside it is
 * ASSUMPTION Q-011 and is off until an estate states one. Both are read from
 * `estate_settings` and both are SNAPSHOTTED onto the nomination when the check
 * runs, because a candidate who clears their arrears next week must not be able
 * to make the record say they were never in arrears.
 */
class Governance
{
    /**
     * The transitions a ballot is allowed to make, and only these.
     *
     * A map rather than a chain of ifs, so the whole lifecycle is legible in one
     * place and a stage nobody has thought about is unreachable rather than
     * accidentally permitted. `certified` appears as a destination and never as
     * a source — that is what irreversibility looks like in a table.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        Ballot::DRAFT => [Ballot::NOMINATIONS_OPEN],
        Ballot::NOMINATIONS_OPEN => [Ballot::NOMINATIONS_CLOSED],
        // Paused nominations reopen (M56 §3.2), until vetting starts.
        Ballot::NOMINATIONS_CLOSED => [Ballot::CANDIDATE_VETTING, Ballot::NOMINATIONS_OPEN],
        Ballot::CANDIDATE_VETTING => [Ballot::CAMPAIGN_PERIOD, Ballot::VOTING_OPEN],
        Ballot::CAMPAIGN_PERIOD => [Ballot::VOTING_OPEN],
        Ballot::VOTING_OPEN => [Ballot::VOTING_CLOSED],
        Ballot::VOTING_CLOSED => [Ballot::TALLY],
        // A closed poll reopens with a reason, until it is certified (M56 §3: "Reopen only with a reason, audited and
        // announced"). Closing moves straight to the tally, so the tally is where it reopens from.
        Ballot::TALLY => [Ballot::CERTIFIED, Ballot::VOTING_OPEN],
        Ballot::CERTIFIED => [],
        // Terminal (M56 §3.2): nothing moves a cancelled election.
        Ballot::CANCELLED => [],
    ];

    /** MySQL's SQLSTATE for a unique-constraint violation. */
    private const DUPLICATE_KEY = '23000';

    public function __construct(private readonly Dues $dues) {}

    /* ------------------------------------------------------------------ */
    /* configuring an election — M10 §7 */
    /* ------------------------------------------------------------------ */

    /*
     * POSITIONS ARE DATA AND ALWAYS WERE; WHAT WAS MISSING WAS A WAY TO TYPE ONE.
     *
     * `ballot_positions.name` has always been free text with its own seat count
     * and sort order — nothing in this codebase has ever hardcoded "Chairman".
     * But the only writer was a seeder: there was no route, no service method and
     * no screen, so an estate whose committee is not the seeded one had no way to
     * say so, and the election it ran would have been for somebody else's
     * offices.
     *
     * Everything below is confined to a DRAFT ballot. Once nominations open,
     * somebody has stood for a named seat: renaming it changes what they stood
     * for, changing the seat count changes who won, and deleting it discards
     * their nomination. `assertDraft()` is the one gate and every method goes
     * through it.
     */

    /**
     * Start an election for a year, in draft.
     *
     * ONE ELECTION A YEAR (B043). An election is addressed by its year —
     * `/governance/elections/2026` — and the control room draws every ballot of
     * that year as one election, speaking for the least advanced. A second
     * election in a year that already had one was accepted, took over the room
     * (a fresh draft is always the least advanced), and hid the running
     * election's own controls behind it. So a year with any ballot at all is
     * refused, in words that name what is already there.
     *
     * THE DATABASE BACKS IT. The ballot is lettered "A" — it is the year's first
     * paper — and `ballots` is unique on (year, code), so two presses arriving
     * together cannot both get past the check: the second insert collides and
     * is refused with the same sentence. It used to be written with no letter,
     * and MySQL lets any number of NULLs share a unique index.
     */
    public function createElection(int $year, string $title, ?string $description, string $kind = Ballot::ELECTION): Ballot
    {
        $title = trim($title);

        if ($title === '') {
            throw new DomainException('An election needs a name: it is what a household sees on the ballot paper.');
        }

        $this->refuseASecondElection($year);

        try {
            return Ballot::create([
                'year' => $year,
                'code' => 'A',
                'title' => $title,
                'kind' => $kind,
                'description' => $this->blankToNull($description),
                'stage' => Ballot::DRAFT,
            ]);
        } catch (QueryException $clash) {
            if ($clash->getCode() !== self::DUPLICATE_KEY) {
                throw $clash;
            }

            // Another press made it first. Named when it can be read, and refused either way.
            $this->refuseASecondElection($year);

            throw new DomainException($year.' already has an election. An election is addressed by its year, so a second one would share its control room and take it over.');
        }
    }

    /**
     * A COMMITTEE'S BALLOT ON A MOTION (M62 A9; Hassam, 10 October 2026), such as "Committee Ballot · 2027 Budget
     * Approval". A resolution, not an election: one paper, For, Against or Abstain, no seats, no candidates and no
     * nominations stage, so from its draft it goes straight to the vote. It is put to the households named here, chosen
     * and recorded, never worked out by a rule; why each one is on the roll goes to the audit log with it. It sits in
     * its year beside that year's election, never inside it (`controlRoom()` keeps the two apart).
     *
     * @param  array<int, string>  $roll  household id => why it is on the roll
     */
    public function createCommitteeBallot(int $year, string $title, string $question, array $roll): Ballot
    {
        $title = trim($title);
        $question = trim($question);

        if ($title === '' || $question === '') {
            throw new DomainException('A committee ballot needs a name and the motion it puts to the vote.');
        }

        $ids = array_map('intval', array_keys($roll));

        if ($ids === []) {
            throw new DomainException('A committee ballot is put to a recorded list of households, and this one names none.');
        }

        $missing = array_values(array_diff($ids, Household::query()->whereIn('id', $ids)->pluck('id')->map(static fn ($id): int => (int) $id)->all()));

        if ($missing !== []) {
            throw new DomainException('No household '.implode(', ', $missing).' is on the register. A roll names households that exist.');
        }

        // The next letter in its year, after the ballots already there.
        $taken = Ballot::query()->where('year', $year)->pluck('code')->all();
        $code = 'A';

        while (in_array($code, $taken, true)) {
            $code = chr(ord($code) + 1);
        }

        return DB::connection('tenant')->transaction(function () use ($year, $code, $title, $question, $ids, $roll): Ballot {
            $ballot = Ballot::create([
                'year' => $year,
                'code' => $code,
                'title' => $title,
                'kind' => Ballot::RESOLUTION,
                'question' => $question,
                'stage' => Ballot::DRAFT,
                'roll' => $ids,
            ]);

            $motion = BallotPosition::create([
                'ballot_id' => $ballot->id,
                'name' => 'The motion',
                'seat_count' => 1,
                'scope' => BallotPosition::ESTATE,
                'sort_order' => 1,
            ]);

            foreach (['For', 'Against', 'Abstain'] as $i => $label) {
                BallotOption::create(['ballot_id' => $ballot->id, 'ballot_position_id' => $motion->id, 'label' => $label, 'sort_order' => $i + 1]);
            }

            $this->auditElection($ballot, 'ballot.roll_recorded', [
                'kind' => Ballot::RESOLUTION,
                'question' => $question,
                'roll' => array_map(static fn (int $id): array => ['household_id' => $id, 'why' => (string) $roll[$id]], $ids),
            ]);

            return $ballot;
        });
    }

    /** Refuse a year that already holds an election, naming it. */
    private function refuseASecondElection(int $year): void
    {
        $titles = Ballot::query()->where('year', $year)->orderBy('code')->orderBy('id')->pluck('title');

        if ($titles->isEmpty()) {
            return;
        }

        throw new DomainException(sprintf(
            '%d already has an election: %s. An election is addressed by its year, so a second one would '.
            'share its control room and take it over. Choose another year, or run this one.',
            $year,
            $titles->implode(' & '),
        ));
    }

    /** Rename an election, or change what it says it is for. */
    public function renameElection(Ballot $ballot, string $title, ?string $description): Ballot
    {
        $this->assertDraft($ballot, 'renamed');

        $title = trim($title);

        if ($title === '') {
            throw new DomainException('An election needs a name: it is what a household sees on the ballot paper.');
        }

        $ballot->forceFill([
            'title' => $title,
            'description' => $this->blankToNull($description),
        ])->save();

        return $ballot;
    }

    /**
     * Add a position by typing its title.
     *
     * Any title the estate uses. The seat count is what makes a result mean
     * something — one seat and the percentages sum to 100, three seats and a
     * voter marks up to three names — so it is required rather than defaulted
     * silently.
     */
    public function addPosition(Ballot $ballot, string $name, int $seats, string $scope = BallotPosition::ESTATE, ?string $phase = null): BallotPosition
    {
        // M56 §3: a seat can still be added while nominations can be made for it; nobody has stood for it yet.
        $this->assertPositionsOpen($ballot, 'added');

        $name = trim($name);

        if ($name === '') {
            throw new DomainException('A position needs a title. It is the words a household votes under.');
        }

        if ($seats < 1) {
            throw new DomainException('A position with no seats cannot be won. Give it at least one.');
        }

        /*
         * Two positions with the same title on one ballot would put a household
         * in front of the same office twice with different candidates under it,
         * and no result could say which "Chairman" anybody won. Compared
         * case-insensitively, because "Chairman" and "chairman" are the same
         * office to everybody except a string comparison.
         */
        $taken = $ballot->positions()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($taken) {
            throw new DomainException('This election already has a position called '.$name.'.');
        }

        if ($scope === BallotPosition::PHASE && ($phase === null || trim($phase) === '')) {
            throw new DomainException('A phase-scoped position has to say which phase it is for.');
        }

        $position = $ballot->positions()->create([
            'name' => $name,
            'seat_count' => $seats,
            'scope' => $scope,
            'phase' => $scope === BallotPosition::PHASE ? trim((string) $phase) : null,

            // Appended. Order is changed deliberately by `reorderPositions()`,
            // never as a side effect of adding one.
            'sort_order' => (int) $ballot->positions()->max('sort_order') + 1,
        ]);

        // Once nominations are open, a new seat changes a live election: audited, as every step of one is.
        if ($ballot->stage !== Ballot::DRAFT) {
            $this->auditElection($ballot, 'election.position_added', ['position' => $position->displayName(), 'seats' => $seats]);
        }

        return $position;
    }

    /** Change a position's title or its number of seats, while it is still a draft. */
    public function updatePosition(BallotPosition $position, string $name, int $seats): BallotPosition
    {
        $this->assertDraft($position->ballot, 'changed');

        $name = trim($name);

        if ($name === '') {
            throw new DomainException('A position needs a title. It is the words a household votes under.');
        }

        if ($seats < 1) {
            throw new DomainException('A position with no seats cannot be won. Give it at least one.');
        }

        $taken = $position->ballot->positions()
            ->whereKeyNot($position->getKey())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($taken) {
            throw new DomainException('This election already has a position called '.$name.'.');
        }

        $position->forceFill(['name' => $name, 'seat_count' => $seats])->save();

        return $position;
    }

    /** Remove a position from a draft. */
    public function removePosition(BallotPosition $position): void
    {
        // M56 §3: while nominations can still be made, and only a seat nobody has stood for (below).
        $this->assertPositionsOpen($position->ballot, 'removed');

        /*
         * A nomination cannot outlive the seat it was for. Since M56 a seat can be removed while nominations are open,
         * so this is the rule that keeps it honest: somebody who stood for a seat keeps the record of what they stood
         * for, and a dangling nomination would appear on board 10 under a position nothing can name.
         */
        if ($position->nominations()->exists()) {
            throw new DomainException(
                'Somebody has already been nominated for '.$position->displayName().
                '. Withdraw the nomination first, or the record of what they stood for goes with the position.'
            );
        }

        $ballot = $position->ballot;
        $name = $position->displayName();
        $position->delete();

        if ($ballot->stage !== Ballot::DRAFT) {
            $this->auditElection($ballot, 'election.position_removed', ['position' => $name]);
        }
    }

    /**
     * WHILE A SEAT CAN STILL BE STOOD FOR (M56 §3): a draft, or nominations open or paused. Adding a seat then takes
     * nothing from anybody, and removing one is refused while anybody has stood for it. Once candidates are being
     * checked the paper is set: a seat added then would have nobody on it, and one removed would take a candidate.
     */
    private function assertPositionsOpen(Ballot $ballot, string $verb): void
    {
        if (in_array($ballot->stage, [Ballot::DRAFT, Ballot::NOMINATIONS_OPEN, Ballot::NOMINATIONS_CLOSED], true)) {
            return;
        }

        throw new DomainException(sprintf(
            'This election is at "%s", so a position cannot be %s. Positions can be added or removed until candidates are being checked.',
            $ballot->stageLabel(),
            $verb,
        ));
    }

    /**
     * Put the positions in the order the ballot paper should read.
     *
     * @param  list<int>  $orderedIds  every position on this ballot, in the order wanted
     */
    public function reorderPositions(Ballot $ballot, array $orderedIds): void
    {
        $this->assertDraft($ballot, 'reordered');

        $own = $ballot->positions()->pluck('id')->all();

        /*
         * The whole set, or nothing. A partial list would leave the positions it
         * omitted holding stale sort orders that collide with the new ones, and
         * the ballot paper's order would then depend on the database's tie-break
         * rather than on anybody's decision.
         */
        sort($own);
        $given = array_values(array_unique(array_map('intval', $orderedIds)));
        $check = $given;
        sort($check);

        if ($check !== $own) {
            throw new DomainException('Reordering takes every position on the ballot, once each.');
        }

        DB::connection('tenant')->transaction(function () use ($ballot, $given): void {
            foreach ($given as $index => $id) {
                $ballot->positions()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });
    }

    /** Only a draft may be configured, and the refusal says what would break. */
    private function assertDraft(Ballot $ballot, string $verb): void
    {
        if ($ballot->stage === Ballot::DRAFT) {
            return;
        }

        throw new DomainException(sprintf(
            'This election is at "%s", so it cannot be %s. Once nominations open, somebody has stood for a '.
            'named seat: renaming it changes what they stood for, and changing its seats changes who wins.',
            $ballot->stageLabel(),
            $verb,
        ));
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /* ------------------------------------------------------------------ */
    /* the lifecycle — board 9's stepper, one step at a time */
    /* ------------------------------------------------------------------ */

    /**
     * Put a drafted ballot into nominations — board 9's "Open nominations" (B042).
     *
     * THE ONE-WAY DOOR OUT OF DRAFT, so it refuses what could not be put right
     * afterwards. A ballot with no position has nothing anybody could be
     * nominated for, and positions cannot be added once it has left draft. And a
     * window needs an end households can be told: "until nominations close" is
     * what board 9 counts down to, and a close already past would open a window
     * that is shut.
     */
    public function openNominations(Ballot $ballot, Carbon|string|null $closesOn = null): Ballot
    {
        $this->assertCanMove($ballot, Ballot::NOMINATIONS_OPEN);

        if (! $ballot->positions()->exists()) {
            throw new DomainException(
                $ballot->title.' has no position on it yet, so there is nothing anybody could be nominated for. '.
                'Add its positions first: none can be added once nominations open.'
            );
        }

        $closes = $closesOn === null ? $ballot->nominations_close_at : $this->asMoment($closesOn);

        if ($closes === null) {
            throw new DomainException('Say when nominations close. Households are told how long they have to put somebody forward.');
        }

        if ($closes->lessThanOrEqualTo(Carbon::now())) {
            throw new DomainException(sprintf(
                'Nominations would close at %s, which has already passed on the estate\'s clock. Choose a close that is still to come.',
                SiteTime::local($closes)->format('j M Y, g:i A'),
            ));
        }

        $this->transition($ballot, Ballot::NOMINATIONS_OPEN);

        $ballot->forceFill([
            'nominations_open_at' => now(),
            'nominations_close_at' => $closes,
        ])->save();

        return $ballot;
    }

    /**
     * Hand a closed nomination window to the returning officer — board 9's
     * "Start candidate vetting" (B042).
     *
     * A stage and nothing else: each candidate is still decided one at a time
     * on board 10. It was the one transition out of "Nominations Closed" and no
     * door reached it, so a ballot whose nominations were closed early could
     * never go on to a poll.
     */
    public function startVetting(Ballot $ballot): Ballot
    {
        $this->transition($ballot, Ballot::CANDIDATE_VETTING);

        $ballot->save();

        return $ballot;
    }

    /**
     * Stop taking nominations — board 9's "Close nominations early".
     *
     * The close date is rewritten to now rather than left standing, because the
     * screen prints "3 days until nominations close" off that column and an
     * estate that closed early would otherwise keep advertising a window it had
     * already shut.
     */
    public function closeNominations(Ballot $ballot): Ballot
    {
        $this->transition($ballot, Ballot::NOMINATIONS_CLOSED);

        $ballot->forceFill(['nominations_close_at' => now()])->save();

        return $ballot;
    }

    /**
     * Open the poll.
     *
     * THIS IS WHERE THE DENOMINATOR FREEZES. Until now `eligible_households` has
     * been kept current — board 9 prints it while nominations are open and an
     * estate that adds a unit that week should see 451. From this moment it is
     * the denominator of a turnout figure that will end up on a certificate, and
     * it stops moving.
     *
     * AND THIS IS WHERE THE PAPER IS SETTLED (B042). A ballot the console ran
     * from draft reached this with nothing on its paper: accepting a candidate
     * on board 10 decides a nomination and writes no option, and nothing else
     * did either — so the poll would have opened for households to find no name
     * to mark. A paper nobody has settled is settled here, from the approved
     * nominations, seat by seat in the order they were lodged; from this moment
     * it stops depending on the vetting record, which is why `ballot_options` is
     * a table of its own. A paper already settled — a seeded one, or a
     * resolution's For / Against / Abstain — is left exactly as it is.
     *
     * Refused while a nomination is still undecided, because opening the poll
     * would leave that candidate off the paper without anybody deciding so; and
     * refused when the paper would carry nobody at all.
     */
    public function open(Ballot $ballot, Carbon|string|null $closesAt = null): Ballot
    {
        $this->assertCanMove($ballot, Ballot::VOTING_OPEN);

        $undecided = $ballot->nominations()
            ->whereNotIn('status', [Nomination::APPROVED, Nomination::REJECTED])
            ->count();

        if ($undecided > 0) {
            throw new DomainException(sprintf(
                '%d nomination%s on %s %s still waiting on a decision. Opening the poll now would leave %s off '.
                'the paper without anybody deciding so. Approve or reject %s on the nominations screen first.',
                $undecided,
                $undecided === 1 ? '' : 's',
                $ballot->title,
                $undecided === 1 ? 'is' : 'are',
                $undecided === 1 ? 'that candidate' : 'those candidates',
                $undecided === 1 ? 'it' : 'each of them',
            ));
        }

        // A poll that shuts the moment it opens is a governance incident (M53 §4.11): at least half an hour, always.
        if ($closesAt !== null && $this->asMoment($closesAt)->lessThan(Carbon::now()->addMinutes(30))) {
            throw new DomainException('Set a closing time at least half an hour from now.');
        }

        $settled = $ballot->options()->exists();

        if (! $settled && ! $ballot->nominations()->where('status', Nomination::APPROVED)->exists()) {
            throw new DomainException(
                'Nobody is on the paper for '.$ballot->title.': no candidate has been approved for any of its seats. '.
                'A poll opened now would give every household nothing to mark.'
            );
        }

        return DB::connection('tenant')->transaction(function () use ($ballot, $closesAt, $settled): Ballot {
            if (! $settled) {
                $this->settlePaper($ballot);
            }

            $this->transition($ballot, Ballot::VOTING_OPEN);

            $ballot->forceFill([
                'opens_at' => now(),
                'closes_at' => $closesAt === null ? $ballot->closes_at : $this->asMoment($closesAt),
                // The roll's households when it has one (M62 A9), else the estate's rule: frozen from here.
                'eligible_households' => $ballot->hasRoll() ? count($ballot->rollUnitIds()) : $this->eligibleHouseholds(),
            ])->save();

            // WHO IS ELIGIBLE: every lot, once per position — less any household the estate's own rule refuses when it
            // comes to vote (arrears over the estate's threshold; tenure when the estate sets one). Checked per household
            // at the moment it votes, so the roll is the rule and the denominator, recorded as they stood today.
            $settings = EstateSetting::current();
            $this->auditElection($ballot, 'election.opened', [
                'eligible_households' => $ballot->eligible_households,
                'rule' => ($ballot->hasRoll()
                    ? 'Put to the '.$ballot->eligible_households.' households on its roll, recorded when it was made, and to nobody else.'
                    : 'Every lot has one paper per position.').' A household with arrears over '.$settings->governance_arrears_days.' days is refused when it comes to vote.',
                'roll' => $ballot->hasRoll() ? $ballot->rollHouseholdIds() : null,
                'closes_at' => $ballot->closes_at === null ? null : SiteTime::iso($ballot->closes_at),
                'positions' => $ballot->positions()->pluck('name')->all(),
            ]);

            return $ballot;
        });
    }

    /**
     * THE ELECTION'S ACTS, IN THE AUDIT LOG (M21 §1.3). Nothing about an election was audited: who opened the poll,
     * who closed it, who certified it — none of it was in the log an auditor reads. Each act is now, with who did it.
     *
     * WHAT IS NEVER WRITTEN HERE: a single vote as it is cast. An audit entry carries the moment it was written, and the
     * estate keeps only the DAY a household voted — "never the time", the receipt tells the resident — so a vote is not
     * its own entry. Who voted is written once, when the poll closes: the lots, from the receipts, with no choice
     * and no time.
     *
     * @param  array<string, mixed>  $after
     */
    private function auditElection(Ballot $ballot, string $action, array $after): void
    {
        app(AuditLogger::class)->record(
            action: $action,
            entityType: 'Ballot',
            entityId: (string) $ballot->id,
            after: ['title' => $ballot->title, 'year' => $ballot->year, ...$after],
            // The estate whose election it is — when run outside a request (a command, a test) tenancy may not be open.
            tenantId: tenant() === null ? null : (string) tenant()->getTenantKey(),
        );
    }

    /**
     * Write the paper from the approved nominations: each seat in the order the
     * ballot lists them, and each seat's candidates in the order they were lodged.
     */
    private function settlePaper(Ballot $ballot): void
    {
        $approved = $ballot->nominations()
            ->where('status', Nomination::APPROVED)
            ->with('unit')
            ->orderBy('id')
            ->get()
            ->groupBy('ballot_position_id');

        foreach ($ballot->positions()->get() as $position) {
            foreach ($approved->get($position->id, collect())->values() as $index => $nomination) {
                BallotOption::create([
                    'ballot_id' => $ballot->id,
                    'ballot_position_id' => $position->id,
                    'label' => $nomination->candidate_name,
                    'unit_id' => $nomination->unit_id,

                    // Read off the candidate's own property, as board 11 prints it.
                    'phase' => $nomination->unit?->block,
                    'sort_order' => $index + 1,
                ]);
            }
        }
    }

    /**
     * Close the poll and move straight to the tally.
     *
     * Two stages in one act, and board 11's own row-label says why: "Stage
     * advances to 'Tally' once voting closes". There is nothing a human does
     * between shutting the poll and counting it, and a stage nobody can act on
     * is a stage a screen would sit in looking broken.
     */
    public function close(Ballot $ballot): Ballot
    {
        $this->transition($ballot, Ballot::VOTING_CLOSED);
        $ballot->forceFill(['closes_at' => now()])->save();

        $this->transition($ballot, Ballot::TALLY);
        $ballot->save();

        // WHO VOTED: the lots and the day each voted, read from the receipts — never a choice and never a time. Rows,
        // not receipts: a lot's reference is the unit's column, which no BallotReceipt has (PHPStan, M22).
        $voted = BallotReceipt::query()->where('ballot_receipts.ballot_id', $ballot->id)
            ->join('units', 'units.id', '=', 'ballot_receipts.unit_id')
            ->orderBy('units.reference')
            ->toBase()
            ->get(['units.reference', 'ballot_receipts.voted_on'])
            ->map(static fn ($r): array => ['lot' => (string) $r->reference, 'voted_on' => Carbon::parse((string) $r->voted_on)->toDateString()])
            ->all();
        $this->auditElection($ballot, 'election.closed', [
            'eligible_households' => $ballot->eligible_households,
            'households_voted' => count($voted),
            'voted' => $voted,
        ]);

        return $ballot;
    }

    /**
     * Give the poll longer.
     *
     * IT MAY ONLY EVER MOVE FORWARD. Shortening a window disenfranchises every
     * household that had not voted yet and was relying on the date it was told —
     * which is not an extension, it is an early close, and an early close is a
     * different decision with a different button. A returning officer who wants
     * one calls `close()` and it goes on the record as such.
     */
    public function extend(Ballot $ballot, Carbon|string $closesAt): Ballot
    {
        $this->refuseIfCertified($ballot, 'extended');

        if ($ballot->stage !== Ballot::VOTING_OPEN) {
            throw new DomainException(
                'Only a poll that is open can be extended. '.$ballot->title.' is at "'.$ballot->stageLabel().
                '", and re-opening a closed poll would let people vote after the result was visible.'
            );
        }

        $new = $this->asMoment($closesAt);

        if ($ballot->closes_at !== null && $new->lessThanOrEqualTo($ballot->closes_at)) {
            throw new DomainException(
                'An extension can only move the closing time later. Bringing it forward would take the '.
                'vote away from every household relying on the date they were given. If the poll should '.
                'stop now, close it: that is a decision the record should show as one.'
            );
        }

        $ballot->forceFill(['closes_at' => $new])->save();

        $this->auditElection($ballot, 'election.extended', ['closes_at' => SiteTime::iso($new)]);
        // "Extend the close time (announced and audited)" (M56 §3): the households who may still vote are told.
        app(PushAnnouncer::class)->election($ballot, 'extended');

        return $ballot;
    }

    /* ------------------------------------------------------------------ */
    /* an election's life (M56 §3) */
    /* ------------------------------------------------------------------ */

    /*
     * "STOP" IS NEVER A BUTTON (M56 §3.2). Three different acts, named plainly: PAUSE nominations (stop taking new ones,
     * keep the election alive, reversible: `closeNominations()` and `reopenNominations()`), EXTEND a close time
     * (`extend()`, `extendNominations()`), and CANCEL (terminal, a reason, everything retained). Delete exists only for
     * a draft nobody has been nominated in; a published result is never edited, and a dispute is a challenge against it.
     */

    /** Delete a draft election, confirmed by typing its title: no nomination, so nobody has stood (M56 §3.1.6). */
    public function deleteDraftElection(Ballot $ballot, string $typed, User $by): void
    {
        if ($ballot->stage !== Ballot::DRAFT || $ballot->nominations()->exists()) {
            throw new DomainException($ballot->title.' is past its draft, so it cannot be deleted. Cancel it instead: it stays on the record, marked.');
        }

        if (mb_strtolower(trim($typed)) !== mb_strtolower(trim($ballot->title))) {
            throw new DomainException('Type the election\'s name to confirm: '.$ballot->title);
        }

        $this->auditElection($ballot, 'election.draft_deleted', ['deleted_by' => $by->name]);

        // Its positions go with it (cascade); it never had options, nominations or a vote.
        DB::connection('tenant')->transaction(static fn () => $ballot->delete());
    }

    /** Move the nominations' close later while they are open (M56 §3: "Extend the nomination close"). Never earlier. */
    public function extendNominations(Ballot $ballot, Carbon|string $closesOn, User $by): Ballot
    {
        if ($ballot->stage !== Ballot::NOMINATIONS_OPEN) {
            throw new DomainException('Nominations on '.$ballot->title.' are not open, so their close cannot be extended.');
        }

        $new = $this->asMoment($closesOn);

        if ($ballot->nominations_close_at !== null && $new->lessThanOrEqualTo($ballot->nominations_close_at)) {
            throw new DomainException('An extension only moves the close later. To stop taking nominations now, pause them.');
        }

        $ballot->forceFill(['nominations_close_at' => $new])->save();
        $this->auditElection($ballot, 'election.nominations_extended', ['closes_at' => SiteTime::iso($new), 'by' => $by->name]);

        return $ballot;
    }

    /** REOPEN paused nominations (M56 §3.2), with when they now close, until vetting starts. Announced and audited. */
    public function reopenNominations(Ballot $ballot, Carbon|string $closesOn, User $by): Ballot
    {
        $this->assertCanMove($ballot, Ballot::NOMINATIONS_OPEN);

        $new = $this->asMoment($closesOn);

        if ($new->lessThanOrEqualTo(Carbon::now())) {
            throw new DomainException('Choose when nominations close again: a moment still to come.');
        }

        $this->transition($ballot, Ballot::NOMINATIONS_OPEN);
        $ballot->forceFill(['nominations_close_at' => $new])->save();
        $this->auditElection($ballot, 'election.nominations_reopened', ['closes_at' => SiteTime::iso($new), 'by' => $by->name]);

        return $ballot;
    }

    /**
     * CANCEL (M56 §3.1.4): from any stage before the result is certified. A reason, the title typed to confirm, and
     * EVERYTHING RETAINED: every ballot cast stays (append-only by trigger and grant) and is counted for nothing. Once
     * voting has opened, everyone who voted is told, with the reason.
     */
    public function cancelElection(Ballot $ballot, string $reason, string $typed, User $by): Ballot
    {
        $this->refuseIfCertified($ballot, 'cancelled');

        if ($ballot->isCancelled()) {
            throw new DomainException($ballot->title.' is already cancelled.');
        }

        if (mb_strtolower(trim($typed)) !== mb_strtolower(trim($ballot->title))) {
            throw new DomainException('Type the election\'s name to confirm: '.$ballot->title);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new DomainException('Say why. Everyone who voted is told the reason.');
        }

        $was = $ballot->stage;
        $voters = BallotReceipt::query()->where('ballot_id', $ballot->id)->pluck('unit_id')->map(static fn ($id): int => (int) $id)->all();

        $ballot->forceFill([
            'stage' => Ballot::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $by->getKey(),
            'cancelled_by_name' => mb_substr((string) $by->name, 0, 160),
            'cancel_reason' => mb_substr($reason, 0, 500),
        ])->save();

        $this->auditElection($ballot, 'election.cancelled', ['was' => $was, 'reason' => $ballot->cancel_reason, 'ballots_kept' => count($voters), 'by' => $by->name]);

        if ($voters !== []) {
            app(PushAnnouncer::class)->electionCancelled($ballot, $voters);
        }

        return $ballot;
    }

    /**
     * REOPEN A CLOSED POLL, with a reason, until it is certified (M56 §3: "Reopen only with a reason, audited and
     * announced"). The paper, the denominator and every ballot already cast stand; only the window opens again.
     */
    public function reopenVoting(Ballot $ballot, Carbon|string $closesAt, string $reason, User $by): Ballot
    {
        $this->refuseIfCertified($ballot, 'reopened');
        $this->assertCanMove($ballot, Ballot::VOTING_OPEN);

        if ($ballot->stage !== Ballot::TALLY) {
            throw new DomainException('Only a closed poll reopens. '.$ballot->title.' is at "'.$ballot->stageLabel().'".');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new DomainException('Say why the poll reopens. It is announced and on the record.');
        }

        $new = $this->asMoment($closesAt);

        if ($new->lessThanOrEqualTo(Carbon::now()->addMinutes(30))) {
            throw new DomainException('Set a closing time at least half an hour from now.');
        }

        $this->transition($ballot, Ballot::VOTING_OPEN);
        $ballot->forceFill(['closes_at' => $new])->save();

        $this->auditElection($ballot, 'election.reopened', ['closes_at' => SiteTime::iso($new), 'reason' => mb_substr($reason, 0, 500), 'by' => $by->name]);

        return $ballot;
    }

    /** Where a reopened window starts from: seven days out at 6 PM on the estate's clock, as Open voting's does. */
    private function defaultReopenClose(): Carbon
    {
        return SiteTime::now()->addDays(7)->setTime(18, 0)->utc();
    }

    /** Archive an election whose result is published (M56 §3). Its result stays readable. */
    public function archiveElection(Ballot $ballot, User $by): Ballot
    {
        if ($ballot->published_at === null) {
            throw new DomainException($ballot->title.' is archived once its result is published.');
        }

        $ballot->forceFill(['archived_at' => now(), 'archived_by_name' => mb_substr((string) $by->name, 0, 160)])->save();
        $this->auditElection($ballot, 'election.archived', ['by' => $by->name]);

        return $ballot;
    }

    /**
     * A FORMAL CHALLENGE against a published result (M56 §3.1.5): "A published result is never edited. A dispute is
     * recorded as a challenge against it, not as a correction of it." It is an entry in the audit log, which nothing
     * edits or removes, listed on the results screen.
     */
    public function challengeResult(Ballot $ballot, string $grounds, User $by): void
    {
        if ($ballot->published_at === null) {
            throw new DomainException('A challenge is recorded against a published result. '.$ballot->title.' has none yet.');
        }

        $grounds = trim($grounds);

        if (mb_strlen($grounds) < 10) {
            throw new DomainException('Write the grounds of the challenge: what is disputed, and why.');
        }

        $this->auditElection($ballot, 'election.challenged', ['grounds' => mb_substr($grounds, 0, 2000), 'lodged_by' => $by->name]);
    }

    /**
     * The challenges lodged against a ballot's result, oldest first.
     *
     * @return list<array{grounds: string, lodged_by: string, lodged_on: string}>
     */
    public function challengesOf(Ballot $ballot): array
    {
        return DB::connection('mysql')->table('audit_log')
            ->where('action', 'election.challenged')
            ->where('entity_type', 'Ballot')
            ->where('entity_id', (string) $ballot->id)
            ->when(tenant() !== null, static fn ($q) => $q->where('tenant_id', (string) tenant()->getTenantKey()))
            ->orderBy('id')
            ->get(['after', 'created_at'])
            ->map(static function (object $row): array {
                $after = json_decode((string) $row->after, true) ?: [];

                return [
                    'grounds' => (string) ($after['grounds'] ?? ''),
                    'lodged_by' => (string) ($after['lodged_by'] ?? ''),
                    'lodged_on' => SiteTime::local(Carbon::parse((string) $row->created_at, 'UTC'))->format('j M Y'),
                ];
            })
            ->all();
    }

    /**
     * Certify the result. THE IRREVERSIBLE ACT.
     *
     * Needs the `approve` verb, which the Secretary does not hold on Governance
     * — the estate matrix gives them `Full` and gives `Full · Approver` to the
     * President and Vice President. That is not an oversight in the matrix: it is
     * exactly what D-013 separated the verb for, so the officer who runs the
     * election is not also the officer who declares it final.
     *
     * The outcome statement is written here and nowhere else, because board 11
     * calls it the estate's formal record and a record that could be edited
     * afterwards is a draft.
     */
    public function certify(Ballot $ballot, User $by, ?string $outcome = null): Ballot
    {
        $this->refuseIfCertified($ballot, 'certified again');

        if ($ballot->stage !== Ballot::TALLY) {
            throw new DomainException(
                $ballot->title.' is at "'.$ballot->stageLabel().'" and cannot be certified. A result is '.
                'certified from the tally of a closed poll; certifying anything earlier would declare a '.
                'winner while votes were still being cast.'
            );
        }

        return DB::connection('tenant')->transaction(function () use ($ballot, $by, $outcome): Ballot {
            $ballot->forceFill([
                'stage' => Ballot::CERTIFIED,
                'certified_at' => now(),
                'certified_by' => $by->getKey(),
                'certified_by_name' => $by->name,
                'outcome_statement' => $outcome ?? $this->outcomeStatement($ballot),
            ])->save();

            $this->auditElection($ballot, 'election.certified', [
                'certified_by' => $by->name,
                'turnout' => $this->turnout($ballot)['label'],
                'outcome' => $ballot->outcome_statement,
            ]);

            return $ballot;
        });
    }

    /**
     * Publish the certified result estate-wide.
     *
     * A SECOND ACT, not a side effect of the first. Board 11's button says
     * "Certify & publish results" and the two are still separate underneath: a
     * ballot can be certified as the estate's record on the night and published
     * to residents when the returning officer has drafted the announcement.
     * Folding them together would make the announcement irreversible too.
     */
    public function publish(Ballot $ballot): Ballot
    {
        if (! $ballot->isCertified()) {
            throw new DomainException(
                $ballot->title.' has not been certified, so there is no result to publish. Publishing an '.
                'uncertified tally would tell 450 households a figure the returning officer has not stood '.
                'behind.'
            );
        }

        if ($ballot->published_at === null) {
            $ballot->forceFill(['published_at' => now()])->save();
            $this->auditElection($ballot, 'election.results_published', ['turnout' => $this->turnout($ballot)['label']]);
        }

        return $ballot;
    }

    /* ------------------------------------------------------------------ */
    /* the vote itself */
    /* ------------------------------------------------------------------ */

    /**
     * Cast one household's paper.
     *
     * RETURNS VOID, AND THAT IS PART OF THE GUARANTEE. There is nothing to hand
     * back that would not pair this household with its marks — not the mark ids,
     * not a count, not a receipt token. A caller that wants to know the vote went
     * through asks `hasVoted()`, which answers about turnout and nothing else.
     *
     * TWO WRITES IN ONE TRANSACTION, AND THAT IS DELIBERATE. Turnout has to be
     * exactly provable, so a crash between the receipt and the marks must leave
     * neither. What the transaction cannot do is hide the correlation from
     * somebody watching the writes happen — nothing can — and it does not need
     * to: the invariant is about what the STORED ROWS allow, and once these two
     * statements have committed there is no column on either side that pairs
     * them, no ordinal, and no clock.
     *
     * @param  list<int>  $optionIds  what the household marked, across every position on the paper
     *
     * @throws DomainException when the poll is shut, the paper is wrong, or the household has already voted
     */
    public function castVote(Ballot $ballot, Unit $unit, array $optionIds): void
    {
        if (! $ballot->isOpenForVoting()) {
            throw new DomainException(
                'The poll for '.$ballot->title.' is not open. It is at "'.$ballot->stageLabel().'"'.
                ($ballot->closes_at === null ? '' : ' and closed on '.SiteTime::local($ballot->closes_at)->format('j M Y, g:i A')).'.'
            );
        }

        // A ballot with a roll takes a paper from the households on it and from nobody else (M62 A9), whoever asks.
        if (! $ballot->admitsUnit($unit->id)) {
            throw new DomainException($ballot->title.' is put to the households on its roll, and '.$unit->reference.' is not one of them.');
        }

        $options = $this->validatePaper($ballot, $optionIds);

        if ($this->hasVoted($ballot, $unit)) {
            throw new DomainException(
                $unit->reference.' has already voted in '.$ballot->title.'. One household, one paper, and '.
                'the vote already cast cannot be shown to anyone, changed or taken back.'
            );
        }

        try {
            DB::connection('tenant')->transaction(function () use ($ballot, $unit, $options): void {
                BallotReceipt::create([
                    'ballot_id' => $ballot->id,
                    'unit_id' => $unit->id,
                    // A calendar date: the day on the ESTATE'S clock, not UTC's (M4 §3.1) — a vote at 8 PM is today's.
                    'voted_on' => SiteTime::today()->toDateString(),
                ]);

                $marks = [];

                foreach ($options as $option) {
                    $marks[] = [
                        /*
                         * 128 CRYPTOGRAPHIC BITS, not an auto-increment and not
                         * a UUIDv7 — a v7 carries a timestamp in its high bits,
                         * which would put the cast order back into the table by
                         * the front door.
                         */
                        'mark_id' => bin2hex(random_bytes(16)),
                        'ballot_option_id' => $option->id,
                    ];
                }

                /*
                 * The insert order within one paper is the only thing about this
                 * batch that is not already random, so it is thrown away too.
                 * It buys little on its own — there is no column that could
                 * record the order anyway — and it costs nothing, which is the
                 * right trade on the one write in this application that has to
                 * be unpickable.
                 */
                shuffle($marks);

                DB::connection('tenant')->table('ballot_marks')->insert($marks);
            });
        } catch (QueryException $clash) {
            /*
             * The unique index on (ballot_id, unit_id) firing after the check
             * above passed. Two tabs open at once is an ordinary thing for a
             * person to do, and the database is what actually settles it — the
             * check exists to give a sentence rather than a stack trace, not to
             * be the guard.
             */
            if ($clash->getCode() === self::DUPLICATE_KEY) {
                throw new DomainException(
                    $unit->reference.' has already voted in '.$ballot->title.'. Two papers arrived at once '.
                    'and the second was refused.'
                );
            }

            throw $clash;
        }
    }

    /** Whether this household's paper is already in — turnout, not choice. */
    public function hasVoted(Ballot $ballot, Unit $unit): bool
    {
        return BallotReceipt::query()
            ->where('ballot_id', $ballot->id)
            ->where('unit_id', $unit->id)
            ->exists();
    }

    /**
     * Board 11's headline: "318 of 450 households (71%)".
     *
     * The numerator is `COUNT(*)` over the receipts and the denominator is the
     * snapshot on the ballot. Nothing about the marks is consulted, and it could
     * not be — in a three-seat race a voter makes three marks, so counting marks
     * would put turnout above 100%.
     *
     * @return array{cast: int, eligible: int, percent: int, label: string}
     */
    public function turnout(Ballot $ballot): array
    {
        $cast = BallotReceipt::query()->where('ballot_id', $ballot->id)->count();
        $eligible = $this->eligibleHouseholdCount($ballot);
        $percent = $eligible === 0 ? 0 : (int) round($cast * 100 / $eligible);

        return [
            'cast' => $cast,
            'eligible' => $eligible,
            'percent' => $percent,
            'label' => $cast.' of '.$eligible.' households ('.$percent.'%)',
        ];
    }

    /**
     * Board 11's five phase bars.
     *
     * EACH PHASE IS ITS OWN FRACTION and not a share of the whole — the board's
     * own note says the five percentages are not an average of the 71%. So the
     * denominator is that phase's unit count, which is why the receipts are
     * joined to `units`: the phase of a voter is a property of the property, and
     * duplicating it onto the receipt would be a second source of truth about
     * where a household lives.
     *
     * @return list<array{phase: string, cast: int, eligible: int, percent: int, caption: string}>
     */
    public function turnoutByPhase(Ballot $ballot): array
    {
        /*
         * IN THE ORDER EACH PHASE'S FIRST LOT WAS CREATED, AND SAID SO (M11 A2).
         *
         * The rows below are drawn in the order these groups arrive, and GROUP BY alone
         * promises no order. Read through a temporary table — the only way before
         * `units_block_status_index` — they arrived in the order each phase was first met
         * scanning the lots by id: MIN(id). Read through that index they would arrive in
         * collation order, where "Phase 10" comes before "Phase 2". The screen's order is
         * a decision, not a side effect of a plan, so it is asked for.
         *
         * EACH PHASE COUNTS ITS HOUSEHOLDS (M85 A9; M73 and M81: "turnout by phase, that
         * phase's households"), by the rule the election itself counts by
         * (`eligibleHouseholdCount()`): the households on the ballot's roll where it has
         * one, every lot where the estate votes by lot, otherwise the households living on
         * the phase's lots. It divided by every unit in the phase, vacant lots too, so the
         * phases could never add up to the election. The phases are the estate's lots'
         * (M60 §C2), each listed even when nobody in it may vote: one read, the lots
         * left-joined to the households the model's own scopes count (removed ones out),
         * and with no roll only the estate's counted households (M87 §1.4: a household
         * with a resident in it), as `householdsOn()` counts the election's.
         */
        $byLots = ! $ballot->hasRoll() && EstateSetting::current()->voting_eligibility === EstateSetting::VOTES_BY_LOTS;

        $living = Household::query()
            ->select(['households.id', 'households.unit_id'])
            ->when(
                $ballot->hasRoll(),
                static fn ($query) => $query->whereIn('households.id', $ballot->rollHouseholdIds()),
                static fn ($query) => $query->counted(),
            );

        $eligible = Unit::lots()->toBase()
            ->when(! $byLots, static fn ($query) => $query->leftJoinSub($living, 'living', 'living.unit_id', '=', 'units.id'))
            ->selectRaw($byLots ? 'units.block as block, COUNT(*) as total' : 'units.block as block, COUNT(living.id) as total')
            ->groupBy('units.block')
            ->orderByRaw('MIN(units.id)')
            ->pluck('total', 'block');

        $cast = DB::connection('tenant')
            ->table('ballot_receipts')
            ->join('units', 'units.id', '=', 'ballot_receipts.unit_id')
            ->where('ballot_receipts.ballot_id', $ballot->id)
            ->selectRaw('units.block as block, COUNT(*) as total')
            ->groupBy('units.block')
            ->pluck('total', 'block');

        $rows = [];

        foreach ($eligible as $phase => $total) {
            $phase = (string) $phase;
            $voted = (int) ($cast[$phase] ?? 0);
            $percent = (int) $total === 0 ? 0 : (int) round($voted * 100 / (int) $total);

            $rows[] = [
                'phase' => $phase,
                'cast' => $voted,
                'eligible' => (int) $total,
                'percent' => $percent,
                'caption' => $phase.' · '.$percent.'%',
            ];
        }

        return $rows;
    }

    /**
     * How many marks each option carries.
     *
     * A LEFT JOIN, so an option nobody voted for comes back as zero rather than
     * missing. A results screen that silently dropped the candidate who got no
     * votes would be the one screen where an absence looks like an oversight.
     *
     * @return array<int, int> option id => marks
     */
    public function tally(Ballot $ballot): array
    {
        $rows = DB::connection('tenant')
            ->table('ballot_options')
            ->leftJoin('ballot_marks', 'ballot_marks.ballot_option_id', '=', 'ballot_options.id')
            ->where('ballot_options.ballot_id', $ballot->id)
            ->groupBy('ballot_options.id')
            ->selectRaw('ballot_options.id as option_id, COUNT(ballot_marks.mark_id) as votes')
            ->get();

        $tally = [];

        foreach ($rows as $row) {
            $tally[(int) $row->option_id] = (int) $row->votes;
        }

        return $tally;
    }

    /**
     * Whether the poll cleared its quorum rule.
     *
     * Counted from the receipts against the ballot's own snapshot, and rounded
     * UP on the requirement — a quorum of "25% of 450" is 112.5 households, and
     * declaring a ballot quorate on 112 is a result somebody can challenge.
     *
     * The turnout the caller has already counted is used when it is handed one: the results page counted the receipts
     * twice, one statement over its budget at 2,000 lots (the Hub's merge run, 12 Oct).
     *
     * @param  array{cast: int, eligible: int, percent: int, label: string}|null  $turnout  `turnout()` of this ballot
     * @return array{required: int, cast: int, percent: int, met: bool}
     */
    public function quorum(Ballot $ballot, ?array $turnout = null): array
    {
        $turnout ??= $this->turnout($ballot);
        $required = $ballot->quorumHouseholds();

        return [
            'required' => $required,
            'cast' => $turnout['cast'],
            'percent' => $ballot->quorum_percent,
            'met' => $turnout['cast'] >= $required,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* nominations — board 10 */
    /* ------------------------------------------------------------------ */

    /**
     * Accept a candidate onto the paper.
     *
     * The eligibility snapshot is written even on an acceptance. A record that
     * only kept the arithmetic behind refusals could not answer the more awkward
     * question — why this candidate was let through — and that is the one a
     * losing candidate asks.
     */
    /**
     * ADD A CANDIDATE (the user, M56 §3.3: "neither the console nor the app can add a candidate, and only the demo seed
     * does it. That breaks the whole election walk at step three."). The office lodges a nomination for a seat on the
     * paper: the candidate, the resident who proposes them and the resident who seconds them, each a resident of a lot,
     * and what the candidate says about standing. It is lodged PENDING and decided on this same screen like any other,
     * with the eligibility snapshot taken when it is decided.
     *
     * While nominations are open, closed or in vetting (M56 §3): once the campaign starts the candidates are settled,
     * and once a paper is settled nobody is added to it.
     */
    public function addNomination(BallotPosition $position, Resident $candidate, Resident $nominator, Resident $seconder, ?string $statement, User $by): Nomination
    {
        $ballot = $position->ballot;

        if (! in_array($ballot->stage, [Ballot::NOMINATIONS_OPEN, Ballot::NOMINATIONS_CLOSED, Ballot::CANDIDATE_VETTING], true)) {
            throw new DomainException('Candidates are added while nominations are open, closed or being vetted. '.$ballot->title.' is at '.mb_strtolower($ballot->stageLabel()).'.');
        }

        if ($ballot->options()->exists()) {
            throw new DomainException('The ballot paper for '.$ballot->title.' is settled, so nobody can be added to it.');
        }

        $people = [$candidate->id, $nominator->id, $seconder->id];

        if (count(array_unique($people)) !== 3) {
            throw new DomainException('The candidate, the proposer and the seconder are three different people.');
        }

        foreach ([$candidate, $nominator, $seconder] as $person) {
            if ($person->status !== Resident::VERIFIED || $person->household?->unit_id === null) {
                throw new DomainException($person->full_name.' is not a verified resident of a lot, so cannot stand, propose or second.');
            }
        }

        if (Nomination::query()->where('ballot_position_id', $position->id)->where('unit_id', $candidate->household->unit_id)->where('candidate_name', $candidate->full_name)->exists()) {
            throw new DomainException($candidate->full_name.' is already nominated for '.$position->displayName().'.');
        }

        $statement = trim((string) $statement);

        $nomination = Nomination::query()->forceCreate([
            'ballot_id' => $ballot->id,
            'ballot_position_id' => $position->id,
            'unit_id' => $candidate->household->unit_id,
            'candidate_name' => mb_substr((string) $candidate->full_name, 0, 160),
            'statement' => $statement === '' ? null : mb_substr($statement, 0, 1000),
            'nominator_unit_id' => $nominator->household->unit_id,
            'nominator_name' => mb_substr((string) $nominator->full_name, 0, 160),
            'seconder_unit_id' => $seconder->household->unit_id,
            'seconder_name' => mb_substr((string) $seconder->full_name, 0, 160),
            'status' => Nomination::PENDING,
        ]);

        $this->auditElection($ballot, 'nomination.added', [
            'nomination' => $nomination->id,
            'position' => $position->displayName(),
            'candidate' => $nomination->candidate_name,
            'proposed_by' => $nomination->nominator_name,
            'seconded_by' => $nomination->seconder_name,
            'lodged_by' => $by->name,
        ]);

        return $nomination;
    }

    /**
     * Residents a candidate, a proposer or a seconder may be: verified, of a lot, by name or by lot (M56 §3.3: "the
     * person, searched from residents"). Ten at most, the closest first.
     *
     * @return list<array{id: int, name: string, lot: string, phase: string|null}>
     */
    public function residentsMatching(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';

        return Resident::query()
            ->join('households as h', 'h.id', '=', 'residents.household_id')
            ->join('units as u', 'u.id', '=', 'h.unit_id')
            ->where('residents.status', Resident::VERIFIED)
            ->where(static fn ($q) => $q->where('residents.full_name', 'like', $like)->orWhere('u.reference', 'like', $like))
            ->orderByRaw('residents.full_name like ? desc', [$query.'%'])
            ->orderBy('residents.full_name')
            ->limit(10)
            ->get(['residents.id', 'residents.full_name', 'u.reference', 'u.block'])
            ->map(static fn ($r): array => ['id' => (int) $r->id, 'name' => (string) $r->full_name, 'lot' => (string) $r->reference, 'phase' => $r->block])
            ->all();
    }

    public function accept(Nomination $nomination, User $by): Nomination
    {
        $this->refuseIfCertified($nomination->ballot, 'changed');

        return $this->decide($nomination, Nomination::APPROVED, null, $by);
    }

    /**
     * Refuse one, WITH A REASON. There is no other kind of refusal.
     *
     * "Rejection always carries a recorded reason" — the Build Spec. The reason
     * is what board 10 prints inside the badge and what a candidate is entitled
     * to be told, so an empty one is refused here rather than stored as an empty
     * string that a screen then renders as "Rejected — ".
     */
    public function reject(Nomination $nomination, string $reason, User $by): Nomination
    {
        $this->refuseIfCertified($nomination->ballot, 'changed');

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'Record why '.$nomination->candidate_name.' is being refused. A rejection with no reason on '.
                'it cannot be explained to the member it is about, and the estate has to be able to explain '.
                'it: this is a decision about who may stand for office in their own community.'
            );
        }

        return $this->decide($nomination, Nomination::REJECTED, $reason, $by);
    }

    /**
     * Ask for more before deciding.
     *
     * A STATE OF ITS OWN, not a rejection with a softer word. A candidate
     * waiting on a proposer's signature has not been refused, and folding this
     * into `rejected` would put a refusal on a member's permanent record for a
     * missing form. It can still become either of the other two.
     */
    public function requestInformation(Nomination $nomination, string $reason, User $by): Nomination
    {
        $this->refuseIfCertified($nomination->ballot, 'changed');

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'Say what is missing. "More information requested" with nothing after it leaves the '.
                'candidate unable to supply it and the officer unable to remember what they asked for.'
            );
        }

        return $this->decide($nomination, Nomination::MORE_INFO, $reason, $by);
    }

    /**
     * Whether a household may stand, under the estate's own rules.
     *
     * TWO CHECKS, BOTH THE ESTATE'S. Arrears is the one board 10 draws —
     * "Rejected — arrears >90 days" — and the threshold is a setting rather than
     * a constant. Tenure is named by the Build Spec, has no figure on any board,
     * and is therefore off by default: see ASSUMPTION Q-011 on the settings row.
     *
     * A UNIT WITH NO TENURE ON RECORD IS NOT DISQUALIFIED. Nothing in this
     * system yet records when a household moved in, and an unknown tenure is not
     * a short one — refusing a member on a fact nobody has captured would
     * disenfranchise them for the estate's own missing paperwork.
     *
     * @return array{eligible: bool, reason: string|null, checked_on: string, arrears_bucket: string, arrears_minor: int, tenure_months: int|null}
     */
    public function eligibility(Unit $unit, ?int $tenureMonths = null, ?Carbon $asAt = null): array
    {
        $settings = EstateSetting::current();
        $today = $asAt?->copy() ?? SiteTime::date();

        $balance = $this->dues->balanceOf($unit, $today)->getMinorAmount()->toInt();

        // This unit's bucket, not the estate's every bucket (M11 A2): FIFO is per
        // unit, so the answer is the same without ageing the other 1,999.
        $bucket = $this->dues->unitBuckets($today, [$unit->id])[$unit->id] ?? 'current';

        $oldest = $this->dues->oldestOpenChargeDate($unit, $today);
        $daysOverdue = $oldest === null ? 0 : (int) $oldest->diffInDays($today, absolute: false);

        $reason = null;

        if ($balance > 0 && $daysOverdue >= $settings->governance_arrears_days) {
            // The board's own wording, built from the estate's own threshold so
            // an estate that moves it to 60 gets a reason that says 60.
            $reason = 'arrears >'.$settings->governance_arrears_days.' days';
        }

        if ($reason === null
            && $settings->governance_tenure_check_enabled
            && $tenureMonths !== null
            && $tenureMonths < $settings->governance_min_tenure_months) {
            $reason = 'tenure under '.$settings->governance_min_tenure_months.' months';
        }

        return [
            'eligible' => $reason === null,
            'reason' => $reason,
            'checked_on' => $today->toDateString(),
            'arrears_bucket' => $bucket,
            'arrears_minor' => $balance,
            'tenure_months' => $tenureMonths,
        ];
    }

    /**
     * Whether this household may vote on THIS ballot. Off a ballot's roll, never, whatever its standing (M62 A9); on it,
     * or on a ballot without a roll, the estate's own rule above.
     *
     * @return array{eligible: bool, reason: string|null, checked_on: string, arrears_bucket: string, arrears_minor: int, tenure_months: int|null}
     */
    public function eligibilityFor(Ballot $ballot, Unit $unit): array
    {
        $check = $this->eligibility($unit);

        if (! $ballot->admitsUnit($unit->id)) {
            return ['eligible' => false, 'reason' => 'not one of the households this ballot is put to'] + $check;
        }

        return $check;
    }

    /* ------------------------------------------------------------------ */
    /* meetings — boards 12 and 36 */
    /* ------------------------------------------------------------------ */

    /**
     * Draft a meeting. WRITES A DRAFT AND NOTHING ELSE.
     *
     * The notice period is not checked here, and deliberately: a secretary
     * sketching an AGM for a fortnight's time has done nothing wrong, and a form
     * that refused to save would lose the agenda they had just typed. The
     * refusal belongs at publication, which is when households are actually told.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{start_time?: string|null, text: string, fiscal_year?: string|null, motion_reference?: string|null}>  $agenda
     */
    public function scheduleMeeting(array $attributes, array $agenda = [], ?User $by = null): Meeting
    {
        $settings = EstateSetting::current();

        return DB::connection('tenant')->transaction(function () use ($attributes, $agenda, $by, $settings): Meeting {
            $meeting = Meeting::create([
                'quorum_percent' => $settings->meeting_quorum_percent,

                /*
                 * The denominator, snapshotted at drafting for the same reason a
                 * ballot's is: a quorum determination recorded in July's minutes
                 * must not move because a unit was added in September. A
                 * committee's own meeting counts its members (M60 §C2).
                 */
                ...$this->quorumBasisFor(
                    (string) ($attributes['audience_scope'] ?? Meeting::audienceFor((string) ($attributes['type'] ?? ''))),
                    isset($attributes['phase']) ? (string) $attributes['phase'] : null,
                    $settings,
                ),
                'status' => Meeting::DRAFT,
                'created_by' => $by?->getKey(),
                'created_by_name' => $by?->name,
                ...$attributes,
            ]);

            foreach ($agenda as $index => $item) {
                $meeting->agenda()->create([
                    'start_time' => $item['start_time'] ?? null,
                    'text' => $item['text'],
                    'sort_order' => $index + 1,
                    'fiscal_year' => $item['fiscal_year'] ?? null,
                    'motion_reference' => $item['motion_reference'] ?? null,
                ]);
            }

            return $meeting;
        });
    }

    /**
     * Publish it, and REFUSE IF IT IS INSIDE THE NOTICE PERIOD.
     *
     * The Build Spec, board 12: "Statutory notice periods for an AGM are
     * validated before publication, and the system refuses to publish a meeting
     * inside the required period." This is that refusal, and the period is the
     * estate's own — 21 days for an AGM by default, which is ASSUMPTION Q-010
     * and the longer of the two Jamaican readings.
     *
     * The required figure is COPIED ONTO THE MEETING rather than left to be
     * looked up later. The setting is editable, and the question a member asks
     * two years on is whether THIS meeting was properly convened — which is a
     * question about the rule in force on the day it was published.
     */
    public function publishMeeting(Meeting $meeting, ?User $by = null): Meeting
    {
        if ($meeting->published_at !== null) {
            return $meeting;
        }

        $settings = EstateSetting::current();
        $required = $settings->noticeDaysFor($meeting->type);

        /*
         * UNCONDITIONAL, AND IT USED TO BE BEHIND A FLAG.
         *
         * `meeting_notice_enforced` let an estate switch this refusal off. That
         * was a cautious hatch of mine while Q-010 was unanswered; the client
         * has now answered it — the periods are configurable, the refusal is
         * not. An estate may state how long its notice period is. It may not
         * state that it has none, because the cost of publishing inside one is
         * not the estate's alone to bear: it is every household that turns up to
         * a meeting whose decisions can be challenged afterwards.
         */
        // One line, said before the press as well as after it (M56: "Needs 7 days notice. Earliest 15 Oct.").
        if (($refusal = $this->noticeRefusal($meeting->type, $meeting->starts_at)) !== null) {
            throw new DomainException($refusal);
        }

        $meeting->forceFill([
            'status' => Meeting::SCHEDULED,
            'notice_days_required' => $required,
            'published_at' => now(),
            'notice_given_at' => now(),
            'created_by' => $meeting->created_by ?? $by?->getKey(),
            'created_by_name' => $meeting->created_by_name ?? $by?->name,
        ])->save();

        return $meeting;
    }

    /* ------------------------------------------------------------------ */
    /* a meeting's life (M56 §2) */
    /* ------------------------------------------------------------------ */

    /*
     * THREE RULES BEHIND ALL OF IT (the user, M56 §1). Before anyone is told, anything may change; after, every change
     * costs somebody a rearranged day, so it is told to them. Nothing the community has acted on is deleted: a
     * published meeting is cancelled, with a reason and a name, and stays on the record marked. And a new date restarts
     * the notice period, counted from today, because that is what makes the meeting's decisions stand.
     *
     * Every act asks `Meeting::stage()` first, so "may it be done now?" has one answer on every screen.
     */

    /**
     * The earliest moment a meeting of this type may start if households are told today (M56 §1.3). `$settings` is the
     * estate's settings when the caller has read them already, so a page that asks several of these reads them once.
     */
    public function earliestStart(string $type, ?Carbon $now = null, ?EstateSetting $settings = null): Carbon
    {
        return ($now?->copy() ?? Carbon::now())->addDays(($settings ?? EstateSetting::current())->noticeDaysFor($type));
    }

    /** "Needs 7 days' notice. Earliest 15 Oct." when this start is inside the type's notice period; otherwise null. */
    public function noticeRefusal(string $type, Carbon $startsAt, ?EstateSetting $settings = null): ?string
    {
        $settings ??= EstateSetting::current();
        $earliest = $this->earliestStart($type, null, $settings);

        // A date on the estate's own calendar: "Earliest 15 Oct", with no clock tag (it names a day, not a moment).
        return $startsAt->lessThan($earliest)
            ? sprintf('Needs %d days\' notice. Earliest %s.', $settings->noticeDaysFor($type), SiteTime::local($earliest)->format('j M'))
            : null;
    }

    /**
     * The meeting's own time of day on the earliest date it may now have: what "Move to 15 Oct" moves it to (M56).
     * The day after, when that time on the earliest date has already gone by the moment the notice could start.
     */
    public function earliestKeepingTime(Meeting $meeting, ?EstateSetting $settings = null): Carbon
    {
        $earliest = SiteTime::local($this->earliestStart($meeting->type, null, $settings));
        $starts = SiteTime::local($meeting->starts_at);
        $candidate = $earliest->copy()->setTime((int) $starts->format('G'), (int) $starts->format('i'));

        return ($candidate->lessThan($earliest) ? $candidate->addDay() : $candidate)->utc();
    }

    /**
     * Edit a draft, anything about it: nobody has been told (M56 §2). The type decides the audience (§2.5).
     *
     * @param  array<string, mixed>  $attributes  the meeting's columns as validated
     * @param  array<int, array{text: string, start_time?: string|null}>|null  $agenda  null leaves the agenda as it is
     */
    public function editDraft(Meeting $meeting, array $attributes, ?array $agenda, User $by): Meeting
    {
        $this->assertMeetingStage($meeting, [Meeting::STAGE_DRAFT], 'edited like this');

        $type = (string) ($attributes['type'] ?? $meeting->type);
        $attributes['audience_scope'] = Meeting::audienceFor($type);

        if ($type !== Meeting::PHASE) {
            $attributes['phase'] = null;
        }

        // Nobody has been told of a draft, so its quorum follows its type, and a phase meeting its phase, as edited (M60 §C2, M69).
        $phase = $type === Meeting::PHASE ? (string) ($attributes['phase'] ?? $meeting->phase) : null;
        $attributes = [...$attributes, ...$this->quorumBasisFor($attributes['audience_scope'], $phase)];

        return DB::connection('tenant')->transaction(function () use ($meeting, $attributes, $agenda): Meeting {
            $meeting->forceFill($attributes)->save();

            if ($agenda !== null) {
                $this->replaceAgenda($meeting, $agenda);
            }

            return $meeting->fresh(['agenda']);
        });
    }

    /** Delete a draft, confirmed by typing its title (M56 §2). Only a draft: nobody was told, so nothing is lost. */
    public function deleteDraft(Meeting $meeting, string $typed, User $by): void
    {
        $this->assertMeetingStage($meeting, [Meeting::STAGE_DRAFT], 'deleted. A meeting households were told of is cancelled instead');
        $this->assertTypedName($meeting->title, $typed);

        $before = ['title' => $meeting->title, 'type' => $meeting->type, 'starts_at' => SiteTime::iso($meeting->starts_at)];

        DB::connection('tenant')->transaction(static fn () => $meeting->delete());

        $this->auditMeeting($meeting, 'meeting.draft_deleted', ['deleted' => true], $before, $by);
    }

    /**
     * A published meeting's venue, link, description and agenda (M56 §2: "Edit venue, link, agenda, description").
     * Never its audience or type once notice has gone out, and its date only through `reschedule()`, which restarts
     * the notice. A changed place is told to every household that said it is coming (§2.1).
     *
     * @param  array{held_as: string, platform_name?: string|null, venue?: string|null, virtual_link?: string|null}  $place
     * @param  array<int, array{text: string, start_time?: string|null}>|null  $agenda  null leaves the agenda as it is
     */
    public function editScheduled(Meeting $meeting, array $place, ?string $description, ?array $agenda, User $by): Meeting
    {
        // Not while postponed (M56 §2: "Anything else until a date is set" is refused there).
        $this->assertMeetingStage($meeting, [Meeting::STAGE_SCHEDULED], 'changed');

        $wasPlace = $meeting->placeLine();
        $wasLink = (string) $meeting->virtual_link;

        $meeting = DB::connection('tenant')->transaction(function () use ($meeting, $place, $description, $agenda): Meeting {
            $meeting->forceFill([
                'held_as' => $place['held_as'],
                'platform_name' => $place['platform_name'] ?? null,
                'venue' => $place['venue'] ?? null,
                'virtual_link' => $place['held_as'] === Meeting::IN_PERSON ? null : ($place['virtual_link'] ?? null),
                'description' => $description,
            ])->save();

            if ($agenda !== null) {
                $this->replaceAgenda($meeting, $agenda);
            }

            return $meeting->fresh(['agenda']);
        });

        $moved = $meeting->placeLine() !== $wasPlace || (string) $meeting->virtual_link !== $wasLink;

        $this->auditMeeting($meeting, 'meeting.details_changed', ['place' => $meeting->placeLine(), 'place_changed' => $moved], ['place' => $wasPlace], $by);

        if ($moved) {
            $attending = DB::connection('tenant')->table('meeting_rsvps')->where('meeting_id', $meeting->id)->where('response', 'attending')->pluck('unit_id')->map(static fn ($id): int => (int) $id)->all();
            app(PushAnnouncer::class)->meetingChanged($meeting, 'place', units: $attending);
        }

        return $meeting;
    }

    /**
     * A NEW DATE (M56 §2.2), for a scheduled meeting, or the date a postponed one was waiting for (§2.3). Outside the
     * type's notice period counted from TODAY, never from the first announcement; every reply is reset to "not
     * answered", because people replied to a different day; the notice clock restarts; households are told it moved;
     * and who moved it, when, from what to what is audited.
     */
    public function reschedule(Meeting $meeting, Carbon|string $startsAt, User $by): Meeting
    {
        $this->assertMeetingStage($meeting, [Meeting::STAGE_SCHEDULED, Meeting::STAGE_POSTPONED], 'moved');

        $new = $this->asMoment($startsAt);

        if (($refusal = $this->noticeRefusal($meeting->type, $new)) !== null) {
            throw new DomainException($refusal);
        }

        $from = $meeting->starts_at->copy();
        $wasPostponed = $meeting->status === Meeting::POSTPONED;

        $cleared = $this->giveNewDate($meeting, $new);

        $this->auditMeeting($meeting, 'meeting.rescheduled', ['starts_at' => SiteTime::iso($new), 'replies_reset' => $cleared, 'was_postponed' => $wasPostponed], ['starts_at' => SiteTime::iso($from)], $by);
        app(PushAnnouncer::class)->meetingChanged($meeting, 'moved');

        return $meeting;
    }

    /**
     * WHAT A NEW DATE DOES, WHICHEVER ACT GIVES IT (M56 §2.2): the meeting is scheduled for it, the notice clock restarts
     * today, and every reply is reset, because people replied to a different day. Returns how many replies were reset.
     */
    private function giveNewDate(Meeting $meeting, Carbon $new): int
    {
        return DB::connection('tenant')->transaction(function () use ($meeting, $new): int {
            $meeting->forceFill([
                'starts_at' => $new,
                'status' => Meeting::SCHEDULED,
                'notice_given_at' => now(),
                'notice_days_required' => EstateSetting::current()->noticeDaysFor($meeting->type),
                'postponed_at' => null,
                'postponed_by_name' => null,
                'postpone_reason' => null,
            ])->save();

            return DB::connection('tenant')->table('meeting_rsvps')->where('meeting_id', $meeting->id)->delete();
        });
    }

    /**
     * POSTPONE (M56 §2.3): not then. With no new date the meeting waits for one, its date reading "New date to be set"
     * and its replies closed until it has it. With a new date (M94 §3.3) it moves there in the same act: later than it
     * was, outside the notice period counted from today, every reply reset, and ONE message to its households,
     * "postponed to", where postponing it and then moving it sent two.
     */
    public function postpone(Meeting $meeting, string $reason, User $by, Carbon|string|null $to = null): Meeting
    {
        $this->assertMeetingStage($meeting, [Meeting::STAGE_SCHEDULED], 'postponed');

        $reason = $this->requireReason($reason, 'postponing');

        if ($to !== null) {
            return $this->postponeTo($meeting, $reason, $this->asMoment($to), $by);
        }

        $meeting->forceFill([
            'status' => Meeting::POSTPONED,
            'postponed_at' => now(),
            'postponed_by_name' => mb_substr((string) $by->name, 0, 160),
            'postpone_reason' => $reason,
        ])->save();

        $this->auditMeeting($meeting, 'meeting.postponed', ['reason' => $reason], null, $by);
        app(PushAnnouncer::class)->meetingChanged($meeting, 'postponed');

        return $meeting;
    }

    /** Postponed to its new date (M94 §3.3): one act on the record, from and to, and one message, with why. */
    private function postponeTo(Meeting $meeting, string $reason, Carbon $new, User $by): Meeting
    {
        if ($new->lessThanOrEqualTo($meeting->starts_at)) {
            throw new DomainException('Postponing moves a meeting later than '.SiteTime::local($meeting->starts_at)->format('j M, g:i A').'. To bring it forward, use Change the date.');
        }

        if (($refusal = $this->noticeRefusal($meeting->type, $new)) !== null) {
            throw new DomainException($refusal);
        }

        $from = $meeting->starts_at->copy();
        $cleared = $this->giveNewDate($meeting, $new);

        $this->auditMeeting($meeting, 'meeting.postponed', ['reason' => $reason, 'starts_at' => SiteTime::iso($new), 'replies_reset' => $cleared], ['starts_at' => SiteTime::iso($from)], $by);
        app(PushAnnouncer::class)->meetingChanged($meeting, 'postponed_to', reason: $reason);

        return $meeting;
    }

    /**
     * CANCEL (M56 §1.2): terminal, with a reason and a name, confirmed by typing the meeting's title. The meeting stays
     * on the record, marked, with why and by whom; it is never deleted and never reopened. Everyone in the audience is
     * told, with the reason.
     */
    public function cancelMeeting(Meeting $meeting, string $reason, string $typed, User $by): Meeting
    {
        $this->assertMeetingStage($meeting, [Meeting::STAGE_SCHEDULED, Meeting::STAGE_POSTPONED], 'cancelled');
        $this->assertTypedName($meeting->title, $typed);

        $reason = $this->requireReason($reason, 'cancelling');

        $meeting->forceFill([
            'status' => Meeting::CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $by->getKey(),
            'cancelled_by_name' => mb_substr((string) $by->name, 0, 160),
            'cancel_reason' => $reason,
        ])->save();

        $this->auditMeeting($meeting, 'meeting.cancelled', ['reason' => $reason], null, $by);
        app(PushAnnouncer::class)->meetingChanged($meeting, 'cancelled');

        return $meeting;
    }

    /** Archive a meeting whose minutes are published (M56 §2). Nothing is hidden from residents by it. */
    public function archiveMeeting(Meeting $meeting, User $by): Meeting
    {
        $this->assertMeetingStage($meeting, [Meeting::STAGE_MINUTES_PUBLISHED], 'archived until its minutes are published');

        $meeting->forceFill(['archived_at' => now(), 'archived_by_name' => mb_substr((string) $by->name, 0, 160)])->save();

        $this->auditMeeting($meeting, 'meeting.archived', ['archived' => true], null, $by);

        return $meeting;
    }

    /**
     * THE REGISTER, kept from the console once the meeting has started (M56 §2: "In progress: record attendance").
     * Quorum is counted from these rows and never stored (the model says why), so there is no "mark quorum": what is
     * recorded here is what the badge counts. A household meeting is recorded by lot, a committee by name.
     */
    public function recordAttendance(Meeting $meeting, ?Unit $unit, ?string $name, ?string $representedBy, string $state, User $by): MeetingAttendance
    {
        $this->assertMeetingStage($meeting, [Meeting::STAGE_IN_PROGRESS, Meeting::STAGE_HELD], 'given attendance before it starts');

        if (! in_array($state, [MeetingAttendance::PRESENT, MeetingAttendance::APOLOGIES, MeetingAttendance::ABSENT], true)) {
            throw new DomainException('Say whether they were present, sent apologies, or were absent.');
        }

        $name = trim((string) $name) === '' ? null : mb_substr(trim((string) $name), 0, 160);
        $representedBy = trim((string) $representedBy) === '' ? null : mb_substr(trim((string) $representedBy), 0, 160);

        if ($meeting->quorum_basis === Meeting::HOUSEHOLDS && ! $unit instanceof Unit) {
            throw new DomainException('Say which lot. A general meeting counts households.');
        }

        if ($meeting->quorum_basis === Meeting::MEMBERS && $name === null) {
            throw new DomainException('Say who attended. A committee meeting counts its members by name.');
        }

        $keys = $unit instanceof Unit ? ['meeting_id' => $meeting->id, 'unit_id' => $unit->id] : ['meeting_id' => $meeting->id, 'unit_id' => null, 'attendee_name' => $name];

        return MeetingAttendance::query()->updateOrCreate($keys, ['attendee_name' => $name, 'represented_by' => $representedBy, 'state' => $state]);
    }

    /** Take a line off the register, while it is still being kept (before the minutes are published). */
    public function removeAttendance(MeetingAttendance $row, User $by): void
    {
        $this->assertMeetingStage($row->meeting, [Meeting::STAGE_IN_PROGRESS, Meeting::STAGE_HELD], 'changed: its register is closed');

        $row->delete();
    }

    /**
     * One refusal for every act: what stage the meeting is at, and that this is not done there.
     *
     * @param  list<string>  $stages  the `Meeting::STAGE_*` the act may be taken at
     */
    private function assertMeetingStage(Meeting $meeting, array $stages, string $act): void
    {
        $stage = $meeting->stage();

        if (! in_array($stage, $stages, true)) {
            throw new DomainException(sprintf('%s is %s, so it cannot be %s.', $meeting->title, mb_strtolower(Meeting::STAGE_LABELS[$stage]), $act));
        }
    }

    /** Delete and Cancel confirm by typing the name, never by clicking Yes (M56 §4.3). */
    private function assertTypedName(string $title, string $typed): void
    {
        if (mb_strtolower(trim($typed)) !== mb_strtolower(trim($title))) {
            throw new DomainException('Type the meeting\'s name to confirm: '.$title);
        }
    }

    private function requireReason(string $reason, string $act): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new DomainException('Say why. Households are told the reason for '.$act.' a meeting.');
        }

        return mb_substr($reason, 0, 500);
    }

    /**
     * The agenda arrives keyed as the request sent it, which need not be 0, 1, 2 (a row removed, a hand-made post), so it
     * is numbered afresh here: it was declared a list, which made `array_values()` look like a call with no effect.
     *
     * @param  array<int, array{text: string, start_time?: string|null}>  $agenda
     */
    private function replaceAgenda(Meeting $meeting, array $agenda): void
    {
        $meeting->agenda()->delete();

        foreach (array_values($agenda) as $index => $item) {
            $meeting->agenda()->create([
                'start_time' => $item['start_time'] ?? null,
                'text' => $item['text'],
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>|null  $before
     */
    private function auditMeeting(Meeting $meeting, string $action, array $after, ?array $before, ?User $by): void
    {
        app(AuditLogger::class)->record(
            action: $action,
            entityType: 'Meeting',
            entityId: (string) $meeting->id,
            before: $before,
            after: ['title' => $meeting->title, ...$after],
            tenantId: tenant() === null ? null : (string) tenant()->getTenantKey(),
            actor: $by,
        );
    }

    /**
     * THE MINUTES OF A MEETING THAT HAS BEEN HELD (M21 §1.4). Before M21 minutes were written by a seeder and nothing
     * else: no screen recorded them, so no estate could ever put the record of a real meeting in front of its members.
     *
     * Only a meeting that was published and has started can have minutes — a meeting that has not happened has no
     * record to keep. They may be corrected while unpublished; once published they ARE the record, and a correction
     * is made at the next meeting, in its own minutes, as an estate would on paper.
     */
    public function recordMinutes(Meeting $meeting, string $body, User $by): MeetingMinutes
    {
        if ($meeting->status === Meeting::CANCELLED) {
            throw new DomainException($meeting->title.' was cancelled, so there is no meeting to minute.');
        }

        if ($meeting->published_at === null || $meeting->starts_at->greaterThan(Carbon::now())) {
            throw new DomainException($meeting->title.' has not been held yet. Minutes are written once it has.');
        }

        $body = trim($body);

        if (mb_strlen($body) < 20) {
            throw new DomainException('Minutes say what the meeting decided: who attended, each item, and what was resolved. Write at least a few sentences.');
        }

        $existing = MeetingMinutes::query()->where('meeting_id', $meeting->id)->first();

        if ($existing?->published_at !== null) {
            throw new DomainException('The minutes of '.$meeting->title.' are published: they are the record now. A correction is minuted at the next meeting.');
        }

        return MeetingMinutes::query()->updateOrCreate(
            ['meeting_id' => $meeting->id],
            ['body' => $body, 'recorded_by_name' => mb_substr((string) $by->name, 0, 160)],
        );
    }

    /**
     * Put the minutes in front of the meeting's households (M21 §1.4): the Resident App's meeting shows them from
     * this moment. The live log carries the change (RecordObserver: a minutes row is its meeting's), so an open
     * meetings screen updates by itself.
     */
    public function publishMinutes(Meeting $meeting, User $by): MeetingMinutes
    {
        $minutes = MeetingMinutes::query()->where('meeting_id', $meeting->id)->first();

        if (! $minutes instanceof MeetingMinutes) {
            throw new DomainException('Nothing is written for '.$meeting->title.' yet. Record the minutes first.');
        }

        if ($minutes->published_at !== null) {
            return $minutes;
        }

        $minutes->forceFill(['published_at' => Carbon::now()])->save();

        // "Minutes are available", to everyone in the audience (M56 §2.1).
        app(PushAnnouncer::class)->meetingChanged($meeting, 'minutes');

        return $minutes;
    }

    /**
     * Board 36's quorum badge, counted from the register.
     *
     * TWO MEASURES IN ONE COLUMN, which is why `quorum_basis` exists. A
     * committee's is a head count of its members and reads "6/7"; a general
     * meeting's is a proportion of the estate and reads "41%". Both are counts
     * over `meeting_attendance` — nothing stores whether quorum was met, because
     * a stored flag disagreeing with the register would be the estate's minutes
     * arguing with its own attendance sheet.
     *
     * @return array{present: int, required: int, percent: int, met: bool, label: string, state: string}
     */
    public function quorumOf(Meeting $meeting, ?int $present = null): array
    {
        // Counted for every meeting at once by the register (M11 A2), or here for one.
        $present ??= MeetingAttendance::query()
            ->where('meeting_id', $meeting->id)
            ->where('state', MeetingAttendance::PRESENT)
            ->count();

        $required = $meeting->quorumRequired();
        $eligible = $meeting->quorum_basis === Meeting::MEMBERS
            ? ($meeting->quorum_required_total ?? 0)
            : $meeting->eligible_households;

        $percent = $eligible === 0 ? 0 : (int) round($present * 100 / $eligible);
        $met = $required > 0 && $present >= $required;

        /*
         * A meeting still to come has not failed its quorum: there is nothing to count yet, and the badge says so
         * (M55: "Not yet met" on an unpublished meeting read as a failure). A cancelled meeting was never held.
         */
        $state = match (true) {
            $meeting->status === Meeting::CANCELLED => 'none',
            $meeting->isUpcoming() || in_array($meeting->status, [Meeting::DRAFT, Meeting::POSTPONED], true) => 'upcoming',
            $met => 'done',
            default => 'failed',
        };

        $measure = $meeting->quorum_basis === Meeting::MEMBERS
            ? $present.'/'.$eligible
            : $percent.'%';

        return [
            'present' => $present,
            'required' => $required,
            'percent' => $percent,
            'met' => $met,
            'state' => $state,
            'label' => match ($state) {
                'none' => 'Not held',
                'upcoming' => 'Counted at the meeting',
                default => $met ? 'Quorum met · '.$measure : 'Quorum not met · '.$measure,
            },
        ];
    }

    /* ------------------------------------------------------------------ */
    /* what the screens read */
    /* ------------------------------------------------------------------ */

    /**
     * What a committee motion's card draws (M62 A9): its title and motion, where it stands, the households it is put
     * to (the number every confirmation names), the papers in, and its three choices. Nothing about how anyone voted.
     *
     * @return array<string, mixed>
     */
    private function committeeBallotCard(Ballot $motion): array
    {
        $options = $motion->options()->orderBy('sort_order')->get(['id', 'label']);

        /*
         * THE TALLY, ONCE THE POLL HAS CLOSED AND NEVER BEFORE (M73 §1, step 7: "Read the tally, then publish"). Counted
         * from the marks as the results board counts them (`tally()`). An aggregate after the close says nothing about
         * how anyone voted; a count while voting is open would be a running score, so it is null until then.
         */
        $closed = in_array($motion->stage, [Ballot::TALLY, Ballot::CERTIFIED], true);
        $marks = $closed ? $this->tally($motion) : [];

        return [
            'id' => $motion->id,
            'code' => $motion->code,
            'title' => $motion->title,
            'question' => $motion->question,
            'stage' => $motion->stage,
            'stage_label' => $motion->stageLabel(),
            'certified' => $motion->isCertified(),
            'published' => $motion->published_at !== null,
            'eligible_households' => $this->eligibleHouseholdCount($motion),
            'papers_in' => BallotReceipt::query()->where('ballot_id', $motion->id)->count(),
            'choices' => $options->pluck('label')->all(),
            'tally' => $closed
                ? $options->map(static fn ($option): array => ['label' => (string) $option->label, 'votes' => $marks[(int) $option->id] ?? 0])->values()->all()
                : null,
        ];
    }

    /**
     * Everything board 9 draws.
     *
     * @return array<string, mixed>
     */
    public function controlRoom(int $year): array
    {
        $all = Ballot::query()->where('year', $year)->orderBy('code')->orderBy('id')->get();
        // A committee's motion is not part of the year's election (M62 A9): its own list below, never folded into this
        // board's heading, stage, positions or counts.
        $ballots = $all->reject(static fn (Ballot $ballot): bool => $ballot->isResolution())->values();
        $lead = $this->leadBallot($ballots);

        $positions = BallotPosition::query()
            ->whereIn('ballot_id', $ballots->pluck('id'))
            ->withCount('nominations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $nominated = Nomination::query()->whereIn('ballot_id', $ballots->pluck('id'))->count();
        $pending = Nomination::query()
            ->whereIn('ballot_id', $ballots->pluck('id'))
            ->where('status', Nomination::PENDING)
            ->count();

        $closesIn = $lead?->nominations_close_at === null
            ? null
            // "Closes in N days" is counted from the ESTATE'S today, on the clock the close was typed on (M4 §3.1).
            // Down, not toward nought: a close yesterday at 5 PM is a day gone, never "Today".
            : (int) floor(SiteTime::today()->diffInDays($lead->nominations_close_at, absolute: false));
        // A day, in Law 5's form (M62 A5): "21 Sep", with no clock tag.
        $closeDay = $lead?->nominations_close_at === null ? null : SiteTime::local($lead->nominations_close_at)->format('j M');

        /*
         * ONLY WHILE NOMINATIONS ARE OPEN (GV-14). It was arithmetic on the close date at every stage: "0 days" long
         * after nominations closed, and a count on a draft whose nominations had not opened. Before they open it is
         * empty; once they have closed it says so.
         *
         * AND NEVER "0 DAYS" WHILE THEY ARE STILL OPEN (Security's dev-server note, 12 October): a closing date that has
         * passed with nobody closing them read "0 days until nominations close". It names the day they were due to
         * close, in amber; on the day itself, "Today".
         */
        $stage = $lead === null ? Ballot::DRAFT : $lead->stage;
        $closesStat = match (true) {
            $stage === Ballot::NOMINATIONS_OPEN && $closesIn !== null && $closesIn < 0 => [
                'value' => (string) $closeDay,
                'label' => 'NOMINATIONS WERE DUE TO CLOSE',
                'tone' => 'warning',
            ],
            $stage === Ballot::NOMINATIONS_OPEN && $closesIn === 0 => ['value' => 'Today', 'label' => 'NOMINATIONS CLOSE'],
            $stage === Ballot::NOMINATIONS_OPEN && $closesIn !== null => ['value' => $closesIn.' day'.($closesIn === 1 ? '' : 's'), 'label' => 'UNTIL NOMINATIONS CLOSE'],
            in_array($stage, [Ballot::DRAFT, Ballot::NOMINATIONS_OPEN], true) => ['value' => '—', 'label' => 'UNTIL NOMINATIONS CLOSE'],
            default => ['value' => 'Closed', 'label' => 'UNTIL NOMINATIONS CLOSE'],
        };

        return [
            'year' => $year,

            // THE COMMITTEE'S MOTIONS THIS YEAR (M62 A9): each with what its card needs. Its count is
            // `eligibleHouseholdCount()`, the same number its confirmations name.
            'committee_ballots' => $all->filter(static fn (Ballot $ballot): bool => $ballot->isResolution())
                ->map(fn (Ballot $motion): array => $this->committeeBallotCard($motion))
                ->values()
                ->all(),

            'heading' => $year.' Election',

            // "Ballot A (Community Executive) & Ballot B (Phase Leadership) ·
            // Returning Officer: Delroy Samuels", composed rather than stored —
            // it is three facts joined for one line of a header.
            'subtitle' => trim(implode(' · ', array_filter([
                $ballots->pluck('title')->implode(' & '),
                $lead?->returning_officer_name === null ? null : 'Returning Officer: '.$lead->returning_officer_name,
            ]))),
            'stage' => $lead === null ? Ballot::DRAFT : $lead->stage,
            'stage_label' => $lead?->stageLabel() ?? Ballot::STAGES[Ballot::DRAFT],
            'stepper' => $this->stepper($lead),

            // WHICH PAPER THE STAGE ABOVE IS, of a year with more than one (Security's dev-server note, 12 October).
            'papers' => $this->papersOf($ballots, $lead),

            'stats' => [
                ['value' => (string) $positions->count(), 'label' => 'POSITIONS CONFIGURED'],
                ['value' => (string) $nominated, 'label' => 'CANDIDATES NOMINATED'],
                // "3 days" while open; the empty mark before, "Closed" after (GV-14). A ballot with no nomination window
                // set has not closed today: nobody has said when it closes.
                ['key' => 'closes', ...$closesStat],
                ['value' => (string) ($lead === null ? $this->eligibleHouseholds() : $this->eligibleHouseholdCount($lead)), 'label' => 'ELIGIBLE HOUSEHOLDS'],
            ],
            'positions' => $positions->map(static fn (BallotPosition $position): array => [
                'id' => $position->id,
                'name' => $position->displayName(),
                'seat_label' => $position->seatLabel(),

                /*
                 * The raw figure as well as the label (M10 §7). `seat_label`
                 * reads "3 seats" and is what the board prints; the editor needs
                 * the number to load into a field, and parsing it back out of
                 * the label would break the moment the wording changed.
                 *
                 * `name` above is `displayName()`, which prefixes a phase-scoped
                 * seat with its phase. `edit_name` is what was actually typed,
                 * so retitling one does not save the prefix into the title.
                 */
                'seats' => $position->seat_count,
                'edit_name' => $position->name,

                'scope' => $position->scope,
                'phase' => $position->phase,
                'nominated' => (int) ($position->nominations_count ?? 0),
                'nominated_label' => (int) ($position->nominations_count ?? 0).' nominated',

                /*
                 * Which paper the row is on (B043). A year can hold two, and the
                 * editor configures one of them — the ballot this screen speaks
                 * for — so a row on the other is drawn and never offered for edit.
                 */
                'ballot_id' => $position->ballot_id,
            ])->all(),
            'pending' => $pending,

            // What `open()` refuses on (M53 §13.1): the lead ballot's nominations neither approved nor rejected — so
            // Open voting is drawn disabled with the count, never pressable into a refusal.
            'undecided' => $lead === null ? 0 : $lead->nominations()
                ->whereNotIn('status', [Nomination::APPROVED, Nomination::REJECTED])
                ->count(),

            /*
             * THE BALLOT THIS SCREEN SPEAKS FOR, AND EVERY WRITE IS ADDRESSED TO IT
             * (B043). The position editor, the rename and the reorder post to this
             * id. They used to post to the YEAR, which resolved to the year's
             * oldest ballot while this screen drew its least advanced — so a draft
             * in a year with an older paper showed an editor whose every save
             * landed on the other ballot.
             */
            'ballotId' => $lead?->id,
            'ballot' => $lead === null ? null : [
                'title' => $lead->title,
                'description' => $lead->description,
            ],
            'canCloseEarly' => $lead !== null && $lead->isOpenForNominations(),
            'certified' => $lead?->isCertified() ?? false,

            // AN ELECTION'S LIFE (M56 §3): the acts beside the stage's own, for the lead ballot, and what they start from.
            'life' => $lead === null ? null : [
                'id' => $lead->id,
                'title' => $lead->title,
                'stage' => $lead->stage,
                'nominations' => $lead->nominations()->count(),
                'voted' => BallotReceipt::query()->where('ballot_id', $lead->id)->count(),
                'published' => $lead->published_at !== null,
                'archived' => $lead->archived_at !== null,
                'cancelled' => ! $lead->isCancelled() ? null : [
                    'reason' => $lead->cancel_reason,
                    'by' => $lead->cancelled_by_name,
                    'when' => $lead->cancelled_at === null ? null : SiteTime::local($lead->cancelled_at)->format('j M Y, g:i A'),
                ],
                'nominations_close_local' => $lead->nominations_close_at === null ? null : SiteTime::local($lead->nominations_close_at)->format('Y-m-d\TH:i'),
                'closes_local' => $lead->closes_at === null ? null : SiteTime::local($lead->closes_at)->format('Y-m-d\TH:i'),
                'default_close_local' => SiteTime::local($this->defaultReopenClose())->format('Y-m-d\TH:i'),
            ],

            /*
             * Every year with an election, newest first (B042). The Elections tab
             * goes to the latest year, so an election drafted for next year would
             * otherwise leave this year's control room and results reachable only
             * by typing the address.
             */
            'years' => Ballot::query()->distinct()->orderByDesc('year')->pluck('year')->map(static fn ($y): int => (int) $y)->values()->all(),
        ];
    }

    /**
     * What board 10's nominations order by (M32 F5). Position is the board's own order — the seats as the paper lists
     * them, and the oldest nomination first within a seat.
     *
     * @return array<string, string|\Closure>
     */
    public static function nominationSorts(): array
    {
        $standing = [Nomination::PENDING => 0, Nomination::MORE_INFO => 1, Nomination::APPROVED => 2, Nomination::REJECTED => 3];

        return [
            'name' => 'name',
            'position' => static fn (array $row): array => [$row['position_order'], $row['id']],
            'nominator' => 'nominator',
            'seconder' => 'seconder',
            // Waiting on the returning officer first, then waiting on the candidate, then decided.
            'status' => static fn (array $row): int => $standing[$row['status']] ?? count($standing),
        ];
    }

    /** Board 10 as the address asks for it (M32 F5). */
    public static function nominationsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::nominationSorts(), 'position', 'asc');
    }

    /**
     * Everything board 10 draws, the nominations a page at a time in the order their headings ask for (M32 F5).
     *
     * @return array<string, mixed>
     */
    public function nominationsBoard(int $year, string $position = '', ?TableQuery $table = null, bool $undecidedOnly = false): array
    {
        $table ??= self::nominationsTable();

        $ballotIds = Ballot::query()->where('year', $year)->pluck('id');

        $nominations = Nomination::query()
            ->whereIn('ballot_id', $ballotIds)
            ->with(['position', 'unit'])
            ->get()

            /*
             * Board 10: "rows grouped by position sought (Chairman rows first,
             * then Vice Chairman)", then oldest nomination first within a seat.
             *
             * TWO-ARGUMENT COMPARATORS, NOT KEY EXTRACTORS. A callable handed to
             * `sortBy([...])` is called as `$fn($a, $b)` and its return value IS
             * the comparison — Laravel only treats the argument as a key when it
             * is a string. A one-argument closure here silently returned the
             * first row's sort order as the verdict, which put the whole board in
             * an order nobody chose. See D-052.
             */
            ->sortBy([
                static fn (Nomination $a, Nomination $b): int => $a->position->sort_order <=> $b->position->sort_order,
                static fn (Nomination $a, Nomination $b): int => $a->id <=> $b->id,
            ]);

        $rows = [];

        foreach ($nominations as $nomination) {
            $name = $nomination->position->displayName();

            if ($position !== '' && $name !== $position) {
                continue;
            }

            $undecided = in_array($nomination->status, [Nomination::PENDING, Nomination::MORE_INFO], true);

            if ($undecidedOnly && ! $undecided) {
                continue;
            }

            $rows[] = [
                'id' => $nomination->id,
                'initials' => $nomination->initials(),
                'name' => $nomination->candidate_name,

                /*
                 * "Phase 1 · Lot 12", read off the UNIT and not off the
                 * nomination. One estate has one map, and a lot that showed a
                 * different phase here from the one the arrears board gives it
                 * would be two estates.
                 */
                'sub' => trim(($nomination->unit->block ?? '').' · '.($nomination->unit->reference ?? ''), ' ·'),
                'position' => $name,
                // Where the seat sits on the paper — what the table orders the position column by (M32 F5).
                'position_order' => (int) $nomination->position->sort_order,
                'nominator' => $nomination->nominator_name,
                'seconder' => $nomination->seconder_name,
                'status' => $nomination->status,
                'status_label' => $nomination->statusLabel(),
                'reason' => $nomination->decision_reason,

                // Board 10's row actions are status-driven: an undecided row gets the tick and the cross, a decided
                // one gets a "View" link. UNDECIDED, NOT ONLY PENDING (M56 §3.4): a nomination asked for more
                // information holds Open voting exactly as a pending one does, and was drawn with no way to decide it,
                // so the poll could be blocked by a row nobody was able to act on.
                'decidable' => $undecided,
            ];
        }

        $page = $table->pageOf($rows);

        return [
            'year' => $year,
            'filter' => $position,
            'undecidedOnly' => $undecidedOnly,
            'positions' => $nominations
                ->map(static fn (Nomination $n): string => $n->position->displayName())
                ->unique()
                ->values()
                ->all(),

            // The counter chip spans the UNFILTERED set — board 10 draws "5
            // pending review" beside a page slice holding two of them.
            'pending' => $nominations->where('status', Nomination::PENDING)->count(),

            // Where a candidate can be added now (M56 §3.3): the year's ballots taking nominations, and their seats.
            'addTo' => Ballot::query()->where('year', $year)
                ->whereIn('stage', [Ballot::NOMINATIONS_OPEN, Ballot::NOMINATIONS_CLOSED, Ballot::CANDIDATE_VETTING])
                ->whereDoesntHave('options')
                ->with(['positions' => static fn ($q) => $q->orderBy('sort_order')])
                ->orderBy('code')->orderBy('id')
                ->get()
                ->map(static fn (Ballot $ballot): array => [
                    'ballot_id' => $ballot->id,
                    'title' => $ballot->title,
                    'positions' => $ballot->positions->map(static fn (BallotPosition $p): array => ['id' => $p->id, 'name' => $p->displayName()])->values()->all(),
                ])
                ->filter(static fn (array $b): bool => $b['positions'] !== [])
                ->values()
                ->all(),
            'rows' => array_values($page->items()),
            'table' => $table->meta($page),
        ];
    }

    /**
     * One nomination, and the record behind its decision — board 10's "View"
     * (12 §2, Wave 4). No board draws it.
     *
     * WHAT IS STORED, AND NOTHING ELSE. The candidate, the seat, who proposed
     * and who seconded, the decision with its reason, who took it and when, and
     * the eligibility snapshot taken at that moment. A proposer's signed form
     * and a seconder's written confirmation are not held by this system, and
     * the screen says so rather than drawing a slot for paper nobody scanned.
     *
     * THE SNAPSHOT IS THE POINT. It is what the returning officer decided
     * against, and it does not move when the household pays next week — that is
     * the reason the four `_at_check` columns exist (see the model).
     *
     * THE ARREARS AMOUNT IS A LEDGER FACT, and only a viewer who holds Dues &
     * ledger sees it. The Secretary runs the election with Governance Full and
     * holds no ledger access at all, so the ageing bucket — which board 10
     * already prints in its badge — is shown to everyone who can open this, and
     * the figure only to those who could read it on the unit's ledger anyway.
     *
     * @return array<string, mixed>|null
     */
    public function nominationDetail(int $nominationId, bool $showsAmounts): ?array
    {
        $nomination = Nomination::query()->with(['position', 'unit', 'ballot'])->find($nominationId);

        if ($nomination === null) {
            return null;
        }

        $decided = $nomination->decided_at !== null;

        return [
            'id' => $nomination->id,
            'initials' => $nomination->initials(),
            'name' => $nomination->candidate_name,

            /*
             * Why they are standing, in their own words (M10 §7). Null where
             * nobody wrote anything, which is most elections — the screen draws
             * the row without it rather than an empty quotation.
             */
            'statement' => $nomination->statement,

            'sub' => trim(($nomination->unit->block ?? '').' · '.($nomination->unit->reference ?? ''), ' ·'),
            'position' => $nomination->position->displayName(),
            'ballot' => $nomination->ballot->title,
            'year' => $nomination->ballot->year,
            'nominator' => $nomination->nominator_name,
            'seconder' => $nomination->seconder_name,
            'lodged_on' => $nomination->created_at === null ? null : SiteTime::local($nomination->created_at)->format('j M Y'),
            'status' => $nomination->status,
            'status_label' => $nomination->statusLabel(),
            'reason' => $nomination->decision_reason,
            'decided_at' => $nomination->decided_at === null ? null : SiteTime::local($nomination->decided_at)->format('j M Y, g:i A'),
            'decided_by' => $nomination->decided_by_name,
            'snapshot' => ! $decided ? null : [
                'checked_on' => $nomination->eligibility_checked_on?->format('j M Y') ?? 'Not recorded',
                'arrears_bucket' => match ($nomination->arrears_bucket_at_check) {
                    'd90' => '90 days or more overdue',
                    'd60' => '60 to 89 days overdue',
                    'd30' => '30 to 59 days overdue',
                    'current' => 'Nothing 30 days overdue',
                    default => 'Not recorded: the unit was not linked when this was decided',
                },
                'arrears' => $showsAmounts && $nomination->arrears_minor_at_check !== null
                    ? MoneyFormatter::fromMinor($nomination->arrears_minor_at_check)
                    : null,
                'tenure' => $nomination->tenure_months_at_check === null
                    ? 'Not on record: nothing in this system records when a household moved in, and an unknown tenure is not a short one'
                    : $nomination->tenure_months_at_check.' months',
            ],
            'amounts_hidden' => ! $showsAmounts,
        ];
    }

    /**
     * Everything board 11 draws.
     *
     * NO COUNT BEFORE THE CLOSE (Hassam, 11 Oct: "Live vote counts during an open election change how people vote").
     * Until the lead ballot's poll has closed (`Ballot::countIsClosed()`) the board is the turnout alone, how many
     * households have voted of how many may, overall and by phase: no candidate, no count, and the marks are not even
     * read. Every reader of this board (the results page, the certificate, the turnout report) inherits the rule.
     *
     * @return array<string, mixed>
     */
    public function resultsBoard(int $year): array
    {
        // A committee's motion is not the year's election (M62 A9), here as on the control room: its result is its own
        // card there, and a motion further along than the election was this page's "2026 election results".
        $ballots = Ballot::query()->where('year', $year)->orderBy('code')->orderBy('id')->get()
            ->reject(static fn (Ballot $ballot): bool => $ballot->isResolution())
            ->values();
        $ballot = $this->leadBallot($ballots, furthest: true);

        if ($ballot === null) {
            return ['year' => $year, 'ballot' => null, 'turnout' => null, 'phases' => [], 'cards' => [], 'count_closed' => false, 'papers' => []];
        }

        $closed = $ballot->countIsClosed();
        $tally = $closed ? $this->tally($ballot) : [];
        $turnout = $this->turnout($ballot);

        $cards = [];

        foreach ($closed ? $ballot->positions()->with('options')->get() : [] as $position) {
            $options = $position->options
                ->map(static fn (BallotOption $option): array => [
                    'id' => $option->id,
                    'initials' => $option->initials(),
                    'name' => $option->label,
                    'phase' => $option->phase,
                    'votes' => 0,
                ])
                ->all();

            foreach ($options as $i => $row) {
                $options[$i]['votes'] = $tally[$row['id']] ?? 0;
            }

            usort($options, static fn (array $a, array $b): int => $b['votes'] <=> $a['votes']);

            foreach ($options as $i => $row) {
                /*
                 * THE DENOMINATOR IS BALLOTS CAST, NOT VOTES CAST, and board 11
                 * says so: in a three-seat race the shares deliberately sum to
                 * more than 100 because one voter marks three names. Dividing by
                 * total votes would make a three-seat race look like a
                 * three-way split of one seat.
                 */
                $options[$i]['percent'] = $turnout['cast'] === 0
                    ? 0
                    : (int) round($row['votes'] * 100 / $turnout['cast']);

                $options[$i]['is_winner'] = $i < $position->seat_count && $row['votes'] > 0;
            }

            $cards[] = [
                'position' => $position->displayName(),

                // "Vice Chairman · 3 seats", and a bare "Chairman" for one seat.
                'heading' => $position->seat_count > 1
                    ? $position->displayName().' · '.$position->seat_count.' seats'
                    : $position->displayName(),
                'seat_count' => $position->seat_count,
                'percent_basis' => 'ballots_cast',
                'rows' => $options,
            ];
        }

        return [
            'year' => $year,
            'ballot' => [
                'id' => $ballot->id,
                'title' => $ballot->title,
                'stage' => $ballot->stage,
                'stage_label' => $ballot->stageLabel(),
                'certified' => $ballot->isCertified(),
                'certified_at' => $ballot->certified_at === null ? null : SiteTime::local($ballot->certified_at)->format('j M Y, g:i A'),
                'certified_by' => $ballot->certified_by_name,
                'returning_officer' => $ballot->returning_officer_name,
                'outcome' => $ballot->outcome_statement,
                'published' => $ballot->published_at !== null,
                // When its poll ran, as moments, so the turnout report can ask whether it falls in a period (REP-7).
                'opens_at' => $ballot->opens_at?->toIso8601String(),
                'closes_at' => $ballot->closes_at?->toIso8601String(),
            ],
            'turnout' => $turnout,
            'quorum' => $this->quorum($ballot, $turnout),
            'phases' => $this->turnoutByPhase($ballot),
            'cards' => $cards,
            // Whether the poll has closed, so the count is in `cards`; while it is open they are empty by design.
            'count_closed' => $closed,

            // WHICH PAPER THIS COUNT IS, of a year with more than one (Security's dev-server note, 12 October).
            'papers' => $this->papersOf($ballots, $ballot),
        ];
    }

    /**
     * What the election certificate says — board 11's Export (12 §1).
     *
     * THE TALLY IS READ BACK, NOT RECOMPUTED. `resultsBoard()` is what the
     * screen shows and what this prints; a certificate with its own arithmetic
     * over the same ballots would be a second answer to a question already
     * decided, and the two would differ the first time either changed.
     *
     * A CERTIFICATE IS ONLY ISSUED FOR A CERTIFIED BALLOT. The document says
     * who was elected and stands behind it; issuing one over a count still open
     * would put a returning officer's name on a result that can still move.
     *
     * @return array<string, mixed>
     */
    public function certificateData(int $ballotId): array
    {
        $ballot = Ballot::query()->findOrFail($ballotId);

        if (! $ballot->isCertified()) {
            throw new DomainException(
                'That count has not been certified. A certificate names who was elected and a returning officer '.
                'stands behind it, so it is issued from a certified result and never from one still open.'
            );
        }

        $board = $this->resultsBoard($ballot->year);

        return [
            'year' => $ballot->year,
            'title' => $ballot->title,
            'returning_officer' => $ballot->returning_officer_name,
            // A certificate has room: the clock by its name, "September 30, 2026 at 8:30 PM Jamaica time" (M19A §4.2).
            'certified_at' => $ballot->certified_at === null ? null : SiteTime::local($ballot->certified_at)->format('F j, Y \a\t g:i A').' '.SiteTime::clockName(SiteTime::zone()),
            'certified_by' => $ballot->certified_by_name,
            'outcome' => $ballot->outcome_statement,
            'turnout' => $board['turnout'],
            'quorum' => $board['quorum'],
            'cards' => $board['cards'],
        ];
    }

    /**
     * What the meeting register orders by (M32 F5). `agenda` is board 36's own order, and the default: upcoming
     * soonest-first, then past newest-first — the next thing a resident has to turn up to, then the history behind it.
     * Any other heading orders the whole register by its own column.
     *
     * @return array<string, string|\Closure>
     */
    public static function meetingSorts(): array
    {
        return [
            // A postponed meeting waits for its new date (M56 §2.3): after every dated meeting to come, before the past.
            'agenda' => static fn (array $row): array => match (true) {
                $row['stage'] === Meeting::STAGE_POSTPONED => [1, $row['starts_ts']],
                $row['upcoming'] => [0, $row['starts_ts']],
                default => [2, -$row['starts_ts']],
            },
            'title' => 'title',
            'type' => 'type_label',
            // A meeting with no date yet sorts after every date.
            'date' => static fn (array $row): array => [$row['stage'] === Meeting::STAGE_POSTPONED ? 1 : 0, $row['starts_ts']],
            'audience' => 'audience',
        ];
    }

    public static function meetingsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::meetingSorts(), 'agenda');
    }

    /**
     * Everything board 36 draws — the register as a table ordered by the server and paged (M32 F5).
     *
     * @return array<string, mixed>
     */
    public function meetingsBoard(?TableQuery $table = null): array
    {
        $table ??= self::meetingsTable();
        $meetings = Meeting::query()->with('minutes')->get();

        /*
         * WHO WAS PRESENT AT EVERY MEETING, IN ONE COUNT (M11 A2). `quorumOf()` asked
         * it of each meeting in turn, and the register is every meeting the estate
         * has ever held — a dozen a year, for as many years as it has run.
         */
        $present = MeetingAttendance::query()
            ->whereIn('meeting_id', $meetings->modelKeys())
            ->where('state', MeetingAttendance::PRESENT)
            ->selectRaw('meeting_id, COUNT(*) AS present')
            ->groupBy('meeting_id')
            ->pluck('present', 'meeting_id')
            ->map(static fn ($n): int => (int) $n);

        $rows = $meetings
            ->sortBy([
                /*
                 * Upcoming soonest-first, then past newest-first — board 36's
                 * order, and the only one a register reads sensibly in: the next
                 * thing a resident has to turn up to, then the history behind it.
                 *
                 * TWO-ARGUMENT COMPARATORS, NOT KEY EXTRACTORS, for the reason
                 * spelled out on `nominationsBoard()` above: `sortBy([...])`
                 * calls a callable as `$fn($a, $b)` and takes what it returns as
                 * the verdict. By the time the second one runs the first has
                 * already settled that both rows are in the same group, so it is
                 * free to reverse itself for the held ones.
                 */
                static fn (Meeting $a, Meeting $b): int => ($a->isUpcoming() ? 0 : 1) <=> ($b->isUpcoming() ? 0 : 1),
                static fn (Meeting $a, Meeting $b): int => $a->isUpcoming()
                    ? $a->starts_at->getTimestamp() <=> $b->starts_at->getTimestamp()
                    : $b->starts_at->getTimestamp() <=> $a->starts_at->getTimestamp(),
            ])
            ->map(function (Meeting $meeting) use ($present): array {
                $quorum = $this->quorumOf($meeting, $present[$meeting->id] ?? 0);
                $stage = $meeting->stage();
                // A draft inside its notice period says so on its row, with the way out (M56 §2.2), not only at Publish.
                $refusal = $stage === Meeting::STAGE_DRAFT ? $this->noticeRefusal($meeting->type, $meeting->starts_at) : null;

                return [
                    'id' => $meeting->id,
                    'title' => $meeting->title,
                    'type' => $meeting->type,
                    'type_label' => $meeting->typeLabel(),
                    'date' => $meeting->dateLabel(),
                    'audience' => $meeting->audienceLabel(),
                    'quorum_label' => $quorum['label'],
                    'quorum_state' => $quorum['state'],

                    // Where it is in its life (M56 §2, §4.5), as the chip on the row.
                    'stage' => $stage,
                    'stage_label' => Meeting::STAGE_LABELS[$stage],
                    'publish_refusal' => $refusal,
                    'move_label' => $refusal === null ? null : 'Move to '.SiteTime::local($this->earliestKeepingTime($meeting))->format('j M'),

                    // The row's menu (M56 §4): the acts its stage allows, as the meeting's own page draws them.
                    'acts' => array_values(array_filter(
                        Meeting::ACTS[$stage] ?? [],
                        static fn (string $act): bool => $act !== 'move_to_earliest' || $refusal !== null,
                    )),

                    // Board 36's row action: an agenda before, minutes after.
                    'action' => $meeting->minutes === null ? 'View agenda' : 'View minutes',
                    'has_minutes' => $meeting->minutes !== null,
                    'published' => $meeting->published_at !== null,

                    // What the register is ordered by (M32 F5): whether it is still to come, and when. A postponed
                    // meeting is still to come whatever day it was first called for (M56 §2.3).
                    'upcoming' => $meeting->isUpcoming() || $stage === Meeting::STAGE_POSTPONED,
                    'starts_ts' => $meeting->starts_at->getTimestamp(),
                ];
            })
            ->values()
            ->all();

        // The register's search (M32 F5): the meeting's title, its kind and its audience.
        if ($table->search !== '') {
            $needle = mb_strtolower($table->search);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower($row['title'].' '.$row['type_label'].' '.$row['audience']), $needle)));
        }

        $page = $table->pageOf($rows);

        return [
            'rows' => $page->items(),
            'table' => $table->meta($page),

            // Which election board 36's "Elections" tab leads to — see electionYear().
            'electionYear' => $this->electionYear(),
        ];
    }

    /**
     * The meetings held in a year that have minutes — what the election control
     * room's "Export minutes" offers (12 §2, item 33). A ballot records no
     * meeting, so the reader chooses from these rather than the screen guessing.
     *
     * @return list<array{id: int, title: string, date: string}>
     */
    public function minutedMeetings(int $year): array
    {
        // The ESTATE'S year (M4 §3.1): from its first moment of Jan 1 to its last of Dec 31, as UTC instants —
        // `whereYear` reads the stored UTC digits, and would put a New Year's Eve meeting in the wrong archive.
        $yearBounds = [
            SiteTime::dayBounds($year.'-01-01')[0],
            SiteTime::dayBounds($year.'-12-31')[1],
        ];

        return Meeting::query()
            ->whereBetween('starts_at', $yearBounds)
            ->where('starts_at', '<=', Carbon::now())
            ->whereHas('minutes')
            ->orderByDesc('starts_at')
            ->get()
            ->map(static fn (Meeting $meeting): array => [
                'id' => $meeting->id,
                'title' => $meeting->title,
                // On the estate's calendar, in Law 5's form (M62 A5): an evening meeting is not the next day in UTC.
                'date' => SiteTime::local($meeting->starts_at)->format('j M Y'),
            ])
            ->all();
    }

    /**
     * One meeting, whole — the detail screen behind board 36's rows (12 §2,
     * Wave 2 item 21). No board draws it.
     *
     * WHAT A RESIDENT OR AN AUDITOR ASKS OF A MEETING: when and where, who it
     * was called for and with how much notice, what was on the agenda, whether
     * it was quorate, and what was minuted. Every figure is the register's own
     * — the quorum is counted from attendance by `quorumOf()`, the same reading
     * board 36 prints, so the two screens cannot disagree.
     *
     * ATTENDANCE IS COUNTED, NOT LISTED. The count is what quorum turns on; a
     * list of which households stayed home is not what anybody opens a meeting
     * record to learn.
     *
     * @return array<string, mixed>|null
     */
    public function meetingDetail(int $meetingId): ?array
    {
        $meeting = Meeting::query()->with(['agenda', 'minutes'])->find($meetingId);

        if ($meeting === null) {
            return null;
        }

        $quorum = $this->quorumOf($meeting);

        $attendance = MeetingAttendance::query()
            ->where('meeting_id', $meeting->id)
            ->selectRaw('state, COUNT(*) as n')
            ->groupBy('state')
            ->pluck('n', 'state');

        /*
         * WHO TO EXPECT (M21 §1.4). The Resident App's reply "tells the secretary who to expect" — and no console screen
         * read it, so it told nobody. An RSVP is not attendance and quorum is never counted from it; it is shown as what
         * it is: which households said they are coming or sent apologies, who in the household said so, and when.
         */
        $rsvps = DB::connection('tenant')->table('meeting_rsvps as r')
            ->leftJoin('units as u', 'u.id', '=', 'r.unit_id')
            ->where('r.meeting_id', $meeting->id)
            ->orderBy('r.response')->orderBy('u.reference')
            ->get(['r.response', 'r.responded_by_name', 'r.responded_at', 'u.reference']);

        /*
         * EVERY DATE ON THIS PAGE IN LAW 5'S ONE FORM (M62 A5, DR-039; Security's dev-server note, 12 Oct: the page
         * wrote "14 Sep 2026", "Monday, October 5, 2026 · 2:00 PM Jamaica time" and "Oct 9, 2026 7:07 PM JA"): a day
         * "5 Oct 2026", a moment "5 Oct 2026, 2:00 PM", and no clock tag, because the console is on the estate's clock.
         */
        return [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'type_label' => $meeting->typeLabel(),
            // A postponed meeting has no date until it is given one (M56 §2.3): the day it was called for is said as that.
            'when' => $meeting->status === Meeting::POSTPONED
                ? Meeting::DATE_TO_BE_SET.'. It was '.SiteTime::local($meeting->starts_at)->format('j M Y, g:i A').'.'
                : SiteTime::local($meeting->starts_at)->format('j M Y, g:i A'),

            /*
             * WHERE, in the same words the Resident App is given (M10 §8).
             * `Meeting::placeLine()` composes it once on the server, so the
             * register, the notice and the handset cannot word it three ways —
             * and it is never blank, because a household reading a meeting with
             * no location assumes the screen is broken rather than that the
             * secretary has not decided.
             */
            'held_as' => $meeting->held_as,
            'place' => $meeting->placeLine(),
            'venue' => $meeting->venue,
            'virtual_link' => $meeting->virtual_link,
            'audience' => $meeting->audienceLabel(),
            'status' => $meeting->status,
            'status_label' => match (true) {
                $meeting->status === Meeting::CANCELLED => 'Cancelled',
                $meeting->published_at === null => 'Draft, not yet published',
                $meeting->isUpcoming() => 'Published '.SiteTime::local($meeting->published_at)->format('j M Y'),
                default => 'Held',
            },
            'published' => $meeting->published_at !== null,
            // The rule, and the day notice went out beside it, on the estate's calendar (GV-17): the rule alone could not
            // be checked from the console.
            'notice' => implode(' · ', array_filter([
                $meeting->notice_days_required === null ? null : $meeting->notice_days_required.' days\' notice required for this meeting type',
                $meeting->notice_given_at === null ? null : 'Notice given '.SiteTime::local($meeting->notice_given_at)->format('j M Y'),
            ])) ?: null,
            'recording' => $meeting->recording_enabled
                ? 'Recorded, with a consent notice to attendees'
                : 'Not recorded',
            'agenda' => $meeting->agenda->map(static fn (MeetingAgendaItem $item): array => [
                // An agenda time is a wall-clock time at the estate, the console's own clock (Law 5: no tag).
                'time' => $item->start_time === null ? null : Carbon::parse($item->start_time)->format('g:i A'),
                'text' => $item->text,
                'motion' => $item->motion_reference,
            ])->all(),
            'quorum' => [
                'label' => $quorum['label'],
                'state' => $quorum['state'],
                'required' => $quorum['required'],
                'present' => $quorum['present'],
                'basis' => $meeting->quorum_basis === Meeting::MEMBERS
                    ? $meeting->quorum_percent.'% of '.($meeting->quorum_required_total ?? 0).' committee members'
                    : $meeting->quorum_percent.'% of '.$meeting->eligible_households.' eligible households',
                'apologies' => (int) ($attendance[MeetingAttendance::APOLOGIES] ?? 0),
                'absent' => (int) ($attendance[MeetingAttendance::ABSENT] ?? 0),
            ],
            'minutes' => $meeting->minutes === null ? null : [
                'body' => $meeting->minutes->body,
                'recorded_by' => $meeting->minutes->recorded_by_name,
                // M21 §1.4: whether the households can read them yet, and since when (the estate's clock, labelled).
                'published' => $meeting->minutes->published_at === null ? null : SiteTime::local($meeting->minutes->published_at)->format('j M Y, g:i A'),
                'adopted' => $meeting->minutes->adopted_at === null
                    ? 'Not yet adopted'
                    : 'Adopted '.$meeting->minutes->adopted_at->format('j M Y').($meeting->minutes->adopted_by_name === null ? '' : ' by '.$meeting->minutes->adopted_by_name),
            ],
            'rsvps' => [
                'attending' => $rsvps->where('response', 'attending')->count(),
                'apologies' => $rsvps->where('response', 'apologies')->count(),
                'not_attending' => $rsvps->where('response', 'not_attending')->count(),
                'rows' => $rsvps->map(static fn (object $r): array => [
                    'lot' => (string) ($r->reference ?? '—'),
                    'response' => match ($r->response) {
                        'attending' => 'Attending',
                        'apologies' => 'Apologies',
                        default => 'Not attending',
                    },
                    'by' => $r->responded_by_name,
                    'when' => $r->responded_at === null ? null : SiteTime::local(Carbon::parse((string) $r->responded_at, 'UTC'))->format('j M, g:i A'),
                ])->values()->all(),
            ],
            'has_minutes' => $meeting->minutes !== null,
            // M21 §1.4: minutes are written once a published meeting has started, and not after they are published.
            'can_minute' => in_array($meeting->stage(), [Meeting::STAGE_IN_PROGRESS, Meeting::STAGE_HELD], true) && $meeting->minutes?->published_at === null,

            ...$this->meetingLife($meeting),
        ];
    }

    /**
     * WHAT THE MEETING'S PAGE OFFERS AT THIS POINT IN ITS LIFE (M56 §2, §4): its stage, the acts that stage allows and
     * nothing else (Law 1: what cannot be done here is not drawn), who an act would tell, and what each form starts
     * from. The routes refuse the same acts whatever the screen offers.
     *
     * @return array<string, mixed>
     */
    private function meetingLife(Meeting $meeting): array
    {
        $stage = $meeting->stage();
        $local = SiteTime::local($meeting->starts_at);

        // The estate's notice rule, read once for everything below. Each helper read it again for itself, and the page
        // paid a query over its budget for it (M71; the QA session's budget run, 10 October).
        $settings = EstateSetting::current();
        $earliest = SiteTime::local($this->earliestStart($meeting->type, null, $settings));

        $acts = Meeting::ACTS[$stage] ?? [];

        if ($stage === Meeting::STAGE_DRAFT && $this->noticeRefusal($meeting->type, $meeting->starts_at, $settings) === null) {
            $acts = array_values(array_diff($acts, ['move_to_earliest']));
        }

        // Who an act tells (M56 §4.4): "This tells 443 households", the households living there, whoever may vote. A
        // committee's own meeting tells nobody's phone.
        $told = match ($meeting->audience_scope) {
            Meeting::COMMITTEE_ONLY => 0,
            // The same count its quorum takes (M69): one definition of a phase's households.
            Meeting::PHASE_SUBSET => $this->phaseHouseholds((string) $meeting->phase),
            default => $this->householdsOn(Unit::lots()),
        };

        return [
            'stage' => $stage,
            'stage_label' => Meeting::STAGE_LABELS[$stage],
            'acts' => $acts,
            'type' => $meeting->type,
            'phase' => $meeting->phase,
            'description' => $meeting->description,
            'duration_minutes' => (int) $meeting->duration_minutes,
            'platform_name' => $meeting->platform_name,
            'starts_local' => $local->format('Y-m-d\TH:i'),
            'earliest_local' => $earliest->format('Y-m-d\TH:i'),
            'notice_line' => sprintf('Needs %d days\' notice. Earliest %s.', $settings->noticeDaysFor($meeting->type), $earliest->format('j M')),
            'publish_refusal' => $stage === Meeting::STAGE_DRAFT ? $this->noticeRefusal($meeting->type, $meeting->starts_at, $settings) : null,
            'move_label' => in_array('move_to_earliest', $acts, true) ? 'Move to '.SiteTime::local($this->earliestKeepingTime($meeting, $settings))->format('j M') : null,
            'households_told' => $told,
            'agenda_items' => $meeting->agenda->map(static fn (MeetingAgendaItem $item): array => [
                'start_time' => $item->start_time === null ? '' : Carbon::parse($item->start_time)->format('H:i'),
                'text' => $item->text,
            ])->values()->all(),
            'postponed' => $meeting->status !== Meeting::POSTPONED ? null : [
                'reason' => $meeting->postpone_reason,
                'by' => $meeting->postponed_by_name,
                'when' => $meeting->postponed_at === null ? null : SiteTime::local($meeting->postponed_at)->format('j M Y, g:i A'),
            ],
            'cancelled' => $meeting->status !== Meeting::CANCELLED ? null : [
                'reason' => $meeting->cancel_reason,
                'by' => $meeting->cancelled_by_name,
                'when' => $meeting->cancelled_at === null ? null : SiteTime::local($meeting->cancelled_at)->format('j M Y, g:i A'),
            ],
            'archived' => $meeting->archived_at === null ? null : SiteTime::local($meeting->archived_at)->format('j M Y'),
            // The register, kept from the console while the meeting is on and after it (M56 §2).
            'register' => in_array($stage, [Meeting::STAGE_IN_PROGRESS, Meeting::STAGE_HELD, Meeting::STAGE_MINUTES_PUBLISHED, Meeting::STAGE_ARCHIVED], true)
                ? MeetingAttendance::query()->leftJoin('units as u', 'u.id', '=', 'meeting_attendance.unit_id')
                    ->where('meeting_attendance.meeting_id', $meeting->id)
                    ->orderBy('u.reference')->orderBy('meeting_attendance.attendee_name')
                    ->get(['meeting_attendance.id', 'meeting_attendance.state', 'meeting_attendance.attendee_name', 'meeting_attendance.represented_by', 'u.reference'])
                    ->map(static fn ($row): array => [
                        'id' => (int) $row->id,
                        'who' => trim(implode(' · ', array_filter([(string) ($row->reference ?? ''), (string) ($row->attendee_name ?? '')]))),
                        'state' => ucfirst((string) $row->state),
                        'represented_by' => $row->represented_by,
                    ])->all()
                : [],
            'counts_households' => $meeting->quorum_basis === Meeting::HOUSEHOLDS,
            'recording_enabled' => (bool) $meeting->recording_enabled,
            // A draft's edit form offers the estate's phases for a phase meeting.
            'phases' => $stage === Meeting::STAGE_DRAFT ? Unit::query()->distinct()->orderBy('block')->pluck('block')->filter()->values()->all() : [],
        ];
    }

    /**
     * Which election a governance tab labelled "Elections" leads to.
     *
     * An election is addressed by year and neither the meetings register nor the
     * notices log is, so the tab has to be told one — and the LATEST year is the
     * right answer rather than today's: an estate reading its meetings in
     * January 2027 wants the 2026 committee election it just ran, not a 2027
     * ballot nobody has drafted. Today's year is the fallback for an estate that
     * has never held one, which lands on an empty control room rather than a
     * 404. One method, because boards 32 and 36 both draw the tab, and two copies
     * of this rule would be free to disagree about which election is current.
     */
    public function electionYear(): int
    {
        return (int) (Ballot::query()->max('year') ?? SiteTime::date()->year);
    }

    /**
     * The blank form board 12 draws, and what it must refuse.
     *
     * Board 12 is `/governance/meetings/new` — it draws a draft nobody has
     * saved, so these are DEFAULTS and not a stored meeting. The earliest
     * publishable date for each type is returned with them, because a form that
     * only learns about the notice period when it is refused has wasted the
     * secretary's afternoon.
     *
     * @return array<string, mixed>
     */
    public function schedulerDefaults(): array
    {
        $settings = EstateSetting::current();
        $phases = Unit::query()->distinct()->orderBy('block')->pluck('block')->filter()->values();

        return [
            /*
             * The eyebrow travels with the type, because board 12's preview
             * prints "ANNUAL GENERAL MEETING" over a segmented control whose
             * button says "AGM" — and the pairing between the two is
             * `Meeting::TYPE_EYEBROWS`. Uppercasing the label in the browser
             * would give "AGM" and a second, quieter answer to what a type is
             * called.
             */
            'types' => array_map(
                static fn (string $key): array => [
                    'value' => $key,
                    'label' => Meeting::TYPES[$key],
                    'eyebrow' => Meeting::TYPE_EYEBROWS[$key],
                    // The title the form gives a meeting of this type until somebody edits it (GV-2).
                    'title' => Meeting::TYPE_TITLES[$key],
                ],
                array_keys(Meeting::TYPES),
            ),
            'phases' => $phases->all(),
            /*
             * A COMMITTEE MEETING, NOT THIS YEAR'S AGM (the QA session's note, 10 October). Opened as "Annual General
             * Meeting 2026", the title stayed when the type changed (a tester's committee and phase meetings both carried
             * it), and Schedule meeting pressed as it opened made a second AGM beside the real one, for the whole estate.
             * The meeting held most often, for the smallest audience, under a title that says what it is. The label is
             * the whole-estate option's, which an AGM or an EGM still draws.
             */
            'defaults' => [
                'type' => Meeting::COMMITTEE,
                'title' => Meeting::TYPE_TITLES[Meeting::COMMITTEE],
                'audience_scope' => Meeting::COMMITTEE_ONLY,
                'audience_label' => 'Whole estate · all '.$phases->count().' phases',
                'quorum_percent' => $settings->meeting_quorum_percent,
                'quorum_label' => $settings->meeting_quorum_percent.'% of eligible households',
                'recording_enabled' => true,
                'recording_consent_notice' => true,
                'recording_label' => 'Enabled, with consent notice',
            ],
            'eligibleHouseholds' => $this->eligibleHouseholds($settings),

            // What a committee meeting's quorum is a head count of (M60 §C2): the form's readout follows the type.
            'committeeMembers' => Settings::committeeSize((string) tenant()?->getTenantKey()),

            /*
             * What the form has to honour, by type. Surfaced rather than left
             * implicit so the date field can refuse before the submit does — and
             * so the screen can say WHY, which is the part a secretary needs.
             */
            'notice' => [
                // Always. The periods are the estate's to set and the refusal
                // is not theirs to switch off (Q-010, ruled). Kept on the
                // payload as a constant rather than removed, so the screen keeps
                // one shape and a reader can see the rule is unconditional.
                'enforced' => true,
                'days' => [
                    Meeting::AGM => $settings->agm_notice_days,
                    Meeting::EGM => $settings->egm_notice_days,
                    Meeting::COMMITTEE => $settings->meeting_notice_days,
                    Meeting::PHASE => $settings->meeting_notice_days,
                ],
                // The ceiling beside the floor (GV-10): the latest day, and the sentence the server refuses in.
                'latest' => Meeting::latestDay()->toDateString(),
                'latest_line' => Meeting::latestLine(),
                'earliest' => [
                    Meeting::AGM => SiteTime::date()->addDays($settings->agm_notice_days)->toDateString(),
                    Meeting::EGM => SiteTime::date()->addDays($settings->egm_notice_days)->toDateString(),
                    Meeting::COMMITTEE => SiteTime::date()->addDays($settings->meeting_notice_days)->toDateString(),
                    Meeting::PHASE => SiteTime::date()->addDays($settings->meeting_notice_days)->toDateString(),
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Move a ballot one step, or refuse.
     *
     * SETS THE STAGE AND DOES NOT SAVE. Every caller has its own columns to
     * write alongside — a closing time, a snapshot, a certification — and two
     * saves where one would do is a window in which a ballot is half moved.
     */
    private function transition(Ballot $ballot, string $to): void
    {
        $this->assertCanMove($ballot, $to);

        $ballot->stage = $to;
    }

    /**
     * Whether a ballot may make that one step, refused in words when it may not.
     *
     * Asked on its own by the acts that have more to check before they move
     * anything, so a refusal about the stage comes first and the ballot in hand
     * is never left holding a stage it was not saved at.
     */
    private function assertCanMove(Ballot $ballot, string $to): void
    {
        $this->refuseIfCertified($ballot, 'moved on');

        $next = self::TRANSITIONS[$ballot->stage] ?? [];

        // A MOTION HAS NO NOMINATIONS STAGE (M62 A9): from its draft it goes straight to the vote, and never to nominations.
        if ($ballot->isResolution() && $ballot->stage === Ballot::DRAFT) {
            $next = [Ballot::VOTING_OPEN];
        }

        if (! in_array($to, $next, true)) {
            throw new DomainException(sprintf(
                '%s is at "%s" and cannot move to "%s". An election runs in one direction and skipping a '.
                'stage would leave a decision nobody made: %s.',
                $ballot->title,
                $ballot->stageLabel(),
                Ballot::STAGES[$to] ?? $to,
                $next === []
                    ? 'this is the end of it'
                    : 'the only step from here is "'.Ballot::STAGES[$next[0]].'"',
            ));
        }
    }

    /** The one sentence certification says to everything that comes after it. */
    private function refuseIfCertified(Ballot $ballot, string $verb): void
    {
        if (! $ballot->isCertified()) {
            return;
        }

        throw new DomainException(sprintf(
            '%s was certified on %s by %s and cannot be %s. Certification cannot be undone: a certified '.
            'result that turns out to be wrong is put right by running another ballot, not by reopening '.
            'this one.',
            $ballot->title,
            $ballot->certified_at === null ? 'an unrecorded date' : SiteTime::local($ballot->certified_at)->format('j M Y'),
            $ballot->certified_by_name ?? 'the returning officer',
            $verb,
        ));
    }

    /**
     * Check the paper before a single row is written.
     *
     * @param  list<int>  $optionIds
     * @return Collection<int, BallotOption>
     */
    private function validatePaper(Ballot $ballot, array $optionIds): mixed
    {
        $ids = array_values(array_unique(array_map('intval', $optionIds)));

        if ($ids === []) {
            throw new DomainException(
                'A blank paper is not a vote. If the household means to abstain, that is an option on the '.
                'paper and it is marked like any other. An empty submission would be indistinguishable '.
                'from a household that never voted, and turnout would be wrong.'
            );
        }

        $options = BallotOption::query()
            ->where('ballot_id', $ballot->id)
            ->whereIn('id', $ids)
            ->with('position')
            ->get();

        if ($options->count() !== count($ids)) {
            throw new DomainException(
                'This paper carries a choice that is not on '.$ballot->title.'. A mark against an option '.
                'from another ballot would be counted into the wrong result.'
            );
        }

        foreach ($options->groupBy('ballot_position_id') as $group) {
            $seats = $group->first()?->position->seat_count ?? 1;

            if ($group->count() > $seats) {
                throw new DomainException(sprintf(
                    '%s has %d seat%s and this paper marks %d candidates for it. Over-marking spoils a '.
                    'paper, and the household has to be told before it is cast rather than after.',
                    $group->first()?->position->displayName() ?? 'That position',
                    $seats,
                    $seats === 1 ? '' : 's',
                    $group->count(),
                ));
            }
        }

        return $options;
    }

    /** Write the decision and the snapshot behind it, in one go. */
    private function decide(Nomination $nomination, string $status, ?string $reason, User $by): Nomination
    {
        $unit = $nomination->unit;
        $check = $unit === null ? null : $this->eligibility($unit);

        $nomination->forceFill([
            'status' => $status,
            'decision_reason' => $reason,
            'decided_at' => now(),
            'decided_by' => $by->getKey(),
            'decided_by_name' => $by->name,
            'eligibility_checked_on' => $check['checked_on'] ?? null,
            'arrears_bucket_at_check' => $check['arrears_bucket'] ?? null,
            'arrears_minor_at_check' => $check['arrears_minor'] ?? null,
            'tenure_months_at_check' => $check['tenure_months'] ?? null,
        ])->save();

        return $nomination;
    }

    /**
     * Board 9's nine-step stepper, with each step's state.
     *
     * @return list<array{key: string, label: string, state: string}>
     */
    private function stepper(?Ballot $ballot): array
    {
        $current = $ballot?->stageSequence() ?? 1;
        $steps = [];
        $position = 0;

        foreach (Ballot::STAGES as $key => $label) {
            $position++;

            $steps[] = [
                'key' => $key,
                'label' => $label,
                'state' => match (true) {
                    $position < $current => 'done',
                    $position === $current => 'current',
                    default => 'upcoming',
                },
            ];
        }

        return $steps;
    }

    /**
     * Which of a year's ballots a screen speaks for.
     *
     * Board 9 draws ONE lifecycle badge over an election that may have two
     * papers, so something has to choose. The control room takes the LEAST
     * advanced — an election is not at "Voting Open" while one of its papers is
     * still taking nominations — and the results screen takes the most advanced,
     * because that is the one with a tally on it.
     *
     * @param  Collection<int, Ballot>  $ballots
     */
    private function leadBallot(mixed $ballots, bool $furthest = false): ?Ballot
    {
        if ($ballots->isEmpty()) {
            return null;
        }

        // A cancelled ballot speaks for the year only when every ballot of it is cancelled (M56 §3): it is not the
        // least advanced paper, it is a paper that is over.
        $live = $ballots->reject(static fn (Ballot $b): bool => $b->isCancelled());
        $ballots = $live->isEmpty() ? $ballots : $live;

        $sorted = $ballots->sortBy(static fn (Ballot $b): int => $b->stageSequence());

        return $furthest ? $sorted->last() : $sorted->first();
    }

    /**
     * A year's election papers, each with its stage, and the one a screen speaks for (Security's dev-server note,
     * 12 October). With two papers at different stages the control room drew one paper's stage and the results page
     * another's count, and neither said which; each now names the paper it shows and lists the rest.
     *
     * @param  Collection<int, Ballot>  $ballots
     * @return list<array{id: int, title: string, stage: string, shown: bool}>
     */
    private function papersOf(mixed $ballots, ?Ballot $shown): array
    {
        return $ballots->map(static fn (Ballot $ballot): array => [
            'id' => (int) $ballot->id,
            'title' => (string) $ballot->title,
            'stage' => (string) $ballot->stage,
            'shown' => $shown !== null && $ballot->is($shown),
        ])->values()->all();
    }

    /**
     * HOW MANY HOUSEHOLDS AN ELECTION COUNTS: the one number every reader of an election's households uses (M62 A9 and
     * B1: the confirmation before nominations open names it, the control room prints it, the turnout divides by it).
     * Once voting has opened, the denominator `open()` froze on the ballot; before that, the households on its roll
     * when it has one (M62 A9: the committee's ballot reads 7), else the estate's rule as it stands today, so nothing
     * names a count the rule has moved on from. This service is its only writer: a screen or a confirmation asks for it
     * and never counts for itself.
     */
    public function eligibleHouseholdCount(Ballot $ballot): int
    {
        if ($ballot->eligible_households > 0) {
            return $ballot->eligible_households;
        }

        return $ballot->hasRoll() ? count($ballot->rollUnitIds()) : $this->eligibleHouseholds();
    }

    /**
     * How many households may vote.
     *
     * THE HOUSEHOLDS THAT EXIST, BY DEFAULT (Hassam, 9 October 2026): counted by lot, Phoenix Park's 25% quorum is 500 of
     * 1,999 with about 443 households living there, never reachable and plain on the screen. An estate whose rule is
     * every lot, vacant ones included (boards 9 and 11 say 450 against 441 occupied: the entitlement attaching to the
     * property), says so in `voting_eligibility`; whether an absent owner votes is the client's to say (CONFIRM-01).
     * Either way only the estate's lots count (M60 §C2): a unit the site plan does not have is not one.
     */
    private function eligibleHouseholds(?EstateSetting $settings = null): int
    {
        return ($settings ?? EstateSetting::current())->voting_eligibility === EstateSetting::VOTES_BY_LOTS
            ? Unit::lots()->count()
            : $this->householdsOn(Unit::lots());
    }

    /**
     * The estate's households on these units (M87 §1.4: a household with a resident, on a lot; removed ones left out by
     * Household's own scope), the count the dashboard and the register say: 441 at Phoenix Park, never 444 or 2,000.
     *
     * @param  Builder<Unit>  $units
     */
    private function householdsOn(Builder $units): int
    {
        return Household::query()->counted()->whereIn('unit_id', $units->select('units.id'))->count();
    }

    /**
     * HOW MANY HOUSEHOLDS A PHASE'S OWN MEETING COUNTS (M69; Hassam, 10 October 2026): the households living on that
     * phase's lots, never the whole estate's. A Phase 2 resident cannot be bound by a threshold that counts Phase 4. By
     * the estate's own rule, as `eligibleHouseholds()` is: every lot of the phase when the estate counts lots.
     */
    private function phaseHouseholds(string $phase, ?EstateSetting $settings = null): int
    {
        $lots = Unit::lots()->where('block', $phase);

        return ($settings ?? EstateSetting::current())->voting_eligibility === EstateSetting::VOTES_BY_LOTS
            ? $lots->count()
            : $this->householdsOn($lots);
    }

    /**
     * WHAT A MEETING'S QUORUM COUNTS (M60 §C2; the M58 report: "Rule: 25% of 2000 eligible households, 500 needed" on a
     * committee meeting). A committee's own meeting is a head count of the committee as it stands, read as "6/7"
     * (board 36); a phase's meeting is a proportion of that phase's households (`phaseHouseholds()`, M69); any other
     * meeting a proportion of the estate's (`eligibleHouseholds()`). The households are snapshotted either way.
     *
     * @return array{quorum_basis: string, quorum_required_total: int|null, eligible_households: int}
     */
    private function quorumBasisFor(string $audience, ?string $phase = null, ?EstateSetting $settings = null): array
    {
        $committee = $audience === Meeting::COMMITTEE_ONLY;

        return [
            'quorum_basis' => $committee ? Meeting::MEMBERS : Meeting::HOUSEHOLDS,
            'quorum_required_total' => $committee ? Settings::committeeSize((string) tenant()?->getTenantKey()) : null,
            'eligible_households' => $audience === Meeting::PHASE_SUBSET && $phase !== null && $phase !== ''
                ? $this->phaseHouseholds($phase, $settings)
                : $this->eligibleHouseholds($settings),
        ];
    }

    /**
     * The sentence board 11 calls the outcome statement, when nobody writes one.
     *
     * Composed from the tally rather than left blank, because a certificate with
     * an empty outcome is a certificate of nothing. A returning officer who wants
     * their own words passes them in and this is never used.
     */
    private function outcomeStatement(Ballot $ballot): string
    {
        $turnout = $this->turnout($ballot);
        $quorum = $this->quorum($ballot);
        $tally = $this->tally($ballot);

        $winners = [];

        foreach ($ballot->positions()->with('options')->get() as $position) {
            $ranked = $position->options
                ->sortByDesc(static fn (BallotOption $option): int => $tally[$option->id] ?? 0)
                ->take($position->seat_count)
                ->filter(static fn (BallotOption $option): bool => ($tally[$option->id] ?? 0) > 0);

            foreach ($ranked as $option) {
                $winners[] = $option->label.' ('.$position->displayName().')';
            }
        }

        return sprintf(
            'Turnout %s. Quorum of %d households %s. Elected: %s.',
            $turnout['label'],
            $quorum['required'],
            $quorum['met'] ? 'met' : 'not met',
            $winners === [] ? 'no seat filled' : implode('; ', $winners),
        );
    }

    /**
     * ONE TIME RULE (M4 §3.1). What the office typed ("2026-09-27 20:00") is a wall-clock time on
     * the ESTATE'S clock and becomes the UTC moment it means; a moment stays the moment it is.
     */
    private function asMoment(Carbon|string $value): Carbon
    {
        return SiteTime::parse($value);
    }
}
