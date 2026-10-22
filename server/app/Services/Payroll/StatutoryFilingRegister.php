<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\StatutoryFiling;
use App\Services\Audit\AuditLogger;
use App\Support\PlatformCalendar;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The register of returns Gemini Security owes the Jamaican authorities.
 *
 * Everything this screen shows is a fact about a record plus a rule about the
 * calendar, and both live here rather than in the controller: whether a return
 * is owed yet, whether it is late, and whether a new one can be prepared at all.
 *
 * THE ONE RULE THAT GOVERNS THIS MODULE (Build Spec, screen 30):
 *
 *     Amounts derive from APPROVED runs only. A draft run never appears in a
 *     filing.
 *
 * A return is prepared from an APPROVED run and never from a calculated one — a
 * remittance is a payment to the tax authority, and paying a figure nobody has
 * approved is exactly the failure this rule exists to prevent. Q-002 is ruled
 * (D-082), so runs on a verified card can now be approved; `blockedReason()`
 * says which of the remaining conditions applies, and the screen's one control
 * carries that sentence.
 *
 * THERE IS NO EDIT AND NO DELETE HERE, NOT EVEN A DISABLED ONE. A filed return
 * is a posted record (invariant 4). A greyed-out delete would tell the reader
 * that deletion is a thing this system does and they simply lack the right to
 * do it, which is false: it is a thing this system does not do.
 */
final class StatutoryFilingRegister
{
    /**
     * What each return covers, said in the words the return itself uses.
     *
     * A map rather than a column: this is a fact about the FORM, identical on
     * every row that shares a code, and storing it per row would let two
     * September remittances disagree about what a September remittance is.
     *
     * @var array<string, string>
     */
    private const COVERAGE = [
        // The ruling's own list: the employer's share is on the same return.
        'S01' => 'PAYE, NIS, NHT, Education Tax, HEART',
        'S02' => "reconciles the month's remittance",
        'P24' => 'covers all guards deployed that year',
    ];

    public function __construct(
        private readonly StatutoryRates $rates,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * "Start new filing" (12 §2, Wave 4): prepare the S01 for the oldest
     * approved run that no return covers yet.
     *
     * FROM THE RUN'S PAYSLIPS, IN MINOR UNITS, BOTH HALVES. The four employee
     * deductions and the four employer contributions (Q-002, ruled), summed
     * from the payslips of an APPROVED run and never a calculated one — the
     * rule this register exists to keep.
     *
     * PREPARED, NOT FILED. The row is owed once its period has closed; filing
     * it is submitting it to TAJ, which is a separate act this platform records
     * afterwards and never performs.
     *
     * AN OWED ROW ALREADY ON THE REGISTER IS COMPLETED, NOT DUPLICATED. The
     * register carries a return for a closed month before any run could be
     * approved for it, with no figures. Preparing that month fills that row in;
     * a second S01 for one period would be two remittances for one month.
     */
    public function prepareNext(): StatutoryFiling
    {
        $reason = $this->blockedReason();

        if ($reason !== null) {
            throw new DomainException($reason);
        }

        $run = PayrollRun::query()
            ->whereIn('status', ['approved', 'paid'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('statutory_filings')
                    ->whereColumn('statutory_filings.payroll_run_id', 'payroll_runs.id');
            })
            ->orderBy('period_start')
            ->firstOrFail();

        $slips = $run->payslips()->get();

        $amounts = [
            'nis_minor' => (int) $slips->sum('nis_minor'),
            'nht_minor' => (int) $slips->sum('nht_minor'),
            'education_tax_minor' => (int) $slips->sum('education_tax_minor'),
            'paye_minor' => (int) $slips->sum('paye_minor'),
            'employer_nis_minor' => (int) $slips->sum('employer_nis_minor'),
            'employer_nht_minor' => (int) $slips->sum('employer_nht_minor'),
            'employer_education_tax_minor' => (int) $slips->sum('employer_education_tax_minor'),
            'heart_minor' => (int) $slips->sum('employer_heart_minor'),
        ];

        $attributes = [
            'form_title' => 'Statutory Deduction Remittance',
            'period_label' => $run->period_start->format('F Y'),
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),

            // Due by the 14th of the month after the period, the rule every
            // S01 on this register was given.
            'due_on' => $run->period_start->copy()->addMonthNoOverflow()->day(14)->toDateString(),
            'status' => $run->period_end->lte(PlatformCalendar::today()) ? StatutoryFiling::DUE : StatutoryFiling::NOT_STARTED,
            'payroll_run_id' => $run->id,
            'employees_covered' => $slips->count(),
            ...$amounts,
            'total_minor' => array_sum($amounts),
            'currency' => $run->currency,
        ];

        return DB::connection('mysql')->transaction(function () use ($run, $attributes): StatutoryFiling {
            $filing = StatutoryFiling::query()
                ->where('form_code', 'S01')
                ->whereDate('period_start', $run->period_start->toDateString())
                ->where('status', '!=', StatutoryFiling::FILED)
                ->whereNull('payroll_run_id')
                ->lockForUpdate()
                ->first();

            $before = $filing?->only(['status', 'payroll_run_id', 'total_minor']);

            $filing ??= new StatutoryFiling(['form_code' => 'S01']);
            $filing->fill($attributes)->save();

            $this->audit->record(
                action: 'payroll.filing_prepared',
                entityType: 'StatutoryFiling',
                entityId: (string) $filing->id,
                before: $before,
                after: [
                    'form' => 'S01',
                    'period' => $filing->period_label,
                    'run' => $run->reference,
                    'employees' => $filing->employees_covered,
                    'total_minor' => $filing->total_minor,
                ],
            );

            return $filing;
        });
    }

    /**
     * The register, in the order the board reads it.
     *
     * Outstanding first, because a return that is owed is the only thing on
     * this screen anyone has to act on. Then what has been filed, newest
     * obligation first. Then what is not owed yet, which is the annual return
     * sitting quietly at the bottom until the year closes.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(?int $year = null): array
    {
        $today = PlatformCalendar::today();

        $query = StatutoryFiling::query();

        if ($year !== null) {
            $query->whereYear('period_start', $year);
        }

        $filings = $query->get();

        $rows = $filings->map(fn (StatutoryFiling $filing): array => $this->row($filing, $today))->all();

        usort($rows, function (array $a, array $b): int {
            /** @var int $bucketA */
            $bucketA = $a['sort_bucket'];
            /** @var int $bucketB */
            $bucketB = $b['sort_bucket'];

            if ($bucketA !== $bucketB) {
                return $bucketA <=> $bucketB;
            }

            /** @var string $dueA */
            $dueA = $a['sort_due'];
            /** @var string $dueB */
            $dueB = $b['sort_due'];

            if ($dueA !== $dueB) {
                return strcmp($dueA, $dueB);
            }

            /** @var string $codeA */
            $codeA = $a['sort_code'];
            /** @var string $codeB */
            $codeB = $b['sort_code'];

            return strcmp($codeA, $codeB);
        });

        return $rows;
    }

    /**
     * Why a new return cannot be prepared, or null when one can.
     *
     * Separate conditions, and the reader is told which one applies rather than
     * being given a generic refusal. Approval blocked by an unverified card is a
     * decision somebody has still to make; having no closed period left to file
     * is simply being up to date, and reads very differently.
     *
     * AN APPROVED RUN NO RETURN COVERS IS THE ANSWER ON ITS OWN (B083). A run is
     * approved once, on its own card, and `GuardPayrollApproval` refuses one on
     * an unverified card — so whatever card is in force TODAY has nothing to say
     * about it. This used to ask today's card first: from 1 April the card in
     * force is the new tax year's, seeded unverified until somebody records TAJ's
     * periodic figures, so the March S01 (due 14 April, from a run approved on
     * March's own verified card) could not be started, and the control said "no
     * run can be approved yet" with an approved run waiting.
     *
     * WHEN NOTHING APPROVED IS WAITING, THE CARD THAT MATTERS IS THE NEXT RUN'S:
     * the oldest calculated run's own card, or today's card when no run has been
     * calculated yet — the one the next run would be calculated on.
     */
    public function blockedReason(): ?string
    {
        if ($this->hasApprovedUnfiledRun()) {
            return null;
        }

        $waiting = PayrollRun::query()
            ->with('rateVersion')
            ->where('status', 'calculated')
            ->orderBy('period_start')
            ->first();

        if ($waiting !== null) {
            $blocked = $this->rates->approvalBlockedReason($waiting->rateVersion);

            return $blocked !== null
                ? sprintf('A return is prepared from an approved pay run, and the %s run cannot be approved yet. %s', $waiting->period_label, $blocked)
                : sprintf(
                    'A return is prepared from an approved pay run. The %s run is calculated and waiting for approval; '
                    .'its return can be prepared once it is approved.',
                    $waiting->period_label,
                );
        }

        $version = $this->rates->current();

        if ($version === null) {
            return 'No statutory rate version is recorded, so nothing can be calculated to file.';
        }

        $blocked = $this->rates->approvalBlockedReason($version);

        if ($blocked !== null) {
            return 'A return is prepared from an approved pay run, and no run can be approved yet. '.$blocked;
        }

        return 'Every approved pay run has already been filed. The next return can be prepared once the '
            .'current period closes and its run is approved.';
    }

    /**
     * The years the register holds anything for, newest first.
     *
     * Used by the empty-because-filtered state so it can say which years DO
     * have returns, rather than leaving the reader to guess at the URL.
     *
     * @return list<int>
     */
    public function years(): array
    {
        return StatutoryFiling::query()
            ->orderByDesc('period_start')
            ->pluck('period_start')
            ->map(fn (mixed $date): int => Carbon::parse((string) $date)->year)
            ->unique()
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------ */

    /**
     * One row, as the board draws it: icon, two lines of text, a date and a
     * badge. Nothing is clickable, because there is nothing a reader may do to
     * a posted record.
     *
     * @return array<string, mixed>
     */
    private function row(StatutoryFiling $filing, Carbon $today): array
    {
        $state = $this->stateOf($filing, $today);

        return [
            'id' => $filing->id,
            'icon' => $state['icon'],
            'warn' => $state['warn'],
            // The console's separator, never an em dash (Law 3; the payroll pass): "S01 · Statutory Deduction Remittance".
            'title' => $filing->form_code.' · '.$filing->form_title,
            'detail' => $this->detail($filing),
            'when' => $this->when($filing, $today),
            'badge_class' => $state['badge_class'],
            'badge_label' => $state['badge_label'],
            'note' => $state['note'],

            // Sorting keys, stripped by the controller before the row is sent.
            'sort_bucket' => $state['bucket'],
            'sort_due' => $filing->due_on->toDateString(),
            'sort_code' => $filing->form_code,
        ];
    }

    /**
     * What state a return is in, which is entirely a question about dates.
     *
     * @return array{bucket: int, icon: string, warn: bool, badge_class: string, badge_label: string, note: string|null}
     */
    private function stateOf(StatutoryFiling $filing, Carbon $today): array
    {
        if ($filing->isFiled()) {
            return [
                'bucket' => 1,
                'icon' => 'document',
                'warn' => false,
                'badge_class' => 'approved',
                // The one vocabulary (M96, M99): it said Filed, Overdue, Due soon and Not started.
                'badge_label' => 'Completed',
                'note' => $filing->confirmation_reference === null
                    ? 'Filed. This return is a posted record and cannot be edited or deleted.'
                    : sprintf(
                        'Filed under %s. This return is a posted record and cannot be edited or deleted.',
                        $filing->confirmation_reference,
                    ),
            ];
        }

        if ($filing->isOverdue($today)) {
            return [
                'bucket' => 0,
                'icon' => 'alert',
                'warn' => true,
                'badge_class' => 'exception',
                // Overdue is a flag beside the state, never instead of one (M99).
                'badge_label' => 'Action required · Overdue',
                'note' => sprintf('This return was due on %s and has not been filed.', $filing->due_on->format('j M Y')),
            ];
        }

        if ($filing->periodHasClosed($today)) {
            return [
                'bucket' => 0,
                'icon' => 'alert',
                'warn' => true,
                'badge_class' => 'pending',
                'badge_label' => 'Action required',
                'note' => sprintf(
                    'The period closed on %s. This return is due by %s.',
                    $filing->period_end->format('j M Y'),
                    $filing->due_on->format('j M Y'),
                ),
            ];
        }

        return [
            'bucket' => 2,
            'icon' => 'calendar',
            'warn' => false,
            'badge_class' => 'draft',
            'badge_label' => 'Pending',
            'note' => sprintf(
                'The period runs to %s. Nothing is owed until it closes.',
                $filing->period_end->format('j M Y'),
            ),
        ];
    }

    /**
     * The row's second line.
     *
     * A filed return needs only its period: it is settled, and what it covered
     * is on the return itself. An outstanding one says what it will cover and,
     * where the figures are not available yet, why.
     */
    private function detail(StatutoryFiling $filing): string
    {
        if ($filing->isFiled()) {
            return $filing->period_label;
        }

        $parts = [$filing->period_label];

        $coverage = self::COVERAGE[$filing->form_code] ?? null;

        if ($coverage !== null) {
            $parts[] = $coverage;
        }

        if ($filing->payroll_run_id === null && $filing->periodHasClosed(PlatformCalendar::today())) {
            $parts[] = 'awaiting an approved pay run';
        } elseif ($filing->employees_covered !== null) {
            $parts[] = $filing->employees_covered.' employees';
        }

        return implode(' · ', $parts);
    }

    /** "Due Sep 14", "Filed Aug 11", "Due Mar 31, 2027". */
    private function when(StatutoryFiling $filing, Carbon $today): string
    {
        if ($filing->filed_on !== null) {
            return 'Filed '.$this->shortDate($filing->filed_on, $today);
        }

        return 'Due '.$this->shortDate($filing->due_on, $today);
    }

    /**
     * A date without its year, unless the year is not this one.
     *
     * "Due Mar 31, 2027" has to carry the year or it reads as three weeks away
     * rather than eighteen months. "Due Sep 14" does not, and spelling it out
     * would be noise on every row.
     */
    private function shortDate(Carbon $date, Carbon $today): string
    {
        return $date->year === $today->year ? $date->format('M j') : $date->format('M j, Y');
    }

    /** Whether any approved run exists that no return has been filed against. */
    private function hasApprovedUnfiledRun(): bool
    {
        return PayrollRun::query()
            ->whereIn('status', ['approved', 'paid'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('statutory_filings')
                    ->whereColumn('statutory_filings.payroll_run_id', 'payroll_runs.id');
            })
            ->exists();
    }
}
