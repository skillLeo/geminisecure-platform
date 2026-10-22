<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Models\Role;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A meeting says where, or deliberately that it is to be confirmed (M68 §2.7, GV-6)
|--------------------------------------------------------------------------
|
| In person needs a venue and online needs its link, as the server already held;
| now an in-person meeting may instead be saved as "to be confirmed", but only when
| that is chosen (`venue_tbc`), and the page then says "To be confirmed" rather
| than "No venue recorded".
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: every meeting here carries this
| file's title and goes afterwards.
|
*/

const VENUE_TITLE = 'Venue Rule Test Meeting';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $this->form = [
        'type' => Meeting::COMMITTEE,
        'title' => VENUE_TITLE,
        'starts_at' => SiteTime::local(now()->addDays(30))->format('Y-m-d\TH:i'),
        'audience_scope' => Meeting::COMMITTEE_ONLY,
        'held_as' => Meeting::IN_PERSON,
    ];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    Meeting::query()->where('title', 'like', VENUE_TITLE.'%')->delete();
});

it('refuses an in-person meeting with no venue, unless "to be confirmed" was chosen, and stores that choice', function () {
    $this->actingAs($this->president)->post(FacilitiesFixture::url('/governance/meetings'), $this->form)
        ->assertSessionHasErrors('venue');

    $this->actingAs($this->president)->post(FacilitiesFixture::url('/governance/meetings'), [...$this->form, 'venue_tbc' => true])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();

    expect(Meeting::query()->where('title', VENUE_TITLE)->sole()->venue)->toBe('To be confirmed');
});

it('still refuses an online meeting with no link, whatever is ticked', function () {
    $this->actingAs($this->president)->post(FacilitiesFixture::url('/governance/meetings'), [...$this->form, 'held_as' => Meeting::ZOOM, 'venue_tbc' => true])
        ->assertSessionHasErrors('virtual_link');
});
