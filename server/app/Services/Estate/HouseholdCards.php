<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\AutopayArrangement;
use App\Models\Estate\HouseholdCard;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Services\Audit\AuditLogger;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "SAVE CARD" (board resident-app-35) AND "SAVED PAYMENT METHODS" (board resident-app-40) — M50 §4.2 #3.
 *
 * TEST CARDS ONLY, AND NEVER THE NUMBER. The checkout takes Stripe's test cards and nothing else (D-179; the phone
 * wipes a real number the moment it recognises one), so a saved card is the four things that NAME a test card — the
 * brand, the last four, the expiry, the name on it — and every row says it is a demonstration. Saving one needs the
 * demonstration checkout switched on (`DemoCardPayments::enabled()`): with it off there is no card to save.
 *
 * A RESIDENT'S OWN. A card belongs to the person who saved it, inside their household: another member does not see
 * it, cannot pay with it and cannot remove it. One of a person's cards is their default — the first one saved, or the
 * one they chose — and Pay and AutoPay start from it.
 */
final class HouseholdCards
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return Collection<int, HouseholdCard> this person's cards in this home, the default first */
    public function of(Unit $unit, ResidentAccount $account): Collection
    {
        return HouseholdCard::query()->where('unit_id', $unit->id)->where('resident_account_id', $account->id)
            ->orderByDesc('is_default')->orderByDesc('id')->get();
    }

    public function find(Unit $unit, ResidentAccount $account, int $cardId): ?HouseholdCard
    {
        return HouseholdCard::query()->whereKey($cardId)->where('unit_id', $unit->id)->where('resident_account_id', $account->id)->first();
    }

    /**
     * @throws DomainException when card payments are off, it is not a test card, or it has expired
     */
    public function save(Unit $unit, ResidentAccount $account, string $brand, string $last4, int $expMonth, int $expYear, ?string $holder, bool $default): HouseholdCard
    {
        if (! DemoCardPayments::enabled()) {
            throw new DomainException('Card payments are not switched on for this estate, so there is no card to save. See How to pay for the estate\'s bank details.');
        }

        $brand = strtolower(trim($brand));
        $last4 = preg_replace('/\D/', '', $last4) ?? '';

        if (! array_key_exists($brand.':'.$last4, DemoCardPayments::TEST_CARDS)) {
            throw new DomainException('That is not one of Stripe\'s test cards. Only test cards can be saved, like 4242 4242 4242 4242 — never a real card.');
        }

        $expYear = $expYear < 100 ? 2000 + $expYear : $expYear;

        if (self::expired($expMonth, $expYear, SiteTime::today((string) tenant()?->getTenantKey()))) {
            throw new DomainException('That card has expired. Check the expiry date.');
        }

        $holder = $holder === null ? null : (mb_substr(trim((string) preg_replace('/\s+/u', ' ', $holder)), 0, 80) ?: null);

        return DB::connection('tenant')->transaction(function () use ($unit, $account, $brand, $last4, $expMonth, $expYear, $holder, $default): HouseholdCard {
            $mine = $this->of($unit, $account);
            $same = $mine->first(static fn (HouseholdCard $c): bool => $c->brand === $brand && $c->last4 === $last4 && $c->exp_month === $expMonth && $c->exp_year === $expYear);
            $default = $default || $mine->isEmpty() || ($same?->is_default ?? false);

            if ($default) {
                HouseholdCard::query()->where('unit_id', $unit->id)->where('resident_account_id', $account->id)->update(['is_default' => false]);
            }

            $card = $same ?? new HouseholdCard(['unit_id' => $unit->id, 'resident_account_id' => $account->id, 'brand' => $brand, 'last4' => $last4, 'exp_month' => $expMonth, 'exp_year' => $expYear, 'is_demonstration' => true]);
            $card->forceFill(['holder' => $holder ?? $card->holder, 'is_default' => $default])->save();

            $this->audit->record(
                action: $same === null ? 'payment_card.saved' : 'payment_card.saved_again',
                entityType: 'HouseholdCard',
                entityId: (string) $card->id,
                after: ['unit' => $unit->reference, 'card' => self::label($card), 'default' => $default, 'account_id' => (int) $account->id],
            );

            return $card;
        });
    }

    public function makeDefault(Unit $unit, ResidentAccount $account, HouseholdCard $card): HouseholdCard
    {
        DB::connection('tenant')->transaction(static function () use ($unit, $account, $card): void {
            HouseholdCard::query()->where('unit_id', $unit->id)->where('resident_account_id', $account->id)->update(['is_default' => false]);
            $card->forceFill(['is_default' => true])->save();
        });

        return $card->refresh();
    }

    /**
     * Removed. The person's next card becomes their default; an AutoPay that used it stops, and says why — a standing
     * payment with no card is not one, and it must never seem to be.
     */
    public function remove(Unit $unit, ResidentAccount $account, HouseholdCard $card): void
    {
        DB::connection('tenant')->transaction(function () use ($unit, $account, $card): void {
            AutopayArrangement::query()->where('household_card_id', $card->id)->where('status', AutopayArrangement::ON)->update([
                'status' => AutopayArrangement::OFF,
                'turned_off_at' => Carbon::now(),
                'last_outcome' => 'Turned off: the card it used was removed.',
            ]);

            $wasDefault = $card->is_default;
            $label = self::label($card);
            $card->delete();

            if ($wasDefault) {
                $this->of($unit, $account)->first()?->forceFill(['is_default' => true])->save();
            }

            $this->audit->record(action: 'payment_card.removed', entityType: 'HouseholdCard', entityId: (string) $card->id, before: ['card' => $label, 'unit' => $unit->reference]);
        });
    }

    /** "Visa •••• 4242" — all that is ever kept of it. */
    public static function label(HouseholdCard $card): string
    {
        return (DemoCardPayments::BRAND_NAMES[$card->brand] ?? 'Card').' •••• '.$card->last4;
    }

    /** Past its month, on the estate's calendar. */
    public static function expired(int $month, int $year, Carbon $today): bool
    {
        return $year < (int) $today->format('Y') || ($year === (int) $today->format('Y') && $month < (int) $today->format('n'));
    }

    /** @return array{id: int, brand: string, brand_name: string, last4: string, label: string, expires: string, holder: string|null, default: bool, expired: bool, demonstration: bool} */
    public static function shape(HouseholdCard $card, Carbon $today): array
    {
        return [
            'id' => (int) $card->id,
            'brand' => $card->brand,
            'brand_name' => DemoCardPayments::BRAND_NAMES[$card->brand] ?? 'Card',
            'last4' => $card->last4,
            'label' => self::label($card),
            'expires' => sprintf('%02d/%02d', $card->exp_month, $card->exp_year % 100),
            'holder' => $card->holder,
            'default' => (bool) $card->is_default,
            'expired' => self::expired($card->exp_month, $card->exp_year, $today),
            'demonstration' => (bool) $card->is_demonstration,
        ];
    }
}
