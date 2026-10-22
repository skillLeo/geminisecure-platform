<?php

declare(strict_types=1);

use App\Models\Estate\Document;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingAgendaItem;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| "View agenda" reads the paper issued; a new one only when the agenda changed (GV-3, register DR-097)
|--------------------------------------------------------------------------
|
| Every press of "View agenda" more than fifteen minutes after the last issued and kept a new agenda paper, each kept
| for seven years: two on one meeting already, identical. A statement is reused only inside that window because a
| balance moves; an agenda or minutes paper says only what the meeting says, so one that would read the same is the
| same paper, however long ago it was issued. A press hands it back; a changed agenda issues a new one.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the meeting here is this file's own and is taken away afterwards. Its
| papers stay, as every issued paper does.
|
*/

const AGENDA_PAPER_TEST_TITLE = 'Agenda Paper Test Meeting';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Queue::fake();
});

afterEach(function () {
    $this->travelBack();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    $ids = Meeting::query()->where('title', AGENDA_PAPER_TEST_TITLE)->pluck('id');
    MeetingAgendaItem::query()->whereIn('meeting_id', $ids)->delete();
    Meeting::query()->whereIn('id', $ids)->delete();
});

it('hands back the agenda already issued, days later, and issues a new one once the agenda changes', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    FacilitiesFixture::boot();
    $meeting = Meeting::query()->forceCreate([
        'type' => Meeting::EGM, 'title' => AGENDA_PAPER_TEST_TITLE, 'starts_at' => now()->addDays(30), 'held_as' => Meeting::ZOOM,
        'virtual_link' => 'https://zoom.us/j/1234567890', 'audience_scope' => Meeting::WHOLE_ESTATE,
        'quorum_percent' => 20, 'quorum_basis' => Meeting::HOUSEHOLDS, 'eligible_households' => 10,
        'status' => Meeting::SCHEDULED, 'published_at' => now()->subDay(),
    ]);
    $item = MeetingAgendaItem::query()->create(['meeting_id' => $meeting->id, 'start_time' => '18:00', 'text' => 'Call to order', 'sort_order' => 1]);

    $press = function () use ($president, $meeting): void {
        $this->actingAs($president)
            ->post(FacilitiesFixture::url('/governance/meetings/'.$meeting->id.'/agenda'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        FacilitiesFixture::boot();
    };
    $papers = static fn () => Document::query()->where('kind', Document::AGENDA)->where('subject_id', (string) $meeting->id)->orderBy('id');

    $press();

    // The worker writes it, as it does every paper.
    $papers()->firstOrFail()->forceFill(['status' => Document::READY, 'issued_at' => now()])->save();

    // Two days on, nothing about the meeting has changed: View hands that paper back.
    $this->travel(2)->days();
    $press();

    expect($papers()->count())->toBe(1);

    // The agenda changes: the next View issues the paper it now is.
    $item->forceFill(['text' => 'Call to order and apologies'])->save();
    $press();

    expect($papers()->count())->toBe(2);
});
