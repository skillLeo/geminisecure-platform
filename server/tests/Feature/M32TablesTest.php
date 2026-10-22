<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Notice;
use App\Models\Estate\Vendor;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Notices;
use App\Services\Estate\Payables;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M32 F5 §8.4 — every table is ordered and paged over the whole set, and its place is its address
|--------------------------------------------------------------------------
|
| Three tables, each built the way the others are: Vendors and Notices are whole sets ordered in PHP
| (`TableQuery::pageOf()`), the maintenance queue is ordered and paged in SQL. Sixty rows of each are this
| file's own, made inside a transaction that is rolled back, and named so one search finds exactly them —
| sixty is three pages at twenty-five: 25, 25 and 10.
|
| The words in the address are `sort`, `dir`, `page`, `per` and `q`. The component's half — which link a
| heading, a page number or a page size carries — is `tests/js/data-table.test.mjs`; this is the server's.
|
*/

const M32_TABLE_ROWS = 60;

beforeEach(function () {
    FacilitiesFixture::boot();

    DB::connection('tenant')->beginTransaction();

    $now = Carbon::now();

    for ($i = 1; $i <= M32_TABLE_ROWS; $i++) {
        // A permutation of 1–60 (7 and 61 share no factor), so no column's order is the order the rows were made in.
        $p = $i * 7 % 61;

        Vendor::query()->create([
            'name' => 'M32 Table Vendor '.$p,
            'category' => ['Plumbing', 'Electrical', 'Landscaping', 'Security', 'Cleaning'][$i % 5],
            'contact_name' => 'Contact '.$i,
            'status' => $i % 4 === 0 ? Vendor::INACTIVE : Vendor::ACTIVE,
        ]);

        Notice::query()->create([
            'kind' => $i % 3 === 0 ? Notice::URGENT : Notice::GENERAL,
            'title' => 'M32 Table Notice '.$p,
            'body' => 'One of sixty.',
            'audience_scope' => Notice::ESTATE_WIDE,
            'author_name' => 'Estate office',
            'published_at' => $now->copy()->subHours($i * 13 % 61),
        ]);

        MaintenanceTicket::query()->create([
            'number' => 90_000 + $p,
            'title' => 'M32 Table Ticket '.$i,
            'location_label' => 'Block '.chr(65 + $i % 26),
            'category' => 'Grounds',
            'priority' => [MaintenanceTicket::HIGH, MaintenanceTicket::MEDIUM, MaintenanceTicket::LOW][$i % 3],
            'status' => MaintenanceTicket::SUBMITTED,
            'reported_by_name' => 'Estate office',
            'reported_at' => $now->copy()->subHours($i * 11 % 61 + 1),
            'sla_hours' => 72,
        ]);
    }
});

afterEach(function () {
    DB::connection('tenant')->rollBack();
});

/**
 * The three tables: how each is read, the search that finds exactly this file's sixty, and three of its columns with
 * the value a row is ordered by and how two of those values compare.
 *
 * @return array<string, array{read: Closure, sorts: array<string, mixed>, default: array{0: string, 1: string}, q: string, columns: array<string, array{0: Closure, 1: Closure}>}>
 */
function m32Tables(): array
{
    $natural = static fn (mixed $a, mixed $b): int => strnatcasecmp((string) $a, (string) $b);
    $number = static fn (mixed $a, mixed $b): int => $a <=> $b;
    // Low to high ascending, as an incident's severity sorts: descending is the most urgent first.
    $priority = [MaintenanceTicket::LOW => 0, MaintenanceTicket::MEDIUM => 1, MaintenanceTicket::HIGH => 2];

    return [
        'vendors' => [
            'read' => static fn (TableQuery $table): array => app(Payables::class)->vendorsBoard(true, $table),
            'sorts' => Payables::vendorSorts(),
            'default' => ['name', 'asc'],
            'q' => 'M32 Table Vendor',
            'columns' => [
                'name' => [static fn (array $row): string => $row['name'], $natural],
                'trade' => [static fn (array $row): string => $row['trade'], $natural],
                'status' => [static fn (array $row): string => (string) $row['status'], $natural],
            ],
        ],
        'notices' => [
            'read' => static fn (TableQuery $table): array => app(Notices::class)->board($table),
            'sorts' => Notices::sorts(),
            'default' => ['posted', 'desc'],
            'q' => 'M32 Table Notice',
            'columns' => [
                'posted' => [static fn (array $row): string => (string) $row['posted_sort'], $natural],
                'title' => [static fn (array $row): string => $row['title'], $natural],
                'kind' => [static fn (array $row): string => $row['kind_label'], $natural],
            ],
        ],
        'maintenance' => [
            'read' => static fn (TableQuery $table): array => app(Maintenance::class)->queueBoard('all', null, $table),
            'sorts' => Maintenance::queueSorts(),
            'default' => ['number', 'desc'],
            'q' => 'M32 Table Ticket',
            'columns' => [
                'number' => [static fn (array $row): int => (int) $row['number'], $number],
                'priority' => [static fn (array $row): int => $priority[$row['priority']], $number],
                // Age is how long ago it was reported, so the oldest is the "largest".
                'age' => [static fn (array $row): int => -MaintenanceTicket::query()->findOrFail($row['id'])->reported_at->getTimestamp(), $number],
            ],
        ],
    ];
}

/** One of the three tables as an address asks for it. */
function m32Table(string $name, ?string $sort = null, ?string $dir = null, int $page = 1, int $per = 25): TableQuery
{
    $table = m32Tables()[$name];

    return TableQuery::make($table['sorts'], $table['default'][0], $table['default'][1], $sort, $dir, $page, $per, $table['q']);
}

/** Every page of a table, in order: the rows read, and the last page's meta. */
function m32Walk(string $name, ?string $sort = null, ?string $dir = null, int $per = 25): array
{
    $rows = [];
    $page = 1;

    do {
        $read = m32Tables()[$name]['read'](m32Table($name, $sort, $dir, $page, $per));
        $rows = [...$rows, ...$read['rows']];
        $page++;
    } while ($page <= $read['table']['last']);

    return ['rows' => $rows, 'table' => $read['table']];
}

it('orders the whole set, both ways, on three columns of three tables', function () {
    foreach (m32Tables() as $name => $table) {
        foreach ($table['columns'] as $column => [$value, $compare]) {
            foreach (['asc' => 1, 'desc' => -1] as $dir => $sign) {
                $walk = m32Walk($name, $column, $dir);
                $rows = $walk['rows'];

                // The whole set, each row once — across three pages, not within one.
                expect($walk['table']['total'])->toBe(M32_TABLE_ROWS, "{$name} by {$column} {$dir}")
                    ->and($walk['table']['last'])->toBe(3)
                    ->and(count($rows))->toBe(M32_TABLE_ROWS)
                    ->and(array_unique(array_column($rows, 'id')))->toHaveCount(M32_TABLE_ROWS);

                // And in order from the first row of page one to the last row of page three, ties by the row's own key
                // the same way round — so a page boundary never splits a tie differently on the next request.
                for ($i = 1; $i < count($rows); $i++) {
                    $order = $sign * $compare($value($rows[$i - 1]), $value($rows[$i]));

                    expect($order)->toBeLessThanOrEqual(0, "{$name} by {$column} {$dir}: row {$i} is out of order");

                    if ($order === 0) {
                        expect($sign * ($rows[$i - 1]['id'] <=> $rows[$i]['id']))->toBe(-1, "{$name} by {$column} {$dir}: a tie at row {$i}");
                    }
                }
            }
        }
    }
});

it('serves page 2 of 3 as rows 26 to 50 of the whole order, with nothing twice and nothing missed', function () {
    foreach (array_keys(m32Tables()) as $name) {
        $read = m32Tables()[$name]['read'];
        $whole = array_column($read(m32Table($name, per: 200))['rows'], 'id');

        $pages = [1, 2, 3];
        $ids = array_map(static fn (int $page): array => array_column($read(m32Table($name, page: $page))['rows'], 'id'), $pages);
        $second = $read(m32Table($name, page: 2))['table'];

        expect($whole)->toHaveCount(M32_TABLE_ROWS)
            ->and($ids[1])->toBe(array_slice($whole, 25, 25), $name)
            ->and($second)->toMatchArray(['page' => 2, 'last' => 3, 'from' => 26, 'to' => 50, 'total' => M32_TABLE_ROWS])
            ->and(array_merge(...$ids))->toBe($whole, "{$name}: the three pages are the whole set in order, once each")
            ->and($ids[2])->toHaveCount(10);
    }
});

it('keeps the first row of the page in view when the page size changes', function () {
    foreach (array_keys(m32Tables()) as $name) {
        $read = m32Tables()[$name]['read'];
        $third = $read(m32Table($name, page: 3));
        $first = $third['rows'][0]['id'];

        expect($third['table']['from'])->toBe(51);

        // The component's rule (`DataTable.vue`, `sizeHref`): the page that row falls on at the new size.
        foreach ([50, 100, 200] as $size) {
            $page = intdiv($third['table']['from'] - 1, $size) + 1;

            // `toContain()` takes needles, not a message: the message goes on a matcher that has one.
            expect(in_array($first, array_column($read(m32Table($name, page: $page, per: $size))['rows'], 'id'), true))->toBeTrue("{$name} at {$size}");
        }
    }
});

it('reads all five words from the address, and the same address reads back the same page', function () {
    $address = '/accounting/vendors?sort=trade&dir=desc&page=2&per=25&q='.rawurlencode('M32 Table Vendor');
    $read = static fn (): array => app(Payables::class)->vendorsBoard(true, Payables::vendorsTable(Request::create($address)));

    $once = $read();
    $again = $read();

    expect($once['table'])->toMatchArray(['sort' => 'trade', 'dir' => 'desc', 'page' => 2, 'per' => 25, 'q' => 'M32 Table Vendor', 'total' => M32_TABLE_ROWS])
        ->and(array_column($again['rows'], 'id'))->toBe(array_column($once['rows'], 'id'))
        ->and(array_column($once['rows'], 'id'))->toBe(array_slice(array_column(m32Walk('vendors', 'trade', 'desc')['rows'], 'id'), 25, 25));
});

it('keeps the search when a heading sorts, and starts a search on page one', function () {
    // A page and a sort asked for with the search: the search still narrows, and the sort orders what it found.
    $sorted = app(Notices::class)->board(Notices::table(Request::create('/governance/notices?q=M32+Table+Notice&sort=title&dir=asc&page=2')));

    expect($sorted['table'])->toMatchArray(['q' => 'M32 Table Notice', 'sort' => 'title', 'page' => 2, 'total' => M32_TABLE_ROWS])
        ->and(collect($sorted['rows'])->every(static fn (array $row): bool => str_contains($row['title'], 'M32 Table Notice')))->toBeTrue();

    // A search arrives with no page — the console's search box sends the table's order and size, never its page
    // (`keptByTable()`) — and so it reads from the first.
    $searched = app(Notices::class)->board(Notices::table(Request::create('/governance/notices?q=M32+Table+Notice&sort=title&dir=asc')));

    expect($searched['table'])->toMatchArray(['page' => 1, 'from' => 1, 'q' => 'M32 Table Notice', 'sort' => 'title']);
});

it('carries the five words through a real request, and the same request draws the same page', function () {
    // The seeded queue, committed, read over HTTP: the controller hands the address to the table and back.
    DB::connection('tenant')->rollBack();

    try {
        $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
        $address = FacilitiesFixture::url('/facilities/maintenance?sort=priority&dir=desc&page=1&per=50&q=Phase');
        $props = [];

        foreach ([0, 1] as $time) {
            $this->withoutVite()->actingAs($manager)->get($address)->assertOk()
                ->assertInertia(function (AssertableInertia $page) use (&$props, $time): void {
                    $props[$time] = $page->toArray()['props'];
                });

            FacilitiesFixture::boot();
        }

        expect($props[0]['table'])->toMatchArray(['sort' => 'priority', 'dir' => 'desc', 'page' => 1, 'per' => 50, 'q' => 'Phase'])
            ->and($props[0]['rows'])->not->toBeEmpty()
            ->and(collect($props[0]['rows'])->every(static fn (array $row): bool => str_contains($row['location'].' '.$row['title'], 'Phase')))->toBeTrue()
            ->and(array_column($props[1]['rows'], 'id'))->toBe(array_column($props[0]['rows'], 'id'));

        // The most urgent first, descending: the order asked for, over what the search found.
        $rank = [MaintenanceTicket::LOW => 0, MaintenanceTicket::MEDIUM => 1, MaintenanceTicket::HIGH => 2];
        $ranks = array_map(static fn (array $row): int => $rank[$row['priority']], $props[0]['rows']);

        expect($ranks)->toBe(collect($ranks)->sortDesc()->values()->all());
    } finally {
        DB::connection('tenant')->beginTransaction();
    }
});
