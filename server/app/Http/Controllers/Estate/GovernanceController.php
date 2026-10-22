<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingAttendance;
use App\Models\Estate\Nomination;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Services\Documents\Documents;
use App\Services\Estate\Governance;
use App\Services\Estate\Settings;
use App\Support\SiteTime;
use App\Support\SourceBadge;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * Elections, nominations, certification and meetings — boards 9, 10, 11, 12, 36.
 *
 * NOT ONE FIGURE ON THESE SCREENS IS STORED. The tallies are counted from
 * `ballot_marks`, turnout from `ballot_receipts`, quorum from
 * `meeting_attendance`, and the nomination counts from `nominations`. A stored
 * tally would agree with the marks by coincidence, and on a ballot paper
 * coincidence and fraud are indistinguishable — which is why `EstateGovernanceTest`
 * re-counts every one of them in raw SQL.
 *
 * THERE IS NO ROUTE THAT CASTS A VOTE, and there will not be one here. Voting is
 * a resident act in the resident app; the console runs the election and never
 * marks a paper. `Governance::castVote()` exists, is exercised by the test suite
 * and the seeder, and returns void — so even if a console route were added, there
 * would be nothing for it to hand back that could pair a household with a choice.
 *
 * CERTIFYING AND PUBLISHING NEED `approve`, NOT `update`. Both are irreversible:
 * a certified ballot cannot be reopened, and a published meeting has already told
 * 450 households a date they will arrange their Saturday around. The estate matrix
 * gives `Full · Approver` on Governance to the President and Vice President and
 * plain `Full` to the Secretary — so the officer who RUNS an election is
 * deliberately not the officer who declares it final. That is exactly what D-013
 * separated the verb for, and board 11's copy addressing the Returning Officer is
 * a description of who reviews the tally, not of who holds the permission.
 *
 * TWO APPROVALS SINCE M11 B3 (D-150): `certify_elections` and
 * `publish_meetings`, because deciding who governs and deciding when members
 * meet are not one power. The President and Vice President hold both.
 *
 * The gates live on the routes. A controller that checked them again would be a
 * second place to keep in step with the matrix.
 */
class GovernanceController extends Controller
{
    /** What an in-person meeting's venue reads when somebody chose to confirm it later (M68 §2.7, GV-6). */
    private const VENUE_TO_BE_CONFIRMED = 'To be confirmed';

    /** Election control room — board community-admin-09. */
    public function controlRoom(Request $request, int $year, Governance $governance): Response
    {
        $minuted = $governance->minutedMeetings($year);

        $room = $governance->controlRoom($year);

        /*
         * THE LEAD BALLOT, READ ONCE: for the households Open voting names (M62 B1), and with the names on its paper
         * counted in the same read (M70 §1.2, E2E-M7), as `Governance::open()` reads them: its candidates once settled,
         * else its approved nominations. None, and Open voting is drawn off with the sentence open() refuses it with,
         * word for word, instead of being pressed into the refusal.
         */
        $lead = ($room['ballotId'] ?? null) === null ? null : Ballot::query()
            ->withCount(['options', 'nominations as approved_count' => static fn ($query) => $query->where('status', Nomination::APPROVED)])
            ->findOrFail((int) $room['ballotId']);
        $onPaper = $lead === null ? null : (int) $lead->options_count + (int) $lead->getAttribute('approved_count');

        return inertia('Estate/Governance/ControlRoom', [
            'estate' => ['name' => (string) tenant()->name],
            ...$room,
            'onPaper' => $onPaper,
            'emptyPaperReason' => $onPaper === 0 && isset($room['ballot']['title'])
                ? 'Nobody is on the paper for '.$room['ballot']['title'].': no candidate has been approved for any of its seats. A poll opened now would give every household nothing to mark.'
                : null,

            // What Open nominations names before it acts (M62 B1, DR-008): this election's own count of households,
            // from the one method that counts it, so the page never counts for itself. None where there is no election.
            'eligibleHouseholds' => $lead === null ? null : $governance->eligibleHouseholdCount($lead),

            // What the "Voting closes" box starts at (M53 §4.11): a week from today, 6:00 PM on the estate's clock —
            // never "now", which would shut the poll the moment it opened.
            'defaultClosesAt' => SiteTime::now()->addDays(7)->setTime(18, 0)->format('Y-m-d\TH:i'),

            // Category B (13 C3): households vote from the Resident App (13 D3).
            'sourceBadge' => SourceBadge::resident(SourceBadge::anySimulatedIn('tenant', 'ballot_receipts', array_filter([$room['ballotId'] ?? null]), 'ballot_id')),
            'canRun' => $request->user()->can('estate.governance.update'),
            'canCertify' => $request->user()->can('estate.governance.certify_elections'),
            // On a committee motion's Publish results when this viewer cannot certify (M72 step 7): whose act it is.
            'certifyBlockedReason' => 'Publishing a result needs the Super Admin, the President or the Vice President.',
            // M56 §3: deleting a draft election is `delete`.
            'canDelete' => $request->user()->can('estate.governance.delete'),

            /*
             * CONFIGURING THE BALLOT — M10 §7.
             *
             * `canConfigure` is create-or-update AND draft. Both halves matter:
             * the permission decides who may, and the stage decides whether
             * anybody may at all. Once nominations open, somebody has stood for
             * a named seat, so the editor is not drawn rather than drawn and
             * refused — the service refuses it too, because a screen that does
             * not draw a control is not a guard rail.
             */
            'canConfigure' => ($request->user()->can('estate.governance.create')
                || $request->user()->can('estate.governance.update'))
                && ($room['ballotId'] ?? null) !== null
                && ($room['stage'] ?? null) === Ballot::DRAFT,

            /*
             * THE SPLIT THE ROUTES HOLD, said per control (B042). Adding a
             * position is `create`; renaming, editing, removing and reordering are
             * `update` — so a role holding one of the two is offered only what its
             * route will accept, rather than a control that answers 403.
             */
            'canAddPosition' => $request->user()->can('estate.governance.create'),
            'canEditPositions' => $request->user()->can('estate.governance.update'),

            // M56 §3: while nominations are open or paused a seat can still be added, and one nobody stood for removed.
            'positionsOpen' => ($room['ballotId'] ?? null) !== null
                && in_array($room['stage'] ?? null, [Ballot::DRAFT, Ballot::NOMINATIONS_OPEN, Ballot::NOMINATIONS_CLOSED], true),

            'configureBlockedReason' => match (true) {
                ($room['stage'] ?? null) === Ballot::DRAFT => 'Adding and editing positions needs Governance create or update access. You are able to read this screen.',
                in_array($room['stage'] ?? null, [Ballot::NOMINATIONS_OPEN, Ballot::NOMINATIONS_CLOSED], true) => 'Nominations are open, so a position can still be added, and one nobody has stood for removed. Renaming one or changing its seats waits for a draft: somebody may have stood for it.',
                default => 'This election is past nominations, so its positions are set: a seat added now would have nobody on it, and one removed would take a candidate.',
            },
            'blockedReason' => 'Running an election (opening nominations, closing them, opening and extending the poll) needs Governance update access. You are able to read this screen.',

            // Drafting an election (M10 §7, B042).
            'canCreate' => $request->user()->can('estate.governance.create'),
            'createReason' => 'Drafting an election needs Governance create access. You are able to read this screen.',

            /*
             * "Export minutes" (12 §2, item 33). A ballot records no meeting, so
             * this screen does not guess whose minutes: it offers the meetings
             * minuted in the election's year and the reader chooses. Each is
             * issued as the same queued, seven-year PDF the register issues.
             */
            'minutedMeetings' => $minuted,
            'reasons' => [
                'minutes' => $minuted === []
                    ? 'No meeting held in '.$year.' has minutes recorded yet. Minutes are issued once a meeting has been held and minuted.'
                    : null,
            ],
        ]);
    }

    /** Nominations review — board community-admin-10. */
    public function nominations(Request $request, int $year, Governance $governance): Response
    {
        return inertia('Estate/Governance/Nominations', [
            'estate' => ['name' => (string) tenant()->name],
            // A page at a time, in the order its headings ask for (M32 F5).
            ...$governance->nominationsBoard(
                $year,
                $request->string('position')->toString(),
                Governance::nominationsTable($request),
                // M56 §3.4: Open voting's "4 nominations still need a decision" links here, to those four.
                undecidedOnly: $request->string('status')->toString() === 'undecided',
            ),
            'canDecide' => $request->user()->can('estate.governance.update'),
            'blockedReason' => 'Vetting a candidate decides who may stand for office and needs Governance update access. You are able to read this screen.',
            // M56 §3.3: adding a candidate is `create`, as adding a position is.
            'canAdd' => $request->user()->can('estate.governance.create'),
        ]);
    }

    /**
     * One nomination and the record behind its decision — board 10's "View"
     * (12 §2, Wave 4). The arrears figure is shown only to a viewer who could
     * read it on the ledger; see `Governance::nominationDetail()`.
     */
    public function nomination(Request $request, int $nomination, Governance $governance): Response
    {
        $detail = $governance->nominationDetail($nomination, $request->user()->can('estate.dues_ledger.view'));

        abort_if($detail === null, 404);

        return inertia('Estate/Governance/Nomination', [
            'estate' => ['name' => (string) tenant()->name],
            'nomination' => $detail,
        ]);
    }

    /** Results & certification — board community-admin-11. */
    public function results(Request $request, int $year, Governance $governance): Response
    {
        $results = $governance->resultsBoard($year);

        // NO COUNT BEFORE THE CLOSE (Hassam, 11 Oct): the board sends none while households are still voting, and this
        // page is never handed one whatever the board sends. Turnout only until the poll has closed.
        if (($results['count_closed'] ?? false) !== true) {
            $results['cards'] = [];
        }

        return inertia('Estate/Governance/Results', [
            'estate' => ['name' => (string) tenant()->name],
            ...$results,
            'sourceBadge' => SourceBadge::resident(SourceBadge::anySimulatedIn('tenant', 'ballot_receipts', array_filter([$results['ballot']['id'] ?? null]), 'ballot_id')),
            'canCertify' => $request->user()->can('estate.governance.certify_elections'),

            /*
             * The sentence a Secretary sees where board 11 draws the certify
             * button. It is not a permission error — they hold Full on
             * Governance — so it says whose act it is rather than that they are
             * not allowed to be here.
             */
            'blockedReason' => 'Certifying needs the Super Admin, the President or the Vice President. You can run the election and read the tally.',
        ]);
    }

    /** The meeting register — board community-admin-36. */
    public function meetings(Request $request, Governance $governance): Response
    {
        return inertia('Estate/Governance/Meetings', [
            'estate' => ['name' => (string) tenant()->name],
            // M32 F5: board 36's own order unless the headings ask otherwise, a page at a time, with its search.
            ...$governance->meetingsBoard(Governance::meetingsTable($request)),
            'canSchedule' => $request->user()->can('estate.governance.create'),

            /*
             * PUBLISHING IS DONE FROM THE REGISTER AND NOWHERE ELSE. Board 12
             * saves a draft, and a meeting that does not exist yet has nothing
             * to publish — so the act belongs on the one screen where the draft
             * can be seen alongside the meetings already announced. An approval,
             * `publish_meetings`, because publication tells 450 households a date.
             */
            'canPublish' => $request->user()->can('estate.governance.publish_meetings'),
            'publishReason' => 'Publishing needs the Super Admin, the President or the Vice President. You can draft and read meetings.',

            // The row menus (M56 §4) offer only what this person may do: the meeting page's own split.
            'canEdit' => $request->user()->can('estate.governance.update'),
            'canDelete' => $request->user()->can('estate.governance.delete'),
        ]);
    }

    /**
     * One meeting, whole — the detail behind a board 36 row (12 §2, Wave 2
     * item 21). The papers issued about it are listed with their retention, so
     * "was the agenda sent?" has an answer on the same page.
     */
    public function meeting(Request $request, int $meeting, Governance $governance, Documents $documents): Response
    {
        $detail = $governance->meetingDetail($meeting);

        abort_if($detail === null, 404);

        return inertia('Estate/Governance/Meeting', [
            'estate' => ['name' => (string) tenant()->name],
            'meeting' => $detail,
            'documents' => $documents->forSubject('meeting', (string) $meeting),
            // M21 §1.4: writing the minutes is Governance update; putting them before the households is publishing.
            'canMinute' => $request->user()->can('estate.governance.update'),
            'minuteReason' => 'Writing a meeting\'s minutes needs Governance update access. You can read them.',
            // M56 §2: editing, keeping the register and archiving are update; deleting a draft is delete.
            'canEdit' => $request->user()->can('estate.governance.update'),
            'canDelete' => $request->user()->can('estate.governance.delete'),
            'canPublish' => $request->user()->can('estate.governance.publish_meetings'),
            'publishReason' => 'Publishing needs the Super Admin, the President or the Vice President. You can draft and read meetings.',

            // Who Publish reaches, as its confirmation names it (M62 B1, M65 §3): a committee's own meeting resolves to the
            // committee's head count, the server's own (`Settings::committeeSize`); every other meeting to
            // `households_told` above. Counted only for a committee meeting, so no other meeting's page pays for it.
            'publishReach' => $this->publishReach($detail),
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{committee: bool, members: int|null}
     */
    private function publishReach(array $detail): array
    {
        // The meeting's own label for a committee audience, asked of the model rather than typed here a second time.
        $committee = ($detail['audience'] ?? null) === (new Meeting)->forceFill(['audience_scope' => Meeting::COMMITTEE_ONLY])->audienceLabel();

        return ['committee' => $committee, 'members' => $committee ? Settings::committeeSize((string) tenant()->getTenantKey()) : null];
    }

    /** Schedule a meeting — board community-admin-12. */
    public function newMeeting(Request $request, Governance $governance): Response
    {
        return inertia('Estate/Governance/MeetingScheduler', [
            'estate' => ['name' => (string) tenant()->name],
            ...$governance->schedulerDefaults(),
            'canSchedule' => $request->user()->can('estate.governance.create'),
            'canPublish' => $request->user()->can('estate.governance.publish_meetings'),
            'blockedReason' => 'Scheduling a meeting needs Governance create access. You are able to read this screen.',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /**
     * Open nominations on a drafted ballot — board 9's "Open nominations" (B042).
     *
     * The window's close is required here and refused in the past by the
     * service, on the estate's clock: it is what households are told, and what
     * board 9 counts down to.
     */
    public function openNominations(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'closes_at' => ['required', 'date'],
        ]);

        try {
            $governance->openNominations($ballot, $data['closes_at']);
        } catch (DomainException $refused) {
            // Under the field the officer is looking at, as the poll's own dates are.
            return back()->withErrors(['closes_at' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year))
            ->with('success', sprintf(
                'Nominations are open on %s until %s. Its positions are now fixed.',
                $ballot->title,
                $ballot->nominations_close_at === null ? 'the close you set' : SiteTime::fmt(SiteTime::local($ballot->nominations_close_at), 'M j, Y g:i A'),
            ));
    }

    /** Hand a closed nomination window to vetting — board 9's "Start candidate vetting" (B042). */
    public function startVetting(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        try {
            $governance->startVetting($ballot);
        } catch (DomainException $refused) {
            return back()->withErrors(['stage' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year))
            ->with('success', $ballot->title.' is in candidate vetting. Decide each nomination on the nominations screen, then open the poll.');
    }

    /** Close nominations — board 9's "Close nominations early". */
    public function closeNominations(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        try {
            $governance->closeNominations($ballot);
        } catch (DomainException $refused) {
            return back()->withErrors(['stage' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year))
            ->with('success', 'Nominations closed for '.$ballot->title.'.');
    }

    /** Open the poll. */
    public function openVoting(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'closes_at' => ['required', 'date'],
        ]);

        try {
            $governance->open($ballot, $data['closes_at']);
        } catch (DomainException $refused) {
            return back()->withErrors(['closes_at' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year))
            ->with('success', 'Voting is open on '.$ballot->title.'.');
    }

    /** Shut the poll and move to the tally. */
    public function closeVoting(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        try {
            $governance->close($ballot);
        } catch (DomainException $refused) {
            return back()->withErrors(['stage' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year.'/results'))
            ->with('success', 'Voting closed. The tally is ready to review.');
    }

    /** Give the poll longer — never less. */
    public function extendVoting(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'closes_at' => ['required', 'date'],
        ]);

        try {
            $governance->extend($ballot, $data['closes_at']);
        } catch (DomainException $refused) {
            return back()->withErrors(['closes_at' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year))
            ->with('success', 'Voting on '.$ballot->title.' now closes '.($ballot->closes_at === null ? 'when you close it' : SiteTime::fmt(SiteTime::local($ballot->closes_at), 'M j, Y g:i A')).'.');
    }

    /**
     * Certify, and publish in the same request — board 11's one button.
     *
     * Two acts underneath, and the order matters: certification is what cannot
     * be undone, publication is what reaches residents. If publication ever
     * needs to be held back, the service already separates them and only this
     * method has to change.
     */
    public function certify(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $governance->certify($ballot, $request->user(), $data['outcome'] ?? null);
            $governance->publish($ballot);
        } catch (DomainException $refused) {
            return back()->withErrors(['outcome' => $refused->getMessage()])->withInput();
        }

        /*
         * A COMMITTEE MOTION IS PUBLISHED FROM ITS OWN CARD (M70 §1.2, M72 step 7), and only the households on its roll
         * are told (`PushAnnouncer::electionResults`). So it comes back to the card, on its year's page, and the line
         * names the roll: the results page shows the year's lead ballot, and "estate-wide" was not true of a motion.
         */
        if ($ballot->isResolution()) {
            $roll = $governance->eligibleHouseholdCount($ballot);

            return redirect()
                ->to($this->path('/governance/elections/'.$ballot->year))
                ->with('success', $ballot->title.': the result is certified and published to the '.$roll.' '.($roll === 1 ? 'household' : 'households').' on its roll.');
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year.'/results'))
            ->with('success', $ballot->title.' certified and published estate-wide.');
    }

    /** Accept a candidate onto the paper. */
    public function acceptNomination(Request $request, Nomination $nomination, Governance $governance): RedirectResponse
    {
        try {
            $governance->accept($nomination, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['nomination' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$nomination->ballot->year.'/nominations'))
            ->with('success', $nomination->candidate_name.' approved.');
    }

    /**
     * Refuse one, with a reason.
     *
     * The reason is `required` in the validator as well as in the service. The
     * service is the guarantee — it is the one door — and the validator is what
     * puts the message under the field the officer is looking at.
     */
    public function rejectNomination(Request $request, Nomination $nomination, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ]);

        try {
            $governance->reject($nomination, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$nomination->ballot->year.'/nominations'))
            ->with('success', $nomination->candidate_name.' rejected: '.$data['reason'].'.');
    }

    /** Ask for more before deciding either way. */
    public function queryNomination(Request $request, Nomination $nomination, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ]);

        try {
            $governance->requestInformation($nomination, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$nomination->ballot->year.'/nominations'))
            ->with('success', 'More information requested from '.$nomination->candidate_name.'.');
    }

    /* ------------------------------------------------------------------ */
    /* configuring an election — M10 §7 */
    /* ------------------------------------------------------------------ */

    /**
     * `/governance/elections` is a parent address (FAC-17). It held only the POST that starts an election, so a GET
     * answered a raw 405; it now goes to the election the Elections tab opens.
     */
    public function electionsIndex(Governance $governance): RedirectResponse
    {
        return redirect()->to($this->path('/governance/elections/'.$governance->electionYear()));
    }

    /**
     * Start an election, in draft.
     *
     * The year is the key an election is addressed by — `/governance/elections/
     * 2026` — so a year holds one. This docblock used to say the database's own
     * index refused a second; it did not (the index is on the year and a letter
     * nothing set), and a second election for a year took its control room over
     * (B043). The service refuses it now, and letters the ballot so the index
     * does back it. The refusal is about the year, so it is said under the year.
     */
    public function storeElection(Request $request, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $ballot = $governance->createElection(
                (int) $data['year'],
                (string) $data['title'],
                $data['description'] ?? null,
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['year' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year))
            ->with('success', $ballot->title.' created. Add its positions before opening nominations.');
    }

    /** Rename an election, or change the description a household reads. */
    public function renameElection(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $governance->renameElection(
                $ballot,
                (string) $data['title'],
                $data['description'] ?? null,
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['title' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', 'Election updated.');
    }

    /**
     * Add a position by typing its title — M10 §7's headline.
     *
     * Any title the estate uses. Nothing here is chosen from a list, because
     * there is no list: the seeded Chairman and Vice Chairman are rows, not
     * an enum, and always were.
     */
    public function storePosition(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'seats' => ['required', 'integer', 'min:1', 'max:50'],
            'scope' => ['required', 'string', 'in:'.BallotPosition::ESTATE.','.BallotPosition::PHASE],
            'phase' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $governance->addPosition(
                $ballot,
                (string) $data['name'],
                (int) $data['seats'],
                (string) $data['scope'],
                $data['phase'] ?? null,
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $data['name'].' added.');
    }

    /** Retitle a position, or change how many seats it carries. */
    public function updatePosition(Request $request, int $position, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'seats' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $governance->updatePosition(
                $this->positionOnThisEstate($position),
                (string) $data['name'],
                (int) $data['seats'],
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()]);
        }

        return back()->with('success', 'Position updated.');
    }

    /** Take a position off a draft ballot. */
    public function removePosition(int $position, Governance $governance): RedirectResponse
    {
        try {
            $governance->removePosition($this->positionOnThisEstate($position));
        } catch (DomainException $refused) {
            return back()->withErrors(['position' => $refused->getMessage()]);
        }

        return back()->with('success', 'Position removed.');
    }

    /** Put the positions in the order the ballot paper should read. */
    public function reorderPositions(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $governance->reorderPositions($ballot, array_map('intval', $data['order']));
        } catch (DomainException $refused) {
            return back()->withErrors(['order' => $refused->getMessage()]);
        }

        return back()->with('success', 'Order saved.');
    }

    /*
     * THE WRITES ARE ADDRESSED TO A BALLOT, NOT TO A YEAR (B043). A year may hold
     * two papers, and "the election for 2026" used to resolve to the oldest of
     * them while board 9 drew — and offered to configure — the least advanced.
     * The screen now posts to the ballot it is showing. Tenancy has already put
     * the request inside one estate's database, so a ballot bound here belongs
     * to this estate by construction: the other estate's are in another database.
     */

    /**
     * One position, on this estate.
     *
     * Tenancy has already put this request inside one estate's database, so a
     * position found here belongs to this estate by construction — there is no
     * cross-estate id to guard against.
     */
    private function positionOnThisEstate(int $position): BallotPosition
    {
        return BallotPosition::query()
            ->with('ballot')
            ->whereKey($position)
            ->firstOr(static fn () => abort(404));
    }

    /**
     * Draft a meeting — board 12's form. Publishing it is a separate act.
     *
     * TWO RULES THE FORM NOW KEEPS (M56): the type decides the audience (§2.5: an AGM is the whole estate, a phase
     * meeting one phase), and the date is refused inside the type's notice period, with the earliest allowed date
     * (§2.2), rather than accepted here and refused at Publish.
     */
    public function storeMeeting(Request $request, Governance $governance): RedirectResponse
    {
        $data = $this->validateMeeting($request);

        if (($refusal = $governance->noticeRefusal($data['type'], Carbon::parse($data['starts_at'], SiteTime::zone())->utc())) !== null) {
            return back()->withErrors(['starts_at' => $refusal])->withInput();
        }

        $meeting = $governance->scheduleMeeting(
            attributes: [
                'type' => $data['type'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'starts_at' => $data['starts_at'],
                'duration_minutes' => (int) ($data['duration_minutes'] ?? Meeting::DEFAULT_DURATION_MINUTES),
                'audience_scope' => Meeting::audienceFor($data['type']),
                'phase' => $data['type'] === Meeting::PHASE ? $data['phase'] : null,
                ...$this->placeOf($data),
                'recording_enabled' => (bool) ($data['recording_enabled'] ?? false),
                'recording_consent_notice' => (bool) ($data['recording_consent_notice'] ?? false),
            ],
            agenda: array_values($data['agenda'] ?? []),
            by: $request->user(),
        );

        return redirect()
            ->to($this->path('/governance/meetings'))
            ->with('success', $meeting->title.' saved as a draft. Publish it to notify households.');
    }

    /**
     * Edit a meeting (M56 §2): a draft entirely; a published or postponed one only its venue, link, description and
     * agenda, since its audience may not change once notice has gone out and its date moves only by "Change the date".
     */
    public function updateMeeting(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        try {
            if ($meeting->stage() === Meeting::STAGE_DRAFT) {
                $data = $this->validateMeeting($request);

                if (($refusal = $governance->noticeRefusal($data['type'], Carbon::parse($data['starts_at'], SiteTime::zone())->utc())) !== null) {
                    return back()->withErrors(['starts_at' => $refusal])->withInput();
                }

                $governance->editDraft($meeting, [
                    'type' => $data['type'],
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'starts_at' => $data['starts_at'],
                    'duration_minutes' => (int) ($data['duration_minutes'] ?? Meeting::DEFAULT_DURATION_MINUTES),
                    'phase' => $data['type'] === Meeting::PHASE ? $data['phase'] : null,
                    ...$this->placeOf($data),
                    'recording_enabled' => (bool) ($data['recording_enabled'] ?? false),
                    'recording_consent_notice' => (bool) ($data['recording_consent_notice'] ?? false),
                ], array_values($data['agenda'] ?? []), $request->user());
            } else {
                $data = $request->validate([...$this->placeRules($request), 'description' => ['nullable', 'string', 'max:2000'], ...$this->agendaRules()]);

                $governance->editScheduled($meeting, $this->placeOf($data), $data['description'] ?? null, array_key_exists('agenda', $data) ? array_values($data['agenda'] ?? []) : null, $request->user());
            }
        } catch (DomainException $refused) {
            return back()->withErrors(['meeting' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $meeting->title.' saved.');
    }

    /** "Move to 15 Oct" (M56): a draft inside its notice period, moved to the earliest day it may have, at its own time. */
    public function moveMeetingToEarliest(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        try {
            if ($meeting->stage() !== Meeting::STAGE_DRAFT) {
                throw new DomainException($meeting->title.' has been published. Change its date from the meeting\'s page, which tells the households.');
            }

            $governance->editDraft($meeting, ['starts_at' => $governance->earliestKeepingTime($meeting)], null, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['meeting' => $refused->getMessage()]);
        }

        return back()->with('success', $meeting->title.' moved to '.SiteTime::local($meeting->fresh()->starts_at)->format('D j M, g:i A').'.');
    }

    /** Delete a draft, confirmed by typing its name (M56 §2). A published meeting is cancelled instead. */
    public function deleteMeeting(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        $data = $request->validate(['confirm_name' => ['required', 'string', 'max:160']], ['confirm_name.required' => 'Type the meeting\'s name to confirm.']);

        try {
            $governance->deleteDraft($meeting, (string) $data['confirm_name'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['confirm_name' => $refused->getMessage()]);
        }

        return redirect()->to($this->path('/governance/meetings'))->with('success', $meeting->title.' deleted. It was a draft, so nobody had been told.');
    }

    /** A new date for a published or postponed meeting (M56 §2.2): the notice restarts and replies reset. */
    public function rescheduleMeeting(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        $data = $request->validate(['starts_at' => ['required', 'date']], ['starts_at.required' => 'Choose the new date and time.']);

        try {
            $governance->reschedule($meeting, Carbon::parse($data['starts_at'], SiteTime::zone())->utc(), $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['starts_at' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $meeting->title.' moved to '.SiteTime::local($meeting->fresh()->starts_at)->format('D j M, g:i A').'. Households are told, and their replies start again.');
    }

    /** Postpone (M56 §2.3), with the reason households are told, and its new date when the office has one (M94 §3.3). */
    public function postponeMeeting(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'starts_at' => ['nullable', 'date'],
        ], ['reason.required' => 'Say why. Households are told.', 'reason.min' => 'Say why. Households are told.']);

        $to = ($data['starts_at'] ?? null) === null ? null : Carbon::parse($data['starts_at'], SiteTime::zone())->utc();

        try {
            $governance->postpone($meeting, (string) $data['reason'], $request->user(), $to);
        } catch (DomainException $refused) {
            return back()->withErrors([$to === null ? 'reason' : 'starts_at' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $to === null
            ? $meeting->title.' postponed. Households are told a new date will follow.'
            : $meeting->title.' postponed to '.SiteTime::local($meeting->fresh()->starts_at)->format('D j M, g:i A').'. Households are told, and their replies start again.');
    }

    /** Cancel (M56 §1.2), with a reason, confirmed by typing the name. It stays on the record, marked. */
    public function cancelMeeting(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'confirm_name' => ['required', 'string', 'max:160'],
        ], ['reason.required' => 'Say why. Households are told.', 'confirm_name.required' => 'Type the meeting\'s name to confirm.']);

        try {
            $governance->cancelMeeting($meeting, (string) $data['reason'], (string) $data['confirm_name'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['confirm_name' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $meeting->title.' cancelled. Households are told why, and it stays on the register, marked.');
    }

    /** Archive a meeting whose minutes are published (M56 §2). */
    public function archiveMeeting(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        try {
            $governance->archiveMeeting($meeting, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['meeting' => $refused->getMessage()]);
        }

        return back()->with('success', $meeting->title.' archived.');
    }

    /** One line of the register (M56 §2): a lot, or a committee member by name, and whether they came. */
    public function recordAttendance(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'lot' => ['nullable', 'string', 'max:40'],
            'name' => ['nullable', 'string', 'max:160'],
            'represented_by' => ['nullable', 'string', 'max:160'],
            'state' => ['required', 'string', 'in:present,apologies,absent'],
        ]);

        try {
            $unit = trim((string) ($data['lot'] ?? '')) === '' ? null : Unit::query()->where('reference', trim((string) $data['lot']))->first();

            if (trim((string) ($data['lot'] ?? '')) !== '' && $unit === null) {
                throw new DomainException('There is no lot "'.trim((string) $data['lot']).'" on this estate.');
            }

            $governance->recordAttendance($meeting, $unit, $data['name'] ?? null, $data['represented_by'] ?? null, (string) $data['state'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['attendance' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', 'Recorded on the register.');
    }

    /** Take a line off the register while it is still kept. */
    public function removeAttendance(Request $request, Meeting $meeting, int $attendance, Governance $governance): RedirectResponse
    {
        $row = MeetingAttendance::query()->where('meeting_id', $meeting->id)->whereKey($attendance)->firstOr(static fn () => abort(404));

        try {
            $governance->removeAttendance($row, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['attendance' => $refused->getMessage()]);
        }

        return back()->with('success', 'Taken off the register.');
    }

    /**
     * ADD A CANDIDATE (M56 §3.3): a nomination lodged by the office, for a seat on the ballot, proposed and seconded by
     * residents. Pending until it is decided on this same screen.
     */
    public function storeNomination(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'position_id' => ['required', 'integer'],
            'candidate_id' => ['required', 'integer'],
            'nominator_id' => ['required', 'integer'],
            'seconder_id' => ['required', 'integer'],
            'statement' => ['nullable', 'string', 'max:1000'],
        ], [
            'candidate_id.required' => 'Choose the candidate from the residents.',
            'nominator_id.required' => 'Choose who proposes them.',
            'seconder_id.required' => 'Choose who seconds them.',
            'position_id.required' => 'Choose the position.',
        ]);

        $position = BallotPosition::query()->where('ballot_id', $ballot->id)->whereKey($data['position_id'])->first();
        $person = static fn (int $id): ?Resident => Resident::query()->with('household')->find($id);

        try {
            if ($position === null) {
                throw new DomainException('That position is not on '.$ballot->title.'.');
            }

            [$candidate, $nominator, $seconder] = [$person((int) $data['candidate_id']), $person((int) $data['nominator_id']), $person((int) $data['seconder_id'])];

            if ($candidate === null || $nominator === null || $seconder === null) {
                throw new DomainException('Choose each person from the residents list.');
            }

            $nomination = $governance->addNomination($position, $candidate, $nominator, $seconder, $data['statement'] ?? null, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['nomination' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $nomination->candidate_name.' added for '.$position->displayName().'. Decide the nomination below.');
    }

    /* ---- an election's life (M56 §3): each act refuses what the ballot's stage does not allow ---- */

    /** Delete a draft election nobody has been nominated in, confirmed by typing its name. */
    public function deleteElection(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate(['confirm_name' => ['required', 'string', 'max:160']], ['confirm_name.required' => 'Type the election\'s name to confirm.']);

        try {
            $governance->deleteDraftElection($ballot, (string) $data['confirm_name'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['election' => $refused->getMessage()]);
        }

        return redirect()->to($this->path('/governance/elections/'.$ballot->year))->with('success', $ballot->title.' deleted. It was a draft, so nobody had stood.');
    }

    /** Move the nominations' close later (never earlier). */
    public function extendNominations(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        return $this->electionAct($request, fn (array $d) => $governance->extendNominations($ballot, Carbon::parse($d['closes_at'], SiteTime::zone())->utc(), $request->user()), ['closes_at' => ['required', 'date']], 'Nominations now close later.');
    }

    /** Reopen paused nominations, with when they now close. */
    public function reopenNominations(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        return $this->electionAct($request, fn (array $d) => $governance->reopenNominations($ballot, Carbon::parse($d['closes_at'], SiteTime::zone())->utc(), $request->user()), ['closes_at' => ['required', 'date']], 'Nominations are open again.');
    }

    /** Cancel the election: a reason, the name typed, every ballot kept. */
    public function cancelElection(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        return $this->electionAct($request, fn (array $d) => $governance->cancelElection($ballot, (string) $d['reason'], (string) $d['confirm_name'], $request->user()), ['reason' => ['required', 'string', 'max:500'], 'confirm_name' => ['required', 'string', 'max:160']], $ballot->title.' cancelled. Every ballot cast is kept and counted for nothing.');
    }

    /** Reopen a closed poll before certification, with a reason and a new close. */
    public function reopenVoting(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        return $this->electionAct($request, fn (array $d) => $governance->reopenVoting($ballot, Carbon::parse($d['closes_at'], SiteTime::zone())->utc(), (string) $d['reason'], $request->user()), ['closes_at' => ['required', 'date'], 'reason' => ['required', 'string', 'max:500']], 'Voting is open again. Households are told.');
    }

    /** Archive an election whose result is published. */
    public function archiveElection(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        return $this->electionAct($request, fn () => $governance->archiveElection($ballot, $request->user()), [], $ballot->title.' archived.');
    }

    /** Record a formal challenge against a published result. */
    public function challengeElection(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        return $this->electionAct($request, fn (array $d) => $governance->challengeResult($ballot, (string) $d['grounds'], $request->user()), ['grounds' => ['required', 'string', 'max:2000']], 'The challenge is recorded against the result.');
    }

    /**
     * One election act: validate, run, and say the refusal under `election` if it is refused.
     *
     * @param  array<string, list<string>>  $rules  the act's validation rules, by field; none for an act that takes no input
     */
    private function electionAct(Request $request, \Closure $act, array $rules, string $done): RedirectResponse
    {
        $data = $rules === [] ? [] : $request->validate($rules);

        try {
            $act($data);
        } catch (DomainException $refused) {
            return back()->withErrors(['election' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $done);
    }

    /** The residents a candidate, a proposer or a seconder may be, by name or lot (M56 §3.3). JSON for the picker. */
    public function residentSearch(Request $request, Governance $governance): JsonResponse
    {
        return response()->json(['items' => $governance->residentsMatching($request->string('q')->toString())]);
    }

    /** @return array<string, mixed> */
    private function validateMeeting(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'string', 'in:agm,egm,committee,phase'],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Up to eighteen months ahead, on the estate's calendar (GV-10): 2030 was taken.
            'starts_at' => ['required', 'date', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (strtotime((string) $value) === false) {
                    return;
                }

                if (SiteTime::local(SiteTime::parse((string) $value))->startOfDay()->greaterThan(Meeting::latestDay())) {
                    $fail(Meeting::latestLine());
                }
            }],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:720'],
            // A phase meeting names its phase; nothing else does (M56 §2.5).
            'phase' => ['nullable', 'string', 'max:32', Rule::requiredIf(fn (): bool => $request->input('type') === Meeting::PHASE)],
            ...$this->placeRules($request),
            'recording_enabled' => ['boolean'],
            'recording_consent_notice' => ['boolean'],
            ...$this->agendaRules(),
        ], ['phase.required' => 'Say which phase the meeting is for.']);
    }

    /** @return array<string, mixed> */
    private function agendaRules(): array
    {
        return [
            'agenda' => ['array'],
            'agenda.*.text' => ['required', 'string', 'max:200'],
            'agenda.*.start_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{held_as: string, platform_name: string|null, venue: string|null, virtual_link: string|null}
     */
    private function placeOf(array $data): array
    {
        // "To be confirmed" only when it was chosen, on an in-person meeting with no venue typed (M68 §2.7, GV-6).
        $toBeConfirmed = $data['held_as'] === Meeting::IN_PERSON && ($data['venue_tbc'] ?? false) && trim((string) ($data['venue'] ?? '')) === '';

        return [
            'held_as' => $data['held_as'],
            'platform_name' => $data['held_as'] === Meeting::OTHER_ONLINE && trim((string) ($data['platform_name'] ?? '')) !== '' ? trim((string) $data['platform_name']) : null,
            'venue' => $toBeConfirmed ? self::VENUE_TO_BE_CONFIRMED : ($data['venue'] ?? null),
            // Kept only where it means something: an in-person meeting leaves no link behind for the night.
            'virtual_link' => $data['held_as'] === Meeting::IN_PERSON ? null : ($data['virtual_link'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function placeRules(Request $request): array
    {
        return [

            /*
             * HOW IT IS HELD, AND THE TWO FIELDS THAT ANSWER TO IT (M10 §8).
             *
             * `venue` and `virtual_link` have been on the table since it was
             * created and board 12's form drew neither, so a household could be
             * told a date and never a place.
             *
             * The rules are asymmetric on purpose. An in-person meeting REQUIRES
             * a venue: a notice telling somebody to attend without saying where
             * is worse than no notice, because they will arrive somewhere. An
             * online meeting requires a link for the same reason. But an online
             * meeting may ALSO carry a venue — that is a hybrid, a real and
             * common arrangement — so the venue is permitted rather than
             * refused, and `Meeting::isHybrid()` reads it back.
             *
             * The link is validated as a URL and confined to http/https. A
             * joining link is put in front of every household in the estate, and
             * a `javascript:` or `data:` scheme in that position is a script
             * somebody clicks from a notice they trust.
             */
            'held_as' => ['required', 'string', 'in:'.implode(',', array_keys(Meeting::HELD_AS))],
            // "Other online" names its platform (26 Sep): Webex, Jitsi… Optional; read only for `other`.
            'platform_name' => ['nullable', 'string', 'max:60'],
            /*
             * OR "TO BE CONFIRMED", CHOSEN (M68 §2.7, GV-6). The AGM went out with "No venue recorded": the seed wrote
             * it with none. An in-person meeting may be saved without its venue only when somebody ticks that the venue
             * is to be confirmed, and the meeting then says so to every household, rather than saying nothing.
             */
            'venue_tbc' => ['nullable', 'boolean'],
            'venue' => ['nullable', 'string', 'max:190', Rule::requiredIf(fn (): bool => $request->input('held_as') === Meeting::IN_PERSON && ! $request->boolean('venue_tbc'))],
            'virtual_link' => [
                'nullable',
                'string',
                'max:255',
                'url:http,https',
                Rule::requiredIf(fn (): bool => $request->input('held_as') !== Meeting::IN_PERSON),
            ],
        ];
    }

    /**
     * Publish it — refused if it is inside the statutory notice period.
     *
     * `approve`, because publication is what tells 450 households a date they
     * will arrange their Saturday around, and because a meeting convened inside
     * its notice period can be challenged along with every decision taken at it.
     */
    public function publishMeeting(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        try {
            $governance->publishMeeting($meeting, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['starts_at' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/governance/meetings'))
            ->with('success', $meeting->title.' published to '.strtolower($meeting->audienceLabel()).'.');
    }

    /** Write or correct the minutes of a meeting that has been held (M21 §1.4). */
    public function saveMinutes(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        try {
            $governance->recordMinutes($meeting, (string) $data['body'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['minutes' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', 'Minutes saved for '.$meeting->title.'. They are not in front of the households until they are published.');
    }

    /** Put the minutes in front of the meeting's households, in the Resident App (M21 §1.4). */
    public function publishMinutes(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        try {
            $governance->publishMinutes($meeting, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['minutes' => $refused->getMessage()]);
        }

        return back()->with('success', 'The minutes of '.$meeting->title.' are published: '.strtolower($meeting->audienceLabel()).' can read them in the Resident App now.');
    }

    /**
     * Where a screen lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
