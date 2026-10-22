<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every estate console page, at Phoenix Park's real size, within its budget
|--------------------------------------------------------------------------
|
| M11 A2: "Find every N+1 query. A list of 50 units that fires 51 queries is
| the classic, and at your data volumes it's fatal." A page proven on the
| five-lot facilities fixture proves nothing about 2,000 lots, so every page
| here is opened against `EstateScaleFixture` — 2,000 lots, 441 households,
| 12,015 charges, built by the same seeder and the same phase table as
| production — and held to a query budget that does not grow with the estate.
|
| AS THE COMMUNITY SUPER ADMIN, because that role holds every module and may
| read the estate's own position, so it is served the most of every page: the
| arrears, the balances, the ageing, the money on the chart. A budget proven
| for the reader who is sent the most holds for every reader sent less.
|
| COLD, ON A FRESH APPLICATION. Each test is a new application, so the count
| includes what a real first request pays — the permission cache loading, the
| estate resolving — rather than a warm second request's.
|
| `M11_SCALE_REPORT=<file>` turns on measuring as well as asserting: each page
| is then also timed over five warm requests, and one JSON line per page is
| appended to that file. That is how the M11 report's before and after figures
| were taken; unset, this file only asserts.
|
*/

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Ballot;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\Nomination;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\Unit;
use App\Models\Estate\Vendor;
use App\Models\Role;
use App\Services\Estate\DashboardTiles;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\EstateScaleFixture;
use Tests\Support\QueryProbe;

beforeEach(function (): void {
    EstateScaleFixture::boot();
    EstateScaleFixture::matrix();

    DB::connection('mysql')->beginTransaction();

    EstateScaleFixture::enter();

    $this->withoutVite();

    $this->admin = EstateScaleFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    // And a committee around them, so the users screen lists nine people, not one.
    EstateScaleFixture::committee();

    // The fixture's connection again: creating the viewer reached the platform.
    EstateScaleFixture::boot();

    /*
     * The dashboard keeps its estate-wide figures between visits (`DashboardTiles`), and
     * the kept rows outlive a test in this reused estate. Thrown away first, so the cold
     * request below pays for computing them — the dearest load, not the kept one — and
     * the warm ones measured after it are the load every later visitor pays.
     */
    app(DashboardTiles::class)->forget();

    /*
     * And the estates' time zones, which SiteTime keeps for the life of the process. A PHP-FPM
     * worker starts every request without them, so a cold page pays that read; kept from the
     * test before, the first page of a run counted one query more than the rest (M12 §5.4).
     */
    SiteTime::flush();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * Open one page cold, assert it answered and stayed within budget, and — when
 * measuring — time it warm and write the figures down.
 */
function scalePage(object $test, string $label, string $path, int $budget): void
{
    $url = EstateScaleFixture::url($path);

    $cold = QueryProbe::measure(fn () => $test->actingAs($test->admin)->get($url));

    /** @var TestResponse $response */
    $response = $cold['result'];

    // Whatever the budget, the page has to have worked: a 500 runs few queries.
    expect($response->getStatusCode())->toBe(200, $label.' answered '.$response->getStatusCode()
        .($response->exception ? ': '.$response->exception->getMessage() : ''));

    $report = getenv('M11_SCALE_REPORT');

    if (is_string($report) && $report !== '') {
        $warm = [];
        $warmQueries = [];

        for ($i = 0; $i < 5; $i++) {
            $run = QueryProbe::measure(fn () => $test->actingAs($test->admin)->get($url));
            $warm[] = round($run['ms'], 1);
            $warmQueries[] = $run['tally']->count;
        }

        $sorted = $warm;
        sort($sorted);

        file_put_contents($report, json_encode([
            'label' => $label,
            'path' => $path,
            'status' => $response->getStatusCode(),
            'queries' => $cold['tally']->count,
            'db_ms' => round($cold['tally']->databaseMs, 1),
            'cold_ms' => round($cold['ms'], 1),
            'warm_ms' => $warm,
            'median_ms' => $sorted[2],

            // What a later visitor's request runs — fewer than the cold count only where a page keeps something.
            'warm_queries' => $warmQueries,
            'bytes' => strlen((string) $response->getContent()),
            'repeated' => $cold['tally']->mostRepeated(3),
            'slowest' => $cold['tally']->slowest(3),

            // Every statement, for reading a page's whole cost rather than its worst line.
            'shapes' => getenv('M11_SCALE_SHAPES') === '1' ? $cold['tally']->shapes : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);

        if (getenv('M11_SCALE_NO_ASSERT') === '1') {
            return;
        }
    }

    expect($cold['tally']->count)->toBeLessThanOrEqual($budget, sprintf(
        "%s ran %d queries at 2,000 lots against a budget of %d.\nMost repeated:\n%s",
        $label,
        $cold['tally']->count,
        $budget,
        $cold['tally']->describe(5),
    ));
}

it('builds the estate at the size production holds', function (): void {
    $report = getenv('M11_SCALE_REPORT');

    if (is_string($report) && $report !== '' && EstateScaleFixture::$builtInSeconds !== null) {
        file_put_contents($report, json_encode(['label' => '(fixture build)', 'seconds' => round(EstateScaleFixture::$builtInSeconds, 1)])."\n", FILE_APPEND | LOCK_EX);
    }

    expect(EstateScaleFixture::volumes())->toBe([
        'lots' => EstateScaleFixture::LOTS,
        'households' => EstateScaleFixture::HOUSEHOLDS,
        'charges' => EstateScaleFixture::CHARGES,
    ]);
});

/*
 * The pages. [label, path (a closure where it needs a record), budget].
 *
 * EACH BUDGET IS THE PAGE'S OWN, MEASURED HERE, PLUS FOUR — not the guard's
 * default of 60. Four is room for a page to gain a read of its own; it is not
 * room for a page of twenty-five households to go back to a query a household,
 * which is what these exist to catch. A page that needs more should say why in
 * the commit that raises it.
 *
 * RE-MEASURED ON THE MERGED BASE (a342c56). Its bug fixes made every page carry
 * the panic watch (B039, B103): the alerts still outstanding here, and whether
 * this is the demonstration estate — two reads a request, the same two on
 * nearly every line below, and neither a read per row. The household page's
 * invites panel was one query a member until `ResidentInvites::latestForEach()`.
 */
dataset('estate pages', [
    'dashboard' => ['Dashboard', '/', 47],
    'activity' => ['Activity log', '/activity', 27],
    'activity deep' => ['Activity log, page 5', '/activity?page=5', 27],
    'notifications' => ['Notifications', '/notifications', 21],
    'messages' => ['Messages', '/messages', 16],
    // +2 for M32 F2 and F6, the contract card and the lots panel: the units once, and the phases they are filed under once.
    // +2 for M57.5 §4.1, the site plan: whether the phase is worked out from the lot (one look at the column), and the
    // units' lots once for "Built 1,999 on the site plan" and any unit off the plan. Both once per page, at any size.
    'structure' => ['Estate structure', '/estate', 23],
    // +1 for the Deleted accounts badge (M18H §3.1): one count of the estate's deleted resident accounts, once per page.
    // M32 F1: the register opens on People; the household list these three measured is the Households tab. +1 there for
    // which household of a lot each row opens (one read of the page's lots). The search's page 2 now holds households in
    // arrears and with pending invites, so it pays those two reads as the unsearched page does: the same 27.
    'residents' => ['Residents register, Households', '/residents?view=households', 27],
    'residents search' => ['Residents register, Households, searched and sorted', '/residents?view=households&q=Lot%201&sort=unit&dir=desc&page=2', 27],
    'residents phase' => ['Residents register, Households, one phase', '/residents?view=households&phase=Phase%204', 27],
    // Measured 22 each, plus four.
    'residents people' => ['Residents register, People', '/residents', 26],
    'residents people search' => ['Residents register, People, searched and sorted', '/residents?q=Lot%201&sort=lot&dir=desc&page=2', 26],
    'residents people phase' => ['Residents register, People, one phase', '/residents?phase=Phase%204&per=200', 26],
    // +2 for the live shell (M11 Part C, W0): the estate log's head and the grant's access check, once per page, whatever the estate's size.
    // +1 for M32 F5's page numbers: the queue's table counts the whole set once (`paginate()`), however long it is.
    'claims' => ['Unit claims', '/residents/claims', 27],
    'new resident' => ['Add resident', '/residents/new', 17],
    // +4 for M32 F1's panel when the lot is occupied: its households, their people, and the balance and oldest open charge.
    'new resident lot' => ['Add resident, a unit typed', '/residents/new?phase=Phase%202&lot=47', 24],
    // +1 for M98's "Who has lived here": every household the lot has held, its people joined in, one read, whatever the
    // lot's history.
    'household' => ['Household (Lot 47)', '/residents/lot-47', 28],
    'arrears' => ['Arrears register', '/finance/arrears', 21],
    'arrears phase' => ['Arrears register, one phase', '/finance/arrears?phase=Phase%202', 21],
    'arrears search' => ['Arrears register, searched and sorted', '/finance/arrears?q=Lot%201&sort=balance&dir=desc&page=2', 21],
    'arrears overdue' => ['Arrears register, 90+ days, by last payment', '/finance/arrears?overdue=1&sort=last_payment&dir=desc', 21],
    'arrears nothing' => ['Arrears register, a search nothing matches', '/finance/arrears?q=nothing-is-called-this', 18],
    // 24, not 23 (M53 §2, 9 October): the page's charges with their sample mark, and the seed's spans, for each line's
    // Reverse. Two reads, whatever the page's length.
    // 25, not 24 (EST-S-02, M85 A3, 11 October): the unit's charges due by today, once, so its strip puts each charge in
    // its own bucket. One read, whatever the ledger's length.
    'unit ledger' => ['Unit ledger (Lot 47)', fn (): string => '/finance/units/'.Unit::query()->where('reference', 'Lot 47')->value('id'), 25],
    'test checkout' => ['Test card checkout (Lot 47)', fn (): string => '/finance/units/'.Unit::query()->where('reference', 'Lot 47')->value('id').'/test-checkout', 19],
    'receipts' => ['Receipt register', '/finance/receipts', 22],
    'receipts deep' => ['Receipt register, page 40', '/finance/receipts?page=40', 22],
    'receipts search' => ['Receipt register, searched', '/finance/receipts?q=Lot%2047', 22],
    'new charge' => ['Post a charge', '/finance/charges/new', 21],
    'payment plans' => ['Payment plans', '/finance/payment-plans', 19],
    'charge schedule' => ['Charge schedule', '/finance/charge-schedule', 21],
    'unit plan' => ['Payment plan (Lot 47)', fn (): string => '/finance/units/'.Unit::query()->where('reference', 'Lot 47')->value('id').'/payment-plan', 25],
    'dunning' => ['Dunning', '/finance/dunning', 21],
    'chart' => ['Chart of accounts', '/accounting/chart-of-accounts', 23],
    'vendors' => ['Vendors', '/accounting/vendors', 20],
    // +3 for M98's work orders on a supplier's page: its jobs, their lots, and both counts, one read each, whatever the
    // number of jobs (measured 29 for a supplier whose jobs name no lot).
    'vendor' => ['Vendor', fn (): string => '/accounting/vendors/'.Vendor::query()->orderBy('id')->value('id'), 30],
    'bills' => ['Bills', '/accounting/bills', 27],
    'reconciliation' => ['Reconciliation', '/accounting/reconciliation', 22],
    'payroll runs' => ['Payroll runs', '/payroll', 22],
    'payroll run' => ['Payroll run', fn (): string => '/payroll/runs/'.PayrollRun::query()->orderByDesc('id')->value('slug'), 20],
    'payroll exceptions' => ['Payroll run exceptions', fn (): string => '/payroll/runs/'.PayrollRun::query()->orderByDesc('id')->value('slug').'/exceptions', 17],
    'employees' => ['Employees', '/payroll/employees', 16],
    'filings' => ['Statutory filings', '/payroll/filings', 17],
    'filing calendar' => ['Filing calendar', '/payroll/filings/calendar', 18],
    // +1 each for M32 F5's page numbers: the queue is counted once (`paginate()`), however many tickets it holds.
    // +1 each for M98's service targets: the estate's settings row, read once.
    'maintenance' => ['Maintenance queue', '/facilities/maintenance', 21],
    'maintenance unit search' => ['Maintenance queue, finding a lot for a work order', '/facilities/maintenance?unit_q=19', 22],
    // +3 for M98: the service targets (the settings row), the job's bills with their suppliers joined in, and the
    // expense accounts Record a bill offers, one read each, whatever the ledger's size. Its suppliers are the list
    // Assign already reads.
    'ticket' => ['Maintenance ticket', fn (): string => '/facilities/maintenance/'.MaintenanceTicket::query()->orderBy('number')->value('number'), 22],
    // +1 for M32 F5's page numbers: the bookings are counted once (`paginate()`).
    'bookings' => ['Amenity bookings', '/facilities/amenities/bookings', 21],
    'amenities' => ['Amenity settings', '/facilities/amenities/settings', 17],
    'booking' => ['Amenity booking', fn (): string => '/facilities/amenities/bookings/'.AmenityBooking::query()->orderBy('id')->value('id'), 19],
    'notices' => ['Notices', '/governance/notices', 21],
    // +2 for M56 §3's life panel: the challenges recorded on the election, and the year's ballots it may act on, once each.
    // +1 for M62 B1 and M65 §3: the election read once more, for the count of households Open voting names before the
    // poll opens (Governance::eligibleHouseholdCount).
    'election' => ['Election control room', fn (): string => '/governance/elections/'.Ballot::query()->max('year'), 25],
    // +1 for M56 §3.3's "Add a candidate": the year's ballots still taking nominations, with their seats, read once.
    'nominations' => ['Nominations', fn (): string => '/governance/elections/'.Ballot::query()->max('year').'/nominations', 20],
    'nomination' => ['Nomination', fn (): string => '/governance/nominations/'.Nomination::query()->orderBy('id')->value('id'), 19],
    'results' => ['Election results', fn (): string => '/governance/elections/'.Ballot::query()->max('year').'/results', 24],
    'meetings' => ['Meetings', '/governance/meetings', 19],
    // +1 for M60 §C2: the committee's head count, which a committee meeting's quorum readout says ("25% of the
    // committee's 7 members"), once, whatever the estate's size.
    'new meeting' => ['New meeting', '/governance/meetings/new', 19],
    // +3 for M56 §2's life panel: how many households an act tells (one count), the attendance register, and the
    // notice period for the date picker's earliest day, each once, whatever the estate's size.
    'meeting' => ['Meeting', fn (): string => '/governance/meetings/'.Meeting::query()->orderBy('id')->value('id'), 24],
    'reports' => ['Reports', '/reports', 15],
    'report p&l' => ['Report: profit and loss', '/reports/profit_and_loss', 16],
    'report ageing' => ['Report: arrears ageing', '/reports/arrears_ageing', 24],
    'report maintenance' => ['Report: maintenance summary', '/reports/maintenance_summary', 16],
    'report incidents' => ['Report: security incidents', '/reports/security_incidents', 16],
    'report turnout' => ['Report: election turnout', '/reports/election_turnout', 24],
    'report minutes' => ['Report: meeting minutes', '/reports/meeting_minutes', 18],
    // +2 for the map (M11 §6): the estate's posts and its checkpoints, one read each, whatever the estate's size.
    // +1 for the estate's own fence (M12 §1, D-178): one read of estate_sites.
    // 28, not 27: the map of posts and officers (D-174) reads four small things — may this estate see
    // positions, its posts, its checkpoints, its own circle — none of them once per lot or officer.
    // +2 for M32 F5's three paged tables (alerts, incidents, the gate's log): each counts its whole set once, and
    // `paginate()` skips the page's own read when the count is nought, so a table with no rows costs nothing more.
    'security' => ['Security', '/security', 30],
    // Asked every 15 seconds by any console page while an alert is open or the socket is down.
    'panic watch' => ['Security: the panic watch poll', '/security/panic-watch', 13],
    'settings profile' => ['Settings: profile', '/settings/profile', 17],
    // +1 for the live shell (M11 Part C, W0), once per page, whatever the estate's size.
    'settings users' => ['Settings: users', '/settings/users', 33],
    'settings features' => ['Settings: features', '/settings/features', 21],
    'settings roles' => ['Settings: roles', '/settings/roles', 29],
    'settings notifications' => ['Settings: notifications', '/settings/notifications', 18],
    'settings privacy' => ['Settings: privacy', '/settings/privacy', 18],
    'settings billing' => ['Settings: billing', '/settings/billing', 16],
]);

it('opens within its query budget at 2,000 lots', function (string $label, string|Closure $path, int $budget): void {
    $resolved = $path instanceof Closure ? $path() : $path;

    scalePage($this, $label, $resolved, $budget);
})->with('estate pages');

it('serves the dashboard again from the figures it kept, without reading the receivable', function (): void {
    $url = EstateScaleFixture::url('/');

    // The first visit computes and keeps them (the budget above is for that one).
    $this->actingAs($this->admin)->get($url)->assertOk();

    $kept = QueryProbe::measure(fn () => $this->actingAs($this->admin)->get($url));
    $kept['result']->assertOk();

    expect(implode("\n", array_keys($kept['tally']->shapes)))
        ->not->toContain('SUM(journal_lines.debit_minor - journal_lines.credit_minor) as balance')
        ->not->toContain('from `maintenance_tickets` order by `number` desc')
        // 31: one more than before for the live shell (M11 Part C, W0), once per page, whatever the estate's size.
        ->and($kept['tally']->count)->toBeLessThanOrEqual(31, $kept['tally']->describe(5));
});
