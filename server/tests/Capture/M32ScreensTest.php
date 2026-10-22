<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Support\SampleResidents;
use App\Support\SiteTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstateScaleFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M32 §16.4: the console screens M32 changed, rendered for the screenshots
|--------------------------------------------------------------------------
|
| NOT PART OF THE SUITE: this folder is in no testsuite of phpunit.xml, and the file does nothing unless asked
| (`M32_CAPTURE=1`). It renders the pages through the application's own kernel, as `M30ScreensTest` does: nobody signs
| in to anything, and no server runs.
|
| ON `EstateScaleFixture`, Phoenix Park's own size and phase table: 2,000 lots, 441 households, the demonstration's
| placeholders on them. The viewers are made here, in a transaction on the platform database that is rolled back; the
| estate's database is the fixture's, committed, so the few rows made here (a member beside Lot 413's placeholder, a
| claim, a second household at a lot) are taken away at the end, whatever happens.
|
| It writes each page's HTML, and the Structure page's answers to the lot numbers typed (its partial reloads), to
| M32_CAPTURE_DIR (storage/framework/testing/m32-capture by default), with a manifest. `node tools/m32/screens.mjs`
| then opens those files offline with the built assets and takes the screenshots.
|
*/

uses(TestCase::class);

beforeEach(function () {
    if (getenv('M32_CAPTURE') !== '1') {
        $this->markTestSkipped('Only when capturing the M32 screenshots: M32_CAPTURE=1.');
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

it('renders the screens M32 changed, for the screenshots', function () {
    $out = getenv('M32_CAPTURE_DIR') ?: storage_path('framework/testing/m32-capture');
    if (! is_dir($out)) {
        mkdir($out, 0777, true);
    }

    $office = EstateScaleFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $office->forceFill(['name' => 'Screens Office'])->save();

    $director = User::factory()->create(['name' => 'Screens Director', 'email' => 'm32-screens-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $director->syncRoles([Role::DIRECTOR]);
    $director = $director->fresh();

    EstateScaleFixture::boot();

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'm32-capture'],
        ['name' => 'Standard', 'price_per_unit_minor' => 340_00, 'currency' => 'JMD', 'min_units' => 1, 'is_active' => true, 'sort' => 97],
    );
    $contract = static fn (int $units) => Subscription::query()->updateOrCreate(['tenant_id' => EstateScaleFixture::ESTATE], ['plan_id' => $plan->id, 'unit_count' => $units, 'status' => 'active']);

    $made = ['residents' => [], 'households' => [], 'claims' => []];

    try {
        EstateScaleFixture::boot();
        $units = Unit::query()->count();

        // Lot 413, as on production: the demonstration's household, its placeholder primary, and a real person beside it.
        $lot413 = Unit::query()->where('reference', 'Lot 413')->first();
        $seeded = $lot413?->household;
        if (! $seeded instanceof Household || ! $seeded->residents->contains(fn (Resident $r): bool => $r->isSample())) {
            $seeded = Household::query()->whereHas('residents', fn ($q) => $q->whereRaw(SampleResidents::sql('residents')))
                ->whereHas('unit', fn ($q) => $q->where('block', 'Phase 2'))->orderBy('id')->firstOrFail();
            $lot413 = $seeded->unit;
        }

        $hassam = new Resident;
        $hassam->forceFill(['household_id' => $seeded->id, 'full_name' => 'Hassam Example', 'email' => 'hassam.example@example.com', 'phone' => '876 555 0771', 'relationship' => 'owner', 'is_primary' => false, 'status' => Resident::VERIFIED, 'verified_at' => now()])->save();
        $made['residents'][] = $hassam->id;

        // A lot holding two households: its seeded one, and a separate household the office added beside it.
        $twoLot = Unit::query()->where('block', 'Phase 2')->whereKeyNot($lot413->id)->whereHas('households', fn ($q) => $q->whereHas('residents'))->orderBy('id')->firstOrFail();
        $second = Household::query()->create(['unit_id' => $twoLot->id, 'name' => 'Morgan household', 'access_restricted' => false]);
        $made['households'][] = $second->id;
        $shauna = new Resident;
        $shauna->forceFill(['household_id' => $second->id, 'full_name' => 'Shauna Morgan', 'email' => 'shauna.morgan@example.com', 'phone' => '876 555 0772', 'relationship' => 'tenant', 'is_primary' => true, 'status' => Resident::VERIFIED, 'verified_at' => now()])->save();
        $made['residents'][] = $shauna->id;

        // Three claims waiting, made a day apart, one on a lot the demonstration seeded: the queue newest first.
        foreach ([['Kerry-Ann Brown', 3], ['Delroy Watson', 2], ['Tanya Lewis', 1]] as $i => [$name, $daysAgo]) {
            $unit = Unit::query()->where('block', 'Phase 3')->whereHas('household')->orderBy('id')->skip($i)->firstOrFail();
            $claim = UnitClaim::query()->create(['unit_id' => $unit->id, 'claim_type' => UnitClaim::TYPE_UNIT, 'submitted_name' => $name, 'submitted_phone' => '876 555 07'.(80 + $i), 'match_result' => 'none', 'status' => UnitClaim::PENDING]);
            $claim->forceFill(['created_at' => Carbon::now()->subDays($daysAgo)])->save();
            $made['claims'][] = $claim->id;
        }

        $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
        $manifest = ['pages' => [], 'partials' => [], 'estate' => EstateScaleFixture::ESTATE, 'lot413' => $lot413->reference, 'twoLot' => $twoLot->reference];

        $render = function (string $name, User $as, string $url, bool $estate = true) use ($out, &$manifest, $version): void {
            // A whole address either way: the screenshot tool finds a page by its host and path. A Gemini page's is the
            // platform's own, from APP_URL — never `url()`, which answers with the host of the last request, an estate's.
            $full = $estate ? EstateScaleFixture::url($url) : rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
            $html = $this->actingAs($as)->get($full)->assertOk()->getContent();
            file_put_contents($out.'/'.$name.'.html', $html);

            // The same page as an Inertia visit answers it, for any reload the page makes that was not recorded.
            $json = $this->actingAs($as)->get($full, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()->getContent();
            file_put_contents($out.'/'.$name.'.json', $json);

            $manifest['pages'][$name] = ['url' => $full, 'file' => $name.'.html', 'json' => $name.'.json'];
            EstateScaleFixture::boot();
        };

        $partial = function (string $name, User $as, string $url, string $component, string $only) use ($out, &$manifest, $version): void {
            $full = EstateScaleFixture::url($url);
            $json = $this->actingAs($as)->get($full, [
                'X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $only,
            ])->assertOk()->getContent();
            file_put_contents($out.'/'.$name.'.json', $json);
            $manifest['partials'][] = ['url' => $full, 'file' => $name.'.json'];
            EstateScaleFixture::boot();
        };

        // F2: the estate full, as production stands (every unit in use).
        $contract($units);
        $render('estate-full', $office, '/estate');

        // F2 and F6: with eleven units of room, the form open on the next phase; the answers to what is typed.
        $contract($units + 11);
        $render('estate-room', $office, '/estate');
        // The suggestion taken (Lot 2000 to 2010: Lot 1999A has no plain number), a range that overlaps, one past the contract.
        foreach (['blocks=4', 'blocks=4&from=2000', 'blocks=4&from=2000&to=2010', 'blocks=4&from=2001', 'blocks=4&from=2001&to=2011', 'blocks=4&from=90', 'blocks=4&from=90&to=100', 'blocks=4&from=2001&to=2020'] as $i => $query) {
            $partial('estate-lots-'.$i, $office, '/estate?'.$query, 'Estate/Structure/Index', 'lots');
        }

        // F2: raising the contract is the Gemini console's, with a reason.
        $contract($units);
        $render('plan', $director, '/clients/'.EstateScaleFixture::ESTATE.'/plan', estate: false);

        // F1 and F4: the register as people, as households, searched, and a search that finds nothing.
        $render('register-people', $office, '/residents');
        $render('register-households', $office, '/residents?view=households');
        $render('register-search-email', $office, '/residents?q=hassam.example');
        $render('register-search-phone', $office, '/residents?q='.rawurlencode('+1 876 555 0771'));
        $render('register-empty', $office, '/residents?q=soft');

        // F1: Add resident at an occupied lot, and at a lot of two households.
        $render('add-occupied', $office, '/residents/new?'.http_build_query(['phase' => (string) $lot413->block, 'lot' => (string) $lot413->reference]));
        $render('add-two', $office, '/residents/new?'.http_build_query(['phase' => (string) $twoLot->block, 'lot' => (string) $twoLot->reference]));

        // F1: the household page, marked sample, and a lot of two households with its switcher.
        $render('household-413', $office, '/residents/'.$lot413->slug());
        $render('household-two', $office, '/residents/'.$twoLot->slug());

        // F1 and F8: the claims queue, newest first, its seeded record marked.
        $render('claims', $office, '/residents/claims');

        // F5: tables ordered and paged by the server, their place in the address — in both consoles.
        $render('maintenance-sorted', $office, '/facilities/maintenance?sort=priority&dir=desc&per=50');
        $render('vendors', $office, '/accounting/vendors');
        $render('receipts-page-2', $office, '/finance/receipts?page=2');
        $render('gemini-clients', $director, '/clients', estate: false);

        // F9: My account, in each console.
        $render('account-estate', $office, '/account');
        $render('account-gemini', $director, '/account', estate: false);

        // F12: pages whose `i` the screenshots open (the Structure page and the household page are above).
        $render('arrears', $office, '/finance/arrears');
        $render('ledger-413', $office, '/finance/units/'.$lot413->id);
        $render('places', $director, '/guards/places?client='.EstateScaleFixture::ESTATE, estate: false);

        file_put_contents($out.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        expect(count($manifest['pages']))->toBe(22);
    } finally {
        EstateScaleFixture::boot();
        DB::connection('tenant')->table('unit_claims')->whereIn('id', $made['claims'])->delete();
        DB::connection('tenant')->table('resident_invites')->whereIn('resident_id', $made['residents'])->delete();
        DB::connection('tenant')->table('residents')->whereIn('id', $made['residents'])->delete();
        DB::connection('tenant')->table('households')->whereIn('id', $made['households'])->delete();
    }
});
