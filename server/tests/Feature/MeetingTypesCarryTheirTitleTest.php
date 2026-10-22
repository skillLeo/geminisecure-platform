<?php

declare(strict_types=1);

use App\Models\Role;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Each meeting type carries the title the form gives it (GV-2, register DR-096)
|--------------------------------------------------------------------------
|
| The form's title follows the type until somebody edits it, so each type the scheduler is sent carries its own title,
| written once on the server: "Annual General Meeting", not the toggle's "AGM". The form opens on the committee's.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    $this->withoutVite();
});

it('sends each type with its title, and opens on the committee meeting\'s', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))
        ->get(FacilitiesFixture::url('/governance/meetings/new'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('types', fn ($types) => collect($types)->pluck('title', 'value')->all() === [
                'agm' => 'Annual General Meeting',
                'egm' => 'Extraordinary General Meeting',
                'committee' => 'Committee meeting',
                'phase' => 'Phase meeting',
            ])
            ->where('defaults.title', 'Committee meeting'));
});
