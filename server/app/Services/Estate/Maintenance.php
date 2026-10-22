<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\AuditEntry;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\TicketActivity;
use App\Models\Estate\Unit;
use App\Models\Estate\Vendor;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Services\Push\PushAnnouncer;
use App\Services\Push\PushAudience;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use App\Support\TableQuery;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The maintenance queue and one ticket end to end — boards 17 and 18.
 *
 * THE SLA IS MEASURED FROM THE REPORT AND FROM NOTHING ELSE. The Build Spec:
 * "SLA breach is computed from the reported time, not the assigned time, so a
 * ticket that sat unassigned still shows as overdue." Every method below that
 * touches a deadline goes through `MaintenanceTicket::isOverdue()`, whose only
 * terms are `reported_at` and `sla_hours`. `assigned_at` is recorded and appears
 * in no arithmetic anywhere in this class — a queue that measured from
 * assignment would hide precisely the failure it exists to surface, and the
 * ticket that has been ignored longest would be the one it reported as fine.
 *
 * NOTHING HERE IS STORED AS A BREACH. There is no flag to recalculate and no
 * nightly job to keep one true. Overdue is arithmetic against a moment, and the
 * moment is always passed in or taken as now.
 *
 * EVERY STATE CHANGE IS WRITTEN DOWN, TIMESTAMPED AND ATTRIBUTED. The Build
 * Spec: "The reporting resident can follow every state change in the app without
 * asking." So no method here mutates a status without also recording what
 * happened, who did it and when — in the same transaction, because a state
 * change with no entry behind it is a ticket that changed for no reason anybody
 * can see.
 *
 * NOT ONE FIGURE THIS CLASS RETURNS IS A RESIDENT'S FINANCIAL POSITION. The
 * Property Manager holds `facilities` in full and holds no `dues_ledger`, no
 * `payments` and no `accounting_posting` — a platform invariant (D-010) rather
 * than an estate setting. A maintenance board that returned a unit balance
 * beside a ticket would hand the person who commissions the work the thing they
 * are locked out of, and `EstateFacilitiesMaintenanceTest` walks both boards'
 * payloads to prove none does.
 */
class Maintenance
{
    /**
     * How long the estate has to resolve a ticket, by priority.
     *
     * DERIVED FROM BOARD 17, NOT CHOSEN. The board draws five tickets with an
     * age and a status against each, and four of them constrain this ladder:
     *
     *   #1039  High    4 hours  Submitted     — so High is longer than 4 hours
     *   #1041  High    6 days   Overdue       — and shorter than 6 days
     *   #1042  Medium  2 days   In progress   — so Medium is longer than 2 days
     *   #1037  Low     3 days   In progress   — so Low is longer than 3 days
     *
     * One day, three days and seven days is the ordinary trades ladder that sits
     * inside every one of those bounds, and it reproduces the board's own
     * "2 overdue" tile against the tickets it draws.
     */
    public const SLA_HOURS = [
        MaintenanceTicket::HIGH => 24,
        MaintenanceTicket::MEDIUM => 72,
        MaintenanceTicket::LOW => 168,
    ];

    /** The longest service target an estate may set, in hours: a month. A job promised further off is not a target. */
    public const LONGEST_TARGET_HOURS = 720;

    /**
     * THIS ESTATE'S SERVICE TARGETS, in hours by priority (M98). The ladder above is the default, and each estate may set
     * its own (`setTargets`). Read from the estate's settings row; a column an estate's database does not have yet reads
     * as the default, so a deploy that has not migrated an estate changes nothing there.
     *
     * @return array<string, int>
     */
    public function targets(): array
    {
        $settings = EstateSetting::current();
        $targets = [];

        foreach (self::SLA_HOURS as $priority => $default) {
            $targets[$priority] = (int) ($settings->getAttribute('sla_hours_'.$priority) ?? $default);
        }

        return $targets;
    }

    /** How many of a supplier's jobs its page lists (M98): the open ones always come first. */
    public const SUPPLIER_JOBS = 50;

    /**
     * THE JOBS A SUPPLIER HAS BEEN GIVEN (M98, maintenance and vendors): every work order assigned to it, the open ones
     * first and then the newest, for the supplier's own page. The queue's own words for each: the state it is in, Overdue
     * beside it when it is late, the priority and its age. A place, and nothing of a household's position (D-010).
     *
     * @return array{rows: list<array<string, mixed>>, open: int, total: int}
     */
    public function workOrdersFor(Vendor $vendor): array
    {
        $now = Carbon::now();

        $rows = MaintenanceTicket::query()
            ->with('unit:id,reference,block')
            ->where('assigned_vendor_id', $vendor->id)
            ->orderByRaw('closed_at IS NULL DESC')
            ->orderByDesc('number')
            ->limit(self::SUPPLIER_JOBS)
            ->get()
            ->map(static fn (MaintenanceTicket $ticket): array => [
                'number' => $ticket->number,
                'ticket' => $ticket->label(),
                'location' => $ticket->placeLabel(),
                'priority' => $ticket->priority,
                'priority_label' => MaintenanceTicket::PRIORITY_LABELS[$ticket->priority],
                'status' => $ticket->boardStatus($now),
                'status_label' => $ticket->stateLabel(),
                'is_overdue' => $ticket->isOverdue($now),
                'age' => $ticket->ageLabel($now),
            ])
            ->values()
            ->all();

        // Both counts in one read: a page's reads stay the same at any number of jobs (the scale budgets).
        $counts = MaintenanceTicket::query()
            ->where('assigned_vendor_id', $vendor->id)
            ->toBase()
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(closed_at IS NULL), 0) AS open')
            ->first();

        return [
            'rows' => $rows,
            'open' => (int) ($counts->open ?? 0),
            'total' => (int) ($counts->total ?? 0),
        ];
    }

    /** The hours a ticket of this priority has before it is overdue, as this estate has set them. */
    public function slaHoursFor(string $priority): int
    {
        $this->guardPriority($priority);

        return $this->targets()[$priority];
    }

    /**
     * Set this estate's three service targets (M98). Whole hours, from one to a month, and High never longer than Medium
     * nor Medium than Low: a ladder that put an urgent job behind a routine one would have the queue chase the wrong
     * ticket. A ticket already raised keeps the target it was raised with; the next one, and the next priority change,
     * take these. Written to the central audit log with what they were.
     *
     * @param  array<string, int>  $hours  high, medium and low
     * @return array<string, int>
     */
    public function setTargets(array $hours, User $by): array
    {
        foreach (self::SLA_HOURS as $priority => $default) {
            $value = $hours[$priority] ?? null;

            if (! is_int($value) || $value < 1 || $value > self::LONGEST_TARGET_HOURS) {
                throw new DomainException(sprintf('A service target is whole hours, from 1 to %d.', self::LONGEST_TARGET_HOURS));
            }
        }

        if ($hours[MaintenanceTicket::HIGH] > $hours[MaintenanceTicket::MEDIUM]) {
            throw new DomainException('High must be no longer than Medium.');
        }

        if ($hours[MaintenanceTicket::MEDIUM] > $hours[MaintenanceTicket::LOW]) {
            throw new DomainException('Medium must be no longer than Low.');
        }

        $before = $this->targets();
        $settings = EstateSetting::current();

        foreach (self::SLA_HOURS as $priority => $default) {
            $settings->setAttribute('sla_hours_'.$priority, $hours[$priority]);
        }

        $settings->save();
        $after = $this->targets();

        if ($after !== $before) {
            AuditEntry::create([
                'tenant_id' => tenant()?->getTenantKey(),
                'actor_id' => $by->getKey(),
                'actor_name' => $by->name,
                'actor_role' => $by->roles->pluck('name')->implode(', '),
                'action' => 'estate.maintenance.targets_updated',
                'entity_type' => 'estate_setting',
                'entity_id' => (string) $settings->getKey(),
                'before' => $before,
                'after' => $after,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }

        return $after;
    }

    /**
     * Board 17's filter chips, in the order it draws them.
     *
     * The chip reads "Open" and the stored status is `submitted` — the board's
     * own wording for a ticket nobody has picked up yet.
     */
    public const FILTERS = [
        'all' => 'All',
        'open' => 'Open',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        // A resident's security report, read apart from the maintenance jobs (FAC-4): every other chip leaves it out.
        self::SECURITY_REPORTS => 'Security reports',
    ];

    /** The chip that holds the Resident App's security reports, and nothing else (FAC-4). */
    public const SECURITY_REPORTS = 'security';

    /** The red badge's filter (M68 §2.4, FAC-1): every ticket past its service target, whatever state it is in. */
    public const OVERDUE = 'overdue';

    /** Where a ticket number starts on an estate that has never had one. */
    private const FIRST_NUMBER = 1000;

    /* ------------------------------------------------------------------ */
    /* writing — each of these is a state change, and each is recorded */
    /* ------------------------------------------------------------------ */

    /**
     * Take a report. The clock starts HERE.
     *
     * `reportedAt` is an argument and does not default to now inside the
     * transaction, because a manager keying in a call taken on Saturday is
     * recording a ticket that is already two days old. Defaulting it to the
     * moment of data entry would restart the SLA at the estate's convenience,
     * which is the same defect as measuring from assignment wearing different
     * clothes.
     */
    public function report(
        string $title,
        string $locationLabel,
        string $priority = MaintenanceTicket::MEDIUM,
        ?Unit $unit = null,
        ?string $category = null,
        ?string $description = null,
        ?string $reportedByName = null,
        Carbon|string|null $reportedAt = null,
        ?User $by = null,
    ): MaintenanceTicket {
        $hours = $this->slaHoursFor($priority);

        $when = $this->asMoment($reportedAt ?? Carbon::now());

        /*
         * NOT IN THE FUTURE, AS A MOMENT (B050). A report may be older than its
         * entry — that is the point of the argument — and it cannot be newer than
         * now: a ticket filed at a time that has not happened starts its service
         * clock late and reads a negative age on board 17. Judged here, on the
         * UTC moment the typed digits mean, so it holds for whichever door the
         * report arrives at; the office's own validator used to read the digits
         * as UTC and let a time up to five hours ahead of Kingston through.
         */
        if ($when->greaterThan(Carbon::now())) {
            throw new DomainException(sprintf(
                'A ticket is reported at a time that has already happened. %s is later than now on the estate\'s clock (%s).',
                SiteTime::fmt(SiteTime::local($when), 'M j, g:i A'),
                SiteTime::fmt(SiteTime::now(), 'M j, g:i A'),
            ));
        }

        return DB::connection('tenant')->transaction(function () use (
            $title, $locationLabel, $priority, $unit, $category, $description, $reportedByName, $when, $by, $hours
        ): MaintenanceTicket {
            $ticket = MaintenanceTicket::create([
                'number' => $this->nextNumber(),
                'title' => $title,
                'location_label' => $locationLabel,
                'unit_id' => $unit?->id,
                'category' => $category,
                'description' => $description,
                'priority' => $priority,
                'status' => MaintenanceTicket::SUBMITTED,
                'reported_by_name' => $reportedByName,
                'reported_at' => $when,
                // This estate's target for the priority (M98), kept on the ticket: a later change to the targets does
                // not move a deadline a household has already been shown.
                'sla_hours' => $hours,
            ]);

            $this->record(
                ticket: $ticket,
                event: TicketActivity::REPORTED,
                stage: MaintenanceTicket::SUBMITTED,
                to: MaintenanceTicket::SUBMITTED,
                occurredAt: $when,

                /*
                 * Attributed to the RESIDENT where one reported it, and to the
                 * member of staff who raised the work order otherwise. Board 18
                 * draws the submitted stage with a timestamp and no name, because
                 * the reporter is already printed in the ticket meta two lines
                 * above — so the name is stored and the timeline chooses not to
                 * repeat it.
                 */
                actorKind: $reportedByName !== null ? TicketActivity::RESIDENT : TicketActivity::STAFF,
                actorName: $reportedByName ?? $by?->name,
                actorId: $reportedByName !== null ? null : $by?->getKey(),
            );

            return $ticket;
        });
    }

    /** Somebody in the office has seen it. */
    public function acknowledge(MaintenanceTicket $ticket, ?User $by = null, Carbon|string|null $on = null): MaintenanceTicket
    {
        if ($ticket->status !== MaintenanceTicket::SUBMITTED) {
            return $ticket;
        }

        return $this->transition(
            ticket: $ticket,
            to: MaintenanceTicket::ACKNOWLEDGED,
            event: TicketActivity::ACKNOWLEDGED,
            stage: MaintenanceTicket::ACKNOWLEDGED,
            occurredAt: $on,
            actorName: $by?->name,
            actorId: $by?->getKey(),
        );
    }

    /**
     * Put a vendor on it — or a different one.
     *
     * THE SAME METHOD FOR BOTH, and the activity log is what distinguishes them:
     * a first assignment advances the lifecycle and a reassignment does not, but
     * both are state changes the resident is entitled to follow. Board 18's
     * "Reassign vendor" button is this method with a ticket that already has one.
     *
     * ASSIGNMENT DOES NOT MOVE THE DEADLINE. It writes `assigned_at` and nothing
     * that any SLA calculation reads. A ticket assigned on day six of a
     * twenty-four hour target is five days overdue the moment the vendor is
     * named, which is the true statement and the useful one.
     */
    public function assign(
        MaintenanceTicket $ticket,
        Vendor $vendor,
        ?string $technicianName = null,
        ?string $technicianPhone = null,
        Carbon|string|null $etaStartsAt = null,
        Carbon|string|null $etaEndsAt = null,
        ?User $by = null,
        Carbon|string|null $on = null,
    ): MaintenanceTicket {
        if ($ticket->isClosed()) {
            throw new DomainException(sprintf(
                'Ticket %s is %s and cannot be assigned. Reopen it first, so the record says the job '.
                'came back rather than that it never finished.',
                $ticket->label(),
                $ticket->status,
            ));
        }

        $previous = $ticket->vendor;
        $when = $this->asMoment($on ?? Carbon::now());

        /*
         * THE SAME SUPPLIER AGAIN IS NOT A REASSIGNMENT (the client, 26 Sep). Changing only the visit window or the
         * technician wrote "Reassigned from Gate2 Contractor Ltd" — onto the office's timeline and the resident's —
         * when nobody had been taken off the job. Now it is a note saying what changed, and pressing Assign with
         * nothing changed writes nothing at all.
         */
        if ($previous !== null && (int) $previous->id === (int) $vendor->id) {
            return $this->changeVisit($ticket, $technicianName, $technicianPhone, $etaStartsAt, $etaEndsAt, $by, $when);
        }

        /*
         * A JOB GOES ONLY TO A SUPPLIER THAT TAKES MAINTENANCE WORK (DR-035): the list the ticket offers, and a post
         * built by hand, answer alike. A job already with a supplier keeps its visit changes above, whatever becomes
         * of that supplier's filing.
         */
        if (! Vendor::query()->takesMaintenanceWork()->whereKey($vendor->id)->exists()) {
            throw new DomainException($vendor->name.' does not take maintenance work. Choose another supplier.');
        }

        return DB::connection('tenant')->transaction(function () use (
            $ticket, $vendor, $previous, $technicianName, $technicianPhone, $etaStartsAt, $etaEndsAt, $when
        ): MaintenanceTicket {
            $from = $ticket->status;

            $ticket->forceFill([
                'assigned_vendor_id' => $vendor->id,
                'assigned_at' => $when,
                'technician_name' => $technicianName,
                'technician_phone' => $technicianPhone,
                'eta_starts_at' => $etaStartsAt === null ? null : $this->asMoment($etaStartsAt),
                'eta_ends_at' => $etaEndsAt === null ? null : $this->asMoment($etaEndsAt),

                // A ticket already in progress stays there: sending a different
                // electrician does not undo the work the first one started.
                'status' => $from === MaintenanceTicket::IN_PROGRESS
                    ? MaintenanceTicket::IN_PROGRESS
                    : MaintenanceTicket::ASSIGNED,
            ])->save();

            $this->record(
                ticket: $ticket,
                event: $previous === null ? TicketActivity::ASSIGNED : TicketActivity::REASSIGNED,

                /*
                 * Only a FIRST assignment fills board 18's third stage. A
                 * reassignment is history rather than progress — the ticket does
                 * not become more assigned — and giving it the stage would
                 * overwrite the timestamp the resident was already shown.
                 */
                stage: $previous === null ? MaintenanceTicket::ASSIGNED : null,
                to: $ticket->status,
                from: $from,
                occurredAt: $when,
                note: $previous === null ? null : 'Reassigned from '.$previous->name,

                // The VENDOR is who this stage is attributed to. Board 18 draws
                // "Sep 3, 11:20 AM · Island Electric Services", not the manager
                // who typed it.
                actorKind: TicketActivity::VENDOR,
                actorName: $vendor->name,
            );

            return $ticket;
        });
    }

    /**
     * The same supplier, a different visit: the technician or the window changes, and a note says which. The
     * supplier's first assignment keeps its stage and its time; the status does not move.
     */
    private function changeVisit(
        MaintenanceTicket $ticket,
        ?string $technicianName,
        ?string $technicianPhone,
        Carbon|string|null $etaStartsAt,
        Carbon|string|null $etaEndsAt,
        ?User $by,
        Carbon $when,
    ): MaintenanceTicket {
        $from = $etaStartsAt === null ? null : $this->asMoment($etaStartsAt);
        $to = $etaEndsAt === null ? null : $this->asMoment($etaEndsAt);

        $sameMoment = static fn (?Carbon $a, ?Carbon $b): bool => ($a === null && $b === null) || ($a !== null && $b !== null && $a->equalTo($b));
        $windowChanged = ! $sameMoment($ticket->eta_starts_at, $from) || ! $sameMoment($ticket->eta_ends_at, $to);
        $technicianChanged = trim((string) $ticket->technician_name) !== trim((string) $technicianName);
        $phoneChanged = trim((string) $ticket->technician_phone) !== trim((string) $technicianPhone);

        if (! $windowChanged && ! $technicianChanged && ! $phoneChanged) {
            return $ticket;
        }

        return DB::connection('tenant')->transaction(function () use ($ticket, $technicianName, $technicianPhone, $from, $to, $windowChanged, $technicianChanged, $by, $when): MaintenanceTicket {
            $ticket->forceFill([
                'technician_name' => $technicianName,
                'technician_phone' => $technicianPhone,
                'eta_starts_at' => $from,
                'eta_ends_at' => $to,
            ])->save();

            // What the household is told changed. The phone number is the office's and never goes on the timeline.
            $parts = array_values(array_filter([
                $windowChanged ? ($from === null && $to === null ? 'Visit time removed' : 'Visit time changed: '.$this->windowLabel($from, $to)) : null,
                $technicianChanged ? (trim((string) $technicianName) === '' ? 'Technician removed' : 'Technician changed: '.trim((string) $technicianName)) : null,
            ]));

            if ($parts !== []) {
                $this->record(
                    ticket: $ticket,
                    event: TicketActivity::NOTE,
                    to: $ticket->status,
                    from: $ticket->status,
                    occurredAt: $when,
                    note: implode(' · ', $parts),
                    actorName: $by?->name,
                    actorId: $by?->getKey(),
                );
            }

            return $ticket;
        });
    }

    /** "Sep 26, 2:00–4:00 PM" on the estate's clock, or "from Sep 26, 2:00 PM" with no end. */
    private function windowLabel(?Carbon $from, ?Carbon $to): string
    {
        $first = $from ?? $to;

        if ($first === null) {
            return '';
        }

        $local = static fn (Carbon $at): Carbon => SiteTime::local($at);

        // The clock it is on, once, at the end (M19A §4.2): "Sep 26, 2:00 PM – 4:00 PM JA".
        if ($from === null) {
            return 'by '.SiteTime::fmt($local($to), 'M j, g:i A');
        }

        if ($to === null) {
            return 'from '.SiteTime::fmt($local($from), 'M j, g:i A');
        }

        return $local($from)->format('M j, g:i A').' – '.SiteTime::fmt($local($to), $local($from)->isSameDay($local($to)) ? 'g:i A' : 'M j, g:i A');
    }

    /** The vendor is on site. */
    public function start(
        MaintenanceTicket $ticket,
        ?string $note = null,
        ?User $by = null,
        Carbon|string|null $on = null,
    ): MaintenanceTicket {
        if ($ticket->isClosed()) {
            throw new DomainException(sprintf(
                'Ticket %s is %s. Work starting again on a closed job is a reopen, which leaves the '.
                'first closure standing in the record.',
                $ticket->label(),
                $ticket->status,
            ));
        }

        return $this->transition(
            ticket: $ticket,
            to: MaintenanceTicket::IN_PROGRESS,
            event: TicketActivity::STARTED,
            stage: MaintenanceTicket::IN_PROGRESS,
            occurredAt: $on,
            note: $note,
            actorKind: $ticket->vendor === null ? TicketActivity::STAFF : TicketActivity::VENDOR,
            actorName: $ticket->vendor->name ?? $by?->name,
        );
    }

    /**
     * Raise or lower the priority.
     *
     * THE DEADLINE MOVES AND THE CLOCK DOES NOT RESET. Raising a three-day-old
     * medium ticket to high makes it overdue immediately, because high means
     * twenty-four hours from the report and the report was three days ago. That
     * is the point of escalating one.
     */
    public function changePriority(MaintenanceTicket $ticket, string $priority, ?User $by = null): MaintenanceTicket
    {
        $this->guardPriority($priority);

        if ($ticket->priority === $priority) {
            return $ticket;
        }

        $was = MaintenanceTicket::PRIORITY_LABELS[$ticket->priority];
        $hours = $this->slaHoursFor($priority);

        return DB::connection('tenant')->transaction(function () use ($ticket, $priority, $was, $by, $hours): MaintenanceTicket {
            $ticket->forceFill([
                'priority' => $priority,
                'sla_hours' => $hours,
            ])->save();

            $this->record(
                ticket: $ticket,
                event: TicketActivity::PRIORITY_CHANGED,
                to: $ticket->status,
                from: $ticket->status,
                occurredAt: Carbon::now(),
                note: 'Priority '.strtolower($was).' → '.strtolower(MaintenanceTicket::PRIORITY_LABELS[$priority]),
                actorName: $by?->name,
                actorId: $by?->getKey(),
            );

            return $ticket;
        });
    }

    /**
     * The work is done — board 18's "Mark completed".
     *
     * `closed_at` is what board 17 prints in place of the Age cell and what the
     * average-resolution tile is measured across. It is NOT the same fact as
     * `verified_at`: the estate saying a job is finished and the household
     * agreeing are two statements, and the sixth stage exists because they can
     * differ.
     */
    public function resolve(
        MaintenanceTicket $ticket,
        string $resolution,
        ?User $by = null,
        Carbon|string|null $on = null,
    ): MaintenanceTicket {
        /*
         * Closing it a second time would overwrite the day the work actually finished, the figure the resolution average
         * is measured on, so it is refused: in one line, saying so (DR-029, M72 §1.4), never the machinery.
         */
        if ($ticket->isClosed()) {
            throw new DomainException(sprintf('Ticket #%s is already %s.', $ticket->number, strtolower($ticket->stateLabel())));
        }

        // A resolution tells the resident who reported it what was done, and the next person to hit the same fault.
        if (trim($resolution) === '') {
            throw new DomainException(
                'Say what was done.'
            );
        }

        $when = $this->asMoment($on ?? Carbon::now());

        return DB::connection('tenant')->transaction(function () use ($ticket, $resolution, $by, $when): MaintenanceTicket {
            $from = $ticket->status;

            $ticket->forceFill([
                'status' => MaintenanceTicket::COMPLETED,
                'resolution' => $resolution,
                'closed_at' => $when,
            ])->save();

            $this->record(
                ticket: $ticket,
                event: TicketActivity::RESOLVED,
                stage: MaintenanceTicket::COMPLETED,
                to: MaintenanceTicket::COMPLETED,
                from: $from,
                occurredAt: $when,
                note: $resolution,
                actorName: $by?->name,
                actorId: $by?->getKey(),
            );

            return $ticket;
        });
    }

    /**
     * It was not fixed. Put it back in the queue.
     *
     * THE ORIGINAL CLOSURE STAYS IN THE LOG. A reopen is a new entry, never an
     * edit to the one that said the job was done — the pair is the record that a
     * fault came back, which is the only way anybody sees a vendor whose repairs
     * do not hold.
     *
     * `closed_at` is cleared and `reported_at` is NOT. The SLA still runs from
     * the resident's original report: a household that has waited a fortnight
     * across two visits has waited a fortnight, and restarting the clock on a
     * reopen would reward failing to fix it the first time.
     */
    public function reopen(MaintenanceTicket $ticket, string $reason, ?User $by = null, ?string $residentName = null): MaintenanceTicket
    {
        if (! $ticket->isClosed()) {
            throw new DomainException(sprintf(
                'Ticket %s is %s and is already open. There is nothing to reopen.',
                $ticket->label(),
                $ticket->status,
            ));
        }

        if (trim($reason) === '') {
            throw new DomainException(
                'Say why it is being reopened. A job that was signed off and then was not is the one '.
                'thing a maintenance record has to be able to explain.'
            );
        }

        return DB::connection('tenant')->transaction(function () use ($ticket, $reason, $by, $residentName): MaintenanceTicket {
            $from = $ticket->status;

            $ticket->forceFill([
                'status' => $ticket->assigned_vendor_id === null
                    ? MaintenanceTicket::ACKNOWLEDGED
                    : MaintenanceTicket::ASSIGNED,
                'closed_at' => null,
                'verified_at' => null,
                'verified_by_name' => null,
            ])->save();

            // The household reopening its own request from the app ("Still a problem", 26 Sep) is named as itself.
            $this->record(
                ticket: $ticket,
                event: TicketActivity::REOPENED,
                to: $ticket->status,
                from: $from,
                occurredAt: Carbon::now(),
                note: $reason,
                actorKind: $residentName !== null ? TicketActivity::RESIDENT : TicketActivity::STAFF,
                actorName: $residentName ?? $by?->name,
                actorId: $residentName !== null ? null : $by?->getKey(),
            );

            return $ticket;
        });
    }

    /** The household says it is fixed — board 18's sixth stage. */
    public function verify(MaintenanceTicket $ticket, string $residentName, Carbon|string|null $on = null): MaintenanceTicket
    {
        if ($ticket->status !== MaintenanceTicket::COMPLETED) {
            throw new DomainException(sprintf(
                'Ticket %s is %s. A resident verifies work the estate has said is finished; there is '.
                'nothing yet for them to agree with.',
                $ticket->label(),
                $ticket->status,
            ));
        }

        $when = $this->asMoment($on ?? Carbon::now());

        return DB::connection('tenant')->transaction(function () use ($ticket, $residentName, $when): MaintenanceTicket {
            $ticket->forceFill([
                'status' => MaintenanceTicket::VERIFIED,
                'verified_at' => $when,
                'verified_by_name' => $residentName,
            ])->save();

            $this->record(
                ticket: $ticket,
                event: TicketActivity::VERIFIED,
                stage: MaintenanceTicket::VERIFIED,
                to: MaintenanceTicket::VERIFIED,
                from: MaintenanceTicket::COMPLETED,
                occurredAt: $when,
                actorKind: TicketActivity::RESIDENT,
                actorName: $residentName,
            );

            return $ticket;
        });
    }

    /* ------------------------------------------------------------------ */
    /* what the screens read */
    /* ------------------------------------------------------------------ */

    /**
     * Board 17 — the four tiles, the chips and the queue.
     *
     * EVERY TILE IS DERIVED, including the overdue count, which is the same
     * arithmetic the rows use rather than a second one over a stored flag.
     *
     * THE TILES ARE THE WHOLE ESTATE'S AND THE TABLE IS A PAGE OF IT (M32 F5).
     * The tiles walk every ticket's status and clock — four columns, no relation —
     * and the queue below is the chip's tickets, searched, ordered and paged in
     * SQL over all of them: the chip, the search and the overdue test are the
     * walk's own rules, written as SQL (`filterQuery()`).
     *
     * @param  string  $filter  one of the keys of self::FILTERS
     * @return array<string, mixed>
     */
    public function queueBoard(string $filter = 'all', ?Carbon $asAt = null, ?TableQuery $table = null): array
    {
        $now = $asAt?->copy() ?? Carbon::now();
        $table ??= self::queueTable(null, $now);
        $filter = array_key_exists($filter, self::FILTERS) || $filter === self::OVERDUE ? $filter : 'all';
        $tiles = $this->tiles($now);

        $query = MaintenanceTicket::query()->with(['vendor', 'unit']);

        // How many photos the resident attached, for the row's mark (FAC-5): counted inside this read, not a read per row.
        $query->addSelect(['photo_count' => DB::connection('tenant')->table('ticket_media')
            ->selectRaw('COUNT(*)')
            ->whereColumn('ticket_media.maintenance_ticket_id', 'maintenance_tickets.id')]);

        $this->filterQuery($query, $filter, $now);
        $this->searchQuery($query, $table->search);
        $table->order($query, 'maintenance_tickets.id');
        $page = $table->paginate($query);

        $rows = [];

        foreach ($page->getCollection() as $ticket) {
            $state = $ticket->boardStatus($now);

            $rows[] = [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'ticket' => $ticket->label(),
                'title' => $ticket->title,
                // A place naming the ticket's own lot, from the lot's record (FAC-15).
                'location' => $ticket->placeLabel(),
                'priority' => $ticket->priority,
                'priority_label' => MaintenanceTicket::PRIORITY_LABELS[$ticket->priority],
                'status' => $state,
                // The state it is in, and overdue beside it, never instead of it (M68 §2.3, FAC-3).
                'status_label' => $ticket->stateLabel(),
                'is_overdue' => $ticket->isOverdue($now),
                'assignee' => $ticket->assigneeLabel(),
                'age' => $ticket->ageLabel($now),
                // The photos the resident attached, for the row's mark (FAC-5); nought for none.
                'photos' => (int) $ticket->getAttribute('photo_count'),

                /*
                 * The unit's REFERENCE, and never anything else about it. Board
                 * 17 draws "Phase 1 · Lot 9" as a place, and a maintenance
                 * screen has no business carrying what that household owes —
                 * D-010.
                 */
                'unit' => $ticket->unit->reference ?? null,
            ];
        }

        return [
            'kpis' => $tiles['kpis'],
            'filters' => array_map(
                static fn (string $key): array => ['key' => $key, 'label' => self::FILTERS[$key]],
                array_keys(self::FILTERS),
            ),
            'filter' => $filter,

            // The red chip board 17 pushes to the right of the row. The same
            // figure as the tile, from the same count — not a second query that
            // could disagree with it.
            'overdue' => $tiles['overdue'],
            'rows' => $rows,
            'table' => $table->meta($page),
        ];
    }

    /**
     * Board 17's four tiles, and the overdue count its red chip shows: the whole estate's, from one walk of every
     * ticket's status and clock — the columns the arithmetic reads, and no relation. The dashboard reads the same.
     *
     * @return array{kpis: list<array{key: string, value: int|float, label: string}>, overdue: int}
     */
    public function tiles(?Carbon $asAt = null): array
    {
        $now = $asAt?->copy() ?? Carbon::now();
        $open = 0;
        $inProgress = 0;
        $overdue = 0;
        $resolvedHours = [];

        foreach (MaintenanceTicket::query()->get(['id', 'status', 'reported_at', 'sla_hours', 'closed_at', 'category']) as $ticket) {
            // A resident's security report is not a maintenance job, and none of these four counts it (FAC-4).
            if ($ticket->isSecurityReport()) {
                continue;
            }

            // The groups the chips filter by (M68 §2.3, FAC-2), and overdue as the flag across both open groups.
            if (in_array($ticket->status, MaintenanceTicket::GROUPS['open'], true)) {
                $open++;
            }

            if (in_array($ticket->status, MaintenanceTicket::GROUPS['in_progress'], true)) {
                $inProgress++;
            }

            if ($ticket->isOverdue($now)) {
                $overdue++;
            }

            if ($ticket->closed_at !== null && $ticket->isClosed()) {
                $resolvedHours[] = (float) $ticket->reported_at->diffInHours($ticket->closed_at, absolute: true);
            }
        }

        return [
            'kpis' => [
                ['key' => 'open', 'value' => $open, 'label' => 'Open tickets'],
                ['key' => 'in_progress', 'value' => $inProgress, 'label' => 'In progress'],
                ['key' => 'overdue', 'value' => $overdue, 'label' => 'Overdue'],

                /*
                 * A duration, not a count, and the page prints it with the unit
                 * word — "3.2 days". One decimal place because that is what the
                 * board draws and because a second one would suggest an accuracy
                 * an average over a dozen jobs does not have.
                 */
                ['key' => 'avg_resolution', 'value' => $this->averageResolutionDays($resolvedHours), 'label' => 'Avg resolution'],
            ],
            'overdue' => $overdue,
        ];
    }

    /**
     * What the queue orders by (M32 F5). The ticket number is the default, newest first. Priority by its ladder, low to
     * high ascending, so descending is the most urgent first — as an incident's severity sorts in both consoles; status
     * by board 17's badge — overdue, submitted, in progress, completed, cancelled — from the same rule as
     * `boardStatus()`, written in SQL; age youngest first, ascending.
     *
     * @return array<string, string|\Closure>
     */
    public static function queueSorts(?Carbon $asAt = null): array
    {
        $now = ($asAt?->copy() ?? Carbon::now())->utc()->toDateTimeString();
        $flip = static fn (string $dir): string => $dir === 'asc' ? 'desc' : 'asc';

        return [
            'number' => 'maintenance_tickets.number',
            'location' => 'maintenance_tickets.location_label',
            'priority' => static fn ($query, string $dir) => $query->orderByRaw("FIELD(maintenance_tickets.priority, 'low', 'medium', 'high') ".$dir),
            'status' => static fn ($query, string $dir) => $query->orderByRaw(
                'CASE WHEN maintenance_tickets.status = ? THEN 5 WHEN maintenance_tickets.status IN (?, ?) THEN 4 '
                .'WHEN DATE_ADD(maintenance_tickets.reported_at, INTERVAL maintenance_tickets.sla_hours HOUR) < ? THEN 1 '
                .'WHEN maintenance_tickets.status IN (?, ?) THEN 2 ELSE 3 END '.$dir,
                [MaintenanceTicket::CANCELLED, MaintenanceTicket::COMPLETED, MaintenanceTicket::VERIFIED, $now, MaintenanceTicket::SUBMITTED, MaintenanceTicket::ACKNOWLEDGED],
            ),
            'assignee' => static fn ($query, string $dir) => $query->orderByRaw('(SELECT vendors.name FROM vendors WHERE vendors.id = maintenance_tickets.assigned_vendor_id) '.$dir),
            'age' => static fn ($query, string $dir) => $query->orderBy('maintenance_tickets.reported_at', $flip($dir)),
        ];
    }

    /** The queue as its address asks for it (M32 F5): newest ticket first. */
    public static function queueTable(?Request $request = null, ?Carbon $asAt = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::queueSorts($asAt), 'number', 'desc');
    }

    /**
     * Which tickets are under one of board 17's chips, in SQL: the groups the tiles count (`MaintenanceTicket::GROUPS`,
     * M68 §2.3), so a card and its chip agree and no ticket is under two. Overdue is a flag, never a group: an overdue
     * ticket is under the chip of the state it is in.
     *
     * @param  Builder<MaintenanceTicket>  $query
     */
    private function filterQuery(Builder $query, string $filter, Carbon $now): void
    {
        /*
         * A SECURITY REPORT IS NOT A MAINTENANCE JOB (FAC-4). The Resident App files one as a ticket with the category
         * "security"; it sat among the jobs, given to a plumber and overdue as plumbing. Its own chip holds it, and every
         * other chip, the default view included, leaves it out.
         */
        if ($filter === self::SECURITY_REPORTS) {
            $query->whereRaw('LOWER(maintenance_tickets.category) = ?', [MaintenanceTicket::SECURITY_CATEGORY]);

            return;
        }

        $query->where(static fn ($jobs) => $jobs->whereNull('maintenance_tickets.category')
            ->orWhereRaw('LOWER(maintenance_tickets.category) <> ?', [MaintenanceTicket::SECURITY_CATEGORY]));

        match ($filter) {
            'open', 'in_progress', 'completed' => $query->whereIn('maintenance_tickets.status', MaintenanceTicket::GROUPS[$filter]),

            // The red badge (FAC-1): `isOverdue()` in SQL, an open ticket past its target, compared in UTC as stored.
            self::OVERDUE => $query->whereNotIn('maintenance_tickets.status', [MaintenanceTicket::COMPLETED, MaintenanceTicket::VERIFIED, MaintenanceTicket::CANCELLED])
                ->whereRaw('DATE_ADD(maintenance_tickets.reported_at, INTERVAL maintenance_tickets.sla_hours HOUR) < ?', [$now->copy()->utc()->toDateTimeString()]),
            default => null,
        };
    }

    /**
     * The queue's search (M32 F5): the ticket's number, its title, where it is, the lot, who reported it and who is
     * assigned it — what the row prints, and the reporter the ticket names.
     *
     * @param  Builder<MaintenanceTicket>  $query
     */
    private function searchQuery(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $number = ltrim($search, '#');

        $query->where(static function (Builder $q) use ($like, $number): void {
            $q->where('maintenance_tickets.title', 'like', $like)
                ->orWhere('maintenance_tickets.location_label', 'like', $like)
                ->orWhere('maintenance_tickets.reported_by_name', 'like', $like)
                ->orWhereHas('unit', static fn (Builder $unit) => $unit->where('reference', 'like', $like))
                ->orWhereHas('vendor', static fn (Builder $vendor) => $vendor->where('name', 'like', $like));

            if (ctype_digit($number)) {
                $q->orWhere('maintenance_tickets.number', (int) $number);
            }
        });
    }

    /**
     * Board 18 — one ticket, its timeline and its vendor.
     *
     * @return array<string, mixed>
     */
    public function ticketBoard(MaintenanceTicket $ticket, ?Carbon $asAt = null): array
    {
        $now = $asAt?->copy() ?? Carbon::now();
        $state = $ticket->boardStatus($now);

        return [
            'ticket' => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'heading' => 'Ticket '.$ticket->label(),
                'summary_title' => $ticket->summaryTitle(),
                'title' => $ticket->title,
                'location' => $ticket->placeLabel(),
                'category' => $ticket->category,
                'description' => $ticket->description,
                'priority' => $ticket->priority,
                'priority_label' => MaintenanceTicket::PRIORITY_LABELS[$ticket->priority],
                'status' => $state,
                'status_label' => $ticket->stateLabel(),

                /*
                 * "Submitted by Andrea Fletcher · Lot 47 · Sep 2, 6:40 PM ·
                 * Category: Electrical" — composed here so the page prints one
                 * string, and composed of only the parts that exist.
                 */
                'meta' => implode(' · ', array_filter([
                    $ticket->reported_by_name === null ? null : 'Submitted by '.$ticket->reported_by_name,
                    $ticket->unit->reference ?? null,
                    SiteTime::fmt(SiteTime::local($ticket->reported_at), 'M j, g:i A'),
                    // In its one word, however it was stored (FAC-6).
                    $ticket->category === null ? null : 'Category: '.MaintenanceTicket::categoryLabel($ticket->category),
                ])),
                /*
                 * "Entry with nobody home: allowed · Best time: Weekday mornings" —
                 * the two named fields the Resident App's form asks for (M4 §3.5),
                 * composed here out of only the parts this ticket has. Null on a
                 * ticket that did not say, which board 18 then does not draw: a
                 * request that said nothing about entry is not a request that said no.
                 */
                'access' => self::accessLine($ticket),
                'reported_at' => SiteTime::fmt(SiteTime::local($ticket->reported_at), 'M j, g:i A'),
                'age' => $ticket->ageLabel($now),
                'is_overdue' => $ticket->isOverdue($now),
                'sla_due' => SiteTime::fmt(SiteTime::local($ticket->slaDueAt()), 'M j, g:i A'),
                'resolution' => $ticket->resolution,
                'closed_at' => $ticket->closed_at?->format('M j, Y'),
                'is_closed' => $ticket->isClosed(),
                // Which of the spine's two middle stages the office can stamp from here (FAC-10).
                'can_acknowledge' => $ticket->status === MaintenanceTicket::SUBMITTED,
                'can_start' => ! $ticket->isClosed() && $ticket->status !== MaintenanceTicket::IN_PROGRESS,
                // Who a message here reaches, named (batch 1 item 1); null on a job nobody at a lot reported.
                'message_to' => $ticket->unit_id === null ? null : $this->messageRecipients($ticket),
            ],
            'timeline' => $this->timeline($ticket),
            'vendor' => $this->vendorPanel($ticket),

            /*
             * The ticket's thread (M68 §2.1, FAC-7): every line of its history, oldest first, the answer to "why has
             * this taken three weeks" that the six fixed stages cannot hold. Each line is worded here (what happened,
             * the words, who and when), so the page prints it and composes nothing.
             */
            'history' => $ticket->activity()
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->map(static fn (TicketActivity $entry): array => [
                    'id' => $entry->id,
                    'event' => $entry->event,
                    'kind' => $entry->actor_kind,
                    'note' => $entry->note,
                    'line' => $entry->timelineLine(),
                    'what' => $entry->what(),
                    'words' => $entry->words(),
                    'who' => $entry->who(),
                    'when' => $entry->stamp(),
                ])
                ->all(),
        ];
    }

    /**
     * Board 18's six stages, in lifecycle order and never in timestamp order.
     *
     * Each stage is drawn whether or not it has happened: done where an entry
     * exists, active on the first one that has not, pending after that. The
     * board greys the pending ones and draws no timestamp against them, which is
     * why a stage with no entry carries a null line rather than an empty string.
     *
     * @return list<array{key: string, label: string, state: string, line: string|null, note: string|null}>
     */
    public function timeline(MaintenanceTicket $ticket): array
    {
        /** @var array<string, TicketActivity> $entries */
        $entries = [];
        $standing = null;

        $stages = array_keys(MaintenanceTicket::STAGES);

        foreach ($ticket->activity()->orderBy('occurred_at')->orderBy('id')->get() as $entry) {
            // The assignment that stands: the latest one that put a different vendor on the job.
            if ($entry->event === TicketActivity::ASSIGNED || ($entry->event === TicketActivity::REASSIGNED && ! $entry->isSameVendorAgain())) {
                $standing = $entry;
            }

            /*
             * THE STRIP FOLLOWS A REOPEN (FAC-9). A reopened job goes back to the stage it reopened to, and every stage
             * after that is to be reached again: the first closure and its note are in the thread, not lit on the
             * spine. The stage it went back to, if it had never been stamped, is stamped by the reopen, with its reason.
             */
            if ($entry->event === TicketActivity::REOPENED) {
                $back = array_search((string) $entry->to_status, $stages, true);

                if ($back !== false) {
                    foreach (array_slice($stages, $back + 1) as $later) {
                        unset($entries[$later]);
                    }

                    $entries[$stages[$back]] ??= $entry;
                }

                continue;
            }

            if ($entry->stage === null) {
                continue;
            }

            // FIRST ENTRY WINS PER STAGE: when the ticket first reached it. Later entries are in `history`.
            $entries[(string) $entry->stage] ??= $entry;
        }

        $rows = [];

        foreach (MaintenanceTicket::STAGES as $key => $label) {
            $entry = $entries[$key] ?? null;

            /*
             * THE VENDOR SHOWN IS THE VENDOR CHOSEN (M68 §2.2, FAC-8). The assigned stage names the assignment that
             * stands, with who it was taken from, so it never contradicts the vendor card; the first assignment and
             * each one after it are in the thread.
             */
            $shown = $key === MaintenanceTicket::ASSIGNED && $entry !== null && $standing !== null ? $standing : $entry;

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'state' => $entry !== null ? 'done' : 'pending',
                'line' => $shown?->timelineLine(),
                'note' => $shown?->note,
            ];
        }

        /*
         * The furthest stage reached is ACTIVE while the ticket is still open.
         *
         * Board 18 draws "In progress" with the amber marker and its note
         * beneath, and that stage HAS an entry — so "the first stage with no
         * entry" would put the marker one row too low, on a stage nothing has
         * happened at. A closed ticket has no active stage at all: every stage
         * it reached is done, and the remaining ones stay pending.
         */
        if ($ticket->isOpen()) {
            for ($i = count($rows) - 1; $i >= 0; $i--) {
                if ($rows[$i]['state'] === 'done') {
                    $rows[$i]['state'] = 'active';

                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * Board 18's vendor panel, or null where nobody is assigned.
     *
     * @return array<string, mixed>|null
     */
    public function vendorPanel(MaintenanceTicket $ticket): ?array
    {
        $vendor = $ticket->vendor;

        if ($vendor === null) {
            return null;
        }

        /*
         * "Tech: Owen Grant · (876) 555 0110 · ETA today 2:00–4:00 PM" — and
         * only the parts that exist. A vendor with no named technician and no
         * window still has a name and a phone number, and a line reading
         * "Tech:  ·  · ETA" is a broken panel rather than a missing detail.
         */
        $detail = array_filter([
            $ticket->technician_name === null ? null : 'Tech: '.$ticket->technician_name,
            $ticket->technician_phone ?? $vendor->contact_phone,
            $this->etaLabel($ticket),
        ]);

        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'initials' => $vendor->initials(),
            'detail' => implode(' · ', $detail),
            'technician' => $ticket->technician_name,
            'phone' => $ticket->technician_phone ?? $vendor->contact_phone,

            // The visit as it stands, which the form starts from when this vendor is chosen again (M68 §2.2, FAC-8).
            'technician_phone' => $ticket->technician_phone,
            'eta_starts_local' => $ticket->eta_starts_at === null ? null : SiteTime::local($ticket->eta_starts_at)->format('Y-m-d\TH:i'),
            'eta_ends_local' => $ticket->eta_ends_at === null ? null : SiteTime::local($ticket->eta_ends_at)->format('Y-m-d\TH:i'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * What the household said about letting somebody in, and when — or null where
     * they said neither.
     *
     * `access_permitted` is THREE-VALUED: null is "the request did not say" and is
     * not printed at all, because a work order raised in the office has no opinion
     * on entry and printing "not allowed" against it would invent one.
     */
    private static function accessLine(MaintenanceTicket $ticket): ?string
    {
        $parts = array_filter([
            // "yes"/"no" and not "allowed": `EstateFacilitiesMaintenanceTest` reads this
            // payload for the word "owed" — which "allowed" contains — and it is right to.
            $ticket->access_permitted === null
                ? null
                : 'Entry with nobody home: '.($ticket->access_permitted ? 'yes' : 'no'),
            ($ticket->preferred_time ?? '') === '' ? null : 'Best time: '.$ticket->preferred_time,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** "ETA today 2:00–4:00 PM", or nothing where no window was given. */
    private function etaLabel(MaintenanceTicket $ticket): ?string
    {
        $from = $ticket->eta_starts_at;

        if ($from === null) {
            return null;
        }

        // "today" where it is today, and the date where it is not — a window a
        // resident reads as "today" on the wrong day is worse than a date.
        $day = $from->isToday() ? 'today' : $from->format('M j');

        $to = $ticket->eta_ends_at;

        // The weekday with a date (M19A §4.4), and the clock once at the end (§4.2).
        $day = $from->isToday() ? 'today' : $from->format('D, M j');

        return 'ETA '.$day.' '.($to === null ? SiteTime::fmt($from, 'g:i A') : $from->format('g:i A').'–'.SiteTime::fmt($to, 'g:i A'));
    }

    /**
     * The mean time to close, in days to one decimal place.
     *
     * @param  list<float>  $hours
     */
    private function averageResolutionDays(array $hours): float
    {
        if ($hours === []) {
            return 0.0;
        }

        return round(array_sum($hours) / count($hours) / 24, 1);
    }

    /**
     * Move the status and write the entry, in one transaction.
     */
    private function transition(
        MaintenanceTicket $ticket,
        string $to,
        string $event,
        ?string $stage = null,
        Carbon|string|null $occurredAt = null,
        ?string $note = null,
        string $actorKind = TicketActivity::STAFF,
        ?string $actorName = null,
        ?int $actorId = null,
    ): MaintenanceTicket {
        $when = $this->asMoment($occurredAt ?? Carbon::now());

        return DB::connection('tenant')->transaction(function () use (
            $ticket, $to, $event, $stage, $when, $note, $actorKind, $actorName, $actorId
        ): MaintenanceTicket {
            $from = $ticket->status;

            $ticket->forceFill(['status' => $to])->save();

            $this->record(
                ticket: $ticket,
                event: $event,
                stage: $stage,
                to: $to,
                from: $from,
                occurredAt: $when,
                note: $note,
                actorKind: $actorKind,
                actorName: $actorName,
                actorId: $actorId,
            );

            return $ticket;
        });
    }

    /**
     * A MESSAGE TO THE RESIDENT, ON THE TICKET'S OWN THREAD (the user, 8 Oct 2026, M53 §4.10: "one thread per ticket;
     * staff and resident both post into it"). A note in the history under this person's name, which the Resident App
     * draws under the status timeline beside the household's own comments (`tickets.comment`), and a notification to the
     * household. It needs no SMS: the resident has the app, with this request open in it. Kept when the ticket closes,
     * like every line of its history.
     */
    public function messageResident(MaintenanceTicket $ticket, string $body, User $by): TicketActivity
    {
        $body = trim($body);

        if ($body === '') {
            throw new DomainException('Write the message first.');
        }

        if ($ticket->unit_id === null) {
            throw new DomainException('Only a request a resident reported has a resident to message.');
        }

        // No status, like the household's own comment: a message moves nothing, so the app never reads it as a stage.
        $entry = $this->record(
            ticket: $ticket,
            event: TicketActivity::NOTE,
            note: mb_substr($body, 0, 500),
            actorName: $by->name,
            actorId: $by->getKey(),
            // Who it went to, as they are now: the history keeps them when the household changes (batch 1 item 1).
            recipients: $this->messageRecipients($ticket)['names'],
        );

        app(PushAnnouncer::class)->ticketMessage($ticket);

        return $entry;
    }

    /**
     * WHO READS A MESSAGE ON THIS TICKET (Hassam, batch 1 item 1, 12 Oct: "Nobody can tell who receives it"). The same
     * people the notification reaches (`PushAnnouncer::ticketMessage`, `PushAudience`): every active Resident App account
     * on the ticket's lot whose access level reaches requests. The app shows the request to each of them, the thread with
     * it. The person who raised it, when one of them, is named first and said to be.
     *
     * @return array{names: list<string>, line: string}
     */
    public function messageRecipients(MaintenanceTicket $ticket): array
    {
        $tenant = (string) tenant()?->getTenantKey();
        $lot = $ticket->unit->reference ?? 'this lot';

        $accounts = $ticket->unit_id === null || $tenant === '' ? collect() : ResidentAccount::query()
            ->where('tenant_id', $tenant)
            ->where('status', ResidentAccount::ACTIVE)
            ->where('unit_id', $ticket->unit_id)
            ->orderBy('id')
            ->get(['id', 'full_name']);

        $reaching = array_flip(app(PushAudience::class)->allowed($tenant, 'ticket_update', $accounts->pluck('id')->map(static fn ($id): int => (int) $id)->all()));
        $names = $accounts->filter(static fn (ResidentAccount $a): bool => isset($reaching[(int) $a->id]))
            ->map(static fn (ResidentAccount $a): string => trim((string) $a->full_name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();

        // The person who raised it first, and said to be: by name, as the request recorded them.
        $raiser = trim((string) $ticket->reported_by_name);
        $isRaiser = static fn (string $name): bool => $raiser !== '' && mb_strtolower($name) === mb_strtolower($raiser);
        usort($names, static fn (string $a, string $b): int => (int) $isRaiser($b) <=> (int) $isRaiser($a));

        if ($names === []) {
            return ['names' => [], 'line' => 'Nobody at '.$lot.' reads requests in the Resident App, so a message would reach nobody.'];
        }

        $shown = array_map(static fn (string $name): string => $isRaiser($name) ? $name.' (who raised it)' : $name, $names);
        $line = 'To '.TicketActivity::listed($shown).', on this request in the Resident App, with a notification.';

        if ($raiser !== '' && ! in_array(true, array_map($isRaiser, $names), true)) {
            $line .= ' '.$raiser.', who raised it, does not read requests in the app.';
        }

        return ['names' => $names, 'line' => $line];
    }

    /**
     * Write one line of the ticket's history.
     *
     * PUBLIC, because a seeder builds board 18's timeline as it happened rather
     * than by replaying six method calls against a clock it does not control —
     * and because a state change recorded any other way would be one the
     * resident cannot follow.
     *
     * @param  list<string>|null  $recipients  who a message went to, by name (batch 1 item 1)
     */
    public function record(
        MaintenanceTicket $ticket,
        string $event,
        ?string $stage = null,
        ?string $to = null,
        ?string $from = null,
        Carbon|string|null $occurredAt = null,
        ?string $note = null,
        string $actorKind = TicketActivity::STAFF,
        ?string $actorName = null,
        ?int $actorId = null,
        ?array $recipients = null,
    ): TicketActivity {
        return TicketActivity::create([
            'maintenance_ticket_id' => $ticket->id,
            'event' => $event,
            'stage' => $stage,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'actor_kind' => $actorKind,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'occurred_at' => $this->asMoment($occurredAt ?? Carbon::now()),
            ...($recipients === null ? [] : ['recipients' => $recipients]),
        ]);
    }

    /**
     * The next ticket number, under a lock.
     *
     * Sequential with gaps, which is what board 17's own list shows: 1042, 1041,
     * 1039, 1037, 1031. Numbers are never reused — two people looking at the
     * same number have to be looking at the same job.
     */
    private function nextNumber(): int
    {
        // Past every number the unique index holds, a retired sample ticket's too (M33 §5).
        $last = (int) Retirements::seeingEverything(static fn (): mixed => MaintenanceTicket::query()->lockForUpdate()->max('number'));

        return max($last, self::FIRST_NUMBER) + 1;
    }

    private function guardPriority(string $priority): void
    {
        if (! array_key_exists($priority, self::SLA_HOURS)) {
            throw new DomainException(sprintf(
                'No such priority [%s]. A ticket is high, medium or low, and each carries its own '.
                'service target measured from the moment it was reported.',
                $priority,
            ));
        }
    }

    /**
     * ONE TIME RULE (M4 §3.1). What the office typed (a visit window, "reported at") is a wall-clock
     * time on the ESTATE'S clock and becomes the UTC moment it means; a moment stays the moment it is.
     */
    private function asMoment(Carbon|string $moment): Carbon
    {
        return SiteTime::parse($moment);
    }
}
