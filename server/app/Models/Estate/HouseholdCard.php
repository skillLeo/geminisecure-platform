<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A card a resident saved (board resident-app-35, "Save card"; M50 §4.2 #3) — one of Stripe's TEST cards, and only
 * what identifies it: the brand, the last four digits, the expiry and the name. Never the number. See the
 * `a_household_keeps_test_cards_and_pays_by_autopay` migration and `HouseholdCards`.
 *
 * @property int $id
 * @property int $unit_id
 * @property int $resident_account_id who saved it
 * @property string $brand visa · mastercard · amex · discover
 * @property string $last4
 * @property int $exp_month
 * @property int $exp_year four digits
 * @property string|null $holder
 * @property bool $is_default
 * @property bool $is_demonstration always true while the checkout takes test cards only (D-179)
 * @property Carbon $created_at
 */
class HouseholdCard extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['exp_month' => 'integer', 'exp_year' => 'integer', 'is_default' => 'boolean', 'is_demonstration' => 'boolean'];
    }
}
