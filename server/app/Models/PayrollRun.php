<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One payroll run. APPEND-ONLY once approved (invariant 4).
 *
 * A correction to an approved run is a new adjustment run referencing it,
 * never an edit.
 *
 * @property int $id
 * @property string $reference
 * @property string $period_label
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $periods_per_year
 * @property int $statutory_rate_version_id
 * @property string $status
 * @property int $gross_minor
 * @property int $net_minor
 * @property string $currency
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $reconciliation_acknowledged_at
 * @property int|null $reconciliation_acknowledged_by
 * @property string|null $reconciliation_rate_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Payslip> $payslips
 * @property-read int|null $payslips_count
 * @property-read StatutoryRateVersion $rateVersion
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereApprovedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereApprovedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereGrossMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereNetMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun wherePeriodEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun wherePeriodLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun wherePeriodStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun wherePeriodsPerYear($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereStatutoryRateVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PayrollRun whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class PayrollRun extends Model
{
    use CentralConnection;

    protected $fillable = [
        'reference', 'period_label', 'period_start', 'period_end',
        'periods_per_year', 'statutory_rate_version_id', 'status',
        'gross_minor', 'net_minor', 'currency', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StatutoryRateVersion, $this> */
    public function rateVersion(): BelongsTo
    {
        return $this->belongsTo(StatutoryRateVersion::class, 'statutory_rate_version_id');
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * A run may be calculated against unverified rates, so the figures can be
     * checked, but never APPROVED against them.
     *
     * Build Spec open item [A]: do not go live on unverified numbers.
     */
    public function canBeApproved(): bool
    {
        return $this->status === 'calculated' && (bool) $this->rateVersion->is_verified;
    }

    public function blockedReason(): ?string
    {
        if ($this->status !== 'calculated') {
            return null;
        }

        return $this->rateVersion->is_verified
            ? null
            : 'The rate card this run was calculated against carries no verified TAJ periodic figures, so it cannot '
                .'be approved until they are recorded.';
    }

    protected static function booted(): void
    {
        static::updating(function (self $run) {
            $wasApproved = in_array($run->getOriginal('status'), ['approved', 'paid'], true);
            $markingPaid = $run->getOriginal('status') === 'approved' && $run->status === 'paid';

            if ($wasApproved && ! $markingPaid) {
                throw new LogicException(
                    "Payroll run [{$run->reference}] is approved and cannot be edited. "
                    .'Post an adjustment run referencing it instead.'
                );
            }
        });
    }
}
