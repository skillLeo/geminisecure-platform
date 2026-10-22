<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Live\Events\GuardSignalLive;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Tracking\GuardSignal;
use App\Services\Tracking\GuardTracking;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A No signal alert clears the moment nothing is missing any more (Payroll, 12 Oct 2026, extending Security's alert,
| GUARD_TRACKING.md 5.6: "clears itself when a position arrives or the shift ends")
|--------------------------------------------------------------------------
|
|   - the guard clocks out: it goes with the clock-out, not at the watch's next minute
|   - sharing is switched off for the service: no phone is asked for a position
|   - the guard stops sharing mid-shift: they refuse the wording, or it changes and they have not answered
|   - each raise and each clear is written in one transaction with its live event (DESIGN.md §1.3)
|   - the queue never says nothing is outstanding under an open No signal alert
|
| Arriving positions clearing it, and the shift's end found by the watch, are GuardSignalTest's.
|
*/

function nsmEstate(): string
{
    DB::table('tenants')->updateOrInsert(['id' => 'nsmestate'], ['name' => 'Quiet Estate', 'data' => json_encode(['name' => 'Quiet Estate']), 'created_at' => now(), 'updated_at' => now()]);

    return 'nsmestate';
}

/** @return array{guard: Guard, token: string, shift: Shift} */
function nsmOnDuty(int $inMinutes = 30): array
{
    static $n = 0;
    $n++;

    $estate = nsmEstate();
    $guard = Guard::create([
        'full_name' => 'Quiet Guard '.$n, 'employee_number' => 'GS-NSM-'.$n, 'psra_number' => 'PSRA-NSM-'.$n,
        'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
    ]);
    $post = Post::create(['tenant_id' => $estate, 'name' => 'Gate '.$n, 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0179, 'longitude' => -76.8099, 'geofence_radius_m' => 60]);
    $shift = Shift::create([
        'tenant_id' => $estate, 'guard_id' => $guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subMinutes($inMinutes + 10), 'rostered_end' => now()->addHours(8),
        'actual_start' => now()->subMinutes($inMinutes), 'status' => 'on_duty',
    ]);
    DB::table('guard_tracking_consents')->insert(['guard_id' => $guard->id, 'policy_version' => GuardTracking::policyVersion(), 'decision' => 'granted', 'decided_at' => now()]);
    $token = $guard->createToken('Quiet handset', AppMatrix::abilitiesFor(AppMatrix::GUARD))->plainTextToken;

    return ['guard' => $guard, 'token' => $token, 'shift' => $shift];
}

function nsmAs(array $h): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($h['token']);
}

/** @return array<string, string> */
function nsmHeaders(): array
{
    return ['Idempotency-Key' => 'nsm-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()];
}

function nsmReason(Shift $shift): ?string
{
    $reason = DB::table('guard_signal_alerts')->where('shift_id', $shift->id)->value('cleared_reason');

    return $reason === null ? null : (string) $reason;
}

it('clears the moment the guard clocks out, through the clock-out itself and not at the next minute', function () {
    $h = nsmOnDuty();
    Artisan::call('tracking:signal');
    expect(app(GuardSignal::class)->open())->toHaveCount(1);

    nsmAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/clock-out", [], nsmHeaders())->assertOk();

    // No watch has run since the clock-out: the clock-out cleared it.
    expect(app(GuardSignal::class)->open())->toBe([])
        ->and(nsmReason($h['shift']))->toBe('shift_ended');
});

it('clears when location sharing is switched off for the service: no phone is asked for a position', function () {
    $h = nsmOnDuty();
    Artisan::call('tracking:signal');
    expect(app(GuardSignal::class)->open())->toHaveCount(1);

    config(['tracking.enabled' => false]);
    Artisan::call('tracking:signal');

    expect(app(GuardSignal::class)->open())->toBe([])
        ->and(nsmReason($h['shift']))->toBe('tracking_off');
});

it('clears when the guard stops sharing mid-shift: the wording refused, or new words not yet answered', function () {
    $refuses = nsmOnDuty();
    $unanswered = nsmOnDuty();
    Artisan::call('tracking:signal');
    expect(app(GuardSignal::class)->open())->toHaveCount(2);

    // A changed mind is a new row: the consents are append-only.
    DB::table('guard_tracking_consents')->insert(['guard_id' => $refuses['guard']->id, 'policy_version' => GuardTracking::policyVersion(), 'decision' => 'refused', 'decided_at' => now()]);
    Artisan::call('tracking:signal');

    expect(collect(app(GuardSignal::class)->open())->pluck('shift_id')->all())->toBe([$unanswered['shift']->id])
        ->and(nsmReason($refuses['shift']))->toBe('not_shared');

    // New words: nobody has answered them yet, so nothing is expected from any phone.
    config(['tracking.policy_version' => GuardTracking::policyVersion().'-nsm']);
    Artisan::call('tracking:signal');

    expect(app(GuardSignal::class)->open())->toBe([])
        ->and(nsmReason($unanswered['shift']))->toBe('not_shared');
});

it('writes each raise and each clear in one transaction with its live event (DESIGN.md §1.3)', function () {
    $h = nsmOnDuty();
    $base = DB::connection('mysql')->transactionLevel();
    $writes = [];

    DB::connection('mysql')->listen(static function (QueryExecuted $query) use (&$writes): void {
        $sql = strtolower(ltrim($query->sql));

        if (! str_starts_with($sql, 'insert') && ! str_starts_with($sql, 'update')) {
            return;
        }

        if (str_contains($sql, 'guard_signal_alerts')) {
            $writes[] = ['alert', $query->connection->transactionLevel()];
        } elseif (str_contains($sql, 'live_events') && in_array(GuardSignalLive::TYPE, $query->bindings, true)) {
            $writes[] = ['live', $query->connection->transactionLevel()];
        }
    });

    // Raised by the watch, then cleared by it: the shift ended without the clock-out's own clear (a shift closed by hand).
    Artisan::call('tracking:signal');
    $h['shift']->forceFill(['actual_end' => now(), 'status' => 'completed'])->save();
    Artisan::call('tracking:signal');

    $kinds = collect($writes)->pluck(0);
    $outside = collect($writes)->filter(static fn (array $write): bool => $write[1] <= $base)->values()->all();

    expect($kinds->filter(static fn (string $kind): bool => $kind === 'alert')->count())->toBe(2)
        ->and($kinds->filter(static fn (string $kind): bool => $kind === 'live')->count())->toBeGreaterThanOrEqual(2)
        ->and($outside)->toBe([]);
});

it('never tells the dispatcher nothing is outstanding under an open No signal alert', function () {
    $page = (string) file_get_contents(resource_path('js/Pages/Gemini/Dispatch/Alerts.vue'));

    $found = preg_match("/const emptyQueue = computed\\(\\(\\) => props\\.noSignal\\.length > 0\\s*\\?\\s*\\{\\s*title: '([^']+)',\\s*body: '([^']+)',\\s*\\}/", $page, $under);

    expect($found)->toBe(1)
        ->and($under[1] ?? null)->toBe('No panic or duress alerts')
        ->and(str_contains($under[2] ?? 'nothing is outstanding', 'nothing is outstanding'))->toBeFalse()
        ->and(str_contains($page, ':title="emptyQueue.title"'))->toBeTrue()
        ->and(str_contains($page, ':body="emptyQueue.body"'))->toBeTrue()
        ->and(str_contains($page, 'title="No active alerts"'))->toBeFalse();
});
