<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Estate\Payment;

/**
 * THE RECEIPT GAPS AS THEY WERE FOUND BEFORE M11, KEPT AS THE YARDSTICK.
 *
 * Copied verbatim from `App\Services\Estate\Receipts::register()` at cef3f2c: every
 * receipt number fetched, parsed with the same pattern `Receipts::numberOf()` uses,
 * and every number in the sequence tested against them. M11 A2 finds the same gaps
 * in SQL; `EstateReceiptGapsAreUnchangedTest` requires the same list.
 */
final class LegacyReceipts
{
    /** @return list<int> */
    public static function gapNumbers(string $prefix, int $firstNo, int $lastNo): array
    {
        $present = Payment::query()
            ->where('receipt_no', 'like', $prefix.'-R-%')
            ->pluck('receipt_no')
            ->map(static fn (string $no): ?int => preg_match('/-R-(\d+)$/', $no, $m) === 1 ? (int) $m[1] : null)
            ->filter()
            ->flip();

        $gaps = [];

        for ($n = $firstNo; $n <= $lastNo; $n++) {
            if (! $present->has($n)) {
                $gaps[] = $n;
            }
        }

        return $gaps;
    }
}
