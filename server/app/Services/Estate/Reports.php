<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Ballot;
use App\Models\Estate\Document;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Support\SiteTime;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The estate's own reports — board 29's catalogue, board 30's results (12 §1).
 *
 * EVERY REPORT STATES ITS PERIOD. That is the defect the P&L card's own reason
 * named: "a report with an unstated period is the defect that surfaces at an
 * audit." So there is no report here without a `from` and a `to`, they are
 * echoed on the screen and in the export's audit scope, and neither defaults to
 * something the reader did not choose.
 *
 * NOTHING HERE RECOMPUTES WHAT A SCREEN ALREADY COMPUTES. The ageing report
 * reads `Dues`; the maintenance report reads the same `sla_hours` the queue
 * measures against; turnout reads the tally. A report that did its own
 * arithmetic over the same rows would be a second answer, and the two would
 * differ the first time either changed.
 *
 * TURNOUT IS A COUNT AND NOTHING FINER. A report that broke a result down until
 * a household could be identified would undo the secret ballot — `ballot_marks`
 * and `ballot_receipts` share no column and nothing here joins them.
 */
class Reports
{
    public function __construct(
        private readonly Dues $dues,
        private readonly Governance $governance,
    ) {}

    /** The reports that can actually be run, and what each one is keyed by. */
    public const BUILT = ['profit_and_loss', 'arrears_ageing', 'maintenance_summary', 'security_incidents', 'election_turnout', 'meeting_minutes'];

    /** What a reader is told when they ask for a report that is not one of those. */
    public const NOT_RUNNABLE = 'That report is not one this estate can run.';

    /**
     * Whose records each report reads, and so whose gate it answers to (bug hunt B137).
     *
     * A REPORT IS NOT A WAY ROUND A MODULE. `estate.reports.export` says a role may take a
     * report out of the console; it has never said which records the role may read. Until
     * B137 it was the only gate, so a Treasurer refused the Security screen read the same
     * incidents here with the guard on post and what was done, and a copy of the Property
     * Manager given Reports Full read every household's balance and the estate's books — the
     * two things Ruling 1 (D-010) says that role, and every copy of it, may never see.
     *
     * EACH REPORT ASKS WHAT ITS OWN MODULE'S SCREEN ASKS. The ageing and the P&L are the
     * estate's position, so they ask `Dues::maySeeEstatePosition()` of the ledger and of
     * Accounting — the conjunction the arrears register, the dashboard and the chart of
     * accounts already keep. The maintenance summary asks Facilities, the incident log asks
     * Security (M10 §2 made incidents that module's content), and turnout and the minutes
     * archive ask Governance. Asked of permissions and never of a role name, so a role a
     * Community Super Admin builds tomorrow inherits the rule from the cells it is given.
     *
     * EVERY BUILT REPORT HAS AN ENTRY. `EstateReportsTest` runs all of BUILT as a role holding
     * Reports Full and nothing any report reads, and expects six refusals, so a seventh added
     * to BUILT without an owner here is refused by nobody and fails there.
     *
     * The refusals name the module and never a figure: each is sent to a browser beside the
     * report it withholds.
     *
     * @var array<string, array{module: string, position: bool, refusal: string}>
     */
    private const OWNERS = [
        'profit_and_loss' => [
            'module' => 'accounting_posting',
            'position' => true,
            'refusal' => 'Running the Profit & Loss needs view access on Accounting.',
        ],
        'arrears_ageing' => [
            'module' => 'dues_ledger',
            'position' => true,
            'refusal' => 'Running the Arrears Ageing needs view access on Dues & ledger.',
        ],
        // M84 §3.3: the books beside the budget, so the P&L's owner and its right to the balances.
        'budget_vs_actual' => [
            'module' => 'accounting_posting',
            'position' => true,
            'refusal' => 'Running Budget vs Actual needs view access on Accounting.',
        ],
        'maintenance_summary' => [
            'module' => 'facilities',
            'position' => false,
            'refusal' => 'Running the Maintenance Summary needs view access on Facilities.',
        ],
        'security_incidents' => [
            'module' => 'security',
            'position' => false,
            'refusal' => 'Running the Security Incident Log needs view access on Security.',
        ],
        'election_turnout' => [
            'module' => 'governance',
            'position' => false,
            'refusal' => 'Running Election Turnout needs view access on Governance.',
        ],
        'meeting_minutes' => [
            'module' => 'governance',
            'position' => false,
            'refusal' => 'Running the Meeting Minutes Archive needs view access on Governance.',
        ],
    ];

    /**
     * Why this viewer may not run this report, or null when they may.
     *
     * A STATIC PREDICATE TAKING THE VIEWER, the shape `Dues::maySeeEstatePosition()` has: the
     * controller asks it before `run()` and again for every card on the catalogue, so the page
     * never offers a report the route would refuse. Null too for a key that is not a built
     * report — `run()` refuses that itself, with its own sentence.
     */
    public static function refusalFor(string $key, User $viewer): ?string
    {
        $owner = self::OWNERS[$key] ?? null;

        if ($owner === null) {
            return null;
        }

        $may = $owner['position']
            ? Dues::maySeeEstatePosition($viewer, $owner['module'])
            : $viewer->can('estate.'.$owner['module'].'.view');

        return $may ? null : $owner['refusal'];
    }

    /**
     * Run one, over a period the reader chose.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    public function run(string $key, string $from, string $to): array
    {
        // M84 §3.3: Budget vs Actual runs only while budgets are switched on, and on its own path.
        if ($key === 'budget_vs_actual') {
            if (! in_array($key, self::built(), true)) {
                throw new DomainException(self::NOT_RUNNABLE);
            }

            [$start, $end] = $this->period($from, $to);

            return $this->budgetVersusActual($start, $end);
        }

        // Which report first: a period is only worth checking for one that runs.
        if (! in_array($key, self::BUILT, true)) {
            throw new DomainException(self::NOT_RUNNABLE);
        }

        [$start, $end] = $this->period($from, $to);

        return match ($key) {
            'profit_and_loss' => $this->profitAndLoss($start, $end),
            'arrears_ageing' => $this->arrearsAgeing($start, $end),
            'maintenance_summary' => $this->maintenanceSummary($start, $end),
            'security_incidents' => $this->securityIncidents($start, $end),
            'election_turnout' => $this->electionTurnout($start, $end),
            // No default arm: `$key` is one of BUILT by here, and a report added to
            // that list without an arm is a match PHPStan refuses as unhandled.
            'meeting_minutes' => $this->minutesArchive($start, $end),
        };
    }

    /**
     * The reports that run now: the six, and Budget vs Actual while FEATURE_BUDGETS is on (M84 §3.3).
     *
     * @return list<string>
     */
    public static function built(): array
    {
        return config('features.budgets') ? [...self::BUILT, 'budget_vs_actual'] : self::BUILT;
    }

    /**
     * The period the reader chose, as the estate's own first and last moments.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(string $from, string $to): array
    {
        /*
         * THE PERIOD IS THE ESTATE'S OWN DAYS (M4 §3.1). "Sep 1 to Sep 30" runs from the first
         * moment of Sep 1 to the last of Sep 30 ON THE ESTATE'S CLOCK. Both are carried in the
         * estate's zone — so their date is still the date the reader chose (the ledger's
         * `posted_on` is a calendar date) — and a stored time is compared with the UTC moment
         * each one is (`UtcMySqlConnection`). Read as UTC days, an incident at 8 PM on the 30th
         * fell into October.
         */
        $start = SiteTime::local(SiteTime::dayBounds(self::calendarDay($from))[0]);
        $end = SiteTime::local(SiteTime::dayBounds(self::calendarDay($to))[1]);

        if ($end->lessThan($start)) {
            throw new ReportPeriodRefused('The period ends before it begins, so the report was not run over it.');
        }

        return [$start, $end];
    }

    /**
     * Budget against actual, by account, over the period (M84 §3.3): budget, actual, the variance and the variance as a
     * percentage of the budget. The actual is the ledger's, on the account's normal side, as the Profit & Loss sums it.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function budgetVersusActual(Carbon $start, Carbon $end): array
    {
        $rows = [];
        $budget = 0;
        $actual = 0;

        foreach (app(Budgets::class)->versusActual($start, $end) as $line) {
            $budget += $line['budget_minor'];
            $actual += $line['actual_minor'];

            $rows[] = [
                $line['code'],
                $line['name'],
                self::signedMoney($line['budget_minor']),
                self::signedMoney($line['actual_minor']),
                self::signedMoney($line['variance_minor']),
                $line['variance_pct'] === null ? '' : number_format($line['variance_pct'], 1).'%',
            ];
        }

        return [
            'key' => 'budget_vs_actual',
            'title' => 'Budget vs Actual',
            'period' => $this->periodLabel($start, $end),
            'columns' => ['Account', 'Name', 'Budget', 'Actual', 'Variance', 'Variance %'],
            'rows' => $rows,
            // The file's money columns (REP-6); its totals are the summary's.
            'money' => [2, 3, 4],
            'summary' => [
                ['label' => 'Budget', 'value' => self::signedMoney($budget)],
                ['label' => 'Actual', 'value' => self::signedMoney($actual)],
                ['label' => 'Variance', 'value' => self::signedMoney($actual - $budget)],
            ],
            'note' => null,
        ];
    }

    /**
     * A REPORT AS A FILE (REP-6, E2E-M10; M85 Phase B): the report, with nothing a spreadsheet cannot add up.
     *
     * The Arrears Ageing wrote "Balance JMD" over plain numbers and the P&L wrote "$74,737,126.08" as text, with no
     * totals and no period. So every money column a report names (`money`) is headed "<name> JMD", its cells are plain
     * numbers ("74737126.08"), and below the rows come a Total where a column adds up (`totals`: the ageing's balances;
     * the P&L mixes income and expense, so its totals are its summary), a blank line, the report's own summary (money
     * as plain numbers) and the period it covers. Exact: an amount is read as money, never through a float.
     *
     * @param  array{columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, period: string, money?: list<int>, totals?: bool}  $result
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public static function csv(array $result): array
    {
        $money = $result['money'] ?? [];

        $headers = [];

        foreach ($result['columns'] as $i => $column) {
            $headers[] = in_array($i, $money, true) && ! str_contains($column, 'JMD') ? $column.' JMD' : $column;
        }

        $rows = array_map(static function (array $row) use ($money): array {
            foreach ($money as $i) {
                $row[$i] = self::plainAmount((string) ($row[$i] ?? ''));
            }

            return $row;
        }, $result['rows']);

        if ($money !== [] && ($result['totals'] ?? false)) {
            $total = array_fill(0, count($result['columns']), '');
            $total[0] = 'Total';

            foreach ($money as $i) {
                $minor = array_sum(array_map(static fn (array $row): int => $row[$i] === '' ? 0 : Money::of($row[$i], 'JMD')->getMinorAmount()->toInt(), $rows));
                $total[$i] = (string) BigDecimal::ofUnscaledValue($minor, 2);
            }

            $rows[] = $total;
        }

        $rows[] = [];

        foreach ($result['summary'] as $item) {
            $rows[] = [$item['label'], preg_match('/^-?\$[\d,]+(\.\d+)?$/', $item['value']) === 1 ? self::plainAmount($item['value']) : $item['value']];
        }

        $rows[] = ['Period', $result['period']];

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** "$1,234.56" or "-$1,234.56" as "1234.56" or "-1234.56"; a plain number or a blank as it came. */
    private static function plainAmount(string $cell): string
    {
        return str_replace(['$', ','], '', $cell);
    }

    /** "-$600.00", "$1,250.50": the sign before the dollar, two decimals. */
    private static function signedMoney(int $minor): string
    {
        return ($minor < 0 ? '-' : '').'$'.number_format(abs($minor) / 100, 2);
    }

    /**
     * A day the reader wrote, if the calendar has it — or the refusal that says it does not.
     *
     * THE DATE PICKER'S OWN SHAPE, `YYYY-MM-DD`, AND A DAY THAT EXISTS. The shape alone let
     * `2026-13-01` through to Carbon, which threw and answered a 500; and it let `2026-02-31`
     * through to PHP, which quietly read it as March 3 — so the report covered a period that
     * was not the one on the screen. `checkdate()` is the calendar's own answer to both, and a
     * year 0 is refused with them because the calendar has none.
     *
     * The typed text is quoted back, so the reader can see which of the two dates was wrong.
     * Cut short, because it came from a URL and could be anything.
     */
    private static function calendarDay(string $typed): string
    {
        $day = trim($typed);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new ReportPeriodRefused('“'.mb_substr($day, 0, 40).'” is not a date on the calendar, so the report was not run over it.');
        }

        return $day;
    }

    /* ------------------------------------------------------------------ */
    /* financial */
    /* ------------------------------------------------------------------ */

    /**
     * Income against expenses, by account, over the period.
     *
     * SUMMED FROM POSTED JOURNAL LINES and nothing else — the same discipline
     * every money screen on this platform keeps. Income is credits less debits
     * because that is its normal side; expense is the other way. Getting that
     * backwards is how a profitable month reads as a loss.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function profitAndLoss(Carbon $start, Carbon $end): array
    {
        $lines = DB::connection('tenant')
            ->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
            ->whereIn('accounts.type', [Account::INCOME, Account::EXPENSE])
            ->whereBetween('journals.posted_on', [$start->toDateString(), $end->toDateString()])
            ->groupBy('accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.code, accounts.name, accounts.type, SUM(journal_lines.debit_minor) AS dr, SUM(journal_lines.credit_minor) AS cr')
            ->get();

        $rows = [];
        $income = 0;
        $expense = 0;

        foreach ($lines as $line) {
            $isIncome = $line->type === Account::INCOME;
            $minor = $isIncome
                ? (int) $line->cr - (int) $line->dr
                : (int) $line->dr - (int) $line->cr;

            $isIncome ? $income += $minor : $expense += $minor;

            $rows[] = [
                $isIncome ? 'Income' : 'Expense',
                $line->code,
                $line->name,
                $this->money($minor),
            ];
        }

        return [
            'key' => 'profit_and_loss',
            'title' => 'Profit & Loss',
            'period' => $this->periodLabel($start, $end),
            'columns' => ['Kind', 'Account', 'Name', 'Amount'],
            'rows' => $rows,
            // The file's money column (REP-6); income and expense do not add up together, so its totals are the summary's.
            'money' => [3],
            'summary' => [
                ['label' => 'Income', 'value' => $this->money($income)],
                ['label' => 'Expenses', 'value' => $this->money($expense)],
                ['label' => $income - $expense >= 0 ? 'Surplus' : 'Deficit', 'value' => $this->money(abs($income - $expense))],
            ],
            'note' => 'Summed from posted journal lines by the date the entry was POSTED, not the date a charge fell due. An invoice raised in March and posted in April belongs to April, which is where the ledger puts it.',
        ];
    }

    /**
     * Every unit's balance by ageing bucket.
     *
     * THE PERIOD IS ECHOED AND DOES NOT FILTER, and the note says so. An ageing
     * is a position at a moment — today's — and pretending a date range narrowed
     * it would be a report that looked filtered and was not. This is the one
     * report where the honest thing is to say the period does not apply.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function arrearsAgeing(Carbon $start, Carbon $end): array
    {
        /*
         * THE POSITION, EXPLICITLY (M10 §5). This report IS the estate's
         * position, and two locks stand before this line: the route's
         * `can:estate.reports.export` — Reports' own verb for putting the
         * estate's books in somebody's hands, which Entry never grants — and
         * `refusalFor()`, which the controller asks first and which asks
         * `Dues::maySeeEstatePosition()` of the ledger (B137). The route alone
         * let a copy of the Property Manager given Reports Full read every
         * household's balance here. Passed as `true` rather than left to the
         * default so the choice is written down: whoever reaches this line has
         * already been asked the question the default would ask.
         */
        $board = $this->dues->arrearsBoard('', false, true);

        $rows = array_map(static fn (array $row): array => [
            $row['unit'],
            $row['household'],
            $row['bucket_label'],
            number_format($row['balance_minor'] / 100, 2, '.', ''),
            self::dayFirst($row['last_payment'] ?? null),
        ], $board['rows']);

        $summary = array_map(fn (array $band): array => [
            'label' => $band['label'],
            'value' => $this->money($band['value_minor']),
        ], $board['ageing']);

        return [
            'key' => 'arrears_ageing',
            'title' => 'Arrears Ageing',
            'period' => 'As at '.SiteTime::date()->format('j M Y'),
            'columns' => ['Unit', 'Household', 'Ageing', 'Balance JMD', 'Last payment'],
            'rows' => $rows,
            // The file's money column, which adds up to what is owed (REP-6).
            'money' => [3],
            'totals' => true,
            'summary' => $summary,
            'note' => 'An ageing is the position today, not over a period, so the dates chosen do not narrow it. The buckets are the same four the arrears board draws, from the same posted lines.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* operational */
    /* ------------------------------------------------------------------ */

    /**
     * Tickets reported in the period, by category, with how long they took.
     *
     * MEASURED FROM REPORT TO CLOSE, which is the same clock the queue runs —
     * a report that measured from assignment would hide precisely the failure
     * it exists to surface.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function maintenanceSummary(Carbon $start, Carbon $end): array
    {
        $tickets = MaintenanceTicket::query()
            ->whereBetween('reported_at', [$start, $end])
            ->orderBy('reported_at')
            ->get();

        $byCategory = [];
        $closedHours = [];
        $breached = 0;

        foreach ($tickets as $ticket) {
            // One row per category, whichever way it was stored: "Plumbing" and "plumbing" were two (FAC-6).
            $category = MaintenanceTicket::categoryLabel($ticket->category) ?? 'Uncategorised';
            $byCategory[$category] ??= ['raised' => 0, 'closed' => 0, 'hours' => []];
            $byCategory[$category]['raised']++;

            if ($ticket->closed_at !== null) {
                $hours = $ticket->reported_at->diffInHours($ticket->closed_at);
                $byCategory[$category]['closed']++;
                $byCategory[$category]['hours'][] = $hours;
                $closedHours[] = $hours;

                if ($hours > $ticket->sla_hours) {
                    $breached++;
                }
            }
        }

        ksort($byCategory);

        $rows = [];

        foreach ($byCategory as $category => $figures) {
            $rows[] = [
                $category,
                (string) $figures['raised'],
                (string) $figures['closed'],
                $figures['hours'] === []
                    ? '—'
                    : number_format(array_sum($figures['hours']) / count($figures['hours']) / 24, 1).' days',
            ];
        }

        return [
            'key' => 'maintenance_summary',
            'title' => 'Maintenance Summary',
            'period' => $this->periodLabel($start, $end),
            'columns' => ['Category', 'Raised', 'Closed', 'Average to close'],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Raised', 'value' => (string) $tickets->count()],
                ['label' => 'Closed', 'value' => (string) count($closedHours)],
                [
                    'label' => 'Average to close',
                    'value' => $closedHours === []
                        ? '—'
                        : number_format(array_sum($closedHours) / count($closedHours) / 24, 1).' days',
                ],
                ['label' => 'Past target', 'value' => (string) $breached],
            ],
            'note' => 'Counted by the date a ticket was reported, and timed from report to close, the same clock the maintenance queue runs. Timing from assignment would hide the tickets nobody picked up.',
        ];
    }

    /**
     * This estate's security incidents in the period.
     *
     * READ FROM THE CENTRAL LOG, FILTERED TO THIS ESTATE — the same reach the
     * estate's own Security screen has, in the other direction from board 26's
     * cross-tenant feed. The rows are Gemini's record of posts it staffs at
     * this estate; nothing here opens any other estate's data.
     *
     * AND ONLY FOR A ROLE THAT HOLDS SECURITY (B137). Incidents became the
     * Security module's content when M10 §2 gave the estate that module, and
     * the Secretary, the Treasurer and the Admin Assistant hold '-' on it; this
     * log names the guard on post and what was done, which is more than the
     * Security screen's own feed row does. See `OWNERS`.
     *
     * NOT BY PHASE. The card's description promises a phase filter, and an
     * incident records its client, its guard and what happened — not a unit or
     * a phase — so the note says so rather than inventing a split.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function securityIncidents(Carbon $start, Carbon $end): array
    {
        $incidents = SecurityIncident::query()
            ->where('tenant_id', (string) tenant()->getTenantKey())
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at')
            ->get();

        // On the estate's clock, written as every date and time on the estate's console is ("4 May 2026, 2:00 PM": Law 5;
        // Hassam, 12 October, no "JA"); the column names the clock once, in its own words ("When (Jamaica time)").
        $rows = $incidents->map(static fn (SecurityIncident $incident): array => [
            SiteTime::local($incident->occurred_at)->format('j M Y, g:i A'),
            $incident->kind,
            match ($incident->severity) {
                'high' => 'High',
                'med' => 'Medium',
                default => 'Low',
            },
            $incident->guard_name ?? 'Not recorded',
            // The words the estate's Security screen gives the same incident (M98 status words): Closed, or Action required.
            $incident->status === SecurityIncident::RESOLVED ? trim('Closed '.($incident->closed_at?->format('j M Y') ?? '')) : 'Action required',
            (string) ($incident->resolution ?? ''),
        ])->all();

        return [
            'key' => 'security_incidents',
            'title' => 'Security Incident Log',
            'period' => $this->periodLabel($start, $end),
            'columns' => ['When ('.SiteTime::clockName(SiteTime::zone()).')', 'Incident', 'Severity', 'Guard on post', 'Status', 'What was done'],
            'rows' => array_values($rows),
            'summary' => [
                ['label' => 'Incidents', 'value' => (string) $incidents->count()],
                ['label' => 'Still open', 'value' => (string) $incidents->where('status', '!=', SecurityIncident::RESOLVED)->count()],
                ['label' => 'High severity', 'value' => (string) $incidents->where('severity', 'high')->count()],
            ],
            'note' => 'Gemini Security\'s record of incidents on the posts it staffs at this estate, by the time each happened. An incident records its guard and what happened, not a unit or a phase, so this log is not split by phase. Duress and panic alerts are a separate life-safety record and are not listed here.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* governance */
    /* ------------------------------------------------------------------ */

    /**
     * Turnout for each election whose poll ran in the period.
     *
     * A COUNT AND NOTHING FINER. Turnout by phase is the finest cut this
     * platform will make, and it comes from `Governance` — which reads receipts
     * and never marks. Nothing here could answer how a household voted because
     * no query exists that would.
     *
     * HELD ELECTIONS ONLY, IN THE PERIOD CHOSEN (REP-2, REP-7; M85 Phase B). It
     * read every year on record and ignored the dates, so a year whose ballot never
     * opened read zero ballots. An election appears when its poll opened (Voting
     * Open or beyond, not cancelled) and its poll ran in the period: opened by its
     * end and closed, or still open, at its start.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function electionTurnout(Carbon $start, Carbon $end): array
    {
        $rows = [];
        $years = DB::connection('tenant')->table('ballots')->distinct()->orderBy('year')->pluck('year');
        $stages = array_keys(Ballot::STAGES);
        $voting = (int) array_search(Ballot::VOTING_OPEN, $stages, true);

        foreach ($years as $year) {
            $board = $this->governance->resultsBoard((int) $year);
            $ballot = $board['ballot'] ?? null;

            // A stage the list does not know (cancelled among them) is not a held election.
            $stage = $ballot === null ? false : array_search($ballot['stage'], $stages, true);

            if ($stage === false || $stage < $voting) {
                continue;
            }

            $opened = ($ballot['opens_at'] ?? $ballot['closes_at'] ?? null) === null ? null : Carbon::parse((string) ($ballot['opens_at'] ?? $ballot['closes_at']));
            $closed = ($ballot['closes_at'] ?? null) === null ? null : Carbon::parse((string) $ballot['closes_at']);

            if ($opened === null || $opened->greaterThan($end) || ($closed !== null && $closed->lessThan($start))) {
                continue;
            }

            foreach ($board['phases'] as $phase) {
                $rows[] = [
                    (string) $year,
                    $phase['phase'],
                    (string) $phase['cast'],
                    (string) $phase['eligible'],
                    $phase['percent'].'%',
                ];
            }
        }

        return [
            'key' => 'election_turnout',
            'title' => 'Election Turnout',
            'period' => $this->periodLabel($start, $end),
            'columns' => ['Year', 'Phase', 'Ballots cast', 'Eligible', 'Turnout'],
            'rows' => $rows,
            'summary' => [],
            'note' => 'Turnout counts the households that voted, by phase, and nothing finer. Whether a household voted and how it voted are kept apart, with nothing that links the two, so no vote can ever be traced back to a household.',
        ];
    }

    /**
     * Every meeting held in the period, and whether its minutes exist as a document.
     *
     * The card's old reason said "this platform stores no files yet". It does
     * now — minutes are issued as a PDF, kept seven years — so this archive is
     * an index of things that CAN be opened, which is what made it wrong before.
     *
     * HELD MEETINGS ONLY, IN THE MEETINGS TAB'S WORDS (REP-3, M85 Phase B). It
     * listed drafts and meetings still ahead as though held, with "Not recorded",
     * in words of its own. A meeting appears once it has been held (Held, Minutes
     * published, Archived) and its Status is the stage the Meetings tab gives it.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function minutesArchive(Carbon $start, Carbon $end): array
    {
        $held = [Meeting::STAGE_HELD, Meeting::STAGE_MINUTES_PUBLISHED, Meeting::STAGE_ARCHIVED];

        $meetings = Meeting::query()
            ->with('minutes')
            ->whereBetween('starts_at', [$start, $end])
            ->orderByDesc('starts_at')
            ->get()
            ->filter(static fn (Meeting $meeting): bool => in_array($meeting->stage(), $held, true))
            ->values();

        $issued = Document::query()
            ->where('kind', Document::MINUTES)
            ->where('status', Document::READY)
            ->pluck('subject_id')
            ->flip();

        $rows = [];

        foreach ($meetings as $meeting) {
            $rows[] = [
                $meeting->starts_at->format('j M Y'),
                $meeting->typeLabel(),
                $meeting->title,
                $meeting->stageLabel(),
                isset($issued[(string) $meeting->id]) ? 'Issued' : '',
            ];
        }

        return [
            'key' => 'meeting_minutes',
            'title' => 'Meeting Minutes Archive',
            'period' => $this->periodLabel($start, $end),
            'columns' => ['Date', 'Type', 'Meeting', 'Status', 'Document'],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Meetings held', 'value' => (string) $meetings->count()],
                ['label' => 'With minutes', 'value' => (string) $meetings->filter(static fn (Meeting $m): bool => $m->minutes !== null)->count()],
            ],
            'note' => 'The meetings held in this period, each with the stage the Meetings tab gives it. A meeting whose minutes have been issued as a PDF says so, and the document is in the meeting register.',
        ];
    }

    /* ------------------------------------------------------------------ */

    /** "1 Jan 2026 to 11 Oct 2026": every date a report states, one way (Law 5; Hassam, 12 October: no long month names). */
    private function periodLabel(Carbon $start, Carbon $end): string
    {
        return $start->format('j M Y').' to '.$end->format('j M Y');
    }

    /**
     * The arrears board's last payment ("Sep 30, 2026", the Dues page's own form) as a report writes a date, "30 Sep 2026".
     * Only that form is rewritten, exactly; anything else is left as it came.
     */
    private static function dayFirst(?string $date): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        $day = DateTimeImmutable::createFromFormat('!M j, Y', $date);

        return $day !== false && $day->format('M j, Y') === $date ? $day->format('j M Y') : $date;
    }

    private function money(int $minor): string
    {
        return '$'.number_format($minor / 100, 2);
    }
}
