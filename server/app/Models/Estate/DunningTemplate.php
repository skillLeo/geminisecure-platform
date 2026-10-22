<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The wording one step of the arrears ladder is sent in — board 8's right pane.
 *
 * EDITABLE, AND THAT IS THE WHOLE REASON `dunning_notices` COPIES IT. A template
 * is what will be sent next time; a notice is what was sent. Editing this row
 * must never change a word of what a resident already received, so `Collections`
 * renders the merge fields at send time and writes the result onto the notice.
 * Nothing re-renders a notice from here, ever.
 *
 * STAGE IS NOT DECORATION. A first reminder and a final demand differ in law as
 * well as in tone, and the stage is what an arrears process is audited against —
 * so it is a column rather than something inferred from the label.
 *
 * @property int $id
 * @property string $key
 * @property string $label
 * @property int $stage
 * @property string $channel
 * @property string $subject
 * @property string $body
 * @property int $days_overdue
 * @property bool $is_active
 * @property-read Collection<int, DunningNotice> $notices
 * @property-read int|null $notices_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DunningTemplate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DunningTemplate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DunningTemplate query()
 *
 * @mixin \Eloquent
 */
class DunningTemplate extends Model
{
    /**
     * The merge fields a template may use — board 8's five chips, verbatim.
     *
     * A CLOSED CATALOGUE, and it is closed because every one of these has to be
     * resolvable against a unit at the instant of sending. A token nobody can
     * resolve is a sentence with a hole in it arriving on a resident's phone,
     * and the resident is the last person who should discover it.
     *
     * @var list<string>
     */
    public const MERGE_FIELDS = [
        '{resident_first_name}',
        '{unit_label}',
        '{amount_due}',
        '{due_date}',
        '{days_overdue}',
    ];

    /**
     * How a step goes out, stored as a compact key.
     *
     * Board 8 renders these as "Push + Email" and "Push + Email + SMS". The
     * rendered form is stored nowhere: the column is 16 characters and the
     * longest label is 18, and more to the point a display string in a data
     * column is a label that cannot be changed without a migration.
     *
     * @var array<string, string>
     */
    public const CHANNEL_LABELS = [
        'email' => 'Email',
        'sms' => 'SMS',
        'push' => 'Push',
        'letter' => 'Letter',
        'push+email' => 'Push + Email',
        'push+email+sms' => 'Push + Email + SMS',
    ];

    protected $fillable = [
        'key',
        'label',
        'stage',
        'channel',
        'subject',
        'body',
        'days_overdue',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'days_overdue' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * What board 8 prints in its Channel column.
     *
     * Falls back to the stored key rather than to an empty cell: a channel
     * nobody has given a label is still a fact about how a notice went out, and
     * a blank cell in a delivery log is worse than an ugly one.
     */
    public static function channelLabel(string $channel): string
    {
        return self::CHANNEL_LABELS[$channel] ?? $channel;
    }

    /** @return HasMany<DunningNotice, $this> */
    public function notices(): HasMany
    {
        return $this->hasMany(DunningNotice::class);
    }
}
