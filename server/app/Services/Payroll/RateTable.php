<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\StatutoryRateVersion;
use App\Support\MoneyFormatter;

/**
 * The rate table screen, assembled from the rates and a real pay run.
 *
 * D-021 IS THE SUBJECT OF THIS SCREEN, not a caveat on it.
 *
 * The rates the engine calculates with have never been checked against a
 * worked example by anyone qualified to check them, so they are a DRAFT and no
 * run may be approved against them. The screen therefore has to do two things
 * at once: show the arithmetic honestly enough that an accountant can check it,
 * and state plainly that nobody has. Both are here. What is deliberately NOT
 * here is any means of approving them.
 *
 * THE WORKED EXAMPLE IS A REAL PAYSLIP, NOT AN ILLUSTRATION.
 *
 * A rate table whose example is invented proves nothing: it demonstrates the
 * arithmetic somebody typed rather than the arithmetic the engine performed.
 * So the example is a payslip off the most recent run, and its six lines add up
 * to that payslip's net pay to the cent. If they ever stop adding up, the
 * screen is telling the truth about a real defect.
 *
 * WHICH payslip is a rule, not a pick: the one immediately ABOVE the PAYE
 * threshold, because that is the only kind of payslip on which all four
 * deductions are visible at once. Where every guard falls below it, the highest
 * earner stands in and the PAYE line says why it is zero.
 */
final class RateTable
{
    public function __construct(private readonly StatutoryRates $rates) {}

    /**
     * Everything screen 31 draws, or null when no rates have ever been
     * recorded - which is a real first-use state and not an error.
     *
     * @return array<string, mixed>|null
     */
    public function forVersion(?int $versionId = null): ?array
    {
        $version = $versionId === null ? $this->rates->current() : $this->rates->version($versionId);

        if ($version === null) {
            return null;
        }

        /*
         * THE NEWEST RUN CALCULATED ON THIS CARD (PAY-B-08, M85 A1). The newest run on the platform can be on another
         * card, and its payslip set under this card's rates is how the screen came to "disagree with the engine".
         */
        $run = PayrollRun::query()->where('statutory_rate_version_id', $version->id)->orderByDesc('period_start')->first();

        return [
            'version' => [
                'id' => $version->id,
                'tag' => $this->rates->tag($version),
                'label' => $version->label,
                'effective_from' => $version->effective_from->format('j M Y'),
                'is_draft' => ! $version->is_verified,
                'blocked_reason' => $this->rates->approvalBlockedReason($version),
            ],
            'deductions' => $this->rates->deductionLines($version),
            'worked_example' => $this->workedExample($version, $run),
            'threshold' => $this->threshold($version, $run),
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * One real payslip, taken apart line by line in the engine's own order.
     *
     * NIS first, because Education Tax and PAYE are both computed on income
     * after it. Listing them in any other order would describe a different
     * calculation from the one that produced the figures.
     *
     * @return array{head: string, rows: list<array{label: string, value: string, total: bool}>}|null
     */
    private function workedExample(StatutoryRateVersion $version, ?PayrollRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        $payslip = $this->justAboveThreshold($run) ?? $this->highestPaid($run);

        if ($payslip === null) {
            return null;
        }

        $name = $payslip->employee->full_name;
        $currency = $payslip->currency;

        return [
            // A colon, never an em dash (Law 3; the payroll pass).
            'head' => sprintf('Worked example: %s, %s', $name, $run->period_label),
            'rows' => [
                [
                    'label' => 'Gross pay',
                    'value' => MoneyFormatter::fromMinor($payslip->gross_minor, $currency),
                    'total' => false,
                ],
                [
                    'label' => sprintf('− NIS (%s of gross)', $this->rates->percent($version->nis_employee_bp)),
                    'value' => MoneyFormatter::fromMinor($payslip->nis_minor, $currency),
                    'total' => false,
                ],
                [
                    'label' => sprintf('− NHT (%s of gross)', $this->rates->percent($version->nht_employee_bp)),
                    'value' => MoneyFormatter::fromMinor($payslip->nht_minor, $currency),
                    'total' => false,
                ],
                [
                    'label' => sprintf(
                        '− Education Tax (%s of gross − NIS)',
                        $this->rates->percent($version->education_tax_employee_bp),
                    ),
                    'value' => MoneyFormatter::fromMinor($payslip->education_tax_minor, $currency),
                    'total' => false,
                ],
                [
                    /*
                     * A bare 0.00 on a payslip reads as a defect to the person
                     * holding it, so the line says which side of the threshold
                     * this guard fell on rather than leaving the reader to
                     * work it out from the figure.
                     */
                    'label' => $payslip->paye_minor > 0
                        ? sprintf('− PAYE (%s above the threshold)', $this->rates->percent($version->paye_bp))
                        : '− PAYE (below threshold)',
                    'value' => MoneyFormatter::fromMinor($payslip->paye_minor, $currency),
                    'total' => false,
                ],
                [
                    'label' => 'Net pay',
                    'value' => MoneyFormatter::fromMinor($payslip->net_minor, $currency),
                    'total' => true,
                ],
            ],
        ];
    }

    /**
     * The threshold, and the two guards nearest it on either side.
     *
     * The threshold on its own is a number. Set beside one guard who pays no
     * income tax and one who does, it becomes the rule it actually is - and
     * both of them are people on the current run rather than figures chosen to
     * make the point.
     *
     * @return array{rows: list<array{label: string, value: string, total: bool}>}
     */
    private function threshold(StatutoryRateVersion $version, ?PayrollRun $run): array
    {
        // A card nobody has run: its own periodic figures, never an assumed frequency (M85 A1).
        if ($run === null) {
            $rows = [];

            foreach (StatutoryRateVersion::FREQUENCIES as $periods => $name) {
                $rows[] = [
                    'label' => 'Tax-free threshold, a '.$name,
                    'value' => MoneyFormatter::fromMinor($this->rates->thresholdPerPeriod($version, $periods)),
                    'total' => false,
                ];
            }

            return ['rows' => $rows];
        }

        $thresholdMinor = $this->rates->thresholdPerPeriod($version, $run->periods_per_year);

        $rows = [[
            'label' => 'Tax-free threshold, per pay period',
            'value' => MoneyFormatter::fromMinor($thresholdMinor),
            'total' => false,
        ]];

        $below = $this->justBelowThreshold($run);
        $above = $this->justAboveThreshold($run);

        if ($below !== null) {
            $rows[] = [
                'label' => $this->possessive($below->employee->full_name).' gross',
                'value' => MoneyFormatter::fromMinor($below->gross_minor, $below->currency).', below the threshold',
                'total' => false,
            ];
        }

        if ($above !== null) {
            $rows[] = [
                'label' => $this->possessive($above->employee->full_name).' gross',
                'value' => MoneyFormatter::fromMinor($above->gross_minor, $above->currency).', above the threshold',
                'total' => false,
            ];

            $rows[] = [
                'label' => $this->possessive($above->employee->full_name).' PAYE this period',
                'value' => MoneyFormatter::fromMinor($above->paye_minor, $above->currency),
                'total' => true,
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * The smallest payslip on the run that still attracted PAYE.
     *
     * Nearest the threshold from above, so the gap between it and the guard
     * below is as small as the run allows - which is what makes the pair read
     * as a threshold rather than as two unrelated salaries.
     */
    private function justAboveThreshold(PayrollRun $run): ?Payslip
    {
        return $run->payslips()
            ->with('employee')
            ->where('paye_minor', '>', 0)
            ->orderBy('gross_minor')
            ->first();
    }

    /** The largest payslip on the run that attracted none. */
    private function justBelowThreshold(PayrollRun $run): ?Payslip
    {
        return $run->payslips()
            ->with('employee')
            ->where('paye_minor', '=', 0)
            ->orderByDesc('gross_minor')
            ->first();
    }

    /** The stand-in when nobody on the run reaches the threshold at all. */
    private function highestPaid(PayrollRun $run): ?Payslip
    {
        return $run->payslips()->with('employee')->orderByDesc('gross_minor')->first();
    }

    /**
     * "Marcus Whyte's", and "Marcus Whytes'" for a name already ending in s.
     *
     * A small thing, and the screen prints it three times on one panel.
     */
    private function possessive(string $name): string
    {
        return str_ends_with($name, 's') ? $name."'" : $name."'s";
    }
}
