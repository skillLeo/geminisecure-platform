<?php

declare(strict_types=1);

use App\Console\Commands\PhoenixDemoStates;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Ballot;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\TicketActivity;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Role;
use App\Services\Estate\Payables;
use App\Support\SiteTime;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| A RECORD IN EVERY STATE THE CONSOLE ACTS ON (M53 §2, M60 §C1, `phoenix:demo-states`)
|--------------------------------------------------------------------------
|
| The M58 run's pass 3 found 21 of 51 record states on Phoenix Park. The command makes a record in each missing state it
| can make honestly, through the service that owns it where a service reaches the state, on households the
| demonstration named, with nobody told; says what it will not make and why; writes nothing without --confirm; makes
| nothing twice; writes no word a committee would read as a placeholder and no date in 2020 (M60 §C1); keeps its mark
| in the seeded columns, never in a word (Hassam, 9 October 2026); approves a bill only when asked for it by name; and
| on the live server writes to Phoenix Park Village 1 only. On an estate of this file's own, seeded as the
| demonstration is.
|
*/

function demoStatesEstate(): OwnEstate
{
    static $estate;

    return $estate ??= new OwnEstate('demostatestest', 'DST', 'Demo States Test Estate');
}

/**
 * The demonstration's finance, once a process. In a named function: a `static` inside `beforeEach`'s closure is copied
 * afresh for every test, so the seed ran before each one and moved the command's own statement onto the seeded one's
 * month (a duplicate key).
 */
function demoStatesSeedOnce(): void
{
    static $seeded = false;

    if ($seeded) {
        return;
    }

    // The central rate card the seeded pay runs are calculated against, seeded here as every payroll file does. Without
    // it PayrollSeeder makes no run at all, so September was there to calculate only when an earlier file had seeded it
    // (on this lane's own test database, 10 Oct, nothing had: "no pay run is open to calculate").
    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);
    (new EstateFinanceSeeder)->run();
    // The seeded statement is last month's; Phoenix Park's is older (seeded in September for August), so last month
    // is free for the new statement there. Moved three months back here, to be the same.
    BankReconciliation::query()->update(['statement_date' => SiteTime::today()->startOfMonth()->subMonths(3)->endOfMonth()->toDateString()]);
    $seeded = true;
}

beforeEach(function () {
    demoStatesEstate()->boot();
    demoStatesSeedOnce();

    DB::connection('mysql')->beginTransaction();
    config(['demo.enabled' => true]);
    $this->admin = demoStatesEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    demoStatesEstate()->boot();
});

/** How many rows of each kind carry the command's mark now. @return array<string, int> */
function demoStatesCounts(): array
{
    demoStatesEstate()->boot();
    $mine = static fn (string $table): int => DB::connection('tenant')->table($table)->where('seeded_by', PhoenixDemoStates::MARK)->count();

    return [
        'tickets' => $mine('maintenance_tickets'),
        'bookings' => $mine('amenity_bookings'),
        'meetings' => $mine('meetings'),
        'ballots' => $mine('ballots'),
        'bills' => $mine('bills'),
        'claims' => $mine('unit_claims'),
        'statements' => $mine('bank_reconciliations'),
    ];
}

/** Every kind, the bill included. */
function demoStatesEveryKind(): string
{
    return 'tickets,bookings,meetings,elections,motion,bills,payroll,claims,statement,invoices';
}

it('puts the committee\'s motion in draft on a roll of seven with Lot 47 first, tells nobody, and makes it once (M62 A9; M73 §1, step 7)', function () {
    demoStatesEstate()->boot();
    $lot47 = Unit::query()->where('reference', 'Lot 47')->with('household')->firstOrFail();
    expect($lot47->household)->not->toBeNull();

    // Run in any order beside the test that makes every default kind: the dry run is read only while the motion is not
    // there yet, and whichever made it, what is made is checked the same way.
    if (! Ballot::query()->where('title', PhoenixDemoStates::MOTION_TITLE)->exists()) {
        expect(Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--only' => 'motion']))->toBe(0);
        $dry = Artisan::output();
        demoStatesEstate()->boot();

        expect($dry)->toContain(PhoenixDemoStates::MOTION_TITLE)->toContain('Lot 47 (')->toContain('on a roll of seven')
            ->toContain('Nobody is told while it is a draft')
            ->and(Ballot::query()->where('title', PhoenixDemoStates::MOTION_TITLE)->exists())->toBeFalse();

        expect(Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--only' => 'motion', '--confirm' => true]))->toBe(0);
        demoStatesEstate()->boot();
    }

    $motion = Ballot::query()->where('title', PhoenixDemoStates::MOTION_TITLE)->firstOrFail();

    expect($motion->kind)->toBe(Ballot::RESOLUTION)
        ->and($motion->stage)->toBe(Ballot::DRAFT)
        ->and($motion->rollHouseholdIds())->toHaveCount(7)->toContain((int) $lot47->household->id)
        ->and(DB::connection('tenant')->table('ballots')->where('id', $motion->id)->where('seeded_by', PhoenixDemoStates::MARK)->exists())->toBeTrue();

    // A second run makes nothing twice.
    expect(Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--only' => 'motion', '--confirm' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('is there already');
    demoStatesEstate()->boot();
    expect(Ballot::query()->where('title', PhoenixDemoStates::MOTION_TITLE)->count())->toBe(1);
});

it('writes nothing without --confirm, and says what it would make', function () {
    $before = demoStatesCounts();

    expect(Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest']))->toBe(0);
    $said = Artisan::output();

    expect(demoStatesCounts())->toBe($before)
        ->and($said)->toContain('What --confirm would make')->toContain('Nothing is written without --confirm');
});

it('makes a record in each state it can make honestly, through the services, and says what it will not make', function () {
    $code = Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--confirm' => true]);
    $said = Artisan::output();
    expect($code)->toBe(0, $said);
    demoStatesEstate()->boot();

    $ticket = static fn (string $title): MaintenanceTicket => MaintenanceTicket::query()->where('title', $title)->where('seeded_by', PhoenixDemoStates::MARK)->firstOrFail();
    expect($ticket('Intercom crackles at the gate')->status)->toBe(MaintenanceTicket::ACKNOWLEDGED)
        ->and($ticket('Street light out by the playground')->status)->toBe(MaintenanceTicket::ASSIGNED)
        ->and($ticket('Low water pressure in the mornings')->status)->toBe(MaintenanceTicket::CANCELLED);
    $reopened = $ticket('Blocked drain at the kerb');
    expect(TicketActivity::query()->where('maintenance_ticket_id', $reopened->id)->where('event', TicketActivity::REOPENED)->exists())->toBeTrue();

    $booking = static fn (string $note): AmenityBooking => AmenityBooking::query()->where('notes', $note)->where('seeded_by', PhoenixDemoStates::MARK)->firstOrFail();
    expect($booking('Birthday party for about twenty children')->status)->toBe(AmenityBooking::PENDING)
        ->and($booking('Family reunion lunch')->status)->toBe(AmenityBooking::DECLINED)
        ->and($booking('Church youth group fellowship')->status)->toBe(AmenityBooking::CANCELLED)
        ->and($booking('Baby shower')->status)->toBe(AmenityBooking::EXPIRED);

    $meeting = static fn (string $title): Meeting => Meeting::query()->where('title', $title)->where('seeded_by', PhoenixDemoStates::MARK)->firstOrFail();
    expect($meeting('Gate contract renewal')->stage())->toBe(Meeting::STAGE_POSTPONED)
        ->and($meeting('Pool opening hours')->stage())->toBe(Meeting::STAGE_CANCELLED)
        ->and($meeting('Road repairs in Phase 2')->stage())->toBe(Meeting::STAGE_HELD)
        ->and($meeting('Landscaping tender')->stage())->toBe(Meeting::STAGE_ARCHIVED);

    // No election is made (Hassam's call: no governance history nobody can answer for; a vote is never written), and
    // the table says why; the one open for voting is what it reports. The committee's motion is a resolution, not an
    // election (M62 A9), and has a test of its own below.
    expect(DB::connection('tenant')->table('ballots')->where('seeded_by', PhoenixDemoStates::MARK)->where('kind', 'election')->count())->toBe(0)
        ->and($said)->toContain('candidate vetting')->toContain('a finished election needs votes')->toContain('open for voting');

    // The pay run: calculated and waiting for approval, never approved.
    expect(PayrollRun::query()->where('status', PayrollRun::CALCULATED)->whereNull('approved_at')->exists())->toBeTrue();

    $claim = static fn (string $phone): UnitClaim => UnitClaim::query()->where('submitted_phone', $phone)->where('seeded_by', PhoenixDemoStates::MARK)->firstOrFail();
    expect($claim('+1 876 555 0170')->status)->toBe(UnitClaim::PENDING)
        ->and($claim('+1 876 555 0171')->status)->toBe(UnitClaim::APPROVED)
        ->and($claim('+1 876 555 0172')->status)->toBe(UnitClaim::REJECTED);

    // Never on the user's own Lot 47.
    expect(AmenityBooking::query()->where('seeded_by', PhoenixDemoStates::MARK)->whereHas('unit', fn ($unit) => $unit->where('reference', 'Lot 47'))->exists())->toBeFalse();

    // The statement: five lines, each proposed for its own posted entry; all five matched, the period can be completed.
    $payables = app(Payables::class);
    $statement = $payables->currentReconciliation();
    $board = $payables->reconciliationBoard($statement);
    expect(BankReconciliation::query()->where('seeded_by', PhoenixDemoStates::MARK)->value('id'))->toBe($statement->id)
        ->and($statement->lines()->count())->toBe(5)
        ->and(collect($board['ledger_side'])->pluck('entry_ref')->filter()->count())->toBe(5)
        ->and($board['reconciliation']['can_complete'])->toBeFalse();

    foreach ($board['ledger_side'] as $side) {
        $payables->match(BankStatementLine::query()->findOrFail($side['line_id']), (string) $side['entry_ref']);
    }

    expect($payables->reconciliationBoard($statement->fresh())['reconciliation'])->toMatchArray(['difference_minor' => 0, 'can_complete' => true]);

    // What it will not make, and why, in the same table: the bill (unless asked for), the approved run, the invoices.
    expect($said)->toContain('--only=bills')->toContain('approving posts the month')->toContain('part paid');
});

it('approves a bill only when asked for it by name', function () {
    expect(Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--confirm' => true, '--only' => 'bills']))->toBe(0);
    demoStatesEstate()->boot();

    expect(Bill::query()->where('description', 'Guard house lighting rewired, Main Gate')->where('seeded_by', PhoenixDemoStates::MARK)->value('status'))->toBe(Bill::APPROVED)
        ->and(Artisan::output())->not->toContain('not made unless asked for by name');
});

it('writes no placeholder word and no 2020 date, and keeps its mark out of every field a person reads', function () {
    Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--confirm' => true, '--only' => demoStatesEveryKind()]);
    $said = Artisan::output();
    demoStatesEstate()->boot();

    // M60 §C1's list, the seeded placeholders' own words ("Lot 12 householder"), and the client's first name (M53 §2).
    $forbidden = '/\b(sample|smpl|test|demo|lorem)\b|john doe|householder|\bomar\b/i';
    $tables = collect(DB::connection('tenant')->select("SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'seeded_by'"))->pluck('t');
    $read = 0;

    foreach ($tables as $table) {
        foreach (DB::connection('tenant')->table($table)->where('seeded_by', PhoenixDemoStates::MARK)->get() as $row) {
            foreach ((array) $row as $column => $value) {
                if (! is_string($value)) {
                    continue;
                }

                $read++;
                expect(preg_match($forbidden, $value))->toBe(0, "{$table}.{$column} reads \"{$value}\"")
                    ->and(str_starts_with($value, '2020-'))->toBeFalse("{$table}.{$column} is dated {$value}");
            }
        }
    }

    // It read the rows it made (a `*` select never hands the mark back, so the mark itself is in none of them).
    expect($read)->toBeGreaterThan(50);

    // And what it prints, past the line naming this test's own estate.
    $printed = collect(explode("\n", $said))->reject(static fn (string $line): bool => str_contains($line, 'demostatestest'))->implode("\n");
    expect(preg_match($forbidden, $printed))->toBe(0, $printed);
});

it('makes nothing twice', function () {
    Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--confirm' => true, '--only' => demoStatesEveryKind()]);
    $first = demoStatesCounts();

    Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--confirm' => true, '--only' => demoStatesEveryKind()]);

    expect(demoStatesCounts())->toBe($first)
        ->and(Artisan::output())->toContain('is there already');
});

it('refuses outside the platform\'s demonstration mode, and writes nothing', function () {
    config(['demo.enabled' => false]);
    $before = demoStatesCounts();

    expect(Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--confirm' => true]))->not->toBe(0)
        ->and(Artisan::output())->toContain('demonstration mode is off')
        ->and(demoStatesCounts())->toBe($before);
});

it('writes to Phoenix Park Village 1 only on the live server', function () {
    $before = demoStatesCounts();
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        expect(Artisan::call('phoenix:demo-states', ['--estate' => 'demostatestest', '--confirm' => true]))->not->toBe(0)
            ->and(Artisan::output())->toContain('Phoenix Park Village 1 (phoenixparkvillage1) only');
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }

    expect(demoStatesCounts())->toBe($before);
});
