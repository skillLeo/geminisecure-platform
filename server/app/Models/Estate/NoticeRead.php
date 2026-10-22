<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * That one resident has read one notice.
 *
 * ONE ROW EACH, ENFORCED BY A UNIQUE KEY, and that is what makes board 32's
 * seen-bar a fact rather than a tally. A resident who opens the same notice four
 * times is one person who has read it; a counter incremented per view would show
 * a notice 130% seen, which a committee would notice and then stop trusting
 * every other figure on the screen.
 *
 * IT RECORDS THAT, NOT WHEN THEY LOOKED AT IT AGAIN. `read_at` is the first
 * time, kept because "posted Tuesday, half the estate had seen it by Wednesday"
 * is the question a secretary actually asks. Re-reading does not move it.
 *
 * @property int $id
 * @property int $notice_id
 * @property int $resident_id
 * @property Carbon $read_at
 * @property-read Notice $notice
 * @property-read Resident $resident
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 *
 * @mixin \Eloquent
 */
class NoticeRead extends Model
{
    protected $table = 'notice_reads';

    public $timestamps = false;

    protected $fillable = ['notice_id', 'resident_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** @return BelongsTo<Notice, $this> */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    /** @return BelongsTo<Resident, $this> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }
}
