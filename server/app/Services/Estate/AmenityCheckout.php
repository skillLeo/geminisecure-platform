<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Services\Passes\VisitorPasses;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BOOK, PAY, CONFIRMED — the design's flow (M47 §9–§10; the user's answer, 7 October: "confirmed on payment").
 *
 * Board 22 picks two-hour blocks and reads "Confirm & pay $9,000"; board 31 is "Booking confirmed", with a code a guard
 * can read aloud and a QR for the gate. Before this a booking was a REQUEST: the office approved it later and the fee and
 * the deposit were paid after, one at a time. Paid for in the app, there is nothing left to decide, so it is confirmed
 * at once. The estate's own checks all still stand — opening hours, the diary, the arrears rule (`Amenities::book`) —
 * and with the demonstration card switched off a booking is still the request it was (`bookings.store`).
 *
 * THE SERVER QUOTES; THE PHONE NEVER ADDS (`quote`). The fee is the hourly rate times the hours ("2 hrs × $2,000"), and
 * what the card takes is the server's sum: the fee, anything the household already owes that a payment must clear
 * first (payments clear the oldest charge first — D-179 — so a fee paid over older dues would leave the fee owing),
 * and the deposit. A household that owes nothing pays exactly the design's "Total due today".
 *
 * ALL OF IT OR NONE OF IT (`book`). The card is checked first — a declined test card makes no booking at all — and then
 * in one transaction: the booking, the estate's yes, the fee raised and paid with a real (demonstration) receipt, the
 * deposit held, a confirmation code, and the guests' pass for its hours. Anything refused undoes everything.
 */
final class AmenityCheckout
{
    /** The guests' pass opens the gate this long before the event, for whoever comes early to set up. */
    public const GATE_EARLY_MINUTES = 60;

    /** "GZ-2609-A4": two characters that cannot be misread aloud — no I, O, 0 or 1. */
    private const CODE_LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const CODE_DIGITS = '23456789';

    public function __construct(
        private readonly Amenities $amenities,
        private readonly DemoCardPayments $cards,
        private readonly Dues $dues,
        private readonly VisitorPasses $passes,
    ) {}

    /**
     * What booking these hours costs, worked out once, here.
     *
     *   fee           the rate times the hours, or the amenity's one flat fee
     *   owed_before   what the household already owes that the fee's payment must clear first (oldest first, D-179)
     *   credit        what the household has paid ahead, which pays for the fee first
     *   fee_to_pay    what the card pays towards the fee: fee + owed_before − credit, never below nothing
     *   total         what the card is charged today: fee_to_pay and the deposit
     *
     * @return array{minutes: int, fee: int, owed_before: int, credit: int, fee_to_pay: int, deposit: int, total: int}
     */
    public function quote(Amenity $amenity, Unit $unit, Carbon $from, Carbon $to): array
    {
        $fee = $amenity->feeMinorFor($from, $to);
        $deposit = (int) ($amenity->deposit_minor ?? 0);
        // What the fee's payment must clear for the fee itself to read paid — the fee walked as `recordFee` raises it, today.
        $toPay = $fee <= 0 ? 0 : Dues::owedThrough($this->dues->ledgerOf($unit), null, $fee, SiteTime::date()->toDateString());

        return [
            'minutes' => intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), 60),
            'fee' => $fee,
            'owed_before' => max(0, $toPay - $fee),
            'credit' => $fee > 0 ? max(0, $fee - $toPay) : 0,
            'fee_to_pay' => $toPay,
            'deposit' => $deposit,
            'total' => $toPay + $deposit,
        ];
    }

    /**
     * Book these hours and pay for them with a test card; the booking is confirmed when it returns.
     *
     * @param  int|null  $shownTotal  the total the phone showed, in minor units — a different one now is refused, never taken
     * @return array{booking: AmenityBooking, receipt: Payment|null, pass: VisitorPass}
     *
     * @throws DemoCardDeclined a test card Stripe declines: nothing was made
     * @throws DomainException the estate's refusal, in its words: nothing was made
     */
    public function book(Amenity $amenity, Unit $unit, string $residentName, ?int $residentId, Carbon $from, Carbon $to, string $brand, string $last4, ?int $shownTotal): array
    {
        // The card before anything else: a declined one leaves no booking, no charge and no pass behind it.
        $card = $this->cards->check($brand, $last4);

        $quote = $this->quote($amenity, $unit, $from, $to);

        if ($shownTotal !== null && $shownTotal !== $quote['total']) {
            throw new QuoteChanged('What this booking costs has changed since you opened it. Nothing was charged. Check the total and pay again.');
        }

        $tenantId = (string) tenant()?->getTenantKey();

        return DB::connection('tenant')->transaction(function () use ($amenity, $unit, $residentName, $residentId, $from, $to, $brand, $last4, $card, $tenantId, $quote): array {
            $booking = $this->amenities->book(
                amenity: $amenity,
                unit: $unit,
                residentName: $residentName,
                startsAt: $from,
                endsAt: $to,
                residentId: $residentId,
            );

            // Paid for, so nothing is left to decide: the estate's yes, given by the payment.
            $this->amenities->approve($booking);
            $booking->forceFill(['approved_by_name' => 'Paid in the Resident App'])->save();
            $booking->load(['amenity', 'unit']);

            // The fee raised and paid — or, where money paid ahead already covers it, raised and covered by that.
            $receipt = null;

            if ($booking->fee_minor > 0) {
                $covered = Dues::owedThrough($this->dues->ledgerOf($unit), null, $booking->fee_minor, SiteTime::date()->toDateString()) <= 0;
                // Exactly the quoted figure, or nothing: `payFee` refuses a different one, and the whole booking with it.
                $receipt = $covered ? null : $this->cards->payFee($booking, $brand, $last4, $residentName, $quote['fee_to_pay']);

                if ($covered) {
                    $this->amenities->recordFee($booking);
                }
            }

            if ($booking->deposit_minor > 0) {
                $this->cards->payDeposit($booking->refresh(), $brand, $last4, $residentName);
            }

            $code = $this->code($amenity, $from);
            $pass = $this->passes->issue($tenantId, $unit, $residentId, $residentName, [
                'category' => 'recurring',
                'visitor_name' => $amenity->name.' guests',
                'purpose' => $amenity->name.' booking '.$code,
                'valid_from' => $from->copy()->subMinutes(self::GATE_EARLY_MINUTES),
                'valid_to' => $to->copy(),
            ]);

            $booking->refresh()->forceFill(['confirmation_code' => $code, 'gate_pass_id' => $pass->pass_id, 'paid_card' => $card])->save();

            return ['booking' => $booking->load(['amenity', 'feeCharge']), 'receipt' => $receipt, 'pass' => $pass];
        });
    }

    /**
     * "GZ-2609-A4": the place's two letters, the event's year and month on the estate's calendar, and a letter and a
     * digit — short enough for a guard to read aloud, and unique at the estate.
     */
    private function code(Amenity $amenity, Carbon $from): string
    {
        $stem = self::initials($amenity->name).'-'.SiteTime::local($from)->format('ym').'-';

        for ($try = 0; $try < 40; $try++) {
            $code = $stem.self::CODE_LETTERS[random_int(0, strlen(self::CODE_LETTERS) - 1)].self::CODE_DIGITS[random_int(0, strlen(self::CODE_DIGITS) - 1)];

            if (! AmenityBooking::query()->where('confirmation_code', $code)->exists()) {
                return $code;
            }
        }

        // Every pair taken this month for this place: a third character, rather than a refusal.
        return $stem.Str::upper(Str::random(3));
    }

    /** "Community Centre" → "CC" · "Gazebo" → "GZ" (its first letter and the next consonant) · "Pool Deck" → "PD". */
    public static function initials(string $name): string
    {
        $words = preg_split('/[^A-Za-z]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: ['X'];

        if (count($words) > 1) {
            return Str::upper($words[0][0].$words[1][0]);
        }

        $word = Str::upper($words[0]);
        $next = preg_replace('/[AEIOU]/', '', substr($word, 1)) ?: substr($word, 1, 1) ?: 'X';

        return $word[0].$next[0];
    }
}
