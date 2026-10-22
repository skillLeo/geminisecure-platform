<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Journal;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Services\Audit\AuditLogger;
use App\Support\DemoMode;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE TEST-CARD CHECKOUT, TAKEN THROUGH TO A RECEIPT (M12 §2.6, the client's instruction overriding D-077; D-179).
 *
 * Card number, expiry, security code, name → processing → success → receipt → balance updated, marked as a
 * demonstration payment, wherever money is payable: dues, and an amenity booking's fee (a charge on the unit like
 * any other) and deposit. Stripe's own test numbers only; nothing real is charged; no card is stored.
 *
 *   THE CARD NEVER LEAVES THE PHONE. The phone checks the form (number, expiry, code, name) against Stripe's test
 *              list itself and sends only which test card it was — its brand and last four digits. A real card
 *              number is wiped on the phone the moment it is recognised, so there is nothing here to store.
 *   THE OUTCOME IS STRIPE'S for that test card, decided here: succeeds, or is declined with Stripe's own sentence
 *              (declined, insufficient funds, expired card, incorrect security code) and nothing is posted.
 *   REAL IN THE BOOKS, NEVER IN THE BANK. A success is a real Payment — a receipt number from the estate's
 *              sequence, the household's receivable cleared oldest-first like any payment — but the debit is 1090
 *              Demonstration Card Payments (no money), never 1000 Bank. The treasurer reads the whole of it on
 *              one line, the receipt and every list say "Demonstration payment", and the operating account never
 *              shows money that did not arrive.
 *   ONLINE ONLY. A payment is never queued (the outbox refuses `payments:` abilities); the phone says so.
 */
final class DemoCardPayments
{
    /** Where a test card's "money" goes: an asset account with nothing in it but demonstrations. */
    public const ACCOUNT = '1090';

    /** Stripe's published test cards, by brand and last four digits — the phone's `TEST_CARDS`, the same list. */
    public const TEST_CARDS = [
        'visa:4242' => 'succeeded',
        'visa:5556' => 'succeeded',
        'mastercard:4444' => 'succeeded',
        'amex:0005' => 'succeeded',
        'discover:1117' => 'succeeded',
        'visa:0002' => 'declined',
        'visa:9995' => 'insufficient_funds',
        'visa:0069' => 'expired_card',
        'visa:0127' => 'incorrect_cvc',
    ];

    /** Stripe's own sentences, and what to do next — never a gateway code. */
    public const DECLINE = [
        'declined' => 'Your card was declined. Nothing was charged. Try another card.',
        'insufficient_funds' => 'Your card has insufficient funds. Nothing was charged. Try another card.',
        'expired_card' => 'Your card has expired. Nothing was charged. Check the date, or try another card.',
        'incorrect_cvc' => 'Your card\'s security code is incorrect. Nothing was charged. Check the code and try again.',
    ];

    public const BRAND_NAMES = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'discover' => 'Discover'];

    public function __construct(
        private readonly Dues $dues,
        private readonly Amenities $amenities,
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * UNDO EVERY DEMONSTRATION AT THIS ESTATE — after the client's demonstration (`payments:reverse-demonstrations`).
     *
     * Each demonstration payment's entry is reversed the only way an entry is (`Ledger::reverse`: mirrored, both kept),
     * so the household owes again what the test card "paid" and 1090 goes back to nil; the payment reads `reversed`.
     * A deposit a test card paid that is still held is refunded — back to 1090, where it came from. Real payments are
     * never touched: only rows marked a demonstration are read.
     *
     * @return array{payments: list<string>, deposits: list<string>}
     */
    public function reverseAll(): array
    {
        $done = ['payments' => [], 'deposits' => []];

        foreach (Payment::query()->where('is_demonstration', true)->where('status', 'recorded')->orderBy('id')->get() as $payment) {
            $entry = Journal::query()->where('reference', $payment->journal_ref)->first();

            if ($entry === null) {
                continue;
            }

            DB::connection('tenant')->transaction(function () use ($payment, $entry): void {
                $this->ledger->reverse($entry, 'Demonstration card payment reversed — receipt '.$payment->receipt_no);
                $payment->forceFill(['status' => 'reversed'])->save();
            });

            $done['payments'][] = $payment->receipt_no;
        }

        foreach (AmenityBooking::query()->where('deposit_demonstration', true)->where('deposit_state', AmenityBooking::DEPOSIT_HELD)->orderBy('id')->get() as $booking) {
            $this->amenities->refundDeposit($booking, why: 'demonstration reversed');
            $done['deposits'][] = $booking->reference;
        }

        if ($done['payments'] !== [] || $done['deposits'] !== []) {
            $this->audit->record(action: 'payment.demonstrations_reversed', entityType: 'Estate', entityId: (string) (tenant()?->getTenantKey() ?? ''), after: $done);
        }

        return $done;
    }

    /**
     * Whether this platform takes demonstration card payments at all.
     *
     * TWO LOCKS, AND THE OUTER ONE IS THE LAUNCH LOCK (M16 §2.4). The test-card checkout
     * moves no money and posts to 1090, so it must never exist on a live system: it is
     * gated first on `DemoMode` — off in production — and only then on the client's own
     * `payments.demo_card` toggle. With the demonstration switch off the card option is
     * gone, not simulated; the phones show How to pay (bank transfer, cash, cheque) only,
     * exactly as D-077 ruled, until a real gateway is wired (M16 §2.2).
     */
    public static function enabled(): bool
    {
        return DemoMode::enabled() && (bool) config('payments.demo_card', true);
    }

    /**
     * Pay some or all of what the household owes, with a test card.
     *
     * @throws DemoCardDeclined when the test card is one Stripe declines — nothing is posted
     * @throws DomainException when it cannot be paid at all: switched off, not a test card, nothing owed, too much
     */
    public function payDues(Unit $unit, Money $amount, string $brand, string $last4, string $payer): Payment
    {
        $card = $this->card($brand, $last4);

        if ($amount->isNegativeOrZero()) {
            throw new DomainException('Choose an amount to pay.');
        }

        $owed = $this->dues->unpaidOf($unit);

        if ($owed->isNegativeOrZero()) {
            throw new DomainException('Nothing is owed on '.$unit->reference.' just now, so there is nothing to pay.');
        }

        if ($amount->isGreaterThan($owed)) {
            throw new DomainException(sprintf('That is more than is owed. The most you can pay now is %s.', MoneyFormatter::fromMinor($owed->getMinorAmount()->toInt())));
        }

        $payment = $this->dues->receive(
            unit: $unit,
            amount: $amount,
            method: Payment::CARD,
            receivedAt: Carbon::now(),
            bankAccount: self::ACCOUNT,
            gatewayRef: 'DEMO-'.strtoupper(bin2hex(random_bytes(5))),
            reference: $card['label'],
            demonstration: true,
        );

        $this->audit->record(
            action: 'payment.demonstration_card',
            entityType: 'Payment',
            entityId: (string) $payment->id,
            after: [
                'receipt_no' => $payment->receipt_no,
                'unit' => $unit->reference,
                'amount_minor' => $payment->amount_minor,
                'card' => $card['label'],
                'paid_by' => $payer,
                'account' => self::ACCOUNT,
            ],
        );

        return $payment;
    }

    /**
     * Pay an amenity booking's FEE with a test card (M12 §2.14). The fee is a charge on the unit like any other: if the
     * office has not raised it yet it is raised now — only on a booking the estate has approved, `recordFee`'s own
     * rule — and then paid, so the booking reads "fee paid" and the statement shows both lines.
     *
     * PAID WITH WHAT IS OWED BEFORE IT. Payments clear the oldest charge first, so the card pays the fee and every
     * older charge still owing (`Dues::owedThrough`) — the phone shows that figure, and why, before the card is taken.
     * Paying the fee alone over older dues would clear the dues and leave the fee still owing.
     *
     * @throws DemoCardDeclined
     * @throws DomainException
     */
    public function payFee(AmenityBooking $booking, string $brand, string $last4, string $payer, ?int $shownMinor = null): Payment
    {
        // The card first: a declined test card leaves the booking exactly as it was, fee not raised.
        $this->card($brand, $last4);

        if ($booking->fee_minor <= 0) {
            throw new DomainException('The '.$booking->amenity->name.' carries no booking fee.');
        }

        if ($booking->status === AmenityBooking::PENDING) {
            throw new DomainException('The estate office has not approved this booking yet. Its fee is paid once it is approved.');
        }

        return DB::connection('tenant')->transaction(function () use ($booking, $brand, $last4, $payer, $shownMinor): Payment {
            $charge = $booking->isCharged() ? $booking->feeCharge : $this->amenities->recordFee($booking);
            $owed = Dues::owedThrough($this->dues->ledgerOf($booking->unit), (int) $charge->id);

            if ($owed <= 0) {
                // Paid — or covered: money paid ahead clears the oldest charge first, and this fee is older than the rest.
                throw new DomainException('The fee for this booking is already paid. Anything your household still owes is under Dues.');
            }

            // Never take a different figure from the one the resident was shown: something was paid or charged since.
            if ($shownMinor !== null && $shownMinor !== $owed) {
                throw new DomainException(sprintf('What this fee takes has changed since you opened it — it is %s now. Nothing was charged. Check it and pay again.', MoneyFormatter::fromMinor($owed)));
            }

            return $this->payDues($booking->unit, Money::ofMinor($owed, 'JMD'), $brand, $last4, $payer);
        });
    }

    /**
     * Pay an amenity booking's deposit with a test card: it is held against 1090, and goes back there if refunded.
     *
     * @throws DemoCardDeclined
     * @throws DomainException
     */
    public function payDeposit(AmenityBooking $booking, string $brand, string $last4, string $payer): AmenityBooking
    {
        $card = $this->card($brand, $last4);

        if ($booking->deposit_minor <= 0) {
            throw new DomainException('This booking carries no deposit.');
        }

        if ($booking->deposit_state !== AmenityBooking::DEPOSIT_AWAITING) {
            throw new DomainException($booking->deposit_state === AmenityBooking::DEPOSIT_HELD
                ? 'The deposit on this booking is already paid and held.'
                : 'The deposit on this booking is no longer waiting to be paid.');
        }

        $held = $this->amenities->holdDeposit($booking, demonstration: true);

        $this->audit->record(
            action: 'booking.demonstration_deposit',
            entityType: 'AmenityBooking',
            entityId: (string) $booking->id,
            after: ['reference' => $booking->reference, 'deposit_minor' => $booking->deposit_minor, 'card' => $card['label'], 'paid_by' => $payer, 'account' => self::ACCOUNT],
        );

        return $held;
    }

    /**
     * The card alone, before anything is written (M47 §10): a booking paid for at once is not even made when its test
     * card is one Stripe declines. "Visa •••• 4417" — the brand and last four, all that is ever kept of it.
     *
     * @throws DemoCardDeclined
     * @throws DomainException
     */
    public function check(string $brand, string $last4): string
    {
        $card = $this->card($brand, $last4);

        return (self::BRAND_NAMES[$card['brand']] ?? 'Card').' •••• '.$card['last4'];
    }

    /**
     * Which test card this is, or why it cannot be used. A declining card is refused HERE, before anything is posted.
     *
     * @return array{brand: string, last4: string, label: string}
     */
    private function card(string $brand, string $last4): array
    {
        if (! self::enabled()) {
            throw new DomainException('Card payments are not switched on. See How to pay for the estate\'s bank details.');
        }

        $brand = strtolower(trim($brand));
        $last4 = preg_replace('/\D/', '', $last4) ?? '';
        $outcome = self::TEST_CARDS[$brand.':'.$last4] ?? null;

        if ($outcome === null) {
            throw new DomainException('That is not one of Stripe\'s test cards. This checkout takes test cards only, like 4242 4242 4242 4242 — never a real card.');
        }

        if ($outcome !== 'succeeded') {
            throw new DemoCardDeclined($outcome, self::DECLINE[$outcome]);
        }

        return ['brand' => $brand, 'last4' => $last4, 'label' => 'Demonstration card payment · '.(self::BRAND_NAMES[$brand] ?? 'Card').' •••• '.$last4];
    }
}
