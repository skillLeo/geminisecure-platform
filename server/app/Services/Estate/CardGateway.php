<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Payment;
use App\Models\Estate\PaymentIntent;
use App\Models\Estate\Unit;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\GatewayDrivers;
use App\Services\Payments\GatewayEvent;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A CARD PAYMENT THROUGH A REAL GATEWAY, FROM THE TAP TO THE BANK STATEMENT (M16 §2.2, M17 §1.2).
 *
 * Provider-agnostic: the provider is a `GatewayDriver`, and none is connected until the client chooses one
 * (`payments.gateway.driver` — null means card payments are off). What this class guarantees does not change with
 * the provider:
 *
 *   A TAP CANNOT CHARGE TWICE. An intent is keyed by the Idempotency-Key the phone chose before the first tap;
 *            a retry, a double tap or two taps racing each other all find the ONE intent (a unique index is the
 *            final word, not a read-then-write).
 *
 *   ONLY A SIGNED WEBHOOK MOVES MONEY. The resident coming back from the provider's page proves nothing; the
 *            provider's webhook does, and the controller verifies its signature and freshness before this sees it.
 *
 *   A WEBHOOK IS APPLIED ONCE. Each event's id is claimed in `payment_gateway_events` (unique per provider)
 *            inside the same transaction that applies it, with the intent locked — so a redelivery, a replay or
 *            two copies arriving at once are answered "duplicate" and change nothing.
 *
 *   ORDER DOES NOT MATTER. States only move forward. A late `authorised` after `captured` is stale. An event
 *            that implies an earlier one — a payout or a refund arriving before its capture — applies the capture
 *            first. Whatever order the provider's webhooks arrive in, the books end in the same place.
 *
 *   THE BOOKS TIE TO THE BANK.
 *            capture   Dr 1080 Card Payments in Transit / Cr 1200 against the unit — the receipt, the lower balance
 *            payout    Dr 1000 Bank (net) + Dr 5910 Bank & Card Charges (the fee) / Cr 1080 (what was in transit)
 *            refund    Dr 1200 against the unit / Cr 1080 before the payout, Cr 1000 after it
 *
 * No card number is ever seen here: the resident types it on the provider's own page (M16 §2.5).
 */
final class CardGateway
{
    public const APPLIED = 'applied';

    public const DUPLICATE = 'duplicate';

    public const STALE = 'stale';

    public const UNKNOWN_INTENT = 'unknown_intent';

    public const TRANSIT_ACCOUNT = '1080';

    public const BANK_ACCOUNT = '1000';

    /*
     * 5910, NOT 5300 (found M18): 5300 is the next plain expense code an office reaches for — the suite's own example
     * adds "Security Services" there — and a migration that finds the code taken leaves the office's account alone, so
     * card fees would have been posted into it. 59xx sits with the system accounts the platform keeps for itself.
     */
    public const CHARGES_ACCOUNT = '5910';

    /** The provider's decline, turned into a sentence a resident can act on — never a code. */
    public const DECLINE = [
        'insufficient_funds' => 'Your card has insufficient funds. Nothing was charged. Try another card, or pay by bank transfer.',
        'expired_card' => 'Your card has expired. Nothing was charged. Check the date, or try another card.',
        'incorrect_cvc' => 'Your card\'s security code was not accepted. Nothing was charged. Check the code and try again.',
        'do_not_honor' => 'Your bank declined this payment. Nothing was charged. Call your bank, or try another card.',
        'fraud_suspected' => 'Your bank stopped this payment as a precaution. Nothing was charged. Call your bank, or pay another way.',
        'declined' => 'Your card was declined. Nothing was charged. Try another card, or pay by bank transfer.',
    ];

    public function __construct(
        private readonly Dues $dues,
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /** Whether card payments through a gateway are on at all. */
    public static function enabled(): bool
    {
        return GatewayDrivers::enabled();
    }

    /**
     * Start paying — or find the payment this key already started.
     *
     * @return array{intent: PaymentIntent, next_action_url: string|null, replayed: bool}
     *
     * @throws DomainException when card payments are off, the amount is not payable, or the key was used for another payment
     */
    public function open(Unit $unit, Money $amount, string $idempotencyKey, ?int $payerAccountId, string $payerName, string $returnUrl): array
    {
        $driver = GatewayDrivers::current();

        if ($driver === null) {
            throw new DomainException('This estate takes dues by bank transfer, cash or cheque. Card payment is not offered in the app yet.');
        }

        $key = trim($idempotencyKey);

        if ($key === '' || strlen($key) > 64) {
            throw new DomainException('A card payment needs the key the app chose before the first tap.');
        }

        $minor = $amount->getMinorAmount()->toInt();

        $existing = PaymentIntent::query()->where('idempotency_key', $key)->first();

        if ($existing instanceof PaymentIntent) {
            return $this->replay($existing, $unit, $minor);
        }

        if ($minor <= 0) {
            throw new DomainException('Choose an amount to pay.');
        }

        $owed = $this->dues->unpaidOf($unit)->getMinorAmount()->toInt();

        if ($owed <= 0) {
            throw new DomainException('Nothing is owed on '.$unit->reference.' just now, so there is nothing to pay.');
        }

        if ($minor > $owed) {
            throw new DomainException(sprintf('That is more than is owed. The most you can pay now is %s.', MoneyFormatter::fromMinor($owed)));
        }

        try {
            $intent = PaymentIntent::query()->create([
                'reference' => 'PI-'.strtoupper(bin2hex(random_bytes(6))),
                'unit_id' => $unit->id,
                'amount_minor' => $minor,
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'status' => PaymentIntent::CREATED,
                'provider' => $driver->name(),
                'idempotency_key' => $key,
                'payer_account_id' => $payerAccountId,
                'payer_name' => $payerName,
            ]);
        } catch (QueryException $race) {
            // Two taps with one key, at the same instant: the unique index let exactly one in. The other is it.
            if (($race->errorInfo[1] ?? null) !== 1062) {
                throw $race;
            }

            $winner = PaymentIntent::query()->where('idempotency_key', $key)->firstOrFail();

            return $this->replay($winner, $unit, $minor);
        }

        $made = $driver->createIntent($intent, $returnUrl);
        $intent->forceFill(['provider_ref' => $made['provider_ref'], 'next_action_url' => $made['next_action_url']])->save();

        $this->audit->record(
            action: 'payment.intent_opened',
            entityType: 'PaymentIntent',
            entityId: (string) $intent->id,
            after: ['reference' => $intent->reference, 'unit' => $unit->reference, 'amount_minor' => $minor, 'provider' => $driver->name(), 'paid_by' => $payerName],
        );

        return ['intent' => $intent, 'next_action_url' => $made['next_action_url'], 'replayed' => false];
    }

    /**
     * Apply one webhook the controller has already verified. Returns what happened: applied, duplicate, stale or
     * unknown_intent. Every outcome is an answer the provider should stop retrying — the controller says 200.
     */
    public function apply(string $provider, GatewayEvent $event, string $payloadSha256): string
    {
        return DB::connection('tenant')->transaction(function () use ($provider, $event, $payloadSha256): string {
            $intent = PaymentIntent::query()->where('reference', $event->intentReference)->lockForUpdate()->first();

            if (! $intent instanceof PaymentIntent || $intent->provider !== $provider) {
                // Claimed all the same, so its redelivery is recognised as a duplicate rather than a fresh unknown.
                return $this->claim($provider, $event, $payloadSha256, self::UNKNOWN_INTENT, 'no intent with that reference at this estate')
                    ? self::UNKNOWN_INTENT
                    : self::DUPLICATE;
            }

            [$outcome, $note] = $this->decide($intent, $event);

            if (! $this->claim($provider, $event, $payloadSha256, $outcome, $note)) {
                return self::DUPLICATE;
            }

            if ($outcome === self::APPLIED) {
                $this->transition($intent, $event);
            }

            return $outcome;
        });
    }

    /**
     * Whether this event moves this intent, and if not, why — decided before anything is written.
     *
     * @return array{0: string, 1: string|null}
     */
    private function decide(PaymentIntent $intent, GatewayEvent $event): array
    {
        return match ($event->type) {
            GatewayEvent::AUTHORISED => PaymentIntent::rank($intent->status) >= 1 || in_array($intent->status, [PaymentIntent::DECLINED, PaymentIntent::REFUNDED], true)
                ? [self::STALE, 'already '.$intent->status]
                : [self::APPLIED, null],

            GatewayEvent::CAPTURED => $intent->isCaptured()
                ? [self::STALE, 'already captured']
                : [self::APPLIED, $intent->status === PaymentIntent::DECLINED ? 'captured after a decline: the money arrived, so the capture stands' : null],

            GatewayEvent::SETTLED => $intent->settled_at !== null
                ? [self::STALE, 'already paid out']
                : [self::APPLIED, $intent->isCaptured() ? null : 'payout before its capture: the capture was applied first'],

            GatewayEvent::DECLINED => $intent->isCaptured()
                ? [self::STALE, 'declined after capture: the capture stands']
                : ($intent->status === PaymentIntent::DECLINED ? [self::STALE, 'already declined'] : [self::APPLIED, null]),

            GatewayEvent::REFUNDED, GatewayEvent::CHARGED_BACK => $intent->isCaptured() && $intent->captured_minor - $intent->refunded_minor <= 0
                ? [self::STALE, 'nothing left to refund']
                : [self::APPLIED, $intent->isCaptured() ? null : 'refund before its capture: the capture was applied first'],

            default => [self::STALE, 'not an event this platform acts on'],
        };
    }

    private function transition(PaymentIntent $intent, GatewayEvent $event): void
    {
        $when = $this->moment($event);

        match ($event->type) {
            GatewayEvent::AUTHORISED => $intent->forceFill(['status' => PaymentIntent::AUTHORISED, 'authorised_at' => $when])->save(),
            GatewayEvent::CAPTURED => $this->capture($intent, $event->amountMinor, $when, $event->providerRef),
            GatewayEvent::SETTLED => $this->settle($intent, $event, $when),
            GatewayEvent::DECLINED => $this->decline($intent, $event, $when),
            GatewayEvent::REFUNDED => $this->refund($intent, $event, $when, chargeback: false),
            GatewayEvent::CHARGED_BACK => $this->refund($intent, $event, $when, chargeback: true),
            default => null,
        };
    }

    /** The receipt: Dr 1080 / Cr 1200 against the unit. Once per intent — guarded by `payment_id`. */
    private function capture(PaymentIntent $intent, ?int $amountMinor, Carbon $when, ?string $providerRef): void
    {
        if ($intent->isCaptured()) {
            return;
        }

        $minor = $amountMinor !== null && $amountMinor > 0 ? $amountMinor : $intent->amount_minor;

        $payment = $this->dues->receive(
            unit: $intent->unit,
            amount: Money::ofMinor($minor, $intent->currency),
            method: Payment::CARD,
            receivedAt: $when,
            bankAccount: self::TRANSIT_ACCOUNT,
            gatewayRef: $providerRef ?? $intent->provider_ref,
            reference: 'Card payment · '.$intent->reference,
        );

        $intent->forceFill([
            'payment_id' => $payment->id,
            'captured_minor' => $minor,
            'captured_at' => $when,
            'authorised_at' => $intent->authorised_at ?? $when,
            'status' => PaymentIntent::rank($intent->status) < 2 ? PaymentIntent::CAPTURED : $intent->status,
        ])->save();

        $this->audit->record(
            action: 'payment.card_captured',
            entityType: 'PaymentIntent',
            entityId: (string) $intent->id,
            after: ['reference' => $intent->reference, 'receipt_no' => $payment->receipt_no, 'amount_minor' => $minor, 'account' => self::TRANSIT_ACCOUNT],
        );
    }

    /**
     * The payout: what is still in transit leaves 1080 — net to the bank, the fee to 5910. If a refund already took
     * part of it back out of transit, only what is left is paid out.
     */
    private function settle(PaymentIntent $intent, GatewayEvent $event, Carbon $when): void
    {
        if (! $intent->isCaptured()) {
            $this->capture($intent, null, $when, $event->providerRef);
            $intent->refresh();
        }

        $gross = max(0, $intent->captured_minor - $intent->refunded_before_settlement_minor);
        $fee = $event->feeMinor;
        $net = $gross - $fee;
        // The console's middot, never an em dash (Law 3): a journal's memo is read on the account and the journal.
        $memo = 'Card payout · '.$intent->reference;

        $postings = array_values(array_filter([
            $net > 0 ? Posting::debit(self::BANK_ACCOUNT, $net, $memo) : null,
            $fee > 0 ? Posting::debit(self::CHARGES_ACCOUNT, $fee, $memo) : null,
            $gross > 0 ? Posting::credit(self::TRANSIT_ACCOUNT, $gross, $memo) : null,
            // A fee larger than what was left in transit is taken from the bank.
            $net < 0 ? Posting::credit(self::BANK_ACCOUNT, -$net, $memo) : null,
        ]));

        $reference = null;

        if (count($postings) >= 2) {
            $reference = $this->ledger->post(
                memo: $memo,
                postings: $postings,
                on: $when,
                source: Ledger::SOURCE_CARD_SETTLEMENT,
                sourceId: $intent->id,
                prefix: 'CPO',
            )->reference;
        }

        $intent->forceFill([
            'settled_at' => $when,
            'fee_minor' => $fee,
            'settled_net_minor' => $net,
            'settlement_journal_ref' => $reference,
            'status' => $intent->status === PaymentIntent::REFUNDED ? PaymentIntent::REFUNDED : PaymentIntent::SETTLED,
        ])->save();

        if ($intent->payment_id !== null) {
            Payment::query()->whereKey($intent->payment_id)->update(['gateway_fee_minor' => $fee]);
        }

        $this->audit->record(
            action: 'payment.card_settled',
            entityType: 'PaymentIntent',
            entityId: (string) $intent->id,
            after: ['reference' => $intent->reference, 'gross_minor' => $gross, 'fee_minor' => $fee, 'net_minor' => $net, 'journal' => $reference],
        );
    }

    private function decline(PaymentIntent $intent, GatewayEvent $event, Carbon $when): void
    {
        $sentence = self::DECLINE[$event->declineCode ?? 'declined'] ?? self::DECLINE['declined'];

        $intent->forceFill(['status' => PaymentIntent::DECLINED, 'decline_reason' => $sentence, 'failed_at' => $when])->save();

        $this->audit->record(
            action: 'payment.card_declined',
            entityType: 'PaymentIntent',
            entityId: (string) $intent->id,
            after: ['reference' => $intent->reference, 'code' => $event->declineCode],
        );
    }

    /**
     * Money sent back to the payer (a refund, or a chargeback the bank forced): the household owes it again. Out of
     * transit before the payout, out of the bank after it. Never more than was captured, in total.
     */
    private function refund(PaymentIntent $intent, GatewayEvent $event, Carbon $when, bool $chargeback): void
    {
        if (! $intent->isCaptured()) {
            $this->capture($intent, null, $when, $event->providerRef);
            $intent->refresh();
        }

        $left = $intent->captured_minor - $intent->refunded_minor;
        $minor = min($left, $event->amountMinor !== null && $event->amountMinor > 0 ? $event->amountMinor : $left);

        if ($minor <= 0) {
            return;
        }

        $fromTransit = $intent->settled_at === null;
        $memo = ($chargeback ? 'Card chargeback · ' : 'Card refund · ').$intent->reference;

        $this->ledger->post(
            memo: $memo,
            postings: [
                Posting::debit('1200', $minor, $memo, unitId: $intent->unit_id),
                Posting::credit($fromTransit ? self::TRANSIT_ACCOUNT : self::BANK_ACCOUNT, $minor, $memo),
            ],
            on: $when,
            source: Ledger::SOURCE_CARD_REFUND,
            sourceId: $intent->id,
            prefix: 'CRF',
        );

        $refunded = $intent->refunded_minor + $minor;

        $intent->forceFill([
            'refunded_minor' => $refunded,
            'refunded_before_settlement_minor' => $intent->refunded_before_settlement_minor + ($fromTransit ? $minor : 0),
            'refunded_at' => $when,
            'charged_back' => $intent->charged_back || $chargeback,
            'status' => $refunded >= $intent->captured_minor ? PaymentIntent::REFUNDED : $intent->status,
        ])->save();

        $this->audit->record(
            action: $chargeback ? 'payment.card_charged_back' : 'payment.card_refunded',
            entityType: 'PaymentIntent',
            entityId: (string) $intent->id,
            after: ['reference' => $intent->reference, 'amount_minor' => $minor, 'from' => $fromTransit ? self::TRANSIT_ACCOUNT : self::BANK_ACCOUNT],
        );
    }

    /**
     * Claim this event's id. False when it was claimed before — a redelivery, a replay, or its twin that won a race.
     * The unique index is the final word; nothing is read first.
     */
    private function claim(string $provider, GatewayEvent $event, string $payloadSha256, string $outcome, ?string $note): bool
    {
        try {
            DB::connection('tenant')->table('payment_gateway_events')->insert([
                'provider' => $provider,
                'event_id' => $event->eventId,
                'event_type' => $event->type,
                'intent_reference' => $event->intentReference,
                'occurred_at' => $event->occurredAt,
                'payload_sha256' => $payloadSha256,
                'outcome' => $outcome,
                'note' => $note,
                'received_at' => Carbon::now(),
            ]);
        } catch (QueryException $duplicate) {
            if (($duplicate->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $duplicate;
        }

        return true;
    }

    /**
     * The same key, presented again. The same payment finds its intent; a different payment under the same key is
     * refused — the key stands for one act.
     *
     * @return array{intent: PaymentIntent, next_action_url: string|null, replayed: bool}
     */
    private function replay(PaymentIntent $intent, Unit $unit, int $minor): array
    {
        if ($intent->unit_id !== $unit->id || $intent->amount_minor !== $minor) {
            throw new DomainException('This payment key was already used for a different payment. Start the payment again.');
        }

        return ['intent' => $intent, 'next_action_url' => $intent->getAttribute('next_action_url'), 'replayed' => true];
    }

    /** When the event happened, never in the future — a receipt is dated the day the money arrived (Dues::receive). */
    private function moment(GatewayEvent $event): Carbon
    {
        $now = Carbon::now();

        return $event->occurredAt === null || $event->occurredAt->greaterThan($now) ? $now : $event->occurredAt->copy();
    }
}
