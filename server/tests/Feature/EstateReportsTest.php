<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Role;
use App\Models\SecurityIncident;
use App\Services\Estate\Budgets;
use App\Services\Estate\Reports;
use App\Services\Rbac\RoleEditor;
use App\Support\SiteTime;
use Brick\Money\Money;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| The estate's own reports — boards 29 and 30 (12 §1)
|--------------------------------------------------------------------------
|
| "All four report generators behind board 30." The minutes archive joined
| them because its own reason — "this platform stores no files yet" — stopped
| being true the day documents shipped.
|
| EVERY REPORT STATES ITS PERIOD, and that is the P&L card's own old warning
| kept: a report with an unstated period is the defect that surfaces at an
| audit. The window is on the screen, in the filename and in the audit scope.
|
| AN ESTATE OF ITS OWN (M11 B5). These reports need the 450-lot estate with six
| months of dues, so `EstateFinanceSeeder` builds it — in `reportstest`, which
| no other file uses. It used to build it over `FacilitiesFixture`'s SHARED
| estate, where the seeder's guard found no 450 lots and truncated the estate
| every other facilities file stands on, in the middle of the process.
| `Tests\Support\OwnEstate` has the whole account.
|
*/

/** This file's own estate: its subdomain, its tenant id and its database suffix. */
function reportsEstate(): OwnEstate
{
    static $estate = null;

    return $estate ??= new OwnEstate('reportstest', 'RPT', 'Reports Test Estate');
}

beforeEach(function () {
    reportsEstate()->boot();

    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    // A role made in a test is rolled back with it, and must not live on in
    // the per-process permission cache for the next file to be granted by.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Carbon::setTestNow();
});

it('runs the six built reports over a stated period and refuses the one with a data gap', function () {
    $treasurer = reportsEstate()->viewer(Role::TREASURER);
    $secretary = reportsEstate()->viewer(Role::SECRETARY);

    $this->actingAs($treasurer)
        ->get(reportsEstate()->url('/reports'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canGenerate', true));

    // The Secretary holds View on Reports and generates nothing — export is
    // withheld from anything below Full, so the books never leave in a file.
    $this->actingAs($secretary)
        ->get(reportsEstate()->url('/reports'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canGenerate', false));

    $this->actingAs($secretary)
        ->get(reportsEstate()->url('/reports/profit_and_loss'))
        ->assertForbidden();

    /*
     * THE PRESIDENT RUNS ALL SIX, because the President holds every module the
     * six read. This loop ran as the Treasurer until B137, and passed only
     * because a report asked for Reports and nothing else: the Treasurer holds
     * no Security, and the incident log is Security's record (M10 §2) — see
     * "runs a report only for a viewer who may read the module…" below.
     */
    $president = reportsEstate()->viewer(Role::PRESIDENT);

    foreach (Reports::BUILT as $key) {
        $this->actingAs($president)
            ->get(reportsEstate()->url('/reports/'.$key).'?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Estate/Reports/Show')
                ->where('key', $key)
                ->where('period', fn ($period) => $period !== '' && $period !== null)
                ->has('columns')
                ->has('rows'));
    }

    // The one with a data gap is not runnable, and the refusal is a sentence.
    $this->actingAs($treasurer)
        ->get(reportsEstate()->url('/reports/budget_vs_actual'))
        ->assertRedirect(reportsEstate()->url('/reports'));
});

it('ties the profit and loss to the ledger, and keeps turnout a count', function () {
    $reports = app(Reports::class);

    $pl = $reports->run('profit_and_loss', '2026-01-01', '2026-12-31');

    /*
     * THE SECOND ROUTE TO THE SAME FIGURE. Income is credits less debits over
     * income accounts; getting that backwards is how a profitable month reads
     * as a loss. Summed here in raw SQL, longhand, so the report and the
     * expectation reach the number by two different paths.
     */
    $income = (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.credit_minor - l.debit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
          JOIN journals j ON j.reference = l.entry_ref
         WHERE a.type = ? AND j.posted_on BETWEEN ? AND ?
    ', ['income', '2026-01-01', '2026-12-31'])->net;

    $reported = 0;

    foreach ($pl['summary'] as $line) {
        if ($line['label'] === 'Income') {
            $reported = (int) round((float) str_replace([',', '$'], '', $line['value']) * 100);
        }
    }

    expect($reported)->toBe($income)
        ->and($pl['period'])->toContain('2026')
        ->and($pl['note'])->toContain('posted');

    /*
     * TURNOUT IS A COUNT AND NOTHING FINER. The columns are the proof: year,
     * phase, cast, eligible, percent — and no column that could be joined back
     * to a household or a mark.
     */
    $turnout = $reports->run('election_turnout', '2020-01-01', '2030-12-31');

    // The note says the same thing in a committee member's words since M98 (Hassam, 11 Oct: "naming no table and no
    // column"): whether a household voted and how it voted are kept apart, with nothing that links the two.
    expect($turnout['columns'])->toBe(['Year', 'Phase', 'Ballots cast', 'Eligible', 'Turnout'])
        ->and($turnout['note'])->toContain('with nothing that links the two')
        ->and(str_contains($turnout['note'], 'ballot_'))->toBeFalse();

    // An ageing is a position TODAY, and the report says so rather than looking
    // filtered by dates that do not narrow it.
    $ageing = $reports->run('arrears_ageing', '2026-01-01', '2026-01-02');

    expect($ageing['period'])->toStartWith('As at ')
        ->and($ageing['note'])->toContain('not over a period');

    /*
     * THE INCIDENT LOG IS THIS ESTATE'S ONLY. Gemini's central log holds every
     * client's incidents; the report reads the rows for this estate and no
     * other, the same reach the estate's own dashboard already has (D-035).
     */
    SecurityIncident::create([
        'tenant_id' => reportsEstate()->key,
        'kind' => 'Report test — ours',
        'severity' => 'high',
        'status' => SecurityIncident::OPEN,
        'occurred_at' => '2026-06-01 21:00:00',
    ]);

    $other = (string) (DB::connection('mysql')->table('tenants')->where('id', '!=', reportsEstate()->key)->value('id') ?? '');

    if ($other !== '') {
        SecurityIncident::create([
            'tenant_id' => $other,
            'kind' => 'Report test — somebody else\'s',
            'severity' => 'low',
            'status' => SecurityIncident::OPEN,
            'occurred_at' => '2026-06-01 21:00:00',
        ]);
    }

    reportsEstate()->boot();
    tenancy()->initialize(reportsEstate()->tenant());

    $log = $reports->run('security_incidents', '2026-01-01', '2026-12-31');

    tenancy()->end();

    $kinds = array_column($log['rows'], 1);

    expect($kinds)->toContain('Report test — ours')
        ->and($kinds)->not->toContain('Report test — somebody else\'s')
        ->and($log['note'])->toContain('not split by phase');

    // The log states its clock once, in the header and in its own words (M11 §4.3), and writes every time as the estate's
    // console writes one, "1 Jun 2026, 9:00 PM", with no tag (Law 5; Hassam, 12 October: no "JA").
    expect($log['columns'][0])->toBe('When (Jamaica time)')
        ->and(array_column($log['rows'], 0))->each->toMatch('/^\d{1,2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} [AP]M$/');

    // A period that ends before it begins is refused rather than answered.
    expect(fn () => $reports->run('profit_and_loss', '2026-12-31', '2026-01-01'))
        ->toThrow(DomainException::class);
});

it('exports a report over exactly the period it was run for, with the audit entry', function () {
    $treasurer = reportsEstate()->viewer(Role::TREASURER);

    $response = $this->actingAs($treasurer)
        ->get(reportsEstate()->url('/reports/maintenance_summary/export').'?from=2026-01-01&to=2026-12-31')
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Category');

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'export.taken')
        ->orderByDesc('id')
        ->first();

    $after = json_decode((string) $entry->after, true);

    // THE PERIOD IS IN THE SCOPE. A figure without its window is a figure
    // somebody will quote, and the audit entry has to say which window.
    expect($after['scope'])->toContain('Maintenance Summary')
        ->and(str_contains((string) $after['scope'], '1 Jan 2026 to 31 Dec 2026'))->toBeTrue('the period in the scope, as Law 5 writes a date')
        ->and($entry->actor_name)->toBe($treasurer->name);
});

it('answers a date that is not on the calendar with a sentence on the report, never a 500 and never another period (B031)', function () {
    $president = reportsEstate()->viewer(Role::PRESIDENT);
    $report = reportsEstate()->url('/reports/profit_and_loss');

    /*
     * A HAND-EDITED OR MISTYPED URL. Month 13 used to reach Carbon and come
     * back a 500; February 31 used to pass the shape check and be read as
     * March 3, so the report covered a different period from the one it said
     * it had been asked for. Each of these is a date the reader wrote and the
     * calendar does not have, and each is now refused with a sentence on the
     * report itself — the screen the reader was on — for the page and for
     * the file alike.
     */
    $refused = [
        '?from=2026-13-01&to=2026-12-31' => '2026-13-01',
        '?from=2026-01-01&to=2026-02-31' => '2026-02-31',
        '?from=2026-00-10&to=2026-12-31' => '2026-00-10',
        '?from=0000-01-01&to=2026-12-31' => '0000-01-01',
        '?from=not-a-date&to=2026-12-31' => 'not-a-date',
        '?from[]=2026-01-01&to=2026-12-31' => 'two dates',
    ];

    foreach ($refused as $query => $named) {
        foreach (['', '/export'] as $suffix) {
            $this->actingAs($president)
                ->get($report.$suffix.$query)
                ->assertRedirect($report)
                ->assertSessionHasErrors('period');

            expect(session('errors')->first('period'))->toContain($named);
        }
    }

    // A period that ends before it begins is refused the same way, on the
    // report rather than back on the catalogue.
    $this->actingAs($president)
        ->get($report.'?from=2026-12-31&to=2026-01-01')
        ->assertRedirect($report)
        ->assertSessionHasErrors('period');

    expect(session('errors')->first('period'))->toContain('ends before it begins');

    // And the sentence reaches the page, which then states the period it DID run.
    $this->actingAs($president)
        ->followingRedirects()
        ->get($report.'?from=2026-13-01&to=2026-12-31')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Reports/Show')
            ->where('errors.period', fn ($message) => str_contains((string) $message, '2026-13-01'))
            ->where('from', fn ($from) => preg_match('/^\d{4}-01-01$/', (string) $from) === 1));

    // A real date at either end of the calendar is a period, not a fault.
    foreach (Reports::BUILT as $key) {
        $this->actingAs($president)
            ->get(reportsEstate()->url('/reports/'.$key).'?from=0001-01-01&to=9999-12-31')
            ->assertOk();
    }

    // The service refuses the same dates for any other caller, with the same class.
    expect(fn () => app(Reports::class)->run('maintenance_summary', '2026-02-31', '2026-12-31'))
        ->toThrow(DomainException::class, '2026-02-31');
});

it('begins an unstated period on the estate\'s own new year, not the server\'s (B031)', function () {
    $president = reportsEstate()->viewer(Role::PRESIDENT);

    // Eight in the evening on New Year's Eve in Kingston is already one in the
    // morning of January 1 in UTC — and the reader is still in 2026.
    Carbon::setTestNow(Carbon::parse('2026-12-31 20:00:00', 'America/Jamaica'));

    $this->actingAs($president)
        ->get(reportsEstate()->url('/reports/maintenance_summary'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('from', '2026-01-01')
            ->where('to', '2026-12-31')
            ->where('period', '1 Jan 2026 to 31 Dec 2026')
            ->where('exportHref', fn ($href) => str_ends_with((string) $href, '?from=2026-01-01&to=2026-12-31')));
});

/** Runs a closure inside the fixture's estate, the way its own console would, and always leaves it. */
function insideReportsEstate(callable $work): mixed
{
    tenancy()->initialize(reportsEstate()->tenant());

    try {
        return $work();
    } finally {
        tenancy()->end();
        reportsEstate()->boot();
    }
}

it('runs a report only for a viewer who may read the module whose records it reads (B137)', function () {
    $period = '?from=2026-01-01&to=2026-12-31';

    $president = reportsEstate()->viewer(Role::PRESIDENT);
    $treasurer = reportsEstate()->viewer(Role::TREASURER);

    /*
     * (a) THE INCIDENT LOG IS THE SECURITY MODULE'S RECORD (M10 §2). The
     * Treasurer holds '-' on Security and is refused its screen; the report
     * was the same rows, with the guard on post and what was done, through a
     * door that asked only for Reports.
     */
    $this->actingAs($treasurer)->get(reportsEstate()->url('/security'))->assertForbidden();

    foreach (['', '/export'] as $suffix) {
        $this->actingAs($treasurer)
            ->get(reportsEstate()->url('/reports/security_incidents'.$suffix).$period)
            ->assertForbidden();
    }

    // The catalogue does not offer to run it for them, and says why; the
    // reports whose modules they hold stay live.
    $this->actingAs($treasurer)
        ->get(reportsEstate()->url('/reports'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('groups', function ($groups): bool {
            $cards = collect($groups)->flatMap(static fn (array $group): array => $group['cards'])->keyBy('key');

            return str_contains((string) $cards['security_incidents']['withheld'], 'Security')
                && $cards['profit_and_loss']['withheld'] === null
                && $cards['arrears_ageing']['withheld'] === null
                && $cards['maintenance_summary']['withheld'] === null;
        }));

    // A positive control: the President holds every module the six read.
    foreach (Reports::BUILT as $key) {
        $this->actingAs($president)->get(reportsEstate()->url('/reports/'.$key).$period)->assertOk();
    }

    /*
     * (b) A COPY OF THE PROPERTY MANAGER WITH REPORTS FULL (M10 §4). Ruling 1
     * (D-010) binds the copy: it can never hold Dues & ledger or Accounting,
     * so it may never read every household's balance or the estate's books —
     * and a report is not a way round a module the role cannot be given.
     */
    $admin = reportsEstate()->viewer(Role::COMMUNITY_SUPER_ADMIN);
    $editor = app(RoleEditor::class);

    $copy = insideReportsEstate(fn () => $editor->copy($admin, Role::named(Role::PROPERTY_MANAGER), 'Works Officer'));
    insideReportsEstate(fn () => $editor->setPermissions($admin, $copy, ['reports' => ['level' => 'full']]));

    $works = reportsEstate()->viewer($copy->name);

    foreach (['arrears_ageing', 'profit_and_loss', 'election_turnout', 'meeting_minutes'] as $key) {
        foreach (['', '/export'] as $suffix) {
            $this->actingAs($works)->get(reportsEstate()->url('/reports/'.$key.$suffix).$period)->assertForbidden();
        }
    }

    // What the copy may read, it may still report on.
    foreach (['maintenance_summary', 'security_incidents'] as $key) {
        $this->actingAs($works)->get(reportsEstate()->url('/reports/'.$key).$period)->assertOk();
    }

    /*
     * A ROLE BUILT FROM NOTHING, with Reports Full and no module the reports
     * read, runs none of them — so every built report answers to a module,
     * and one added later without deciding which fails here.
     */
    $bare = insideReportsEstate(fn () => $editor->create($admin, 'Report Runner', [
        'dashboard' => ['level' => 'view'],
        'reports' => ['level' => 'full'],
    ]));

    $runner = reportsEstate()->viewer($bare->name);

    foreach (Reports::BUILT as $key) {
        $this->actingAs($runner)->get(reportsEstate()->url('/reports/'.$key).$period)->assertForbidden();
    }

    // Given the ledger as well, the same role reads the ageing — the two
    // permissions together, which is the estate's-position rule everywhere else.
    insideReportsEstate(fn () => $editor->setPermissions($admin, $bare, ['dues_ledger' => ['level' => 'view']]));

    $this->actingAs(reportsEstate()->viewer($bare->name))
        ->get(reportsEstate()->url('/reports/arrears_ageing').$period)
        ->assertOk();
});

it('exports each of the seven reports with the rows and the figures its screen shows (Hassam, 12 October)', function () {
    /*
     * "A REPORT THAT RENDERS BUT EXPORTS NOTHING, OR EXPORTS DIFFERENT NUMBERS FROM WHAT IS ON SCREEN, IS WORSE THAN ONE
     * THAT DOES NOT EXPORT AT ALL." Each report is opened as the President over one period and then taken as a file over
     * the same period, and the file is read back against the screen: a row for every row, cell for cell, every amount
     * the same number of cents, the Total where a column adds up, the summary's figures and the period. Budget vs Actual
     * runs with budgets switched on and a budget for two accounts; the incident log has one of this estate's incidents.
     */
    config(['features.budgets' => true]);

    $president = reportsEstate()->viewer(Role::PRESIDENT);

    insideReportsEstate(function () use ($president): void {
        $budgeted = Account::query()->whereIn('code', ['4000', '5100'])->pluck('id', 'code');

        app(Budgets::class)->saveMonth('2026-03', [(int) $budgeted['4000'] => 2_000_000_00, (int) $budgeted['5100'] => 150_000_00], $president);
    });

    SecurityIncident::create([
        'tenant_id' => reportsEstate()->key,
        'kind' => 'Export test: a gate left open',
        'severity' => 'med',
        'status' => SecurityIncident::OPEN,
        'occurred_at' => '2026-05-04 14:00:00',
    ]);

    // An amount as the screen draws it ("$1,234.56", "-$600.00") or as the file writes it ("1234.56"), in cents.
    $cents = static fn (string $cell): ?int => preg_match('/^-?\$?\d[\d,]*(\.\d{1,2})?$/', $cell) === 1
        ? Money::of(str_replace(['$', ','], '', $cell), 'JMD')->getMinorAmount()->toInt()
        : null;

    // The same figure, or the same words. A file cell the formula guard prefixed with ' is read without it.
    $same = static function (string $screen, string $file) use ($cents): bool {
        $file = str_starts_with($file, "'") && ! str_starts_with($screen, "'") ? substr($file, 1) : $file;

        return $cents($screen) !== null ? $cents($screen) === $cents($file) : $screen === $file;
    };

    // The file as a spreadsheet reads it: the BOM off, RFC 4180 quoting, a blank line kept as an empty row.
    $read = static function (string $csv): array {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row === [null] ? [] : array_map(static fn (?string $cell): string => (string) $cell, $row);
        }

        fclose($stream);

        return $rows;
    };

    $period = '?from=2026-01-01&to=2026-12-31';
    $taken = [];

    foreach (Reports::built() as $key) {
        $screen = $this->actingAs($president)->get(reportsEstate()->url('/reports/'.$key).$period)->assertOk()->viewData('page')['props'];
        $file = $read($this->actingAs($president)->get(reportsEstate()->url('/reports/'.$key.'/export').$period)->assertOk()->streamedContent());

        $headers = array_shift($file);
        $body = array_slice($file, 0, count($screen['rows']));
        $after = array_slice($file, count($screen['rows']));

        // Something to compare: a report that exports nothing is the failure this test exists for.
        expect($screen['rows'])->not->toBeEmpty($key.': the screen has no rows over 2026')
            ->and(count($headers))->toBe(count($screen['columns']), $key.': a column for every column');

        foreach ($screen['columns'] as $i => $column) {
            expect($headers[$i] === $column || $headers[$i] === $column.' JMD')->toBeTrue($key.': column '.$column.' is headed '.$headers[$i]);
        }

        foreach ($screen['rows'] as $r => $row) {
            expect(count($body[$r] ?? []))->toBe(count($row), $key.': row '.$r);

            foreach ($row as $c => $cell) {
                expect($same((string) $cell, $body[$r][$c] ?? ''))->toBeTrue($key.': row '.$r.', column '.$c.': the screen says "'.$cell.'", the file "'.($body[$r][$c] ?? '').'"');
            }
        }

        // Where a column adds up (the ageing's balances), the file's Total is the sum of its rows, to the cent.
        if (($after[0][0] ?? null) === 'Total') {
            $total = array_shift($after);

            foreach ($total as $c => $cell) {
                if ($c === 0 || $cell === '') {
                    continue;
                }

                expect($cents($cell))->toBe(array_sum(array_map(static fn (array $row): int => (int) $cents((string) $row[$c]), $screen['rows'])), $key.': the Total of column '.$c);
            }
        }

        // A blank line, then the summary's figures, then the period.
        expect(array_shift($after))->toBe([], $key.': a blank line before the summary');

        foreach ($screen['summary'] as $s => $item) {
            expect($after[$s][0] ?? null)->toBe($item['label'], $key.': summary line '.$s)
                ->and($same((string) $item['value'], $after[$s][1] ?? ''))->toBeTrue($key.': '.$item['label'].' is "'.$item['value'].'" on the screen and "'.($after[$s][1] ?? '').'" in the file');
        }

        $line = $after[count($screen['summary'])] ?? [];

        expect($line[0] ?? null)->toBe('Period', $key.': the period closes the file');

        // The same words on the screen and in the file, the ageing's "As at 12 Oct 2026" among them (one way to write a date).
        expect($line[1] ?? null)->toBe($screen['period'], $key.': the period');

        $taken[] = $key;
    }

    // All seven, Budget vs Actual among them.
    expect($taken)->toBe(Reports::built())
        ->and($taken)->toHaveCount(7);
});

it('writes every date a report states one way, "1 Jan 2026": never a long month name, never the month first (Hassam, 12 October)', function () {
    /*
     * "1 Jan 2026 to 11 Oct 2026". Same as everywhere else, no "JA", no long month names." Every built report, run over
     * a year: its period, the ageing's "As at", and every cell, with a closed incident in the year so the incident log
     * has a closing date to write and a payment so the ageing has a last payment.
     */
    SecurityIncident::create([
        'tenant_id' => reportsEstate()->key,
        'kind' => 'Date test: a gate left open',
        'severity' => 'med',
        'status' => SecurityIncident::RESOLVED,
        'occurred_at' => '2026-05-04 14:00:00',
        'closed_at' => '2026-05-05 09:00:00',
        'resolution' => 'Closed and checked.',
    ]);

    $stated = insideReportsEstate(function (): array {
        $words = [];

        foreach (Reports::built() as $key) {
            $report = app(Reports::class)->run($key, '2026-01-01', '2026-12-31');
            $words[$key.': the period'] = (string) $report['period'];

            foreach ($report['rows'] as $r => $row) {
                foreach (array_values((array) $row) as $c => $cell) {
                    if (is_string($cell) && $cell !== '') {
                        $words[$key.': row '.$r.', cell '.$c] = $cell;
                    }
                }
            }
        }

        return $words;
    });

    $months = 'January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec';

    foreach ($stated as $where => $words) {
        expect(preg_match('/\b('.$months.') \d{1,2}, \d{4}\b/', $words))->toBe(0, $where.': "'.$words.'" puts the month first')
            ->and(preg_match('/\b\d{1,2}:\d{2} [AP]M [A-Z]{2,3}\b/', $words))->toBe(0, $where.': "'.$words.'" tags a time with its zone');
    }

    expect($stated['maintenance_summary: the period'])->toBe('1 Jan 2026 to 31 Dec 2026')
        ->and($stated['arrears_ageing: the period'])->toBe('As at '.SiteTime::date()->format('j M Y'))
        ->and(collect($stated)->contains('4 May 2026, 2:00 PM'))->toBeTrue('the incident log writes when it happened as Law 5 does')
        ->and(collect($stated)->contains(static fn (string $words): bool => str_starts_with($words, 'Closed 5 May 2026')))->toBeTrue('the incident log writes its closing date as Law 5 does');
});
