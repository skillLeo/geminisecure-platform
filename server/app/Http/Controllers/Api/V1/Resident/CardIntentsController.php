<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Payment;
use App\Models\Estate\PaymentIntent;
use App\Services\Estate\CardGateway;
use App\Services\ResidentApp\ResidentAccounts;
use App\Support\Decimal;
use Brick\Money\Money;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PAYING BY CARD THROUGH THE GATEWAY — the resident's side (M16 §2.2; App\Services\Estate\CardGateway).
 *
 *   payments.intents.store   start a card payment for part or all of what the household owes. The answer names
 *                            the provider's page to send the resident to; the card is typed THERE, never here.
 *   payments.intents.show    where that payment stands — only the provider's signed webhook moves it, so the phone
 *                            reads this rather than trusting its own return from the provider's page.
 *
 * Off until a provider is connected (`payments.gateway.driver`): then `store` is refused with the same sentence the
 * rest of the app gives — dues are paid by bank transfer, cash or cheque.
 */
class CardIntentsController extends Controller
{
    public function __construct(private readonly ResidentAccounts $accounts) {}

    public function store(Request $request, DeviceContext $context, CardGateway $gateway): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ], [
            'amount.regex' => 'Enter an amount in dollars and cents, like 12400.00.',
        ]);

        try {
            $opened = $gateway->open(
                unit: $home['unit'],
                amount: Money::of((string) $data['amount'], 'JMD'),
                idempotencyKey: (string) $request->header('Idempotency-Key'),
                payerAccountId: (int) $home['account']->id,
                payerName: (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident'),
                returnUrl: rtrim((string) config('app.url'), '/'),
            );
        } catch (DomainException $refused) {
            throw ApiError::conflict('payment_refused', $refused->getMessage());
        }

        return response()->json($this->shape($opened['intent'], $opened['next_action_url']), 201);
    }

    public function show(string $reference, DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $intent = PaymentIntent::query()->where('reference', $reference)->where('unit_id', $unit->id)->first();

        if (! $intent instanceof PaymentIntent) {
            throw ApiError::notFound('not_found', 'No card payment of your household\'s with that reference.');
        }

        return response()->json($this->shape($intent, $intent->getAttribute('next_action_url')));
    }

    /** @return array<string, mixed> */
    private function shape(PaymentIntent $intent, mixed $nextActionUrl): array
    {
        $receipt = $intent->payment_id === null ? null : Payment::query()->whereKey($intent->payment_id)->value('receipt_no');

        return [
            'reference' => $intent->reference,
            'status' => $intent->status,
            'amount' => Decimal::of($intent->amount_minor),
            'currency' => $intent->currency,
            'next_action_url' => is_string($nextActionUrl) ? $nextActionUrl : null,
            'decline_reason' => $intent->decline_reason,
            'receipt_no' => $receipt,
            // An intent just created carries no database defaults on the model: nothing refunded is 0, said as 0.
            'refunded' => Decimal::of((int) ($intent->refunded_minor ?? 0)),
        ];
    }
}
