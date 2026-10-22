<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Services\Payroll\GuardPayrollApproval;
use App\Services\Payroll\RateTable;
use App\Services\Payroll\StatutoryFilingRegister;
use App\Services\Push\PushAnnouncer;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;
use App\Support\TableQuery;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * Guard payroll and accounting, Super Admin screens 28 to 30.
 *
 * Gemini Security paying its own guards. An estate's staff payroll is a
 * separate ledger in the estate database and never appears here; nothing in
 * this controller opens an estate connection.
 *
 * The screens are the approved boards' shape, not a shape of my own:
 *
 *   28  a tab strip, one exception banner, four KPI cards for the current
 *       run, then the list of runs
 *   29  one table of payslips for a single run, nothing else
 *   30  a tab strip and one card of statutory returns, none of them clickable
 *
 * Q-002 IS RULED (D-082). The TAJ cards carry their published periodic figures
 * and are verified, so a calculated run can now be APPROVED — by a role holding
 * Payroll & Accounting approval (D-084), with the first-live-run acknowledgement
 * the ruling asks for. `GuardPayrollApproval` is the one door; this controller
 * only carries its answer to the screen.
 */
class PayrollController extends Controller
{
    /**
     * Sortable columns on the pay-run list, mapped to the columns they sort.
     *
     * An allow-list rather than the request string, because `orderBy` takes a
     * column name straight into SQL.
     *
     * @var array<string, string>
     */
    private const RUN_SORTS = [
        'period' => 'period_start',
        'employees' => 'payslips_count',
        'gross' => 'gross_minor',
        'net' => 'net_minor',
        'status' => 'status',
    ];

    /** @var array<string, string> */
    private const SLIP_SORTS = [
        'employee' => 'guards.full_name',
        'client' => 'tenants.name',
        'gross' => 'payslips.gross_minor',
        'nis' => 'payslips.nis_minor',
        'nht' => 'payslips.nht_minor',
        'education_tax' => 'payslips.education_tax_minor',
        'paye' => 'payslips.paye_minor',
        'net' => 'payslips.net_minor',
    ];

    /**
     * Guard states that mean a payslip on this run needs a human look.
     *
     * @var list<string>
     */
    private const NOT_IN_GOOD_STANDING = ['suspended', 'licence_expired', 'inactive'];

    /**
     * Guard states that mean the guard was employed during the period and so
     * should appear on the run.
     *
     * @var list<string>
     */
    private const ON_THE_ROSTER = ['active', 'on_leave', 'licence_expired', 'suspended'];

    public function index(Request $request): Response
    {
        // M32 F5: the run list as the shared table — newest period first, sorted and paged in SQL.
        $table = TableQuery::from($request, self::RUN_SORTS, 'period', 'desc');
        $search = $table->search;

        $runs = PayrollRun::query()->with('rateVersion')->withCount('payslips');

        if ($search !== '') {
            $runs->where(function ($query) use ($search): void {
                $query->where('period_label', 'like', '%'.$search.'%')
                    ->orWhere('reference', 'like', '%'.$search.'%')
                    ->orWhere('status', 'like', '%'.$search.'%');
            });
        }

        $table->order($runs, 'payroll_runs.id');
        $page = $table->paginate($runs);
        $listed = $page->getCollection();

        /*
         * The KPI cards and the banner describe the CURRENT run, not the
         * filtered list. On the board the four figures are the top row's, not
         * the sum of the three rows below them, and a search that silently
         * rewrote the headline totals would be reporting a different thing
         * under the same four labels.
         */
        $current = PayrollRun::query()->with('rateVersion')->orderByDesc('period_start')->first();

        /*
         * EVERY RUN'S EXCEPTIONS FROM ONE PAIR OF READS (M11 A2): the current run's, and every
         * listed run still under review. Each of them used to ask its own two queries inside
         * the list below — a query pair per run awaiting approval.
         */
        $underReview = $listed->filter(static fn (PayrollRun $run): bool => in_array($run->status, ['draft', 'calculated'], true));
        $exceptionsByRun = $this->exceptionsForRuns($current === null ? $underReview : $underReview->push($current)->unique('id'));
        $currentExceptions = $current === null ? [] : ($exceptionsByRun[(int) $current->id] ?? []);

        // M96 level 3 (the payroll pass, 11 Oct): a row offers approving only to a role that ever approves a run.
        $approves = $request->user()->can('gemini.payroll_accounting.approve');

        return inertia('Gemini/Payroll/Index', [
            'kpis' => $this->kpis($current),
            'banner' => $this->banner($current, $currentExceptions),
            'runs' => $listed->map(function (PayrollRun $run) use ($current, $currentExceptions, $exceptionsByRun, $approves): array {
                /*
                 * Exceptions are recomputed only for a run still under review.
                 * An approved run's badge says when it was approved, so the
                 * work of finding its exceptions would be thrown away.
                 */
                $exceptions = match (true) {
                    $current !== null && $run->id === $current->id => $currentExceptions,
                    in_array($run->status, ['draft', 'calculated'], true) => $exceptionsByRun[(int) $run->id] ?? [],
                    default => [],
                };

                // Only a run still under review is badged by its exceptions: the newest run's are read for the banner even
                // once it is approved, and its badge said "Action required · 6 exceptions" over a run nobody can act on.
                $badge = $this->badgeFor($run, in_array($run->status, ['draft', 'calculated'], true) ? count($exceptions) : 0);

                return [
                    'id' => $run->id,
                    'period' => $run->period_label,
                    'employees' => number_format((int) $run->payslips_count),
                    'gross' => MoneyFormatter::fromMinor($run->gross_minor, $run->currency),
                    'net' => MoneyFormatter::fromMinor($run->net_minor, $run->currency),
                    'badge_class' => $badge['class'],
                    'badge_label' => $badge['label'],
                    'blocked_reason' => $run->blockedReason(),
                    /*
                     * "Review & approve" only when approval is genuinely
                     * available — a calculated run on a verified card. A run
                     * on an unverified card still says "Review", because that
                     * is all the link can honestly offer.
                     */
                    'action_label' => match (true) {
                        $run->canBeApproved() && $approves => 'Review & approve',
                        $run->status === 'calculated' || $run->status === 'draft' => 'Review',
                        default => 'View',
                    },
                ];
            })->values()->all(),
            'filters' => ['q' => $search],
            'table' => $table->meta($page),
        ]);
    }

    /**
     * What a run's payslips order by (M32 F5): `SLIP_SORTS`, with the guard's name as the next tie-break wherever it
     * is not the order itself — two guards at the same client or on the same gross never swap places between one
     * request and the next.
     *
     * @return array<string, \Closure>
     */
    private static function slipSorts(): array
    {
        $sorts = [];

        foreach (self::SLIP_SORTS as $key => $column) {
            $sorts[$key] = static function ($query, string $dir) use ($key, $column): void {
                $query->orderBy($column, $dir);

                if ($key !== 'employee') {
                    $query->orderBy('guards.full_name');
                }
            };
        }

        return $sorts;
    }

    public function show(Request $request, PayrollRun $run, GuardPayrollApproval $approval): Response
    {
        // M32 F5: the payslips as the shared table — by client unless a heading asks otherwise, a page at a time.
        $table = TableQuery::from($request, self::slipSorts(), 'client', 'asc');
        $search = $table->search;

        $periodEnd = $run->period_end;

        /*
         * The query builder, not Eloquent.
         *
         * This selects the guard's name and standing and the estate's name
         * alongside the payslip's own columns. Hydrating a Payslip would hand
         * back model objects carrying attributes the class does not declare,
         * and the join is what makes sorting by employee or client a database
         * sort rather than a second sort in PHP over an already-sorted set.
         *
         * A LEFT join to tenants: a payslip's tenant_id is nullable, because a
         * guard between postings is a real state and not a broken row.
         */
        $query = DB::connection('mysql')
            ->table('payslips')
            ->join('guards', 'guards.id', '=', 'payslips.guard_id')
            ->leftJoin('tenants', 'tenants.id', '=', 'payslips.tenant_id')
            ->where('payslips.payroll_run_id', $run->id)
            ->select(
                'payslips.id',
                'payslips.guard_id',
                'payslips.currency',
                'payslips.gross_minor',
                'payslips.nis_minor',
                'payslips.nht_minor',
                'payslips.education_tax_minor',
                'payslips.paye_minor',
                'payslips.net_minor',
                'payslips.paye_note',
                'guards.full_name',
                'guards.status as guard_status',
                'guards.psra_expires_on',
                'tenants.name as estate_name',
            );

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            // M32 F4: the guard by employee number too, as the directory finds them.
            $query->where(function ($inner) use ($like): void {
                $inner->where('guards.full_name', 'like', $like)
                    ->orWhere('guards.employee_number', 'like', $like)
                    ->orWhere('tenants.name', 'like', $like);
            });
        }

        // The order the table asks for, the guard's name next, and the payslip's own id last (`slipSorts()`).
        $table->order($query, 'payslips.id');
        $page = $table->paginateRows($query);

        $payslips = $page->getCollection()->map(function (object $row) use ($periodEnd): array {
            $lapsed = $this->licenceLapsedBefore($row, $periodEnd);
            $standing = ! in_array((string) $row->guard_status, self::NOT_IN_GOOD_STANDING, true);
            $exception = $lapsed || ! $standing;

            $currency = (string) $row->currency;

            return [
                'id' => (int) $row->id,
                'guard_id' => (int) $row->guard_id,
                'employee' => (string) $row->full_name,
                'initials' => $this->initials((string) $row->full_name),
                'estate' => $row->estate_name === null ? 'Unassigned' : (string) $row->estate_name,
                'gross' => MoneyFormatter::fromMinor((int) $row->gross_minor, $currency),
                'nis' => MoneyFormatter::fromMinor((int) $row->nis_minor, $currency),
                'nht' => MoneyFormatter::fromMinor((int) $row->nht_minor, $currency),
                'education_tax' => MoneyFormatter::fromMinor((int) $row->education_tax_minor, $currency),
                'paye' => MoneyFormatter::fromMinor((int) $row->paye_minor, $currency),
                'net' => MoneyFormatter::fromMinor((int) $row->net_minor, $currency),
                /*
                 * Most guards fall under the PAYE threshold, so a bare 0.00
                 * reads as a defect to the person holding the payslip. The
                 * board has no room for a second line, so the sentence that
                 * names the threshold rides on the cell itself.
                 */
                'paye_note' => $row->paye_note === null ? null : (string) $row->paye_note,
                'exception' => $exception,
                'exception_reason' => $exception ? $this->paidWhileLapsedReason($row, $periodEnd) : null,
            ];
        })->values()->all();

        // The run's state at the top of its page, in the list's own words (M96 §5; the payroll pass).
        $badge = $this->badgeFor($run, in_array($run->status, ['draft', 'calculated'], true)
            ? count($this->exceptionsForRuns(collect([$run]))[(int) $run->id] ?? [])
            : 0);

        return inertia('Gemini/Payroll/Show', [
            'run' => [
                'id' => $run->id,
                'reference' => $run->reference,
                'period' => $run->period_label,
                'status' => $run->status,
                'badge_label' => $badge['label'],
                'badge_class' => $badge['class'],
                'blocked_reason' => $run->blockedReason(),

                /*
                 * WHAT "APPROVE & DISBURSE" WOULD DO, NAMED BEFORE THE PRESS (DR-073): the net it pays, and how many
                 * guards it tells "Payslip ready", counted by the query the push sends by. Counted only while the run
                 * waits for approval; once it has gone, nobody is asked, so nothing is counted.
                 */
                'net' => MoneyFormatter::fromMinor((int) $run->net_minor, (string) $run->currency),
                'told_count' => $run->status === 'calculated' ? PushAnnouncer::payslipAudience((int) $run->id)->distinct()->count('guard_id') : null,
            ],
            /*
             * Whether the employee cell is a link at all.
             *
             * The guard roster is its own module with its own permission, and
             * a role can hold payroll without holding it. Asking the same gate
             * the route asks means the cell is a plain cell for those roles
             * rather than a link that lands on a 403.
             */
            // Named as the page declares it: sent as `can_open_guards`, it never reached the page, so no guard's name
            // linked to their record for any role (the payroll pass, 11 Oct).
            'canOpenGuards' => Gate::allows('gemini.guard_workforce.view'),
            'payslips' => $payslips,
            'filters' => ['q' => $search],
            'table' => $table->meta($page),

            /*
             * The board's "Approve & disburse", with the answer from the one
             * door that decides it. A refusal travels as the sentence the
             * control carries; the first live run also carries the
             * acknowledgement the ruling asks for (Part F).
             */
            'approval' => [
                'reason' => $approval->refusal($run, $request->user()),
                // M96 level 3: whether this role ever approves a guard run; a role that never does has no button.
                'holds' => $request->user()->can('gemini.payroll_accounting.approve'),
                'acknowledgement' => [
                    'required' => $run->status === 'calculated' && $approval->needsReconciliationAcknowledgement(),
                    'statement' => $approval->reconciliationStatement($run),
                ],
            ],
        ]);
    }

    /**
     * Approve a calculated guard run — the one write this module has.
     *
     * Refusals come back as the sentence the service wrote, never a generic
     * "not allowed": each is a different person's problem to solve.
     *
     * BOTH ANSWERS REACH THE SCREEN (B018). The success is flashed under
     * `success`, a key `HandleInertiaRequests` shares; it used to go under
     * `flash`, which nothing shares, so "Run approved" never reached a page. The
     * pay-run screen draws `flash.success` and `errors.run` above its table.
     */
    public function approve(Request $request, PayrollRun $run, GuardPayrollApproval $approval): RedirectResponse
    {
        try {
            $approval->approve($run, $request->user(), $request->boolean('reconciled'));
        } catch (DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('success', 'Run approved. The transfer itself is made from Gemini\'s bank; this records the decision.');
    }

    /**
     * Statutory filings, board screen super-admin-30.
     *
     * The board's body is a tab strip and one card of rows, and that is all
     * this renders. Every row is a POSTED RECORD or an obligation to produce
     * one: nothing on it is clickable, and there is no edit and no delete, not
     * even a disabled one. A greyed-out delete would tell the reader that
     * deleting a filed return is a thing this system does and they merely lack
     * the right to do it. It is not.
     *
     * The topbar's "Start new filing" prepares the next return from an
     * approved run (12 §2, Wave 4); when none can be prepared, it is inert and
     * carries the register's own reason.
     */
    public function filings(Request $request, StatutoryFilingRegister $register): Response
    {
        $year = $this->yearFrom($request);

        /*
         * The sorting keys the register used are dropped here rather than in
         * the service. The service needs them to order the list; the screen
         * would only be able to misuse them.
         */
        $rows = array_map(
            static fn (array $row): array => Arr::except($row, ['sort_bucket', 'sort_due', 'sort_code']),
            $register->rows($year),
        );

        return inertia('Gemini/Payroll/Filings', [
            'filings' => $rows,
            'blockedReason' => $register->blockedReason(),
            'filters' => ['year' => $year],
            'years' => $register->years(),

            // Preparing a return brings a remittance into existence: `create`.
            'canPrepare' => $request->user()->can('gemini.payroll_accounting.create'),
            'prepareDeniedReason' => 'Preparing a return needs Payroll & accounting create access. You are able to read this register.',
        ]);
    }

    /** "Start new filing" (12 §2, Wave 4) — see `StatutoryFilingRegister::prepareNext()`. */
    public function prepareFiling(StatutoryFilingRegister $register): RedirectResponse
    {
        try {
            $filing = $register->prepareNext();
        } catch (DomainException $refused) {
            return back()->withErrors(['filing' => $refused->getMessage()]);
        }

        return back()->with('success', sprintf(
            'S01 for %s prepared from the approved run: %s across %d employees. Filing it with TAJ is recorded separately.',
            $filing->period_label,
            MoneyFormatter::fromMinor((int) $filing->total_minor, $filing->currency),
            (int) $filing->employees_covered,
        ));
    }

    /**
     * The statutory rate table — board screen super-admin-31.
     *
     * D-021 is the subject of this screen rather than a caveat on it: the rates
     * are a DRAFT nobody qualified has checked against a worked example, and no
     * run may be approved against them. The screen shows the arithmetic
     * honestly enough to be checked and states plainly that it has not been.
     * There is deliberately no means of approving it here, and no route that
     * could grow into one.
     *
     * `rates` returns null only when no version has ever been recorded, which
     * is a genuine first-use state rather than an error.
     */
    public function rates(RateTable $table): Response
    {
        return inertia('Gemini/Payroll/Rates', [
            'table' => $table->forVersion(),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The year a register is narrowed to, or null for all of them.
     *
     * Read from the URL rather than from a control, because the filings board
     * draws no filter and inventing one would be authoring a piece of design
     * nobody approved. It is still a real, linkable filter — /payroll/filings
     * ?year=2025 is a page somebody can bookmark — and when it matches nothing
     * the screen says which years do hold returns and offers to clear it.
     *
     * Anything that is not a plausible year is ignored rather than rejected: a
     * mistyped URL should show the register, not an error page.
     */
    private function yearFrom(Request $request): ?int
    {
        $year = $request->query('year');

        if (! is_string($year) || ! ctype_digit($year)) {
            return null;
        }

        $value = (int) $year;

        return $value >= 2000 && $value <= 2100 ? $value : null;
    }

    /**
     * The four KPI cards, in the order the board draws them.
     *
     * Deductions are gross less net in MINOR UNITS and formatted once. They
     * are never re-derived from the two formatted strings, and never from a
     * float: the payslip table below has to add up to these figures exactly.
     *
     * @return list<array<string, mixed>>
     */
    private function kpis(?PayrollRun $run): array
    {
        $employees = $run === null ? 0 : $run->payslips()->count();
        $grossMinor = $run === null ? 0 : $run->gross_minor;
        $netMinor = $run === null ? 0 : $run->net_minor;
        $currency = $run === null ? MoneyFormatter::DEFAULT_CURRENCY : $run->currency;

        return [
            [
                'key' => 'employees',
                'icon' => 'guards',
                'stroke' => 1.7,
                'value' => number_format($employees),
                'label' => 'Employees',
            ],
            [
                'key' => 'gross',
                'icon' => 'currency',
                'stroke' => 1.7,
                'value' => MoneyFormatter::fromMinor($grossMinor, $currency),
                'label' => 'Total gross',
            ],
            [
                'key' => 'deductions',
                'icon' => 'billing',
                'stroke' => 1.7,
                'value' => MoneyFormatter::fromMinor($grossMinor - $netMinor, $currency),
                'label' => 'Total deductions',
            ],
            [
                'key' => 'net',
                'icon' => 'check',
                'stroke' => 3,
                'value' => MoneyFormatter::fromMinor($netMinor, $currency),
                'label' => 'Total net pay',
            ],
        ];
    }

    /**
     * The one banner the board draws, carrying whatever is actually wrong.
     *
     * The board shows a single exception banner, so this returns a single
     * alert rather than a stack: a screen that grows a second red bar whenever
     * two things are true at once is a different layout, not the approved one.
     * Exceptions lead, because they name a person whose pay is wrong; the
     * D-021 approval block rides on the same line rather than being lost.
     *
     * @param  list<array{name: string, reason: string}>  $exceptions
     * @return array{title: string, detail: string}|null
     */
    private function banner(?PayrollRun $run, array $exceptions): ?array
    {
        if ($run === null) {
            return null;
        }

        $blocked = $run->blockedReason();

        if ($exceptions !== []) {
            $count = count($exceptions);
            $first = $exceptions[0];

            // A colon, never an em dash (Law 3; the payroll pass).
            $title = $count === 1
                ? $first['name'].': 1 exception in this run'
                : sprintf(
                    '%s and %d other%s: %d exceptions in this run',
                    $first['name'],
                    $count - 1,
                    $count === 2 ? '' : 's',
                    $count,
                );

            $detail = $count === 1
                ? $first['reason']
                : 'Each is suspended, out of licence, or missing from the run entirely. Settle them before it is approved.';

            if ($blocked !== null) {
                $detail .= ' Approval is blocked too: this run\'s rate card carries no verified TAJ figures.';
            }

            return ['title' => $title, 'detail' => $detail];
        }

        if ($blocked !== null) {
            return [
                'title' => 'Approval blocked: this run\'s rate card carries no verified TAJ figures',
                'detail' => sprintf(
                    'The %s card has no TAJ periodic thresholds recorded, so a run calculated on it cannot be approved. The figures below can be reviewed in the meantime.',
                    $run->rateVersion->label,
                ),
            ];
        }

        return null;
    }

    /**
     * Everything about each run that a reviewer has to settle before it is
     * approved, in two kinds, keyed by run id.
     *
     * Paid but out of standing: the guard is on the run and drew a full
     * period's pay while suspended, off-roster, or out of licence.
     *
     * On the roster but unpaid: the guard was employed for the period and has
     * no payslip on the run at all, so the period is silently unpaid. This is
     * the harder one to notice, because the row simply is not there.
     *
     * THE SAME TWO QUESTIONS, ASKED ONCE FOR EVERY RUN (M11 A2). The payroll list badges every
     * run still under review with its exception count, and each run used to ask both questions
     * of the database itself. Here the rows are read once for all of them — widened to the
     * latest period end among them — and each run then keeps exactly the rows its own query
     * returned:
     *
     *   paid, out of standing   a payslip on THIS run, whose guard is suspended, inactive or
     *                           licence-expired, or whose licence expired before THIS run's end
     *   on the roster, unpaid   a guard on the roster, hired by THIS run's end (or with no hire
     *                           date), with no payslip on THIS run
     *
     * Dates are compared as the `Y-m-d` strings the columns hold, which is the order MySQL
     * compares them in. Each list stays in full-name order, as its query ordered it, and the
     * two are merged and sorted by name exactly as before.
     *
     * @param  iterable<PayrollRun>  $runs
     * @return array<int, list<array{name: string, reason: string}>>
     */
    private function exceptionsForRuns(iterable $runs): array
    {
        $runs = collect($runs)->values();

        if ($runs->isEmpty()) {
            return [];
        }

        $ids = $runs->map(static fn (PayrollRun $run): int => (int) $run->id)->all();
        $latestEnd = (string) $runs->map(static fn (PayrollRun $run): string => $run->period_end->toDateString())->max();

        /*
         * ONE READ, NOT THREE (the pay run page's query budget, 12 Oct: 19 against 17). Who was paid out of standing, who
         * is on the roster, and which roster guard has a payslip on which run were three statements. They are one: the
         * paid-out-of-standing rows and the roster rows in a UNION ALL, each roster row carrying the runs among these it
         * has a payslip on. The same rows, each list in full-name order, as before.
         */
        $paid = DB::connection('mysql')
            ->table('payslips')
            ->join('guards', 'guards.id', '=', 'payslips.guard_id')
            ->whereIn('payslips.payroll_run_id', $ids)
            ->where(function ($query) use ($latestEnd): void {
                $query->whereIn('guards.status', self::NOT_IN_GOOD_STANDING)
                    ->orWhere('guards.psra_expires_on', '<', $latestEnd);
            })
            ->select([
                DB::raw("'paid' as kind"), 'payslips.payroll_run_id', 'guards.id', 'guards.full_name', 'guards.status',
                'guards.psra_expires_on', DB::raw('NULL as hired_on'), DB::raw('NULL as on_runs'),
            ]);

        $roster = DB::connection('mysql')
            ->table('guards')
            ->whereIn('guards.status', self::ON_THE_ROSTER)
            ->where(function ($query) use ($latestEnd): void {
                $query->whereNull('guards.hired_on')->orWhere('guards.hired_on', '<=', $latestEnd);
            })
            ->select([
                DB::raw("'roster' as kind"), DB::raw('NULL as payroll_run_id'), 'guards.id', 'guards.full_name', 'guards.status',
                'guards.psra_expires_on', 'guards.hired_on',
            ])
            ->addSelect(['on_runs' => DB::connection('mysql')->table('payslips')
                ->selectRaw('GROUP_CONCAT(payslips.payroll_run_id)')
                ->whereColumn('payslips.guard_id', 'guards.id')
                ->whereIn('payslips.payroll_run_id', $ids)]);

        $rows = $paid->unionAll($roster)->orderBy('full_name')->get();

        $paidByRun = $rows->where('kind', 'paid')->groupBy('payroll_run_id');
        $rostered = $rows->where('kind', 'roster')->values();

        /** @var array<string, true> $onRun "run:guard" for every roster guard's payslip on these runs */
        $onRun = [];

        foreach ($rostered as $guard) {
            foreach (array_filter(explode(',', (string) $guard->on_runs), static fn (string $id): bool => $id !== '') as $runId) {
                $onRun[(int) $runId.':'.(int) $guard->id] = true;
            }
        }

        $out = [];

        foreach ($runs as $run) {
            $periodEnd = $run->period_end;
            $end = $periodEnd->toDateString();
            $exceptions = [];

            foreach ($paidByRun->get((int) $run->id, collect()) as $guard) {
                $lapsedBefore = $guard->psra_expires_on !== null && substr((string) $guard->psra_expires_on, 0, 10) < $end;

                if (! in_array((string) $guard->status, self::NOT_IN_GOOD_STANDING, true) && ! $lapsedBefore) {
                    continue;
                }

                $exceptions[] = [
                    'name' => (string) $guard->full_name,
                    'reason' => $this->paidWhileLapsedReason($guard, $periodEnd),
                ];
            }

            foreach ($rostered as $guard) {
                $hiredInTime = $guard->hired_on === null || substr((string) $guard->hired_on, 0, 10) <= $end;

                if (! $hiredInTime || isset($onRun[(int) $run->id.':'.(int) $guard->id])) {
                    continue;
                }

                $exceptions[] = [
                    'name' => (string) $guard->full_name,
                    'reason' => $this->unpaidReason($guard),
                ];
            }

            usort($exceptions, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

            $out[(int) $run->id] = $exceptions;
        }

        return $out;
    }

    /** Why a guard who WAS paid on this run still needs a look. */
    private function paidWhileLapsedReason(object $guard, Carbon $periodEnd): string
    {
        $expiry = $this->expiry($guard);

        if ($expiry !== null && $expiry->lt($periodEnd)) {
            return sprintf(
                'PSRA licence expired %s, before the period closed on %s, but paid for the whole period.',
                $expiry->format('j M Y'),
                $periodEnd->format('j M Y'),
            );
        }

        /*
         * `status` from `exceptionsForRuns()`, `guard_status` from `show()` — which selects it under
         * that name beside the payslip's own columns. Reading only `status` made the run's page
         * a 500 for any paid guard who was suspended, inactive or marked licence-expired while
         * their licence was still in date: exactly the row this sentence exists to explain.
         */
        return sprintf(
            '%s on the roster, but paid for the whole period.',
            $this->statusLabel((string) ($guard->status ?? $guard->guard_status ?? '')),
        );
    }

    /** Why a guard with NO payslip on this run needs a look. */
    private function unpaidReason(object $guard): string
    {
        $expiry = $this->expiry($guard);

        return match ((string) $guard->status) {
            'licence_expired' => $expiry === null
                ? 'PSRA licence expired: no payslip in this run, so the period is unpaid.'
                : sprintf(
                    'PSRA licence expired %s: no payslip in this run, so the period is unpaid.',
                    $expiry->format('j M Y'),
                ),
            'suspended' => 'Suspended: no payslip in this run, so the period is unpaid.',
            default => 'On the roster for this period, with no payslip in this run.',
        };
    }

    private function licenceLapsedBefore(object $guard, Carbon $periodEnd): bool
    {
        $expiry = $this->expiry($guard);

        return $expiry !== null && $expiry->lt($periodEnd);
    }

    private function expiry(object $guard): ?Carbon
    {
        $raw = $guard->psra_expires_on ?? null;

        return $raw === null ? null : Carbon::parse((string) $raw);
    }

    /**
     * The run's status badge, in the one vocabulary (M96, M99), the count or the date after it: "Action required ·
     * 3 exceptions", "Paid · Oct 3". It said "3 exceptions", "Approved Oct 3" and "Calculated", none of them the
     * vocabulary's. A run approved but not yet paid (the decision recorded, the transfer made from Gemini's bank) is a
     * workflow state with a money flag beside it, as Overdue sits beside one (Hassam's ruling, 11 Oct): "Confirmed ·
     * Unpaid".
     *
     * @return array{class: string, label: string}
     */
    private function badgeFor(PayrollRun $run, int $exceptionCount): array
    {
        if ($exceptionCount > 0) {
            return [
                'class' => 'exception',
                'label' => 'Action required · '.$exceptionCount.' exception'.($exceptionCount === 1 ? '' : 's'),
            ];
        }

        // Gemini's payroll, dated on Gemini's calendar: `approved_at` is UTC (M11 §4.2).
        $on = $run->approved_at === null ? '' : ' · '.PlatformCalendar::local($run->approved_at)->format('M j');

        return match ($run->status) {
            'paid' => ['class' => 'approved', 'label' => 'Paid'.$on],
            'approved' => ['class' => 'approved', 'label' => 'Confirmed · Unpaid'],
            'calculated' => ['class' => 'pending', 'label' => 'Pending'],
            default => ['class' => 'draft', 'label' => 'Draft'],
        };
    }

    /** Two characters, uppercase, as the board draws an avatar. */
    private function initials(string $name): string
    {
        $parts = array_values(array_filter(explode(' ', $name)));

        return strtoupper(implode('', array_map(
            static fn (string $part): string => mb_substr($part, 0, 1),
            array_slice($parts, 0, 2),
        )));
    }

    private function statusLabel(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }
}
