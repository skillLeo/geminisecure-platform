<?php

declare(strict_types=1);

use App\Models\EstateAssignment;
use App\Models\Role;
use App\Services\Gemini\PlatformOverview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\QueryBudget;
use Tests\Support\ScaleFixture;
use Tests\Support\ScaleVolume;

/*
|--------------------------------------------------------------------------
| Every Gemini console page, at production volume, inside a query budget — M11 A2
|--------------------------------------------------------------------------
|
| A page that asks the database once per row is invisible on a five-row fixture and
| is the whole request at Phoenix Park's size. So every page here is opened on a
| platform built to volume — the scale estate's 2,000 lots, 441 households and 12,015
| charges, and around it twelve clients, sixty officers and their shifts, alerts,
| gate log, leave requests, invoices, priced overrides and payroll (`ScaleVolume`) —
| and held to three things:
|
|   1. IT ANSWERS. A 500 is not a fast page.
|   2. IT ASKS NO MORE THAN ITS BUDGET. The budgets are the counts measured on
|      25 September 2026, after the N+1s were taken out and the bug fixes merged,
|      plus four, so a new N+1 fails here rather than in front of a committee. Each
|      is counted as a NEW request (`geminiCount`), as the server answers every one.
|   3. IT ASKS NO MORE OF A PLATFORM TWICE THE SIZE. The volume is built a second
|      time on top of the first and every page is counted again: a count that grew
|      is a query per row, whatever its budget says. This is the property that
|      cannot be faked by a generous budget.
|
| Counted with `DB::listen` (`QueryBudget`), on every connection. The permanent
| development guard that fails a request over a sane count is m11-n1-estate's; this
| is the measurement for the pages this unit changed.
|
| No RefreshDatabase: the scale estate is committed once per process, and every
| central row the volume adds is inside a transaction rolled back after each test.
|
*/

beforeEach(function (): void {
    ScaleFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/**
 * Every page, with its budget: the count measured after the fixes, as a new request, plus four.
 *
 * @param  array<string, mixed>  $v  what `ScaleVolume::build()` handed back
 * @return array<string, int>
 */
function geminiBudgets(array $v): array
{
    $scale = ScaleFixture::ESTATE;
    $guard = $v['scale_guard_ids'][0];

    return [
        // One more for the bell's residents waiting on Live chat (M50 §4.3): a single read, never one per conversation.
        '/dashboard' => 34,
        '/dashboard/activity' => 18,
        // The bell's Live chat read, as on the dashboard above.
        '/notifications' => 29,
        '/clients' => 16,
        '/clients/new' => 16,
        // +1 for M57.5 §4.1: "Built (site plan)" beside the contract's units, read from the estate's own phases, once.
        "/clients/{$scale}" => 24,
        "/clients/{$scale}/guards" => 22,
        "/clients/{$scale}/plan" => 21,
        "/clients/{$scale}/message" => 18,
        // Every client's next invoice drafted from one set of reads (`InvoiceRun::previews`).
        '/billing' => 34,
        '/billing/plans' => 17,
        '/billing/payment-methods' => 14,
        "/billing/invoices/{$v['invoice_id']}" => 17,
        "/billing/invoices/{$v['invoice_id']}/pdf" => 10,
        "/billing/preview/{$scale}" => 28,
        '/audit' => 17,
        '/audit/export' => 11,
        // +1 for M57.5 §4.5: every estate's boundary as its site plan draws it, read once for the whole map.
        '/dispatch/map' => 27,
        '/dispatch/coverage' => 21,
        '/dispatch/alertness' => 25,
        '/dispatch/requests' => 17,
        '/dispatch/requests/history' => 16,
        '/dispatch/alerts' => 22,
        '/dispatch/alerts?tab=resolved' => 20,
        "/dispatch/alerts/{$v['alert_id']}" => 28,
        '/dispatch/panic-watch' => 11,
        // The sounding siren's question (B107), about both of the scale estate's alerts.
        '/dispatch/alarm?'.http_build_query(['ids' => [$v['alert_id'], $v['household_alert_id']]]) => 8,
        '/guards' => 24,
        '/guards/compliance' => 19,
        '/guards/compliance/export' => 13,
        '/guards/supervisors' => 20,
        '/guards/new' => 18,
        // Five more reads for the positions on recent shifts (M22 §1.1): the week's shifts and their posts, then the
        // consents, counts and paired fixes in three reads for all of them — the same five on a platform twice the
        // size. The page asked 22 before, under a budget of 24.
        "/guards/{$guard}" => 27,
        "/guards/{$guard}/compliance" => 21,
        '/guards/roster' => 23,
        '/guards/standing-orders' => 20,
        "/guards/standing-orders/{$v['set_id']}" => 20,
        '/guards/activity' => 19,
        '/guards/incidents' => 18,
        "/guards/incidents/{$v['incident_id']}" => 18,
        '/account/override-pin' => 14,
        '/payroll' => 21,
        '/payroll/filings' => 16,
        '/payroll/rates' => 20,
        "/payroll/{$v['run_id']}" => 17,
        "/payroll/{$v['approved_run_id']}" => 16,
        '/reports' => 13,
        '/reports/mrr' => 25,
        '/reports/revenue' => 18,
        '/reports/mrr/export' => 19,
        '/reports/revenue/export' => 12,
        '/reports/churn' => 16,
        '/reports/utilisation' => 18,
        '/reports/client-health' => 15,
        '/settings' => 31,
        '/settings/packages' => 16,
        // The scale estate's price panel, named: the bare page opens on whichever client sorts
        // first, and that client's overrides — not this page's code — would set the count.
        "/settings/line-items?client={$scale}" => 23,
        // +1 for the live shell (M11 Part C, W0): the platform log's head, once per page, whatever the platform's size.
        '/settings/roles' => 22,
        '/settings/admins' => 21,
        '/settings/email' => 14,
        '/settings/sms' => 14,
        '/simulator' => 14,
    ];
}

/**
 * One page, twice — the first fills whatever the page keeps in the shared cache, and the
 * second is the one counted.
 *
 * THE COUNTED ONE IS A NEW REQUEST, as every request is on the server: nothing left in this
 * process's statics by the first (`QueryBudget::asANewRequest`), and the viewer read afresh,
 * without the roles the first request loaded onto them, as the session guard reads a user
 * on every request. Counted any other way, a query asked once per estate — a zone, say —
 * is asked by the first request and hidden from the second.
 *
 * The whole response is read inside the measurement, a streamed export included: an
 * export's queries run in its stream callback.
 *
 * @return array{status: int, count: int, worst: int, repeats: array<string, int>}
 */
function geminiCount($test, $viewer, string $page): array
{
    $open = function ($as) use ($test, $page): int {
        $response = $test->actingAs($as)->get($page);

        if ($response->baseResponse instanceof StreamedResponse) {
            $response->streamedContent();
        }

        return $response->baseResponse->getStatusCode();
    };

    $open($viewer->fresh());

    QueryBudget::asANewRequest();
    $afresh = $viewer->fresh();
    $warm = QueryBudget::measure(fn (): int => $open($afresh));

    return [
        'status' => $warm['result'],
        'count' => $warm['count'],
        'worst' => QueryBudget::worstRepeat($warm),
        'repeats' => $warm['repeats'],
    ];
}

it('answers every Gemini console page at volume within its query budget, and asks no more of a platform twice the size', function (): void {
    $v = ScaleVolume::build();
    $director = $v['director'];
    $budgets = geminiBudgets($v);

    $first = [];

    foreach (array_keys($budgets) as $page) {
        $first[$page] = geminiCount($this, $director, $page);
    }

    // The same platform again, on top of the first: twice the clients, officers, shifts,
    // alerts, gate log, incidents, requests, invoices, payslips and audit entries.
    ScaleVolume::build();

    foreach ($budgets as $page => $budget) {
        $second = geminiCount($this, $director, $page);

        expect($first[$page]['status'])->toBe(200, "{$page} did not answer")
            ->and($first[$page]['count'])->toBeLessThanOrEqual($budget, "{$page} asked {$first[$page]['count']} queries against a budget of {$budget}")
            // Never MORE of the larger platform. Fewer is not a failure: the property is that
            // the count does not rise with the rows.
            ->and($second['count'])->toBeLessThanOrEqual($first[$page]['count'], "{$page} asked {$first[$page]['count']} queries of the platform and {$second['count']} of one twice its size — a query per row: ".json_encode($second['repeats']))
            ->and($first[$page]['worst'])->toBeLessThanOrEqual(4, "{$page} ran one statement {$first[$page]['worst']} times: ".json_encode($first[$page]['repeats']));
    }
});

it('draws the panic pop-up the console mounts on every page from one read of the estates\' dispatch lines, however many estates have an alert open', function (): void {
    $v = ScaleVolume::build();

    // Every client has an alert nobody has acknowledged (`ScaleVolume`), so every page's
    // shared prop names a dispatch line per estate. It was a query each.
    $count = function () use ($v): array {
        QueryBudget::asANewRequest();
        $afresh = $v['director']->fresh();

        return QueryBudget::measure(fn () => $this->actingAs($afresh)->get('/clients/new')->assertOk());
    };

    $count();
    $before = $count();

    ScaleVolume::build();
    $count();
    $after = $count();

    $numberReads = static fn (array $m): int => count(array_filter(
        $m['statements'],
        static fn (array $s): bool => str_contains($s['sql'], 'site_emergency_numbers'),
    ));

    expect($numberReads($before))->toBe(1)
        ->and($numberReads($after))->toBe(1)
        ->and($after['count'])->toBe($before['count']);
});

it('keeps the dashboard\'s platform-wide cards for a few minutes, sums them again only when that time is up, and says when they were computed', function (): void {
    $v = ScaleVolume::build();
    Cache::flush();

    $cold = QueryBudget::measure(fn () => $this->actingAs($v['director'])->get('/dashboard')->assertOk());
    $warm = QueryBudget::measure(fn () => $this->actingAs($v['director'])->get('/dashboard')->assertOk());

    $sums = static fn (array $m): int => count(array_filter(
        $m['statements'],
        static fn (array $s): bool => str_contains($s['sql'], 'sum(') || str_contains($s['sql'], 'count(*) as `aggregate` from `tenants`'),
    ));

    // The cold load sums the platform; the warm one reads the figures it kept.
    expect($sums($cold))->toBeGreaterThan(0)
        ->and($sums($warm))->toBe(0)
        ->and($warm['count'])->toBeLessThan($cold['count']);

    $props = [];
    $this->actingAs($v['director'])->get('/dashboard')->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    // Said on the page, in the console's own clock, and never newer than the figures are.
    expect($props['figures_computed_at']['label'])->toStartWith('Figures as of ')
        ->and(Carbon::parse($props['figures_computed_at']['iso'])->lessThanOrEqualTo(now()))->toBeTrue();

    // Once the few minutes are up, the next load sums the platform again.
    $this->travel(PlatformOverview::DASHBOARD_TTL_SECONDS + 1)->seconds();
    $expired = QueryBudget::measure(fn () => $this->actingAs($v['director'])->get('/dashboard')->assertOk());
    expect($sums($expired))->toBeGreaterThan(0);
});

it('never works out the platform\'s revenue on behalf of a reader who may not see it, cached or not', function (): void {
    ScaleVolume::build();
    Cache::flush();

    $assistant = ScaleVolume::geminiUser(Role::ADMIN_ASSISTANT);
    $keys = app(PlatformOverview::class)->cacheKeys($assistant);
    $loaded = QueryBudget::measure(fn () => $this->actingAs($assistant)->get('/dashboard')->assertOk());

    // The counts are kept; the revenue entry is never filled, and the MRR sum never runs.
    expect(Cache::has($keys['counts']))->toBeTrue()
        ->and(Cache::has($keys['revenue']))->toBeFalse()
        ->and(collect($loaded['statements'])->contains(static fn (array $s): bool => str_contains($s['sql'], 'price_per_unit_minor')))->toBeFalse();
});

it('keeps each scope\'s cards apart: a Head of Security is never shown the Director\'s cached platform, nor the Director theirs', function (): void {
    $v = ScaleVolume::build();
    Cache::flush();

    $overview = app(PlatformOverview::class);
    $director = $v['director'];
    $clients = static fn (array $props): string => (string) collect($props['kpis'])->firstWhere('key', 'clients')['value'];

    // A Head of Security assigned to the scale estate, and a second one assigned to it and to one
    // more client: two different sets of sites.
    $headRole = Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail();
    $assign = static function (string $tenant, $user) use ($headRole): void {
        EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => $tenant, 'role_id' => $headRole->id, 'is_active' => true]);
    };

    $head = ScaleVolume::geminiUser(Role::HEAD_OF_SECURITY);
    $assign(ScaleFixture::ESTATE, $head);

    $widerHead = ScaleVolume::geminiUser(Role::HEAD_OF_SECURITY);
    $assign(ScaleFixture::ESTATE, $widerHead);
    $assign($v['clients'][1], $widerHead);

    $props = static function ($test, $viewer): array {
        $out = [];
        $test->actingAs($viewer->fresh())->get('/dashboard')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$out): void {
                $out = $page->toArray()['props'];
            });

        return $out;
    };

    // The Director fills the "all" entry first; each Head of Security then reads their own sites.
    $directorFirst = $props($this, $director);
    $headProps = $props($this, $head);
    $widerProps = $props($this, $widerHead);
    $directorAgain = $props($this, $director);

    // Each page is what that reader's own fresh figures say, and the cached answer and the live one agree.
    expect($clients($headProps))->toBe('1')
        ->and($clients($widerProps))->toBe('2')
        ->and($headProps['kpis'])->toBe($overview->kpis($head->fresh(), false))
        ->and($widerProps['kpis'])->toBe($overview->kpis($widerHead->fresh(), false))
        ->and($directorFirst['kpis'])->toBe($overview->kpis($director->fresh(), true))
        ->and($directorAgain['kpis'])->toBe($directorFirst['kpis'])
        ->and((int) $clients($directorFirst))->toBeGreaterThan(2)
        ->and($headProps['sites_only'])->toBeTrue()
        ->and($directorAgain['sites_only'])->toBeFalse();

    // Three scopes, three entries: the Director's, and one per set of sites.
    $keys = array_map(static fn ($viewer): string => $overview->cacheKeys($viewer->fresh())['counts'], [$director, $head, $widerHead]);
    expect(array_unique($keys))->toHaveCount(3);

    foreach ($keys as $key) {
        expect(Cache::has($key))->toBeTrue();
    }

    // The same sites in another order are the same scope, and a new assignment is a new one.
    $sameSites = ScaleVolume::geminiUser(Role::HEAD_OF_SECURITY);
    $assign($v['clients'][1], $sameSites);
    $assign(ScaleFixture::ESTATE, $sameSites);
    expect($overview->cacheKeys($sameSites->fresh()))->toBe($overview->cacheKeys($widerHead->fresh()));

    $assign($v['clients'][2], $head);
    expect($clients($props($this, $head)))->toBe('2');
});
