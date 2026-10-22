<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Services\Estate\Reports;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The Meeting Minutes Archive lists held meetings, in the Meetings tab's words (REP-3, M85 Phase B)
|--------------------------------------------------------------------------
|
| M81 §5: the archive "lists draft and future meetings as though held, with 'Not recorded', and its status wording
| disagrees with the Meetings tab. Held meetings only, one set of status terms." A meeting appears once it has been held
| (Held, Minutes published, Archived), and says which in the Meetings tab's own words (`Meeting::STAGE_LABELS`).
|
| Meetings of this file's own in a year nobody else uses, inside a transaction that is rolled back.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    DB::connection('tenant')->beginTransaction();

    $meeting = static fn (string $title, string $status, string $startsAt, ?string $published = '2013-01-15 15:00:00') => Meeting::query()->create([
        'type' => Meeting::COMMITTEE, 'title' => $title, 'starts_at' => $startsAt, 'duration_minutes' => 120, 'held_as' => Meeting::IN_PERSON,
        'venue' => 'Community Centre', 'audience_scope' => Meeting::COMMITTEE_ONLY, 'quorum_percent' => 50, 'quorum_basis' => Meeting::MEMBERS,
        'quorum_required_total' => 5, 'eligible_households' => 0, 'status' => $status, 'published_at' => $published,
    ]);

    // One held in March 2013; a draft never published; one called off.
    $meeting('M85 archive held', Meeting::SCHEDULED, '2013-03-10 15:00:00');
    $meeting('M85 archive draft', Meeting::DRAFT, '2013-04-10 15:00:00', null);
    $meeting('M85 archive cancelled', Meeting::CANCELLED, '2013-05-10 15:00:00');
});

afterEach(function (): void {
    DB::connection('tenant')->rollBack();
});

it('lists only the meetings that were held, each with the stage the Meetings tab gives it', function (): void {
    $report = app(Reports::class)->run('meeting_minutes', '2013-01-01', '2013-12-31');

    expect($report['columns'])->toBe(['Date', 'Type', 'Meeting', 'Status', 'Document'])
        ->and(array_map(static fn (array $row): array => [$row[2], $row[3]], $report['rows']))->toBe([['M85 archive held', 'Held']])
        ->and($report['summary'][0])->toBe(['label' => 'Meetings held', 'value' => '1']);
});
