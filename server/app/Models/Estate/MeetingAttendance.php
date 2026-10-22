<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who was in the room — the register board 36's quorum badge is counted from.
 *
 * QUORUM IS COUNTED FROM THESE ROWS AND IS NEVER STORED ANYWHERE. "Quorum met ·
 * 6/7" and "Quorum met · 41%" are both `COUNT(*)` over this table against the
 * meeting's denominator. Nothing writes a `quorum_met` flag, because a flag that
 * disagreed with the register would be the estate's minutes arguing with the
 * estate's own attendance sheet, and the register is the one a challenge would
 * be settled from.
 *
 * A HOUSEHOLD IS WHAT COUNTS, NOT A PERSON. The Build Spec, board 36: "Quorum
 * counts households, not individuals." Three people from one lot are one row and
 * one household, which is why `unit_id` is unique per meeting. A committee
 * meeting is the exception and has no units at all — the seven people in the
 * room are members, and that is exactly why board 36 needs two quorum measures.
 *
 * `represented_by` IS A PROXY, and it changes who stood there without changing
 * who is counted. The household is still the household; the minutes just have to
 * be able to say who actually attended on its behalf.
 *
 * THREE STATES, NOT A BOOLEAN. Apologies are not attendance and are not absence
 * either — a general meeting's minutes record them, and a register holding only
 * the people in the room could not.
 *
 * @property int $id
 * @property int $meeting_id
 * @property int|null $unit_id
 * @property string|null $attendee_name
 * @property string|null $represented_by
 * @property string $state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Meeting $meeting
 * @property-read Unit|null $unit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MeetingAttendance newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MeetingAttendance newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MeetingAttendance query()
 *
 * @mixin \Eloquent
 */
class MeetingAttendance extends Model
{
    public const PRESENT = 'present';

    public const APOLOGIES = 'apologies';

    public const ABSENT = 'absent';

    protected $table = 'meeting_attendance';

    protected $fillable = [
        'meeting_id',
        'unit_id',
        'attendee_name',
        'represented_by',
        'state',
    ];

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
