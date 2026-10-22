<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Payment;
use App\Models\Tenant;
use App\Services\Estate\DemoCardPayments;
use Illuminate\Console\Command;

/**
 *   php artisan payments:reverse-demonstrations phoenixparkvillage1            # what it would reverse
 *   php artisan payments:reverse-demonstrations phoenixparkvillage1 --confirm  # reverse them
 *
 * After a demonstration (M12 §2.6; D-179): every test-card payment at the estate is reversed through the ledger —
 * mirrored, both entries kept — so the households owe again what the test cards "paid", and every deposit a test card
 * paid that is still held is refunded to 1090. Real payments are never touched.
 */
class PaymentsReverseDemonstrations extends Command
{
    protected $signature = 'payments:reverse-demonstrations
        {estate : the estate subdomain}
        {--confirm : reverse them; without it, only say what would be reversed}';

    protected $description = 'Reverse every demonstration (test-card) payment and deposit at one estate';

    public function handle(): int
    {
        $estate = Tenant::query()->find((string) $this->argument('estate'));

        if (! $estate instanceof Tenant) {
            $this->error('No estate ['.$this->argument('estate').'].');

            return self::FAILURE;
        }

        $confirm = (bool) $this->option('confirm');

        $result = $estate->run(function () use ($confirm): array {
            if (! $confirm) {
                return [
                    'payments' => Payment::query()->where('is_demonstration', true)->where('status', 'recorded')->pluck('receipt_no')->all(),
                    'deposits' => AmenityBooking::query()->where('deposit_demonstration', true)->where('deposit_state', AmenityBooking::DEPOSIT_HELD)->pluck('reference')->all(),
                ];
            }

            return app(DemoCardPayments::class)->reverseAll();
        });

        $this->line(sprintf(
            '%s %d demonstration payment(s)%s and %d demonstration deposit(s)%s at %s.',
            $confirm ? 'Reversed' : 'Would reverse',
            count($result['payments']),
            $result['payments'] === [] ? '' : ' ('.implode(', ', $result['payments']).')',
            count($result['deposits']),
            $result['deposits'] === [] ? '' : ' ('.implode(', ', $result['deposits']).')',
            $estate->name,
        ));

        if (! $confirm && ($result['payments'] !== [] || $result['deposits'] !== [])) {
            $this->line('Run again with --confirm to reverse them.');
        }

        return self::SUCCESS;
    }
}
