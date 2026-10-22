<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\StatutoryRateVersion;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;

/**
 * What the calculation engine is allowed to say about its own rates.
 *
 * Q-002 IS RULED (D-082). The cards in force carry TAJ's published periodic
 * thresholds and are verified, so a run calculated against one may be approved.
 * A card seeded from its annual figure alone is not verified, and a run on it
 * may be calculated for review but never approved — the same block the draft
 * card carried before the ruling, now reserved for the case it actually guards.
 * This class exists so every screen states that in the same words.
 *
 * There is deliberately no method here that approves anything.
 *
 * Rates are basis points throughout - 3% is 300 - so a percentage is formatted
 * by integer division, never by dividing by 100 into a float. A rate that
 * round-trips through a float is a rate that eventually disagrees with the
 * payslip it produced.
 */
final class StatutoryRates
{
    /**
     * Why approval is blocked, in the words every screen uses.
     *
     * Named as a constant rather than written into each controller so that the
     * day the accountant signs off, the sentence disappears from the whole
     * console at once instead of surviving in the one screen somebody missed.
     */
    public const BLOCKED_REASON = 'This rate card carries no verified TAJ periodic figures, so no run calculated '
        ."against it can be approved. Record TAJ's published thresholds for the card and verify it.";

    /**
     * The version the engine is calculating with today.
     *
     * Null only on an installation where no rates have ever been recorded,
     * which is a real first-use state and not an error - the engine simply
     * cannot calculate until somebody enters a version.
     */
    public function current(?string $on = null): ?StatutoryRateVersion
    {
        return StatutoryRateVersion::inForceOn($on ?? PlatformCalendar::today()->toDateString());
    }

    /** A specific version, for a screen that is linked to one. */
    public function version(int $id): ?StatutoryRateVersion
    {
        return StatutoryRateVersion::query()->find($id);
    }

    /**
     * The tag a version is known by: "2026-04-DRAFT".
     *
     * Built from the effective date and the verification flag rather than from
     * the stored label, because the label is prose somebody typed and the two
     * halves of this tag are facts. A version that has been signed off reads
     * 2026-04-APPROVED, and nothing else in the console has to change for that
     * to happen.
     */
    public function tag(StatutoryRateVersion $version): string
    {
        return $version->effective_from->format('Y-m').'-'.($version->is_verified ? 'APPROVED' : 'DRAFT');
    }

    /**
     * Whether this version may be approved, and if not, why not: never before it takes effect, on Gemini's own calendar
     * (Hassam, 12 Oct 2026; D-234), and never without verified figures.
     */
    public function approvalBlockedReason(StatutoryRateVersion $version): ?string
    {
        if ($version->takesEffectAfter(PlatformCalendar::today())) {
            return $version->notYetInForceReason();
        }

        return $version->is_verified ? null : self::BLOCKED_REASON;
    }

    /**
     * The four deductions, in the order the engine applies them.
     *
     * ORDER IS LOAD-BEARING and is the same order PayrollCalculator uses: NIS
     * first, because Education Tax and PAYE are both computed on income after
     * it. The screen listing them in a different order would be describing a
     * different calculation from the one that produced the payslips.
     *
     * @return list<array{key: string, icon: string, name: string, basis: string, rate: string}>
     */
    public function deductionLines(StatutoryRateVersion $version): array
    {
        return [
            [
                'key' => 'nis',
                'icon' => 'guards',
                // Plain words first, the abbreviation after, no em dash (Law 3; the payroll pass). Names only: no
                // rate, threshold or ceiling here changes.
                'name' => 'National Insurance Scheme (NIS)',
                'basis' => sprintf(
                    'Employee contribution on gross, to a %s annual ceiling',
                    MoneyFormatter::whole($version->nis_ceiling_annual_minor),
                ),
                'rate' => $this->percent($version->nis_employee_bp),
            ],
            [
                'key' => 'nht',
                'icon' => 'clients',
                'name' => 'National Housing Trust (NHT)',
                'basis' => 'Employee contribution, on total gross earnings',
                'rate' => $this->percent($version->nht_employee_bp),
            ],
            [
                'key' => 'education_tax',
                'icon' => 'ledger',
                'name' => 'Education Tax',
                'basis' => 'On gross earnings minus NIS contribution',
                'rate' => $this->percent($version->education_tax_employee_bp),
            ],
            [
                'key' => 'paye',
                'icon' => 'calendar',
                'name' => 'Income Tax (PAYE)',
                // The 30% band from the card, never a constant (PAY-S-16, M85 A1).
                'basis' => sprintf(
                    '%s above the tax-free threshold for the pay period; %s on statutory income above %s a year',
                    $this->percent($version->paye_bp),
                    $this->percent($version->paye_higher_bp),
                    MoneyFormatter::whole($version->paye_higher_band_annual_minor),
                ),
                'rate' => $this->percent($version->paye_bp).' / '.$this->percent($version->paye_higher_bp),
            ],
        ];
    }

    /**
     * The tax-free threshold for ONE pay period, in minor units.
     *
     * TAJ's PUBLISHED periodic figure — 73,234.90 a fortnight on the 2026-04
     * card — not the annual threshold divided, which would give 73,167.69 (Q-002,
     * ruled). Delegated to the version so this screen and the payslip it
     * explains read the same number from the same place and cannot disagree by
     * a cent.
     */
    public function thresholdPerPeriod(StatutoryRateVersion $version, int $periodsPerYear): int
    {
        return $periodsPerYear < 1 ? 0 : $version->thresholdPerPeriod($periodsPerYear);
    }

    /**
     * A basis-point rate as a percentage: 300 becomes "3%", 225 becomes "2.25%".
     *
     * Integer arithmetic on the whole and fractional parts separately. Trailing
     * zeros are dropped, so the four rates read 3%, 2%, 2.25% and 25% rather
     * than a mixture of 3.0% and 25%.
     */
    public function percent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $hundredths = $basisPoints % 100;

        if ($hundredths === 0) {
            return $whole.'%';
        }

        // 25 hundredths is ".25"; 20 hundredths is ".2", not ".20".
        $fraction = $hundredths % 10 === 0 ? (string) intdiv($hundredths, 10) : str_pad((string) $hundredths, 2, '0', STR_PAD_LEFT);

        return $whole.'.'.$fraction.'%';
    }
}
