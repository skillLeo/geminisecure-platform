<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Gemini\CrossTenantReports;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The cross-tenant report catalogue — board 36's "Open" (12 §2, item 45)
|--------------------------------------------------------------------------
|
| A card lights up because its route exists and the viewer holds the module.
| Four cards once looked up route names nobody had registered, and so told
| every reader that four built reports had "not shipped". This asserts every
| card opens for a role that holds everything.
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

it('opens every report on the catalogue for a role that holds its module', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $cards = collect(app(CrossTenantReports::class)->catalogue($director))->flatMap(fn (array $group) => $group['reports']);

    expect($cards)->toHaveCount(6);

    foreach ($cards as $card) {
        expect($card['href'])->not->toBeNull("{$card['name']} does not open: {$card['unavailable']}")
            ->and($card['unavailable'])->toBeNull();
    }

    $this->actingAs($director)
        ->get('/reports')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Gemini/Reports/Index'));
});

it('tells a role without the module why, rather than sending it to a 403', function () {
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    $cards = collect(app(CrossTenantReports::class)->catalogue($dispatcher))->flatMap(fn (array $group) => $group['reports']);

    $revenue = $cards->firstWhere('key', 'revenue_by_tier');

    expect($revenue['href'])->toBeNull()
        ->and($revenue['unavailable'])->toContain('Your role does not include');
});
