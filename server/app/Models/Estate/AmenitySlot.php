<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\SiteDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A period carved out of an amenity's diary — the holds and blackouts board 19's
 * slot view draws, and what "block a slot" writes.
 *
 * A SLOT ROW IS AN EXCLUSION, NEVER A MATERIALISED AVAILABILITY. The bookable
 * periods are the amenity's opening hours less what is already taken; a table
 * with a row per hour per amenity per day would grow without bound and still
 * could not answer a question about next year, which is the one a resident asks.
 * So availability is derived and only the exceptions are stored.
 *
 * TWO KINDS, AND THE DIFFERENCE IS WHAT A RESIDENT IS TOLD. A `blocked` period
 * is the estate's own decision about its diary — resurfacing, a private
 * function, a closure — and a `hold` is a request in flight. Both refuse an
 * overlapping booking; only one of them means the amenity is unavailable, and
 * telling somebody the Gazebo is closed when it is merely spoken for is a
 * different sentence with a different answer.
 *
 * @property int $id
 * @property int $amenity_id
 * @property string $kind
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $reason
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Amenity $amenity
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AmenitySlot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AmenitySlot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AmenitySlot query()
 *
 * @mixin \Eloquent
 */
class AmenitySlot extends Model
{
    public const BLOCKED = 'blocked';

    public const HOLD = 'hold';

    protected $fillable = [
        'amenity_id',
        'kind',
        'starts_at',
        'ends_at',
        'reason',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        // ONE TIME RULE (M4 §3.1): stored true UTC, read on the estate's clock — see `SiteDateTime`.
        return [
            'starts_at' => SiteDateTime::class,
            'ends_at' => SiteDateTime::class,
        ];
    }

    /**
     * Whether this period overlaps another.
     *
     * Half-open on the right: a slot ending at 1:00 PM and a booking starting at
     * 1:00 PM do not overlap. Treating the boundary as a clash would make
     * back-to-back bookings impossible, which is how a Club House is actually
     * used on a Saturday.
     */
    public function overlaps(Carbon $startsAt, Carbon $endsAt): bool
    {
        return $this->starts_at->lessThan($endsAt) && $this->ends_at->greaterThan($startsAt);
    }

    /** What the diary prints on the block — the reason, or what kind it is. */
    public function label(): string
    {
        return trim((string) $this->reason) !== ''
            ? (string) $this->reason
            : ($this->kind === self::HOLD ? 'Held pending approval' : 'Blocked');
    }

    /** @return BelongsTo<Amenity, $this> */
    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class);
    }
}
