<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingAgendaItem;
use App\Models\Estate\MeetingAttendance;
use App\Models\Estate\MeetingMinutes;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| One meeting, whole — the detail behind a board 36 row (12 §2, item 21)
|--------------------------------------------------------------------------
|
| THE QUORUM IS THE REGISTER'S. It is counted from attendance by the same
| method board 36's badge uses, so the register and the meeting cannot
| disagree. Attendance is counted, never listed.
|
*/

const MEETING_DETAIL_TITLE = 'Detail Test Committee Sitting';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();

    $ids = Meeting::query()->where('title', MEETING_DETAIL_TITLE)->pluck('id');
    MeetingAttendance::query()->whereIn('meeting_id', $ids)->delete();
    MeetingAgendaItem::query()->whereIn('meeting_id', $ids)->delete();
    MeetingMinutes::query()->whereIn('meeting_id', $ids)->delete();
    Meeting::query()->whereIn('id', $ids)->delete();
});

it('shows a held meeting with its agenda, its counted quorum and its minutes', function () {
    $meeting = Meeting::query()->forceCreate([
        'type' => Meeting::COMMITTEE,
        'title' => MEETING_DETAIL_TITLE,
        'starts_at' => now()->subDays(10)->setTime(18, 30),
        'venue' => 'Clubhouse',
        'audience_scope' => Meeting::COMMITTEE_ONLY,
        'quorum_percent' => 50,
        'quorum_basis' => Meeting::MEMBERS,
        'quorum_required_total' => 7,
        'eligible_households' => 0,
        'status' => Meeting::HELD,
        'published_at' => now()->subDays(20),
    ]);

    MeetingAgendaItem::query()->forceCreate(['meeting_id' => $meeting->id, 'start_time' => '18:30', 'text' => 'Apologies', 'sort_order' => 1]);
    MeetingAgendaItem::query()->forceCreate(['meeting_id' => $meeting->id, 'text' => 'Gate barrier quotation', 'sort_order' => 2]);

    foreach ([MeetingAttendance::PRESENT, MeetingAttendance::PRESENT, MeetingAttendance::PRESENT, MeetingAttendance::PRESENT, MeetingAttendance::APOLOGIES] as $i => $state) {
        MeetingAttendance::query()->forceCreate(['meeting_id' => $meeting->id, 'attendee_name' => 'Member '.$i, 'state' => $state]);
    }

    MeetingMinutes::query()->forceCreate([
        'meeting_id' => $meeting->id,
        'body' => 'The committee approved the barrier quotation.',
        'recorded_by_name' => 'Estate Secretary',
    ]);

    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $propertyManager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/governance/meetings/'.$meeting->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Governance/Meeting')
            ->where('meeting.title', MEETING_DETAIL_TITLE)
            ->has('meeting.agenda', 2)
            // Law 5 as decided (M62 A5, DR-039): the console is on the estate's clock and tags no time.
            ->where('meeting.agenda.0.time', '6:30 PM')
            // 50% of seven members is 3.5, rounded UP to four; four were present.
            ->where('meeting.quorum.required', 4)
            ->where('meeting.quorum.present', 4)
            ->where('meeting.quorum.label', 'Quorum met · 4/7')
            ->where('meeting.quorum.apologies', 1)
            ->where('meeting.minutes.body', 'The committee approved the barrier quotation.')
            ->has('documents')
            // The register is the office's own record (M56 §2): listed to those who keep it, so a wrong line can come off.
            // Nobody outside governance opens this page at all (the Property Manager, below); the phones are told counts.
            ->has('meeting.register', 5)
            ->where('meeting.register.0.who', 'Member 0'));

    // "Export minutes" on the election control room offers the meetings
    // minuted that year, because a ballot records no meeting of its own.
    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/governance/elections/'.$meeting->starts_at->year))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('minutedMeetings', fn ($meetings) => collect($meetings)->contains('id', $meeting->id))
            ->where('reasons.minutes', null));

    // Governance is not part of the Property Manager's access.
    $this->actingAs($propertyManager)
        ->get(FacilitiesFixture::url('/governance/meetings/'.$meeting->id))
        ->assertForbidden();

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/governance/meetings/999999'))
        ->assertNotFound();
});
