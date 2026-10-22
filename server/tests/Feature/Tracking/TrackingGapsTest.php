<?php

declare(strict_types=1);

use App\Jobs\Push\SendExpoPush;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Dispatch\PatrolMonitor;
use App\Services\Tracking\GuardTracking;
use App\Services\Tracking\TrackingGaps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| A gap in an officer's positions is seen — by the dispatcher, on the record, and on their phone (M22 §1.1)
|--------------------------------------------------------------------------
|
| Found on the emulator: Android started the Guard App in the background, its on-duty task received no positions
| for the rest of that run, and the notification went on saying "location shared". The phone could not know. Now the
| positions themselves say it: on a shift the officer agreed to share, ten minutes with no position TAKEN is a gap —
| on the dispatcher's alertness board while it is open, on the officer's record afterwards, and told to the officer's
| phone once. Judged on capture times: a phone out of signal that sends its queue later has no gap.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-10-12 15:00:00', 'UTC'));
    config(['tracking.gap_after_seconds' => 600, 'tracking.enabled' => true]);

    $this->post = RingFixture::post();
    [$this->guard, , $this->handset] = RingFixture::guard('GS-GAP-1', postId: $this->post->id);
    // Clocked in at 13:00 UTC (8:00 AM in Kingston), rostered to 01:00.
    $this->shift = Shift::withoutEvents(fn (): Shift => Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $this->guard->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->subHours(2), 'rostered_end' => now()->addHours(10),
        'actual_start' => now()->subHours(2), 'status' => 'on_duty',
    ]));
    gapConsent($this->guard->id, GuardTracking::GRANTED);
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    RingFixture::tearDown();
});

function gapConsent(int $guardId, string $decision): void
{
    DB::connection('mysql')->table('guard_tracking_consents')->insert([
        'guard_id' => $guardId, 'policy_version' => GuardTracking::policyVersion(), 'decision' => $decision, 'decided_at' => now()->subDay(), 'device_time' => now()->subDay(),
    ]);
}

/** Positions taken each minute from `$from` to `$to` (UTC), arriving at `$received` (default: as taken). */
function gapFixes(Shift $shift, string $from, string $to, ?string $received = null): void
{
    $rows = [];
    for ($at = Carbon::parse($from, 'UTC'); $at->lte(Carbon::parse($to, 'UTC')); $at = $at->copy()->addMinute()) {
        $rows[] = [
            'guard_id' => $shift->guard_id, 'shift_id' => $shift->id, 'tenant_id' => (string) $shift->tenant_id, 'client_id' => (string) Str::uuid(),
            'latitude' => 18.0181, 'longitude' => -76.8102, 'accuracy_m' => 8, 'source' => 'interval', 'battery_pct' => 70,
            'captured_at' => $at, 'device_captured_at' => $at, 'received_at' => $received === null ? $at : Carbon::parse($received, 'UTC'), 'is_simulated' => false,
        ];
    }
    DB::connection('mysql')->table('guard_positions')->insert($rows);
}

it('finds every stretch with no position taken, on capture time, so positions that arrived late leave no gap', function () {
    gapFixes($this->shift, '2026-10-12 13:00', '2026-10-12 13:30');
    // Forty minutes with nothing taken: the gap.
    // Then twenty minutes TAKEN out of signal and SENT at 14:55: recorded, so not a gap.
    gapFixes($this->shift, '2026-10-12 14:10', '2026-10-12 14:30', received: '2026-10-12 14:55');
    // Nothing since 14:30: a gap still open, half an hour so far.

    $record = app(TrackingGaps::class)->onShift($this->shift->refresh());

    expect($record['shared'])->toBeTrue()
        ->and($record['count'])->toBe(52)
        ->and($record['gaps'])->toHaveCount(2)
        ->and($record['gaps'][0]['seconds'])->toBe(2400)
        ->and($record['gaps'][1]['to'])->toBeNull();

    $lines = array_map(fn (array $gap): string => TrackingGaps::line($gap, FacilitiesFixture::ESTATE), $record['gaps']);
    expect($lines)->toBe([
        'No position recorded 8:30 AM – 9:10 AM JA (40 min)',
        'No position received since 9:30 AM JA (30 min)',
    ]);
});

it('shows the open gap on the dispatcher\'s alertness board, and names the officer in its banner', function () {
    gapFixes($this->shift, '2026-10-12 13:00', '2026-10-12 14:30');

    $board = app(PatrolMonitor::class)->forViewer($this->director);
    $row = collect($board['rows'])->firstWhere('key', 'guard-'.$this->guard->id);

    expect($row['position'])->toBe('None since 9:30 AM JA · 30 min')
        ->and($row['position_gap'])->toBeTrue()
        ->and($board['banner']['title'])->toBe($this->guard->full_name.' — no position received')
        ->and($board['banner']['meta'])->toContain('No position received since 9:30 AM JA (30 min)');

    // Positions coming in again: the cell says how recent, and the gap is gone from the board.
    gapFixes($this->shift, '2026-10-12 14:57', '2026-10-12 14:58');
    $row = collect(app(PatrolMonitor::class)->forViewer($this->director)['rows'])->firstWhere('key', 'guard-'.$this->guard->id);
    expect($row['position'])->toBe('2 min ago')->and($row['position_gap'])->toBeFalse();
});

it('tells the officer\'s phone once per gap, and again only for a new one', function () {
    gapFixes($this->shift, '2026-10-12 13:00', '2026-10-12 14:30');

    expect(app(TrackingGaps::class)->watch())->toBe(['watched' => 1, 'open' => 1, 'told' => 1]);
    // A minute later the same gap is still open: nobody is told twice.
    Carbon::setTestNow(now()->addMinute());
    expect(app(TrackingGaps::class)->watch()['told'])->toBe(0);

    Bus::assertDispatchedTimes(SendExpoPush::class, 1);
    $notice = DB::connection('mysql')->table('tracking_gap_notices')->where('shift_id', $this->shift->id)->first();
    expect((int) $notice->handsets)->toBe(1)
        ->and(Carbon::parse((string) $notice->gap_since, 'UTC')->toDateTimeString())->toBe('2026-10-12 14:30:00');

    // Positions again, then silence again: a new gap, told once more.
    gapFixes($this->shift, '2026-10-12 15:02', '2026-10-12 15:05');
    Carbon::setTestNow(Carbon::parse('2026-10-12 15:20:00', 'UTC'));
    expect(app(TrackingGaps::class)->watch()['told'])->toBe(1);
    Bus::assertDispatchedTimes(SendExpoPush::class, 2);
});

it('is not a gap when the officer did not agree to share: that is "not shared", and nobody is pushed', function () {
    gapConsent($this->guard->id, GuardTracking::REFUSED);

    expect(app(TrackingGaps::class)->onShift($this->shift->refresh()))->toMatchArray(['shared' => false, 'gaps' => []])
        ->and(app(TrackingGaps::class)->watch()['told'])->toBe(0);
    $row = collect(app(PatrolMonitor::class)->forViewer($this->director)['rows'])->firstWhere('key', 'guard-'.$this->guard->id);
    expect($row['position'])->toBe('Not shared')->and($row['position_gap'])->toBeFalse();
    Bus::assertNotDispatched(SendExpoPush::class);
});

it('keeps each recent shift\'s gaps on the officer\'s record, for the Head of Security to read afterwards', function () {
    gapFixes($this->shift, '2026-10-12 13:00', '2026-10-12 13:30');
    gapFixes($this->shift, '2026-10-12 14:10', '2026-10-12 14:30');
    $this->shift->forceFill(['actual_end' => Carbon::parse('2026-10-12 14:40', 'UTC'), 'status' => 'completed'])->save();

    $this->actingAs($this->director)->get('/guards/'.$this->guard->id)->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('positions.0.gap', true)
            ->where('positions.0.summary', '1 gap · 52 positions recorded')
            ->where('positions.0.gaps', ['No position recorded 8:30 AM – 9:10 AM JA (40 min)']));
});
