<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\SiteDateTime;
use App\Support\SiteTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One state change on one ticket — board 18's timeline.
 *
 * WHY THE TICKET'S OWN STATUS IS NOT ENOUGH. The Build Spec: "The reporting
 * resident can follow every state change in the app without asking. State
 * changes are timestamped and attributed." A status column says where a ticket
 * is; it cannot say when it got there, who moved it, or that it was assigned to
 * one vendor before another. A resident asking why their gate light is still out
 * is asking about the history, and a history that has to be inferred from a
 * single mutable string is not one.
 *
 * THE ACTOR IS A NAME FIRST AND AN ID SECOND. Board 18 attributes one stage to
 * "Patricia Morgan" and the next to "Island Electric Services" — a console user
 * and a supplier, and the supplier has no user account. An `actor_id` foreign
 * key to `users` could record only half of this ticket's own history, so the
 * name is what is stored and `actor_kind` says what kind of party it names.
 *
 * `stage` IS NULLABLE AND `event` IS NOT. Six stages make up the spine board 18
 * draws in fixed lifecycle order; a reassignment and a priority change are state
 * changes a resident is entitled to follow and advance no stage. Storing them in
 * the same table keeps one chronological record of what happened, which is what
 * anybody reading it actually wants.
 *
 * @property int $id
 * @property int $maintenance_ticket_id
 * @property string $event
 * @property string|null $stage
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note
 * @property string $actor_kind
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property list<string>|null $recipients who a message went to, by name (batch 1 item 1); null on older lines
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MaintenanceTicket $ticket
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketActivity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketActivity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketActivity query()
 *
 * @mixin \Eloquent
 */
class TicketActivity extends Model
{
    /**
     * The table is named for what it holds rather than pluralised.
     *
     * "Activity" is already a mass noun; `ticket_activities` reads as a
     * different word, and every query in this module says `activity`.
     */
    protected $table = 'maintenance_ticket_activity';

    public const REPORTED = 'reported';

    public const ACKNOWLEDGED = 'acknowledged';

    public const ASSIGNED = 'assigned';

    public const REASSIGNED = 'reassigned';

    public const PRIORITY_CHANGED = 'priority_changed';

    public const STARTED = 'started';

    public const RESOLVED = 'resolved';

    public const REOPENED = 'reopened';

    public const VERIFIED = 'verified';

    public const NOTE = 'note';

    /** Who a change is attributed to. */
    public const STAFF = 'staff';

    public const VENDOR = 'vendor';

    public const RESIDENT = 'resident';

    public const SYSTEM = 'system';

    protected $fillable = [
        'maintenance_ticket_id',
        'event',
        'stage',
        'from_status',
        'to_status',
        'note',
        'actor_kind',
        'actor_id',
        'actor_name',
        'occurred_at',
        // Who a message to the household went to, by name, as they were when it was sent (batch 1 item 1).
        'recipients',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            /*
             * ONE TIME RULE (M4 §3.1). Stored true UTC; read on the estate's clock, because
             * `timelineLine()` below prints it — and board 18 prints it beside the ticket's own
             * `reported_at`, which is the SAME MOMENT for the reported entry. Left on UTC the
             * two lines on one screen disagreed by five hours.
             */
            'occurred_at' => SiteDateTime::class,
        ];
    }

    /**
     * Board 18's sub-line: "Sep 3, 8:15 AM · Patricia Morgan".
     *
     * The time alone where nothing attributed it — the resident's own report is
     * drawn with a timestamp and no name, because "Submitted · Andrea Fletcher"
     * would repeat the name already printed two lines above it in the meta.
     */
    public function timelineLine(): string
    {
        $stamp = $this->stamp();

        return trim((string) $this->actor_name) === ''
            ? $stamp
            : $stamp.' · '.$this->actor_name;
    }

    /** "Oct 9, 2:05 PM JA": when it happened, on the estate's clock, as the timeline prints it. */
    public function stamp(): string
    {
        return SiteTime::fmt(SiteTime::local($this->occurred_at), 'M j, g:i A');
    }

    /**
     * What happened, in the ticket thread's words (M68 §2.1, FAC-7): "Reassigned from Island Electric Services to
     * AquaTech Pool Services", "Message to the household". A vendor's line names the vendor here, because the vendor is
     * who the line is attributed to and the office member who typed it is not stored.
     */
    public function what(): string
    {
        $actor = trim((string) $this->actor_name);
        $note = trim((string) $this->note);

        return match ($this->event) {
            self::REPORTED => 'Reported',
            self::ACKNOWLEDGED => 'Acknowledged',
            self::ASSIGNED => $actor === '' ? 'Assigned to a vendor' : 'Assigned to '.$actor,
            // The note is "Reassigned from Island Electric Services" (Maintenance::assign()).
            self::REASSIGNED => match (true) {
                $this->isSameVendorAgain() => 'Assigned to '.$actor.' again',
                $note === '' => 'Reassigned to '.$actor,
                default => $note.' to '.$actor,
            },
            self::PRIORITY_CHANGED => $note === '' ? 'Priority changed' : $note,
            self::STARTED => $this->actor_kind === self::VENDOR && $actor !== '' ? 'Work started by '.$actor : 'Work started',
            self::RESOLVED => 'Marked completed',
            self::REOPENED => 'Reopened',
            self::VERIFIED => 'The household confirmed it is fixed',
            // A message moves no status (Maintenance::messageResident()); a visit change keeps the status it had.
            self::NOTE => match (true) {
                $this->actor_kind === self::RESIDENT => 'Message from the household',
                // Who it went to, named, where the line kept them (batch 1 item 1); older messages say the household.
                $this->to_status === null && is_array($this->recipients) && $this->recipients !== [] => 'Message to '.self::listed($this->recipients),
                $this->to_status === null => 'Message to the household',
                default => 'Note',
            },
            default => ucfirst(str_replace('_', ' ', $this->event)),
        };
    }

    /**
     * A "reassignment" to the vendor that already had the job (M68 §2.2, FAC-8). The seed wrote these (ticket #1041)
     * before the same supplier stopped being a reassignment (26 Sep); read as what happened, never as a change of vendor.
     */
    public function isSameVendorAgain(): bool
    {
        return $this->event === self::REASSIGNED
            && trim((string) $this->note) === 'Reassigned from '.trim((string) $this->actor_name);
    }

    /** What somebody wrote with it (a message, a reason, a resolution), or null where `what()` already says it all. */
    public function words(): ?string
    {
        if (in_array($this->event, [self::REASSIGNED, self::PRIORITY_CHANGED], true)) {
            return null;
        }

        $note = trim((string) $this->note);

        return $note === '' ? null : $note;
    }

    /**
     * "Andrea Fletcher", "Andrea Fletcher and Marlon Fletcher", "A, B and C".
     *
     * @param  list<string>  $names
     */
    public static function listed(array $names): string
    {
        $names = array_values(array_filter(array_map(static fn (mixed $name): string => trim((string) $name), $names), static fn (string $name): bool => $name !== ''));

        return count($names) < 2 ? ($names[0] ?? '') : implode(', ', array_slice($names, 0, -1)).' and '.$names[count($names) - 1];
    }

    /** Who did it, by name; null on a vendor's line (`what()` names the vendor) and where nobody is recorded. */
    public function who(): ?string
    {
        if (in_array($this->actor_kind, [self::VENDOR, self::SYSTEM], true)) {
            return null;
        }

        $name = trim((string) $this->actor_name);

        return $name === '' ? null : $name;
    }

    /** @return BelongsTo<MaintenanceTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTicket::class, 'maintenance_ticket_id');
    }
}
