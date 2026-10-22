<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property int $id
 * @property int $invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $total_minor
 * @property string $currency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Money $total
 * @property-read Invoice $invoice
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereCurrency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereInvoiceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereTotalMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereUnitPriceMinor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InvoiceLine whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class InvoiceLine extends Model
{
    use CentralConnection;

    protected $fillable = [
        'invoice_id', 'description', 'quantity',
        'unit_price_minor', 'total_minor', 'currency',
    ];

    protected function casts(): array
    {
        return ['total' => MoneyCast::class];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
