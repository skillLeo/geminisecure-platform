<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A household's AutoPay (boards resident-app-10, -40; M50 §4.2 #3): pay what is owed, up to a limit, on a day of the
 * month, with a saved card. See the `a_household_keeps_test_cards_and_pays_by_autopay` migration and `Autopay`.
 *
 * @property int $id
 * @property int $unit_id
 * @property int $resident_account_id who turned it on
 * @property int|null $household_card_id
 * @property int $limit_minor
 * @property int $day_of_month 1–28
 * @property string $status on · off
 * @property Carbon|null $turned_on_at
 * @property Carbon|null $turned_off_at
 * @property Carbon|null $last_run_on
 * @property string|null $last_outcome what its last run did, in a sentence
 * @property int|null $last_payment_id
 * @property-read HouseholdCard|null $card
 */
class AutopayArrangement extends Model
{
    public const ON = 'on';

    public const OFF = 'off';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'limit_minor' => 'integer',
            'day_of_month' => 'integer',
            'turned_on_at' => 'datetime',
            'turned_off_at' => 'datetime',
            'last_run_on' => 'date',
            'last_payment_id' => 'integer',
        ];
    }

    /** @return BelongsTo<HouseholdCard, $this> */
    public function card(): BelongsTo
    {
        return $this->belongsTo(HouseholdCard::class, 'household_card_id');
    }
}
