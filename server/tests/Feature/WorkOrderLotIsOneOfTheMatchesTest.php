<?php

declare(strict_types=1);

use App\Models\Role;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The lot on a new work order is the one typed (FAC-13, register DR-085)
|--------------------------------------------------------------------------
|
| "047" is how a lot is written on a gate pass and a cheque, and it means Lot 47. The search read it as text, so it
| found only the lots whose reference holds "047": Lot 1047, alone, which the form then chose by itself. The
| leading zeros are now dropped from a lot number, so "088" finds Lot 88 first, as "88" does; the form no longer has
| a single wrong match to choose.
|
| One scratch lot, Lot 1088, belongs to this file.
*/

it('reads a lot number with leading zeros as the number, the exact lot first (FAC-13)', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    FacilitiesFixture::scratchUnit('Lot 1088');

    foreach (['088', 'Lot 088', '88'] as $typed) {
        $this->withoutVite()->actingAs($manager)
            ->get(FacilitiesFixture::url('/facilities/maintenance?unit_q='.urlencode($typed)))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('units.0.id', FacilitiesFixture::unit('Lot 88')->id, "\"{$typed}\" finds Lot 88 first")
                ->where('units.0.label', 'Lot 88 · Phase 4')
                ->where('unitQuery', $typed));
    }

    // Lot 1088 is still offered, after the lot that was meant: the form now has two to choose from, not one.
    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/maintenance?unit_q=088'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('units', fn ($units) => collect($units)->pluck('label')->contains('Lot 1088 · Phase 9')));
});
