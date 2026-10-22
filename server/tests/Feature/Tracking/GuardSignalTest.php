<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Tracking\GuardSignal;
use App\Services\Tracking\GuardTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| "No signal from <name> at <site>, last seen HH:MM" (Hassam, 12 Oct 2026; GUARD_TRACKING.md 5.6), and a panic followed
| while it is open (5.3)
|--------------------------------------------------------------------------
|
|   - a guard on duty, sharing, with nothing arrived for longer than the estate's tolerance raises one alert
|   - it clears itself when a position arrives, and when the shift ends
|   - a guard who refused the wording is never alerted on: nothing is expected from their phone
|   - the phone that raised a panic sends where it is while it is open; closed, it is refused; a reading far from the
|     estate is kept, never refused
|
*/

function sgEstate(): string
{
    DB::table('tenants')->updateOrInsert(['id' => 'sgestate'], ['name' => 'Signal Estate', 'data' => json_encode(['name' => 'Signal Estate']), 'created_at' => now(), 'updated_at' => now()]);

    return 'sgestate';
}

/** @return array{guard: Guard, token: string, shift: Shift} */
function sgOnDuty(int $inMinutes = 30, string $decision = 'granted'): array
{
    static $n = 0;
    $n++;

    $estate = sgEstate();
    $guard = Guard::create([
        'full_name' => 'Signal Guard '.$n, 'employee_number' => 'GS-SG-'.$n, 'psra_number' => 'PSRA-SG-'.$n,
        'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
    ]);
    $post = Post::create(['tenant_id' => $estate, 'name' => 'Gate '.$n, 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0179, 'longitude' => -76.8099, 'geofence_radius_m' => 60]);
    $shift = Shift::create([
        'tenant_id' => $estate, 'guard_id' => $guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subMinutes($inMinutes + 10), 'rostered_end' => now()->addHours(8),
        'actual_start' => now()->subMinutes($inMinutes), 'status' => 'on_duty',
    ]);
    DB::table('guard_tracking_consents')->insert(['guard_id' => $guard->id, 'policy_version' => GuardTracking::policyVersion(), 'decision' => $decision, 'decided_at' => now()]);
    $token = $guard->createToken('Signal handset', AppMatrix::abilitiesFor(AppMatrix::GUARD))->plainTextToken;

    return ['guard' => $guard, 'token' => $token, 'shift' => $shift];
}

function sgAs(array $h): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($h['token']);
}

/** @return array<string, string> */
function sgHeaders(): array
{
    return ['Idempotency-Key' => 'sg-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()];
}

function sgSend(array $h, int $takenSecondsAgo = 5): void
{
    sgAs($h)->postJson('/api/v1/presence/positions', ['positions' => [[
        'idempotency_key' => (string) Str::uuid(), 'captured_at' => now()->subSeconds($takenSecondsAgo)->toIso8601String(),
        'lat' => 18.0181, 'lng' => -76.8102, 'accuracy_metres' => 8, 'battery_percent' => 55, 'is_moving' => false, 'source' => 'background',
    ]]], sgHeaders())->assertOk()->assertJsonPath('results.0.result', 'accepted');
}

it('raises one No signal alert for a guard whose phone has sent nothing past the tolerance, and says who, where and since when', function () {
    $quiet = sgOnDuty(inMinutes: 30);
    $talking = sgOnDuty(inMinutes: 30);
    sgSend($talking);

    Artisan::call('tracking:signal');
    Artisan::call('tracking:signal');

    $open = app(GuardSignal::class)->open();

    expect($open)->toHaveCount(1)
        ->and($open[0]['shift_id'])->toBe($quiet['shift']->id)
        ->and(str_starts_with($open[0]['sentence'], 'No signal from Signal Guard '))->toBeTrue()
        ->and(str_contains($open[0]['sentence'], ' at Signal Estate, nothing since clock-in at '))->toBeTrue();
});

it('clears itself when a position arrives, and when the shift ends', function () {
    $h = sgOnDuty(inMinutes: 30);
    Artisan::call('tracking:signal');
    expect(app(GuardSignal::class)->open())->toHaveCount(1);

    sgSend($h);

    expect(app(GuardSignal::class)->open())->toBe([])
        ->and(DB::table('guard_signal_alerts')->where('shift_id', $h['shift']->id)->value('cleared_reason'))->toBe('position_arrived');

    $ended = sgOnDuty(inMinutes: 30);
    Artisan::call('tracking:signal');
    expect(collect(app(GuardSignal::class)->open())->pluck('shift_id')->all())->toBe([$ended['shift']->id]);

    $ended['shift']->forceFill(['actual_end' => now(), 'status' => 'completed'])->save();
    Artisan::call('tracking:signal');

    expect(app(GuardSignal::class)->open())->toBe([])
        ->and(DB::table('guard_signal_alerts')->where('shift_id', $ended['shift']->id)->value('cleared_reason'))->toBe('shift_ended');
});

it('never alerts on a guard who refused the wording, nor on one still within the tolerance of the clock-in', function () {
    sgOnDuty(inMinutes: 30, decision: 'refused');
    sgOnDuty(inMinutes: 2);

    Artisan::call('tracking:signal');

    expect(app(GuardSignal::class)->open())->toBe([]);
});

it('follows a panic while it is open, keeps a reading far from the estate, and refuses once it is closed', function () {
    $h = sgOnDuty();
    $alert = DuressAlert::withoutEvents(static function () use ($h): DuressAlert {
        $row = new DuressAlert;
        $row->forceFill(['tenant_id' => 'sgestate', 'kind' => 'duress', 'guard_id' => $h['guard']->id, 'raised_by_name' => 'Signal Guard', 'status' => 'open', 'server_time' => now()->subMinute(), 'is_simulated' => false, 'latitude' => 18.0181, 'longitude' => -76.8102])->save();

        return $row;
    });

    $near = ['idempotency_key' => (string) Str::uuid(), 'captured_at' => now()->subSeconds(10)->toIso8601String(), 'lat' => 18.0190, 'lng' => -76.8110, 'accuracy_metres' => 6];
    $far = ['idempotency_key' => (string) Str::uuid(), 'captured_at' => now()->subSeconds(5)->toIso8601String(), 'lat' => 18.2000, 'lng' => -76.5000, 'accuracy_metres' => 6];

    $answer = sgAs($h)->postJson("/api/v1/duress/{$alert->id}/positions", ['positions' => [$near, $near, $far]], sgHeaders())->assertOk();

    expect(collect($answer->json('results'))->pluck('result')->all())->toBe(['accepted', 'duplicate', 'accepted'])
        ->and(DB::table('duress_alert_positions')->where('duress_alert_id', $alert->id)->count())->toBe(2);

    DuressAlert::withoutEvents(static fn () => $alert->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save());

    $closed = sgAs($h)->postJson("/api/v1/duress/{$alert->id}/positions", ['positions' => [['idempotency_key' => (string) Str::uuid(), 'captured_at' => now()->toIso8601String(), 'lat' => 18.0190, 'lng' => -76.8110]]], sgHeaders())->assertOk();

    expect($closed->json('results.0.reason'))->toBe('alert_closed')
        ->and(DB::table('duress_alert_positions')->where('duress_alert_id', $alert->id)->count())->toBe(2);
});
