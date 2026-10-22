<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * ONE ROUND OF A RING: when it falls due, who claimed it, and what it reached.
 *
 * All of an alert's rounds are written at the raise, each with its `due_at`. A round is
 * sent by whoever CLAIMS it first — its queued job, or the safety-net sweep — in one
 * statement, so it is never sent twice by two callers. Central, and the only way the
 * table is touched (G5).
 *
 * @property int $id
 * @property int $duress_alert_id
 * @property string $tenant_id
 * @property int $round
 * @property string $reason initial, nobody_on_duty, no_handset, unanswered, declined or re_ring
 * @property Carbon $due_at
 * @property Carbon|null $claimed_at
 * @property string|null $claimed_by job or sweep
 * @property Carbon|null $finished_at
 * @property int|null $officers
 * @property int|null $phones
 * @property int|null $phones_ring
 * @property int|null $phones_visible
 * @property int|null $phones_ready
 * @property int|null $phones_partial
 * @property int|null $heads_texted
 * @property string|null $outcome
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin \Eloquent
 */
class DuressAlertRingRound extends Model
{
    use CentralConnection;

    /** Round 0: the raise. Today's visible push has already gone; this adds the call-style ring. */
    public const INITIAL = 'initial';

    /** Round 1 at once: nobody was on duty or rostered. */
    public const NOBODY_ON_DUTY = 'nobody_on_duty';

    /** Round 1 at once: officers were on the list, but no handset of theirs could be pushed. */
    public const NO_HANDSET = 'no_handset';

    /** Round 1 at 60 s: nobody answered. */
    public const UNANSWERED = 'unanswered';

    /** Round 1 pulled forward: every officer rung at the raise said "can't respond". */
    public const DECLINED = 'declined';

    /** Rounds 2 to 5. */
    public const RE_RING = 're_ring';

    public const BY_JOB = 'job';

    public const BY_SWEEP = 'sweep';

    /* ---- outcomes ---- */

    public const SENT = 'sent';

    public const PARTLY_SENT = 'partly_sent';

    public const PARTLY_FAILED = 'partly_failed';

    public const NONE = 'none';

    public const FAILED = 'failed';

    public const SKIPPED_ANSWERED = 'skipped_answered';

    public const SKIPPED_LATE = 'skipped_late';

    public const SKIPPED_DRILL = 'skipped_drill';

    public const SKIPPED_DEMO = 'skipped_demo';

    /** The ring was switched off (`ring.enabled`) between the raise and the round. */
    public const SKIPPED_OFF = 'skipped_off';

    /** @var list<string> the outcomes that mean the round never sent anything, on purpose */
    public const SKIPPED = [self::SKIPPED_ANSWERED, self::SKIPPED_LATE, self::SKIPPED_DRILL, self::SKIPPED_DEMO, self::SKIPPED_OFF];

    public const NOTE_LENGTH = 500;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'due_at' => 'datetime',
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
            'officers' => 'integer',
            'phones' => 'integer',
            'phones_ring' => 'integer',
            'phones_visible' => 'integer',
            'phones_ready' => 'integer',
            'phones_partial' => 'integer',
            'heads_texted' => 'integer',
        ];
    }

    /** Whether this round has been decided either way — sent, or skipped on purpose. */
    public function isSettled(): bool
    {
        return $this->finished_at !== null;
    }
}
