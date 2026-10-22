<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\Ledger;
use App\Services\Estate\ReportPeriodRefused;
use App\Services\Estate\Reports;
use App\Services\Exports\Exporter;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports — board screen community-admin-29.
 *
 * A catalogue: seven cards in three groups, each naming a report and offering
 * to generate it. The board draws no output, no parameters and no history —
 * this screen is the index, and each report is its own screen behind it.
 *
 * SIX OF THE SEVEN RUN (12 §1). The ruling asked for "all four report
 * generators behind board 30" — the four whose data this estate already holds.
 * The minutes archive joined them when documents shipped, and the incident log
 * when it was plain that the estate could read its own incidents from Gemini's
 * central log (D-035).
 *
 * EACH ANSWERS TO THE MODULE IT READS (B137). Reports export decides who may
 * take a report away; `Reports::refusalFor()` decides who may read what it
 * reads — the ledger and Accounting for the two money reports, Facilities,
 * Security and Governance for the rest — and both the routes and the catalogue
 * ask it. The incident log above was added before M10 §2 gave the estate a
 * Security module; it is that module's record now, and is read as such.
 *
 * THE ONE THAT DOES NOT RUN HAS A DATA GAP, NOT MISSING CODE, and says so.
 * There is no budget model on this platform, so there is nothing to compare
 * actuals against. A live button over that would make it indistinguishable
 * from the six that work.
 *
 * EVERY REPORT STATES ITS PERIOD, which is the P&L card's own old warning kept:
 * "a report with an unstated period is the defect that surfaces at an audit".
 * The window is on the screen, in the export's filename and in the audit scope.
 */
class ReportsController extends Controller
{
    /**
     * What follows a refused period, on the report the reader is sent back to.
     *
     * TRUE OF EVERY REPORT, which is why it names no dates. Four reports run
     * over the period stated under their title; the ageing is "as at today"
     * and turnout is "every election on record", and each of those is still
     * the period the page states — so "the period stated below" is accurate on
     * all six, where "this year to date" would be wrong on two.
     */
    private const CHOOSE_AGAIN = 'What is shown instead is the period stated below. Choose the dates again to run it over another.';

    /**
     * The board's seven cards, in its own three groups and its own order.
     *
     * `ready` is whether this estate holds the data the report reads. It drives
     * nothing on screen today — no card generates — but it is the difference
     * between "we have not written this yet" and "there is nothing to write it
     * from", and those are two different conversations to have with a client.
     *
     * `icon` names one of the board's seven drawn glyphs. The page holds the
     * paths; this list only says which card wears which, because the board gives
     * every card a different one and a shared icon would be a visible fault.
     *
     * @var list<array{key: string, group: string, name: string, description: string, icon: string, ready: bool, reason: string}>
     */
    private const REPORTS = [
        [
            'key' => 'profit_and_loss',
            'group' => 'Financial',
            'name' => 'Profit & Loss',
            'description' => 'Income vs expenses for any period, by account category',
            'icon' => 'currency',
            'ready' => true,
            'reason' => 'Income against expenses over a period you choose.',
        ],
        [
            'key' => 'arrears_ageing',
            'group' => 'Financial',
            'name' => 'Arrears Ageing',
            // M81 §5 "the cards": board 30's words promised features the reports do not have; each card says what its report does.
            'description' => "Every unit's balance by ageing bucket, as at today",
            'icon' => 'clock',
            'ready' => true,
            'reason' => 'Every unit\'s balance by ageing bucket, as at today.',
        ],
        [
            'key' => 'budget_vs_actual',
            'group' => 'Financial',
            'name' => 'Budget vs Actual',
            'description' => 'Compares the budget with what came in and went out, for a period you choose',
            'icon' => 'calendar',
            'ready' => false,
            // M84 §3.3: until budgets are switched on, the card says so in one line, visibly (M83 item 3).
            'reason' => 'Budgets are coming.',
        ],
        [
            'key' => 'maintenance_summary',
            'group' => 'Operational',
            'name' => 'Maintenance Summary',
            'description' => 'Tickets by category, and how long they took to close',
            'icon' => 'spanner',
            'ready' => true,
            'reason' => 'Tickets raised in a period you choose, by category.',
        ],
        [
            'key' => 'security_incidents',
            'group' => 'Operational',
            'name' => 'Security Incident Log',
            'description' => 'All incident reports for a period you choose',
            'icon' => 'shield',
            'ready' => true,
            /*
             * BUILT (12 §2). Incidents are Gemini's central record, read here
             * filtered to this estate — the same reach D-035 gives the estate's
             * own activity feed. The old reason said this console never reads
             * them; its dashboard already did. Since M10 §2 they are the
             * Security module's, and both this card and the feed are for a
             * role holding Security view (B049, B137).
             */
            'reason' => 'Gemini Security\'s incidents here, for a period you choose.',
        ],
        [
            'key' => 'election_turnout',
            'group' => 'Governance',
            'name' => 'Election Turnout',
            'description' => 'Turnout by phase for the elections held in a period you choose',
            'icon' => 'people',
            'ready' => true,
            /*
             * BUILT, AND WITH THE CONSTRAINT INTACT. Turnout is a COUNT of
             * receipts and nothing finer: by phase is the finest cut this
             * platform makes, because a report that broke a result down until
             * a household could be identified would undo the secret ballot.
             */
            'reason' => 'Ballots cast by phase, never how anyone voted.',
        ],
        [
            'key' => 'meeting_minutes',
            'group' => 'Governance',
            'name' => 'Meeting Minutes Archive',
            // What the report can do (Hassam, 12 October: a card says it in one line): it says whether minutes were issued,
            // and the minutes themselves are opened from the meeting, not from here.
            'description' => 'The meetings held in a period you choose, and whether their minutes were issued',
            'icon' => 'megaphone',

            /*
             * READY NOW, and it was not: this card's reason used to say "this
             * platform stores no files yet", which was true and is not. Minutes
             * are issued as a PDF and kept seven years (12 §1), so the archive
             * indexes things that can actually be opened — which is exactly
             * what made it wrong to build before.
             */
            'ready' => true,
            'reason' => 'Every meeting held, its type, its date and its stage.',
        ],
    ];

    public function index(Request $request): Response
    {
        $groups = [];

        foreach (self::REPORTS as $report) {
            $groups[$report['group']]['heading'] = $report['group'];
            $groups[$report['group']]['cards'][] = [
                'key' => $report['key'],
                'name' => $report['name'],
                'description' => $report['description'],
                'icon' => $report['icon'],
                'action' => 'Generate',
                'ready' => $report['ready'],
                'reason' => $report['reason'],

                // The four the ruling named, plus the minutes archive whose own
                // reason said "this platform stores no files yet" — it does now.
                'built' => in_array($report['key'], Reports::built(), true),

                /*
                 * THE THIRD REFUSAL (B137), and the page is owed it for the
                 * reason it is owed the other two: a role that holds Reports
                 * export but not the module a report reads is refused for
                 * THAT, and would ask for the wrong thing if told otherwise.
                 * The same predicate the route's second lock asks, so no card
                 * is ever live over a report `show()` would refuse. Null where
                 * nothing is withheld.
                 */
                'withheld' => Reports::refusalFor($report['key'], $request->user()),
            ];
        }

        return inertia('Estate/Reports/Index', [
            'estate' => ['name' => (string) tenant()->name],
            'groups' => array_values($groups),

            /*
             * `export`, NOT `create`. A report brings nothing into existence —
             * it takes what the estate already holds and puts it in somebody's
             * hands, which is the one verb D-013 has for exactly that. It also
             * decides who is refused: the matrix legend withholds export from
             * `Entry` — "data entry, no approval" — so an Admin Assistant who
             * could be given rows to type could never be given the whole
             * estate's books in a file. `create` would have granted it to them.
             *
             * On the reports row the two verbs happen to land on the same five
             * roles today, and that is a coincidence of one matrix row rather
             * than a reason to pick either. The View cells — the Secretary and
             * the Property Manager — read the catalogue and generate nothing.
             *
             * Sent because the page must not have to guess which of the two
             * refusals applies: a role without the verb is refused for a reason
             * that will still be true tomorrow, and a card with a data gap is
             * refused for one that will not.
             */
            'canGenerate' => $request->user()->can('estate.reports.export'),
            'reasons' => [
                'access' => 'Generating a report needs Reports export access.',
            ],
        ]);
    }

    /**
     * One report, over a period the reader chose — board community-admin-30.
     *
     * THE PERIOD IS ALWAYS STATED. "A report with an unstated period is the
     * defect that surfaces at an audit" is the P&L card's own reason, and it is
     * the rule for all of them: the dates come from the reader — or, when they
     * gave none, are this year to date, which `period()` says is still a
     * stated period — are echoed on the screen, and go into the export's audit
     * scope. A date the reader gave that the calendar does not have is refused
     * on this screen with a sentence, never answered with a 500 or run as
     * some other day (B031).
     */
    public function show(Request $request, string $key, Reports $reports): Response|RedirectResponse
    {
        if (! in_array($key, Reports::built(), true)) {
            return redirect()->to($this->path('/reports'))->withErrors(['report' => Reports::NOT_RUNNABLE]);
        }

        $this->refuseUnlessModuleReadable($request, $key);

        try {
            $period = $this->period($request);
            $result = $reports->run($key, $period['from'], $period['to']);
        } catch (ReportPeriodRefused $refused) {
            return $this->chooseAgain($key, $refused);
        } catch (DomainException $refused) {
            return redirect()->to($this->path('/reports'))->withErrors(['report' => $refused->getMessage()]);
        }

        // M83 §1.2 item 3 (REP-4): the ageing, a position as at today, draws no dates and says so. Election Turnout's dates
        // narrow it now (REP-7, M85 Phase B), so it draws them like every other report.
        $periodApplies = $key !== 'arrears_ageing';

        return inertia('Estate/Reports/Show', [
            'estate' => ['name' => (string) tenant()->name],
            ...$result,
            'periodApplies' => $periodApplies,
            'period' => $periodApplies ? $result['period'] : 'As at '.Ledger::today()->format('j M Y'),
            'from' => $period['from'],
            'to' => $period['to'],
            'catalogueHref' => $this->path('/reports'),
            'path' => $this->path('/reports/'.$key),
            'exportHref' => $this->path('/reports/'.$key.'/export').'?from='.$period['from'].'&to='.$period['to'],
        ]);
    }

    /** The same report as a file, with the entry every export writes (12 §1). */
    public function export(Request $request, string $key, Reports $reports, Exporter $exporter): StreamedResponse|RedirectResponse
    {
        if (! in_array($key, Reports::built(), true)) {
            return redirect()->to($this->path('/reports'))->withErrors(['report' => Reports::NOT_RUNNABLE]);
        }

        // Before a row is read, so no audit entry records a file that never left.
        $this->refuseUnlessModuleReadable($request, $key);

        /*
         * A FILE IS NEVER TAKEN OVER A PERIOD NOBODY CHOSE. A bad date in the
         * export's own URL is not answered with this year's figures in a file
         * named for today — the reader is put back on the report, told which
         * date was wrong, and takes the file from there.
         */
        try {
            $period = $this->period($request);
            $result = $reports->run($key, $period['from'], $period['to']);
        } catch (ReportPeriodRefused $refused) {
            return $this->chooseAgain($key, $refused);
        } catch (DomainException $refused) {
            return redirect()->to($this->path('/reports'))->withErrors(['report' => $refused->getMessage()]);
        }

        // REP-6: plain amounts under one currency label, the totals and the period in the file, and the period in its name.
        $file = Reports::csv($result);

        return $exporter->csv(
            // The audit log shows this to a person: the console's middot, never an em dash (Law 3).
            scope: $result['title'].' · '.$result['period'],
            headers: $file['headers'],
            rows: $file['rows'],
            filename: str_replace('_', '-', $key).'-'.($key === 'arrears_ageing' ? 'as-at-'.Ledger::today()->toDateString() : $period['from'].'-to-'.$period['to']).'.csv',
        );
    }

    /**
     * The period, and it has to be chosen.
     *
     * A DEFAULT IS STILL A STATED PERIOD. What the rule forbids is a report
     * that does not say what it covers; it does not forbid the screen offering
     * this year to begin with. The dates are echoed on the result and in the
     * audit scope either way, so nobody reads a figure without its window.
     *
     * A DATE THAT WAS CHOSEN IS NEVER REPLACED, only refused. Until B031 a
     * `from` that did not look like a date was quietly swapped for January 1,
     * and one that looked like a date and was not — month 13 — went on to a
     * 500. The default is for a date nobody gave; a date somebody gave is
     * theirs, and `Reports::run()` either runs it or says why it cannot.
     *
     * THE ESTATE'S YEAR AND THE ESTATE'S TODAY (M4 §3.1), not the server's. At
     * eight on New Year's Eve in Kingston the server is already in January, and
     * a default read off its clock opened next year's empty report.
     *
     * @return array{from: string, to: string}
     *
     * @throws ReportPeriodRefused when a date was sent as anything but one date
     */
    private function period(Request $request): array
    {
        $today = SiteTime::today();

        return [
            'from' => $this->chosenDay($request, 'from') ?? $today->copy()->startOfYear()->toDateString(),
            'to' => $this->chosenDay($request, 'to') ?? $today->toDateString(),
        ];
    }

    /**
     * What the reader sent for one end of the period, or null when they sent nothing.
     *
     * @throws ReportPeriodRefused when it is not a single value — `from[]=…` in a hand-edited URL
     */
    private function chosenDay(Request $request, string $field): ?string
    {
        $value = $request->input($field);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new ReportPeriodRefused('A period is two dates, one for each end, and this one was not sent as two dates, so the report was not run over it.');
        }

        return trim($value) === '' ? null : $value;
    }

    /**
     * THE SECOND LOCK (B137): the module whose records this report reads.
     *
     * The route asks `estate.reports.export`, which is the same for all six
     * and so cannot say which records a role may read; this asks
     * `Reports::refusalFor()`, which can. A 403 with the sentence, the shape
     * `DuesController::exportArrears()` gives its own second lock — the
     * catalogue never links a report this refuses, so the only way here is by
     * URL, which is what the error page tells the reader.
     */
    private function refuseUnlessModuleReadable(Request $request, string $key): void
    {
        $withheld = Reports::refusalFor($key, $request->user());

        if ($withheld !== null) {
            abort(403, $withheld);
        }
    }

    /**
     * Back to the report the reader was on, with the reason, and over the period it states.
     *
     * THE REPORT, NOT THE CATALOGUE. Nothing is wrong with the report; only
     * the dates are, and the reader is already where they choose them. The
     * page they land on runs its default period and states it, so the
     * sentence says the period shown is not the one asked for.
     */
    private function chooseAgain(string $key, ReportPeriodRefused $refused): RedirectResponse
    {
        return redirect()->to($this->path('/reports/'.$key))->withErrors([
            'period' => $refused->getMessage().' '.self::CHOOSE_AGAIN,
        ]);
    }

    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
