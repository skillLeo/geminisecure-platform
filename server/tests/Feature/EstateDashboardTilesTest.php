<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The dashboard's estate-wide tiles are kept, and never kept wrong — M11 A2
|--------------------------------------------------------------------------
|
| "Dashboard tiles that count or sum across the whole estate must not compute on
| every page load. Cache them with a sensible TTL, or maintain them
| incrementally, and show when the figure was computed."
|
| `DashboardTiles` keeps them in the estate's own `dashboard_snapshots`, stamped
| with the data they were computed from. What has to be true, and is proven here
| at Phoenix Park's real size:
|
|   - the kept figures ARE the services' figures, not a second arithmetic;
|   - a second dashboard load reads the snapshot instead of the ledger;
|   - a charge posted, a household leaving its lot or a ticket closed — even twice
|     within the same second — is on the very next load;
|   - nothing is served older than the TTL, whatever its stamp says;
|   - a viewer who may not read the estate's position never causes it to be
|     computed or read, and is never told when it was.
|
| Inside a transaction on the estate's connection, rolled back, for everything
| that writes; the one HTTP test writes nothing but the snapshot itself.
|
*/

use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Estate\DashboardTiles;
use App\Services\Estate\Dues;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Residents;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\EstateScaleFixture;
use Tests\Support\QueryProbe;

beforeEach(function (): void {
    EstateScaleFixture::boot();

    $this->tiles = app(DashboardTiles::class);
    $this->tenant = DB::connection('tenant');
    $this->tenant->beginTransaction();

    // Start from nothing kept, so the first read in each test is a computation.
    $this->tiles->forget();
});

afterEach(function (): void {
    if ($this->tenant->transactionLevel() > 0) {
        $this->tenant->rollBack();
    }
});

it('keeps exactly the figures the services give', function (): void {
    $dues = app(Dues::class);

    $open = collect(app(Maintenance::class)->queueBoard()['kpis'])->firstWhere('key', 'open')['value'];

    expect($this->tiles->structure())->toMatchArray([
        'units' => EstateScaleFixture::LOTS,
        // The estate's households, as the structure page counts them (M87 §1.4), never the units flagged occupied.
        'occupied' => app(Residents::class)->structureBoard()['totals']['occupied'],
        'open_maintenance' => (int) $open,
    ])->and($this->tiles->position())->toMatchArray([
        'outstanding_minor' => array_sum($dues->ageing()),
        'phases' => $dues->arrearsByPhase(),
    ]);

    // And a figure worth keeping: the estate is owed something, in more than one phase.
    expect($this->tiles->position()['outstanding_minor'])->toBeGreaterThan(0)
        ->and(array_filter($this->tiles->position()['phases']))->not->toHaveCount(0);
});

/** The statement every estate-wide balance is read with — `Dues::balancesQuery()`. */
const M11_TILES_BALANCE_AGGREGATE = 'SUM(journal_lines.debit_minor - journal_lines.credit_minor) as balance';

/**
 * The tile's own walk of the tickets, `Maintenance::tiles()` (M32 F5: board 17's tile, not a page of its queue), whatever
 * columns it reads after the four it always has. Pinned word for word, this went red when the walk began reading
 * `category` (the facilities lane), and failed the first load's check before the second load was ever counted.
 */
const M11_TILES_TICKET_WALK = '/select `id`, `status`, `reported_at`, `sla_hours`, `closed_at`(, `[a-z_]+`)* from `maintenance_tickets`/';

it('serves a second load from the snapshot, reading neither the ledger nor the tickets', function (): void {
    // The first load computes, and is seen to: the marker the second is checked for is real.
    $first = QueryProbe::measure(fn (): array => [$this->tiles->structure(), $this->tiles->position()]);

    expect(implode("\n", array_keys($first['tally']->shapes)))->toContain(M11_TILES_BALANCE_AGGREGATE)
        ->toMatch(M11_TILES_TICKET_WALK);

    $again = QueryProbe::measure(fn (): array => [$this->tiles->structure(), $this->tiles->position()]);
    $sql = implode("\n", array_keys($again['tally']->shapes));

    // Two statements a part: the stamp, and the kept row.
    expect($again['tally']->count)->toBe(4, $again['tally']->describe(6))
        ->and($sql)->not->toContain(M11_TILES_BALANCE_AGGREGATE)
        ->and($sql)->not->toContain('journal_lines')
        ->and($sql)->not->toMatch(M11_TILES_TICKET_WALK)
        ->and($sql)->not->toContain('`charges`');
});

it('recomputes the position the moment a charge is posted, and says when', function (): void {
    $before = $this->tiles->position();

    $this->travel(2)->minutes();

    app(Dues::class)->charge(Unit::query()->where('reference', 'Lot 12')->firstOrFail(), Money::of('777.00', 'JMD'), 'M11 tile proof', now()->subDays(3));

    $after = $this->tiles->position();

    expect($after['outstanding_minor'])->toBe($before['outstanding_minor'] + 777_00)
        ->and($after['computed_at']->greaterThan($before['computed_at']))->toBeTrue();
});

it('recomputes the structure the moment a household leaves its lot or comes back, even twice in the same second', function (): void {
    // The occupied tile counts the estate's households (M87 §1.4), so a household moving is what moves it.
    $household = Household::query()->counted()->orderBy('id')->firstOrFail();
    $occupied = $this->tiles->structure()['occupied'];

    $household->forceFill(['removed_at' => now()])->save();
    expect($this->tiles->structure()['occupied'])->toBe($occupied - 1);

    // The same second, the same row, the same count and highest id: only the checksum sees it.
    $household->forceFill(['removed_at' => null])->save();
    expect($this->tiles->structure()['occupied'])->toBe($occupied);
});

it('does not move the occupied tile for a unit flagged occupied with no household behind it (M87 §1.4)', function (): void {
    $occupied = $this->tiles->structure()['occupied'];

    Unit::query()->where('status', 'vacant')->whereDoesntHave('households')->orderBy('id')->firstOrFail()->forceFill(['status' => 'occupied'])->save();

    expect($this->tiles->structure()['occupied'])->toBe($occupied);
});

it('recomputes the open-ticket tile the moment a ticket is closed', function (): void {
    // A ticket the Open tile counts: nobody has the job yet (M68 §2.3, MaintenanceTicket::GROUPS).
    $ticket = MaintenanceTicket::query()->whereIn('status', MaintenanceTicket::GROUPS['open'])->orderBy('id')->firstOrFail();
    $open = $this->tiles->structure()['open_maintenance'];

    $ticket->forceFill(['status' => MaintenanceTicket::CANCELLED])->save();

    expect($this->tiles->structure()['open_maintenance'])->toBe($open - 1);
});

it('never serves a figure older than the TTL, whatever its stamp says', function (): void {
    $first = $this->tiles->structure();

    $this->travel(DashboardTiles::TTL_SECONDS - 5)->seconds();
    expect($this->tiles->structure()['computed_at']->equalTo($first['computed_at']))->toBeTrue();

    $this->travel(10)->seconds();
    expect($this->tiles->structure()['computed_at']->greaterThan($first['computed_at']))->toBeTrue();
});

it('never reads or computes the position for a viewer it is withheld from, nor tells them when it was taken', function (): void {
    // Over HTTP: the request opens the estate on a connection of its own, so this test's
    // transaction is closed first and the only thing the requests write is the snapshot.
    $this->tenant->rollBack();

    EstateScaleFixture::matrix();
    DB::connection('mysql')->beginTransaction();

    try {
        EstateScaleFixture::enter();
        $this->withoutVite();

        $assistant = EstateScaleFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);
        EstateScaleFixture::boot();
        $this->tiles->forget();

        $seen = QueryProbe::measure(fn () => $this->actingAs($assistant)->get(EstateScaleFixture::url('/')));
        $sql = implode("\n", array_keys($seen['tally']->shapes));

        $seen['result']->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('arrears.positionWithheld', true)
            ->where('arrears.total_minor', null)
            ->where('computed.position', null)
            ->where('computed.structure', fn (string $line): bool => str_starts_with($line, 'Estate totals as at ')));

        expect($sql)->not->toContain(M11_TILES_BALANCE_AGGREGATE);

        EstateScaleFixture::boot();

        expect(DB::connection('tenant')->table('dashboard_snapshots')->where('key', 'position')->exists())->toBeFalse()
            ->and(DB::connection('tenant')->table('dashboard_snapshots')->where('key', 'structure')->exists())->toBeTrue();

        // And for the Treasurer, the same page carries both, with the time each was taken.
        $owed = array_sum(app(Dues::class)->ageing());
        $treasurer = EstateScaleFixture::viewer(Role::TREASURER);
        EstateScaleFixture::boot();

        $this->actingAs($treasurer)->get(EstateScaleFixture::url('/'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('arrears.total_minor', $owed)
            ->where('arrears.positionWithheld', false)
            ->where('computed.position', fn (string $line): bool => str_starts_with($line, 'As at ')));
    } finally {
        DB::connection('mysql')->rollBack();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        EstateScaleFixture::boot();
        DB::connection('tenant')->table('dashboard_snapshots')->delete();
    }
});
