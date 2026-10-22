<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * ONE LEG OF A PANIC: WHO, BY WHAT, WITH WHAT OUTCOME, AND HOW FAST IT WAS HANDED OFF.
 *
 * M10 §1.3: "Measure and record delivery time to each destination." One row per §1.2
 * destination per channel, written by `Services\Push\PanicDeliveries` in a single
 * insert once every leg of the fan-out has gone.
 *
 * HANDED OFF, NEVER DELIVERED — and every word on every screen built on this says so.
 * `elapsed_ms` runs, on `hrtime`, from the moment the platform began telling anyone about
 * the alert — just before the live broadcast, which goes first (M10 §1.2) — to the moment
 * it let go of that leg: the broadcast accepted by the socket server, the push job written
 * to the queue, the mail notification queued, the text job queued. The reach broadcast
 * that follows the fan-out (`AlertReachKnown`) tells nobody new and is never a row. What happens after that —
 * Expo, the carrier, the mail relay, whether a browser was subscribed, whether a phone was
 * in a pocket — is downstream, reports nothing back synchronously, and is not in this
 * table. "Handed off in 212 ms" is true; "delivered in 212 ms" would not be.
 *
 * THE OUTCOME IS ONE OF FIVE PLAIN WORDS, AND THE NOTE SAYS WHY.
 *
 *   queued    written to the panic queue; a worker hands it to the provider next
 *   sent      handed to the provider inside the fan-out itself (a synchronous queue, or
 *             the socket server for a broadcast) — accepted, which is still not arrival
 *   none      there was nobody to hand it to: no officer on shift, no Head of Security
 *             on file. A fact about the site, not a failure of the platform
 *   failed    the hand-off was attempted and threw; the error was reported
 *   skipped   deliberately not attempted, or attempted into something that delivers to
 *             nobody: the demonstration estate, push switched off, no live channel, the
 *             mailer or the SMS driver set to `log`
 *
 * Only `queued` and `sent` count as REACHED (`reached()`), and a destination with no row
 * at all is reported as NOT REACHED with the reason in `UNADDRESSED` — which is how a leg
 * the platform does not have yet stays visible instead of silently absent.
 *
 * APPEND-ONLY, by a withheld grant and two triggers. Never read with a row lock.
 *
 * @property int $id
 * @property int $duress_alert_id
 * @property string $tenant_id
 * @property string $destination one of DESTINATIONS
 * @property int|null $round which ring round this leg was (the guard ring); null is the raise, and every row written before rings
 * @property string $channel `push`, `broadcast`, `email` or `sms`
 * @property int $recipients handsets, people, numbers or channels — see the migration
 * @property string $outcome `queued`, `sent`, `none`, `failed` or `skipped`
 * @property int $elapsed_ms from the fan-out's start to this leg's hand-off
 * @property string|null $note why, in a sentence
 * @property Carbon|null $created_at
 * @property-read DuressAlert|null $alert
 *
 * @mixin \Eloquent
 */
class DuressAlertDelivery extends Model
{
    use CentralConnection;

    /* -------------------------------------------------------- destinations */

    /** §1.2 item 1 — every guard on shift at that estate. */
    public const GUARDS_ON_SHIFT = 'guards_on_shift';

    /** §1.2 item 2 — the Gemini dispatcher console's live queue. */
    public const DISPATCHER_CONSOLE = 'dispatcher_console';

    /** §1.2 item 3 — the Gemini Director. */
    public const DIRECTOR_DASHBOARD = 'director_dashboard';

    /** §1.2 item 4 — the Gemini Head of Security for that site. */
    public const GEMINI_HEAD_OF_SECURITY = 'gemini_head_of_security';

    /** §1.2 item 5 — the estate's own Head of Security (§2). */
    public const ESTATE_HEAD_OF_SECURITY = 'estate_head_of_security';

    /** §1.2 item 6 — the estate's community console: its pop-up, and its committee. */
    public const ESTATE_CONSOLE = 'estate_console';

    /** §1.2 item 7 — the household's nominated emergency contacts. */
    public const EMERGENCY_CONTACTS = 'emergency_contacts';

    /**
     * §1.2's seven, in its own order, with the words a screen uses for them.
     *
     * The ORDER is the work order's, so the dispatcher's list and `panic:walk` read in
     * the sequence the client wrote them and a missing one is easy to spot.
     *
     * @var array<string, string>
     */
    public const DESTINATIONS = [
        self::GUARDS_ON_SHIFT => 'Guards on shift',
        self::DISPATCHER_CONSOLE => 'Gemini dispatch',
        self::DIRECTOR_DASHBOARD => 'Gemini Director',
        self::GEMINI_HEAD_OF_SECURITY => 'Gemini Head of Security for this site',
        self::ESTATE_HEAD_OF_SECURITY => 'The estate\'s own Head of Security',
        self::ESTATE_CONSOLE => 'The estate\'s committee and console',
        self::EMERGENCY_CONTACTS => 'The household\'s emergency contacts',
    ];

    /**
     * WHY A DESTINATION HAS NO ROW AT ALL — the legs this platform does not have.
     *
     * Stated rather than left blank, because a list that simply omitted the Director
     * would read as "the Director was told" to anybody who did not count the lines.
     * Each sentence is true of the code as it stands; when a leg is built, it writes
     * rows and this sentence is never shown again.
     *
     * @var array<string, string>
     */
    public const UNADDRESSED = [
        self::DIRECTOR_DASHBOARD => 'Nothing reached the Director for this alert: it was raised before the Gemini console listened for alerts, so a Director saw it only on the notification bell or on a dispatch screen already open.',

        /*
         * EMERGENCY_CONTACTS IS NO LONGER HERE (M10 §1.2 item 7). The household's own
         * contacts are texted by `Dispatch\HouseholdContactsSms`, and every fan-out now
         * writes their row — the count and an honest outcome, or `none` with the reason
         * on an officer's duress. An alert with no row at all predates the leg, and
         * reads `NOT_ADDRESSED`, which is true of it.
         */
    ];

    /** For any destination not in `UNADDRESSED` that nonetheless has no row. */
    public const NOT_ADDRESSED = 'No leg of the fan-out addressed this destination for this alert.';

    /* ------------------------------------------------------------ channels */

    public const PUSH = 'push';

    public const BROADCAST = 'broadcast';

    public const EMAIL = 'email';

    public const SMS = 'sms';

    /** @var array<string, string> */
    public const CHANNELS = [
        self::PUSH => 'Push',
        self::BROADCAST => 'Live channel',
        self::EMAIL => 'Email',
        self::SMS => 'Text message',
    ];

    /* ------------------------------------------------------------ outcomes */

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const NONE = 'none';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /**
     * What each outcome is called on a screen. "Handed to the service", never
     * "delivered": see the class note.
     *
     * @var array<string, string>
     */
    public const OUTCOMES = [
        self::QUEUED => 'Queued',
        self::SENT => 'Handed to the service',
        self::NONE => 'Nobody to tell',
        self::FAILED => 'Failed',
        self::SKIPPED => 'Not sent',
    ];

    /** The two outcomes that mean the platform let go of the message towards somebody. */
    public const REACHED = [self::QUEUED, self::SENT];

    /**
     * THE REASON AN ALERT MAIL WAS NOT SENT TO SOMEBODY WHO WAS ON ITS LIST (M10 §1.4).
     *
     * "An unverified address is never used for alerting." A person the alert mail was
     * meant for who has not proven an address is written as an `email` row of their
     * own — `skipped`, with how many of them — and its note carries this phrase. It is
     * the ONE way the report knows such a row from the mailer being set to `log`, which
     * is also an email leg that was skipped: the table has no column for why, and one
     * fixed phrase written by one writer (`PanicDeliveries`) is sturdier than a second
     * reading of free text.
     */
    public const ADDRESS_NOT_VERIFIED = 'could not be emailed: address not verified';

    /**
     * How long a `note` may be — the column's width (bug hunt B091). It was 240, and a
     * sentence the recorder writes reached 317: the one insert was refused and the alert
     * lost its whole record. `PanicDeliveries` trims to this, so the column is never why.
     */
    public const NOTE_LENGTH = 1000;

    /** Append-only: `created_at` is written with the row and there is no `updated_at`. */
    public $timestamps = false;

    protected $fillable = [
        'duress_alert_id',
        'tenant_id',
        'destination',
        'round',
        'channel',
        'recipients',
        'outcome',
        'elapsed_ms',
        'note',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'recipients' => 'integer',
            'elapsed_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DuressAlert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(DuressAlert::class, 'duress_alert_id');
    }

    /** Whether this leg was handed off towards somebody — `queued` or `sent`, nothing else. */
    public function reached(): bool
    {
        return in_array($this->outcome, self::REACHED, true);
    }

    /** Whether this row is the people an alert mail skipped for want of a proven address. */
    public function isUnverifiedSkip(): bool
    {
        return $this->channel === self::EMAIL
            && $this->outcome === self::SKIPPED
            && str_contains((string) $this->note, self::ADDRESS_NOT_VERIFIED);
    }
}
