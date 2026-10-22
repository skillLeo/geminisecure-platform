<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One resident's attempt to pay by card, through the gateway (M16 §2.2, M17 §1.2; App\Services\Estate\CardGateway).
 *
 * THE STATE ONLY MOVES FORWARD, AND ONLY A SIGNED WEBHOOK MOVES IT. The phone's return from the provider's page is
 * never proof of payment — the webhook is. `rank()` orders the states, so a webhook that arrives late (an
 * `authorised` after the `captured`) is recognised as stale and changes nothing.
 *
 *   created → authorised → captured → settled        the ordinary path
 *   created/authorised → declined                    the card was refused; nothing is posted
 *   captured/settled → refunded                      money sent back; the household owes it again
 *
 * @property int $id
 * @property string $reference
 * @property int $unit_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $status
 * @property string $provider
 * @property string|null $provider_ref
 * @property string|null $next_action_url
 * @property string $idempotency_key
 * @property int|null $payer_account_id
 * @property string|null $payer_name
 * @property string|null $decline_reason
 * @property int|null $payment_id
 * @property int $captured_minor
 * @property int $fee_minor
 * @property int $settled_net_minor
 * @property int $refunded_minor
 * @property int $refunded_before_settlement_minor
 * @property string|null $settlement_journal_ref
 * @property Carbon|null $authorised_at
 * @property Carbon|null $captured_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $refunded_at
 * @property bool $charged_back
 * @property-read Unit $unit
 * @property-read Payment|null $payment
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PaymentIntent query()
 *
 * @mixin \Eloquent
 */
class PaymentIntent extends Model
{
    public const CREATED = 'created';

    public const AUTHORISED = 'authorised';

    public const CAPTURED = 'captured';

    public const SETTLED = 'settled';

    public const DECLINED = 'declined';

    public const REFUNDED = 'refunded';

    /** The forward order of the ordinary path. Declined and refunded are outcomes, not steps on it. */
    private const RANK = [
        self::CREATED => 0,
        self::AUTHORISED => 1,
        self::CAPTURED => 2,
        self::SETTLED => 3,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'captured_minor' => 'integer',
            'fee_minor' => 'integer',
            'settled_net_minor' => 'integer',
            'refunded_minor' => 'integer',
            'refunded_before_settlement_minor' => 'integer',
            'authorised_at' => 'datetime',
            'captured_at' => 'datetime',
            'settled_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'charged_back' => 'boolean',
        ];
    }

    /** Where a state stands on the ordinary path; -1 for an outcome off it. */
    public static function rank(string $status): int
    {
        return self::RANK[$status] ?? -1;
    }

    /** Whether the money has been captured — a receipt exists and the household's balance went down. */
    public function isCaptured(): bool
    {
        return $this->payment_id !== null;
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
