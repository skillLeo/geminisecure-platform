<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Estate\Ledger;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M83 §1.2 item 3: every dead control works or is visibly off, with its reason in one line (10 October 2026)
|--------------------------------------------------------------------------
|
| REP-4, REP-7: Arrears Ageing and Election Turnout drew From and To that changed nothing. Tonight they draw no dates:
| the ageing says the day it is as at, the turnout says it covers every election. (Making them filter is M85 B3.)
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

it('ends a long title in the top bar with an ellipsis, so the page never scrolls sideways (DR-126, M83 item 6)', function () {
    // The estate shell's own scoped style: resources/css is pinned with the classic Gemini pages (M81 §1.2.5).
    $shell = (string) file_get_contents(resource_path('js/Layouts/EstateConsole.vue'));

    expect(str_contains($shell, '<h1 :title="title">{{ title }}</h1>'))->toBeTrue()
        ->and((bool) preg_match('/\.topbar h1 \{[^}]*min-width: 0;[^}]*text-overflow: ellipsis;[^}]*white-space: nowrap;/s', $shell))->toBeTrue();
});

it('draws no dates on the two reports the dates do not narrow, and says what each covers instead', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/reports/arrears_ageing'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('periodApplies', false)
            ->where('period', 'As at '.Ledger::today()->format('j M Y')));

    // Election Turnout's dates narrow it now (REP-7, M85 Phase B): the elections whose poll ran in the period chosen.
    $this->actingAs(FacilitiesFixture::viewer(Role::PRESIDENT))->get(FacilitiesFixture::url('/reports/election_turnout').'?from=2026-01-01&to=2026-12-31')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('periodApplies', true)->where('period', '1 Jan 2026 to 31 Dec 2026'));

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/reports/profit_and_loss'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('periodApplies', true));

    // The page draws its From and To only where they apply.
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Reports/Show.vue'));
    expect(str_contains($page, 'v-if="periodApplies"'))->toBeTrue();
});
