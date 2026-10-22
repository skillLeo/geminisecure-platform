<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One arrears reminder, AS IT WAS SENT — board 8's left pane.
 *
 * "Every dunning notice is logged with its exact sent content, so a dispute can
 * be settled from the record." That sentence is this table's entire reason for
 * existing, and it is why `subject` and `body` are stored here in full rather
 * than looked up through `dunning_template_id`. The template can be reworded or
 * archived tomorrow; a log that re-rendered from today's wording would show a
 * resident a message they were never sent, which is the one failure it exists
 * to prevent.
 *
 * `template_label`, `stage` AND `channel` ARE COPIED FOR THE SAME REASON, not
 * for convenience. Asked six months later which step went out on the 20th, the
 * answer has to come from this row alone.
 *
 * NO BALANCE IS STORED HERE. Board 8 draws a Balance column and it is read from
 * the ledger when the screen is drawn — a figure kept beside the accounts is
 * free to disagree with them, and the amount a resident was actually quoted is
 * already preserved, rendered into `body`, which is the only place a dispute
 * would look.
 *
 * @property int $id
 * @property int $unit_id
 * @property int|null $dunning_template_id
 * @property string $template_label
 * @property int $stage
 * @property string $channel
 * @property string $subject
 * @property string $body
 * @property string $delivery_state
 * @property string|null $delivery_detail
 * @property Carbon $sent_at
 * @property int|null $sent_by
 * @property string|null $sent_by_name
 * @property-read Unit $unit
 * @property-read DunningTemplate|null $template
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DunningNotice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DunningNotice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DunningNotice query()
 *
 * @mixin \Eloquent
 */
class DunningNotice extends Model
{
    /** Written to the log, not yet handed to a channel. */
    public const QUEUED = 'queued';

    /**
     * Taken off the queue by the delivery run (M84 §3.5) and being handed to its channels. Claimed in one guarded
     * update, so two runs never send one reminder twice; a row left here by a run that died stays here, seen, and is
     * never retried on its own.
     */
    public const SENDING = 'sending';

    /** Handed to a channel, which has not yet said what became of it. */
    public const SENT = 'sent';

    /** The channel confirmed it arrived. */
    public const DELIVERED = 'delivered';

    /** The channel refused it — board 8's red "SMS failed". */
    public const FAILED = 'failed';

    /** It arrived nowhere and came back. */
    public const BOUNCED = 'bounced';

    /**
     * Queued before delivery started, or left too long to be right, and NEVER SENT (M84 §3.5 rule 2): the queue that
     * stood from 29 September is not flushed in one burst of backdated demands when delivery is turned on.
     */
    public const EXPIRED = 'expired';

    protected $fillable = [
        'unit_id',
        'dunning_template_id',
        'template_label',
        'stage',
        'channel',
        'subject',
        'body',
        'delivery_state',
        'delivery_detail',
        'sent_at',
        'sent_by',
        'sent_by_name',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'stage' => 'integer',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The template this was rendered from, where it still exists.
     *
     * Nullable, and nothing reads the body through it. It is a trail back to
     * the wording that was in force, not the source of what was sent.
     *
     * @return BelongsTo<DunningTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DunningTemplate::class, 'dunning_template_id');
    }

    /**
     * What board 8's Status column reads.
     *
     * A FAILURE SHOWS ITS OWN DETAIL — "SMS failed", not "Failed" — because a
     * multi-channel step can fail on one leg and land on the others, and a
     * treasurer chasing a household needs to know the resident got the email.
     */
    public function statusLabel(): string
    {
        return match ($this->delivery_state) {
            // A step can land on one channel and not another: the detail says which ("Delivered by email; push not sent").
            self::DELIVERED => $this->delivery_detail ?? 'Delivered',
            self::SENT => 'Sent',
            self::SENDING => 'Sending',
            self::QUEUED => 'Queued',
            self::EXPIRED => 'Expired, never sent',
            self::BOUNCED => $this->delivery_detail ?? 'Bounced',
            self::FAILED => $this->delivery_detail ?? 'Failed',
            default => $this->delivery_state,
        };
    }

    /** Which of board 8's two pill styles the row wears. An expired reminder reached nobody, so it wears the failure's. */
    public function statusTone(): string
    {
        return in_array($this->delivery_state, [self::FAILED, self::BOUNCED, self::EXPIRED], true) ? 'failed' : 'sent';
    }

    /** The Channel column, e.g. "Push + Email + SMS". */
    public function channelLabel(): string
    {
        return DunningTemplate::channelLabel($this->channel);
    }
}
