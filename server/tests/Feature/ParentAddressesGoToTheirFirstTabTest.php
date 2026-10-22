<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Estate\Governance;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A parent address goes to its first tab (FAC-17, register DR-088)
|--------------------------------------------------------------------------
|
| `/facilities/amenities` and `/governance/elections` answered a raw "405 Method Not Allowed" to anybody who typed
| them or trimmed an address back to them: each holds only the POST that creates an amenity or an election. A GET now
| goes to the module's first tab, the bookings diary and the election the Elections tab opens, behind the same gate
| as the module; the POSTs are unchanged.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    $this->withoutVite();
});

it('sends /facilities/amenities to the bookings diary', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))
        ->get(FacilitiesFixture::url('/facilities/amenities'))
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/bookings'));
});

it('sends /governance/elections to the election the Elections tab opens', function () {
    $year = app(Governance::class)->electionYear();

    $this->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))
        ->get(FacilitiesFixture::url('/governance/elections'))
        ->assertRedirect(FacilitiesFixture::url('/governance/elections/'.$year));
});
