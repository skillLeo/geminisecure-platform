<?php

declare(strict_types=1);

namespace App\Casts;

use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a (`*_minor` bigint, `currency` char) column pair to brick/money.
 *
 * Money is stored as an integer of minor units with an explicit ISO currency,
 * never as a float or a decimal string. A float cannot represent 0.10, and a
 * community's ledger has to reconcile to the cent years after it was posted.
 *
 * The currency is stored per row rather than assumed globally, so a later
 * multi-currency estate does not require a migration of every historical
 * amount — and so no amount is ever ambiguous about what it denominates.
 *
 * ASSUMPTION Q-001: JMD is the default where a row does not specify one.
 *
 * The write side accepts `mixed` rather than Money so the runtime guard below
 * is meaningful: callers reach this from request data and array literals, not
 * only from typed code, and a silent cast of a float or a string is exactly
 * the failure this class exists to prevent.
 *
 * @implements CastsAttributes<Money, mixed>
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(
        private string $amountColumn = 'amount_minor',
        private string $currencyColumn = 'currency',
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $minor = $attributes[$this->amountColumn] ?? null;

        if ($minor === null) {
            return null;
        }

        return Money::ofMinor(
            (int) $minor,
            $attributes[$this->currencyColumn] ?? 'JMD',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$this->amountColumn => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf(
                    'Attribute [%s] must be a %s, got %s. Construct it explicitly — '.
                    'Money::of(1500, \'JMD\') or Money::ofMinor(150000, \'JMD\') — so the '.
                    'currency and the scale are never inferred.',
                    $key,
                    Money::class,
                    get_debug_type($value),
                ),
            );
        }

        return [
            $this->amountColumn => $value->getMinorAmount()->toInt(),
            $this->currencyColumn => $value->getCurrency()->getCurrencyCode(),
        ];
    }
}
