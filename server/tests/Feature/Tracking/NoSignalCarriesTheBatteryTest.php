<?php

declare(strict_types=1);

use App\Api\AppMatrix;
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
| "No signal from Marlon Grant at Phoenix Park, last seen 2:08 AM, battery 14%" (Hassam, 12 Oct 2026, through the hub):
| "That one figure is the difference between calling the guard and sending someone."
|--------------------------------------------------------------------------
|
|   - the dispatcher's sentence ends with the battery at the last reading, the reading "last seen" names
|   - no figure when the phone sent none, and none before the first reading arrives
|
| The battery is the employer's: an estate's screen never shows a guard's battery (his ruling on consent), and the No
| signal alert is read only by the Gemini dispatcher's queue.
|
*/

function nsbEstate(): string
{
    DB::table('tenants')->updateOrInsert(['id' => 'nsbestate'], ['name' => 'Battery Estate', 'data' => json_encode(['name' => 'Battery Estate']), 'created_at' => now(), 'updated_at' => now()]);

    return 'nsbestate';
}

/** @return array{guard: Guard, token: string, shift: Shift} */
function nsbOnDuty(int $inMinutes = 30): array
{
    static $n = 0;
    $n++;

    $estate = nsbEstate();
    $guard = Guard::create([
        'full_name' => 'Battery Guard '.$n, 'employee_number' => 'GS-NSB-'.$n, 'psra_number' => 'PSRA-NSB-'.$n,
        'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
    ]);
    $post = Post::create(['tenant_id' => $estate, 'name' => 'Gate '.$n, 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0179, 'longitude' => -76.8099, 'geofence_radius_m' => 60]);
    $shift = Shift::create([
        'tenant_id' => $estate, 'guard_id' => $guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subMinutes($inMinutes + 10), 'rostered_end' => now()->addHours(8),
        'actual_start' => now()->subMinutes($inMinutes), 'status' => 'on_duty',
    ]);
    DB::table('guard_tracking_consents')->insert(['guard_id' => $guard->id, 'policy_version' => GuardTracking::policyVersion(), 'decision' => 'granted', 'decided_at' => now()]);
    $token = $guard->createToken('Battery handset', AppMatrix::abilitiesFor(AppMatrix::GUARD))->plainTextToken;

    return ['guard' => $guard, 'token' => $token, 'shift' => $shift];
}

function nsbAs(array $h): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($h['token']);
}

/** A reading taken a few seconds ago, with the battery the phone reported, or none. */
function nsbSend(array $h, ?int $battery): void
{
    $fix = [
        'idempotency_key' => (string) Str::uuid(), 'captured_at' => now()->subSeconds(5)->toIso8601String(),
        'lat' => 18.0181, 'lng' => -76.8102, 'accuracy_metres' => 8, 'is_moving' => false, 'source' => 'background',
    ];

    if ($battery !== null) {
        $fix['battery_percent'] = $battery;
    }

    nsbAs($h)->postJson('/api/v1/presence/positions', ['positions' => [$fix]], ['Idempotency-Key' => 'nsb-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()])
        ->assertOk()->assertJsonPath('results.0.result', 'accepted');
}

/** The sentence of this shift's open No signal alert, or null when none is open. */
function nsbSentence(Shift $shift): ?string
{
    $alert = collect(app(GuardSignal::class)->open())->firstWhere('shift_id', $shift->id);

    return $alert === null ? null : (string) $alert['sentence'];
}

it('ends the dispatcher\'s sentence with the battery at the last reading, the figure that decides between calling and sending', function () {
    $h = nsbOnDuty();
    nsbSend($h, battery: 14);

    $this->travel(10)->minutes();
    Artisan::call('tracking:signal');

    $sentence = (string) nsbSentence($h['shift']);

    expect(str_starts_with($sentence, 'No signal from Battery Guard '))->toBeTrue()
        ->and(preg_match('/ at Battery Estate, last seen \d{1,2}:\d{2} [AP]M [A-Z]+, battery 14%$/', $sentence))->toBe(1);
});

it('names no figure when the phone sent none, nor before the first reading arrives', function () {
    $sentNone = nsbOnDuty();
    nsbSend($sentNone, battery: null);
    $nothingYet = nsbOnDuty();

    $this->travel(10)->minutes();
    Artisan::call('tracking:signal');

    $quiet = (string) nsbSentence($sentNone['shift']);
    $never = (string) nsbSentence($nothingYet['shift']);

    expect(preg_match('/, last seen \d{1,2}:\d{2} [AP]M [A-Z]+$/', $quiet))->toBe(1)
        ->and(str_contains($quiet, 'battery'))->toBeFalse()
        ->and(str_contains($never, ', nothing since clock-in at '))->toBeTrue()
        ->and(str_contains($never, 'battery'))->toBeFalse();
});
