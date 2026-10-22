<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Models\StatutoryRateVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One month's payroll for the estate's own staff — boards 13, 14 and 15.
 *
 * NOT `App\Models\PayrollRun`. That class is central and is Gemini's guards;
 * this one lives in the estate's own database and is a different population
 * paid by a different employer. See the migration's docblock and DECISIONS.md
 * D-057.
 *
 * `statutory_rate_version_id` POINTS AT THE CENTRAL TABLE WITH NO FOREIGN KEY,
 * because the two rows live in different physical databases and MySQL cannot
 * constrain across them. `rateVersion()` resolves it explicitly against the
 * central connection rather than assuming the default connection is central —
 * inside a tenant request the default connection IS the tenant's, and a bare
 * `StatutoryRateVersion::find()` would silently query the wrong database if
 * that model ever lost its `CentralConnection` trait.
 *
 * @property int $id
 * @property string $reference
 * @property string $slug
 * @property string $period_label
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int $periods_per_year
 * @property int $statutory_rate_version_id
 * @property string $status
 * @property int $gross_minor
 * @property int $net_minor
 * @property string $currency
 * @property int|null $prepared_by
 * @property string|null $prepared_by_name
 * @property int|null $calculated_by who worked out the payslips — kept off the approval with the preparer (bug hunt B025)
 * @property string|null $calculated_by_name
 * @property Carbon|null $calculated_at
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property Carbon|null $approved_at
 * @property string|null $changes_requested_reason
 * @property string|null $journal_ref
 * @property string|null $reconciliation_acknowledged_at
 * @property int|null $reconciliation_acknowledged_by
 * @property string|null $reconciliation_acknowledged_by_name
 * @property string|null $reconciliation_rate_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PayrollRunLine> $lines
 * @property-read Collection<int, PayrollException> $exceptions
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 *
 * @mixin \Eloquent
 */
class PayrollRun extends Model
{
    protected $table = 'payroll_runs';

    public const DRAFT = 'draft';

    public const EXCEPTIONS = 'exceptions';

    public const CALCULATED = 'calculated';

    public const PAID = 'paid';

    protected $fillable = [
        'reference',
        'slug',
        'period_label',
        'period_start',
        'period_end',
        'periods_per_year',
        'statutory_rate_version_id',
        'status',
        'gross_minor',
        'net_minor',
        'currency',
        'prepared_by',
        'prepared_by_name',
        'calculated_by',
        'calculated_by_name',
        'calculated_at',
        'approved_by',
        'approved_by_name',
        'approved_at',
        'changes_requested_reason',
        'journal_ref',
        'reconciliation_acknowledged_at',
        'reconciliation_acknowledged_by',
        'reconciliation_acknowledged_by_name',
        'reconciliation_rate_version',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** @return HasMany<PayrollRunLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class);
    }

    /** @return HasMany<PayrollException, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(PayrollException::class);
    }

    /**
     * The returns that remit this run's deductions.
     *
     * Plural, and it has to be: one month's run is cleared by an S01 and
     * reconciled by an S02, and board 16 dates both July returns to the same
     * day. A `belongsTo` here would have made the second one unreachable.
     *
     * @return HasMany<StatutoryFiling, $this>
     */
    public function filings(): HasMany
    {
        return $this->hasMany(StatutoryFiling::class);
    }

    /** The rate version, resolved explicitly against the central connection. */
    public function rateVersion(): StatutoryRateVersion
    {
        return StatutoryRateVersion::on('mysql')->findOrFail($this->statutory_rate_version_id);
    }

    public function isPosted(): bool
    {
        return $this->status === self::PAID;
    }

    public function hasUnresolvedExceptions(): bool
    {
        return $this->exceptions()->where('status', PayrollException::UNRESOLVED)->exists();
    }
}
