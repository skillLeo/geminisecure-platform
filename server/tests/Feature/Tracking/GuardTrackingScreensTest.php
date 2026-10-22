<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Shift;
use App\Services\Tracking\GuardTracking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HeadOfSecurityScope;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| A guard's location sharing and the positions held about them, on the Gemini console (Hassam, 12 October 2026)
|--------------------------------------------------------------------------
|
| "Two console screens the tracking work needs and nobody owns: the guard's consent record, showing what was agreed and
| when, and a guard's own view of their track, because nobody is tracked without being able to see what is held about
| them." Both read only. The answers inherit the guard page's own permission; the places, the live map's
| (`gemini.dispatch.view`): nobody is added and nobody removed. A guard outside the viewer's estates is not found, and a
| site-scoped role sees only the shifts at its own estates.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-10-12 15:00:00', 'UTC'));
    config(['tracking.enabled' => true, 'tracking.retention_days' => 90]);

    $this->guard = HeadOfSecurityScope::officer(FacilitiesFixture::ESTATE, 'Track Officer '.Str::random(5));
    $this->here = RingFixture::post();
    $this->there = RingFixture::post(RingFixture::OTHER_ESTATE);
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    RingFixture::tearDown();
});

function trackAnswer(int $guardId, string $version, string $decision, string $at): void
{
    DB::connection('mysql')->table('guard_tracking_consents')->insert([
        'guard_id' => $guardId, 'policy_version' => $version, 'decision' => $decision,
        'decided_at' => Carbon::parse($at, 'UTC'), 'device_time' => Carbon::parse($at, 'UTC'),
    ]);
}

function trackShift(int $guardId, int $postId, string $tenant, string $from, ?string $to): Shift
{
    return Shift::withoutEvents(fn (): Shift => Shift::create([
        'tenant_id' => $tenant, 'guard_id' => $guardId, 'post_id' => $postId,
        'rostered_start' => Carbon::parse($from, 'UTC'), 'rostered_end' => Carbon::parse($from, 'UTC')->addHours(12),
        'actual_start' => Carbon::parse($from, 'UTC'), 'actual_end' => $to === null ? null : Carbon::parse($to, 'UTC'),
        'status' => $to === null ? 'on_duty' : 'completed',
    ]));
}

/** One reading: taken at `$at`, arriving at `$arrived` (as taken when null). */
function trackReading(Shift $shift, string $at, string $source, ?string $arrived = null, ?int $guardId = null): void
{
    DB::connection('mysql')->table('guard_positions')->insert([
        'guard_id' => $guardId ?? $shift->guard_id, 'shift_id' => $shift->id, 'tenant_id' => (string) $shift->tenant_id,
        'client_id' => (string) Str::uuid(), 'latitude' => 18.0181234, 'longitude' => -76.8102345, 'accuracy_m' => 8,
        'source' => $source, 'battery_pct' => 71, 'captured_at' => Carbon::parse($at, 'UTC'), 'device_captured_at' => Carbon::parse($at, 'UTC'),
        'received_at' => Carbon::parse($arrived ?? $at, 'UTC'), 'is_simulated' => false,
    ]);
}

it('shows every answer the officer gave, the button they pressed in its own words, when, and which one stands', function () {
    trackAnswer($this->guard->id, '2026-10-03', GuardTracking::GRANTED, '2026-09-26 12:00:00');
    trackAnswer($this->guard->id, '2026-10-10', GuardTracking::REFUSED, '2026-10-11 13:00:00');
    trackAnswer($this->guard->id, '2026-10-10', GuardTracking::GRANTED, '2026-10-12 07:04:00');

    $props = HeadOfSecurityScope::props($this->actingAs($this->director)->get('/guards/'.$this->guard->id.'/location-sharing'));

    // The guard's estate is on Kingston's clock: 07:04 UTC is 2:04 AM there.
    expect($props['standing']['state'])->toBe('agreed')
        ->and($props['standing']['line'])->toContain('agreed to the words of 10 Oct 2026 on 12 Oct 2026, 2:04 AM')
        ->and($props['canSeeTrack'])->toBeTrue()
        ->and(array_column($props['answers'], 'answer'))->toBe(['Agreed', 'Refused', 'Agreed'])
        ->and($props['answers'][0])->toMatchArray(['stands' => true, 'pressed' => 'I agree: share my location on duty', 'version_label' => '10 Oct 2026', 'note' => 'The answer in force now.'])
        ->and($props['answers'][1])->toMatchArray(['stands' => false, 'pressed' => 'No: do not share my location', 'note' => 'Changed by the later answer above.'])
        // The older words, as the officer read them then, dashes and all: a record, not a rewrite.
        ->and($props['answers'][2])->toMatchArray(['version' => '2026-10-03', 'pressed' => 'I agree — share my location on duty', 'note' => 'Asked again when the words changed.'])
        ->and(array_keys($props['words']))->toBe(['2026-10-10', '2026-10-03'])
        ->and(collect($props['words']['2026-10-10']['sections'])->firstWhere('heading', 'How long it is kept')['body'])->toBe('Positions are deleted after 90 days.');
});

it('says an officer who has not answered the words in force has nothing recorded, and one who refused is "not shared"', function () {
    $url = '/guards/'.$this->guard->id.'/location-sharing';

    expect(HeadOfSecurityScope::props($this->actingAs($this->director)->get($url))['standing']['state'])->toBe('unanswered');

    trackAnswer($this->guard->id, '2026-10-03', GuardTracking::GRANTED, '2026-09-26 12:00:00');
    $older = HeadOfSecurityScope::props($this->actingAs($this->director)->get($url))['standing'];
    expect($older['state'])->toBe('unanswered')
        ->and($older['line'])->toContain('has not answered the words of 10 Oct 2026');

    trackAnswer($this->guard->id, '2026-10-10', GuardTracking::REFUSED, '2026-10-12 07:04:00');
    $refused = HeadOfSecurityScope::props($this->actingAs($this->director)->get($url))['standing'];
    expect($refused['state'])->toBe('refused')
        ->and($refused['line'])->toContain('position not shared');
});

it('lists the shifts with positions held, newest first, and one shift\'s readings in the fields of the officer\'s own record', function () {
    $earlier = trackShift($this->guard->id, $this->here->id, FacilitiesFixture::ESTATE, '2026-10-11 13:00:00', '2026-10-11 23:00:00');
    trackReading($earlier, '2026-10-11 13:00:30', 'clock_in');
    $now = trackShift($this->guard->id, $this->here->id, FacilitiesFixture::ESTATE, '2026-10-12 13:00:00', null);
    trackReading($now, '2026-10-12 13:00:20', 'clock_in');
    // Taken at 13:30 out of signal, sent at 14:00: recorded on time, arrived 30 minutes later.
    trackReading($now, '2026-10-12 13:30:00', 'interval', arrived: '2026-10-12 14:00:00');
    trackReading($now, '2026-10-12 13:45:00', 'gate');
    // Another officer's reading on the same shift is never this officer's record.
    $other = HeadOfSecurityScope::officer(FacilitiesFixture::ESTATE, 'Other Officer '.Str::random(5));
    trackReading($now, '2026-10-12 13:50:00', 'interval', guardId: $other->id);

    $props = HeadOfSecurityScope::props($this->actingAs($this->director)->get('/guards/'.$this->guard->id.'/track'));

    expect(array_column($props['shifts'], 'id'))->toBe([$now->id, $earlier->id])
        ->and($props['shifts'][0]['count'])->toBe(3)
        ->and($props['shift']['id'])->toBe($now->id)
        ->and($props['shift']['clocked_out_at'])->toBeNull()
        ->and($props['table']['total'])->toBe(3)
        ->and(array_column($props['rows'], 'source'))->toBe(['clock_in', 'interval', 'gate'])
        ->and($props['rows'][0])->toMatchArray(['lat' => 18.0181234, 'lng' => -76.8102345, 'accuracy_metres' => 8, 'why' => 'Clock-in', 'battery_percent' => 71])
        ->and($props['rows'][1]['arrived'])->toBe('Arrived 30 min later')
        ->and($props['rows'][2]['arrived'])->toBeNull()
        ->and($props['pins'])->toHaveCount(3)
        ->and($props['retention_days'])->toBe(90);

    // Another shift, asked for by the address.
    $older = HeadOfSecurityScope::props($this->actingAs($this->director)->get('/guards/'.$this->guard->id.'/track?shift='.$earlier->id));
    expect($older['shift']['id'])->toBe($earlier->id)->and($older['table']['total'])->toBe(1);
});

it('opens the answers to every role that reads the guard page, and the places only to the live map\'s roles', function () {
    $shift = trackShift($this->guard->id, $this->here->id, FacilitiesFixture::ESTATE, '2026-10-12 13:00:00', null);
    trackReading($shift, '2026-10-12 13:00:20', 'clock_in');

    // The Accountant reads the guard workforce and does not hold the live map.
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);
    expect($accountant->can('gemini.guard_workforce.view'))->toBeTrue()
        ->and($accountant->can('gemini.dispatch.view'))->toBeFalse();

    $consent = HeadOfSecurityScope::props($this->actingAs($accountant)->get('/guards/'.$this->guard->id.'/location-sharing'));
    expect($consent['canSeeTrack'])->toBeFalse();
    $this->actingAs($accountant)->get('/guards/'.$this->guard->id.'/track')->assertForbidden();
    expect(HeadOfSecurityScope::props($this->actingAs($accountant)->get('/guards/'.$this->guard->id))['canSeeTrack'])->toBeFalse();

    // The Dispatcher holds both.
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $this->actingAs($dispatcher)->get('/guards/'.$this->guard->id.'/track')->assertOk();
    expect(HeadOfSecurityScope::props($this->actingAs($dispatcher)->get('/guards/'.$this->guard->id))['canSeeTrack'])->toBeTrue();
});

it('shows a site-scoped role only the shifts at its own estates, and nothing of a guard posted elsewhere', function () {
    $mine = trackShift($this->guard->id, $this->here->id, FacilitiesFixture::ESTATE, '2026-10-11 13:00:00', '2026-10-11 23:00:00');
    trackReading($mine, '2026-10-11 13:00:30', 'clock_in');
    $theirs = trackShift($this->guard->id, $this->there->id, RingFixture::OTHER_ESTATE, '2026-10-12 13:00:00', null);
    trackReading($theirs, '2026-10-12 13:00:20', 'clock_in');

    $head = HeadOfSecurityScope::assigned(FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY), Role::HEAD_OF_SECURITY, FacilitiesFixture::ESTATE);
    expect($head->canAccessEstate(RingFixture::OTHER_ESTATE))->toBeFalse();

    // Asking for the other estate's shift by the address shows the newest shift it may see instead.
    $props = HeadOfSecurityScope::props($this->actingAs($head)->get('/guards/'.$this->guard->id.'/track?shift='.$theirs->id));
    expect(array_column($props['shifts'], 'id'))->toBe([$mine->id])
        ->and($props['shift']['id'])->toBe($mine->id);

    // A guard posted at an estate the role cannot see is not found, on both screens.
    $elsewhere = HeadOfSecurityScope::officer(RingFixture::OTHER_ESTATE, 'Elsewhere Officer '.Str::random(5));
    $this->actingAs($head)->get('/guards/'.$elsewhere->id.'/location-sharing')->assertNotFound();
    $this->actingAs($head)->get('/guards/'.$elsewhere->id.'/track')->assertNotFound();
});
