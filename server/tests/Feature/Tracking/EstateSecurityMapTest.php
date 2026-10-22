<?php

declare(strict_types=1);

use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Tracking\GuardTracking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The estate's Security map, one feed: GET security/map (Hassam, 12 Oct 2026; GUARD_TRACKING.md 5.4)
|--------------------------------------------------------------------------
|
| The estate is a different company from the security company and sees a guard's working presence, not their
| employment, enforced in what the server sends:
|   - an estate user's map response carries none of the forbidden fields, as keys or as values
|   - ONLY guards clocked in at this estate and not out: never one off shift, at another site, or from a forgotten shift
|   - a reading over a minute old is Last seen, never Live; nothing arrived past the tolerance is No signal
|   - while the estate may not see positions, no position, freshness or battery is sent at all
|   - while it may, never the battery or what a reading was taken for: the consent words (2026-10-12) tell an officer the
|     estate's management sees where they are, and nothing more
|   - two states only, On duty or Responding, and never when a shift began (Hassam, 12 Oct)
|   - the nearest guards to an open panic by where they ARE, never by their post
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    // The estate may see positions: the platform's hold lifted and the contract's switch on (TRACK-01).
    config(['tracking.estate_positions_held' => false]);
    $plan = DB::connection('mysql')->table('plans')->insertGetId(['key' => 'esm-plan-'.Str::random(6), 'name' => 'Map plan', 'price_per_unit_minor' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::connection('mysql')->table('subscriptions')->updateOrInsert(['tenant_id' => FacilitiesFixture::ESTATE], ['plan_id' => $plan, 'unit_count' => 10, 'status' => 'active', 'estate_sees_guard_positions' => true, 'created_at' => now(), 'updated_at' => now()]);
    config(['tracking.no_signal_after_seconds' => 300]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/**
 * A guard and a shift. `in`: clocked in that long ago (null: never); `out`: clocked out that long ago (null: still on).
 *
 * @param  array<string, mixed>  $private  employment fields the estate must never receive
 */
function esmOnShift(string $name, ?int $inMinutes = 60, ?int $outMinutes = null, string $estate = FacilitiesFixture::ESTATE, array $private = [], ?array $post = null): Shift
{
    // Central rows through the central connection: the fixture has the estate's own database open as the default.
    // Only a missing estate is made: the fixture's own row carries its tenancy settings and is never written over.
    DB::connection('mysql')->table('tenants')->insertOrIgnore(['id' => $estate, 'name' => 'Estate '.$estate, 'data' => json_encode(['name' => 'Estate '.$estate]), 'created_at' => now(), 'updated_at' => now()]);

    $guard = new Guard;
    $guard->forceFill([
        'full_name' => $name, 'employee_number' => 'GS-ESM-'.Str::random(6), 'psra_number' => 'PSRA-ESM-'.Str::random(6),
        'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
        ...$private,
    ])->save();

    $place = Post::create(['tenant_id' => $estate, 'name' => 'Post of '.$name, 'type' => 'gate', 'is_active' => true, 'latitude' => $post[0] ?? 18.0179, 'longitude' => $post[1] ?? -76.8099, 'geofence_radius_m' => 40]);

    $shift = Shift::create([
        'tenant_id' => $estate, 'guard_id' => $guard->id, 'post_id' => $place->id,
        'rostered_start' => now()->subMinutes(($inMinutes ?? 30) + 30), 'rostered_end' => now()->addHours(8),
        'actual_start' => $inMinutes === null ? null : now()->subMinutes($inMinutes),
        'actual_end' => $outMinutes === null ? null : now()->subMinutes($outMinutes),
        'status' => $outMinutes !== null ? 'completed' : ($inMinutes === null ? 'rostered' : 'on_duty'),
    ]);

    DB::connection('mysql')->table('guard_tracking_consents')->insert(['guard_id' => $guard->id, 'policy_version' => GuardTracking::policyVersion(), 'decision' => 'granted', 'decided_at' => now()]);

    return $shift;
}

/** A reading on a shift, taken `takenSecondsAgo` and arrived `arrivedSecondsAgo` (by default when it was taken). */
function esmReading(Shift $shift, float $lat, float $lng, int $takenSecondsAgo, ?int $arrivedSecondsAgo = null): void
{
    DB::connection('mysql')->table('guard_positions')->insert([
        'guard_id' => $shift->guard_id, 'shift_id' => $shift->id, 'tenant_id' => $shift->tenant_id, 'client_id' => (string) Str::uuid(),
        'latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => 7, 'source' => 'background', 'battery_pct' => 61, 'is_moving' => false,
        'captured_at' => now()->subSeconds($takenSecondsAgo), 'device_captured_at' => now()->subSeconds($takenSecondsAgo),
        'received_at' => now()->subSeconds($arrivedSecondsAgo ?? $takenSecondsAgo),
    ]);
}

/** @param  array<string, mixed>  $attributes */
function esmPanic(array $attributes = []): DuressAlert
{
    return DuressAlert::withoutEvents(static function () use ($attributes): DuressAlert {
        $alert = new DuressAlert;
        $alert->forceFill([
            'tenant_id' => FacilitiesFixture::ESTATE, 'kind' => 'panic', 'raised_by_name' => 'Marcia Grant', 'unit_reference' => 'Lot 12',
            'status' => 'open', 'server_time' => now()->subMinutes(3), 'is_simulated' => false, 'latitude' => 18.0185, 'longitude' => -76.8105,
            ...$attributes,
        ])->save();

        return $alert;
    });
}

/** @return array<string, mixed> */
function esmMap(string $role = Role::PRESIDENT): array
{
    return test()->actingAs(FacilitiesFixture::viewer($role))->getJson(FacilitiesFixture::url('/security/map'))->assertOk()->json();
}

/**
 * Every key at every depth of an answer.
 *
 * @param  array<mixed>  $value
 * @return list<string>
 */
function esmKeys(array $value): array
{
    $keys = [];

    foreach ($value as $key => $inner) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($inner)) {
            array_push($keys, ...esmKeys($inner));
        }
    }

    return $keys;
}

it('sends an estate user none of the fields the estate must not see, as keys or as values', function () {
    $shift = esmOnShift('Andre Brown', private: [
        'employee_number' => 'GS-SECRET-501', 'psra_number' => 'PSRA-SECRET-501', 'phone' => '876-555-0101',
        'email' => 'andre.private@example.test', 'standard_rate_minor' => 987654, 'hired_on' => '2019-04-01',
    ]);
    esmReading($shift, 18.0187, -76.8101, 20);
    esmPanic(['responding_guard_id' => $shift->guard_id, 'dispatched_at' => now()->subMinute(), 'acknowledged_at' => now()->subMinutes(2)]);

    $map = esmMap();
    $json = json_encode($map);

    // Seen: the guard by name, the panic by its lot, who is going.
    expect($map['positions'])->toBeTrue()
        ->and(collect($map['guards'])->pluck('name')->all())->toBe(['Andre Brown'])
        ->and($map['alerts'][0]['lot'])->toBe('Lot 12')
        ->and($map['alerts'][0]['responder']['name'])->toBe('Andre Brown')
        ->and($map['alerts'][0]['responder']['state'])->toBe('Dispatched');

    $forbiddenKeys = ['guard_id', 'employee_number', 'psra_number', 'psra_expires_on', 'phone', 'email', 'standard_rate_minor', 'standard_rate_currency', 'pension_minor', 'approved_pension_minor', 'hired_on', 'user_id', 'employment_type', 'trn', 'nis', 'nis_number', 'bank', 'bank_account', 'address', 'home_address', 'pay_rate', 'payslips', 'certifications', 'discipline', 'performance', 'drowsiness', 'vitality', 'activity_score', 'device_uid', 'mock_location_flag', 'clock_flag', 'tenant_id', 'href', 'battery_pct', 'battery_percent', 'source', 'source_label', 'clocked_in_at', 'since'];
    $sent = array_values(array_unique(esmKeys($map)));

    foreach ($forbiddenKeys as $key) {
        expect(in_array($key, $sent, true))->toBeFalse();
    }

    foreach (['GS-SECRET-501', 'PSRA-SECRET-501', '876-555-0101', 'andre.private@example.test', '987654', '2019-04-01'] as $value) {
        expect(str_contains((string) $json, $value))->toBeFalse();
    }
});

it('shows only the guards clocked in at this estate and not out: never off shift, elsewhere or a forgotten shift', function () {
    esmReading(esmOnShift('On Duty Here', inMinutes: 60), 18.0181, -76.8102, 30);
    esmReading(esmOnShift('Clocked Out Here', inMinutes: 300, outMinutes: 60), 18.0181, -76.8102, 4000);
    esmOnShift('Rostered Not In', inMinutes: null);
    esmReading(esmOnShift('Elsewhere On Duty', inMinutes: 60, estate: 'esmother'), 18.0181, -76.8102, 30);
    esmReading(esmOnShift('Forgotten Shift', inMinutes: 30 * 60), 18.0181, -76.8102, 30);

    $map = esmMap();

    expect(collect($map['guards'])->pluck('name')->all())->toBe(['On Duty Here']);
});

it('draws a reading over a minute old as Last seen, never Live, and a phone silent past the tolerance as No signal', function () {
    esmReading(esmOnShift('Live Guard'), 18.0181, -76.8102, 20, 19);
    esmReading(esmOnShift('Ninety Guard'), 18.0181, -76.8102, 90, 88);
    esmReading(esmOnShift('Quiet Guard'), 18.0181, -76.8102, 8 * 60);
    // A queue flushed after forty minutes with no signal: it arrived just now, and it is forty minutes old.
    esmReading(esmOnShift('Late Guard'), 18.0181, -76.8102, 40 * 60, 10);

    $fresh = collect(esmMap()['guards'])->pluck('freshness', 'name');
    $labels = collect(esmMap()['guards'])->pluck('freshness_label', 'name');

    expect($fresh['Live Guard'])->toBe('live')
        ->and($fresh['Ninety Guard'])->toBe('last_seen')
        ->and(str_starts_with((string) $labels['Ninety Guard'], 'Last seen 1 min ago · '))->toBeTrue()
        ->and($fresh['Quiet Guard'])->toBe('no_signal')
        ->and(str_starts_with((string) $labels['Quiet Guard'], 'No signal · last seen '))->toBeTrue()
        ->and($fresh['Late Guard'])->toBe('last_seen')
        ->and(str_starts_with((string) $labels['Late Guard'], 'Last seen 40 min ago · '))->toBeTrue();
});

it('sends no position, freshness or battery at all while the estate may not see positions', function () {
    esmReading(esmOnShift('Held Guard'), 18.0181, -76.8102, 20);
    config(['tracking.estate_positions_held' => true]);

    $map = esmMap();
    $guard = $map['guards'][0];

    expect($map['positions'])->toBeFalse()
        ->and($guard['name'])->toBe('Held Guard')
        ->and($guard['state_label'])->toBe('On duty');

    foreach (['lat', 'lng', 'accuracy_m', 'reading_at', 'arrived_at', 'freshness', 'battery_pct', 'distances'] as $key) {
        expect(array_key_exists($key, $guard))->toBeFalse();
    }
});

it('keeps where a panic was pressed beside where the phone is now', function () {
    $alert = esmPanic();
    DB::connection('mysql')->table('duress_alert_positions')->insert([
        'duress_alert_id' => $alert->id, 'client_id' => (string) Str::uuid(), 'latitude' => 18.0199, 'longitude' => -76.8120,
        'accuracy_m' => 5, 'captured_at' => now()->subSeconds(10), 'received_at' => now()->subSeconds(9),
    ]);

    $entry = esmMap()['alerts'][0];

    expect($entry['lat'])->toBe(18.0199)
        ->and($entry['position_moved'])->toBeTrue()
        ->and($entry['raised_lat'])->toBe(18.0185)
        ->and($entry['raised_lng'])->toBe(-76.8105)
        ->and($entry['raised_off_estate'])->toBeNull();
});

it('names the guards nearest an open panic by where they are, never by their post, as a suggestion of three', function () {
    // Their posts say the opposite of where they stand.
    esmReading(esmOnShift('Near By Position', post: [18.0400, -76.8400]), 18.0189, -76.8105, 20);
    esmReading(esmOnShift('Far By Position', post: [18.0185, -76.8105]), 18.0360, -76.8105, 20);
    esmReading(esmOnShift('Middle Guard'), 18.0220, -76.8105, 20);
    esmReading(esmOnShift('Fourth Guard'), 18.0500, -76.8105, 20);
    esmPanic();

    $nearest = esmMap()['alerts'][0]['nearest'];

    expect(collect($nearest)->pluck('name')->all())->toBe(['Near By Position', 'Middle Guard', 'Far By Position'])
        ->and($nearest[0]['distance_m'])->toBeLessThan(100)
        ->and($nearest[2]['distance_m'])->toBeGreaterThan(1500);
});

it('tells the estate two states, On duty or Responding, and never when a shift began', function () {
    // Hassam, 12 Oct: "A guard taking a break is still on duty"; at post and on patrol fold into On duty.
    esmReading(esmOnShift('Post Guard'), 18.0181, -76.8102, 20);

    $onBreak = esmOnShift('Break Guard');
    esmReading($onBreak, 18.0181, -76.8102, 20);
    DB::connection('mysql')->table('shift_breaks')->insert(['shift_id' => $onBreak->id, 'started_at' => now()->subMinutes(5), 'ended_at' => null, 'created_at' => now(), 'updated_at' => now()]);

    $patrol = esmOnShift('Patrol Guard');
    $patrol->forceFill(['duty' => Shift::DUTY_PATROL])->save();
    esmReading($patrol, 18.0181, -76.8102, 20);

    $responder = esmOnShift('Responding Guard');
    esmReading($responder, 18.0186, -76.8104, 20);
    esmPanic(['responding_guard_id' => $responder->guard_id, 'dispatched_at' => now()->subMinute()]);

    // Clocked in twenty minutes ago and silent since: past the five-minute tolerance with no reading at all.
    esmOnShift('Quiet Guard', inMinutes: 20);

    $map = esmMap();
    $states = collect($map['guards'])->mapWithKeys(static fn (array $g): array => [$g['name'] => [$g['state'], $g['state_label']]]);

    expect($states['Post Guard'])->toBe(['on_duty', 'On duty'])
        ->and($states['Break Guard'])->toBe(['on_duty', 'On duty'])
        ->and($states['Patrol Guard'])->toBe(['on_duty', 'On duty'])
        ->and($states['Responding Guard'])->toBe(['responding', 'Responding'])
        ->and(collect($map['guards'])->firstWhere('name', 'Quiet Guard')['freshness_label'])->toBe('No signal since the shift began')
        ->and(in_array('clocked_in_at', esmKeys($map), true))->toBeFalse();

    // The page's own clock words it the same way when the feed carries no clock-in time (guardFreshness.js).
    $js = (string) file_get_contents(resource_path('js/lib/guardFreshness.js'));
    expect(str_contains($js, "label: 'No signal since the shift began'"))->toBeTrue();
});
