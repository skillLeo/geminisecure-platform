<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Support\PlatformPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The Export on the MRR and Revenue reports is live for a role that may take
| it — bug hunt B010
|--------------------------------------------------------------------------
|
| `ReportController` offered the Export when the viewer held
| `gemini.reports.export`. No such permission exists — the module is
| `cross_tenant_reports` — and Spatie answers a permission that does not exist
| with "no", so the button was greyed out for everybody, the Director included,
| with a reason telling them their role lacked access it had. The export routes
| themselves check the real permission and worked, reachable only by typing the
| URL.
|
| The controller and the routes now read one constant, so the button and the
| route it links to cannot disagree about who may press it again.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('offers the Director a live Export on the MRR and Revenue reports, and the file it links to downloads', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    foreach (['/reports/mrr' => '/reports/mrr/export', '/reports/revenue' => '/reports/revenue/export'] as $screen => $export) {
        $this->actingAs($director)->get($screen)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('exportHref', $export));

        $this->actingAs($director)->get($export)->assertOk();
    }
});

it('offers it to every role holding the export verb, and tells a role that can only read the report why not', function () {
    // The Operations Manager holds Cross-tenant reports in full and Billing to read.
    $operations = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);

    $this->actingAs($operations)->get('/reports/revenue')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('exportHref', '/reports/revenue/export'));

    // The Accountant may read the reports and not take them away.
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    foreach (['/reports/mrr' => '/reports/mrr/export', '/reports/revenue' => '/reports/revenue/export'] as $screen => $export) {
        $this->actingAs($accountant)->get($screen)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('exportHref', null)
                ->where('exportDisabledReason', fn (string $reason) => str_contains($reason, 'Cross-tenant reports export')));

        // And the route agrees with the button.
        $this->actingAs($accountant)->get($export)->assertForbidden();
    }
});

it('exports the MRR trend and revenue by tier with the figures their screens show (Hassam, 12 October)', function () {
    /*
     * "A REPORT THAT RENDERS BUT EXPORTS NOTHING, OR EXPORTS NUMBERS DIFFERENT FROM THE SCREEN, IS WORSE THAN ONE THAT
     * DOES NOT EXPORT AT ALL." Each report is opened as the Director, then taken as a file, and the file is read back as
     * a spreadsheet reads it and held to the screen: a row for every month on the chart, with the month's exact total
     * (the bar's own, never its "$243.9k" label) and what moved it; a row for every tier and every client, with the same
     * figures. One invoice with cents in it, and one client on a plan, are put on the platform for this test alone.
     */
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $client = 'qaexportcheck';

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $client, 'name' => 'Export Check Estate', 'receipt_prefix' => 'QXC', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'key' => 'qaexportcheck', 'name' => 'Export Check', 'price_per_unit_minor' => 300_50, 'currency' => 'JMD', 'min_units' => 1, 'is_active' => true, 'sort' => 96,
    ]);

    Subscription::query()->create([
        'tenant_id' => $client, 'plan_id' => $plan->id, 'unit_count' => 40, 'contracted_guards' => 2, 'status' => 'active', 'started_on' => now()->startOfMonth()->subMonths(2)->toDateString(),
    ]);

    // The per-guard rate in force, so the add-on, which every invoice carries on top of a tier, is not nothing.
    DB::connection('mysql')->table('platform_rates')->updateOrInsert(
        ['key' => PlatformPricing::GUARD_RATE_KEY],
        ['label' => 'Security provider guard', 'applies_to' => 'Every client', 'amount_minor' => 4_500_25, 'currency' => 'JMD', 'basis' => 'guard', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    );

    $month = now()->startOfMonth();
    $invoice = [
        'tenant_id' => $client, 'reference' => 'QA-EXPORT-CHECK-1', 'period' => $month->format('M Y'),
        'period_start' => $month->toDateString(), 'period_end' => $month->copy()->endOfMonth()->toDateString(),
        'total_minor' => 243_912_50, 'currency' => 'JMD', 'due_on' => $month->copy()->addDays(14)->toDateString(),
        'status' => 'issued', 'created_at' => now(), 'updated_at' => now(),
    ];

    if (Schema::connection('mysql')->hasColumn('invoices', 'subtotal_minor')) {
        $invoice['subtotal_minor'] = 243_912_50;
    }

    DB::connection('mysql')->table('invoices')->insert($invoice);

    // The file as a spreadsheet reads it: the BOM off, RFC 4180 quoting; a cell the formula guard prefixed is read without it.
    $read = static function (string $csv): array {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = array_map(static fn (?string $cell): string => preg_replace("/^'(?=[=+\\-@])/", '', (string) $cell), $row);
        }

        fclose($stream);

        return $rows;
    };

    // MRR: one row a month, the month with its year, its exact total and what moved it.
    $mrr = $this->actingAs($director)->get('/reports/mrr')->assertOk()->viewData('page')['props'];
    $file = $read($this->actingAs($director)->get('/reports/mrr/export')->assertOk()->streamedContent());

    $thisMonth = collect($mrr['bars'])->firstWhere('month', $month->format('M Y'));

    expect(array_shift($file))->toBe(['Month', 'Invoiced', 'What moved it'])
        ->and($file)->toHaveCount(count($mrr['bars']))
        ->and($file)->toBe(array_map(static fn (array $bar): array => [$bar['month'], $bar['exact'], (string) ($bar['annotation'] ?? '')], $mrr['bars']))
        // This month holds the test's invoice, so its figure is not a zero that would match anything.
        ->and($thisMonth)->not->toBeNull()
        ->and(str_replace(['$', ','], '', (string) $thisMonth['exact']) !== '0')->toBeTrue()
        ->and($thisMonth['exact'] === $thisMonth['value'])->toBeFalse();

    // Revenue: a row for every tier and the per-guard add-on, then every client, then the total, the screen's own figures.
    $revenue = $this->actingAs($director)->get('/reports/revenue')->assertOk()->viewData('page')['props'];
    $file = $read($this->actingAs($director)->get('/reports/revenue/export')->assertOk()->streamedContent());

    $expected = [
        ...array_map(static fn (array $tier): array => [$tier['key'] === 'guard_addon' ? 'Add-on' : 'Tier', (string) $tier['name'], '', '', (string) $tier['amount'], $tier['percent'].'%'], $revenue['tiers']),
        ...array_map(static fn (array $row): array => ['Client', (string) $row['client'], (string) $row['tier'], (string) $row['units'], (string) $row['contribution'], (string) $row['share']], $revenue['clients']),
        ['Total', 'Monthly recurring revenue', '', '', (string) $revenue['total'], ''],
    ];

    expect(array_shift($file))->toBe(['Row', 'Name', 'Tier', 'Units', 'Monthly', 'Share'])
        ->and($file)->toBe($expected)
        ->and(collect($revenue['clients'])->pluck('client')->all())->toContain('Export Check Estate');

    /*
     * EVERY TOTAL TIES TO ITS ROWS, to the cent. The split's lines (the tiers and the per-guard add-on) are the heading's
     * total; so are the clients' contributions. They were the tiers alone, short of the total by the add-on.
     */
    $cents = static function (string $money): ?int {
        if (preg_match('/^\$(\d{1,3}(?:,\d{3})*)(?:\.(\d{2}))?$/', $money, $m) !== 1) {
            return null;
        }

        return (int) str_replace(',', '', $m[1]) * 100 + (int) ($m[2] ?? 0);
    };

    $total = $cents((string) $revenue['total']);
    $split = array_sum(array_map(static fn (array $tier): int => (int) $cents((string) $tier['amount']), $revenue['tiers']));
    $clients = array_sum(array_map(static fn (array $row): int => (int) $cents((string) $row['contribution']), $revenue['clients']));

    expect($total)->not->toBeNull()
        ->and($split)->toBe($total)
        ->and($clients)->toBe($total)
        ->and(collect($revenue['tiers'])->pluck('key')->all())->toContain('guard_addon');

    PlatformPricing::forgetGuardRate();
});
