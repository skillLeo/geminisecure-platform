<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\SiteDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The minutes of a meeting that has been held — board 36's "View minutes".
 *
 * THEIR EXISTENCE IS WHAT SPLITS BOARD 36'S ROW ACTION. A meeting with an agenda
 * and no minutes is one nobody has held yet, and the row offers "View agenda"; a
 * meeting with minutes offers "View minutes". That is a fact about the record
 * rather than a status somebody remembers to set, which is why it is a row in a
 * table and not a column on the meeting.
 *
 * `adopted_at` IS NOT A FORMALITY. Minutes are one person's account until a
 * subsequent meeting adopts them, and an adopted set is the estate's record —
 * the document a resolution is proved from and a dispute is settled against. The
 * timestamp is the only thing that tells the two apart, and a set of minutes
 * with no adoption on it is a draft however long it has been sitting there.
 *
 * @property int $id
 * @property int $meeting_id
 * @property string $body
 * @property string|null $recorded_by_name
 * @property Carbon|null $adopted_at
 * @property int|null $adopted_by
 * @property string|null $adopted_by_name
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Meeting $meeting
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MeetingMinutes newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MeetingMinutes newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MeetingMinutes query()
 *
 * @mixin \Eloquent
 */
class MeetingMinutes extends Model
{
    protected $table = 'meeting_minutes';

    protected $fillable = [
        'meeting_id',
        'body',
        'recorded_by_name',
        'adopted_at',
        'adopted_by',
        'adopted_by_name',
        'published_at',
    ];

    protected function casts(): array
    {
        // Read on the estate's clock (M4 §3.1, M11 §4.2): every reader prints the DAY minutes were adopted,
        // and on the UTC clock an adoption on a Kingston evening was dated the next morning.
        return [
            'adopted_at' => SiteDateTime::class,
            'published_at' => SiteDateTime::class,
        ];
    }

    public function isAdopted(): bool
    {
        return $this->adopted_at !== null;
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
