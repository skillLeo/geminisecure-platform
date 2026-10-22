<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\Auth\EstateScope;
use App\Models\Estate\Account;
use App\Models\Estate\Bill;
use App\Models\Estate\Vendor;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\GeminiBills;
use App\Support\MoneyFormatter;
use Illuminate\Console\Command;

/**
 *   php artisan billing:estate-bills --estate=phoenixparkvillage1             what it would draft
 *   php artisan billing:estate-bills --estate=phoenixparkvillage1 --confirm   draft them
 *
 * THE DRAFT BILLS FOR THE INVOICES GEMINI ISSUED BEFORE EST-S-05 (M85 A4). From EST-S-05 on, raising an invoice drafts
 * its bill to 5050 in the estate (`GeminiBills`, `InvoiceRun::raise()`); the invoices issued before it never did, and
 * one whose estate could not be written when it was raised is on the audit log as billing.estate_bill_not_drafted. This
 * drafts each one's bill once, as the raise would: Gemini Security Limited on the register, a draft for what the
 * invoice still asks for, no journal. A person approves each on Bills & payments, or voids it with a reason.
 *
 * Only an ISSUED invoice that still asks for something: one marked paid, or credited to nothing, is listed and left;
 * one already drafted is listed as drafted. Nothing else moves: not Gemini's invoices or journals, not the estate's
 * journals (a draft posts nothing), not any other estate.
 *
 * Without --confirm nothing is written. Run on production by a person who has read the dry run, never by a deploy.
 */
class BillingEstateBills extends Command
{
    protected $signature = 'billing:estate-bills
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--confirm : draft the bills; without it nothing is written}';

    protected $description = 'Draft the estate\'s bill to 5050 for each issued Gemini invoice that has none yet';

    public function handle(GeminiBills $bills, AuditLogger $audit): int
    {
        $key = (string) $this->option('estate');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Say which estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('confirm');
        $invoices = Invoice::query()->where('tenant_id', $key)->orderBy('period_start')->orderBy('id')->get();

        return EstateScope::run($key, function () use ($estate, $key, $apply, $invoices, $bills, $audit): int {
            $this->info(($apply ? 'Drafting the bills for Gemini\'s invoices at ' : 'What --confirm would draft at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is written without --confirm.'));

            $account = Account::query()->where('code', GeminiBills::ACCOUNT)->first();

            if ($account === null || $account->type !== Account::EXPENSE || ! $account->is_active) {
                $this->error('This estate has no active expense account '.GeminiBills::ACCOUNT.' to draft a bill to. Nothing was changed.');

                return self::FAILURE;
            }

            $drafted = Bill::query()->whereIn('platform_invoice_id', $invoices->modelKeys())->pluck('reference', 'platform_invoice_id')->all();
            $onRegister = Vendor::query()->whereRaw('LOWER(name) = ?', [strtolower(GeminiBills::VENDOR)])->exists();

            $rows = [];
            $todo = [];
            $totalMinor = 0;

            foreach ($invoices as $invoice) {
                $owed = $invoice->outstandingMinor();
                $row = [$invoice->reference, $invoice->period, MoneyFormatter::fromMinor($owed, $invoice->currency)];

                $outcome = match (true) {
                    isset($drafted[$invoice->id]) => 'drafted already: '.$drafted[$invoice->id],
                    $invoice->status === 'paid' => 'paid, not drafted',
                    $invoice->status !== 'issued' => 'not issued, not drafted',
                    $owed <= 0 => 'credited to nothing, not drafted',
                    default => null,
                };

                if ($outcome === null) {
                    $todo[$invoice->id] = $invoice;
                    $totalMinor += $owed;
                    $outcome = $apply ? 'drafted: ' : 'would draft to '.GeminiBills::ACCOUNT;
                }

                $rows[$invoice->id] = [...$row, $outcome];
            }

            if ($apply) {
                foreach ($todo as $id => $invoice) {
                    $bill = $bills->draftFor($invoice);

                    if ($bill === null) {
                        continue;
                    }

                    $rows[$id][3] .= $bill->reference;

                    $audit->record(
                        action: 'billing.estate_bill_drafted',
                        entityType: 'Invoice',
                        entityId: (string) $invoice->id,
                        after: ['reference' => $invoice->reference, 'bill' => $bill->reference, 'amount_minor' => $bill->amount_minor, 'account' => GeminiBills::ACCOUNT, 'by' => 'billing:estate-bills'],
                        tenantId: $key,
                    );
                }
            }

            $this->newLine();
            $this->line(GeminiBills::VENDOR.': '.($onRegister ? 'on the register' : ($todo === [] ? 'not on the register, and not needed yet' : ($apply ? 'added to the register' : 'would be added to the register'))).'.');
            $this->table(['Invoice', 'Period', 'Asks for', 'Bill'], array_values($rows));
            $this->line('NOT TOUCHED: Gemini\'s invoices and journals, the estate\'s journals (a draft posts nothing), any other estate.');
            $this->newLine();

            $count = count($todo).' bill'.(count($todo) === 1 ? '' : 's');
            $all = MoneyFormatter::fromMinor($totalMinor, $invoices->first()->currency ?? MoneyFormatter::DEFAULT_CURRENCY);

            if ($todo === []) {
                $this->info('Nothing to draft. Nothing was changed.');
            } elseif ($apply) {
                $this->info('Done: '.$count.' drafted, '.$all.' in all, each waiting for approval on Bills & payments.');
            } else {
                $this->info($count.' would be drafted, '.$all.' in all. Nothing is written without --confirm.');
            }

            return self::SUCCESS;
        });
    }
}
