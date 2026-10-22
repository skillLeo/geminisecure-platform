<?php

declare(strict_types=1);

namespace App\Services\Estate;

use Brick\Money\Money;
use InvalidArgumentException;

/**
 * One side of one journal entry, before it is written.
 *
 * A value object rather than an array, because this is the money core and an
 * array key typed `debt_minor` instead of `debit_minor` is a silent zero. There
 * is no constructor: a posting is made with Posting::debit() or
 * Posting::credit(), so a caller cannot produce one that is neither, or both.
 *
 * THE AMOUNT IS ALWAYS POSITIVE. Which side it sits on is the statement being
 * made; a negative debit is a credit written the wrong way round, and the
 * database refuses it.
 */
final readonly class Posting
{
    private function __construct(
        public string $accountCode,
        public int $debitMinor,
        public int $creditMinor,
        public string $currency,
        public ?string $memo,
        public ?int $unitId,
        public ?int $vendorId,
    ) {}

    /**
     * Money INTO an asset or expense, or OUT of a liability, equity or income.
     *
     * @param  Money|int  $amount  a Money, or minor units when the currency is the estate's own
     */
    public static function debit(
        string $accountCode,
        Money|int $amount,
        ?string $memo = null,
        ?int $unitId = null,
        ?int $vendorId = null,
        string $currency = 'JMD',
    ): self {
        [$minor, $code] = self::normalise($amount, $currency);

        return new self($accountCode, $minor, 0, $code, $memo, $unitId, $vendorId);
    }

    /** The other side. */
    public static function credit(
        string $accountCode,
        Money|int $amount,
        ?string $memo = null,
        ?int $unitId = null,
        ?int $vendorId = null,
        string $currency = 'JMD',
    ): self {
        [$minor, $code] = self::normalise($amount, $currency);

        return new self($accountCode, 0, $minor, $code, $memo, $unitId, $vendorId);
    }

    /**
     * The same posting on the opposite side. What a reversal is made of.
     */
    public function mirrored(): self
    {
        return new self(
            $this->accountCode,
            $this->creditMinor,
            $this->debitMinor,
            $this->currency,
            $this->memo,
            $this->unitId,
            $this->vendorId,
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function normalise(Money|int $amount, string $currency): array
    {
        if ($amount instanceof Money) {
            $minor = $amount->getMinorAmount()->toInt();
            $currency = $amount->getCurrency()->getCurrencyCode();
        } else {
            $minor = $amount;
        }

        /*
         * Refused here rather than at the database, so the message names the
         * mistake. A zero posting is not a line — it is noise in a ledger
         * somebody will have to read in five years — and a negative one is the
         * other side of the entry written in the wrong column.
         */
        if ($minor <= 0) {
            throw new InvalidArgumentException(
                'A posting must be a positive amount. Which side it sits on is what makes it a debit '.
                'or a credit; a negative debit is a credit written the wrong way round, and the '.
                'database will refuse it.'
            );
        }

        return [$minor, $currency];
    }
}
