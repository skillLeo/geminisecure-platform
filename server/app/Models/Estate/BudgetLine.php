<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One month's budget for one income or expense account (M84 §3.3). A plan: changed and removed in place, never posted.
 *
 * @property int $id
 * @property int $account_id
 * @property string $period
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $set_by
 * @property string|null $set_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 */
class BudgetLine extends Model
{
    protected $table = 'budget_lines';

    protected $fillable = [
        'account_id',
        'period',
        'amount_minor',
        'currency',
        'set_by',
        'set_by_name',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
