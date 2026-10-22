<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HeadOfSecurityScope;

/*
|--------------------------------------------------------------------------
| The cross-tenant reports, as the Head of Security may read them — bug hunt B003
|--------------------------------------------------------------------------
|
| The revenue reports — platform MRR, every client's tier, units and monthly
| contribution, churn — were behind `cross_tenant_reports.view` alone, which the
| Head of Security holds Scoped. They opened for that role, unscoped, and both
| exports downloaded the platform's whole commercial position as a file, while
| the same role's dashboard withheld the Platform MRR card. Two rules were
| missed at once:
|
|   D-139   revenue follows Billing & subscriptions, which this role does not hold
|   D-015   this role's assigned-sites scope applies to every cell it holds
|
| So the three revenue reports and their exports are behind Billing as well, the
| catalogue says so on the card, and every report — the utilisation report this
| role does open, and the revenue ones for a scoped role that holds Billing — is
| read as its viewer may see it.
|
| THE POSITIVE HALF IS BESIDE EACH REFUSAL: the Director still opens and reads
| everything, against the same rows.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    HeadOfSecurityScope::seed($this);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('refuses the Head of Security the three revenue reports and both exports, and the Director still opens them', function () {
    foreach (['/reports/mrr', '/reports/revenue', '/reports/churn', '/reports/mrr/export', '/reports/revenue/export'] as $path) {
        $this->actingAs($this->head)->get($path)->assertForbidden();
    }

    foreach (['/reports/mrr', '/reports/revenue', '/reports/churn'] as $path) {
        $this->actingAs($this->director)->get($path)->assertOk();
    }

    $this->actingAs($this->director)->get('/reports/revenue/export')->assertOk();
});

it('tells the Head of Security on the catalogue why the revenue reports do not open, and opens the operational ones', function () {
    $cards = collect(HeadOfSecurityScope::props($this->actingAs($this->head)->get('/reports'))['groups'])
        ->flatMap(fn (array $group) => $group['reports'])
        ->keyBy('key');

    foreach (['mrr_trend', 'revenue_by_tier', 'churn_retention'] as $key) {
        expect($cards[$key]['href'])->toBeNull($key)
            ->and($cards[$key]['unavailable'])->toContain('Billing & subscriptions');
    }

    foreach (['guard_utilisation', 'psra_compliance', 'client_health'] as $key) {
        expect($cards[$key]['href'])->not->toBeNull($key)
            ->and($cards[$key]['unavailable'])->toBeNull();
    }
});

it('narrows the utilisation report to the Head of Security\'s own sites', function () {
    $clients = array_column(HeadOfSecurityScope::props($this->actingAs($this->head)->get('/reports/utilisation'))['rows'], 'client');

    expect($clients)->toContain($this->names[$this->mine])
        ->and($clients)->not->toContain($this->names[$this->theirs])
        ->and($clients)->not->toContain($this->names[$this->onboarding]);

    $everyone = array_column(HeadOfSecurityScope::props($this->actingAs($this->director)->get('/reports/utilisation'))['rows'], 'client');

    expect($everyone)->toContain($this->names[$this->mine])
        ->and($everyone)->toContain($this->names[$this->theirs]);
});

it('narrows every revenue report, and the audit entry of its export, for a site-scoped role that does hold Billing', function () {
    $copy = HeadOfSecurityScope::billingCopy($this->suffix, $this->mine);

    // Revenue by tier: only its own client, and the total is that client's contribution.
    $revenue = HeadOfSecurityScope::props($this->actingAs($copy)->get('/reports/revenue'));
    $contributors = collect($revenue['clients'])->keyBy('client');

    expect($contributors->keys()->all())->toBe([$this->names[$this->mine]])
        ->and($revenue['total'])->toBe($contributors[$this->names[$this->mine]]['contribution'])
        ->and($revenue['total'])->toContain('40,800');

    // Churn and the MRR trend: nothing about anybody else.
    $churn = HeadOfSecurityScope::props($this->actingAs($copy)->get('/reports/churn'));
    $mrr = HeadOfSecurityScope::scan(HeadOfSecurityScope::props($this->actingAs($copy)->get('/reports/mrr')));

    expect(array_column($churn['tenures'], 'client'))->toBe([$this->names[$this->mine]])
        ->and($mrr)->not->toContain(strtolower($this->names[$this->theirs]))
        ->and($mrr)->not->toContain(strtolower($this->names[$this->onboarding]))
        ->and($mrr)->toContain(strtolower($this->names[$this->mine]));

    // The file, and the record of it leaving, say the same.
    $csv = $this->actingAs($copy)->get('/reports/revenue/export')->assertOk()->streamedContent();

    expect($csv)->toContain($this->names[$this->mine])
        ->and($csv)->not->toContain($this->names[$this->theirs]);

    $this->actingAs($copy)->get('/reports/mrr/export')->assertOk()->streamedContent();

    $entry = AuditEntry::query()
        ->where('action', 'export.taken')
        ->where('actor_id', $copy->id)
        ->orderByDesc('id')
        ->firstOrFail();

    expect($entry->after['tenants'])->toBe([$this->names[$this->mine]])
        ->and($entry->after['scope'])->not->toContain('every client on the platform');

    // The Director's same report still names every client.
    $all = collect(HeadOfSecurityScope::props($this->actingAs($this->director)->get('/reports/revenue'))['clients'])->pluck('client');

    expect($all)->toContain($this->names[$this->mine])
        ->and($all)->toContain($this->names[$this->theirs]);
});
