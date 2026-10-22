<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The register's empty filtered list names the filter that emptied it (B113)
|--------------------------------------------------------------------------
|
| Board 4's empty filtered state was written for the phase chip alone. A search
| with no chip set read "No household is filed in " — a blank phase — and blamed
| a chip nobody had pressed, and its "All phases" button kept the search, so it
| reloaded the same empty list: the one way out it offered went nowhere. The
| server now says which filter emptied the list and which filters its one button
| clears, because only it knows.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** Board 4 with these query parameters. */
function registerWith(object $test, object $viewer, string $query): mixed
{
    return $test->actingAs($viewer)->get(FacilitiesFixture::url('/residents?'.$query))->assertOk();
}

it('says a search matched nothing, naming the search, and clears the search', function () {
    registerWith($this, $this->admin, 'q=zzz-no-such-household')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Residents/Index')
            ->where('rows', [])
            ->where('empty_filtered.title', fn (string $title): bool => str_contains($title, 'zzz-no-such-household') && ! str_contains($title, 'filed in'))
            ->where('empty_filtered.clears', ['q'])
            ->where('empty_filtered.action_label', 'Clear the search'));
});

it('says a phase holds no household, naming the phase, and clears the phase', function () {
    registerWith($this, $this->admin, 'phase='.rawurlencode('Phase Nobody Lives In'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows', [])
            ->where('empty_filtered.title', 'No household is filed in Phase Nobody Lives In')
            ->where('empty_filtered.clears', ['phase'])
            ->where('empty_filtered.action_label', 'All phases'));
});

it('says the two together matched nothing, and clears both', function () {
    registerWith($this, $this->admin, 'phase='.rawurlencode('Phase 1').'&q=zzz-no-such-household')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rows', [])
            ->where('empty_filtered.title', fn (string $title): bool => str_contains($title, 'Phase 1') && str_contains($title, 'zzz-no-such-household'))
            ->where('empty_filtered.clears', ['q', 'phase']));
});

it('has nothing to say about filters when none is set', function () {
    registerWith($this, $this->admin, '')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('empty_filtered', null));
});
