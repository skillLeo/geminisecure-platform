<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\StatutoryRateVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The Jamaican statutory rate card, as the client ruled it on Q-002 (D-082).
 *
 * THE THRESHOLD CHANGES EVERY 1 APRIL, AND INCOME TAX IS ASSESSED ON A CALENDAR
 * YEAR. So a calendar year spans two versions — January to March 2026 is the
 * 2025-04 card, April to December the 2026-04 one — and a pay run takes the
 * version in force on its pay date. That is what `StatutoryRateVersion::
 * forPayDate()` is for, and why this seeds the versions either side of each
 * 1 April rather than "the current rates".
 *
 * TAJ'S PERIODIC FIGURES ARE STORED, NEVER DERIVED. They do not equal the annual
 * threshold divided by 12, 26 or 52 — TAJ rounds differently — and the ruling is
 * explicit: store what TAJ publishes. The 2025-04 and 2026-04 cards carry all
 * three and are verified. The 2024-04 and 2027-04 cards were ruled on their
 * annual figure alone; no periodic figures were supplied, so they are seeded
 * UNVERIFIED. The engine can still calculate against them by division, and no run
 * can be approved against them until somebody records TAJ's published figures —
 * which reuses the approval block this platform already has, rather than inventing
 * a second one for the same risk.
 *
 * NOT COUNTERSIGNED. These figures are from TAJ publications and the client's
 * ruling on method, and reconcile to the cent against both worked payslips. The
 * client's accountant has not signed them. `verified_by` says so, and the first
 * live pay run carries an explicit acknowledgement for the same reason (Part F).
 *
 * IDEMPOTENT, and it never edits a version a run may have used. Superseding the
 * provisional card is a flag, not an edit to its figures.
 */
class StatutoryRatesSeeder extends Seeder
{
    /**
     * What every version shares. Only the thresholds move each April.
     *
     * Basis points throughout — 3% is 300 — so no float touches a rate.
     *
     * @var array<string, int>
     */
    private const RATES = [
        'nis_employee_bp' => 300,
        'nis_employer_bp' => 300,
        'nis_ceiling_annual_minor' => 5_000_000_00,
        'nht_employee_bp' => 200,
        'nht_employer_bp' => 300,
        'education_tax_employee_bp' => 225,
        'education_tax_employer_bp' => 350,
        'paye_bp' => 2500,
        'paye_higher_bp' => 3000,
        'paye_higher_band_annual_minor' => 6_000_000_00,
        'heart_employer_bp' => 300,

        // ASSUMPTION Q-016 — the HEART monthly payroll floor. Ruled (13 §0):
        // zero, confirmed — HEART is 3% of gross emoluments for every employer,
        // always. Kept marked so the note for the accountant stays on the line.
        'heart_monthly_floor_minor' => 0,
    ];

    /**
     * The four cards the ruling tabulated, in date order.
     *
     * @var list<array{from: string, to: string|null, label: string, annual: int, monthly: int|null, fortnightly: int|null, weekly: int|null, verified: bool}>
     */
    private const VERSIONS = [
        [
            'from' => '2024-04-01',
            'to' => '2025-03-31',
            'label' => 'TAJ 2024/25 — annual threshold only',
            'annual' => 1_700_088_00,
            'monthly' => null,
            'fortnightly' => null,
            'weekly' => null,
            'verified' => false,
        ],
        [
            'from' => '2025-04-01',
            'to' => '2026-03-31',
            'label' => 'TAJ 2025/26',
            'annual' => 1_799_376_00,
            'monthly' => 149_948_00,
            'fortnightly' => 69_307_26,
            'weekly' => 34_603_00,
            'verified' => true,
        ],
        [
            'from' => '2026-04-01',
            'to' => '2027-03-31',
            'label' => 'TAJ 2026/27',
            'annual' => 1_902_360_00,
            'monthly' => 158_530_00,
            'fortnightly' => 73_234_90,
            'weekly' => 36_583_85,
            'verified' => true,
        ],
        [
            'from' => '2027-04-01',
            'to' => null,
            'label' => 'TAJ 2027/28 — annual threshold only',
            'annual' => 2_003_496_00,
            'monthly' => null,
            'fortnightly' => null,
            'weekly' => null,
            'verified' => false,
        ],
    ];

    /** Who the verified cards are signed off by — and, honestly, who they are not. */
    public const VERIFIED_BY = 'TAJ published tables, per the client ruling on Q-002. Not countersigned by '
        ."the client's accountant — the first live pay run carries that acknowledgement.";

    public function run(): void
    {
        /*
         * The provisional card from before the ruling. Superseded, not deleted
         * and not edited: pay runs point at it, and changing its figures would
         * silently restate every payslip it produced.
         */
        StatutoryRateVersion::query()
            ->whereNull('superseded_at')
            ->where('label', 'like', 'Provisional%')
            ->update([
                'superseded_at' => now(),
                'superseded_note' => 'Superseded by the TAJ cards seeded under the Q-002 ruling (D-082). '
                    .'Kept so the runs that used it still reproduce exactly.',
            ]);

        foreach (self::VERSIONS as $row) {
            $version = StatutoryRateVersion::query()
                ->whereNull('superseded_at')
                ->whereDate('effective_from', $row['from'])
                ->first() ?? new StatutoryRateVersion;

            $version->forceFill([
                ...self::RATES,
                'label' => $row['label'],
                'effective_from' => $row['from'],
                'effective_to' => $row['to'],
                'paye_threshold_annual_minor' => $row['annual'],
                'paye_threshold_monthly_minor' => $row['monthly'],
                'paye_threshold_fortnightly_minor' => $row['fortnightly'],
                'paye_threshold_weekly_minor' => $row['weekly'],
                'is_verified' => $row['verified'],
                'verified_by' => $row['verified'] ? self::VERIFIED_BY : null,
                'verified_on' => $row['verified'] ? ($version->verified_on ?? Carbon::today()) : null,
            ])->save();
        }
    }
}
