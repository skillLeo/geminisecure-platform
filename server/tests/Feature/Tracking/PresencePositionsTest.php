<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Tracking\GuardPins;
use App\Services\Tracking\GuardTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| POST presence/positions: the guard's batch, answered reading by reading (Hassam, 12 Oct 2026; GUARD_TRACKING.md 5.1)
|--------------------------------------------------------------------------
|
|   - a duplicate key is stored ONCE, however often it is sent; a replay never double-logs
|   - a position with no open shift is REJECTED and not stored: a privacy rule, not a validation rule
|   - a phone clock far from the server's is stored and FLAGGED for a supervisor, never refused, never corrected
|   - a reading taken before the clock-in is rejected
|   - the guard can read back what is recorded about them
|
*/

function ppEstate(): string
{
    DB::table('tenants')->updateOrInsert(['id' => 'ppestate'], ['name' => 'Presence Estate', 'data' => json_encode(['name' => 'Presence Estate']), 'created_at' => now(), 'updated_at' => now()]);

    return 'ppestate';
}

/** @return array{guard: Guard, token: string, shift: Shift} */
function ppGuard(bool $clockedIn = true, bool $clockedOut = false): array
{
    static $n = 0;
    $n++;

    $estate = ppEstate();
    $guard = Guard::create([
        'full_name' => 'Presence Guard '.$n, 'employee_number' => 'GS-PP-'.$n, 'psra_number' => 'PSRA-PP-'.$n,
        'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
    ]);
    $post = Post::create(['tenant_id' => $estate, 'name' => 'Gate '.$n, 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0179, 'longitude' => -76.8099, 'geofence_radius_m' => 60]);
    $shift = Shift::create([
        'tenant_id' => $estate, 'guard_id' => $guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subHours(2), 'rostered_end' => now()->addHours(10),
        'actual_start' => $clockedIn ? now()->subHour() : null,
        'actual_end' => $clockedOut ? now()->subMinutes(5) : null,
        'status' => $clockedOut ? 'completed' : ($clockedIn ? 'on_duty' : 'rostered'),
    ]);
    $token = $guard->createToken('Presence handset', AppMatrix::abilitiesFor(AppMatrix::GUARD))->plainTextToken;

    DB::table('guard_tracking_consents')->insert(['guard_id' => $guard->id, 'policy_version' => GuardTracking::policyVersion(), 'decision' => 'granted', 'decided_at' => now()]);

    return ['guard' => $guard, 'token' => $token, 'shift' => $shift];
}

function ppAs(array $h): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($h['token']);
}

/** @return array<string, string> */
function ppHeaders(?string $deviceNow = null): array
{
    return ['Idempotency-Key' => 'pp-'.bin2hex(random_bytes(8)), 'X-Device-Time' => $deviceNow ?? now()->toIso8601String()];
}

/** @return array<string, mixed> */
function ppReading(string $capturedAt, ?string $key = null): array
{
    return ['idempotency_key' => $key ?? (string) Str::uuid(), 'captured_at' => $capturedAt, 'lat' => 18.0181, 'lng' => -76.8102, 'accuracy_metres' => 9, 'battery_percent' => 64, 'is_moving' => true, 'source' => 'background'];
}

it('stores a duplicate key once, however often it is sent, and says which was a duplicate', function () {
    $h = ppGuard();
    $a = ppReading(now()->subMinutes(3)->toIso8601String());
    $b = ppReading(now()->subMinutes(2)->toIso8601String());

    $first = ppAs($h)->postJson('/api/v1/presence/positions', ['positions' => [$a, $a, $b]], ppHeaders())->assertOk();

    expect($first->json('shift_id'))->toBe($h['shift']->id)
        ->and(collect($first->json('results'))->pluck('result')->all())->toBe(['accepted', 'duplicate', 'accepted']);

    // The phone did not hear the answer and sends the whole batch again: nothing is logged twice.
    $replay = ppAs($h)->postJson('/api/v1/presence/positions', ['positions' => [$a, $b]], ppHeaders())->assertOk();

    expect(collect($replay->json('results'))->pluck('result')->all())->toBe(['duplicate', 'duplicate'])
        ->and(DB::table('guard_positions')->where('client_id', $a['idempotency_key'])->count())->toBe(1)
        ->and(DB::table('guard_positions')->where('shift_id', $h['shift']->id)->count())->toBe(2);
});

it('rejects every position when the guard has no open shift, and stores none of them', function () {
    $offDuty = ppGuard(clockedIn: false);
    $clockedOut = ppGuard(clockedIn: true, clockedOut: true);

    foreach ([$offDuty, $clockedOut] as $h) {
        $answer = ppAs($h)->postJson('/api/v1/presence/positions', ['positions' => [ppReading(now()->subMinute()->toIso8601String()), ppReading(now()->toIso8601String())]], ppHeaders())->assertOk();

        expect($answer->json('shift_id'))->toBeNull()
            ->and(collect($answer->json('results'))->pluck('result')->unique()->all())->toBe(['rejected'])
            ->and(collect($answer->json('results'))->pluck('reason')->unique()->all())->toBe(['no_open_shift'])
            ->and(DB::table('guard_positions')->where('guard_id', $h['guard']->id)->count())->toBe(0);
    }
});

it('stores a reading from a phone whose clock is wrong, flagged for a supervisor and never corrected', function () {
    $h = ppGuard();

    // The phone's clock is ten minutes fast: it says 12:10 when the server says 12:00.
    $deviceNow = now()->addMinutes(10)->startOfSecond();
    $reading = ppReading($deviceNow->copy()->subSeconds(30)->toIso8601String());

    $answer = ppAs($h)->postJson('/api/v1/presence/positions', ['positions' => [$reading]], ppHeaders($deviceNow->toIso8601String()))->assertOk();

    expect($answer->json('results.0.result'))->toBe('accepted')
        ->and($answer->json('results.0.flag'))->toBe('clock_ahead');

    $row = DB::table('guard_positions')->where('client_id', $reading['idempotency_key'])->first();

    expect($row)->not->toBeNull()
        ->and($row->clock_flag)->toBe('clock_ahead')
        ->and((int) $row->clock_offset_s)->toBeLessThanOrEqual(-595)
        // As the phone sent it, not moved by the difference.
        ->and((string) $row->captured_at)->toBe($deviceNow->copy()->subSeconds(30)->utc()->format('Y-m-d H:i:s'));
});

it('rejects a reading taken before the clock-in, and keeps the rest of the batch', function () {
    $h = ppGuard();
    $before = ppReading(now()->subHours(3)->toIso8601String());
    $during = ppReading(now()->subMinutes(5)->toIso8601String());

    $answer = ppAs($h)->postJson('/api/v1/presence/positions', ['positions' => [$before, $during]], ppHeaders())->assertOk();

    expect($answer->json('results.0.result'))->toBe('rejected')
        ->and($answer->json('results.0.reason'))->toBe('before_shift')
        ->and($answer->json('results.1.result'))->toBe('accepted')
        ->and(DB::table('guard_positions')->where('client_id', $before['idempotency_key'])->exists())->toBeFalse();
});

it('keeps what the phone says it holds unsent, for the dispatcher, and warns past the line', function () {
    $h = ppGuard();

    ppAs($h)->postJson('/api/v1/presence/positions', [
        'positions' => [ppReading(now()->subMinute()->toIso8601String())],
        'backlog' => ['waiting' => 340, 'oldest_captured_at' => now()->subMinutes(40)->toIso8601String()],
        'thinned' => ['count' => 12, 'from' => now()->subHours(2)->toIso8601String(), 'to' => now()->subHour()->toIso8601String()],
    ], ppHeaders())->assertOk();

    $row = DB::table('guard_queue_reports')->where('shift_id', $h['shift']->id)->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->waiting)->toBe(340)
        ->and((int) $row->thinned_count)->toBe(12);

    $officer = GuardPins::for(GuardPins::withBreak(Shift::query())->with(['officer', 'post'])->whereKey($h['shift']->id)->get())['officers'][0];

    expect($officer['queue']['waiting'])->toBe(340)
        ->and($officer['queue']['thinned'])->toBe(12)
        ->and($officer['queue']['warning'])->toBeTrue();
});

it('lets the guard read back every reading recorded about them today', function () {
    $h = ppGuard();
    $reading = ppReading(now()->subMinutes(4)->toIso8601String());
    ppAs($h)->postJson('/api/v1/presence/positions', ['positions' => [$reading]], ppHeaders())->assertOk();

    ppAs($h)->getJson('/api/v1/presence/positions')->assertOk()
        ->assertJsonPath('shift.id', $h['shift']->id)
        ->assertJsonPath('retention_days', 90)
        ->assertJsonCount(1, 'positions')
        ->assertJsonPath('positions.0.lat', 18.0181)
        ->assertJsonPath('positions.0.source', 'background');
});
