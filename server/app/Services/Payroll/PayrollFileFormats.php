<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use DomainException;

/**
 * The formats a pay run can leave in — the registry behind the adapter.
 *
 * ONE PLACE THE LIST LIVES. The screen offers what this returns and the route
 * validates against the same list, so a format cannot be offered that the route
 * refuses, or accepted that the screen never showed. Adding a bank's own spec is
 * a class beside the other two and one line here.
 */
class PayrollFileFormats
{
    /** @var list<PayrollFileFormat> */
    private array $formats;

    public function __construct(BankCreditCsv $bank, PayrollSummaryXlsx $summary)
    {
        $this->formats = [$summary, $bank];
    }

    /** @return list<PayrollFileFormat> */
    public function all(): array
    {
        return $this->formats;
    }

    /**
     * What the screen draws: the key, the label and what each file is for.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public function catalogue(): array
    {
        return array_map(static fn (PayrollFileFormat $format): array => [
            'key' => $format->key(),
            'label' => $format->label(),
            'description' => $format->description(),
        ], $this->formats);
    }

    public function find(string $key): PayrollFileFormat
    {
        foreach ($this->formats as $format) {
            if ($format->key() === $key) {
                return $format;
            }
        }

        throw new DomainException('A pay run leaves as a summary for the accountant or a credit file for the bank. They are different documents, so the format is chosen rather than defaulted.');
    }
}
