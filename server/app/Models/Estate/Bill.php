<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What the estate owes one supplier — board 27's sub-ledger row.
 *
 * THE INVOICE, not the accounting. The entry raised on approval is the
 * liability; this row carries the due date, the supplier's paperwork state and
 * the work order it came from — none of which belongs on a journal line.
 *
 * A BILL IS RECORDED BEFORE IT IS APPROVED, and a draft raises no journal at
 * all. That gap is the point: an invoice that has arrived but has not been agreed
 * is not yet a liability, and posting it on arrival would let a disputed bill
 * change the estate's accounts before anybody decided it should.
 *
 * `status` IS THE WORKFLOW, NOT THE BOARD'S BADGE. Board 27 draws Unpaid,
 * Overdue and Paid; this column holds draft, approved, paid and void. "Overdue"
 * is not a state anything sets — it is an approved bill whose due date has
 * passed, which is arithmetic against today and would be a lie the moment it was
 * stored. `boardStatus()` derives it.
 *
 * @property int $id
 * @property int $vendor_id
 * @property string $reference
 * @property string $description
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $due_on
 * @property int|null $account_id
 * @property int|null $ticket_id
 * @property string|null $ticket_label
 * @property int|null $platform_invoice_id the Gemini invoice a draft was raised from (EST-S-05), null on the estate's own
 * @property string $status
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property Carbon|null $approved_at
 * @property string|null $journal_ref
 * @property string|null $void_reason why a draft was voided (bug hunt B036)
 * @property int|null $voided_by
 * @property string|null $voided_by_name
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Money $amount
 * @property-read Vendor $vendor
 * @property-read Account|null $account
 * @property-read Journal|null $entry
 * @property-read Collection<int, BillPayment> $payments
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bill newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bill newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Bill query()
 *
 * @mixin \Eloquent
 */
class Bill extends Model
{
    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const VOID = 'void';

    protected $fillable = [
        'vendor_id',
        'reference',
        'description',
        'amount_minor',
        'currency',
        'due_on',
        'account_id',
        'ticket_id',
        'ticket_label',
        'status',
        'approved_by',
        'approved_by_name',
        'approved_at',
        'journal_ref',
        'void_reason',
        'voided_by',
        'voided_by_name',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'amount_minor' => 'integer',
            'due_on' => 'date',
            'approved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** Whether the liability has been posted — a draft has raised no entry. */
    public function isPosted(): bool
    {
        return in_array($this->status, [self::APPROVED, self::PAID], true);
    }

    /**
     * The badge board 27 draws, which is not what `status` holds.
     *
     * `$asAt` rather than `now()` inside, because "overdue" is a statement about
     * a date and a reconciliation or a statement run asks it about a date that is
     * not today.
     *
     * Today is the ESTATE's today (bug hunt B023): a bill due on the 30th is not
     * overdue at 8 PM that evening in Kingston, however late it is in UTC.
     */
    public function boardStatus(?Carbon $asAt = null): string
    {
        $today = $asAt?->copy() ?? Carbon::parse(SiteTime::today()->toDateString());

        return match (true) {
            $this->status === self::PAID => 'paid',
            $this->status === self::DRAFT => 'draft',
            $this->status === self::VOID => 'void',
            $this->due_on->lessThan($today) => 'overdue',
            default => 'unpaid',
        };
    }

    /**
     * What board 27 prints in the Reference column.
     *
     * The ticket where there is one, because a committee asking what a job cost
     * is asking about the ticket and not about an invoice number. The
     * description otherwise, which is what the electricity bill has instead.
     */
    public function referenceLabel(): string
    {
        return self::plain($this->ticket_label ?? $this->description);
    }

    /**
     * Words as the console says them: the middot where a row was written with an em dash (M53 Law 3).
     *
     * The wireframe's own text ("Ticket #1042 — Gate lighting", "POS DEP — MAINT FEES") was seeded as drawn, and rows
     * written that way are in databases already; reading them through here says every one the same way as the rows
     * written since (`MaintenanceTicket::label()`: "Ticket #1063 · Gate light out").
     */
    public static function plain(string $words): string
    {
        return str_replace(' — ', ' · ', $words);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return HasMany<BillPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    /**
     * The entry raised on approval: Dr the expense account, Cr 2000.
     *
     * @return BelongsTo<Journal, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_ref', 'reference');
    }
}
