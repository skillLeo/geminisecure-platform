<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\TicketActivity;
use App\Models\Estate\Unit;
use App\Models\Estate\Vendor;
use App\Services\Estate\Amenities;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Phoenix Park's maintenance queue and its booking diary — boards 17 to 20.
 *
 * IT RUNS BEFORE `PayablesSeeder` AND IT HAS TO. Board 27's bills are recorded
 * against tickets #1042, #1041, #1037 and #1031 by number, and `bills.ticket_id`
 * is now a foreign key to `maintenance_tickets.number` — so the tickets have to
 * exist before the bills that name them. The supplier register is the payables
 * seeder's, and this one calls that step directly rather than keeping a second
 * copy of five TRNs.
 *
 * IT POSTS NOTHING TO THE LEDGER, AND THAT IS DELIBERATE. An amenity fee that is
 * charged goes through `Dues::charge` like any other charge — but charging these
 * four seeded bookings would add J$17,500 to a receivable that boards 2, 5, 6
 * and 25 all state as J$1,840,000, and would break `EstateFinanceSeeder`'s own
 * idempotency guard, which recognises a finished estate by that figure. Board 19
 * draws no fee state against a booking either; its only money column is the
 * deposit. So the diary is seeded as the board draws it and the charging path is
 * exercised where it belongs, in `EstateFacilitiesAmenitiesTest`.
 *
 * WHY THERE ARE SEVENTEEN TICKETS AND THE BOARD DRAWS FIVE. Board 17's tiles say
 * 12 open, 7 in progress, 2 overdue and 3.2 days average resolution, and every
 * one of those is DERIVED — the brief says so, and the five rows drawn cannot
 * reach any of them. Seeding only the drawn five would leave four figures on a
 * reviewed screen unreachable, which is the same argument that makes the estate
 * 450 units rather than four. The twelve extra tickets are numbered BELOW 1031
 * so that the queue, sorted descending by number as the board sorts it, still
 * opens with exactly the five rows the board draws.
 *
 * THE DATES ARE RELATIVE AND THE BOARD'S ARE ABSOLUTE. An age of "2 days" and a
 * status of "Overdue" are the load-bearing figures on board 17, and both are
 * arithmetic against today — a ticket seeded on a fixed date in September 2026
 * would read as months overdue by the time anybody opened the screen. So each
 * ticket is placed by its distance from today, chosen to reproduce the board's
 * own ages, and the drawn literal dates ("Closed Sep 2") follow from that rather
 * than being written down. Board 19's bookings are placed the same way, at the
 * offsets that reproduce Sep 20, 21 and 27 when this is seeded in early
 * September.
 *
 * CONTENT RESIDUALS, recorded rather than resolved by invention:
 *   - Board 17 writes "Gate2_Contractor Ltd" with an underscore and board 26
 *     registers the same supplier as "Gate2 Contractor Ltd". The register wins;
 *     one estate cannot have two of the same vendor.
 *   - Board 19 dates three bookings "Sat, Sep 20", "Sun, Sep 21" and "Sat, Sep
 *     27". Those weekdays cannot all be true of one calendar. The DATES are
 *     seeded and the weekday is derived, so the screen is never internally
 *     wrong.
 *   - The Build Spec names a cancellation rule and a booking window on every
 *     amenity and board 20 draws neither. Both are left unset rather than
 *     invented; the columns exist because a booking snapshots the cancellation
 *     rule, and an estate sets it on the edit screen.
 */
class FacilitiesSeeder extends Seeder
{
    /**
     * The five tickets board 17 draws, verbatim, in its own order.
     *
     * `ago` is hours before now, which is what produces the board's Age column;
     * `closed` is hours before now for a completed one. `stages` is present only
     * where a board gives the timeline explicitly — board 18 does, for #1042.
     *
     * @var list<array<string, mixed>>
     */
    private const DRAWN = [
        [
            'number' => 1042,
            'title' => 'Gate lighting',
            'location' => 'Phase 2 · visitor parking',
            'unit' => 'Lot 47',
            'category' => 'Electrical',
            'priority' => MaintenanceTicket::MEDIUM,
            'status' => MaintenanceTicket::IN_PROGRESS,
            'ago' => 48,
            'reporter' => 'Andrea Fletcher',
            'description' => 'The gate light dem nuh work since Tuesday, it dark by the visitor parking at night.',
            'vendor' => 'Island Electric Services',
            'technician' => 'Owen Grant',
            'phone' => '(876) 555 0110',
            'eta' => [14, 16],
            'closed' => null,
            'resolution' => null,
        ],
        [
            'number' => 1041,
            'title' => 'Pool filter fault',
            'location' => 'Community Centre',
            'unit' => null,
            'category' => 'Plumbing',
            'priority' => MaintenanceTicket::HIGH,

            /*
             * ASSIGNED, not in progress. Board 17 draws its badge as "Overdue",
             * which is not a lifecycle state at all — it is a high-priority
             * ticket six days past a one-day target, and the badge is derived.
             * The stored status says what has actually happened: a vendor was
             * named and nobody has started.
             */
            'status' => MaintenanceTicket::ASSIGNED,
            'ago' => 144,
            'reporter' => null,
            'description' => null,
            'vendor' => 'AquaTech Pool Services',
            'technician' => null,
            'phone' => null,
            'eta' => null,
            'closed' => null,
            'resolution' => null,
        ],
        [
            'number' => 1039,
            'title' => 'Leaking pipe',
            'location' => 'Phase 1 · Lot 9',
            'unit' => 'Lot 9',
            'category' => 'Plumbing',
            'priority' => MaintenanceTicket::HIGH,
            'status' => MaintenanceTicket::SUBMITTED,
            'ago' => 4,
            'reporter' => 'Ricardo Hall',
            'description' => null,

            // "Unassigned" on the board, and the reason this row matters: four
            // hours into a one-day target it is not yet overdue, and the same
            // ticket left alone until tomorrow will be — without anybody
            // assigning it to anyone.
            'vendor' => null,
            'technician' => null,
            'phone' => null,
            'eta' => null,
            'closed' => null,
            'resolution' => null,
        ],
        [
            'number' => 1037,
            'title' => 'Broken gym equipment',
            'location' => 'Club House',
            'unit' => null,
            'category' => 'Equipment',
            'priority' => MaintenanceTicket::LOW,
            'status' => MaintenanceTicket::IN_PROGRESS,
            'ago' => 72,
            'reporter' => null,
            'description' => null,
            'vendor' => 'FitFix Equipment Repair',
            'technician' => null,
            'phone' => null,
            'eta' => null,
            'closed' => null,
            'resolution' => null,
        ],
        [
            'number' => 1031,
            'title' => 'Barrier arm sensor',
            'location' => 'Main Gate',
            'unit' => null,
            'category' => 'Access control',
            'priority' => MaintenanceTicket::MEDIUM,
            'status' => MaintenanceTicket::COMPLETED,
            'ago' => 120,
            'reporter' => null,
            'description' => null,
            'vendor' => 'Gate2 Contractor Ltd',
            'technician' => null,
            'phone' => null,
            'eta' => null,
            'closed' => 48,
            'resolution' => 'Sensor replaced and the barrier re-calibrated.',
        ],
    ];

    /**
     * The queue behind the tiles — the tickets board 17 counts and does not draw.
     *
     * Numbered below 1031 so the descending list still opens with the five the
     * board draws. Together with those five they produce exactly 12 open, 7 in
     * progress, 2 overdue and a 3.2 day average resolution.
     *
     * @var list<array<string, mixed>>
     */
    private const QUEUE = [
        ['number' => 1030, 'title' => 'Corridor light out', 'location' => 'Phase 3 · block C', 'category' => 'Electrical', 'priority' => MaintenanceTicket::LOW, 'status' => MaintenanceTicket::IN_PROGRESS, 'ago' => 48, 'closed' => null],
        ['number' => 1029, 'title' => 'Blocked drain', 'location' => 'Phase 1 · Lot 22', 'category' => 'Plumbing', 'priority' => MaintenanceTicket::MEDIUM, 'status' => MaintenanceTicket::IN_PROGRESS, 'ago' => 24, 'closed' => null],

        // High priority, three days old: the second of board 17's two overdue.
        ['number' => 1028, 'title' => 'Intercom fault', 'location' => 'Main Gate', 'category' => 'Access control', 'priority' => MaintenanceTicket::HIGH, 'status' => MaintenanceTicket::ASSIGNED, 'ago' => 72, 'closed' => null],

        ['number' => 1027, 'title' => 'Playground swing seat', 'location' => 'Community Centre', 'category' => 'Grounds', 'priority' => MaintenanceTicket::LOW, 'status' => MaintenanceTicket::IN_PROGRESS, 'ago' => 96, 'closed' => null],
        ['number' => 1026, 'title' => 'Perimeter fence panel', 'location' => 'Phase 4 · perimeter', 'category' => 'Grounds', 'priority' => MaintenanceTicket::MEDIUM, 'status' => MaintenanceTicket::IN_PROGRESS, 'ago' => 48, 'closed' => null],
        ['number' => 1025, 'title' => 'Pump room leak', 'location' => 'Pool Deck', 'category' => 'Plumbing', 'priority' => MaintenanceTicket::MEDIUM, 'status' => MaintenanceTicket::IN_PROGRESS, 'ago' => 24, 'closed' => null],
        ['number' => 1024, 'title' => 'Street light flickering', 'location' => 'Phase 5 · Drive', 'category' => 'Electrical', 'priority' => MaintenanceTicket::LOW, 'status' => MaintenanceTicket::SUBMITTED, 'ago' => 6, 'closed' => null],
        ['number' => 1023, 'title' => 'Garbage skip overflowing', 'location' => 'Phase 2 · bin store', 'category' => 'Grounds', 'priority' => MaintenanceTicket::MEDIUM, 'status' => MaintenanceTicket::SUBMITTED, 'ago' => 10, 'closed' => null],

        /*
         * The four completed tickets the average is measured over, with #1031's
         * three days: 3 + 2 + 3 + 4 + 4 over five jobs is 3.2 days, which is the
         * figure board 17 prints and not one this seeder writes anywhere.
         */
        ['number' => 1022, 'title' => 'Car park bollard', 'location' => 'Phase 2 · visitor parking', 'category' => 'Grounds', 'priority' => MaintenanceTicket::LOW, 'status' => MaintenanceTicket::COMPLETED, 'ago' => 144, 'closed' => 96],
        ['number' => 1021, 'title' => 'Club House air conditioning', 'location' => 'Club House', 'category' => 'Equipment', 'priority' => MaintenanceTicket::MEDIUM, 'status' => MaintenanceTicket::COMPLETED, 'ago' => 216, 'closed' => 144],
        ['number' => 1020, 'title' => 'Gate motor service', 'location' => 'Main Gate', 'category' => 'Access control', 'priority' => MaintenanceTicket::MEDIUM, 'status' => MaintenanceTicket::COMPLETED, 'ago' => 288, 'closed' => 192],
        ['number' => 1019, 'title' => 'Water tank float valve', 'location' => 'Phase 4 · pump house', 'category' => 'Plumbing', 'priority' => MaintenanceTicket::MEDIUM, 'status' => MaintenanceTicket::COMPLETED, 'ago' => 336, 'closed' => 240],
    ];

    /**
     * What a ticket row leaves unsaid, and what unsaid means.
     *
     * Every one of these is null rather than absent on purpose: a ticket with no
     * named reporter was raised by the office, not by a household, and a ticket
     * with no ETA has not been scheduled. Both are facts about the ticket. This
     * is the shape `seedTickets` merges each row over.
     *
     * @var array<string, mixed>
     */
    private const UNSTATED = [
        'unit' => null,
        'category' => null,
        'description' => null,
        'reporter' => null,
        'vendor' => null,
        'technician' => null,
        'phone' => null,
        'eta' => null,
        'closed' => null,
        'resolution' => null,
    ];

    /**
     * Board 20's four cards, figure for figure, in the order it draws them.
     *
     * @var list<array{name: string, icon: string, capacity: int, fee: int|null, deposit: int|null, opens: string, closes: string}>
     */
    private const AMENITIES = [
        ['name' => 'Gazebo', 'icon' => 'gazebo', 'capacity' => 25, 'fee' => 3_000_00, 'deposit' => 5_000_00, 'opens' => '08:00:00', 'closes' => '22:00:00'],
        ['name' => 'Club House', 'icon' => 'clubhouse', 'capacity' => 60, 'fee' => 4_500_00, 'deposit' => 6_000_00, 'opens' => '09:00:00', 'closes' => '23:00:00'],

        // "9:00 AM – Midnight". Stored as the twenty-fourth hour, because
        // 00:00:00 would make the amenity closed all day.
        ['name' => 'Community Centre', 'icon' => 'pavilion', 'capacity' => 120, 'fee' => 8_000_00, 'deposit' => 10_000_00, 'opens' => '09:00:00', 'closes' => '24:00:00'],

        // "Free for residents" and "None" — null on both, because neither is an
        // amount and a zero would post a journal line for nothing.
        ['name' => 'Pool Deck', 'icon' => 'pool', 'capacity' => 40, 'fee' => null, 'deposit' => null, 'opens' => '06:00:00', 'closes' => '21:00:00'],
    ];

    /**
     * Board 19's four bookings, verbatim.
     *
     * `days` is the offset from today that reproduces the board's own dates when
     * this is seeded in early September; `refund` likewise, and it is three days
     * after the event exactly as the board draws it.
     *
     * @var list<array<string, mixed>>
     */
    private const BOOKINGS = [
        ['amenity' => 'Gazebo', 'unit' => 'Lot 47', 'resident' => 'Andrea Fletcher', 'days' => 10, 'from' => 11, 'to' => 13, 'status' => AmenityBooking::CONFIRMED, 'deposit' => AmenityBooking::DEPOSIT_HELD, 'refund' => null],
        ['amenity' => 'Club House', 'unit' => 'Lot 88', 'resident' => 'Sonia Campbell', 'days' => 11, 'from' => 15, 'to' => 19, 'status' => AmenityBooking::PENDING, 'deposit' => AmenityBooking::DEPOSIT_AWAITING, 'refund' => null],
        ['amenity' => 'Community Centre', 'unit' => 'Lot 63', 'resident' => 'Keith Walters', 'days' => 17, 'from' => 18, 'to' => 22, 'status' => AmenityBooking::CONFIRMED, 'deposit' => AmenityBooking::DEPOSIT_HELD, 'refund' => null],
        ['amenity' => 'Gazebo', 'unit' => 'Lot 3', 'resident' => 'Rachel Bennett', 'days' => -26, 'from' => 11, 'to' => 13, 'status' => AmenityBooking::COMPLETED, 'deposit' => AmenityBooking::DEPOSIT_REFUNDED, 'refund' => -23],
    ];

    public function run(): void
    {
        /*
         * The supplier register first, called directly out of the payables
         * seeder. Board 17 assigns four of its five suppliers to tickets, and a
         * ticket cannot name a vendor that does not exist — while the bills that
         * name these tickets cannot be recorded until the tickets do. This is
         * the only ordering that satisfies both, and it keeps one copy of the
         * register rather than two.
         */
        $vendors = (new PayablesSeeder)->seedVendors();

        $this->seedTickets($vendors);
        $this->seedAmenities();
        $this->seedBookings();
    }

    /**
     * @param  array<string, Vendor>  $vendors
     */
    private function seedTickets(array $vendors): void
    {
        $now = Carbon::now();
        $units = Unit::query()->pluck('id', 'reference');

        foreach ([...self::DRAWN, ...self::QUEUE] as $row) {
            /*
             * Filled out to the same shape first. The five rows a board draws
             * carry a reporter, a technician and an ETA; the twelve behind the
             * tiles carry none of them, and reaching for a key that a QUEUE row
             * simply does not have is how this seeder first fell over. Normalise
             * once here rather than defending every read downstream.
             */
            $row = [...self::UNSTATED, ...$row];

            $reportedAt = $now->copy()->subHours((int) $row['ago']);
            $priority = (string) $row['priority'];
            $vendorName = $row['vendor'];

            /*
             * Keyed on the NUMBER, and updated rather than skipped. The
             * migration writes a thin recovered ticket for every number a bill
             * already claims — it has to, or the foreign key could not be added
             * over a seeded estate — and this is what puts the board's own
             * detail over the top of it.
             */
            $ticket = MaintenanceTicket::updateOrCreate(
                ['number' => (int) $row['number']],
                [
                    'title' => (string) $row['title'],
                    'location_label' => (string) $row['location'],
                    'unit_id' => $units[$row['unit'] ?? ''] ?? null,
                    'category' => $row['category'],
                    'description' => $row['description'],
                    'priority' => $priority,
                    'status' => (string) $row['status'],
                    'reported_by_name' => $row['reporter'],
                    'reported_at' => $reportedAt,
                    'sla_hours' => Maintenance::SLA_HOURS[$priority],
                    'assigned_vendor_id' => $vendorName === null ? null : $vendors[$vendorName]->id,

                    /*
                     * Assigned some hours after the report, and it changes
                     * nothing about the deadline. #1041 is six days old against
                     * a one-day target and is overdue however recently its
                     * vendor was named — which is the rule this whole module
                     * exists to keep true.
                     */
                    'assigned_at' => $vendorName === null ? null : $reportedAt->copy()->addHours(6),
                    'technician_name' => $row['technician'],
                    'technician_phone' => $row['phone'],
                    'eta_starts_at' => $this->eta($row, 0),
                    'eta_ends_at' => $this->eta($row, 1),
                    'closed_at' => $row['closed'] === null ? null : $now->copy()->subHours((int) $row['closed']),
                    'resolution' => $row['resolution'],
                ],
            );

            /*
             * The history is written once. A second run must not stack a second
             * set of state changes onto a ticket — the log is the resident's
             * record of what happened, and padding it would make it evidence of
             * something that did not.
             */
            if ($ticket->activity()->count() === 0) {
                $this->trail($ticket, $row, $vendorName);
            }
        }
    }

    /**
     * One ticket's state changes, timestamped and attributed.
     *
     * Written through `Maintenance::record()` rather than by replaying the
     * service's own methods, because those stamp the moment they run and this
     * has to place each stage where the board puts it — board 18 dates the
     * acknowledgement to the morning after the report, and no amount of calling
     * `acknowledge()` at seed time produces that.
     *
     * @param  array<string, mixed>  $row
     */
    private function trail(MaintenanceTicket $ticket, array $row, ?string $vendorName): void
    {
        $maintenance = app(Maintenance::class);
        $reported = $ticket->reported_at;

        $maintenance->record(
            ticket: $ticket,
            event: TicketActivity::REPORTED,
            stage: MaintenanceTicket::SUBMITTED,
            to: MaintenanceTicket::SUBMITTED,
            occurredAt: $reported,
            actorKind: $row['reporter'] === null ? TicketActivity::STAFF : TicketActivity::RESIDENT,
            actorName: $row['reporter'] ?? null,
        );

        if ($ticket->status === MaintenanceTicket::SUBMITTED) {
            return;
        }

        /*
         * Board 18's own offsets for #1042 — submitted 6:40 PM, acknowledged
         * 8:15 the next morning, assigned at 11:20 — expressed as hours so they
         * hold whatever day the seed runs on. Thirteen and three quarter hours
         * and sixteen and two thirds are those two gaps; every other ticket takes
         * the same shape at rounder intervals.
         */
        $acknowledged = $reported->copy()->addMinutes($ticket->number === 1042 ? 815 : 240);

        $maintenance->record(
            ticket: $ticket,
            event: TicketActivity::ACKNOWLEDGED,
            stage: MaintenanceTicket::ACKNOWLEDGED,
            from: MaintenanceTicket::SUBMITTED,
            to: MaintenanceTicket::ACKNOWLEDGED,
            occurredAt: $acknowledged,
            actorName: 'Patricia Morgan',
        );

        if ($vendorName !== null) {
            $maintenance->record(
                ticket: $ticket,
                event: TicketActivity::ASSIGNED,
                stage: MaintenanceTicket::ASSIGNED,
                from: MaintenanceTicket::ACKNOWLEDGED,
                to: MaintenanceTicket::ASSIGNED,
                occurredAt: $reported->copy()->addMinutes($ticket->number === 1042 ? 1000 : 360),

                // Attributed to the supplier, which is what board 18 draws
                // against this stage — not to the manager who typed it.
                actorKind: TicketActivity::VENDOR,
                actorName: $vendorName,
            );
        }

        if (in_array($ticket->status, [MaintenanceTicket::IN_PROGRESS, MaintenanceTicket::COMPLETED, MaintenanceTicket::VERIFIED], true)) {
            $maintenance->record(
                ticket: $ticket,
                event: TicketActivity::STARTED,
                stage: MaintenanceTicket::IN_PROGRESS,
                from: MaintenanceTicket::ASSIGNED,
                to: MaintenanceTicket::IN_PROGRESS,
                occurredAt: $ticket->eta_starts_at ?? $reported->copy()->addHours(12),

                // Board 18's note on the active stage, verbatim.
                note: $ticket->number === 1042 ? 'Technician on site, today 2:00–4:00 PM' : null,
                actorKind: $vendorName === null ? TicketActivity::STAFF : TicketActivity::VENDOR,
                actorName: $vendorName,
            );
        }

        if ($ticket->closed_at !== null) {
            $maintenance->record(
                ticket: $ticket,
                event: TicketActivity::RESOLVED,
                stage: MaintenanceTicket::COMPLETED,
                from: MaintenanceTicket::IN_PROGRESS,
                to: MaintenanceTicket::COMPLETED,
                occurredAt: $ticket->closed_at,
                note: $ticket->resolution,
                actorName: 'Patricia Morgan',
            );
        }
    }

    /**
     * Board 18's "ETA today 2:00–4:00 PM", as a datetime on today.
     *
     * @param  array<string, mixed>  $row
     */
    private function eta(array $row, int $index): ?Carbon
    {
        $window = $row['eta'] ?? null;

        if (! is_array($window)) {
            return null;
        }

        // "2:00–4:00 PM" is ON THE ESTATE'S CLOCK (M4 §3.1): built in the estate's zone, stored as the UTC moment it means.
        return SiteTime::today()->addHours((int) $window[$index]);
    }

    private function seedAmenities(): void
    {
        foreach (self::AMENITIES as $index => $row) {
            Amenity::updateOrCreate(
                ['name' => $row['name']],
                [
                    'icon_key' => $row['icon'],
                    'capacity' => $row['capacity'],

                    /*
                     * The rate card is rewritten on every run, and safely: a
                     * booking already made carries its own copy of both figures,
                     * so restoring the board's numbers here cannot move a single
                     * deposit the estate is holding. That is the whole point of
                     * the snapshot.
                     */
                    'booking_fee_minor' => $row['fee'],
                    'deposit_minor' => $row['deposit'],
                    'currency' => 'JMD',
                    'opens_at' => $row['opens'],
                    'closes_at' => $row['closes'],
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'is_bookable' => true,
                ],
            );
        }
    }

    private function seedBookings(): void
    {
        $amenities = Amenity::query()->get()->keyBy('name');
        $today = Carbon::today();

        foreach (self::BOOKINGS as $row) {
            $unit = Unit::where('reference', $row['unit'])->first();

            /*
             * Skipped where the lot does not exist rather than invented. This
             * seeder runs against whatever estate it is pointed at, and a test
             * fixture with six lots should get the bookings whose households it
             * has and no others — a booking against a unit nobody lives in is a
             * screen nobody can check.
             */
            if ($unit === null) {
                continue;
            }

            $amenity = $amenities->get($row['amenity']);

            if (! $amenity instanceof Amenity) {
                continue;
            }

            /*
             * ONE TIME RULE (M4 §3.1). "11 AM to 1 PM" is ON THE ESTATE'S CLOCK, so the booking is
             * built from the estate's own today, in its zone, and the model stores the UTC moment
             * it means. `$today` above stays what the deposit dates are counted from.
             */
            $siteDay = SiteTime::today()->addDays((int) $row['days']);
            $startsAt = $siteDay->copy()->addHours((int) $row['from']);

            $deposit = (int) ($amenity->deposit_minor ?? 0);

            $state = $deposit > 0 ? $row['deposit'] : AmenityBooking::DEPOSIT_NONE;

            $reference = 'BKG-'.$startsAt->format('Y-m').'-'.str_pad((string) $amenity->id, 4, '0', STR_PAD_LEFT);

            /*
             * FOUND BY ITS REFERENCE, THE BUSINESS KEY — NOT BY ITS START TIME.
             *
             * This used to match on `starts_at`, and `starts_at` is computed from
             * TODAY. Re-seeding a correctly built estate on the next day found
             * nothing at yesterday's start time, tried to create the booking
             * again, and collided with the reference's unique key — on
             * `gs_estate_oceanview`, the day after the Q-009 deposits went in.
             * The reference is what the row is known by and what the unique index
             * already guards, so it is what the lookup uses.
             */
            $existing = AmenityBooking::query()->where('reference', $reference)->first();

            /*
             * AN ESTATE SEEDED BEFORE Q-009 WAS RULED IS REPAIRED RATHER THAN
             * SKIPPED, and this is the case that made the difference.
             *
             * This seeder is idempotent: a booking that already exists is left
             * alone, which is right. But every estate seeded before deposits
             * reached the ledger holds bookings marked "held" with no journal
             * behind them, and re-running would have skipped straight past them
             * — leaving 2200 at zero while board 19 drew J$15,000 held, which is
             * the exact drift the ruling closes. Re-seeding is the one moment
             * this is cheap to fix, so it is fixed here.
             *
             * `deposit_journal_ref` is the test, not the state: a booking whose
             * state says held and whose ref is null is one this ran before the
             * ruling.
             */
            if ($existing !== null) {
                $this->backfillDeposit($existing, $state, $startsAt, $today, $row);

                continue;
            }

            $booking = AmenityBooking::create([
                'reference' => $reference,
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'resident_name' => $row['resident'],
                'starts_at' => $startsAt,
                'ends_at' => $siteDay->copy()->addHours((int) $row['to']),
                'status' => $row['status'],

                // The terms AS THEY WERE. Copied, never joined.
                'fee_minor' => (int) ($amenity->booking_fee_minor ?? 0),
                'deposit_minor' => $deposit,
                'currency' => $amenity->currency,
                'cancellation_hours' => $amenity->cancellation_hours,

                /*
                 * AWAITING to begin with, whatever this row ends up as. The
                 * deposit is then moved through the SERVICE below rather than
                 * written straight to its final state, so the ledger entries
                 * exist — see the block after this one.
                 */
                'deposit_state' => $state === AmenityBooking::DEPOSIT_NONE
                    ? AmenityBooking::DEPOSIT_NONE
                    : AmenityBooking::DEPOSIT_AWAITING,

                'approved_at' => $row['status'] === AmenityBooking::PENDING ? null : $startsAt->copy()->subDays(7),
                'approved_by_name' => $row['status'] === AmenityBooking::PENDING ? null : 'Patricia Morgan',
            ]);

            /*
             * THE DEPOSIT IS MOVED THROUGH `Amenities`, NOT WRITTEN.
             *
             * Q-009 was ruled after this seeder was written: a deposit posts to
             * the ledger — Dr 1000 Bank, Cr 2200 Deposits Held — and a refund
             * reverses it. Setting `deposit_state` directly, as this did, would
             * leave board 19 drawing "$5,000 held" over an account 2200 that had
             * never heard of it, which is the exact drift the ruling exists to
             * close and which a ledger-tie test would then report against the
             * seeder rather than against the code.
             *
             * Dated to the booking rather than to today: a deposit was taken
             * when the booking was accepted, and the refunded one went back on
             * the date board 19 prints beside it.
             */
            $this->moveDeposit($booking, $state, $startsAt, $today, $row);
        }
    }

    /**
     * Walk a new booking's deposit into the state this row asks for.
     *
     * THROUGH THE SERVICE, NOT WRITTEN. Q-009 was ruled after this seeder was
     * written: a deposit posts to the ledger — Dr 1000 Bank, Cr 2200 Deposits
     * Held — and a refund reverses it. Setting `deposit_state` directly, as this
     * did, would leave board 19 drawing "$5,000 held" over an account 2200 that
     * had never heard of it.
     *
     * Dated to the booking rather than to today: a deposit was taken when the
     * booking was accepted, and the refunded one went back on the date board 19
     * prints beside it.
     *
     * @param  array<string, mixed>  $row
     */
    private function moveDeposit(
        AmenityBooking $booking,
        string $state,
        Carbon $startsAt,
        Carbon $today,
        array $row,
    ): void {
        if ($state !== AmenityBooking::DEPOSIT_HELD && $state !== AmenityBooking::DEPOSIT_REFUNDED) {
            return;
        }

        // NOT `$amenities` — that name is already the collection of Amenity
        // models the caller reads from, and shadowing it emptied the lookup on
        // the very next iteration.
        $service = app(Amenities::class);

        $service->holdDeposit($booking, $startsAt->copy()->subDays(7));

        if ($state === AmenityBooking::DEPOSIT_REFUNDED) {
            $service->refundDeposit($booking, $today->copy()->addDays((int) $row['refund']));
        }
    }

    /**
     * Give an already-seeded booking the ledger entries its state implies.
     *
     * Only where there are none: `deposit_journal_ref` is null exactly on the
     * bookings this seeder created before Q-009 was ruled. A booking that
     * already has its entries is left alone, so re-seeding twice does not post
     * the same deposit twice.
     *
     * The booking is walked back to `awaiting` first, because the service
     * refuses to hold a deposit that is already held — which is the right
     * refusal for a console and simply in the way of a repair.
     *
     * @param  array<string, mixed>  $row
     */
    private function backfillDeposit(
        AmenityBooking $booking,
        string $state,
        Carbon $startsAt,
        Carbon $today,
        array $row,
    ): void {
        if ($booking->deposit_journal_ref !== null) {
            return;
        }

        if ($state !== AmenityBooking::DEPOSIT_HELD && $state !== AmenityBooking::DEPOSIT_REFUNDED) {
            return;
        }

        $booking->forceFill(['deposit_state' => AmenityBooking::DEPOSIT_AWAITING])->save();

        $this->moveDeposit($booking, $state, $startsAt, $today, $row);
    }
}
