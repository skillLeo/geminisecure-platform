<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\SiteDateTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One reported fault — boards 17 and 18.
 *
 * THE SLA CLOCK STARTS AT `reported_at` AND NOWHERE ELSE. The Build Spec is
 * explicit: "SLA breach is computed from the reported time, not the assigned
 * time, so a ticket that sat unassigned still shows as overdue." So the deadline
 * is `reported_at` plus `sla_hours`, and `assigned_at` — which is recorded, and
 * which a queue built the obvious way would have measured from — appears in no
 * arithmetic on this class. A ticket that sat in the queue for a week is a week
 * overdue, which is the failure a maintenance queue exists to surface and the
 * one a start-at-assignment model would hide.
 *
 * THERE IS NO `is_overdue` COLUMN AND NO `sla_due_at` COLUMN. A stored breach
 * flag is wrong from the second after it is written and needs a job to keep it
 * true; a stored deadline needs rewriting every time the priority changes, and
 * the rewrite somebody forgets is a ticket quietly given longer than its
 * priority allows. Both are derived here, from two columns of this row.
 *
 * `status` IS THE LIFECYCLE, NOT THE BOARD'S BADGE. Board 17 draws Submitted, In
 * progress, Overdue and Completed in one column; this column holds submitted,
 * acknowledged, assigned, in_progress, completed, verified and cancelled.
 * "Overdue" is not a state anything sets — it is an open ticket past its
 * deadline, arithmetic against now — and `boardStatus()` derives it, the same
 * way `Bill::boardStatus()` derives an overdue bill.
 *
 * COMPLETED AND VERIFIED ARE TWO FACTS. Board 18's timeline draws "Verified by
 * resident" as a sixth stage after "Completed": a job the estate believes is
 * finished and one the household agrees is finished are different states, and
 * the gap between them is where a reopen comes from.
 *
 * @property bool $is_simulated whether the Resident App simulator wrote this row (13 C3)
 * @property int $id
 * @property int $number
 * @property string $title
 * @property string $location_label
 * @property int|null $unit_id
 * @property string|null $category
 * @property string|null $description
 * @property bool|null $access_permitted may a technician be let in with nobody home — null where the request did not say (M4 §3.5)
 * @property string|null $preferred_time when the household would like the visit, in their own words
 * @property string|null $latitude where the resident was when they sent a security report, if they agreed to attach it
 * @property string|null $longitude
 * @property int|null $location_accuracy_m the fix's own accuracy, in metres
 * @property bool $happening_now the resident said it is happening as they write — it alerts the guards on duty and changes no ownership
 * @property string $priority
 * @property string $status
 * @property string|null $reported_by_name
 * @property int|null $reported_by
 * @property Carbon $reported_at
 * @property int $sla_hours
 * @property int|null $assigned_vendor_id
 * @property Carbon|null $assigned_at
 * @property string|null $technician_name
 * @property string|null $technician_phone
 * @property Carbon|null $eta_starts_at
 * @property Carbon|null $eta_ends_at
 * @property string|null $resolution
 * @property Carbon|null $closed_at
 * @property Carbon|null $verified_at
 * @property string|null $verified_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit|null $unit
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, TicketActivity> $activity
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceTicket newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceTicket newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceTicket query()
 *
 * @mixin \Eloquent
 */
class MaintenanceTicket extends Model
{
    public const SUBMITTED = 'submitted';

    public const ACKNOWLEDGED = 'acknowledged';

    public const ASSIGNED = 'assigned';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const VERIFIED = 'verified';

    public const CANCELLED = 'cancelled';

    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    /**
     * Board 18's timeline, in the order it draws them.
     *
     * The order is the LIFECYCLE's and not the timestamps': board 18 renders all
     * six rows whether or not each has happened, greying the ones that have not,
     * so a stage nobody has reached still occupies its place.
     */
    public const STAGES = [
        self::SUBMITTED => 'Submitted',
        self::ACKNOWLEDGED => 'Acknowledged',
        self::ASSIGNED => 'Assigned to vendor',
        self::IN_PROGRESS => 'In progress',
        self::COMPLETED => 'Completed',
        self::VERIFIED => 'Verified by resident',
    ];

    /** What board 17's priority dot prints beside its colour. */
    public const PRIORITY_LABELS = [
        self::HIGH => 'High',
        self::MEDIUM => 'Medium',
        self::LOW => 'Low',
    ];

    /**
     * The colour of board 17's status badge, by group: `boardStatus()` returns one of these keys. Overdue is not
     * among them (M68 §2.3, FAC-3): it is a flag beside the state, never a state of its own.
     */
    public const STATUS_LABELS = [
        'submitted' => 'Submitted',
        'progress' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    /**
     * THE QUEUE'S GROUPS, MUTUALLY EXCLUSIVE (M68 §2.3, FAC-2). Open: nobody has the job. In progress: a vendor has it.
     * Completed: the work is done. A cancelled ticket is in none of them and shows under All. The tiles count them and
     * the chips filter by them, from this one list, so a card and its chip can never disagree.
     */
    public const GROUPS = [
        'open' => [self::SUBMITTED, self::ACKNOWLEDGED],
        'in_progress' => [self::ASSIGNED, self::IN_PROGRESS],
        'completed' => [self::COMPLETED, self::VERIFIED],
    ];

    /**
     * What the Status column prints: the state the ticket is in (FAC-3), never "Overdue". In the approved workflow words
     * (M96): seen by the office and nobody on it yet is Under review, and done and agreed by the household is Closed;
     * "Acknowledged" and "Verified" were the stored words. The spine keeps its stage names, which are events, and the
     * Resident App never reads these.
     */
    public const STATE_LABELS = [
        self::SUBMITTED => 'Submitted',
        self::ACKNOWLEDGED => 'Under review',
        self::ASSIGNED => 'Assigned',
        self::IN_PROGRESS => 'In progress',
        self::COMPLETED => 'Completed',
        self::VERIFIED => 'Closed',
        self::CANCELLED => 'Cancelled',
    ];

    /**
     * THE ONE LIST OF MAINTENANCE CATEGORIES (FAC-6): keys and their words, for the console and the phones. The console
     * offered "Plumbing" and the Resident App sends "plumbing", so the summary counted the two apart. The keys are the
     * console's own and what the Resident App already sends; "security" is not among them, because a security report is
     * not a maintenance job (FAC-4). Stored either way, a category is said in its one word through `categoryLabel()`.
     */
    public const CATEGORIES = [
        'electrical' => 'Electrical',
        'plumbing' => 'Plumbing',
        'equipment' => 'Equipment',
        'access control' => 'Access control',
        'grounds' => 'Grounds',
        'structural' => 'Structural',
        'water' => 'Water',
        'waste' => 'Waste',
        'roads' => 'Roads',
        'landscaping' => 'Landscaping',
        'amenity' => 'Amenity',
        'other' => 'Other',
    ];

    /** The Resident App's security report: stored as a category, never a maintenance one (FAC-4). */
    public const SECURITY_CATEGORY = 'security';

    /** Whether this is the Resident App's security report rather than a maintenance job (FAC-4). */
    public function isSecurityReport(): bool
    {
        return mb_strtolower(trim((string) $this->category)) === self::SECURITY_CATEGORY;
    }

    /** "Plumbing", whether it was stored as "plumbing" or "Plumbing"; null for none (FAC-6). */
    public static function categoryLabel(?string $category): ?string
    {
        $typed = trim((string) $category);

        if ($typed === '') {
            return null;
        }

        $key = mb_strtolower($typed);

        return $key === self::SECURITY_CATEGORY ? 'Security' : (self::CATEGORIES[$key] ?? ucfirst($typed));
    }

    protected $fillable = [
        'number',
        'title',
        'location_label',
        'unit_id',
        'category',
        'description',

        // The Resident App's named fields (M4 §3.5) — see the migration for why
        // `access_permitted` is three-valued and why `happening_now` owns nothing.
        'access_permitted',
        'preferred_time',
        'latitude',
        'longitude',
        'location_accuracy_m',
        'happening_now',
        'priority',
        'status',
        'reported_by_name',
        'reported_by',
        'reported_at',
        'sla_hours',
        'assigned_vendor_id',
        'assigned_at',
        'technician_name',
        'technician_phone',
        'eta_starts_at',
        'eta_ends_at',
        'resolution',
        'closed_at',
        'verified_at',
        'verified_by_name',
    ];

    /**
     * The route binds on the NUMBER.
     *
     * Board 18's own URL is `/facilities/maintenance/1042`, which is the number
     * a resident was given and a bill already stores — not a surrogate id that
     * would make the same job answer to two different addresses.
     */
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_simulated' => 'boolean',
            'sla_hours' => 'integer',

            // Three-valued: null is "the request did not say", which is not "no".
            'access_permitted' => 'boolean',
            'happening_now' => 'boolean',
            'location_accuracy_m' => 'integer',

            // ONE TIME RULE (M4 §3.1): stored true UTC, read on the estate's clock — see `SiteDateTime`.
            'reported_at' => SiteDateTime::class,
            'assigned_at' => SiteDateTime::class,
            'eta_starts_at' => SiteDateTime::class,
            'eta_ends_at' => SiteDateTime::class,
            'closed_at' => SiteDateTime::class,
            'verified_at' => SiteDateTime::class,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the SLA, derived from the report and from nothing else */
    /* ------------------------------------------------------------------ */

    /** When this ticket was due, counted from the moment it was REPORTED. */
    public function slaDueAt(): Carbon
    {
        return $this->reported_at->copy()->addHours($this->sla_hours);
    }

    /**
     * Whether it has breached.
     *
     * `$asAt` rather than `now()` inside, because "overdue" is a statement about
     * a moment and a report run asks it about a moment that is not this one.
     *
     * A CLOSED TICKET IS NOT OVERDUE, however late it was. Board 17 replaces the
     * Age cell with "Closed Sep 2" for a completed ticket and gives it the
     * completed badge — how long a finished job took is a question for the
     * average-resolution tile, not for a queue of work outstanding.
     */
    public function isOverdue(?Carbon $asAt = null): bool
    {
        // A security report has no maintenance deadline (FAC-4): it was counted overdue as plumbing.
        if ($this->isClosed() || $this->isSecurityReport()) {
            return false;
        }

        return ($asAt?->copy() ?? Carbon::now())->greaterThan($this->slaDueAt());
    }

    /** Whether the estate still owes anybody work on this. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::VERIFIED, self::CANCELLED], true);
    }

    /** Whether it counts towards board 17's "Open tickets" tile. */
    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    /**
     * The colour of the badge board 17 draws: the ticket's group (`GROUPS`), or cancelled.
     *
     * OVERDUE IS NOT A STATE (M68 §2.3, FAC-3). It used to win over every open state, so an overdue ticket read
     * "Overdue" and nobody could tell whether it had ever been started; `isOverdue()` is the flag drawn beside the
     * state instead. `$asAt` is kept for the callers that pass the moment they read.
     */
    public function boardStatus(?Carbon $asAt = null): string
    {
        return match (true) {
            $this->status === self::CANCELLED => 'cancelled',
            in_array($this->status, self::GROUPS['completed'], true) => 'completed',
            in_array($this->status, self::GROUPS['open'], true) => 'submitted',
            default => 'progress',
        };
    }

    /** "Acknowledged": the state the ticket is in, as the Status column prints it. */
    public function stateLabel(): string
    {
        return self::STATE_LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', (string) $this->status));
    }

    /* ------------------------------------------------------------------ */
    /* what the boards print */
    /* ------------------------------------------------------------------ */

    /**
     * "#1042 · Gate lighting", board 17's Ticket cell and board 27's reference. The console's separator, the middot, and
     * never an em dash (M53 Law 3): every screen and refusal that names a work order says it through here. The title
     * without a place it ends with (FAC-15): the place has a column of its own.
     */
    public function label(): string
    {
        return '#'.$this->number.' · '.$this->displayTitle();
    }

    /**
     * Board 17's Age column.
     *
     * A closed ticket's age is replaced by the day it closed, because how old a
     * finished job is tells a manager nothing and when it finished tells them
     * whether the estate is keeping up.
     *
     * Humanised to the LARGEST unit the board uses — "2 days", "4 hours" — and
     * never to minutes, which is not the resolution a queue is triaged at. Under
     * an hour it is "Just now" (FAC-14): a ticket raised seconds ago read
     * "1 hour", an hour it had not been waiting.
     */
    public function ageLabel(?Carbon $asAt = null): string
    {
        if ($this->closed_at !== null && $this->isClosed()) {
            return 'Closed '.$this->closed_at->format('M j');
        }

        $now = $asAt?->copy() ?? Carbon::now();
        $hours = (int) $this->reported_at->diffInHours($now, absolute: true);

        if ($hours >= 24) {
            $days = intdiv($hours, 24);

            return $days.' day'.($days === 1 ? '' : 's');
        }

        if ($hours < 1) {
            return 'Just now';
        }

        return $hours.' hour'.($hours === 1 ? '' : 's');
    }

    /** "Island Electric Services", or the word board 17 draws when there is none. */
    public function assigneeLabel(): string
    {
        return $this->vendor->name ?? 'Unassigned';
    }

    /**
     * The title as the boards draw it, without the place when it ends with it (FAC-15).
     *
     * The Resident App writes "The gate arm sticks open: Common area" and sends "Common area" as the place too, so the
     * queue and the ticket printed the place twice. The title as stored is untouched: it is what the household wrote.
     */
    public function displayTitle(): string
    {
        $title = trim($this->title);
        $place = trim((string) $this->location_label);

        if ($place === '') {
            return $title;
        }

        $trimmed = trim((string) preg_replace('/(?:\s*[:,·]\s*|\s+-\s+|\s+at\s+)'.preg_quote($place, '/').'\s*$/iu', '', $title));

        return $trimmed === '' ? $title : $trimmed;
    }

    /**
     * Where the job is, as the boards draw it (FAC-15). A place that names the ticket's own lot is drawn from the
     * lot's record, "Lot 47 · Phase 1", whatever phase was typed beside it; any other place is drawn as it was typed.
     */
    public function placeLabel(): string
    {
        $typed = trim((string) $this->location_label);
        // A ticket filed against no unit asks nothing about one.
        $unit = $this->unit_id === null ? null : $this->unit;

        if ($unit !== null && preg_match('/^'.preg_quote((string) $unit->reference, '/').'(?![0-9])/i', $typed) === 1) {
            return $unit->reference.(trim((string) $unit->block) === '' ? '' : ' · '.$unit->block);
        }

        return $typed;
    }

    /** "Gate lighting · Phase 2 visitor parking": board 18's summary heading, with the middot, not an em dash (Law 3). */
    public function summaryTitle(): string
    {
        // The location's own middot flattened to a space, which is what board 18
        // draws where board 17 draws "Phase 2 · visitor parking".
        return $this->displayTitle().' · '.str_replace(' · ', ' ', $this->placeLabel());
    }

    /* ------------------------------------------------------------------ */
    /* relations */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'assigned_vendor_id');
    }

    /** @return HasMany<TicketActivity, $this> */
    public function activity(): HasMany
    {
        return $this->hasMany(TicketActivity::class, 'maintenance_ticket_id');
    }

    /**
     * The bills raised against this job.
     *
     * Joined on the NUMBER, because that is what board 27 prints and what the
     * bill was recorded with. This is the trace a committee walks when it asks
     * what a job cost — ticket to bill to payment to bank line — and the reason
     * `bills.ticket_id` has a foreign key at all.
     *
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class, 'ticket_id', 'number');
    }
}
