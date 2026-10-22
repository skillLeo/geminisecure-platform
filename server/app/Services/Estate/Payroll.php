<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Employee;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\PayrollRunLine;
use App\Models\Estate\StatutoryFiling;
use App\Models\StatutoryRateVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payroll\PayrollCalculator;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Brick\Math\Exception\MathException;
use Brick\Money\Money;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The estate's own staff payroll — boards 13, 14, 15, 16 and 37.
 *
 * THE ONE DOOR. Every run is calculated here, approved here and disbursed
 * here, and the money leaves through `Ledger::post()` exactly as a bill does.
 * Nothing in this class writes a journal row itself and nothing outside it
 * writes a payslip.
 *
 * FOUR REFUSALS, AND EACH ONE IS A DIFFERENT PERSON'S PROBLEM
 * ===========================================================
 *
 * 1. A run with an unresolved exception cannot be calculated. Board 14 says so
 *    in its own banner — "This run can't proceed to calculation until each item
 *    below is resolved or the employee is excluded" — and it is the estate's
 *    own control: a missing timesheet calculated anyway pays somebody for hours
 *    nobody recorded.
 *
 * 2. A run cannot be approved by the person who prepared it. Board 15's banner
 *    is explicit: "Prepared by Tracey Reid — awaiting your approval. As a
 *    second approver, this run cannot be disbursed until you review and approve
 *    it." That is a SECOND-PERSON control, not a permission level: whoever
 *    holds `payroll.approve_runs` still may not approve their own work. Holding
 *    the permission and being a different human are two different conditions
 *    and both are checked.
 *
 * 3. A run cannot be approved against a rate card that is not verified. Q-002
 *    is ruled (D-082): the 2025-04 and 2026-04 cards carry TAJ's published
 *    periodic thresholds and are verified, so a run in either period can be
 *    approved. A card seeded from its annual figure alone — 2024-04, 2027-04 —
 *    is not, and a run falling in one stops at `calculated` with the reason on
 *    the control until somebody records TAJ's figures for it.
 *
 *    AND THE FIRST LIVE RUN CARRIES AN ACKNOWLEDGEMENT (Part F of the ruling).
 *    The figures reconcile to the cent against TAJ's tables, and nobody at the
 *    client has countersigned them, so the first run approved in an estate asks
 *    the approver to confirm it was reconciled against current TAJ tables,
 *    naming the card. Not a block — a tick, recorded on the run with the name
 *    and the card — and never asked again once one run carries it.
 *
 * 4. A posted run cannot be re-posted, re-approved or edited. Its journal is
 *    append-only at the database, so a second disbursement would be a second
 *    J$323,005 that nothing can unpost.
 *
 * WHAT IS STORED AND WHAT IS COMPUTED
 * ===================================
 *
 * `PayrollCalculator` is not reimplemented here — it already computes a payslip
 * in the order that makes the figures right (NIS first, because Education Tax
 * and PAYE are both charged on income after it). This class decides WHO is in a
 * run and WHEN, and hands each gross to that calculator.
 *
 * A run's `gross_minor` and `net_minor` are stored, and its lines' five figures
 * are stored, because a payslip is a statement issued to a person on a date.
 * Recomputing it later against whatever rate version is current would silently
 * restate what somebody was told they earned. The control totals every screen
 * prints are summed from those lines on read, never stored a second time — so
 * a run header that disagreed with its own lines is a test failure rather than
 * a number a committee reads.
 *
 * ONE STATUTORY PAYABLE, NOT FOUR. Board 16's accounting note describes the S01
 * as clearing "the four statutory payable control accounts", and the approved
 * chart of accounts carries one: 2100 Statutory Deductions Payable. The chart
 * is what every other estate screen already posts against and board 25 draws,
 * so it is not reshaped to match a note. The four-way split lives on the
 * payslip line and on the filing, which is the sub-ledger 2100 is the control
 * for, and `EstatePayrollTest` asserts the tie both ways. See DECISIONS.md.
 */
class Payroll
{
    /** M78 §1: why a paid run's exceptions are not decided again, in plain words (it said "part of the record"). */
    public const PAID_EXCEPTION = 'This run has been paid, so its exceptions cannot change.';

    /** Gross pay. The estate's own staff, never Gemini's guards. */
    public const EXPENSE = '5000';

    /** What has been withheld and not yet remitted. */
    public const STATUTORY_PAYABLE = '2100';

    /** Where net pay leaves from, and where a remittance leaves from. */
    public const BANK = '1000';

    /**
     * The employer's own NIS, NHT, Education Tax and HEART (D-083).
     *
     * Debited on approval and credited to 2100 beside what was withheld — the
     * ruling on Q-002 put employer contributions on the S01, and a payable the
     * S01 clears has to have been credited first.
     */
    public const EMPLOYER_CONTRIBUTIONS = '5010';

    /**
     * An approved pension withheld and owed to the scheme — Q-017 (13 §0).
     *
     * Not 2100: the S01 clears 2100, and a pension is not TAJ's. Not in the
     * seeded chart either, because every contribution is zero; a run carrying
     * one is refused until the estate adds the account.
     */
    public const PENSION_PAYABLE = '2150';

    /**
     * Monthly. Every rate on board 37 is quoted "/mo" and every run on board 13
     * is a calendar month, so a run reads TAJ's published MONTHLY threshold —
     * 158,530.00 on the 2026-04 card — rather than dividing the annual one.
     */
    public const PERIODS_PER_YEAR = 12;

    /** Who releases a run, and who can calculate one: the two permissions the approver line reads (M94 §1.1). */
    private const APPROVES = 'estate.payroll.approve_runs';

    private const CALCULATES = 'estate.payroll.update';

    /** An exception's kind in the words the run page's decisions use: "excluded Neil Anderson from this run, missing timesheet." */
    private const EXCEPTION_WORDS = [
        PayrollException::MISSING_TIMESHEET => 'missing timesheet',
        PayrollException::OVERTIME_ANOMALY => 'unusual overtime',
    ];

    /**
     * WHAT A REQUEST FOR CHANGES MUST NAME (the client, 12 Oct 2026: "all done" sent a run back). The box asks "What
     * needs changing?", so a reply that names nobody on the payroll and no part of a payslip is refused, as an empty
     * one is. A list, not a judgement: "Wayne Thomas's overtime" passes; "all done", "OK" and "please check" do not.
     */
    private const PAYSLIP_WORDS = [
        'overtime', 'hour', 'hours', 'timesheet', 'timesheets', 'shift', 'shifts', 'days', 'salary', 'salaries', 'wage',
        'wages', 'pay', 'payslip', 'payslips', 'gross', 'net', 'deduction', 'deductions', 'nis', 'nht', 'paye', 'tax',
        'heart', 'rate', 'rates', 'bonus', 'allowance', 'allowances', 'pension', 'exception', 'exceptions', 'exclude',
        'excluded', 'include', 'included', 'employee', 'employees', 'bank', 'account', 'amount', 'amounts', 'figure',
        'figures', 'total', 'totals', 'leave', 'sick', 'vacation', 'holiday', 'advance', 'loan', 'arrears', 'backpay',
        'overpaid', 'underpaid',
    ];

    /**
     * The hours a monthly rate pays for in a year: a 40-hour week, 52 weeks (EST-B-07, M85 A2). Accepted overtime is
     * paid at time and a half of the monthly rate over these hours.
     */
    public const STANDARD_HOURS_A_YEAR = 2_080;

    /**
     * Who can approve and who can calculate, kept for the request (`estateAccountsThatCanEach()`).
     *
     * @var array<string, array<string, array<int, array{name: string, role: string|null}>>>
     */
    private array $accounts = [];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /* ------------------------------------------------------------------ */
    /* board 13 — the run list */
    /* ------------------------------------------------------------------ */

    /**
     * What the run list orders by (M32 F5): newest period first by default — anything with a time.
     *
     * @return array<string, string>
     */
    public static function runSorts(): array
    {
        return [
            'period' => 'period_sort',
            'status' => 'status_label',
            'employees' => 'employees',
            'gross' => 'gross_minor',
            'net' => 'net_minor',
        ];
    }

    public static function runsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::runSorts(), 'period', 'desc');
    }

    /**
     * Board 13: the tiles, and every run as a table ordered by the server and paged (M32 F5).
     *
     * @return array<string, mixed>
     */
    public function runsBoard(?TableQuery $table = null, ?User $viewer = null): array
    {
        $table ??= self::runsTable();

        // M96 level 3 (the payroll pass, 11 Oct): a row never offers to approve to a role that can never approve.
        $approves = $viewer === null || $viewer->can('estate.payroll.approve_runs');

        /*
         * EVERY RUN, ONCE (the run list's query budget, 12 Oct). The calendar under the tiles and the next pay date read
         * the latest run, the earliest open one and the earliest unpaid one each with a query of its own: three
         * statements for what this list already holds. They are taken from it now. (Who settled each run's exceptions
         * came with it too, for the approver line; since D-233 that line counts only the calculation, which the run holds.)
         */
        $runs = PayrollRun::query()
            ->withCount('lines')
            ->orderByDesc('period_start')
            ->get();

        $latestPaid = $runs->firstWhere('status', PayrollRun::PAID);

        $rows = $runs->map(fn (PayrollRun $run): array => [
            'id' => $run->id,
            'slug' => $run->slug,
            'period' => $run->period_label,
            'period_sort' => $run->period_start->toDateString(),
            'status' => $run->status,
            'status_label' => $this->statusLabel($run),
            'employees' => $run->lines_count,

            /*
             * A run nobody has calculated has no gross and no net, and the
             * board prints an em dash rather than a zero. Zero is a figure —
             * it says the estate owes its staff nothing this month — and
             * this run says nothing at all yet.
             */
            'gross_minor' => $run->status === PayrollRun::DRAFT || $run->status === PayrollRun::EXCEPTIONS
                ? null
                : $run->gross_minor,
            'net_minor' => $run->status === PayrollRun::DRAFT || $run->status === PayrollRun::EXCEPTIONS
                ? null
                : $run->net_minor,
            'action' => $this->rowAction($run, $approves),
        ])->values()->all();

        // The run's search (M32 F5): its period, as the list names it — "Sep 2026".
        if ($table->search !== '') {
            $needle = mb_strtolower($table->search);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower((string) $row['period'].' '.$row['status_label']), $needle)));
        }

        $page = $table->pageOf($rows);

        return [
            'kpis' => [
                [
                    'key' => 'employees',
                    'value' => (string) Employee::query()->where('status', Employee::ACTIVE)->count(),
                    'label' => 'Employees on payroll',
                ],
                [
                    'key' => 'next_pay_date',
                    'value' => $this->nextPayDate($runs)?->format('M j') ?? '—',
                    'label' => 'Next pay date',
                ],
                // Plain words (the payroll pass): no em dash, and no "your" to a role that never approves.
                [
                    'key' => 'last_net',
                    'value_minor' => $latestPaid === null ? 0 : (int) $latestPaid->net_minor,
                    'label' => 'Last run’s net pay',
                ],
                [
                    'key' => 'pending',
                    'value' => (string) $runs->where('status', PayrollRun::CALCULATED)->count(),
                    'label' => 'Waiting for approval',
                ],
            ],

            'rows' => $page->items(),
            'table' => $table->meta($page),

            // The next period, and why it cannot be started if it cannot, from the runs above (see the note on `$runs`).
            'calendar' => $this->calendar($runs),
        ];
    }

    /**
     * The badge board 13 draws, in the one vocabulary (M96, M99): Draft; Action required for a run with exceptions to
     * settle or sent back to be calculated again; Pending for a calculated run waiting for its approver; Paid. It said
     * "2 exceptions" (and "0 exceptions" for a run sent back) and "Pending approval", neither of them the vocabulary's.
     * The stored value stays the lifecycle state and the label is derived.
     */
    private function statusLabel(PayrollRun $run): string
    {
        return match ($run->status) {
            PayrollRun::EXCEPTIONS => 'Action required',
            PayrollRun::CALCULATED => 'Pending',
            PayrollRun::PAID => 'Paid',
            default => 'Draft',
        };
    }

    /** What the row's link offers to do about this run: approving only to a role that ever approves. */
    private function rowAction(PayrollRun $run, bool $approves = true): string
    {
        return match ($run->status) {
            PayrollRun::EXCEPTIONS => 'Resolve exceptions',
            PayrollRun::CALCULATED => $approves ? 'Review & approve' : 'Review',
            default => 'View',
        };
    }

    /**
     * The payroll calendar: the period the next run would cover.
     *
     * CALENDAR MONTHS, ONE OPEN AT A TIME (12 §2, Wave 1 — "a payroll calendar
     * of periods and close dates"). This estate pays monthly; a period runs
     * from the first to the last day of the month, closes on that last day,
     * and is paid on it. The next period is the month after the latest run,
     * whatever its state — or this month, for an estate that has never run
     * one — so the calendar cannot skip a month or run one twice.
     *
     * Returned with why it cannot be started, when it cannot: a run still open
     * (a month is closed before the next is opened), or no rate card in force
     * on its pay date.
     *
     * Given the runs the run list already holds (`runsBoard()`), its latest and its earliest open month are taken from
     * them; asked on its own (Start new run, which must read afresh), they are read as before.
     *
     * @param  Collection<int, PayrollRun>|null  $runs  every run, as `runsBoard()` reads them
     * @return array<string, mixed>
     */
    public function calendar(?Collection $runs = null): array
    {
        $latest = $runs === null
            ? PayrollRun::query()->orderByDesc('period_start')->first()
            : $runs->sortByDesc(static fn (PayrollRun $run): int => $run->period_start->getTimestamp())->first();

        // The estate's month, not UTC's: on the last evening of September in
        // Kingston a first run is still September's (bug hunt B023).
        $start = $latest === null
            ? Ledger::today()->startOfMonth()
            : $latest->period_start->copy()->addMonthNoOverflow()->startOfMonth();

        $end = $start->copy()->endOfMonth();

        $open = $runs === null
            ? PayrollRun::query()->where('status', '!=', PayrollRun::PAID)->orderBy('period_start')->first()
            : $runs->filter(static fn (PayrollRun $run): bool => $run->status !== PayrollRun::PAID)
                ->sortBy(static fn (PayrollRun $run): int => $run->period_start->getTimestamp())
                ->first();

        $card = StatutoryRateVersion::inForceOn($end->toDateString());

        return [
            'slug' => strtolower($start->format('M-Y')),
            'label' => $start->format('F Y'),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            // The days it covers, written the way every other date on the screen is (Law 5): "Nov 1 to Nov 30, 2026".
            'runs' => $start->format('M j').' to '.$end->format('M j, Y'),
            'pay_date' => $end->format('M j, Y'),
            'closes_on' => $end->format('M j, Y'),
            'rate_card' => $card === null ? null : $card->effective_from->format('Y-m').($card->is_verified ? '' : ' (unverified)'),
            'blocked_by' => match (true) {
                $open !== null => $this->openRunLine($open, $start),
                $card === null => 'No statutory rate card is in force on '.$end->format('M j, Y').'. Gemini records the rates; a run cannot be calculated without them.',
                default => null,
            },
        ];
    }

    /**
     * Start the next run on the calendar — board 13's "Start new run".
     *
     * A DRAFT, prepared by whoever pressed the button. Nothing is calculated
     * and nothing posts: the run is then taken through exceptions (board 14),
     * calculation and approval (board 15) as every run is. Refused for the
     * calendar's own reasons, so the button and the service agree.
     */
    public function startRun(User $by): PayrollRun
    {
        $calendar = $this->calendar();

        if ($calendar['blocked_by'] !== null) {
            throw new DomainException($calendar['blocked_by']);
        }

        // A retired sample run still holds its month in the unique index (M33 §5): refused here, never by the database.
        if (Retirements::seeingEverything(static fn (): bool => PayrollRun::query()->where('slug', $calendar['slug'])->exists())) {
            throw new DomainException($calendar['label'].' already has a run. A month is paid once.');
        }

        $end = Carbon::parse($calendar['period_end']);

        return PayrollRun::create([
            'reference' => 'PR-'.strtoupper(str_replace('-', '', $calendar['slug'])),
            'slug' => $calendar['slug'],
            'period_label' => $calendar['label'],
            'period_start' => $calendar['period_start'],
            'period_end' => $calendar['period_end'],
            'periods_per_year' => self::PERIODS_PER_YEAR,
            'statutory_rate_version_id' => StatutoryRateVersion::forPayDate($end)->id,
            'status' => PayrollRun::DRAFT,
            'gross_minor' => 0,
            'net_minor' => 0,
            'currency' => 'JMD',
            'prepared_by' => $by->getKey(),
            'prepared_by_name' => $by->name,
        ]);
    }

    /**
     * The next pay date, which is the end of the earliest unpaid period.
     *
     * Derived rather than stored. A stored "next pay date" is a second copy of
     * something the runs already say, and it is the copy that goes stale the
     * moment a period is added or paid. From the runs the run list already holds where it sends them.
     *
     * @param  Collection<int, PayrollRun>|null  $runs
     */
    private function nextPayDate(?Collection $runs = null): ?Carbon
    {
        $next = $runs === null
            ? PayrollRun::query()
                ->where('status', '!=', PayrollRun::PAID)
                ->orderBy('period_end')
                ->first()
            : $runs->filter(static fn (PayrollRun $run): bool => $run->status !== PayrollRun::PAID)
                ->sortBy(static fn (PayrollRun $run): int => $run->period_end->getTimestamp())
                ->first();

        return $next?->period_end;
    }

    /* ------------------------------------------------------------------ */
    /* board 14 — pre-run exceptions */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function exceptionsBoard(PayrollRun $run): array
    {
        $rows = $run->exceptions()->with('employee')->orderBy('id')->get();
        $blocking = $rows->where('status', PayrollException::UNRESOLVED);
        $calculateReason = $this->calculationRefusal($run, $blocking->count());

        return [
            'run' => [
                'id' => $run->id,
                'slug' => $run->slug,
                'period' => $run->period_label,
                'status' => $run->status,

                // What a run sent back with Request changes was sent back for, for whoever calculates it again.
                'sent_back_reason' => $run->status === PayrollRun::EXCEPTIONS ? $run->changes_requested_reason : null,
            ],
            'blocking' => $blocking->count(),
            'rows' => $rows->map(fn (PayrollException $row): array => [
                'id' => $row->id,
                'title' => ($row->employee->full_name ?? 'Employee').' · '.$this->exceptionTitle($row),
                'detail' => $row->detail,
                'status' => $row->status,
                'resolved' => ! $row->blocksCalculation(),
                'resolution_note' => $row->resolution_note,
                'actions' => $this->exceptionActions($row),
            ])->all(),

            /*
             * The board draws its "Continue to calculation" control at half
             * opacity with the reason in the label itself. The reason is
             * computed rather than fixed, because a run whose last exception
             * was just resolved has to be able to proceed without a reload
             * telling it why it cannot.
             */
            // Live exactly when nothing refuses it: paid, an exception to settle, or a card not yet in force (D-234).
            'canCalculate' => $calculateReason === null,
            'calculateReason' => $calculateReason,
        ];
    }

    /** Board 14's own two headings, by stored type. */
    private function exceptionTitle(PayrollException $row): string
    {
        return match ($row->type) {
            PayrollException::MISSING_TIMESHEET => 'missing timesheet',
            PayrollException::OVERTIME_ANOMALY => 'overtime anomaly',
            default => str_replace('_', ' ', $row->type),
        };
    }

    /**
     * The two buttons board 14 puts on each row, and they differ by kind.
     *
     * A missing timesheet is entered or the person is excluded; an overtime
     * anomaly is reviewed or approved as it stands. Both pairs resolve — see
     * `PayrollException`'s docblock on why excluding is not resolving.
     *
     * @return list<array{key: string, label: string, primary: bool}>
     */
    private function exceptionActions(PayrollException $row): array
    {
        if ($row->type === PayrollException::MISSING_TIMESHEET) {
            return [
                ['key' => 'enter', 'label' => 'Enter manually', 'primary' => true],
                ['key' => 'exclude', 'label' => 'Exclude', 'primary' => false],
            ];
        }

        return [
            ['key' => 'review', 'label' => 'Review hours', 'primary' => true],
            ['key' => 'accept', 'label' => 'Approve as-is', 'primary' => false],
        ];
    }

    /**
     * The card a calculation of this run would use, chosen by its pay date as `calculate()` chooses it, when that card has
     * not taken effect on the estate's calendar yet (D-234); null otherwise. A card in force on a pay date already past
     * took effect before it, so only a pay date still to come needs the card read. No card in force at all is
     * `calculate()`'s own refusal, with its own sentence.
     */
    private function futureCard(PayrollRun $run): ?StatutoryRateVersion
    {
        $payDate = Carbon::parse($run->period_end)->toDateString();

        if ($payDate <= Ledger::today()->toDateString()) {
            return null;
        }

        $card = StatutoryRateVersion::inForceOn($payDate);

        return $card !== null && $card->takesEffectAfter(Ledger::today()) ? $card : null;
    }

    private function calculationRefusal(PayrollRun $run, int $blocking): ?string
    {
        if ($run->status === PayrollRun::PAID) {
            return 'This run has already been paid. A pay run is calculated once; a correction is a '.
                'later run, never a recalculation of one that has already left the bank.';
        }

        // The card the calculation would use, chosen by pay date as `calculate()` chooses it, never one still to come (D-234).
        $card = $this->futureCard($run);

        if ($card !== null) {
            return $card->notYetInForceReason();
        }

        if ($blocking === 0) {
            return null;
        }

        // A sentence, with no em dash (the payroll pass, 11 Oct): it read "Continue to calculation (resolve all first) — ".
        return 'Settle the '.$blocking.' exception'.($blocking === 1 ? '' : 's').' above first. A missing timesheet '.
            'calculated anyway pays somebody for hours nobody recorded.';
    }

    /* ------------------------------------------------------------------ */
    /* board 15 — one run, and every figure on it ties */
    /* ------------------------------------------------------------------ */

    /**
     * What a run's payslips order by (M32 F5): the largest gross first by default, board 15's own order.
     *
     * @return array<string, string>
     */
    public static function lineSorts(): array
    {
        return [
            'name' => 'name',
            'role' => 'role',
            'gross' => 'gross_minor',
            'nis' => 'nis_minor',
            'nht' => 'nht_minor',
            'education_tax' => 'education_tax_minor',
            'paye' => 'paye_minor',
            'net' => 'net_minor',
        ];
    }

    public static function linesTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::lineSorts(), 'gross', 'desc');
    }

    /**
     * Board 15: the four boxes, summed from every line, and the lines as a table ordered by the server and paged
     * (M32 F5).
     *
     * @return array<string, mixed>
     */
    public function runBoard(PayrollRun $run, ?User $viewer = null, ?TableQuery $table = null): array
    {
        $table ??= self::linesTable();
        $lines = $run->lines()->with('employee')->orderByDesc('gross_minor')->get();

        /*
         * SUMMED FROM THE LINES, NOT READ FROM THE HEADER. Board 15's four
         * summary boxes and its seven columns are the same figures twice, and
         * the whole point of this screen is that they agree. Reading the header
         * for the boxes and the lines for the table would make them agree by
         * construction and prove nothing.
         */
        $gross = (int) $lines->sum('gross_minor');
        $net = (int) $lines->sum('net_minor');
        $deductions = $gross - $net;

        $rows = $lines->map(fn (PayrollRunLine $line): array => [
            'id' => $line->id,
            'name' => $line->employee->full_name ?? 'Employee',
            'initials' => $this->initials($line->employee->full_name ?? ''),
            'role' => $line->employee->job_title ?? '',
            'gross_minor' => $line->gross_minor,
            'nis_minor' => $line->nis_minor,
            'nht_minor' => $line->nht_minor,
            'education_tax_minor' => $line->education_tax_minor,
            'paye_minor' => $line->paye_minor,
            'net_minor' => $line->net_minor,

            /*
             * Why a zero is a zero. Simone Clarke's PAYE is nil because her
             * pay is under the threshold, and a bare 0 on a payslip reads
             * as a defect to the person holding it. `PayrollCalculator`
             * writes the sentence; this passes it through.
             */
            'paye_note' => $line->paye_note,
        ])->values()->all();

        // The payslips' search (M32 F5): who, and what they do.
        if ($table->search !== '') {
            $needle = mb_strtolower($table->search);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower($row['name'].' '.$row['role']), $needle)));
        }

        $page = $table->pageOf($rows);

        $refusal = $this->approvalRefusal($run, $viewer);
        $holds = $viewer !== null && $viewer->can('estate.payroll.approve_runs');

        // The viewer calculated it and is the only approver: the line's three short lines say the rule (D-233).
        $calculatedBy = $run->status === PayrollRun::CALCULATED && $run->calculated_by !== null ? (int) $run->calculated_by : null;
        $ruleInLine = $viewer !== null && $calculatedBy === (int) $viewer->getKey()
            && array_diff_key($this->estateAccountsThatCanEach([self::APPROVES, self::CALCULATES])[self::APPROVES], [$calculatedBy => true]) === [];

        return [
            'run' => [
                'id' => $run->id,
                'slug' => $run->slug,
                'period' => $run->period_label,
                'status' => $run->status,
                'prepared_by' => $run->prepared_by_name,

                // Who worked out the figures, where that is recorded (B025) — the banner names both.
                'calculated_by' => $run->calculated_by_name,
                'approved_by' => $run->approved_by_name,
                // The estate's own date, in words (Law 5): the banner read "Approved by Dahlia Reid on 2026-09-02".
                'approved_at' => $run->approved_at === null ? null : SiteTime::local($run->approved_at)->format('M j, Y'),

                // Sent back with Request changes, and what was asked, until it is calculated again (the way out's middle step).
                'sent_back_reason' => $run->status === PayrollRun::EXCEPTIONS ? $run->changes_requested_reason : null,
            ],

            'summary' => [
                ['key' => 'employees', 'value' => (string) $lines->count(), 'label' => 'Employees'],
                ['key' => 'gross', 'value_minor' => $gross, 'label' => 'Total gross'],
                ['key' => 'deductions', 'value_minor' => $deductions, 'label' => 'Total deductions'],
                ['key' => 'net', 'value_minor' => $net, 'label' => 'Total net pay'],
            ],

            'lines' => $page->items(),
            'table' => $table->meta($page),

            'canApprove' => $this->mayApprove($run, $viewer),
            'approvalReason' => $refusal,

            // M96 §1: whether this viewer's role ever approves a run (a role that never does sees no Approve button).
            'holdsApproval' => $holds,

            /*
             * WHO APPROVES AND WHAT STOPS THEM, ON THE PAGE BEFORE ANYBODY PRESSES ANYTHING (Hassam, 12 Oct 2026): one of
             * his three cases for every viewer, so no page is a table and nothing else (the President's, tonight).
             */
            'approvalNext' => $this->approvalNext($run, $viewer),

            // Any other refusal an approver meets, on the screen under it: the line says the two-person rule itself when
            // the viewer calculated it and nobody else can approve it, so it is not said twice.
            'approvalBlock' => $holds && ! $ruleInLine ? $refusal : null,
            'canRequestChanges' => $viewer !== null && $viewer->can('estate.payroll.update'),

            // What was decided on the run's exceptions, for the approver to weigh (D-233), never a reason to bar them.
            'decisions' => $this->decisionsOn($run),

            /*
             * Part F of the Q-002 ruling. On the estate's first live run the
             * approver ticks a sentence naming the card the run was reconciled
             * against; the server refuses the approval without it, so the tick
             * cannot be skipped by posting straight to the route.
             */
            'acknowledgement' => [
                'required' => $run->status === PayrollRun::CALCULATED && $this->needsReconciliationAcknowledgement(),
                'statement' => $this->reconciliationStatement($run),
            ],
        ];
    }

    /**
     * WHAT WAS DECIDED ON THE RUN, FOR THE APPROVER TO WEIGH (Hassam, 12 Oct 2026; D-233). Settling an exception used to
     * count against whoever did it and locked half a committee out of approving. A decision is a judgement the approver
     * should see instead, plainly: "Dahlia Reid excluded Neil Anderson from this run, missing timesheet." Oldest first.
     *
     * @return list<string>
     */
    private function decisionsOn(PayrollRun $run): array
    {
        return $run->exceptions()->with('employee')
            ->whereIn('status', [PayrollException::RESOLVED, PayrollException::EXCLUDED])
            ->orderBy('resolved_at')->orderBy('id')->get()
            ->map(static function (PayrollException $exception): string {
                $who = $exception->resolved_by_name ?? 'Somebody';
                $name = $exception->employee->full_name;

                if ($exception->status === PayrollException::EXCLUDED) {
                    return sprintf('%s excluded %s from this run, %s.', $who, $name, self::EXCEPTION_WORDS[$exception->type] ?? str_replace('_', ' ', $exception->type));
                }

                if ($exception->type === PayrollException::OVERTIME_ANOMALY) {
                    $hours = match (true) {
                        $exception->overtime_hours === null => '',
                        $exception->overtime_baseline_hours === null => sprintf(': %d hours', $exception->overtime_hours),
                        default => sprintf(': %d hours against a usual %d', $exception->overtime_hours, $exception->overtime_baseline_hours),
                    };

                    return sprintf("%s accepted %s's overtime as logged%s.", $who, $name, $hours);
                }

                return sprintf('%s kept %s in this run without a timesheet.', $who, $name);
            })->values()->all();
    }

    private function initials(string $name): string
    {
        $parts = array_values(array_filter(explode(' ', $name)));

        return strtoupper(implode('', array_map(
            static fn (string $part): string => $part[0] ?? '',
            array_slice($parts, 0, 2),
        )));
    }

    /* ------------------------------------------------------------------ */
    /* board 16 — statutory filings */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function filingsBoard(): array
    {
        $today = Ledger::today();

        /*
         * WARNINGS FIRST, then by due date — board 16's own order, with the amber
         * row at the top. Which rows warn is worked out from the dates below, so
         * the order is too.
         */
        $filings = StatutoryFiling::query()
            ->with('run')
            ->orderBy('due_on')
            ->orderBy('form_code')
            ->get()
            ->sortBy(fn (StatutoryFiling $filing): int => $this->filingState($filing, $today) === 'overdue'
                || $this->filingState($filing, $today) === 'due_soon' ? 0 : 1)
            ->values();

        return [
            'rows' => $filings->map(function (StatutoryFiling $filing) use ($today): array {
                $state = $this->filingState($filing, $today);

                return [
                    'id' => $filing->id,
                    'code' => $filing->form_code,
                    // The console's separator, never an em dash (Law 3): it read "S01 — Statutory Deduction Remittance".
                    'title' => $filing->form_code.' · '.$filing->form_title,
                    'period' => $filing->period_label,
                    'subtitle' => $this->filingSubtitle($filing),
                    'due' => $filing->filed_on === null
                        ? 'Due '.$filing->due_on->format('M j').($filing->due_on->year === $today->year ? '' : ', '.$filing->due_on->year)
                        : 'Filed '.$filing->filed_on->format('M j'),
                    'status' => $filing->status,

                    // What the badge says, from the dates — not the stored status (B037) — in the one vocabulary (M96,
                    // M99): a late return is Action required with Overdue as a flag beside it, never instead of a state.
                    'state' => $state,
                    'status_label' => match ($state) {
                        'filed' => 'Completed',
                        'overdue', 'due_soon' => 'Action required',
                        default => 'Pending',
                    },
                    'flag' => $state === 'overdue' ? 'Overdue' : null,
                    'warn' => $state === 'overdue' || $state === 'due_soon',
                    'total_minor' => $filing->total_minor,

                    // Why File cannot be pressed yet, where it cannot: the pay run it remits for is unpaid (B026).
                    'file_blocked' => $filing->status !== StatutoryFiling::FILED && $filing->run !== null && ! $filing->run->isPosted()
                        ? 'The '.$filing->run->period_label.' pay run has not been approved, so nothing has been withheld for this return to remit. Approve the run first.'
                        : null,

                    // The pay run it remits for, so a return waiting on that run opens it from its status (batch 1 item 10).
                    'run_slug' => $filing->run?->slug,

                    // The same reason short enough to sit on the row, readable without hovering (M96 level 2).
                    'file_blocked_short' => $filing->status !== StatutoryFiling::FILED && $filing->run !== null && ! $filing->run->isPosted()
                        ? 'Approve the '.$filing->run->period_label.' pay run first.'
                        : null,
                ];
            })->all(),
        ];
    }

    /** How near a return's due date has to be, once its period has closed, for board 16 to say "Due soon". */
    private const DUE_SOON_DAYS = 30;

    /**
     * What board 16 says about a return, which is a question about dates (bug hunt B037).
     *
     * The badge used to be the stored status, and nothing ever moves that by
     * date — the seeder set August's S01 to "due soon", and ten days after it
     * fell due the board still said so while the compliance calendar said
     * "Overdue". Now: filed is filed; a return past its due date is overdue; one
     * whose period has closed and which falls due within thirty days is due soon;
     * anything else is not started — the annual P24, due in March, is not "due
     * soon" in September. A return is due all of its due day, on the estate's
     * calendar.
     */
    private function filingState(StatutoryFiling $filing, Carbon $today): string
    {
        return match (true) {
            $filing->status === StatutoryFiling::FILED => 'filed',
            $filing->due_on->lt($today) => 'overdue',
            $filing->period_end->lt($today) && $filing->due_on->lte($today->copy()->addDays(self::DUE_SOON_DAYS)) => 'due_soon',
            default => 'not_started',
        };
    }

    /**
     * The monthly S01 and S02 fall due on this day of the month after the
     * period — the rule board 16's own dates follow (August's S01 is due
     * September 14). A PROJECTION ONLY: a return on the register keeps the
     * `due_on` it was given, because a rule that changes by statute must not
     * move a date that has already been owed (see the model).
     */
    private const MONTHLY_RETURN_DUE_DAY = 14;

    /** How far ahead the compliance calendar looks. */
    private const CALENDAR_MONTHS = 12;

    /**
     * Board 16's "Compliance calendar" (12 §2, Wave 4). No board draws it.
     *
     * TWO SOURCES, AND EVERY ROW SAYS WHICH. The register holds the returns
     * that exist, each with the due date it was given; the calendar adds the
     * monthly S01 and S02 for each period in the year ahead that the register
     * does not hold yet, dated by the rule above and marked "projected". A
     * projected row is a reminder, not a return — nothing is written for it.
     *
     * NO RULE IS INVENTED. The GCT return and the annual P24 are shown when the
     * register holds them, with their stored dates; neither is projected,
     * because no rule for either is on record here and a date guessed onto a
     * compliance calendar is a date somebody trusts.
     *
     * AN ESTATE WITH NO PAYROLL OWES NO MONTHLY RETURNS, so nothing is
     * projected until it has a run or an active employee.
     *
     * @return array{months: list<array{label: string, rows: list<array<string, mixed>>}>, projects: bool, overdue: int}
     */
    public function complianceCalendar(?Carbon $today = null): array
    {
        $today = ($today ?? Ledger::today())->copy()->startOfDay();

        $register = StatutoryFiling::query()->orderBy('due_on')->get();

        $projects = PayrollRun::query()->exists()
            || Employee::query()->where('status', Employee::ACTIVE)->exists();

        $rows = [];

        // Outstanding returns on the register, whenever they fall due — an
        // overdue one is the first thing a calendar has to show — and filed
        // ones due inside the window, so a month reads as done.
        $windowStart = $today->copy()->startOfMonth();
        $windowEnd = $windowStart->copy()->addMonthsNoOverflow(self::CALENDAR_MONTHS)->subDay();

        foreach ($register as $filing) {
            if ($filing->status === StatutoryFiling::FILED
                && ($filing->due_on->lt($windowStart) || $filing->due_on->gt($windowEnd))) {
                continue;
            }

            $rows[] = [
                'key' => 'filing-'.$filing->id,
                'code' => $filing->form_code,
                // The console's separator, never an em dash (Law 3; the payroll pass).
                'title' => $filing->form_code.' · '.$filing->form_title,
                'period' => $filing->period_label,
                'due_on' => $filing->due_on->toDateString(),
                'due' => $filing->due_on->format('D, M j, Y'),
                'source' => 'register',
                'state' => match (true) {
                    $filing->status === StatutoryFiling::FILED => 'filed',
                    $filing->due_on->lt($today) => 'overdue',
                    default => 'outstanding',
                },
                // The one vocabulary (M96, M99), the date where it is known; Overdue a flag beside the state.
                'state_label' => match (true) {
                    $filing->status === StatutoryFiling::FILED => 'Completed'.($filing->filed_on === null ? '' : ' · '.$filing->filed_on->format('M j')),
                    $filing->due_on->lt($today) => 'Action required',
                    default => 'Pending',
                },
                'flag' => $filing->status !== StatutoryFiling::FILED && $filing->due_on->lt($today) ? 'Overdue' : null,
            ];
        }

        if ($projects) {
            $held = $register
                ->map(static fn (StatutoryFiling $f): string => $f->form_code.'@'.$f->period_start->format('Y-m'))
                ->flip();

            // No return is owed for a month before this estate's first payroll.
            $firstRun = PayrollRun::query()->min('period_start');
            $firstPeriod = $firstRun === null ? $today->copy()->startOfMonth() : Carbon::parse((string) $firstRun)->startOfMonth();

            // The period whose returns fall due this month, and the eleven after.
            $period = $today->copy()->startOfMonth()->subMonthNoOverflow();

            for ($i = 0; $i < self::CALENDAR_MONTHS; $i++, $period->addMonthNoOverflow()) {
                if ($period->lt($firstPeriod)) {
                    continue;
                }

                $due = $period->copy()->addMonthNoOverflow()->day(self::MONTHLY_RETURN_DUE_DAY);

                foreach ([StatutoryFiling::S01 => 'Statutory Deduction Remittance', StatutoryFiling::S02 => 'Monthly Reconciliation'] as $code => $title) {
                    if ($held->has($code.'@'.$period->format('Y-m'))) {
                        continue;
                    }

                    $rows[] = [
                        'key' => 'projected-'.$code.'-'.$period->format('Y-m'),
                        'code' => $code,
                        'title' => $code.' · '.$title,
                        'period' => $period->format('F Y'),
                        'due_on' => $due->toDateString(),
                        'due' => $due->format('D, M j, Y'),
                        'source' => 'projected',
                        'state' => $due->lt($today) ? 'overdue' : 'projected',
                        // The page says a projected row is a reminder, not a return, beside its period.
                        'state_label' => $due->lt($today) ? 'Action required' : 'Pending',
                        'flag' => $due->lt($today) ? 'Overdue' : null,
                    ];
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['due_on'], $a['code']] <=> [$b['due_on'], $b['code']]);

        $months = [];

        foreach ($rows as $row) {
            $label = Carbon::parse($row['due_on'])->format('F Y');
            $months[$label] ??= ['label' => $label, 'rows' => []];
            $months[$label]['rows'][] = $row;
        }

        return [
            'months' => array_values($months),
            'projects' => $projects,
            'overdue' => count(array_filter($rows, static fn (array $row): bool => $row['state'] === 'overdue')),
        ];
    }

    /**
     * "August 2026 · NIS, NHT, Education Tax, PAYE" — the period, and what the
     * return covers where it covers deductions.
     */
    private function filingSubtitle(StatutoryFiling $filing): string
    {
        if ($filing->remittanceMinor() === 0) {
            return $filing->period_label;
        }

        // The ruling's own list: an S01 covers PAYE, NIS, NHT, Education Tax
        // and HEART — the employer's share on the same return (D-083).
        return $filing->period_label.' · PAYE, NIS, NHT, Education Tax, HEART';
    }

    /* ------------------------------------------------------------------ */
    /* board 37 — the employee register */
    /* ------------------------------------------------------------------ */

    /**
     * Put somebody on the payroll — board 37's "Add employee" (12 §1).
     *
     * THE CONSENT CHECKBOX IS THE RULING AND IT IS NOT DECORATION. A bank
     * account number and an NIS number are personal data the estate is about to
     * hold for seven years, and the old inert reason was right that this
     * console had no consented intake for them. The consent is what makes the
     * intake one: it is required, it is recorded with the person who took it
     * and the moment they did, and without it the write is refused — not
     * accepted-with-a-warning, refused.
     *
     * THE BANK DETAILS ARE OPTIONAL, THE CONSENT IS NOT. An employee can be put
     * on the register before their bank sends the account — payroll then pays
     * them by another arrangement — and a register that refused to list them
     * would understate the estate's wage bill. What is never optional is having
     * asked before holding what they did give.
     *
     * NOTHING HERE POSTS. An employee is a register row; the journal is raised
     * when a run is approved, against the rate in force on that day.
     *
     * @param  array<string, mixed>  $fields
     */
    public function addEmployee(array $fields, User $by): Employee
    {
        $name = trim((string) ($fields['full_name'] ?? ''));
        $title = trim((string) ($fields['job_title'] ?? ''));

        if ($name === '' || $title === '') {
            throw new DomainException('An employee has a name and a job title. The title is what a payslip and the payroll journal both print.');
        }

        if (! ($fields['consent'] ?? false)) {
            throw new DomainException(
                'Tick the consent box. A bank account number and an NIS number are this person\'s data, held for seven '.
                'years, and the estate records that they were asked before it holds any of it.'
            );
        }

        if (Employee::query()->whereRaw('LOWER(full_name) = ?', [strtolower($name)])->where('status', Employee::ACTIVE)->exists()) {
            throw new DomainException($name.' is already on the payroll. Two rows for one person is two salaries and two sets of deductions.');
        }

        $rate = $this->amountTyped(
            (string) ($fields['monthly_rate'] ?? '0'),
            'A monthly rate is dollars and cents, at most two decimal places, as "185000.00".',
        );

        if ($rate->isNegativeOrZero()) {
            throw new DomainException('A monthly rate is a positive amount. It is what every run gross-to-net works down from.');
        }

        /*
         * Q-017, ruled: zero for everybody today, and never more than the pay it
         * comes out of — a contribution larger than the monthly rate would make
         * statutory income negative.
         */
        $pension = $this->amountTyped(
            (string) (($fields['approved_pension'] ?? '') === '' ? '0' : $fields['approved_pension']),
            'An approved pension contribution is dollars and cents, at most two decimal places, as "4000.00".',
        );

        if ($pension->isNegative() || $pension->isGreaterThan($rate)) {
            throw new DomainException('An approved pension contribution is between zero and the monthly rate it is deducted from.');
        }

        $this->refuseUnpayablePension($name, $rate->getMinorAmount()->toInt(), $pension->getMinorAmount()->toInt());

        $nis = trim((string) ($fields['nis_number'] ?? ''));
        $account = trim((string) ($fields['bank_account_number'] ?? ''));
        $bank = trim((string) ($fields['bank_name'] ?? ''));

        if (($account === '') !== ($bank === '')) {
            throw new DomainException('A bank account needs both the bank and the number, or neither. Half of one pays nobody.');
        }

        return DB::connection('tenant')->transaction(function () use ($name, $title, $fields, $rate, $pension, $nis, $account, $bank, $by): Employee {
            $employee = Employee::create([
                'full_name' => $name,
                'job_title' => $title,
                'employment_type' => (string) ($fields['employment_type'] ?? Employee::FULL_TIME),
                'bank_name' => $bank === '' ? null : $bank,
                'bank_account_number' => $account === '' ? null : $account,
                'nis_number' => $nis === '' ? null : $nis,
                'monthly_rate_minor' => $rate->getMinorAmount()->toInt(),
                'approved_pension_minor' => $pension->getMinorAmount()->toInt(),
                'currency' => 'JMD',
                // Today is the estate's day: somebody taken on at 8 PM in Kingston started that day (B023).
                'employed_since' => ($fields['employed_since'] ?? null) ?: Ledger::today()->toDateString(),
                'status' => Employee::ACTIVE,
            ]);

            /*
             * THE CONSENT IS AUDITED, not stored as a boolean on the row. A
             * flag says "yes" forever and says nothing about when, or who was
             * standing there; the audit entry names the officer, the moment and
             * the employee, which is what a data-protection question a year
             * from now actually asks.
             */
            $this->audit->record(
                action: 'estate.employee_added',
                entityType: 'Employee',
                entityId: (string) $employee->id,
                after: [
                    'name' => $name,
                    'consent_taken_by' => (string) $by->name,
                    'consent_at' => Carbon::now()->toDateTimeString(),
                    'holds_bank_details' => $account !== '',
                    'holds_nis' => $nis !== '',
                ],
            );

            return $employee;
        });
    }

    /**
     * A pension no payslip can bear, refused when the employee is added (bug hunt B032).
     *
     * "Never more than the monthly rate" was the only rule, and the calculator
     * asks more: a pension comes out of what is left after NIS, and the payslip
     * still has NHT and Education Tax to pay after it. A pension equal to the
     * salary was stored, and every Calculate for every run after it was a 500,
     * with no screen that could change the record; one just under the ceiling
     * gave a payslip below nothing. So the payslip is worked out now, on the card
     * in force today, and a pension that breaks it is refused on the field.
     *
     * Where no card is in force there is nothing to work it out on, and the
     * calculation asks the same question later, naming the employee.
     */
    private function refuseUnpayablePension(string $name, int $rateMinor, int $pensionMinor): void
    {
        if ($pensionMinor === 0) {
            return;
        }

        $rates = StatutoryRateVersion::inForceOn(Ledger::today()->toDateString());

        if ($rates === null) {
            return;
        }

        try {
            $slip = app(PayrollCalculator::class)->payslip($rateMinor, $rates, self::PERIODS_PER_YEAR, $pensionMinor);
        } catch (RuntimeException) {
            throw new DomainException($this->unpayablePension($name, $rateMinor, $pensionMinor));
        }

        if ($slip['net_minor'] < 0) {
            throw new DomainException($this->unpayablePension($name, $rateMinor, $pensionMinor));
        }
    }

    /** The sentence both refusals of an unpayable pension carry — the person, the pension, the pay. */
    private function unpayablePension(string $name, int $rateMinor, int $pensionMinor): string
    {
        return sprintf(
            'An approved pension contribution of J$%s leaves too little of %s\'s J$%s a month to pay the statutory '.
            'deductions from: NIS, NHT and Education Tax come out of the same pay, and the payslip would come out '.
            'below nothing. A pension is taken from what is left after them.',
            number_format($pensionMinor / 100, 2),
            $name,
            number_format($rateMinor / 100, 2),
        );
    }

    /**
     * An amount as the office typed it, in JMD — or the sentence that says why not.
     *
     * `Money::of` refuses a value it cannot hold exactly ("185000.005") and one
     * whose minor units do not fit an integer, with a `MathException` (bug hunt
     * B022, B081). Uncaught, that was a 500 for one key too many; the controller
     * now refuses both first, and this keeps any other caller from reaching the
     * arithmetic with them — the same conversion `ChargeSchedules::amendRate()`
     * makes.
     */
    private function amountTyped(string $typed, string $refusal): Money
    {
        try {
            $money = Money::of($typed, 'JMD');
            $money->getMinorAmount()->toInt();
        } catch (MathException) {
            throw new DomainException($refusal);
        }

        return $money;
    }

    /**
     * What the employee register orders by (M32 F5): name order by default — it is a register. The masked bank and NIS
     * numbers are not offered: an order over them would be an order over the numbers they hide.
     *
     * @return array<string, string>
     */
    public static function employeeSorts(): array
    {
        return [
            'name' => 'name',
            'role' => 'role',
            'type' => 'type',
            'since' => 'since_sort',
            'rate' => 'rate_minor',
            'status' => 'status',
        ];
    }

    public static function employeesTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::employeeSorts(), 'name');
    }

    /**
     * Board 37: the register as a table ordered by the server and paged (M32 F5).
     *
     * @return array<string, mixed>
     */
    public function employeesBoard(?TableQuery $table = null): array
    {
        $table ??= self::employeesTable();
        $employees = Employee::query()->orderBy('id')->get();

        $rows = $employees->map(fn (Employee $employee): array => [
            'id' => $employee->id,
            'name' => $employee->full_name,
            'initials' => $this->initials($employee->full_name),
            'since' => 'Employed since '.$employee->employed_since->format('M Y'),
            'since_sort' => $employee->employed_since->toDateString(),
            'role' => $employee->job_title,
            // The words the form offers (the payroll pass): a fixed-term employee read "Part-time".
            'type' => match ($employee->employment_type) {
                'full_time' => 'Full time',
                'part_time' => 'Part time',
                'contract' => 'Fixed term',
                default => ucfirst(str_replace('_', ' ', (string) $employee->employment_type)),
            },

            /*
             * MASKED AT THE BOUNDARY, never sent whole. A bank account
             * number and an NIS number both identify a person to their
             * bank and to the revenue authority; a register that lists
             * four staff does not need either in full, and a payload is
             * readable in a browser's network tab by anyone at the desk.
             */
            'bank' => $this->maskedBank($employee),
            'nis' => $this->maskedNis($employee),
            'rate_minor' => $employee->monthly_rate_minor,
            'status' => $employee->status,
        ])->values()->all();

        // The register's search (M32 F5): who, and what they do.
        if ($table->search !== '') {
            $needle = mb_strtolower($table->search);
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower($row['name'].' '.$row['role']), $needle)));
        }

        $page = $table->pageOf($rows);

        return [
            'rows' => $page->items(),
            'table' => $table->meta($page),
        ];
    }

    /** "NCB •••• 3315". */
    private function maskedBank(Employee $employee): string
    {
        if ($employee->bank_account_number === null) {
            return '—';
        }

        return trim(($employee->bank_name ?? '').' •••• '.substr($employee->bank_account_number, -4));
    }

    /** "••• •••5 208" — the board's own shape, last four with its own spacing. */
    private function maskedNis(Employee $employee): string
    {
        if ($employee->nis_number === null) {
            return '—';
        }

        $tail = substr(preg_replace('/\D/', '', $employee->nis_number) ?? '', -4);

        return '••• •••'.substr($tail, 0, 1).' '.substr($tail, 1);
    }

    /* ------------------------------------------------------------------ */
    /* the writes */
    /* ------------------------------------------------------------------ */

    /**
     * Work out every payslip in a run and store them.
     *
     * REFUSED WHILE ANY EXCEPTION IS UNRESOLVED. An excluded employee is left
     * out entirely rather than given a zero line, which is why board 13's May
     * run has three employees against the other months' four and differs by
     * exactly one person's figures.
     *
     * WHOEVER CALCULATES IS RECORDED (bug hunt B025). The figures are made
     * here, not when the run was started, so this is the preparer the second-
     * approver control has to keep away from the approval; see
     * `approvalRefusal()`. `$by` is null only for a caller with nobody behind
     * it, and then the run's starter stands as its preparer.
     */
    public function calculate(PayrollRun $run, ?User $by = null): PayrollRun
    {
        $this->refuseCalculation($run);

        /*
         * THE CARD IN FORCE ON THE PAY DATE, chosen now rather than when the run
         * was created. The threshold changes every 1 April and income tax is
         * assessed on a calendar year, so a March run and an April run are taxed
         * on different cards; the pay date is the period end (D-082).
         */
        $rates = StatutoryRateVersion::forPayDate($run->period_end);
        $calculator = app(PayrollCalculator::class);

        return DB::connection('tenant')->transaction(function () use ($run, $rates, $calculator, $by): PayrollRun {
            /*
             * THE RUN AS IT STANDS, UNDER A LOCK (bug hunt B020, B075). A copy
             * loaded before the run was approved used to pass the check above
             * and delete the payslips of a run whose money had already left the
             * bank. The lock is the one approval takes, so the two cannot run
             * side by side, and the checks are asked again of what it holds.
             */
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            $this->refuseCalculation($locked);

            $excluded = $locked->exceptions()
                ->where('status', PayrollException::EXCLUDED)
                ->pluck('employee_id')
                ->all();

            // Recalculating replaces the previous attempt outright. These are
            // not ledger rows — nothing has been posted while a run is still
            // being worked out — so a stale line from an earlier attempt would
            // simply be a payslip for a figure nobody is going to be paid.
            $run->lines()->delete();

            $gross = 0;
            $net = 0;

            /*
             * NOBODY IS PAID FOR A MONTH THEY HAD NOT STARTED. Wayne Thomas was
             * employed from June 2026, and a run for May that included him
             * would pay a man J$88,000 for a month he did not work there — and
             * would do it quietly, because every total on every screen would
             * still add up. Board 13 says so in its own figures: May is drawn
             * with three employees where the other months have four, and the
             * variance is exactly his gross and his net.
             *
             * Measured against the period END. Somebody who started on the 20th
             * is on that month's payroll; what they are paid for a part month is
             * a timesheet question, and a timesheet that is missing is what
             * board 14's first exception is for.
             */
            $employees = Employee::query()
                ->where('status', Employee::ACTIVE)
                ->where('employed_since', '<=', $run->period_end)
                ->whereNotIn('id', $excluded)
                ->orderBy('id')
                ->get();

            /*
             * ACCEPTED OVERTIME IS PAID (EST-B-07, M85 A2). Each resolved overtime exception's hours, at time and a
             * half of the hourly rate, on top of the month. An excluded exception has already left the person out
             * of the run; an unresolved one refused it.
             */
            $overtimeHours = $locked->exceptions()
                ->where('type', PayrollException::OVERTIME_ANOMALY)
                ->where('status', PayrollException::RESOLVED)
                ->whereNotNull('overtime_hours')
                ->get(['employee_id', 'overtime_hours'])
                ->groupBy('employee_id')
                ->map(static fn ($rows): int => (int) $rows->sum('overtime_hours'));

            /*
             * HEART is decided on the payroll, not the person — the employer's
             * whole monthly payroll against a statutory floor (Q-016).
             */
            $heartApplies = $rates->heartAppliesTo(
                (int) $employees->sum('monthly_rate_minor'),
                self::PERIODS_PER_YEAR,
            );

            foreach ($employees as $employee) {
                /*
                 * ONE EMPLOYEE NAMED, NOT A 500 (bug hunt B032). The calculator
                 * refuses a pension larger than what is left after NIS, and a
                 * pension just under that leaves the payslip below nothing once
                 * NHT and Education Tax are taken. Either way the whole run is
                 * refused — the transaction takes back every line written so far
                 * — with the one person whose record is the reason.
                 */
                $grossMinor = $employee->monthly_rate_minor
                    + $this->overtimePay($calculator, $employee->monthly_rate_minor, (int) ($overtimeHours[$employee->id] ?? 0));

                try {
                    $slip = $calculator->payslip(
                        $grossMinor,
                        $rates,
                        self::PERIODS_PER_YEAR,
                        $employee->approved_pension_minor,
                    );

                    $employer = $calculator->employerCost(
                        $grossMinor,
                        $rates,
                        self::PERIODS_PER_YEAR,
                        $heartApplies,
                        $employee->approved_pension_minor,
                    );
                } catch (RuntimeException) {
                    throw new DomainException($this->unpayablePension($employee->full_name, $employee->monthly_rate_minor, $employee->approved_pension_minor).
                        ' No payslip in this run can be calculated until that record is corrected.');
                }

                if ($slip['net_minor'] < 0) {
                    throw new DomainException($this->unpayablePension($employee->full_name, $employee->monthly_rate_minor, $employee->approved_pension_minor).
                        ' No payslip in this run can be calculated until that record is corrected.');
                }

                $run->lines()->create([
                    'employee_id' => $employee->id,
                    'gross_minor' => $slip['gross_minor'],
                    'nis_minor' => $slip['nis_minor'],
                    'nht_minor' => $slip['nht_minor'],
                    'education_tax_minor' => $slip['education_tax_minor'],
                    'paye_minor' => $slip['paye_minor'],
                    'pension_minor' => $slip['pension_minor'],
                    'net_minor' => $slip['net_minor'],

                    // The estate's own share. Stored beside the slip because the
                    // S01 remits it and 2100 carries it, and never shown to the
                    // employee as a deduction — none of it came out of their pay.
                    'employer_nis_minor' => $employer['nis_minor'],
                    'employer_nht_minor' => $employer['nht_minor'],
                    'employer_education_tax_minor' => $employer['education_tax_minor'],
                    'employer_heart_minor' => $employer['heart_minor'],

                    'currency' => $employee->currency,
                    'paye_note' => $slip['paye_note'],
                ]);

                $gross += $slip['gross_minor'];
                $net += $slip['net_minor'];
            }

            /*
             * The card this run was calculated against is stored on it, so the
             * run reproduces exactly and approval checks the card the payslips
             * actually came from rather than whatever is current.
             */
            $locked->forceFill([
                'status' => PayrollRun::CALCULATED,
                'statutory_rate_version_id' => $rates->id,
                'gross_minor' => $gross,
                'net_minor' => $net,
                'calculated_by' => $by?->getKey(),
                'calculated_by_name' => $by?->name,
                'calculated_at' => now(),
            ])->save();

            return $run->refresh();
        });
    }

    /**
     * What accepted overtime adds to a month (EST-B-07, M85 A2): the hours at time and a half of the monthly rate over a
     * 40-hour week, worked by the engine that works a guard's overtime (`PayrollCalculator::earnings`), each line
     * rounded once. 88,000.00 a month is 507.69 an hour; 22 hours at 761.54 is 16,753.88.
     */
    private function overtimePay(PayrollCalculator $calculator, int $monthlyRateMinor, int $hours): int
    {
        if ($hours <= 0) {
            return 0;
        }

        $hourlyMinor = (int) round($monthlyRateMinor * self::PERIODS_PER_YEAR / self::STANDARD_HOURS_A_YEAR);

        return $calculator->earnings(0.0, (float) $hours, $hourlyMinor)['overtime_minor'];
    }

    /** Why this run cannot be calculated as it stands — asked of the caller's copy, then of the locked row. */
    private function refuseCalculation(PayrollRun $run): void
    {
        if ($run->isPosted()) {
            throw new DomainException(
                'This run has already been paid. A pay run is calculated once; a correction is a later '.
                'run, never a recalculation of one whose money has already left the bank.'
            );
        }

        // Never against a card that has not taken effect (Hassam, 12 Oct 2026; D-234).
        $card = $this->futureCard($run);

        if ($card !== null) {
            throw new DomainException($card->notYetInForceReason());
        }

        $blocking = $run->exceptions()->where('status', PayrollException::UNRESOLVED)->count();

        if ($blocking > 0) {
            throw new DomainException($this->calculationRefusal($run, $blocking) ?? 'This run cannot be calculated yet.');
        }
    }

    /**
     * Whether this viewer may approve THIS run — permission and person, both.
     */
    public function mayApprove(PayrollRun $run, ?User $viewer): bool
    {
        return $this->approvalRefusal($run, $viewer) === null;
    }

    /**
     * Why not, in the words the screen prints on the control.
     *
     * Never a bare disabled button. Each of these is a different person's
     * problem to solve and saying which is the difference between a control
     * somebody can act on and one that looks broken.
     */
    public function approvalRefusal(PayrollRun $run, ?User $viewer): ?string
    {
        if ($run->isPosted()) {
            return 'This run has already been approved and paid.';
        }

        // One line, and it says what comes first (DR-032; M73 §1: a deliberate stopping point, never an error).
        if ($run->status !== PayrollRun::CALCULATED) {
            /*
             * SENT BACK WITH REQUEST CHANGES (the payroll audit, 11 Oct): its earlier payslips are still on the screen, so
             * "there are no payslips" would be untrue. It says what the way out needs next: calculating again.
             */
            if ($run->status === PayrollRun::EXCEPTIONS && $run->changes_requested_reason !== null) {
                return 'This run was sent back with Request changes, so it must be calculated again before it can be approved.';
            }

            return 'Calculate the run first; there are no payslips to approve yet.';
        }

        // Its own approval since M11 B3 (D-150): filing a return is another.
        if ($viewer === null || ! $viewer->can('estate.payroll.approve_runs')) {
            return 'Approving a pay run needs the Approve pay runs permission on Payroll & HR.';
        }

        /*
         * THE SECOND-PERSON CONTROL, ONE ACT (Hassam, 12 Oct 2026; D-233): whoever performed the run's final
         * calculation does not approve it, whoever they are; the Community Super Admin is not exempt, nobody is.
         * It is separate from the permission. `calculated_by` is written by every calculation, so it is the last one.
         *
         * NOTHING ELSE COUNTS. It also barred whoever started the run and whoever settled one of its exceptions, so in
         * an estate with three staff nobody could approve ("You resolved an exception on this run", the client going
         * round the loop for twenty minutes). Starting a run makes an empty draft, and an exception's decision is a
         * judgement the approver weighs: `runBoard()` lists each one on the page, by name, for them to read.
         */
        // One line (M53 Law 2): who may not, and that somebody else does. Why is the policy's, not the button's.
        if ($run->calculated_by !== null && (int) $run->calculated_by === (int) $viewer->getKey()) {
            // "So you cannot approve it", not "so someone else approves it": there may be nobody else (the client's ruling).
            return 'You calculated this run, so you cannot approve it.';
        }

        $version = $run->rateVersion();

        // Never against a card that has not taken effect, on the estate's own calendar (Hassam, 12 Oct 2026; D-234).
        if ($version->takesEffectAfter(Ledger::today())) {
            return $version->notYetInForceReason();
        }

        if (! $version->is_verified) {
            // One line (M78 §2): what stops it. Recording and verifying the card is the Filings screen's to say.
            return sprintf('The %s rate card has no verified TAJ figures yet, so this run cannot be approved.', $this->rateCardLabel($version));
        }

        /*
         * SAID BEFORE THE PRESS, NOT AFTER IT (the payroll audit, 11 Oct: a control that errors because of a rule states
         * the rule instead). Approving refused this only once pressed; the screen now greys Approve with it.
         */
        if ($this->pensionAccountMissing($run)) {
            return $this->pensionAccountRefusal();
        }

        return null;
    }

    /** Whether this run withholds pension contributions that have no liability account to post to. */
    private function pensionAccountMissing(PayrollRun $run): bool
    {
        return (int) $run->lines()->sum('pension_minor') > 0
            && ! Account::query()->where('code', self::PENSION_PAYABLE)->exists();
    }

    private function pensionAccountRefusal(): string
    {
        return 'This run withholds approved pension contributions, which are owed to the scheme and not to TAJ, so they '.
            'post to '.self::PENSION_PAYABLE.' Pension Contributions Payable. Add that liability account to the chart first.';
    }

    /* ------------------------------------------------------------------ */
    /* who approves, and the way out when nobody can (M94 §1.1) */
    /* ------------------------------------------------------------------ */

    /**
     * WHAT HAPPENS NEXT, SAID ON THE SCREEN (M94 §1.1; the client's faults, 12 Oct 2026): who calculated the run and
     * who must approve it, the approver by full name and role ("Dahlia" alone told a committee member nothing), or, when
     * nobody can, that nobody can and the way out. A rule the user cannot see their way out of reads as a broken
     * product. THE CLIENT'S RULING (12 Oct 2026): only the Community Super Admin approves a pay run, and the page says,
     * before anyone presses anything, who may approve and what would stop them, in Hassam's three cases
     * (`approvalLine()`). Whoever calculated the run last does not approve it (D-233); the one way out is somebody else
     * calculating it, which writes `calculated_by` afresh. No second approver is offered and no permission changes here.
     */
    public function approvalNext(PayrollRun $run, ?User $viewer, ?string $noun = null): ?string
    {
        if ($run->isPosted()) {
            return null;
        }

        $people = $this->estateAccountsThatCanEach([self::APPROVES, self::CALCULATES]);
        $viewerId = $viewer === null ? null : (int) $viewer->getKey();
        $calculated = $run->status === PayrollRun::CALCULATED;

        // Before a run is calculated an approver reads what comes first in their own refusal; everybody else still reads
        // who approves, so no page is ever a table and nothing else.
        if (! $calculated && ($viewerId === null || isset($people[self::APPROVES][$viewerId]))) {
            return null;
        }

        return self::approvalLine(
            // A calculation that was sent back is not the one anybody approves.
            calculatedBy: $calculated && $run->calculated_by !== null ? (int) $run->calculated_by : null,
            calculatedByName: $calculated ? $run->calculated_by_name : null,
            viewerId: $viewerId,
            approvers: $people[self::APPROVES],
            calculators: $people[self::CALCULATES],
            noun: $noun,
        );
    }

    /**
     * WHO APPROVES AND WHAT STOPS THEM, IN HASSAM'S WORDS (12 Oct 2026), one line each, from who calculated the run and
     * who holds what, so every case reads without a database. Each person named, never "she" or "he" (D-238); the role
     * is the approver role's own label and every name the estate's own, never an example's:
     *
     *   can approve        "Only the Community Super Admin approves a pay run. Dahlia Reid can approve this one."
     *   calculated it      "Dahlia Reid calculated this run." / "Whoever calculates a run cannot approve it." / "Ask Norman
     *                      Case, Treasurer, to calculate it instead, then Dahlia Reid can approve."
     *   can never approve  "Only the Community Super Admin approves a pay run. That is Dahlia Reid.", with the lines above
     *                      when the only approver calculated it, so whoever can calculate it again reads that here
     *
     * No second approver is ever offered: only the Community Super Admin approves (the client's ruling).
     *
     * @param  array<int, array{name: string, role: string|null}>  $approvers  who at this estate holds Approve pay runs
     * @param  array<int, array{name: string, role: string|null}>  $calculators  who at this estate can calculate a run
     * @return string|null the lines, one to a line ("\n")
     */
    public static function approvalLine(
        ?int $calculatedBy,
        ?string $calculatedByName,
        ?int $viewerId,
        array $approvers,
        array $calculators,
        ?string $noun = null,
    ): ?string {
        $noun ??= 'this run';

        if ($approvers === []) {
            return 'Nobody at this estate can approve a pay run, so '.$noun.' cannot be approved.';
        }

        $lead = 'Only '.self::whoMayApprove($approvers).' approves a pay run.';
        $names = static fn (array $people): array => array_values(array_map(static fn (array $person): string => $person['name'], $people));

        // Every approver but whoever calculated it last (D-233): nobody else is barred, and nobody is exempt.
        $free = $calculatedBy === null ? $approvers : array_diff_key($approvers, [$calculatedBy => true]);

        // The only approver calculated it: three short lines, each person named once a line.
        $blocked = null;

        if ($free === [] && $calculatedBy !== null) {
            $calculator = $calculatedByName ?? $approvers[$calculatedBy]['name'];
            $others = array_diff_key($calculators, [$calculatedBy => true]);

            $blocked = implode("\n", [
                $calculator.' calculated '.$noun.'.',
                'Whoever calculates a run cannot approve it.',
                $others === []
                    ? 'Nobody else here can calculate a run, so '.$calculator.' cannot approve it.'
                    : 'Ask '.self::people(array_map(self::person(...), array_values($others)), 'or').' to calculate it instead, then '.$calculator.' can approve.',
            ]);
        }

        // Somebody who can never approve: who does, by name, and what stops them when something does.
        if ($viewerId !== null && ! isset($approvers[$viewerId])) {
            $who = $names($approvers);
            $line = $lead.' '.(count($who) === 1 ? 'That is '.$who[0].'.' : 'Those are '.self::both($who).'.');

            return $blocked === null ? $line : $line."\n".$blocked;
        }

        return $blocked ?? $lead.' '.self::either($names($free)).' can approve '.($noun === 'this run' ? 'this one' : $noun).'.';
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param  list<string>  $items
     */
    private static function both(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    /**
     * Who may approve, by role: "the Community Super Admin", or "the Community Super Admin or the President"; the
     * people themselves where their accounts hold no role.
     *
     * @param  array<int, array{name: string, role: string|null}>  $approvers
     */
    private static function whoMayApprove(array $approvers): string
    {
        $roles = array_values(array_unique(array_filter(
            array_map(static fn (array $person): string => trim((string) $person['role']), $approvers),
            static fn (string $role): bool => $role !== '',
        )));

        return $roles === []
            ? self::either(array_values(array_map(static fn (array $person): string => $person['name'], $approvers)))
            : self::either(array_map(static fn (string $role): string => 'the '.$role, $roles));
    }

    /**
     * "A", "A or B", "A, B or C".
     *
     * @param  list<string>  $items
     */
    private static function either(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }

        $last = array_pop($items);

        return implode(', ', $items).' or '.$last;
    }

    /**
     * SAID BEFORE THE PRESS (M94 §1.1, item 2: "When a Super Admin presses Calculate and they are the only approver,
     * say so then"). Whoever calculates a run may not approve it, so when the person about to calculate holds Approve
     * pay runs and nobody else does, calculating makes a run nobody can release.
     */
    public function calculationWarning(PayrollRun $run, ?User $viewer): ?string
    {
        if ($viewer === null || $run->isPosted()) {
            return null;
        }

        $people = $this->estateAccountsThatCanEach([self::APPROVES, self::CALCULATES]);

        return self::calculationWarningLine(
            viewerId: (int) $viewer->getKey(),
            approvers: $people[self::APPROVES],
            calculators: $people[self::CALCULATES],
        );
    }

    /**
     * @param  array<int, array{name: string, role: string|null}>  $approvers
     * @param  array<int, array{name: string, role: string|null}>  $calculators
     */
    public static function calculationWarningLine(int $viewerId, array $approvers, array $calculators): ?string
    {
        // Another approver is free to approve it once the viewer has calculated it (D-233: calculating is the one bar).
        if (! isset($approvers[$viewerId]) || array_diff_key($approvers, [$viewerId => true]) !== []) {
            return null;
        }

        $instead = array_diff_key($calculators, [$viewerId => true]);

        // Someone else must calculate it (the client's ruling): never a second approver.
        return $instead === []
            ? 'If you calculate this run, you cannot approve it, and nobody else here can. Someone else must calculate it, and nobody else here can calculate a run.'
            : 'If you calculate this run, you cannot approve it, and nobody else here can. Ask '.self::people(array_map(self::person(...), array_values($instead)), 'or').' to calculate it instead.';
    }

    /**
     * A PERSON WHO MUST ACT, NAMED AS ONE (Hassam, 12 Oct 2026): full name and role, "Dahlia Reid, Community Super
     * Admin". The role is the one their account holds; an account with none is named alone.
     *
     * @param  array{name: string, role: string|null}  $person
     */
    private static function person(array $person): string
    {
        $role = trim((string) $person['role']);

        return $role === '' ? $person['name'] : $person['name'].', '.$role;
    }

    /**
     * People who must act, joined so each role reads as an aside: "A, Treasurer," then "A, Treasurer, or B, President,";
     * three or more take semicolons. It ends with the comma that closes the last role, so the verb follows it: "Waiting
     * for Dahlia Reid, Community Super Admin, to approve it."
     *
     * @param  list<string>  $named  each from `person()`, or "you"
     */
    private static function people(array $named, string $word): string
    {
        $aside = static fn (string $one): string => str_contains($one, ', ') ? $one.',' : $one;

        if (count($named) < 3) {
            $last = array_pop($named);

            return ($named === [] ? '' : $aside($named[0]).' '.$word.' ').$aside((string) $last);
        }

        $last = array_pop($named);

        return implode('; ', $named).'; '.$word.' '.$aside((string) $last);
    }

    /**
     * The active accounts that reach this estate and hold a permission: id => name, in name order.
     *
     * Found as the gate finds them (`can()` over each account's roles, through Spatie's `permission` scope) and kept to
     * the accounts `canAccessEstate()` answers yes for (`User::whoCanAccessEstate()`, its one rule). Read only where a
     * screen asks who can do something; outside an estate there is nobody to name.
     *
     * @return array<int, string>
     */
    public function estateAccountsThatCan(string $permission): array
    {
        return array_map(static fn (array $person): string => $person['name'], $this->estateAccountsThatCanEach([$permission])[$permission]);
    }

    /**
     * `estateAccountsThatCan()` for several permissions in ONE pass (the run list's query budget, 12 Oct): who approves
     * a run and who can calculate one were found by asking the whole question twice, three statements each. Now the
     * accounts holding either are read once, with their own direct permissions; `whoCanAccessEstate()` keeps those
     * that reach this estate, as before; and each permission's holders are picked from them by Spatie's own
     * `hasPermissionTo()`, over the roles and permissions already loaded. The same people, in the same name order.
     *
     * Each person comes with the role their account holds (Hassam, 12 Oct 2026: a person who must act is named with
     * their role), and the answer is kept for the request: the run page asks it twice, for its line and its way out.
     *
     * @param  list<string>  $permissions
     * @return array<string, array<int, array{name: string, role: string|null}>> permission => id => the person
     */
    public function estateAccountsThatCanEach(array $permissions): array
    {
        $answer = array_fill_keys($permissions, []);
        $estate = tenant()?->getTenantKey();

        if ($estate === null || $permissions === []) {
            return $answer;
        }

        $key = $estate.'|'.implode(',', $permissions);

        return $this->accounts[$key] ??= $this->readEstateAccountsThatCanEach($permissions, (string) $estate);
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, array<int, array{name: string, role: string|null}>>
     */
    private function readEstateAccountsThatCanEach(array $permissions, string $estate): array
    {
        $answer = array_fill_keys($permissions, []);

        try {
            $holders = User::query()->permission($permissions)->with('permissions')->where('status', 'active')->orderBy('name')->get();
        } catch (PermissionDoesNotExist) {
            // One of them is not a permission here: each asked on its own, as before, so the others still answer.
            if (count($permissions) > 1) {
                foreach ($permissions as $permission) {
                    $answer[$permission] = $this->estateAccountsThatCanEach([$permission])[$permission];
                }
            }

            return $answer;
        }

        $reaching = User::whoCanAccessEstate($holders, $estate);

        foreach ($permissions as $permission) {
            $answer[$permission] = $reaching
                ->filter(static fn (User $user): bool => $user->hasPermissionTo($permission))
                // The role their account holds, as the console's own header names it (`HandleInertiaRequests`).
                ->mapWithKeys(static fn (User $user): array => [(int) $user->getKey() => [
                    'name' => (string) $user->name,
                    'role' => $user->roles->first()?->label,
                ]])
                ->all();
        }

        return $answer;
    }

    /**
     * Why the next month cannot start while one is open, and what the open one waits on (M94 §1.1). It read
     * "September 2026 is still open (Pending approval). A month is closed — calculated, approved and paid — before the
     * next is started.": right, two em dashes, and a wall. Now it says what closes the open month, the way out included.
     */
    private function openRunLine(PayrollRun $open, Carbon $next): string
    {
        $line = sprintf('%s is still open, so %s cannot start yet.', $open->period_label, $next->format('F Y'));

        // The approver line's lines run on as one sentence here: it is a line of the list, not a block of the page.
        $step = $open->status === PayrollRun::CALCULATED
            ? str_replace("\n", ' ', (string) $this->approvalNext($open, null, $open->period_label))
            : 'Settle its exceptions and calculate it, then it can be approved.';

        return $step === '' ? $line : $line.' '.$step;
    }

    /**
     * Whether the next approval in this estate is its first live one — Part F.
     *
     * "Live" means approved on this platform with the acknowledgement recorded.
     * Months posted as seeded history never carried it and do not count, which
     * is also why the demonstration estate still asks on its August run.
     */
    public function needsReconciliationAcknowledgement(): bool
    {
        return ! PayrollRun::query()
            ->where('status', PayrollRun::PAID)
            ->whereNotNull('reconciliation_acknowledged_at')
            ->exists();
    }

    /** The sentence an approver ticks on the first live run, naming the card. */
    public function reconciliationStatement(PayrollRun $run): string
    {
        return sprintf(
            'I confirm this run has been reconciled against the current TAJ tables, rate card %s.',
            $this->rateCardLabel($run->rateVersion()),
        );
    }

    /** "TAJ 2026/27 (2026-04)" — the label and the month it took effect. */
    private function rateCardLabel(StatutoryRateVersion $version): string
    {
        return $version->label.' ('.$version->effective_from->format('Y-m').')';
    }

    /**
     * Approve a run and post it, in one act.
     *
     * ONE ENTRY, FOUR LINES, AND IT IS THE WHOLE RUN. Gross debits the payroll
     * expense; the employer's own contributions debit 5010; 2100 is credited
     * with what was withheld AND what the employer owes on top (D-083); net
     * credits the bank. Debits equal credits by construction — gross plus the
     * employer share on one side, net plus both liabilities on the other — and
     * the database refuses the entry if they ever do not.
     *
     * THE FIRST LIVE RUN NEEDS `$reconciled`. Part F of the Q-002 ruling: not a
     * block, an acknowledgement, recorded with the approver's name and the card
     * it was reconciled against. Checked here, so it cannot be skipped by
     * posting to the route without the tick.
     *
     * ONE DISBURSEMENT, HOWEVER MANY PRESSES (bug hunt B020, B075). The refusals
     * are asked of the caller's copy, which answers the ordinary late click, and
     * again inside the transaction of the run read under `FOR UPDATE`. Two
     * requests that loaded the run while it was `calculated` — a double-click,
     * two approvers in two tabs — used to both pass and both post the salaries,
     * 2100 and the bank. Now the second waits for the first, finds the run paid,
     * and is refused. The unique entry per run on `journals` is the database's
     * own copy of the rule.
     */
    public function approve(PayrollRun $run, User $by, bool $reconciled = false): PayrollRun
    {
        $this->refuseApproval($run, $by, $reconciled);

        return DB::connection('tenant')->transaction(function () use ($run, $by, $reconciled): PayrollRun {
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            $acknowledging = $this->refuseApproval($locked, $by, $reconciled);

            $lines = $locked->lines()->get();

            if ($lines->isEmpty()) {
                throw new DomainException('This run has no payslips on it. There is nothing to pay.');
            }

            $gross = (int) $lines->sum('gross_minor');
            $net = (int) $lines->sum('net_minor');
            $pension = (int) $lines->sum('pension_minor');

            // What was withheld for TAJ. The pension is withheld too, and owed elsewhere.
            $withheld = $gross - $net - $pension;
            $employer = (int) $lines->sum(static fn (PayrollRunLine $line): int => $line->employerContributionsMinor());

            if ($pension > 0 && ! Account::query()->where('code', self::PENSION_PAYABLE)->exists()) {
                throw new DomainException($this->pensionAccountRefusal());
            }

            // The console's middot, never an em dash (Law 3): "Payroll · May 2026 · net pay" is read on the account and the journal.
            $memo = 'Payroll · '.$locked->period_label;
            $card = $this->rateCardLabel($locked->rateVersion());

            $postings = [Posting::debit(self::EXPENSE, $gross, $memo)];

            if ($employer > 0) {
                $postings[] = Posting::debit(self::EMPLOYER_CONTRIBUTIONS, $employer, $memo.' · employer contributions');
            }

            $postings[] = Posting::credit(
                self::STATUTORY_PAYABLE,
                $withheld + $employer,
                $memo.' · withheld and employer contributions',
            );
            if ($pension > 0) {
                $postings[] = Posting::credit(self::PENSION_PAYABLE, $pension, $memo.' · approved pension contributions');
            }

            $postings[] = Posting::credit(self::BANK, $net, $memo.' · net pay');

            $entry = $this->ledger->post(
                memo: $memo,
                postings: $postings,
                on: $locked->period_end,
                source: Ledger::SOURCE_PAYROLL,
                sourceId: $locked->id,
                by: $by,
            );

            $locked->forceFill([
                'status' => PayrollRun::PAID,
                'gross_minor' => $gross,
                'net_minor' => $net,
                'approved_by' => $by->getKey(),
                'approved_by_name' => $by->name,
                'approved_at' => now(),
                'journal_ref' => $entry->reference,
                ...($acknowledging ? [
                    'reconciliation_acknowledged_at' => now(),
                    'reconciliation_acknowledged_by' => $by->getKey(),
                    'reconciliation_acknowledged_by_name' => $by->name,
                    'reconciliation_rate_version' => $card,
                ] : []),
            ])->save();

            $this->registerReturns($locked, $lines);

            return $run->refresh();
        });
    }

    /**
     * Put the period's S01 and S02 on the register — board 16 (bug hunt B026).
     *
     * Approving a run credits 2100 with what it withheld and what the employer
     * owes on top, and only filing an S01 clears it. Nothing ever put a live
     * month's S01 on the register: board 16 held the seeded July and August and
     * nothing after, so from September 2100 grew every month and no screen could
     * record remitting it. The returns are now written in the same transaction as
     * the posting, so the payable and the return that clears it cannot exist one
     * without the other.
     *
     * THE S01 CARRIES THE RUN'S OWN FIGURES, read off its lines — the four
     * deductions and the employer's share (D-083) — which is exactly what the
     * posting credited to 2100, so filing it returns the payable to nil for the
     * period. The S02 reconciles that remittance and carries none, as the seeded
     * July pair does: the same amounts on both would read as the deductions paid
     * twice.
     *
     * A RETURN ALREADY ON THE REGISTER KEEPS ITS DUE DATE and takes the run's
     * figures; one already filed is left exactly as it is, because it is a
     * record of what was sent. A new one is due on the 14th of the month after the
     * period — the rule the compliance calendar projects.
     *
     * @param  Collection<int, PayrollRunLine>  $lines
     */
    private function registerReturns(PayrollRun $run, Collection $lines): void
    {
        $due = $run->period_start->copy()->startOfMonth()->addMonthNoOverflow()->day(self::MONTHLY_RETURN_DUE_DAY);

        $figures = [
            'employees_covered' => $lines->count(),
            'nis_minor' => (int) $lines->sum('nis_minor'),
            'nht_minor' => (int) $lines->sum('nht_minor'),
            'education_tax_minor' => (int) $lines->sum('education_tax_minor'),
            'paye_minor' => (int) $lines->sum('paye_minor'),
            'employer_nis_minor' => (int) $lines->sum('employer_nis_minor'),
            'employer_nht_minor' => (int) $lines->sum('employer_nht_minor'),
            'employer_education_tax_minor' => (int) $lines->sum('employer_education_tax_minor'),
            'heart_minor' => (int) $lines->sum('employer_heart_minor'),
        ];

        $figures['total_minor'] = $figures['nis_minor'] + $figures['nht_minor'] + $figures['education_tax_minor']
            + $figures['paye_minor'] + $figures['employer_nis_minor'] + $figures['employer_nht_minor']
            + $figures['employer_education_tax_minor'] + $figures['heart_minor'];

        $none = array_fill_keys(array_keys($figures), null);

        $returns = [
            StatutoryFiling::S01 => ['title' => 'Statutory Deduction Remittance', 'figures' => $figures],
            StatutoryFiling::S02 => ['title' => 'Monthly Reconciliation', 'figures' => $none],
        ];

        foreach ($returns as $code => $return) {
            $filing = StatutoryFiling::query()
                ->where('form_code', $code)
                ->where('period_label', $run->period_label)
                ->lockForUpdate()
                ->first();

            if ($filing?->status === StatutoryFiling::FILED) {
                continue;
            }

            $filing ??= new StatutoryFiling([
                'form_code' => $code,
                'form_title' => $return['title'],
                'period_label' => $run->period_label,
                'due_on' => $due->toDateString(),
                'status' => StatutoryFiling::NOT_STARTED,
            ]);

            $filing->forceFill([
                ...$return['figures'],
                'period_start' => $run->period_start->toDateString(),
                'period_end' => $run->period_end->toDateString(),
                'payroll_run_id' => $run->id,
                'currency' => $run->currency,
            ])->save();
        }
    }

    /**
     * Why this person may not approve this run as it stands — or, when they may,
     * whether this approval is the first live one and carries the acknowledgement.
     */
    private function refuseApproval(PayrollRun $run, User $by, bool $reconciled): bool
    {
        $refusal = $this->approvalRefusal($run, $by);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        $acknowledging = $this->needsReconciliationAcknowledgement();

        if ($acknowledging && ! $reconciled) {
            throw new DomainException(
                'This is the first live pay run in this estate, so it needs the approver to confirm it has been '.
                'reconciled against the current TAJ tables. '.$this->reconciliationStatement($run)
            );
        }

        return $acknowledging;
    }

    /**
     * Send a run back to whoever prepared it.
     *
     * A REASON IS REQUIRED, and the refusal says why: "request changes" with no
     * changes named is a run bounced back to somebody who now has to guess what
     * is wrong with it.
     */
    public function requestChanges(PayrollRun $run, string $reason, User $by): PayrollRun
    {
        if ($run->isPosted()) {
            throw new DomainException('This run has already been paid and cannot be sent back.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'Say what needs changing. A run sent back with no reason leaves the person who prepared '.
                'it guessing at which of four payslips you disagreed with.'
            );
        }

        // A reply that names nothing is refused as an empty one is (the client, 12 Oct 2026: "all done" sent it back).
        if (! $this->namesAChange($reason)) {
            throw new DomainException(sprintf(
                'Say what needs changing: whose payslip is wrong, or which figure, such as their overtime, hours or a deduction. "%s" names nothing to change.',
                Str::limit($reason, 60),
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($run, $reason): PayrollRun {
            /*
             * Under the lock approval takes (bug hunt B020, B075). A copy loaded
             * before the run was paid used to mark a paid run `exceptions`, and
             * Calculate then treated it as a run whose money had not left.
             */
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPosted()) {
                throw new DomainException('This run has already been paid and cannot be sent back.');
            }

            $locked->forceFill([
                'status' => PayrollRun::EXCEPTIONS,
                'changes_requested_reason' => $reason,
            ])->save();

            return $run->refresh();
        });
    }

    /** Whether a request for changes names a part of a payslip, or somebody on this estate's payroll by name. */
    private function namesAChange(string $reason): bool
    {
        $words = self::words($reason);

        if (array_intersect($words, self::PAYSLIP_WORDS) !== []) {
            return true;
        }

        // Any part of an employee's name of three letters or more: "Neil's timesheet", "Wayne Thomas is short".
        $names = Employee::query()->pluck('full_name')
            ->flatMap(static fn (mixed $name): array => self::words((string) $name))
            ->filter(static fn (string $part): bool => mb_strlen($part) >= 3)
            ->all();

        return array_intersect($words, $names) !== [];
    }

    /** @return list<string> the words of a sentence, lower case, without their punctuation */
    private static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [], static fn (string $word): bool => $word !== ''));
    }

    /**
     * Deal with one exception — resolved into the run, or excluded from it.
     *
     * The note is kept whichever way it goes. The reason a technician's 22
     * overtime hours went through unchallenged is exactly what somebody asks
     * about six months later.
     */
    public function resolveException(
        PayrollException $exception,
        string $outcome,
        ?string $note,
        User $by,
    ): PayrollException {
        if ($exception->run->isPosted()) {
            throw new DomainException(self::PAID_EXCEPTION);
        }

        if (! in_array($outcome, [PayrollException::RESOLVED, PayrollException::EXCLUDED], true)) {
            throw new DomainException('An exception is either resolved into the run or excluded from it.');
        }

        return DB::connection('tenant')->transaction(function () use ($exception, $outcome, $note, $by): PayrollException {
            // Under the run's lock, so an exception is never decided beside the
            // calculation or the approval that reads it (bug hunt B020, B075).
            $run = PayrollRun::query()->whereKey($exception->payroll_run_id)->lockForUpdate()->firstOrFail();

            if ($run->isPosted()) {
                throw new DomainException(self::PAID_EXCEPTION);
            }

            $exception->forceFill([
                'status' => $outcome,
                'resolution_note' => trim((string) $note) ?: null,
                'resolved_at' => now(),
                'resolved_by' => $by->getKey(),
                'resolved_by_name' => $by->name,
            ])->save();

            return $exception->refresh();
        });
    }

    /**
     * File a return and clear what it remits.
     *
     * DR 2100 / CR 1000 for the exact total the run withheld AND the employer
     * contributed on top (D-083) — which returns the payable to nil for that
     * period, and is the assertion board 16's whole screen turns on even though
     * it prints no figures at all.
     *
     * A return with nothing to remit — a GCT return, the annual P24 — is
     * recorded as filed and posts nothing. There is no entry to make, and a
     * zero-value journal is noise in a ledger somebody has to read in five
     * years.
     */
    public function fileReturn(StatutoryFiling $filing, User $by, Carbon|string|null $on = null): StatutoryFiling
    {
        $this->refuseFiling($filing);

        // Filed on the estate's today: an S01 filed on the evening of its due day
        // in Kingston is filed on time, not the next day (bug hunt B023).
        $filedOn = $on === null ? Ledger::today() : Carbon::parse($on);

        return DB::connection('tenant')->transaction(function () use ($filing, $by, $filedOn): StatutoryFiling {
            /*
             * THE RETURN AS IT STANDS, UNDER A LOCK (bug hunt B020, B075). Two
             * requests that loaded it unfiled — a double-click on File — used to
             * both remit it, driving 2100 below nil. The second now waits for the
             * first, finds it filed, and is refused; the unique entry per return
             * on `journals` is the database's own copy of the rule.
             */
            $locked = StatutoryFiling::query()->whereKey($filing->getKey())->lockForUpdate()->firstOrFail();

            $this->refuseFiling($locked);

            $total = $locked->remittanceMinor();
            $reference = null;

            if ($total > 0) {
                $memo = $locked->form_code.' remittance · '.$locked->period_label;

                $reference = $this->ledger->post(
                    memo: $memo,
                    postings: [
                        Posting::debit(self::STATUTORY_PAYABLE, $total, $memo),
                        Posting::credit(self::BANK, $total, $memo),
                    ],
                    on: $filedOn,
                    source: Ledger::SOURCE_PAYROLL_REMITTANCE,
                    sourceId: $locked->id,
                    by: $by,
                )->reference;
            }

            $locked->forceFill([
                'status' => StatutoryFiling::FILED,
                'filed_on' => $filedOn,
                'journal_ref' => $reference,
            ])->save();

            return $filing->refresh();
        });
    }

    /** Why this return cannot be filed as it stands — asked of the caller's copy, then of the locked row. */
    private function refuseFiling(StatutoryFiling $filing): void
    {
        if ($filing->status === StatutoryFiling::FILED) {
            throw new DomainException(
                $filing->form_code.' for '.$filing->period_label.' has already been filed. '.
                'Filing it twice would remit the same deductions twice.'
            );
        }

        /*
         * NOT BEFORE ITS PAY RUN IS PAID (bug hunt B026). A return remits what a
         * run withheld, and 2100 holds that only once the run is approved. The
         * seeded August S01 sat on the register beside a run still waiting for
         * approval, and filing it would have taken August's deductions out of
         * 2100 — and out of the bank — before anybody had withheld them.
         */
        // Read as it stands, never from a relation the caller may have loaded before the approval.
        $run = $filing->payroll_run_id === null ? null : PayrollRun::query()->find($filing->payroll_run_id);

        if ($run !== null && ! $run->isPosted()) {
            throw new DomainException(sprintf(
                '%s for %s reports what the %s pay run withheld, and that run has not been approved yet: nothing has '.
                'been withheld, and 2100 holds nothing for this return to clear. Approve the run first; the return is '.
                'filed after it.',
                $filing->form_code,
                $filing->period_label,
                $run->period_label,
            ));
        }
    }

    /**
     * What the estate still owes the revenue authority, read from the ledger.
     *
     * The control account itself, not a sum of filings. A filing register that
     * disagreed with account 2100 is exactly the drift this returns rather than
     * hides.
     */
    public function outstandingStatutoryMinor(): int
    {
        return $this->ledger
            ->balanceOf(Account::query()->where('code', self::STATUTORY_PAYABLE)->firstOrFail())
            ->getMinorAmount()
            ->toInt();
    }

    /**
     * The card a run paid today would use — the selector, never "the latest".
     *
     * "The latest" was the old answer, and on the day this changed it would
     * have been the 2027-04 card: a version not yet in force, seeded from its
     * annual figure alone.
     */
    public function currentRates(): StatutoryRateVersion
    {
        return StatutoryRateVersion::forPayDate(Ledger::today());
    }
}
