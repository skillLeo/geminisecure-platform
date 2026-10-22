<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\PlatformCalendar;
use App\Support\Seeded\Retirements;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * An invoice Gemini Security raises against a client estate.
 *
 * Not to be confused with a resident charge, which lives in the estate
 * database. These are two separate ledgers and the distinction is load-bearing:
 * an overdue platform invoice must never restrict a resident.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $subscription_id
 * @property string $reference
 * @property string $period
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property int|null $subtotal_minor
 * @property int $total_minor
 * @property string $currency
 * @property Carbon $due_on
 * @property string $status
 * @property Carbon|null $paid_on
 * @property string|null $journal_ref
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Money $total
 * @property-read Tenant|null $estate
 * @property-read Collection<int, InvoiceLine> $lines
 * @property-read int|null $lines_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereDueOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePaidOn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriodEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice wherePeriodStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereSubscriptionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereTotalMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Invoice whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Invoice extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id', 'subscription_id', 'reference', 'period',
        'period_start', 'period_end', 'subtotal_minor', 'total_minor', 'currency',
        'due_on', 'status', 'paid_on', 'journal_ref',
    ];

    protected function casts(): array
    {
        return [
            'total' => MoneyCast::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'due_on' => 'date',
            'paid_on' => 'date',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * What an invoice still asks for, as SQL: its total less its credit notes.
     *
     * A CREDIT NOTE NEVER TOUCHES THE INVOICE (`InvoiceActions::creditNote()`).
     * Its total is what was charged; what it is worth now is that total less its
     * notes. Recording a payment is not built (D-093), so a credit note is the
     * only thing that settles one — and every reading of "overdue" or "due" that
     * looked at the total alone (B080) went on calling an invoice credited to
     * nothing overdue, for ever, with the client's 1100 at zero.
     *
     * @param  string  $invoices  the table or alias the invoices are read under
     */
    public static function outstandingSql(string $invoices = 'invoices'): string
    {
        // A retired sample credit note is no more counted here than anywhere (M33 §5).
        return "({$invoices}.total_minor - (SELECT COALESCE(SUM(credit_notes.amount_minor), 0)"
            ." FROM credit_notes WHERE credit_notes.invoice_id = {$invoices}.id".Retirements::and('mysql', ['credit_notes' => 'credit_notes']).'))';
    }

    /**
     * Carry each invoice's credited total on the row, as `credited_minor`, so a
     * list can say which are still owed without a query per row.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeWithCredited(Builder $query): Builder
    {
        return $query->addSelect([
            'credited_minor' => $query->getQuery()->newQuery()
                ->from('credit_notes')
                ->selectRaw('COALESCE(SUM(credit_notes.amount_minor), 0)')
                ->whereColumn('credit_notes.invoice_id', 'invoices.id'),
        ]);
    }

    /** The sum of this invoice's credit notes — carried by `withCredited()`, or read. */
    public function creditedMinor(): int
    {
        $carried = $this->getAttribute('credited_minor');

        if ($carried !== null) {
            return (int) $carried;
        }

        return (int) DB::connection($this->getConnectionName())
            ->table('credit_notes')
            ->where('invoice_id', $this->getKey())
            ->sum('amount_minor');
    }

    /** What it still asks for: the total less its credit notes. Nought or less is owed nothing. */
    public function outstandingMinor(): int
    {
        return $this->total_minor - $this->creditedMinor();
    }

    /** Issued, and credited to nothing — a correction that left nothing owed. */
    public function isCreditedInFull(): bool
    {
        return $this->status === 'issued' && $this->outstandingMinor() <= 0;
    }

    /**
     * Overdue is derived, not stored.
     *
     * An invoice that passes its due date overnight is overdue the moment it
     * is asked about, rather than whenever a scheduled job next runs.
     *
     * FROM THE DAY AFTER ITS DUE DATE, ON GEMINI'S CALENDAR, AND ONLY FOR WHAT IS
     * STILL OWED (B080). It is payable on its due date — "Payable by …" is what
     * the invoice says — so it is late the day after, which is the rule the
     * billing KPI, the client's billing status and the bell already applied; this
     * used to call it overdue from midnight UTC on the due date itself. And an
     * invoice its credit notes have taken to nothing is owed nothing.
     */
    public function isOverdue(?Carbon $today = null): bool
    {
        return $this->status === 'issued'
            && $this->due_on->lt($today ?? PlatformCalendar::today())
            && $this->outstandingMinor() > 0;
    }

    public function statusBadge(): string
    {
        return match (true) {
            $this->status === 'paid' => 'paid',
            $this->isCreditedInFull() => 'ok',
            $this->isOverdue() => 'overdue',
            $this->status === 'issued' => 'due',
            default => 'ok',
        };
    }

    /**
     * THE ONE WORD FOR AN INVOICE'S STANDING (BIL-B-04), wherever it is printed: the Gemini console's list and detail,
     * the estate's billing page and itemised view, and the PDF. Derived as `statusBadge()` is, never the stored status
     * capitalised: an overdue invoice is still stored `issued`, and "Issued" in green on a bill the estate owes money on
     * is what this replaces.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->status === 'paid' => 'Paid',
            $this->status === 'void' => 'Void',
            $this->status === 'draft' => 'Draft',
            $this->isCreditedInFull() => 'Credited',
            $this->isOverdue() => 'Overdue',
            // The money list's word (Hassam, 11 Oct): Unpaid, Overdue, Partly paid, Paid, Credited, Void. It read "Due".
            default => 'Unpaid',
        };
    }
}
