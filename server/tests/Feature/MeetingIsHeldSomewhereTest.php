<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A meeting has to be held SOMEWHERE, and the notice has to say where — M10 §8
|--------------------------------------------------------------------------
|
| `meetings.venue` and `meetings.virtual_link` have been columns since the table
| was created, and board 12's form drew NEITHER. So a secretary could schedule an
| AGM and the notice went out with a date, a time and an audience and nothing
| about the place: a household reading it had nowhere to go and nothing to click.
|
| What was missing underneath was the question those two columns answer between
| them. A venue and a link are not alternatives to be inferred from, because a
| meeting with BOTH is hybrid — a real arrangement, not an accident. `held_as`
| stores the intent, so the Resident App can say "Zoom" rather than guessing it
| from the shape of a URL, and a meeting moved offline stops claiming to be
| online even if an old link is still on the row.
|
| THE ASYMMETRY IS DELIBERATE and the first three tests are about it: an
| in-person meeting must have a venue, an online one must have a link, and an
| online one MAY also have a venue.
|
| No RefreshDatabase: this suite shares the estate fixture, so everything is
| rolled back in afterEach.
|
*/

const HELD_SOMEWHERE_TITLE = 'Where Is It Test Meeting';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->secretary = FacilitiesFixture::viewer(Role::SECRETARY);

    $this->payload = [
        'type' => Meeting::AGM,
        'title' => HELD_SOMEWHERE_TITLE,
        'starts_at' => now()->addMonths(2)->format('Y-m-d\TH:i'),
        'audience_scope' => Meeting::WHOLE_ESTATE,
        'held_as' => Meeting::IN_PERSON,
        'venue' => 'The Club House',
        'recording_enabled' => false,
        'recording_consent_notice' => false,
        'agenda' => [],
    ];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    Meeting::query()->where('title', HELD_SOMEWHERE_TITLE)->delete();
});

/** Post the scheduler form with these fields changed. */
function scheduleWith(array $changes): TestResponse
{
    return test()->actingAs(test()->secretary)
        ->post(FacilitiesFixture::url('/governance/meetings'), array_merge(test()->payload, $changes));
}

it('refuses an in-person meeting with nowhere to turn up to', function () {
    /*
     * A notice telling somebody to attend without saying where is worse than no
     * notice at all, because they will arrive somewhere.
     */
    scheduleWith(['venue' => ''])->assertSessionHasErrors('venue');

    expect(Meeting::query()->where('title', HELD_SOMEWHERE_TITLE)->exists())->toBeFalse();
});

it('refuses an online meeting with no joining link', function () {
    scheduleWith(['held_as' => Meeting::ZOOM, 'venue' => '', 'virtual_link' => ''])
        ->assertSessionHasErrors('virtual_link');
});

it('refuses a joining link that is not an http address', function () {
    /*
     * A joining link is put in front of every household in the estate. A
     * `javascript:` scheme in that position is a script somebody runs from a
     * notice they trust.
     */
    scheduleWith([
        'held_as' => Meeting::ZOOM,
        'venue' => '',
        'virtual_link' => 'javascript:alert(1)',
    ])->assertSessionHasErrors('virtual_link');

    scheduleWith([
        'held_as' => Meeting::ZOOM,
        'venue' => '',
        'virtual_link' => 'not a url at all',
    ])->assertSessionHasErrors('virtual_link');
});

it('takes an in-person meeting with a venue, and keeps no link on it', function () {
    scheduleWith([])->assertSessionHasNoErrors()->assertRedirect();

    $meeting = Meeting::query()->where('title', HELD_SOMEWHERE_TITLE)->firstOrFail();

    expect($meeting->held_as)->toBe(Meeting::IN_PERSON)
        ->and($meeting->venue)->toBe('The Club House')
        ->and($meeting->virtual_link)->toBeNull()
        ->and($meeting->isOnline())->toBeFalse()
        ->and($meeting->isHybrid())->toBeFalse()
        ->and($meeting->placeLine())->toBe('The Club House');
});

it('takes an online meeting with a link and no venue', function () {
    scheduleWith([
        'held_as' => Meeting::TEAMS,
        'venue' => '',
        'virtual_link' => 'https://teams.microsoft.com/l/meetup-join/abc',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $meeting = Meeting::query()->where('title', HELD_SOMEWHERE_TITLE)->firstOrFail();

    expect($meeting->held_as)->toBe(Meeting::TEAMS)
        ->and($meeting->virtual_link)->toBe('https://teams.microsoft.com/l/meetup-join/abc')
        ->and($meeting->isOnline())->toBeTrue()
        ->and($meeting->isHybrid())->toBeFalse()
        ->and($meeting->placeLine())->toBe('Microsoft Teams');
});

it('names the platform of an "Other online" meeting as the estate typed it, and keeps no name on any other mode', function () {
    // The client, 26 Sep: "there is no option to add … the meeting platform name". Webex was told as "Other online".
    scheduleWith([
        'held_as' => Meeting::OTHER_ONLINE,
        'platform_name' => '  Webex  ',
        'venue' => '',
        'virtual_link' => 'https://meet.webex.com/abc',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $meeting = Meeting::query()->where('title', HELD_SOMEWHERE_TITLE)->firstOrFail();

    expect($meeting->platform_name)->toBe('Webex')
        ->and($meeting->platformLabel())->toBe('Webex')
        ->and($meeting->placeLine())->toBe('Webex');

    // Left empty, it says what it always said.
    $meeting->forceFill(['platform_name' => null])->save();
    expect($meeting->platformLabel())->toBe('Other online');

    // A name sent with a named mode is not kept: Zoom is Zoom.
    Meeting::query()->where('title', HELD_SOMEWHERE_TITLE)->delete();
    scheduleWith(['held_as' => Meeting::ZOOM, 'platform_name' => 'Webex', 'venue' => '', 'virtual_link' => 'https://zoom.us/j/1'])->assertSessionHasNoErrors();
    $zoom = Meeting::query()->where('title', HELD_SOMEWHERE_TITLE)->firstOrFail();
    expect($zoom->platform_name)->toBeNull()
        ->and($zoom->platformLabel())->toBe('Zoom');
});

it('takes both for a hybrid meeting, and says so in one line', function () {
    /*
     * The case the whole asymmetry exists for. Hybrid is NOT a sixth value of
     * `held_as` — it is an online mode that also carries a venue, so the two
     * facts stay independent and nothing has to keep a sixth option in step
     * with the fields it governs (D-135).
     */
    scheduleWith([
        'held_as' => Meeting::ZOOM,
        'venue' => 'The Club House',
        'virtual_link' => 'https://zoom.us/j/123456789',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $meeting = Meeting::query()->where('title', HELD_SOMEWHERE_TITLE)->firstOrFail();

    expect($meeting->isOnline())->toBeTrue()
        ->and($meeting->isHybrid())->toBeTrue()
        ->and($meeting->venue)->toBe('The Club House')
        ->and($meeting->virtual_link)->toBe('https://zoom.us/j/123456789')
        ->and($meeting->placeLine())->toBe('Zoom · also in person at The Club House');
});

it('drops a stale link when a meeting is held in person', function () {
    /*
     * A meeting moved offline must not leave a joining link behind for a
     * household to click on the night. The controller nulls it rather than
     * trusting the form not to send one.
     */
    scheduleWith([
        'held_as' => Meeting::IN_PERSON,
        'venue' => 'The Club House',
        'virtual_link' => 'https://zoom.us/j/999999999',
    ])->assertSessionHasNoErrors();

    expect(Meeting::query()->where('title', HELD_SOMEWHERE_TITLE)->firstOrFail()->virtual_link)->toBeNull();
});

it('never leaves the place line blank, even on a meeting nobody has placed', function () {
    /*
     * A household reading a meeting with no location assumes the screen is
     * broken, not that the secretary has not decided. Reachable only by writing
     * the row directly — the form refuses it — which is why it is written
     * directly here.
     */
    $meeting = Meeting::query()->forceCreate([
        'type' => Meeting::COMMITTEE,
        'title' => HELD_SOMEWHERE_TITLE,
        'starts_at' => now()->addWeek(),
        'held_as' => Meeting::IN_PERSON,
        'venue' => null,
        'audience_scope' => Meeting::COMMITTEE_ONLY,
        'quorum_percent' => 50,
        'quorum_basis' => Meeting::MEMBERS,
        'eligible_households' => 0,
        'status' => Meeting::DRAFT,
    ]);

    // Said without an em dash (M53 Law 3), as every Governance sentence now is.
    expect($meeting->placeLine())->toBe('In person, venue to be confirmed');
});
