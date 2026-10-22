<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\VisitorPass;
use App\Services\Estate\Amenities;
use App\Services\Estate\AmenityCheckout;
use App\Services\Estate\DemoCardDeclined;
use App\Services\Estate\DemoCardPayments;
use App\Services\Estate\Dues;
use App\Services\Estate\QuoteChanged;
use App\Services\Estate\SlotTaken;
use App\Services\ResidentApp\ResidentAccounts;
use App\Support\Decimal;
use App\Support\SiteTime;
use Brick\Money\Money;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Amenities and the household's bookings (13 D3). Boards resident-app-22, -23.
 *
 * The same diary board 19 decides from, through `Amenities::book` and
 * `Amenities::cancel`: capacity, opening hours, the diary and the arrears rule
 * are the estate's own checks, and their refusals come back word for word.
 */
class BookingsController extends Controller
{
    /** How far ahead the taken hours are sent when an amenity sets no booking window of its own (M21 §1.5). */
    private const TAKEN_DAYS_MAX = 90;

    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly Amenities $amenities,
        private readonly Dues $dues,
    ) {}

    public function amenities(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $mayBook = $this->amenities->mayBook($unit);
        $list = Amenity::query()->where('is_active', true)->where('is_bookable', true)->orderBy('sort_order')->get();
        // As far ahead as any of them takes bookings — the picker offers no day past its amenity's window.
        $ahead = min(self::TAKEN_DAYS_MAX, (int) ($list->max(static fn (Amenity $a): int => $a->booking_window_days ?? self::TAKEN_DAYS_MAX) ?? 0));
        $taken = $this->amenities->takenPeriods($list->pluck('id')->map(static fn ($id): int => (int) $id)->all(), Carbon::now()->addDays($ahead + 1));

        return response()->json([
            'may_book' => $mayBook,
            // M47 §9–§10: a booking can be paid for in the app, which confirms it at once (`bookings.book`).
            'card_payments' => DemoCardPayments::enabled(),
            'items' => $list
                ->map(static fn (Amenity $a): array => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'icon_key' => $a->icon_key,
                    'capacity' => $a->capacity,
                    'opens_at' => $a->opens_at,
                    'closes_at' => $a->closes_at,
                    'booking_fee' => Decimal::of((int) ($a->booking_fee_minor ?? 0)),
                    'deposit' => Decimal::of((int) ($a->deposit_minor ?? 0)),
                    'currency' => $a->currency,
                    'cancellation_hours' => $a->cancellation_hours,
                    'booking_window_days' => $a->booking_window_days,
                    // M53 §2: a booking of it is a request the office approves, its fee charged then (`bookings.store`),
                    // never paid for in the app (`bookings.book` refuses it).
                    'requires_approval' => (bool) $a->requires_approval,
                    // When the diary is already taken (M21 §1.5): the hours only — never whose booking, never its reference.
                    'taken' => array_map(static fn (array $p): array => ['starts_at' => SiteTime::iso($p['starts_at']), 'ends_at' => SiteTime::iso($p['ends_at'])], $taken[(int) $a->id] ?? []),
                    // M47 §8–§9: what the place is and where, its rate by the hour, the blocks it is booked in, its rules.
                    'description' => $a->description,
                    'place' => $a->place,
                    'hourly_rate' => $a->isHourly() ? Decimal::of((int) $a->hourly_rate_minor) : null,
                    'slot_minutes' => $a->slot_minutes,
                    'rules_summary' => $a->rules_summary,
                    'rules' => $a->rules,
                ])->all(),
        ]);
    }

    /**
     * What booking these hours would cost, worked out by the server (M47 §9.5): the rate times the hours, anything the
     * household already owes that a payment clears first, the deposit, and the total the card would be charged. The
     * phone shows these figures and adds none of them up.
     */
    public function quote(Request $request, int $amenity, DeviceContext $context, AmenityCheckout $checkout): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $place = Amenity::query()->whereKey($amenity)->where('is_active', true)->where('is_bookable', true)->first();

        if ($place === null) {
            throw ApiError::notFound('not_found', 'No amenity with that id.');
        }

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $quote = $checkout->quote($place, $unit, SiteTime::parse((string) $data['starts_at']), SiteTime::parse((string) $data['ends_at']));

        return response()->json([
            'minutes' => $quote['minutes'],
            'hourly_rate' => $place->isHourly() ? Decimal::of((int) $place->hourly_rate_minor) : null,
            'fee' => Decimal::of($quote['fee']),
            'owed_before' => Decimal::of($quote['owed_before']),
            'credit' => Decimal::of($quote['credit']),
            'deposit' => Decimal::of($quote['deposit']),
            'total' => Decimal::of($quote['total']),
            'currency' => $place->currency,
        ]);
    }

    /**
     * Book these hours and pay for them with a test card — CONFIRMED when it returns (M47 §10, the user's "confirmed on
     * payment"): the fee paid with a receipt, the deposit held, a confirmation code, and the guests' pass for its hours.
     * Refused in the estate's words, or the card's, with nothing made.
     */
    public function book(Request $request, DeviceContext $context, AmenityCheckout $checkout, DemoCardPayments $cards): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'amenity_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'brand' => ['required', 'string', 'in:visa,mastercard,amex,discover'],
            'last4' => ['required', 'string', 'regex:/^\d{4}$/'],
            // The total the phone showed: a different one now is refused, never taken.
            'total' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'rules_accepted' => ['nullable', 'boolean'],
        ]);

        $place = Amenity::query()->find($data['amenity_id']);

        if ($place === null) {
            throw ApiError::notFound('not_found', 'No amenity with that id.');
        }

        // M53 §2: a booking of this amenity waits for the office, so it is never paid for and confirmed here.
        if ($place->requires_approval) {
            throw ApiError::conflict('approval_required', 'The '.$place->name.' takes requests the estate office approves. Send a request: its fee is charged once it is approved.');
        }

        // The house rules are accepted where the estate has some for the place (M47 §9.6); with none, nothing is asked.
        if (trim((string) $place->rules) !== '' && ! $request->boolean('rules_accepted')) {
            throw ValidationException::withMessages(['rules_accepted' => 'Tick the box to accept the house rules.']);
        }

        $starts = SiteTime::parse((string) $data['starts_at']);

        if ($starts->isPast()) {
            throw ApiError::unprocessable('booking_refused', 'A booking starts in the future.');
        }

        if ($place->booking_window_days !== null && $starts->greaterThan(Carbon::now()->addDays($place->booking_window_days))) {
            throw ApiError::unprocessable('booking_refused', 'The '.$place->name.' takes bookings up to '.$place->booking_window_days.' days ahead.');
        }

        // The card's own refusal comes first, and in its own words: not switched on, not a test card, or declined.
        try {
            $cards->check((string) $data['brand'], (string) $data['last4']);
        } catch (DemoCardDeclined $declined) {
            throw ApiError::unprocessable('card_declined', $declined->getMessage());
        } catch (DomainException $refused) {
            throw ApiError::conflict('payment_refused', $refused->getMessage());
        }

        $payer = (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident');

        try {
            $made = $checkout->book(
                amenity: $place,
                unit: $home['unit'],
                residentName: $payer,
                residentId: $home['resident']?->id,
                from: $starts,
                to: SiteTime::parse((string) $data['ends_at']),
                brand: (string) $data['brand'],
                last4: (string) $data['last4'],
                shownTotal: Money::of((string) $data['total'], 'JMD')->getMinorAmount()->toInt(),
            );
        } catch (DemoCardDeclined $declined) {
            throw ApiError::unprocessable('card_declined', $declined->getMessage());
        } catch (QuoteChanged $changed) {
            throw ApiError::conflict('payment_refused', $changed->getMessage());
        } catch (SlotTaken $taken) {
            throw ApiError::unprocessable('booking_refused', sprintf(
                'The %s is already booked %s. Choose hours outside that time, or another day.',
                $place->name,
                self::periodWords($taken->from, $taken->to),
            ));
        } catch (DomainException $refused) {
            throw ApiError::unprocessable('booking_refused', $refused->getMessage());
        }

        $booking = $made['booking'];
        $booking->forceFill(['is_simulated' => $request->boolean('simulated')])->save();
        $receipt = $made['receipt'];

        return response()->json([
            ...$this->shape($booking, $this->dues->ledgerOf($home['unit']), [$made['pass']->pass_id => $made['pass']]),
            'receipt' => $receipt === null ? null : [
                'id' => $receipt->id,
                'receipt_no' => $receipt->receipt_no,
                'amount' => Decimal::of((int) $receipt->amount_minor),
                'reference' => $receipt->reference,
                'is_demonstration' => true,
            ],
        ], 201);
    }

    /**
     * "from 2:00 PM to 6:00 PM JA on Saturday, 3 October" — a taken period in the household's terms (M21 §1.5). The
     * estate's own refusal named the other household's booking reference and spoke to the office; the household
     * needs the hours, and what to do.
     */
    private static function periodWords(Carbon $from, Carbon $to): string
    {
        $a = SiteTime::local($from);
        $b = SiteTime::local($to);
        $tag = SiteTime::clockTag(SiteTime::zone());

        return $a->isSameDay($b)
            ? sprintf('from %s to %s %s on %s', $a->format('g:i A'), $b->format('g:i A'), $tag, $a->format('l, j F'))
            : sprintf('from %s %s to %s %s', $a->format('l, j F, g:i A'), $tag, $b->format('l, j F, g:i A'), $tag);
    }

    public function index(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        // The unit's balance and charges, once for the list: whether each booking's fee is paid (M12 §2.14).
        $ledger = $this->dues->ledgerOf($unit);

        $items = AmenityBooking::query()->with('amenity')->where('unit_id', $unit->id)->orderByDesc('starts_at')->limit(100)->get();
        $passes = self::passesOf(...$items->all());

        return response()->json([
            'card_payments' => DemoCardPayments::enabled(),
            'items' => $items->map(fn (AmenityBooking $b): array => $this->shape($b, $ledger, $passes))->all(),
        ]);
    }

    /**
     * The guests' passes of these bookings, by pass id, in one query — none when none carries one (M47 §10.3).
     *
     * @return array<string, VisitorPass>
     */
    private static function passesOf(AmenityBooking ...$bookings): array
    {
        $ids = array_values(array_filter(array_map(static fn (AmenityBooking $b): ?string => $b->gate_pass_id, $bookings)));

        return $ids === [] ? [] : VisitorPass::query()->whereIn('pass_id', $ids)->get()->keyBy('pass_id')->all();
    }

    /**
     * Pay a booking's fee or deposit with a TEST card (M12 §2.6, §2.14; D-179). The phone sends only which of Stripe's
     * test cards it was. The fee becomes a real receipt; the deposit is held — both against 1090, never the bank.
     */
    public function payCard(Request $request, int $booking, DeviceContext $context, DemoCardPayments $cards): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = AmenityBooking::query()->with(['amenity', 'unit', 'feeCharge'])->whereKey($booking)->where('unit_id', $home['unit']->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No booking of your household\'s with that id.');
        }

        $data = $request->validate([
            'part' => ['required', 'string', 'in:fee,deposit'],
            'brand' => ['required', 'string', 'in:visa,mastercard,amex,discover'],
            'last4' => ['required', 'string', 'regex:/^\d{4}$/'],
            // The figure the phone showed for the fee (`fee_to_clear`): a different one is refused, never taken.
            'amount' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ]);

        $payer = (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident');
        $shown = isset($data['amount']) ? Money::of((string) $data['amount'], 'JMD')->getMinorAmount()->toInt() : null;
        $receipt = null;

        try {
            if ($data['part'] === 'fee') {
                $receipt = $cards->payFee($record, (string) $data['brand'], (string) $data['last4'], $payer, $shown);
            } else {
                $cards->payDeposit($record, (string) $data['brand'], (string) $data['last4'], $payer);
            }
        } catch (DemoCardDeclined $declined) {
            throw ApiError::unprocessable('card_declined', $declined->getMessage());
        } catch (DomainException $refused) {
            throw ApiError::conflict('payment_refused', $refused->getMessage());
        }

        $record->refresh()->load(['amenity', 'feeCharge']);

        return response()->json([
            'booking' => $this->shape($record, $this->dues->ledgerOf($home['unit']), self::passesOf($record)),
            'receipt' => $receipt === null ? null : [
                'id' => $receipt->id,
                'receipt_no' => $receipt->receipt_no,
                'amount' => Decimal::of((int) $receipt->amount_minor),
                'reference' => $receipt->reference,
                'is_demonstration' => true,
            ],
        ]);
    }

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'amenity_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            // How many guests: no longer asked (M47 §9d — the board has no such field), still taken and still checked
            // against the capacity when an older app sends it. The note is ADDITIONAL (M18C §3.3).
            'guests' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $amenity = Amenity::query()->find($data['amenity_id']);

        if ($amenity === null) {
            throw ApiError::notFound('not_found', 'No amenity with that id.');
        }

        // The phone sends a moment WITH ITS OFFSET (`…T14:00:00-05:00`): the offset is honoured and the
        // moment kept in UTC. A string with no offset is read on the estate's clock (M4 §3.1).
        $starts = SiteTime::parse((string) $data['starts_at']);

        if ($starts->isPast()) {
            throw ApiError::unprocessable('booking_refused', 'A booking starts in the future.');
        }

        if ($amenity->booking_window_days !== null && $starts->greaterThan(Carbon::now()->addDays($amenity->booking_window_days))) {
            throw ApiError::unprocessable('booking_refused', 'The '.$amenity->name.' takes bookings up to '.$amenity->booking_window_days.' days ahead.');
        }

        try {
            $booking = $this->amenities->book(
                amenity: $amenity,
                unit: $home['unit'],
                residentName: (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident'),
                startsAt: $starts,
                endsAt: SiteTime::parse((string) $data['ends_at']),
                guests: $data['guests'] ?? null,
                notes: $data['notes'] ?? null,
                residentId: $home['resident']?->id,
            );
        } catch (SlotTaken $taken) {
            throw ApiError::unprocessable('booking_refused', sprintf(
                'The %s is already booked %s. Choose hours outside that time, or another day.',
                $amenity->name,
                self::periodWords($taken->from, $taken->to),
            ));
        } catch (DomainException $refused) {
            throw ApiError::unprocessable('booking_refused', $refused->getMessage());
        }

        $booking->forceFill(['is_simulated' => $request->boolean('simulated')])->save();

        return response()->json($this->shape($booking->load('amenity'), $this->dues->ledgerOf($home['unit'])), 201);
    }

    public function cancel(int $booking, DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $record = AmenityBooking::query()->with('amenity')->whereKey($booking)->where('unit_id', $unit->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No booking of your household\'s with that id.');
        }

        try {
            $this->amenities->cancel($record);
        } catch (DomainException $refused) {
            throw ApiError::conflict('cancellation_refused', $refused->getMessage());
        }

        return response()->json($this->shape($record, $this->dues->ledgerOf($unit), self::passesOf($record)));
    }

    /**
     * @param  array{balance: int, charges: list<array{id: int, due: string, amount: int}>}  $ledger  the unit's (`Dues::ledgerOf`)
     * @param  array<string, VisitorPass>  $passes  the guests' passes, by pass id (`passesOf`)
     * @return array<string, mixed>
     */
    private function shape(AmenityBooking $b, array $ledger, array $passes = []): array
    {
        // The gate QR only while it can open the gate for this booking: confirmed, still to come, its pass not cancelled.
        $pass = $b->gate_pass_id === null ? null : ($passes[$b->gate_pass_id] ?? null);
        $gate = $pass !== null && $pass->status === VisitorPass::ACTIVE && $b->status === AmenityBooking::CONFIRMED && $b->ends_at->isFuture() ? (string) $pass->token : null;

        $charged = $b->isCharged();
        $refused = in_array($b->status, [AmenityBooking::DECLINED, AmenityBooking::CANCELLED, AmenityBooking::EXPIRED], true);
        // What is owed on the fee itself, and what a card payment for it takes: the fee and every older charge still
        // owing, since payments clear the oldest first (`Dues::owedThrough`). A fee not raised yet is walked as it will be.
        $feeOwed = match (true) {
            $b->fee_minor <= 0 => 0,
            $charged => Dues::walk($ledger)[(int) $b->fee_charge_id] ?? 0,
            $refused => 0,
            default => $b->fee_minor,
        };
        $toClear = match (true) {
            $feeOwed <= 0 => 0,
            $charged => Dues::owedThrough($ledger, (int) $b->fee_charge_id),
            default => Dues::owedThrough($ledger, null, $b->fee_minor, SiteTime::date()->toDateString()),
        };

        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'amenity' => ['id' => $b->amenity_id, 'name' => $b->amenity->name],
            // ONE TIME RULE (M4 §3.1): UTC instants, all four. The app shows them in the estate's `timezone`.
            'starts_at' => SiteTime::iso($b->starts_at),
            'ends_at' => SiteTime::iso($b->ends_at),
            'guests' => $b->guests,
            'status' => $b->status,
            'booking_fee' => Decimal::of($b->fee_minor),
            'deposit' => Decimal::of($b->deposit_minor),
            'deposit_state' => $b->deposit_state,
            // What the estate KEPT of a forfeited deposit — it may be a part, and the rest went back (M4 §4, defect 2).
            'deposit_kept' => $b->depositKeptMinor() === null ? null : Decimal::of((int) $b->depositKeptMinor()),
            'currency' => $b->currency,
            // The same moment `Amenities::cancel` judges by: N elapsed hours before the start, on the UTC clock.
            'cancellable_until' => SiteTime::iso($b->cancellation_hours === null ? $b->starts_at : $b->starts_at->copy()->utc()->subHours($b->cancellation_hours)),
            'declined_reason' => $b->declined_reason,
            'cancelled_at' => SiteTime::iso($b->cancelled_at),
            // M12 §2.14: whether each part is paid, and whether it can be paid in the app now.
            'fee_charged' => $charged,
            'fee_outstanding' => Decimal::of($feeOwed),
            'fee_to_clear' => Decimal::of($toClear),
            'fee_paid' => $b->fee_minor > 0 && $charged && $feeOwed <= 0,
            'deposit_demonstration' => (bool) $b->deposit_demonstration,
            // M47 §10: a booking paid for in the app — its code for the gate, the guests' QR, and the card it was paid with.
            'confirmation_code' => $b->confirmation_code,
            'gate_token' => $gate,
            'paid_with' => $b->paid_card,
        ];
    }
}
