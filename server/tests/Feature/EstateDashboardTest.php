<?php

declare(strict_types=1);

use App\Models\Estate\Meeting;
use App\Models\Role;
use App\Services\Estate\DashboardTiles;
use App\Services\Estate\Maintenance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The Estate Console dashboard — board community-admin-02
|--------------------------------------------------------------------------
|
| A TILE IS A CLAIM ABOUT THE ESTATE, and the rule every screen keeps is that
| it must never claim what is not so. "Next meeting: AGM · Oct 12" says the
| households have been told of a meeting; a draft nobody has published says no
| such thing, and neither does a meeting that was called off.
|
*/

/**
 * One meeting row, written past the model's events.
 *
 * RAW, because a published meeting's `created` event announces it to every
 * household's phone, and a test of what a tile says has no business sending a
 * push. The estate database is committed and shared, so the caller removes it.
 */
function dashboardMeeting(string $type, string $title, Carbon $startsAt, string $status, ?Carbon $publishedAt): int
{
    return (int) DB::connection('tenant')->table('meetings')->insertGetId([
        'type' => $type,
        'title' => $title,
        'starts_at' => $startsAt->copy()->utc(),
        'status' => $status,
        'published_at' => $publishedAt?->copy()->utc(),
        'created_at' => now()->utc(),
        'updated_at' => now()->utc(),
    ]);
}

/** @return array<string, mixed> the dashboard's meeting tile, as this viewer is sent it */
function meetingTile(mixed $test, mixed $viewer): array
{
    $tile = [];

    $test->actingAs($viewer)
        ->get(FacilitiesFixture::url('/'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$tile): void {
            $tile = collect($page->toArray()['props']['kpis'])->firstWhere('key', 'meeting');
        });

    return $tile;
}

it('counts a meeting on the dashboard only once the households have been told of it (B054)', function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $made = [];

    try {
        $president = FacilitiesFixture::viewer(Role::PRESIDENT);

        /*
         * SOONER THAN ANYTHING ELSE ON THE REGISTER, so no other meeting can
         * stand between these and the tile. A Secretary sketching an AGM that
         * may never be called, and an EGM that was called and then cancelled.
         */
        $made[] = dashboardMeeting(Meeting::AGM, 'B054 — a draft nobody has published', now()->addMinutes(20), Meeting::DRAFT, null);
        $made[] = dashboardMeeting(Meeting::EGM, 'B054 — called, then cancelled', now()->addMinutes(25), Meeting::CANCELLED, now()->subDay());

        // What the tile should say about the rest of the register: the soonest
        // meeting that is scheduled and published, or none.
        $told = Meeting::query()
            ->where('status', Meeting::SCHEDULED)
            ->whereNotNull('published_at')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();

        $tile = meetingTile($this, $president);

        expect($tile['label'])->toBe($told === null ? 'No meeting scheduled' : 'Next meeting: '.$told->typeLabel())
            ->and($tile['value'])->toBe($told?->starts_at->format('M j') ?? '—');

        // Published, it is the next meeting — the positive control.
        $made[] = dashboardMeeting(Meeting::COMMITTEE, 'B054 — scheduled and published', now()->addMinutes(30), Meeting::SCHEDULED, now());

        $tile = meetingTile($this, $president);

        expect($tile['label'])->toBe('Next meeting: Committee')
            ->and($tile['value'])->toBe(now()->addMinutes(30)->setTimezone('America/Jamaica')->format('M j'));
    } finally {
        DB::connection('tenant')->table('meetings')->whereIn('id', $made)->delete();
        DB::connection('mysql')->rollBack();
    }
});

/*
 * THE MAINTENANCE TILE IS THE MAINTENANCE PAGE'S OPEN CARD, AND SAYS WHAT THAT COUNTS (Hassam, 10 October). The QA
 * session's status model (fed643d5, M68 §2.3) made "Open" the tickets nobody has the job for yet, Submitted or
 * Acknowledged. The tile reads that card (`DashboardTiles::structure()`), so its number follows; its words say so,
 * and keep "open maintenance", which the browser journeys read the tile by.
 */
it('counts on its maintenance tile what the maintenance page counts as open, and says what that is', function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    try {
        $president = FacilitiesFixture::viewer(Role::PRESIDENT);
        app(DashboardTiles::class)->forget();

        $tile = [];

        $this->actingAs($president)
            ->get(FacilitiesFixture::url('/'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$tile): void {
                $tile = collect($page->toArray()['props']['kpis'])->firstWhere('key', 'maintenance');
            });

        // The request ended the estate's connection; the maintenance page's own card, read as that page reads it.
        FacilitiesFixture::boot();
        $open = collect(app(Maintenance::class)->tiles()['kpis'])->firstWhere('key', 'open');

        expect($tile['label'])->toBe('Open maintenance, no vendor yet')
            ->and($tile['value'])->toBe((int) $open['value']);
    } finally {
        DB::connection('mysql')->rollBack();
    }
});
