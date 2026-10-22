<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Add resident opens in a modal over the register (Hassam, 11 Oct 2026)
|--------------------------------------------------------------------------
|
| "Add resident opens the form inline on the page. It must open in a modal." The register draws the form in the
| console's modal; the form's board, and each lookup of the unit as it is typed, comes from the register itself, only
| when the modal asks for it: a partial reload of `addResident`, the phase and unit as `add_phase` and `add_lot`. An
| ordinary visit to the register carries none of it, and the lookup never touches the register's own phase filter. The
| modal's half is tests/js/estate-add-resident-modal.test.mjs.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED, so the unit here is this file's own scratch lot.
|
*/

const REGISTER_MODAL_PHASE = 'Phase 9';

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

it('sends the register without the add-resident board on an ordinary visit', function () {
    $this->actingAs($this->admin)
        ->get(FacilitiesFixture::url('/residents'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Residents/Index')->missing('addResident'));
});

it('answers the modal with the board, the unit looked up under add_phase and add_lot, the register\'s filter untouched', function () {
    FacilitiesFixture::scratchUnit('RM-4A', REGISTER_MODAL_PHASE);

    $url = FacilitiesFixture::url('/residents?'.http_build_query(['add_phase' => REGISTER_MODAL_PHASE, 'add_lot' => 'rm-4a']));

    $this->actingAs($this->admin)
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Residents/Index')
            ->missing('addResident')
            ->where('filters.phase', '')
            ->reloadOnly('addResident', fn (AssertableInertia $reload) => $reload
                ->where('addResident.lot.phase', REGISTER_MODAL_PHASE)
                ->where('addResident.lot.typed', 'rm-4a')
                ->where('addResident.lot.resolved', 'RM-4A')
                ->where('addResident.canCreate', true)
                ->has('addResident.phases')
                ->has('addResident.verification')
                ->has('addResident.household_choices')
                ->has('addResident.biometric_consent')
                ->has('addResident.preview')
                ->missing('rows')));
});
