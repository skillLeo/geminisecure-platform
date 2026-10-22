<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * WHAT HAPPENED TO ONE PHONE, IN ONE ROUND OF ONE ALERT'S RING.
 *
 * APPEND-ONLY, by a withheld grant and two triggers. Written only with `insertOrIgnore`
 * on its unique key, so a retried send, a second sweep or a replayed receipt adds
 * nothing. Never read with a row lock.
 *
 * WHAT THE EVENTS MEAN — and what they do not:
 *
 *   sent_ring / sent_visible / sent_stop / sent_visible_stop / sent_test
 *        Expo ACCEPTED the message (a ticket `ok`). Not that the phone got it: a receipt
 *        "ok" means FCM or APNs took it, and nothing downstream reports arrival.
 *   shown        THE PHONE ITSELF reported it put the ring on screen. `detail` is its
 *                live sound check at that moment (`imp=4;snd=1;vol=5/7;dnd=pass;…`).
 *   full_screen  the system launched the full-screen ring. A tap is `opened`, never this.
 *   opened / held / dismissed / stopped / suppressed / fallback
 *                the phone's other receipts. `dismissed` neither declines nor answers.
 *   answered_responding / answered_cannot
 *                the guard's answer, as the server recorded it.
 *   refused      the server would not send to, or accept from, this phone — `detail`
 *                says why: `entitlement` (moved estate, signed out), `stale`, …
 *   failed       Expo refused the message for this phone; `detail` is Expo's code.
 *   test_requested
 *                the guard asked for a test ring; it claims the test's own number (G24).
 *
 * "RANG" IS NOT AN EVENT, and no screen built on this table says it (G11).
 *
 * @property int $id
 * @property int $duress_alert_id 0 for a test ring
 * @property string $tenant_id
 * @property int $round
 * @property int $test_seq
 * @property int|null $guard_id
 * @property int $push_token_id
 * @property string $event
 * @property string|null $detail
 * @property Carbon|null $created_at
 *
 * @mixin \Eloquent
 */
class DuressAlertRingEvent extends Model
{
    use CentralConnection;

    public const SENT_RING = 'sent_ring';

    public const SENT_VISIBLE = 'sent_visible';

    public const SENT_STOP = 'sent_stop';

    /**
     * The VISIBLE stop, which replaces a visible re-ring by its tag. An event of its own, and
     * not a second `sent_stop`: a phone sent a visible backup beside its call-style ring needs
     * both stops, and one event name for both would let the unique key swallow the second.
     */
    public const SENT_VISIBLE_STOP = 'sent_visible_stop';

    public const SENT_TEST = 'sent_test';

    /** The guard asked for a test ring from the Call-style screen; this claims the test's number. */
    public const TEST_REQUESTED = 'test_requested';

    public const SHOWN = 'shown';

    public const FULL_SCREEN = 'full_screen';

    public const OPENED = 'opened';

    public const HELD = 'held';

    public const DISMISSED = 'dismissed';

    public const STOPPED = 'stopped';

    public const SUPPRESSED = 'suppressed';

    public const FALLBACK = 'fallback';

    public const ANSWERED_RESPONDING = 'answered_responding';

    public const ANSWERED_CANNOT = 'answered_cannot';

    public const REFUSED = 'refused';

    public const FAILED = 'failed';

    /** @var list<string> what a phone may report through `ring.receipt` */
    public const RECEIPTS = [self::SHOWN, self::FULL_SCREEN, self::OPENED, self::HELD, self::DISMISSED, self::STOPPED, self::SUPPRESSED, self::FALLBACK];

    public const DETAIL_LENGTH = 60;

    /** Append-only: `created_at` is written with the row and there is no `updated_at`. */
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'test_seq' => 'integer',
            'guard_id' => 'integer',
            'push_token_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Write one event, once. Answers whether this call wrote it — false when the same
     * event for the same phone, round and test was already recorded.
     *
     * @param  array{duress_alert_id: int, tenant_id: string, round: int, push_token_id: int, event: string, test_seq?: int, guard_id?: int|null, detail?: string|null}  $row
     */
    public static function once(array $row): bool
    {
        $detail = $row['detail'] ?? null;

        return self::query()->insertOrIgnore([
            'duress_alert_id' => $row['duress_alert_id'],
            'tenant_id' => $row['tenant_id'],
            'round' => $row['round'],
            'test_seq' => $row['test_seq'] ?? 0,
            'guard_id' => $row['guard_id'] ?? null,
            'push_token_id' => $row['push_token_id'],
            'event' => $row['event'],
            'detail' => $detail === null ? null : mb_substr($detail, 0, self::DETAIL_LENGTH),
            'created_at' => Carbon::now(),
        ]) === 1;
    }
}
