<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A dated set of statutory rates.
 *
 * Versioned data with effective dates, never constants. A payroll run records
 * the version it used so it reproduces exactly, to the cent, years later.
 *
 * Rates are basis points: 3% is 300. No float ever touches a wage.
 *
 * TAJ'S PERIODIC THRESHOLDS ARE STORED, NOT DERIVED (Q-002, ruled — D-082). The
 * published monthly, fortnightly and weekly figures are not the annual threshold
 * divided by 12, 26 or 52: 2026's fortnightly figure is 73,234.90 where the
 * division gives 73,167.69. `thresholdPerPeriod()` reads the published figure and
 * divides only when none was recorded — and a version with none recorded is
 * never verified, so a derived threshold can never reach an approved payslip.
 *
 * `forPayDate()` IS THE VERSION SELECTOR. The threshold changes every 1 April and
 * income tax is assessed on a calendar year, so which card a run uses is a
 * question about its pay date, asked once when the run is calculated and then
 * stored on the run.
 *
 * @property int $id
 * @property string $label
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $nis_employee_bp
 * @property int $nis_employer_bp
 * @property int $nis_ceiling_annual_minor
 * @property int $nht_employee_bp
 * @property int $nht_employer_bp
 * @property int $education_tax_employee_bp
 * @property int $education_tax_employer_bp
 * @property int $paye_bp
 * @property int $paye_threshold_annual_minor
 * @property int|null $paye_threshold_monthly_minor
 * @property int|null $paye_threshold_fortnightly_minor
 * @property int|null $paye_threshold_weekly_minor
 * @property int $paye_higher_bp
 * @property int $paye_higher_band_annual_minor
 * @property int $heart_employer_bp
 * @property int $heart_monthly_floor_minor
 * @property bool $is_verified
 * @property string|null $verified_by
 * @property Carbon|null $verified_on
 * @property Carbon|null $superseded_at
 * @property string|null $superseded_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StatutoryRateVersion query()
 *
 * @mixin \Eloquent
 */
class StatutoryRateVersion extends Model
{
    use CentralConnection;

    /**
     * The ruled defaults for the columns added under Q-002, mirrored from the
     * migration so a version built in memory — a test fixture, a preview —
     * behaves exactly as a stored one does rather than taxing everything above
     * a band of zero at 30%.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'paye_higher_bp' => 3000,
        'paye_higher_band_annual_minor' => 6_000_000_00,
        'heart_employer_bp' => 300,
        'heart_monthly_floor_minor' => 0,
    ];

    protected $fillable = [
        'label', 'effective_from', 'effective_to',
        'nis_employee_bp', 'nis_employer_bp', 'nis_ceiling_annual_minor',
        'nht_employee_bp', 'nht_employer_bp',
        'education_tax_employee_bp', 'education_tax_employer_bp',
        'paye_bp', 'paye_threshold_annual_minor',
        'paye_threshold_monthly_minor', 'paye_threshold_fortnightly_minor', 'paye_threshold_weekly_minor',
        'paye_higher_bp', 'paye_higher_band_annual_minor',
        'heart_employer_bp', 'heart_monthly_floor_minor',
        'is_verified', 'verified_by', 'verified_on',
        'superseded_at', 'superseded_note',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'verified_on' => 'date',
            'superseded_at' => 'datetime',
            'is_verified' => 'boolean',
        ];
    }

    /**
     * The version in force on a given date, or null when none is.
     *
     * Superseded versions are skipped: the provisional card seeded before the
     * Q-002 ruling shares the 2026-04 effective date with the real one, and a
     * selector that could return either would compute two different payslips
     * for the same person on the same day.
     */
    public static function inForceOn(string $date): ?self
    {
        return static::query()
            ->whereNull('superseded_at')
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * The version a run paid on this date must use — the selector.
     *
     * Refuses rather than guessing. A pay run with no card in force is a run
     * nobody can calculate, and quietly falling back to "the latest" would tax
     * a January run on April's threshold.
     */
    public static function forPayDate(Carbon|string $date): self
    {
        $day = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return static::inForceOn($day) ?? throw new DomainException(
            "No statutory rate version is in force on {$day}, so no payslip dated then can be calculated. "
            .'Record the TAJ card for that period first.'
        );
    }

    /**
     * WHETHER THIS CARD HAS NOT TAKEN EFFECT YET (Hassam, 12 Oct 2026; D-234): no run is calculated or approved against
     * a card whose effective date is still to come. A demonstration guard run for a September 2026 fortnight was
     * approved on the 2027/28 card, before the seeder chose its card by pay date (M85 A1); nothing in either engine
     * compared a card's date with today. `$today` is the engine's own calendar: the estate's (`Ledger::today()`) or
     * Gemini's (`PlatformCalendar::today()`).
     */
    public function takesEffectAfter(CarbonInterface $today): bool
    {
        return $this->effective_from->toDateString() > $today->toDateString();
    }

    /** Why a run cannot be calculated or approved against this card yet, in the words every screen uses. */
    public function notYetInForceReason(): string
    {
        // The date, not the label: two stored labels carry an em dash (Law 3), and the date is the reason.
        return sprintf(
            'This rate card takes effect on %s, which has not come yet, so no run can be calculated or approved against it.',
            $this->effective_from->format('M j, Y'),
        );
    }

    /** The pay frequencies TAJ publishes a threshold for: periods a year, and what one period is called (M85 A1). */
    public const FREQUENCIES = [12 => 'month', 26 => 'fortnight', 52 => 'week'];

    /** TAJ's published threshold for this pay frequency, or null if none was recorded. */
    public function publishedThresholdFor(int $periodsPerYear): ?int
    {
        return match ($periodsPerYear) {
            12 => $this->paye_threshold_monthly_minor,
            26 => $this->paye_threshold_fortnightly_minor,
            52 => $this->paye_threshold_weekly_minor,
            default => null,
        };
    }

    /**
     * The PAYE threshold for one pay period, in minor units.
     *
     * The published figure wherever TAJ supplies one. Division is the fallback
     * for a card nobody has published periodic figures into — never verified,
     * so never approvable, so never on a payslip somebody is paid from.
     */
    public function thresholdPerPeriod(int $periodsPerYear): int
    {
        if ($periodsPerYear < 1) {
            throw new DomainException('A pay frequency has at least one period a year.');
        }

        return $this->publishedThresholdFor($periodsPerYear)
            ?? intdiv($this->paye_threshold_annual_minor, $periodsPerYear);
    }

    /**
     * Where the 30% band starts, per pay period. 500,000.00 a month.
     *
     * Rounded half up rather than truncated, which is exact for a month and
     * within a cent for any other frequency.
     */
    public function higherBandPerPeriod(int $periodsPerYear): int
    {
        return self::perPeriod($this->paye_higher_band_annual_minor, $periodsPerYear);
    }

    /**
     * The NIS ceiling per pay period. 416,666.67 a month, as the ruling states it.
     *
     * Rounded half up: truncating gave 416,666.66, a cent short of the figure
     * TAJ publishes, on exactly the payslips that reach the ceiling.
     */
    public function nisCeilingPerPeriod(int $periodsPerYear): int
    {
        return self::perPeriod($this->nis_ceiling_annual_minor, $periodsPerYear);
    }

    /**
     * Whether HEART is charged on a payroll of this size.
     *
     * The floor is monthly, so a payroll run at another frequency is scaled to
     * its monthly equivalent before it is compared. With the floor at zero —
     * the ruled default, Q-016 — every payroll with anybody on it is charged.
     */
    public function heartAppliesTo(int $payrollGrossMinor, int $periodsPerYear): bool
    {
        $monthlyEquivalent = self::perPeriod($payrollGrossMinor * $periodsPerYear, 12);

        return $monthlyEquivalent > $this->heart_monthly_floor_minor;
    }

    /** An annual amount split across pay periods, rounded half up to the cent. */
    private static function perPeriod(int $annualMinor, int $periodsPerYear): int
    {
        if ($periodsPerYear < 1) {
            throw new DomainException('A pay frequency has at least one period a year.');
        }

        return intdiv($annualMinor * 2 + $periodsPerYear, $periodsPerYear * 2);
    }
}
