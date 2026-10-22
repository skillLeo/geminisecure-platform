<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\AutopayArrangement;
use App\Models\Estate\HouseholdCard;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Services\Audit\AuditLogger;
use App\Support\Decimal;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AUTOPAY (boards resident-app-10 "Turn on AutoPay", -40 "AUTOPAY IS ON"; M50 §4.2 #3, §6.6) — it turns on, it stays
 * on, and it pays.
 *
 *   ON        a household's standing instruction: on `day_of_month` (1–28, a day every month has), pay what the
 *             household owes, up to the limit, with the resident's saved card. One per household; turning it on again
 *             changes it, it never stacks.
 *   IT PAYS   `dues:autopay` runs it on the estate's own clock, through the same demonstration checkout a tap on Pay
 *             uses (`DemoCardPayments::payDues`): a real receipt from the estate's sequence, posted to 1090 Demonstration
 *             Card Payments, no money moved, and undone with every other demonstration by
 *             `payments:reverse-demonstrations`. Nothing owed is nothing charged. What each run did is on the row, in a
 *             sentence the app shows ("Paid $12,400.00 — receipt R-…", "Nothing was owed", a decline in Stripe's words).
 *   ONLY WHILE CARDS ARE ON. With the demonstration checkout off (`DemoCardPayments::enabled()` — the launch lock) it
 *             cannot be turned on, and an arrangement made before does not charge: its run says so instead.
 */
final class Autopay
{
    /** The board's own terms: "Automatically pay up to $15,000 on the 28th each month." */
    public const DEFAULT_LIMIT_MINOR = 1_500_000;

    public const DEFAULT_DAY = 28;

    public const MIN_LIMIT_MINOR = 10_000;

    public const MAX_LIMIT_MINOR = 100_000_000;

    public function __construct(
        private readonly DemoCardPayments $payments,
        private readonly Dues $dues,
        private readonly AuditLogger $audit,
    ) {}

    public function current(Unit $unit): ?AutopayArrangement
    {
        return AutopayArrangement::query()->with('card')->where('unit_id', $unit->id)->where('status', AutopayArrangement::ON)->latest('id')->first();
    }

    /**
     * Turned on — or its terms changed. The card must be the resident's own, saved, and not expired.
     *
     * @throws DomainException
     */
    public function turnOn(Unit $unit, ResidentAccount $account, HouseholdCard $card, int $limitMinor, int $day, Carbon $today): AutopayArrangement
    {
        if (! DemoCardPayments::enabled()) {
            throw new DomainException('AutoPay needs card payments, which are not switched on for this estate. Pay by bank transfer, cash or cheque — see How to pay.');
        }

        if ((int) $card->unit_id !== (int) $unit->id || (int) $card->resident_account_id !== (int) $account->id) {
            throw new DomainException('That card is not one of yours. Choose one of your saved cards.');
        }

        if (HouseholdCards::expired($card->exp_month, $card->exp_year, $today)) {
            throw new DomainException('That card has expired. Save a current card, then turn AutoPay on.');
        }

        if ($limitMinor < self::MIN_LIMIT_MINOR || $limitMinor > self::MAX_LIMIT_MINOR) {
            throw new DomainException(sprintf('Choose a limit between %s and %s.', MoneyFormatter::fromMinor(self::MIN_LIMIT_MINOR), MoneyFormatter::fromMinor(self::MAX_LIMIT_MINOR)));
        }

        if ($day < 1 || $day > 28) {
            throw new DomainException('Choose a day from the 1st to the 28th — a day every month has.');
        }

        return DB::connection('tenant')->transaction(function () use ($unit, $account, $card, $limitMinor, $day): AutopayArrangement {
            $was = $this->current($unit);
            $arrangement = $was ?? new AutopayArrangement(['unit_id' => $unit->id, 'status' => AutopayArrangement::ON, 'turned_on_at' => Carbon::now()]);
            $arrangement->forceFill([
                'resident_account_id' => (int) $account->id,
                'household_card_id' => (int) $card->id,
                'limit_minor' => $limitMinor,
                'day_of_month' => $day,
            ])->save();

            $this->audit->record(
                action: $was === null ? 'autopay.on' : 'autopay.changed',
                entityType: 'AutopayArrangement',
                entityId: (string) $arrangement->id,
                before: $was === null ? null : ['limit_minor' => $was->limit_minor, 'day' => $was->day_of_month, 'card_id' => $was->household_card_id],
                after: ['unit' => $unit->reference, 'limit_minor' => $limitMinor, 'day' => $day, 'card' => HouseholdCards::label($card), 'account_id' => (int) $account->id],
            );

            return $arrangement->load('card');
        });
    }

    public function turnOff(Unit $unit, ResidentAccount $account, AutopayArrangement $arrangement): AutopayArrangement
    {
        if ((int) $arrangement->unit_id !== (int) $unit->id) {
            throw new DomainException('That is not your household\'s AutoPay.');
        }

        if ($arrangement->status === AutopayArrangement::ON) {
            $arrangement->forceFill(['status' => AutopayArrangement::OFF, 'turned_off_at' => Carbon::now()])->save();
            $this->audit->record(action: 'autopay.off', entityType: 'AutopayArrangement', entityId: (string) $arrangement->id, after: ['unit' => $unit->reference, 'account_id' => (int) $account->id]);
        }

        return $arrangement;
    }

    /** The next day it pays, on the estate's calendar: this month's day if it is still to come today or later, else next month's. */
    public static function nextChargeOn(AutopayArrangement $arrangement, Carbon $today): Carbon
    {
        $thisMonth = $today->copy()->startOfMonth()->day($arrangement->day_of_month);
        $ranToday = $arrangement->last_run_on !== null && $arrangement->last_run_on->isSameDay($today);

        return $thisMonth->greaterThan($today) || ($thisMonth->isSameDay($today) && ! $ranToday) ? $thisMonth : $thisMonth->addMonthNoOverflow();
    }

    /**
     * Every arrangement due on `$today` (the estate's date) that has not run today: pay what is owed, up to its limit.
     *
     * @return array{paid: list<string>, nothing: list<string>, refused: list<string>}
     */
    public function runDue(Carbon $today, bool $dryRun = false): array
    {
        $report = ['paid' => [], 'nothing' => [], 'refused' => []];

        $due = AutopayArrangement::query()->with('card')->where('status', AutopayArrangement::ON)->where('day_of_month', (int) $today->format('j'))
            ->where(static fn ($q) => $q->whereNull('last_run_on')->orWhere('last_run_on', '<', $today->toDateString()))
            ->orderBy('id')->get();

        foreach ($due as $arrangement) {
            $unit = Unit::query()->find($arrangement->unit_id);

            if ($unit === null) {
                continue;
            }

            [$kind, $sentence, $paymentId] = $this->charge($arrangement, $unit, $today, $dryRun);
            $report[$kind][] = $unit->reference.' · '.$sentence;

            if (! $dryRun) {
                $arrangement->forceFill(['last_run_on' => $today->toDateString(), 'last_outcome' => mb_substr($sentence, 0, 200), 'last_payment_id' => $paymentId ?? $arrangement->last_payment_id])->save();
            }
        }

        return $report;
    }

    /** @return array{0: 'paid'|'nothing'|'refused', 1: string, 2: int|null} */
    private function charge(AutopayArrangement $arrangement, Unit $unit, Carbon $today, bool $dryRun): array
    {
        $card = $arrangement->card;

        if (! DemoCardPayments::enabled()) {
            return ['refused', 'Not charged: card payments are switched off for this estate.', null];
        }

        if ($card === null) {
            return ['refused', 'Not charged: AutoPay has no saved card.', null];
        }

        if (HouseholdCards::expired($card->exp_month, $card->exp_year, $today)) {
            return ['refused', 'Not charged: '.HouseholdCards::label($card).' has expired. Save a current card.', null];
        }

        $owed = $this->dues->balanceOf($unit);

        if ($owed->isNegativeOrZero()) {
            return ['nothing', 'Nothing was owed, so nothing was charged.', null];
        }

        $amount = Money::ofMinor(min($owed->getMinorAmount()->toInt(), $arrangement->limit_minor), 'JMD');

        if ($dryRun) {
            return ['paid', 'Would pay '.MoneyFormatter::fromMinor($amount->getMinorAmount()->toInt()).' with '.HouseholdCards::label($card).'.', null];
        }

        try {
            $payer = (string) (ResidentAccount::query()->whereKey($arrangement->resident_account_id)->value('full_name') ?? 'AutoPay');
            $payment = $this->payments->payDues($unit, $amount, $card->brand, $card->last4, $payer.' (AutoPay)');
        } catch (DemoCardDeclined $declined) {
            return ['refused', 'Not charged: '.$declined->getMessage(), null];
        } catch (Throwable $refused) {
            return ['refused', 'Not charged: '.$refused->getMessage(), null];
        }

        $paid = MoneyFormatter::fromMinor((int) $payment->amount_minor);
        $capped = $owed->getMinorAmount()->toInt() > $arrangement->limit_minor ? ' (the limit; the rest is still owed)' : '';

        return ['paid', "Paid {$paid}{$capped} with ".HouseholdCards::label($card).' — receipt '.$payment->receipt_no.'.', (int) $payment->id];
    }

    /** What the app draws for the household's AutoPay. */
    public static function shape(?AutopayArrangement $arrangement, Carbon $today): array
    {
        $available = DemoCardPayments::enabled();

        return [
            'available' => $available,
            'reason' => $available ? null : 'AutoPay needs card payments, which are not switched on for this estate.',
            'on' => $arrangement !== null && $arrangement->status === AutopayArrangement::ON,
            'id' => $arrangement === null ? null : (int) $arrangement->id,
            'limit' => $arrangement === null ? null : Decimal::of($arrangement->limit_minor),
            'day' => $arrangement?->day_of_month,
            'card' => $arrangement?->card === null ? null : HouseholdCards::shape($arrangement->card, $today),
            'next_charge_on' => $arrangement === null || $arrangement->status !== AutopayArrangement::ON ? null : self::nextChargeOn($arrangement, $today)->toDateString(),
            'last_run_on' => $arrangement?->last_run_on?->toDateString(),
            'last_outcome' => $arrangement?->last_outcome,
            'defaults' => ['limit' => Decimal::of(self::DEFAULT_LIMIT_MINOR), 'day' => self::DEFAULT_DAY],
        ];
    }
}
