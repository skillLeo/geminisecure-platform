<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DuressAlert;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Estate\Governance;
use App\Support\SiteTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstateScaleFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M60 §C4: the ten Sunday rehearsal screens, rendered with the red emergency bar showing
|--------------------------------------------------------------------------
|
| NOT PART OF THE SUITE: this folder is in no testsuite of phpunit.xml, and the file does nothing unless asked
| (`SUNDAY_CAPTURE=1`). It renders the pages through the application's own kernel, as `M32ScreensTest` does: nobody
| signs in to anything, and no server runs. On `EstateScaleFixture` (Phoenix Park's own size), with one panic alert
| nobody has answered, made in a transaction on the platform database that is rolled back and with its events off, so
| the console's own `panicWatch` seeds the bar on every page exactly as on the live estate.
|
| It writes each page's HTML and Inertia JSON to SUNDAY_CAPTURE_DIR (storage/framework/testing/sunday-capture by
| default) with a manifest; `node tools/sunday/banner-check.mjs` opens them offline with the built assets at 1440 and
| 1280 and runs M60 §D4's bounding-box check. Run it AFTER `npm run build`: each page names the asset files of the
| build current when it was rendered, and a later build replaces them.
|
*/

uses(TestCase::class);

beforeEach(function () {
    if (getenv('SUNDAY_CAPTURE') !== '1') {
        $this->markTestSkipped('Only when capturing the Sunday screens: SUNDAY_CAPTURE=1.');
    }

    EstateScaleFixture::boot();
    EstateScaleFixture::matrix();
    DB::connection('mysql')->beginTransaction();
    EstateScaleFixture::enter();
});

afterEach(function () {
    if (DB::connection('mysql')->transactionLevel() > 0) {
        DB::connection('mysql')->rollBack();
    }
    SiteTime::flush();
});

it('renders the ten rehearsal screens with the emergency bar showing', function () {
    $out = getenv('SUNDAY_CAPTURE_DIR') ?: storage_path('framework/testing/sunday-capture');
    if (! is_dir($out)) {
        mkdir($out, 0777, true);
    }

    $office = EstateScaleFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $office->forceFill(['name' => 'Dahlia Reid'])->save();

    // One panic nobody has answered: the bar the committee would see (no event, so nobody is told).
    DuressAlert::withoutEvents(fn (): DuressAlert => DuressAlert::create([
        'tenant_id' => EstateScaleFixture::ESTATE, 'kind' => 'panic', 'raised_by_name' => 'Lot 47 household', 'unit_reference' => 'Lot 47',
        'status' => 'open', 'server_time' => now()->subMinutes(2), 'idempotency_key' => 'sunday-banner-capture-'.uniqid(),
    ]));

    EstateScaleFixture::boot();
    $household = Household::query()->with('unit')->orderBy('id')->first();
    // A work order's page is addressed by its number, as board 18's own URL is, not by its id.
    $ticket = MaintenanceTicket::query()->orderByDesc('id')->first()?->getRouteKey();
    $run = PayrollRun::query()->orderByDesc('period_start')->first();
    $year = app(Governance::class)->electionYear();
    $unit = $household?->unit ?? Unit::query()->orderBy('id')->first();

    $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
    $manifest = ['pages' => [], 'partials' => [], 'estate' => EstateScaleFixture::ESTATE];

    // `$open`: the words on a button the checker presses before it measures. `$expect`: a part of the page that must be
    // on screen when it does (M71: the Post a notice panel is a column of the notices page, with no button to open it).
    $render = function (string $name, string $path, ?string $open = null, ?string $expect = null) use ($office, $out, &$manifest, $version): void {
        $full = EstateScaleFixture::url($path);
        $html = $this->actingAs($office)->get($full)->assertOk()->getContent();
        file_put_contents($out.'/'.$name.'.html', $html);
        EstateScaleFixture::boot();
        $json = $this->actingAs($office)->get($full, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()->getContent();
        file_put_contents($out.'/'.$name.'.json', $json);
        EstateScaleFixture::boot();
        $manifest['pages'][$name] = ['url' => $full, 'file' => $name.'.html', 'json' => $name.'.json', 'open' => $open, 'expect' => $expect];
    };

    // The ten steps of the Sunday rehearsal (M58-A §7), a page for each, and the record page where the step opens one.
    $render('01-dashboard', '/');
    $render('02-estate-structure', '/estate');
    $render('03-residents', '/residents');
    $render('03-household', '/residents/'.$unit->slug());
    $render('04-maintenance', '/facilities/maintenance');
    if ($ticket !== null) {
        $render('04-work-order', '/facilities/maintenance/'.$ticket);
    }
    $render('05-new-charge', '/finance/charges/new');
    $render('05-unit-ledger', '/finance/units/'.$unit->id);
    $render('06-new-meeting', '/governance/meetings/new');
    $render('06-meetings', '/governance/meetings');
    $render('07-elections', '/governance/elections/'.$year);
    $render('08-bookings', '/facilities/amenities/bookings');
    $render('09-payroll', '/payroll');
    if ($run !== null) {
        $render('09-pay-run', '/payroll/runs/'.$run->slug);
    }
    $render('10-reports', '/reports');
    // M71: step 10's Profit & Loss (its columns' alignment, E2E-M10) and the Post a notice panel at 125% (E2E-M4).
    $render('10-profit-and-loss', '/reports/profit_and_loss?from='.now()->subYears(2)->toDateString().'&to='.now()->toDateString());
    $render('11-notices-post', '/governance/notices', null, '.compose-panel');

    file_put_contents($out.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // The bar is seeded on the page itself: every page's props carry the alert nobody has answered.
    // (`toContain` takes every argument as a needle, so the page's name is the failure message of a plain check.)
    foreach ($manifest['pages'] as $name => $page) {
        expect(str_contains((string) file_get_contents($out.'/'.$page['json']), '"unit":"Lot 47"'))->toBeTrue($name.' carries no panic in its props');
    }
});
