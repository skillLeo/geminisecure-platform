<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of an agenda — board 12's builder, board 36's "View agenda".
 *
 * A START TIME AND NO DURATION. Board 12 draws 10:00, 10:15, 10:45 and 11:15,
 * and the gaps between them are the durations. Storing both would be two numbers
 * that disagree the first time somebody drags an item up the list, and the one
 * that would be wrong is the one printed on the notice.
 *
 * `fiscal_year` AND `motion_reference` ARE LABELS, NOT KEYS. Board 12's own
 * accounting note ties two of its four items to real records — "Treasurer's
 * report — FY2025/26" and "Motion: approve 2026/27 budget" — and neither the
 * fiscal calendar nor the budget register is built. A foreign key to a table
 * that does not exist is a migration that cannot run, so the trace is kept as
 * text until there is something to point it at.
 *
 * @property int $id
 * @property int $meeting_id
 * @property string|null $start_time
 * @property string $text
 * @property int $sort_order
 * @property string|null $fiscal_year
 * @property string|null $motion_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Meeting $meeting
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MeetingAgendaItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MeetingAgendaItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MeetingAgendaItem query()
 *
 * @mixin \Eloquent
 */
class MeetingAgendaItem extends Model
{
    protected $fillable = [
        'meeting_id',
        'start_time',
        'text',
        'sort_order',
        'fiscal_year',
        'motion_reference',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * The time board 12 prints beside the line: "10:00", 24-hour.
     *
     * Deliberately not the 12-hour form the meeting's own Time field uses. An
     * agenda is a running order read at a glance down a column, and "10:45 AM"
     * beside "11:15 AM" is two words of noise per row.
     *
     * MySQL hands a TIME column back as a string, which is why this trims rather
     * than formats — a Carbon cast on a TIME column parses it as today's date at
     * that hour, and an agenda item is not an appointment on any particular day.
     */
    public function timeLabel(): ?string
    {
        $raw = $this->getRawOriginal('start_time');

        return $raw === null ? null : mb_substr((string) $raw, 0, 5);
    }

    /** @return BelongsTo<Meeting, $this> */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
