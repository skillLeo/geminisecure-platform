<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Document;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\StatutoryFiling;
use App\Services\Documents\Documents;
use App\Services\Estate\Payroll;
use App\Services\Exports\Exporter;
use App\Services\Payroll\PayrollFileFormats;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The estate's own staff payroll — boards 13, 14, 15, 16 and 37.
 *
 * THE PROPERTY MANAGER CANNOT REACH ANY OF THIS, and one of them is on the
 * payroll. Patricia Morgan is board 15's first line and board 37's first row,
 * and D-010 locks her role out of `payroll` entirely: being ON a payroll is not
 * permission to SEE one, least of all everybody else's gross pay. The gate is
 * the route's, not this controller's, and `EstatePayrollTest` asserts the 403
 * rather than assuming the middleware is there.
 *
 * APPROVAL IS OPEN, AND THE FIRST ONE ASKS FOR A TICK. Q-002 is ruled (D-082):
 * the 2026-04 card carries TAJ's published thresholds and is verified, so a
 * calculated run can be approved by somebody who holds `estate.payroll.approve_runs`
 * and did not prepare it. The first live run in an estate also asks the approver to
 * confirm it was reconciled against current TAJ tables, naming the card — a
 * recorded acknowledgement, not a block. A refusal still reaches the page as text
 * on the control: a disabled button with nothing on it reads as a bug.
 */
class PayrollController extends Controller
{
    /** Board 13 — the run list. */
    public function runs(Request $request, Payroll $payroll): Response
    {
        return inertia('Estate/Payroll/Runs', [
            'estate' => ['name' => (string) tenant()->name],
            // M32 F5: newest period first unless the headings ask otherwise, a page at a time.
            // With `calendar`: the next period, and why it cannot be started if it cannot, so the button and the
            // service refuse alike. From the runs the board already reads (the run list's query budget, 12 Oct).
            ...$payroll->runsBoard(Payroll::runsTable($request), $request->user()),
            'tabs' => $this->tabs('runs'),
            'canCreate' => $request->user()->can('estate.payroll.create'),
            'reasons' => [
                'create' => 'Starting a run needs Payroll create access, which this role does not hold.',
            ],
        ]);
    }

    /** Start the next run on the calendar — board 13's "Start new run" (12 §2, Wave 1). */
    public function startRun(Request $request, Payroll $payroll): RedirectResponse
    {
        try {
            $run = $payroll->startRun($request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return redirect()
            ->to($this->path('/payroll/runs/'.$run->slug))
            ->with('success', $run->period_label.' started as a draft. Calculate it when the timesheets are in.');
    }

    /** Board 14 — what stops a run being calculated. */
    public function exceptions(Request $request, string $slug, Payroll $payroll): Response
    {
        $run = $this->resolveRun($slug);

        return inertia('Estate/Payroll/Exceptions', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->exceptionsBoard($run),
            'canResolve' => $request->user()->can('estate.payroll.update'),

            // M96 level 3: Calculate needs Payroll update, the route's own gate; a role without it sees no such button.
            'canCalculateRuns' => $request->user()->can('estate.payroll.update'),

            // M94 §1.1, item 2: said by the button, before the press, when calculating would leave nobody to approve.
            'calculateWarning' => $payroll->calculationWarning($run, $request->user()),
            'reasons' => [
                'resolve' => 'Resolving an exception changes what somebody is paid, so it needs Payroll '.
                    'update access, which this role does not hold.',
            ],
        ]);
    }

    /**
     * The run this URL names.
     *
     * Resolved by SLUG rather than id, because boards 14 and 15 address a run
     * by its period in their own URLs — /payroll/runs/sep-2026/exceptions and
     * /payroll/runs/aug-2026. A period is what a treasurer bookmarks and what
     * survives a reseed; an auto-increment id is neither.
     */
    private function resolveRun(string $slug): PayrollRun
    {
        return PayrollRun::query()->where('slug', $slug)->firstOrFail();
    }

    /** Where a screen lives, in whichever shape this environment serves. */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }

    /** Board 15 — one run, every figure tied to its own lines. */
    public function show(Request $request, string $slug, Payroll $payroll, PayrollFileFormats $formats, Documents $documents): Response
    {
        $run = $this->resolveRun($slug);
        $canExport = $request->user()->can('estate.payroll.export');

        return inertia('Estate/Payroll/Run', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->runBoard($run, $request->user(), Payroll::linesTable($request)),

            /*
             * THE FILES THIS RUN HAS LEFT IN, as kept (13 A2) — each downloadable
             * again, byte for byte, until its retention date. Shown only to a
             * role that may export: a bank file carries every account number.
             */
            'keptFiles' => $canExport ? $documents->forSubject('payroll_run', (string) $run->id) : [],

            /*
             * TWO FORMATS, CHOSEN AND NOT DEFAULTED (12 §1). The old reason
             * had it right: "a payslip file for a bank and a summary for an
             * accountant are different documents". One is an instruction to
             * move money and carries account numbers; the other is a record of
             * what was paid and carries deductions.
             */
            'exportFormats' => $formats->catalogue(),
            'canExport' => $canExport,
            'exportBlockedReason' => 'Downloading payroll files needs Payroll export access.',
        ]);
    }

    /**
     * A pay run as a file — board 15's Export (12 §1).
     *
     * NOTHING BUT AN APPROVED RUN LEAVES. A draft is arithmetic nobody has
     * agreed; a bank file built from one would be an instruction to pay figures
     * the committee has not seen, and a summary from one would be a record of a
     * payment that has not happened.
     */
    public function exportRun(Request $request, string $slug, PayrollFileFormats $formats, Exporter $exporter, Documents $documents): StreamedResponse|RedirectResponse
    {
        $run = $this->resolveRun($slug);

        try {
            $format = $formats->find($request->string('format')->toString());
        } catch (DomainException $refused) {
            return back()->withErrors(['format' => $refused->getMessage()]);
        }

        /*
         * `PAID` IS THE APPROVED STATE on this platform — board 15's one
         * irreversible control is "Approve & submit for payment", and it takes
         * a run straight there. Anything else is arithmetic nobody has agreed.
         */
        if ($run->status !== PayrollRun::PAID) {
            return back()->withErrors(['format' => $run->period_label.' has not been approved. A file built from a draft is an instruction to pay figures nobody has agreed.']);
        }

        $lines = $run->lines()->with('employee')->orderByDesc('gross_minor')->get();
        $contents = $format->build($run, $lines);

        /*
         * KEPT BEFORE IT LEAVES (13 A2). The copy is written and its hash
         * checked first, so a file that could not be kept is not released: the
         * estate must be able to produce in year three the exact bank file that
         * moved the money today.
         */
        try {
            $kept = $documents->keep(
                kind: $format->key() === 'bank' ? Document::PAYROLL_BANK_FILE : Document::PAYROLL_SUMMARY,
                title: $run->period_label.' pay run · '.$format->label(),
                filename: $format->filename($run),
                contents: $contents,
                contentType: $format->contentType(),
                by: $request->user(),
                subjectType: 'payroll_run',
                subjectId: (string) $run->id,
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['format' => $refused->getMessage()]);
        }

        return $exporter->file(
            scope: $run->period_label.' pay run · '.$format->label(),
            contents: $contents,
            filename: $format->filename($run),
            contentType: $format->contentType(),
            rowCount: $lines->count(),
            kept: $kept,
        );
    }

    /** Board 16 — the returns the estate owes. */
    public function filings(Request $request, Payroll $payroll): Response
    {
        return inertia('Estate/Payroll/Filings', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->filingsBoard(),
            'tabs' => $this->tabs('filings'),
            // Its own approval since M11 B3 (D-150), not the power to release pay.
            'canFile' => $request->user()->can('estate.payroll.file_returns'),
            'reasons' => [
                'file' => 'Filing a return remits money to the revenue authority, so it needs the File statutory '.
                    'returns permission on Payroll & HR, which this role does not hold.',
            ],
        ]);
    }

    /**
     * Board 16's "Compliance calendar" (12 §2, Wave 4) — the register's returns
     * and the monthly ones projected for the year ahead. A read; see
     * `Payroll::complianceCalendar()`.
     */
    public function calendar(Payroll $payroll): Response
    {
        return inertia('Estate/Payroll/Calendar', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->complianceCalendar(),
            'tabs' => $this->tabs('filings'),
        ]);
    }

    /** Board 37 — the employee register. */
    public function employees(Request $request, Payroll $payroll): Response
    {
        return inertia('Estate/Payroll/Employees', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->employeesBoard(Payroll::employeesTable($request)),
            'tabs' => $this->tabs('employees'),
            'canCreate' => $request->user()->can('estate.payroll.create'),
            'reasons' => [
                'create' => 'Adding an employee puts somebody on a payroll, so it needs Payroll create '.
                    'access, which this role does not hold.',
            ],
        ]);
    }

    /**
     * Add an employee — board 37, behind the consent checkbox (12 §1).
     *
     * The consent is required and it is recorded with the officer who took it.
     * The bank details are not required: an employee can go on the register
     * before their bank sends the account, and a register that refused to list
     * them would understate the estate's wage bill.
     */
    public function addEmployee(Request $request, Payroll $payroll): RedirectResponse
    {
        /*
         * Two decimals and a ceiling on both amounts (bug hunt B022, B081):
         * `numeric` alone let "185000.005" through to `Money::of`, which refused
         * it with an exception nobody caught, and the office got a 500.
         */
        $fields = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'job_title' => ['required', 'string', 'max:80'],
            'employment_type' => ['required', 'string', 'in:full_time,part_time,contract'],
            'monthly_rate' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999.99'],
            'approved_pension' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999.99'],
            // The ESTATE's today, not the UTC calendar's, which is a day ahead from 7 PM in Kingston (B023).
            'employed_since' => ['nullable', 'date', 'before_or_equal:'.SiteTime::today()->toDateString()],
            'bank_name' => ['nullable', 'string', 'max:60'],
            'bank_account_number' => ['nullable', 'string', 'max:32'],
            'nis_number' => ['nullable', 'string', 'max:32'],
            'consent' => ['accepted'],
        ], [
            'monthly_rate.decimal' => 'A monthly rate is dollars and cents, at most two decimal places, as "185000.00".',
            'monthly_rate.max' => 'That is more than any monthly rate this payroll pays. Check the digits.',
            'approved_pension.decimal' => 'A pension contribution is dollars and cents, at most two decimal places, as "4000.00".',
            'approved_pension.max' => 'That is more than any pension contribution this payroll withholds. Check the digits.',
        ]);

        try {
            $employee = $payroll->addEmployee($fields, $request->user());
        } catch (DomainException $e) {
            // On the field the refusal is about, so the sentence sits beside the figure that caused it.
            $field = match (true) {
                str_contains($e->getMessage(), 'pension') => 'approved_pension',
                str_contains($e->getMessage(), 'monthly rate') => 'monthly_rate',
                default => 'full_name',
            };

            return back()->withErrors([$field => $e->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/payroll/employees'))
            ->with('success', $employee->full_name.' added to the payroll. They appear on the next run calculated; nothing has posted.');
    }

    /* ------------------------------------------------------------------ */
    /* the writes */
    /* ------------------------------------------------------------------ */

    public function calculate(Request $request, string $slug, Payroll $payroll): RedirectResponse
    {
        $run = $this->resolveRun($slug);

        try {
            // Who calculated it is recorded: they may not also approve it (B025).
            $payroll->calculate($run, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        // M94 §1.1: who approves it now, or what stops them, said at the moment it is calculated, as one sentence.
        $next = str_replace("\n", ' ', (string) $payroll->approvalNext($run->refresh(), $request->user()));

        return back()->with('success', trim('Payslips calculated. Nothing has been paid yet. '.$next));
    }

    public function approve(Request $request, string $slug, Payroll $payroll): RedirectResponse
    {
        try {
            $payroll->approve($this->resolveRun($slug), $request->user(), $request->boolean('reconciled'));
        } catch (DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('success', 'Run approved and posted.');
    }

    public function requestChanges(Request $request, string $slug, Payroll $payroll): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:190']]);

        try {
            $payroll->requestChanges($this->resolveRun($slug), $data['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('success', 'Sent back to whoever prepared it, with your reason.');
    }

    public function resolveException(Request $request, PayrollException $exception, Payroll $payroll): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'string', 'in:resolved,excluded'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $payroll->resolveException(
                $exception,
                $data['outcome'],
                $data['note'] ?? null,
                $request->user(),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['exception' => $e->getMessage()]);
        }

        return back()->with('success', 'Exception recorded.');
    }

    public function file(Request $request, StatutoryFiling $filing, Payroll $payroll): RedirectResponse
    {
        try {
            $payroll->fileReturn($filing, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['filing' => $e->getMessage()]);
        }

        return back()->with('success', 'Filed, and the deductions it remits are cleared.');
    }

    /**
     * The module's three tabs. All three are built, so all three link.
     *
     * @return list<array{key: string, label: string}>
     */
    private function tabs(string $active): array
    {
        return array_map(
            static fn (array $tab): array => [...$tab, 'active' => $tab['key'] === $active],
            [
                ['key' => 'runs', 'label' => 'Pay runs'],
                ['key' => 'employees', 'label' => 'Employees'],
                ['key' => 'filings', 'label' => 'Statutory filings'],
            ],
        );
    }
}
