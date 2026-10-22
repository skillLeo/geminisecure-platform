<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Services\Estate\Governance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A meeting says when notice was given, beside the rule (GV-17, register DR-101)
|--------------------------------------------------------------------------
|
| The meeting page said "21 days' notice required for this meeting type" and never the day notice went out, so nobody
| could check the rule from the console. It now says both, on the estate's calendar: "Notice given 19 Sep 2026".
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the meetings here are this file's own and are taken away afterwards.
|
*/

const NOTICE_GIVEN_TEST_TITLE = 'Notice Given Test Meeting';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    Meeting::query()->where('title', NOTICE_GIVEN_TEST_TITLE)->delete();
});

function noticedMeeting(array $attributes): Meeting
{
    FacilitiesFixture::boot();

    return Meeting::query()->forceCreate([
        'type' => Meeting::EGM, 'title' => NOTICE_GIVEN_TEST_TITLE, 'starts_at' => now()->addDays(30), 'held_as' => Meeting::ZOOM,
        'virtual_link' => 'https://zoom.us/j/1234567890', 'audience_scope' => Meeting::WHOLE_ESTATE,
        'quorum_percent' => 20, 'quorum_basis' => Meeting::HOUSEHOLDS, 'eligible_households' => 10,
        'status' => Meeting::SCHEDULED, 'published_at' => now()->subDay(), ...$attributes,
    ]);
}

it('says the day notice was given beside the rule, on the estate\'s calendar', function () {
    // 19 Sep 2026 at 9 PM in Kingston is already the 20th in UTC: the estate's day is the one said.
    $meeting = noticedMeeting(['notice_days_required' => 21, 'notice_given_at' => Carbon::parse('2026-09-20 02:00:00', 'UTC')]);

    expect(app(Governance::class)->meetingDetail($meeting->id)['notice'])
        ->toBe('21 days\' notice required for this meeting type · Notice given 19 Sep 2026');
});

it('says the rule alone for a meeting whose notice has not gone out', function () {
    $meeting = noticedMeeting(['notice_days_required' => 21, 'notice_given_at' => null, 'published_at' => null]);

    expect(app(Governance::class)->meetingDetail($meeting->id)['notice'])->toBe('21 days\' notice required for this meeting type');
});
