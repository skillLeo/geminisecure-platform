<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\ReportController;
use App\Models\Role;
use App\Services\Gemini\CrossTenantReports;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The revenue reports are money, and ask for it — M11 B3's audit, D-151
|--------------------------------------------------------------------------
|
| `gemini.cross_tenant_reports.view` opened the operational reports and the
| revenue reports alike. So the Head of Security — site-scoped, holding no
| Billing & subscriptions — could read the platform's MRR and every client's
| share of it, and take both away as files, which D-139 ("The Gemini Head of
| Security and Dispatcher hold neither money module, so they lose the same
| figures") says it must not.
|
| And their Export was asked of `gemini.reports.export`, a permission that does
| not exist, so the button the boards draw was inert for everybody — the Director
| included — while the export route itself was open to the real permission.
|
| M11 B3's permission audit found both; bug hunt B003 closed them first, and put
| churn in the revenue group as well (which clients cancelled is Billing's
| record). This file holds the audit's finding against that fix.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('refuses the Head of Security the revenue reports and their files, and leaves it the operational ones', function () {
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    // The permissions, as the matrix gives them: the reports module, and no money.
    expect($head->can('gemini.cross_tenant_reports.view'))->toBeTrue()
        ->and($head->can('gemini.billing_subscriptions.view'))->toBeFalse();

    foreach (['/reports/mrr', '/reports/revenue', '/reports/churn', '/reports/mrr/export', '/reports/revenue/export'] as $path) {
        $this->actingAs($head)->get($path)->assertForbidden();
    }

    // What carries no figure is still the Head of Security's to read.
    foreach (['/reports', '/reports/utilisation'] as $path) {
        $this->actingAs($head)->get($path)->assertOk();
    }

    // And the catalogue says why, on the three cards, instead of sending it to a 403.
    $cards = collect(app(CrossTenantReports::class)->catalogue($head))->flatMap(fn (array $group) => $group['reports'])->keyBy('key');

    foreach (['mrr_trend', 'revenue_by_tier', 'churn_retention'] as $key) {
        expect($cards[$key]['href'])->toBeNull()
            ->and($cards[$key]['unavailable'])->toBe(CrossTenantReports::REVENUE_WITHHELD);
    }

    expect($cards['guard_utilisation']['href'])->not->toBeNull();
});

it('offers the Export the boards draw to every role that may take the file, and to nobody else', function () {
    // The permission the control asks is the one the route asks, and it exists.
    expect(ReportController::EXPORT_PERMISSION)->toBe('gemini.cross_tenant_reports.export');

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $operations = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    // Director and Operations Manager: Billing, and Full on reports — the file too.
    foreach ([$director, $operations] as $viewer) {
        $this->actingAs($viewer)->get('/reports/mrr')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('exportHref', '/reports/mrr/export'));

        $this->actingAs($viewer)->get('/reports/revenue')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('exportHref', '/reports/revenue/export'));

        $this->actingAs($viewer)->get('/reports/mrr/export')->assertOk();
    }

    // The Accountant reads both — it holds Billing — and holds View, not export, on reports.
    $this->actingAs($accountant)->get('/reports/mrr')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('exportHref', null));

    $this->actingAs($accountant)->get('/reports/mrr/export')->assertForbidden();
});
