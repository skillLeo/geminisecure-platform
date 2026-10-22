<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\TicketActivity;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Support\SiteTime;
use Database\Seeders\Estate\FacilitiesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The maintenance queue — boards 17 and 18
|--------------------------------------------------------------------------
|
| ONE GUARANTEE IS UNDER TEST HERE AND EVERYTHING ELSE SUPPORTS IT: the SLA
| clock starts at the report and nothing an estate does afterwards moves it.
|
| That is easy to say and easy to lose. `assigned_at` is right there on the row,
| a queue built the obvious way would measure from it, and the ticket that had
| been ignored longest would then be the one the screen reported as fine. Ticket
| #1041 is seeded to make the failure visible: six days old against a one-day
| target, with a vendor named six hours after the report. It is overdue, it is
| still overdue the moment a different vendor is named, and its STORED status
| says `assigned` throughout — because "overdue" is not a state anybody sets. It
| is arithmetic over two columns of one row, computed at draw time.
|
| THE FOUR TILES ARE THE SAME ARITHMETIC, TOTALLED. 12 open, 7 in progress, 2
| overdue and 3.2 days average resolution are not four numbers this seeder wrote
| down: they are counts and a mean over seventeen tickets, and every one of them
| is re-derived below in raw SQL so that the expectation and the application
| reach the same figure by two different routes.
|
| THE FIRST TEST IN THIS FILE COUNTS THE WHOLE QUEUE, so it runs before anything
| here raises a ticket of its own. Every test after it either works on a ticket
| it created or, where board 17's own rows are the point, leaves the counts
| alone.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();

    // Committed, and before the transaction below: the permission matrix and
    // the estate itself are the ground these tests stand on, not part of what
    // any one of them writes.
    FacilitiesFixture::platform();

    /*
     * The users each test issues live in the PLATFORM database and are rolled
     * back with it. The estate's own database is not transacted — it is built
     * once per process and these tests read the queue the seeder left there,
     * exactly as `CollectionsFixture` does.
     */
    DB::connection('mysql')->beginTransaction();

    $this->maintenance = app(Maintenance::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/**
 * Board 17's four tiles, recomputed in raw SQL over the same rows.
 *
 * Written out longhand and deliberately not through `boardStatus()`: what is
 * being proven is that the tiles are derived from `reported_at` and `sla_hours`,
 * and a second route that called the first method would prove only that the
 * method is deterministic.
 *
 * The moment is BOUND FROM PHP rather than taken as MySQL's `NOW()`. Every
 * `reported_at` in this estate was written by Carbon in the application's
 * timezone, and a comparison against the database server's clock would be a
 * quiet hour out wherever the two disagree.
 *
 * WITH `$numbers`, over THOSE TICKETS ONLY (M4 §4, defect 3). The estate database is committed and
 * shared: the Resident App's contract walk raises a ticket here, the community history suite raises
 * more, and this estate is the one a reviewer opens. "12 open" is a claim about what the SEEDER
 * made, so it is asked of the seeder's own queue; "the tile equals the count" is a claim about the
 * board, so it is asked of everything.
 *
 * @param  list<int>|null  $numbers
 * @return array{open: int, progress: int, overdue: int, resolution_seconds: float}
 */
function queueTiles(Carbon $asAt, ?array $numbers = null): array
{
    $closed = "('completed', 'verified', 'cancelled')";
    $only = $numbers === null ? '' : ' WHERE number IN ('.implode(',', array_map(intval(...), $numbers)).')';

    // M68 §2.3 (FAC-2): Open is nobody has the job (submitted, acknowledged), In progress is a vendor has it (assigned,
    // in progress), whether or not either is overdue; overdue is the flag across both.
    $row = DB::connection('tenant')->selectOne("
        SELECT
            SUM(CASE WHEN status IN ('submitted', 'acknowledged') THEN 1 ELSE 0 END) AS open_count,

            SUM(CASE WHEN status NOT IN {$closed}
                      AND DATE_ADD(reported_at, INTERVAL sla_hours HOUR) < ? THEN 1 ELSE 0 END) AS overdue_count,

            SUM(CASE WHEN status IN ('assigned', 'in_progress') THEN 1 ELSE 0 END) AS progress_count,

            AVG(CASE WHEN closed_at IS NOT NULL AND status IN {$closed}
                      THEN TIMESTAMPDIFF(SECOND, reported_at, closed_at) END) AS resolution_seconds
          FROM maintenance_tickets{$only}
    ", [$asAt->toDateTimeString()]);

    return [
        'open' => (int) $row->open_count,
        'progress' => (int) $row->progress_count,
        'overdue' => (int) $row->overdue_count,
        'resolution_seconds' => (float) $row->resolution_seconds,
    ];
}

/**
 * How many entries each ticket's history holds, by the number board 17 prints.
 *
 * @return array<int, int>
 */
function trailLengths(): array
{
    $rows = DB::connection('tenant')->select('
        SELECT t.number AS number, COUNT(*) AS entries
          FROM maintenance_ticket_activity a
          JOIN maintenance_tickets t ON t.id = a.maintenance_ticket_id
         GROUP BY t.number
    ');

    $lengths = [];

    foreach ($rows as $row) {
        $lengths[(int) $row->number] = (int) $row->entries;
    }

    return $lengths;
}

/* ------------------------------------------------------------------ */
/* the tiles, derived */
/* ------------------------------------------------------------------ */

it('counts board 17 four tiles over the seeded queue rather than reading a flag', function () {
    $now = Carbon::now();

    $board = $this->maintenance->queueBoard('all', $now);
    $tiles = collect($board['kpis'])->keyBy('key');

    /*
     * TWO DIFFERENT CLAIMS, ASKED OF TWO DIFFERENT SETS OF ROWS (M4 §4, defect 3).
     *
     * This used to assert the board's tiles against the literal 12 · 7 · 2 · 3.2, over the whole
     * table. That is only true of an estate nothing has been added to since it was seeded — and
     * this estate is committed and shared with every other facilities suite, two of which raise
     * tickets on it. In file order this happened to run first; `phpunit.xml` now runs the suite in
     * random order and the tile read 14. A board that counts absolutes will go on being right
     * while a test that asserts somebody else's absolute goes on being wrong, so:
     *
     *   the BOARD's claim  — "the tile is derived from `reported_at` and `sla_hours`, not read
     *                        off a flag" — is asked of EVERY row in the table, whoever put it
     *                        there, which is the stronger form of the original assertion;
     *   the SEEDER's claim — "board 17 draws twelve open, seven in progress, two overdue and a
     *                        3.2-day mean" — is asked of the seeder's own seventeen tickets,
     *                        named by `FacilitiesFixture::seededTicketNumbers()`, which is taken
     *                        the moment the seeder finishes and before anything can be added.
     */
    $traced = queueTiles($now);
    $seeded = queueTiles($now, FacilitiesFixture::seededTicketNumbers());

    // The board drew 12 open (every ticket still outstanding) and 7 in progress inside them. Since M68 §2.3 the two
    // groups do not overlap: the seeder's twelve outstanding tickets are split between them, every one in one.
    expect($tiles['open']['value'])->toBe($traced['open'])
        ->and($tiles['in_progress']['value'])->toBe($traced['progress'])
        ->and($seeded['open'] + $seeded['progress'])->toBe(12)

        ->and($tiles['overdue']['value'])->toBe($traced['overdue'])
        ->and($seeded['overdue'])->toBe(2)

        // A mean over the five completed jobs, in days to one decimal place:
        // 3 + 2 + 3 + 4 + 4 over five is 3.2, which is what the board prints.
        ->and($tiles['avg_resolution']['value'])->toBe(round($traced['resolution_seconds'] / 3600 / 24, 1))
        ->and(round($seeded['resolution_seconds'] / 3600 / 24, 1))->toBe(3.2);

    // The red chip beside the row is the same count as the tile and not a
    // second query that could disagree with it.
    expect($board['overdue'])->toBe($tiles['overdue']['value']);

    /*
     * And board 17's own five rows are still its first five, IN ITS OWN ORDER — asked of the
     * seeded queue, because a ticket somebody else raised a moment ago is newer than all of them
     * and would sit above #1042 without anything being wrong.
     */
    $seededNumbers = FacilitiesFixture::seededTicketNumbers();

    expect(array_slice(array_values(array_filter(
        array_column($board['rows'], 'number'),
        static fn (int $number): bool => in_array($number, $seededNumbers, true),
    )), 0, 5))->toBe([1042, 1041, 1039, 1037, 1031]);
});

it('keeps no stored breach flag and no stored deadline for one to drift from', function () {
    /*
     * THE STRUCTURAL HALF OF THE SAME GUARANTEE. A stored `is_overdue` is wrong
     * from the second after it is written and needs a nightly job to keep it
     * true; a stored `sla_due_at` needs rewriting every time a priority
     * changes, and the rewrite somebody forgets is a ticket quietly given
     * longer than its priority allows. Neither column exists, so neither can be
     * left saying otherwise.
     */
    expect(Schema::connection('tenant')->hasColumn('maintenance_tickets', 'is_overdue'))->toBeFalse()
        ->and(Schema::connection('tenant')->hasColumn('maintenance_tickets', 'sla_due_at'))->toBeFalse()
        ->and(Schema::connection('tenant')->hasColumn('maintenance_tickets', 'reported_at'))->toBeTrue()
        ->and(Schema::connection('tenant')->hasColumn('maintenance_tickets', 'sla_hours'))->toBeTrue();

    // "Overdue" is not a lifecycle state either, and since M68 §2.3 (FAC-3) not a status badge: it is a flag drawn
    // beside the state, so an overdue ticket still says whether anybody started it.
    expect(MaintenanceTicket::STAGES)->not->toHaveKey('overdue')
        ->and(MaintenanceTicket::STATUS_LABELS)->not->toHaveKey('overdue');
});

/* ------------------------------------------------------------------ */
/* the clock, and what does not move it */
/* ------------------------------------------------------------------ */

it('calls #1041 overdue however recently its vendor was named', function () {
    $ticket = FacilitiesFixture::ticket(1041);

    // Six days old against a one-day target, which is what makes this ticket
    // the one the whole module is arranged around.
    expect($ticket->priority)->toBe(MaintenanceTicket::HIGH)
        ->and($ticket->sla_hours)->toBe(Maintenance::SLA_HOURS[MaintenanceTicket::HIGH])
        ->and($ticket->sla_hours)->toBe(24)
        ->and($ticket->isOverdue())->toBeTrue()
        // In progress (a vendor has it) and overdue beside it, never "Overdue" instead (M68 §2.3).
        ->and($ticket->boardStatus())->toBe('progress')

        /*
         * AND THE STORED STATUS SAYS WHAT ACTUALLY HAPPENED. A vendor was named
         * and nobody has started. If "overdue" were ever written into this
         * column the estate would have lost the fact that the job is assigned,
         * and gained a flag that stops being true the moment the work starts.
         */
        ->and($ticket->status)->toBe(MaintenanceTicket::ASSIGNED);

    $deadline = $ticket->slaDueAt();
    $reported = $ticket->reported_at->copy();

    /*
     * READ BEFORE THE WRITE, SO WHAT IS ASSERTED BELOW IS A CHANGE (M6 §3.1).
     *
     * This estate's database is COMMITTED and shared, and a ticket's history is append-only: the
     * entry this test is about to write can never be taken back off. "#1041 has one reassignment
     * on it" is therefore a claim about rows this test does not own, and it is wrong the first
     * time any other file names a vendor on #1041 — wrong in a fixed order too, just not yet.
     *
     * What the test actually means is "ONE reassignment writes ONE entry, and the note is composed
     * from the vendor who was there before", so both are measured against what was there first.
     * The seeder puts AquaTech Pool Services on #1041; the expectation is taken from the row rather
     * than written out, because the assertion is about how the note is built.
     */
    $reassignmentsBefore = $ticket->activity()->where('event', TicketActivity::REASSIGNED)->count();
    $previousVendor = (string) $ticket->vendor?->name;

    // A different supplier, named this second. Board 18's "Reassign vendor".
    $this->maintenance->assign($ticket, FacilitiesFixture::vendor('Island Electric Services'));

    $reassigned = FacilitiesFixture::ticket(1041);

    expect($reassigned->assigned_at->diffInMinutes(Carbon::now(), absolute: true))->toBeLessThan(2)

        // THE ASSERTION THIS FILE EXISTS FOR. The deadline did not move, the
        // report time did not move, and the ticket is still overdue — five days
        // over, with a vendor appointed a minute ago. A queue measuring from
        // assignment would now report it as fresh.
        ->and($reassigned->slaDueAt()->equalTo($deadline))->toBeTrue()
        ->and($reassigned->reported_at->equalTo($reported))->toBeTrue()
        ->and($reassigned->isOverdue())->toBeTrue()
        ->and($reassigned->boardStatus())->toBe('progress')
        ->and($reassigned->status)->toBe(MaintenanceTicket::ASSIGNED);

    // The reassignment is history rather than progress: it does not fill board
    // 18's third stage a second time, and it does not overwrite the timestamp
    // the resident was already shown.
    expect($reassigned->activity()->where('event', TicketActivity::REASSIGNED)->count())
        ->toBe($reassignmentsBefore + 1);

    // The newest one, named as such: on an append-only trail the entry this test wrote is the last
    // one, and an unordered `first()` would hand back somebody else's the day there is one.
    $entry = $reassigned->activity()->where('event', TicketActivity::REASSIGNED)->latest('id')->firstOrFail();

    /*
     * The note names WHO IT CAME OFF, which is the only part of a reassignment a manager reading
     * board 18 needs. `$previousVendor` is asserted non-empty and different from the new supplier
     * first, or "Reassigned from " with nothing after it would satisfy the line below.
     */
    expect($entry->stage)->toBeNull()
        ->and($previousVendor)->not->toBe('')
        ->and($previousVendor)->not->toBe('Island Electric Services')
        ->and($entry->note)->toBe('Reassigned from '.$previousVendor);
});

it('gives every ticket the target its priority carries and measures it from the report', function () {
    // The ladder board 17's own five rows constrain: a high ticket four hours
    // old is not yet overdue and the same one at six days is.
    expect(Maintenance::SLA_HOURS)->toBe([
        MaintenanceTicket::HIGH => 24,
        MaintenanceTicket::MEDIUM => 72,
        MaintenanceTicket::LOW => 168,
    ]);

    // The seeder's own tickets, raised under the default ladder. Since M98 an estate sets its own targets, and a ticket
    // another test raised under them carries those (MaintenanceServiceTargetsTest), as the file's tile counts already
    // ask the seeder's queue alone.
    foreach (MaintenanceTicket::query()->whereIn('number', FacilitiesFixture::seededTicketNumbers())->get() as $ticket) {
        expect($ticket->sla_hours)->toBe(Maintenance::SLA_HOURS[$ticket->priority])
            ->and($ticket->slaDueAt()->equalTo($ticket->reported_at->copy()->addHours($ticket->sla_hours)))
            ->toBeTrue();
    }

    /*
     * #1039 IS THE ROW THAT PROVES THE CLOCK RUNS ON ITS OWN. Four hours into a
     * one-day target with nobody assigned, it is not overdue; twenty-five hours
     * in, with still nobody assigned and nothing done to it at all, it is. No
     * write happens between those two assertions.
     */
    $unassigned = FacilitiesFixture::ticket(1039);

    expect($unassigned->assigned_vendor_id)->toBeNull()
        ->and($unassigned->assigneeLabel())->toBe('Unassigned')
        ->and($unassigned->isOverdue($unassigned->reported_at->copy()->addHours(4)))->toBeFalse()
        ->and($unassigned->boardStatus($unassigned->reported_at->copy()->addHours(4)))->toBe('submitted')
        ->and($unassigned->isOverdue($unassigned->reported_at->copy()->addHours(25)))->toBeTrue()
        // Still Submitted, nobody having started it, with overdue beside it (M68 §2.3).
        ->and($unassigned->boardStatus($unassigned->reported_at->copy()->addHours(25)))->toBe('submitted');
});

it('moves the deadline when a ticket is escalated and does not restart the clock', function () {
    /*
     * A ticket of this test's own, because board 17's rows are what the tile
     * counts above are measured over. Sixty hours old: comfortably inside a
     * medium ticket's three-day target and two and a half times outside a high
     * one's, so the escalation below is the only thing that can change the
     * answer.
     */
    $ticket = $this->maintenance->report(
        title: 'Sagging carport beam',
        locationLabel: 'Phase 3 · block C',
        priority: MaintenanceTicket::MEDIUM,
        reportedAt: Carbon::now()->subHours(60),
    );

    expect($ticket->sla_hours)->toBe(72)
        ->and($ticket->isOverdue())->toBeFalse();

    $this->maintenance->changePriority($ticket, MaintenanceTicket::HIGH);

    /*
     * OVERDUE IMMEDIATELY, AND THAT IS THE POINT OF ESCALATING ONE. High means
     * twenty-four hours from the report, and the report was three days ago. A
     * clock that restarted here would hand a ticket somebody had just decided
     * was urgent a fresh day to sit in.
     */
    expect($ticket->refresh()->sla_hours)->toBe(24)
        ->and($ticket->priority)->toBe(MaintenanceTicket::HIGH)
        ->and($ticket->isOverdue())->toBeTrue()
        ->and($ticket->slaDueAt()->equalTo($ticket->reported_at->copy()->addHours(24)))->toBeTrue();

    // And the change is on the record, in the words board 18 prints.
    $note = $ticket->activity()->where('event', TicketActivity::PRIORITY_CHANGED)->value('note');

    expect($note)->toBe('Priority medium → high');
});

/* ------------------------------------------------------------------ */
/* what the Age column prints */
/* ------------------------------------------------------------------ */

it('humanises an age to its largest unit, in the singular where there is one of it', function () {
    $ticket = FacilitiesFixture::ticket(1029);
    $reported = $ticket->reported_at;

    /*
     * "1 days" IS A BUG THIS CODEBASE HAS ALREADY FIXED ONCE. Carbon 3 returns
     * a FLOAT from `diffInHours`, so the obvious `$days === 1` test against an
     * unfloored quotient never holds and every one-day ticket on board 17 reads
     * "1 days". The moment is stated rather than taken as now, because an age
     * is arithmetic against an instant.
     */
    expect($ticket->ageLabel($reported->copy()->addHours(24)))->toBe('1 day')
        ->and($ticket->ageLabel($reported->copy()->addHours(47)))->toBe('1 day')
        ->and($ticket->ageLabel($reported->copy()->addHours(48)))->toBe('2 days')

        // Never minutes: a ticket raised twenty minutes ago reads "Just now"
        // (FAC-14, DR-086), not an hour it has not been waiting.
        ->and($ticket->ageLabel($reported->copy()->addMinutes(20)))->toBe('Just now')
        ->and($ticket->ageLabel($reported->copy()->addHours(1)))->toBe('1 hour')
        ->and($ticket->ageLabel($reported->copy()->addHours(4)))->toBe('4 hours')
        ->and($ticket->ageLabel($reported->copy()->addHours(23)))->toBe('23 hours');
});

it('replaces the age of a finished job with the day it finished', function () {
    $closed = FacilitiesFixture::ticket(1031);

    expect($closed->status)->toBe(MaintenanceTicket::COMPLETED)
        ->and($closed->closed_at)->not->toBeNull();

    /*
     * Board 17 draws "Closed Sep 2" where the Age cell would be. How old a
     * finished job is tells a manager nothing; when it finished tells them
     * whether the estate is keeping up. The date is the ticket's own because
     * this estate is seeded relative to today — see `FacilitiesSeeder` — so the
     * shape is what is asserted, and that it is not an age.
     */
    expect($closed->ageLabel())->toBe('Closed '.$closed->closed_at->format('M j'))
        ->and($closed->ageLabel())->not->toContain('day')
        ->and($closed->ageLabel())->not->toContain('hour')

        // And a closed ticket is not overdue, however late it ran. That is a
        // question for the average-resolution tile, not for a queue of work
        // outstanding.
        ->and($closed->isOverdue())->toBeFalse()
        ->and($closed->boardStatus())->toBe('completed');
});

/* ------------------------------------------------------------------ */
/* board 18, and the history behind it */
/* ------------------------------------------------------------------ */

it('draws board 18 six stages in lifecycle order with the furthest one active', function () {
    $board = $this->maintenance->ticketBoard(FacilitiesFixture::ticket(1042));

    // The order is the LIFECYCLE's and not the timestamps': every stage is
    // drawn whether or not it has happened.
    expect(array_column($board['timeline'], 'key'))->toBe(array_keys(MaintenanceTicket::STAGES))
        ->and(array_column($board['timeline'], 'state'))
        ->toBe(['done', 'done', 'done', 'active', 'pending', 'pending']);

    $stages = collect($board['timeline'])->keyBy('key');

    // The amber marker sits on the stage that HAS an entry — "the first stage
    // with no entry" would put it one row too low, on a stage where nothing has
    // happened — and its note is board 18's own sub-line.
    expect($stages[MaintenanceTicket::IN_PROGRESS]['note'])->toBe('Technician on site, today 2:00–4:00 PM')
        ->and($stages[MaintenanceTicket::COMPLETED]['line'])->toBeNull();

    // Board 18's vendor panel, composed of only the parts that exist.
    expect($board['vendor']['name'])->toBe('Island Electric Services')
        ->and($board['vendor']['detail'])->toBe('Tech: Owen Grant · (876) 555 0110 · ETA today 2:00 PM–4:00 PM JA');

    expect($board['ticket']['is_overdue'])->toBeFalse()
        ->and($board['ticket']['summary_title'])->toBe('Gate lighting · Phase 2 visitor parking')
        ->and($board['ticket']['meta'])->toContain('Submitted by Andrea Fletcher')
        ->and($board['ticket']['meta'])->toContain('Lot 47');
});

it('carries no vendor panel for a ticket nobody has been sent to', function () {
    $board = $this->maintenance->ticketBoard(FacilitiesFixture::ticket(1039));

    // Null rather than an empty panel: a ticket with no supplier is a fact, and
    // a card reading "Tech: · · ETA" is a broken screen rather than a missing
    // detail.
    expect($board['vendor'])->toBeNull()
        ->and(collect($board['timeline'])->firstWhere('key', MaintenanceTicket::ASSIGNED)['state'])->toBe('pending');
});

it('leaves the first closure standing when a job comes back', function () {
    $ticket = $this->maintenance->report(
        title: 'Pump room leak — returned',
        locationLabel: 'Pool Deck',
        priority: MaintenanceTicket::MEDIUM,
        reportedAt: Carbon::now()->subDay(),
    );

    $this->maintenance->assign($ticket, FacilitiesFixture::vendor('AquaTech Pool Services'));
    $this->maintenance->resolve($ticket, 'Seal replaced on the pump housing.');

    expect($ticket->refresh()->status)->toBe(MaintenanceTicket::COMPLETED)
        ->and($ticket->closed_at)->not->toBeNull();

    // Closing it twice would overwrite the day the work actually finished,
    // which is the figure the resolution average is measured on.
    expect(fn () => $this->maintenance->resolve($ticket, 'Again'))
        ->toThrow(DomainException::class, 'already');

    $reported = $ticket->reported_at->copy();

    $this->maintenance->reopen($ticket, 'The leak came back within the day.');

    expect($ticket->refresh()->status)->toBe(MaintenanceTicket::ASSIGNED)
        ->and($ticket->closed_at)->toBeNull()

        /*
         * `reported_at` IS NOT CLEARED. A household that has waited a fortnight
         * across two visits has waited a fortnight, and restarting the clock on
         * a reopen would reward failing to fix it the first time.
         */
        ->and($ticket->reported_at->equalTo($reported))->toBeTrue();

    // THE PAIR IS THE RECORD. A reopen is a new entry, never an edit to the one
    // that said the job was done — and the pair is the only way anybody sees a
    // vendor whose repairs do not hold.
    $events = $ticket->activity()->orderBy('id')->pluck('event')->all();

    expect($events)->toContain(TicketActivity::RESOLVED)
        ->and($events)->toContain(TicketActivity::REOPENED)
        ->and(array_search(TicketActivity::RESOLVED, $events, true))
        ->toBeLessThan(array_search(TicketActivity::REOPENED, $events, true));
});

it('refuses to close a ticket with nothing said about what was done', function () {
    $ticket = $this->maintenance->report(
        title: 'Corridor light out — block D',
        locationLabel: 'Phase 3 · block D',
        priority: MaintenanceTicket::LOW,
    );

    // A ticket closed with no resolution tells the resident who reported it
    // nothing, and tells the next person to hit the same fault less than that.
    expect(fn () => $this->maintenance->resolve($ticket, '   '))
        ->toThrow(DomainException::class, 'Say what was done');

    expect($ticket->refresh()->status)->toBe(MaintenanceTicket::SUBMITTED)
        ->and($ticket->closed_at)->toBeNull();

    // And there is nothing to reopen on a job that never closed.
    expect(fn () => $this->maintenance->reopen($ticket, 'It is still broken'))
        ->toThrow(DomainException::class, 'already open');
});

it('adds no second set of state changes when the estate is seeded again', function () {
    $before = trailLengths();
    $tickets = MaintenanceTicket::query()->count();

    expect($before)->not->toBeEmpty();

    /*
     * THE LOG IS THE RESIDENT'S RECORD OF WHAT HAPPENED. A second run that
     * stacked another six entries onto every ticket would make it evidence of
     * something that did not — two acknowledgements, two assignments, a job
     * apparently started twice — and no application path could take them back
     * off, because a ticket's history is written down rather than derived.
     */
    (new FacilitiesSeeder)->run();

    expect(trailLengths())->toBe($before)
        ->and(MaintenanceTicket::query()->count())->toBe($tickets)
        ->and(TicketActivity::query()->count())->toBe(array_sum($before));
});

it('carries no household financial position on either maintenance board', function () {
    /*
     * D-010, walked rather than asserted about. The persona on boards 17 and 18
     * is the Property Manager, who holds Facilities in full and holds `-` on
     * Dues & ledger, Payments and Accounting — the split exists so that whoever
     * commissions the work can see what a job costs without ever reaching what
     * a household owes. A unit reaches these screens as a PLACE ("Phase 1 · Lot
     * 9") and never as an account.
     */
    $payload = json_encode([
        $this->maintenance->queueBoard(),
        $this->maintenance->ticketBoard(FacilitiesFixture::ticket(1042)),
    ]);

    $serialised = strtolower((string) $payload);

    foreach (['balance', 'arrear', 'bucket', 'receivable', 'owed', 'invoice', 'statement', 'ageing', 'j$'] as $forbidden) {
        expect($serialised)->not->toContain(
            $forbidden,
            "a maintenance payload contained the word [{$forbidden}], which is a household's financial position"
        );
    }

    // And no bare figure that could be an amount, which is the other shape of
    // the same mistake: a permitted key carrying money in its value.
    expect($serialised)->not->toMatch('/\d[\d,]*\.\d{2}/');
});

/* ------------------------------------------------------------------ */
/* who may do any of it */
/* ------------------------------------------------------------------ */

it('lets a role holding Facilities view read board 17 and refuses it every act', function () {
    /*
     * The Treasurer holds `V` on Facilities in the seeded matrix: they may read
     * the queue and may not triage it. Each assertion below is an HTTP one,
     * because what is being proven is that the ROUTE refuses — a screen that
     * merely drew its buttons greyed would still accept the POST behind them.
     */
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->withoutVite()->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/facilities/maintenance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Facilities/Maintenance')
            ->where('canUpdate', false)
            ->where('canCreate', false)
            ->has('blockedReason'));

    $acts = [
        '/facilities/maintenance/1042/assign' => ['vendor_id' => FacilitiesFixture::vendor('FitFix Equipment Repair')->id],
        '/facilities/maintenance/1042/priority' => ['priority' => 'low'],
        '/facilities/maintenance/1042/resolve' => ['resolution' => 'Light replaced.'],
        '/facilities/maintenance/1031/reopen' => ['reason' => 'It came back.'],
    ];

    foreach ($acts as $path => $payload) {
        $this->actingAs($treasurer)
            ->post(FacilitiesFixture::url($path), $payload)
            ->assertForbidden();
    }

    // Nothing was triaged on the way to being refused.
    FacilitiesFixture::boot();

    expect(FacilitiesFixture::ticket(1042)->priority)->toBe(MaintenanceTicket::MEDIUM)
        ->and(FacilitiesFixture::ticket(1042)->status)->toBe(MaintenanceTicket::IN_PROGRESS)
        ->and(FacilitiesFixture::ticket(1031)->status)->toBe(MaintenanceTicket::COMPLETED);
});

it('lets the office message the resident on the ticket\'s own thread, under their name, and only on a request a resident reported (M53 §4.10)', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 9478');
    $ticket = $this->maintenance->report(
        title: 'Message thread probe',
        locationLabel: 'Lot 9478',
        priority: MaintenanceTicket::MEDIUM,
        unit: $unit,
        reportedByName: 'Thread Resident',
    );
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    // The button is live, with one line saying what it does.
    $this->withoutVite()->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reasons.message', null));

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/message'), ['body' => 'The technician comes tomorrow at 9.'])
        ->assertRedirect(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number))
        ->assertSessionHas('success');
    FacilitiesFixture::boot();

    $note = TicketActivity::query()->where('maintenance_ticket_id', $ticket->id)->latest('id')->firstOrFail();
    expect($note->event)->toBe(TicketActivity::NOTE)
        ->and($note->note)->toBe('The technician comes tomorrow at 9.')
        ->and($note->actor_kind)->toBe(TicketActivity::STAFF)
        ->and($note->actor_name)->toBe($manager->name);

    // A reader without Facilities update is refused at the route, and nothing is written.
    $count = TicketActivity::query()->where('maintenance_ticket_id', $ticket->id)->count();
    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->post(FacilitiesFixture::url('/facilities/maintenance/'.$ticket->number.'/message'), ['body' => 'No.'])
        ->assertForbidden();
    FacilitiesFixture::boot();
    expect(TicketActivity::query()->where('maintenance_ticket_id', $ticket->id)->count())->toBe($count);

    // A job the office raised for the common areas has nobody to message.
    $common = $this->maintenance->report(title: 'Common area probe', locationLabel: 'Pool Deck', priority: MaintenanceTicket::LOW);
    expect(fn () => $this->maintenance->messageResident($common, 'Hello', $manager))
        ->toThrow(DomainException::class, 'Only a request a resident reported has a resident to message.');
});

it('lets the Property Manager triage the queue that is theirs to run', function () {
    // The one role that holds Facilities in FULL, and the persona every one of
    // these four boards is drawn for.
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/maintenance/1037/priority'), ['priority' => 'high'])
        ->assertRedirect(FacilitiesFixture::url('/facilities/maintenance/1037'));

    FacilitiesFixture::boot();

    $ticket = FacilitiesFixture::ticket(1037);

    expect($ticket->priority)->toBe(MaintenanceTicket::HIGH)
        ->and($ticket->sla_hours)->toBe(24)

        // Attributed to the person who did it, which is the whole of what an activity log is for.
        // THE NEWEST ENTRY, NOT "AN" ENTRY: #1037's trail is append-only and shared, this test adds
        // a second entry to it two lines below, and an unordered `value()` returns whichever row
        // MySQL hands back first.
        ->and($ticket->activity()->where('event', TicketActivity::PRIORITY_CHANGED)->latest('id')->value('actor_name'))
        ->toBe($manager->name);

    // Put board 17's own row back, because these tests share one queue.
    $this->maintenance->changePriority($ticket, MaintenanceTicket::LOW);

    expect($ticket->refresh()->sla_hours)->toBe(168);
});

it('reads a ticket by the number the resident was given', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    // Board 18's own URL is /facilities/maintenance/1042 — the number a
    // resident quotes and a bill already stores, not a surrogate id that would
    // make one job answer to two addresses.
    $this->withoutVite()->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/maintenance/1042'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Facilities/Ticket')
            ->where('ticket.number', 1042)
            ->where('canUpdate', true));
});

it('raises a work order from the office with the clock running from the time reported', function () {
    /*
     * 12 §2, Wave 2. A work order raised by the office starts an SLA clock,
     * so the location, the category and the priority are chosen on the form —
     * and the time reported may be earlier than now, because a call taken on
     * Saturday and keyed on Monday is a ticket that is already two days old.
     */
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $this->withoutVite()->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/maintenance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCreate', true)
            ->where('canUpdate', true)

            // M11 A2: the queue carries no lots until the office searches for one.
            ->where('units', [])
            ->has('categories'));

    // Typed as "9", found as Lot 9 — the exact lot first, however many others contain the digit.
    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/maintenance?unit_q=9'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units.0.id', FacilitiesFixture::unit('Lot 9')->id)
            ->where('units.0.label', 'Lot 9 · Phase 1')
            ->where('unitQuery', '9'));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/facilities/maintenance'), ['title' => 'x', 'location' => 'y', 'category' => 'Other', 'priority' => 'low'])
        ->assertForbidden();

    /*
     * ON THE ESTATE'S CLOCK (M4 §3.1). "The call came in Saturday at 4" is typed into this form
     * as digits with no offset, and digits with no offset ARE the estate's wall clock — so the
     * moment to compare against is built in the estate's zone, and the row holds the UTC moment
     * those digits mean. Built under UTC, the typed time meant five hours earlier than intended.
     */
    $reported = SiteTime::now()->subDays(2)->startOfMinute();
    $unit = FacilitiesFixture::unit('Lot 9');

    $response = $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/maintenance'), [
            'title' => 'Pool pump tripping',
            'location' => 'Pool Deck plant room',
            'unit_id' => $unit->id,
            'category' => 'Equipment',
            'priority' => 'high',
            'description' => 'Trips the breaker every twenty minutes.',
            'reported_at' => $reported->toDateTimeString(),
        ])
        ->assertRedirect();

    FacilitiesFixture::boot();

    $ticket = MaintenanceTicket::query()->where('title', 'Pool pump tripping')->firstOrFail();

    expect($ticket->priority)->toBe(MaintenanceTicket::HIGH)
        ->and($ticket->sla_hours)->toBe(24)
        ->and($ticket->unit_id)->toBe($unit->id)
        ->and($ticket->category)->toBe('Equipment')
        ->and($ticket->reported_at->equalTo($reported))->toBeTrue()
        ->and($ticket->status)->toBe(MaintenanceTicket::SUBMITTED)
        ->and($ticket->activity()->count())->toBe(1)
        ->and((string) $response->headers->get('Location'))->toEndWith('/facilities/maintenance/'.$ticket->number);

    // Two days old at a one-day target: overdue from the moment it was raised,
    // which is the whole reason the clock is not allowed to restart at entry.
    expect($ticket->isOverdue(Carbon::now()))->toBeTrue()
        ->and(app(Maintenance::class)->ticketBoard($ticket)['ticket']['is_overdue'])->toBeTrue();
});
