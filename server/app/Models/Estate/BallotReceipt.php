<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * THAT a household voted. Never what it chose.
 *
 * Half of platform invariant 3, and the half that is allowed to name a
 * household. Turnout and quorum are proved by counting these rows — board 11's
 * "318 of 450 households (71%)" is `COUNT(*)` over one ballot and nothing else —
 * and a second vote is refused by the unique index on (ballot_id, unit_id),
 * which is at the database because two browser tabs open at once is an ordinary
 * thing for a person to do and a service-layer check would lose that race.
 *
 * THE OTHER HALF IS `ballot_marks`, AND THE TWO SHARE NO COLUMN NAME. Not one.
 * There is deliberately no relation from this model to the marks, no `choice`
 * accessor, and no `BallotMark` model to relate to — the marks are reached only
 * through the query builder inside `App\Services\Estate\Governance`, which
 * counts them and never returns one.
 *
 * `voted_on` IS A DATE AND THERE ARE NO TIMESTAMPS. A clock is an ordinal with
 * extra steps: `created_at` here and `created_at` there would pair 318
 * households with 318 choices in a single query, because both rows are written
 * in one transaction and share a microsecond. The marks carry no temporal column
 * at all, and this side carries a date so that if some future migration puts one
 * there after all, there is still nothing over here fine-grained enough to pair
 * with it.
 *
 * @property int $id
 * @property int $ballot_id
 * @property int $unit_id
 * @property Carbon $voted_on
 * @property-read Ballot $ballot
 * @property-read Unit $unit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BallotReceipt newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BallotReceipt newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BallotReceipt query()
 *
 * @mixin \Eloquent
 */
class BallotReceipt extends Model
{
    /**
     * The table is append-only at the database, so Eloquent must never issue an
     * UPDATE — and with no `created_at`/`updated_at` columns to write, it would
     * fail on the insert before it ever got that far.
     */
    public $timestamps = false;

    protected $fillable = [
        'ballot_id',
        'unit_id',
        'voted_on',
    ];

    protected function casts(): array
    {
        return [
            'voted_on' => 'date',
        ];
    }

    /** @return BelongsTo<Ballot, $this> */
    public function ballot(): BelongsTo
    {
        return $this->belongsTo(Ballot::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
