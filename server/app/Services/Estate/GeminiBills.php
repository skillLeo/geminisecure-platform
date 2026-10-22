<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Live\Auth\EstateScope;
use App\Models\Estate\Bill;
use App\Models\Estate\Vendor;
use App\Models\Invoice;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * GEMINI IS THE ESTATE'S SUPPLIER, AND EACH INVOICE IT ISSUES IS A DRAFT BILL TO 5050 (EST-S-05, M82 answer 2, M85 A4).
 *
 * Account 5050 read $0 for the year while Gemini had invoiced the estate about $6.8M: the estate's books were missing
 * their largest supplier (M81 §3.4). So the estate carries Gemini Security Limited on its vendor register, and an
 * invoice Gemini issues becomes a bill in that estate's own database, recorded through `Payables::recordBill()` like
 * any other.
 *
 * A DRAFT, NEVER POSTED HERE. "Draft, never posted automatically, so a person always approves it before it reaches the
 * books." A draft raises no journal; approving it on Bills & payments posts Dr 5050, Cr 2000 for Gemini, and a draft the
 * estate does not owe is voided there with a reason.
 *
 * ONCE PER INVOICE. The bill names the invoice (`platform_invoice_id`, unique), so a retry, or the command after a raise,
 * finds the bill already drafted. FOR WHAT THE INVOICE STILL ASKS: its total less its credit notes; one that asks for
 * nothing, or is not issued, drafts nothing.
 *
 * IN THE INVOICE'S OWN ESTATE, AND NO OTHER. The estate is opened by the invoice's own `tenant_id` and always left again
 * (`EstateScope`), whatever was open before.
 */
class GeminiBills
{
    /** Gemini's name on the vendor register, as it signs its invoices. */
    public const VENDOR = 'Gemini Security Limited';

    /** The vendor's trade, under its name on the register. */
    public const CATEGORY = 'Security services';

    /** Security Services (Gemini Security Ltd) in the chart (`ChartOfAccountsSeeder`). */
    public const ACCOUNT = '5050';

    public function __construct(private readonly Payables $payables) {}

    /**
     * The estate's draft bill for one of Gemini's invoices: drafted now, or the one drafted before.
     *
     * Null when the invoice asks for nothing: not issued, or credited to nothing.
     */
    public function draftFor(Invoice $invoice): ?Bill
    {
        $owed = $invoice->outstandingMinor();

        if ($invoice->status !== 'issued' || $owed <= 0) {
            return null;
        }

        return EstateScope::run((string) $invoice->tenant_id, function () use ($invoice, $owed): Bill {
            $drafted = Bill::query()->where('platform_invoice_id', $invoice->id)->first();

            if ($drafted !== null) {
                return $drafted;
            }

            return DB::connection('tenant')->transaction(function () use ($invoice, $owed): Bill {
                $bill = $this->payables->recordBill(
                    vendor: $this->vendor(),
                    amount: Money::ofMinor($owed, $invoice->currency),
                    description: sprintf('Security services, %s (Gemini invoice %s)', $invoice->period, $invoice->reference),
                    dueOn: $invoice->due_on,
                    account: self::ACCOUNT,
                );

                $bill->forceFill(['platform_invoice_id' => $invoice->id])->save();

                return $bill;
            });
        });
    }

    /**
     * Gemini on this estate's register, put there the first time it is needed.
     *
     * By name, whatever its case, as `Payables::addVendor()` refuses a second row for one supplier; active or not, since
     * the estate owes an issued invoice either way.
     */
    private function vendor(): Vendor
    {
        return Vendor::query()->whereRaw('LOWER(name) = ?', [strtolower(self::VENDOR)])->first()
            ?? $this->payables->addVendor(self::VENDOR, self::CATEGORY);
    }
}
