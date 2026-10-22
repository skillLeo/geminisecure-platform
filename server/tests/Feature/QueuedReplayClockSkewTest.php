<?php

declare(strict_types=1);

use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| An act synced late is not a wrong clock — bug hunt B066
|--------------------------------------------------------------------------
|
| X-Device-Time is, by contract, WHEN THE ACT HAPPENED — for anything queued with no
| signal, that is minutes or hours before it reaches the server. `clock_skewed` was
| "the two times differ by more than two minutes", so every scan, report, alert and
| gate event synced more than two minutes after it was captured came back
| `clock_skewed: true`, was stored as clock-skewed evidence, and the Guard App told a
| guard whose phone was right that it was wrong.
|
| A device time in the PAST cannot be told apart from a queued act by anything the
| phones send today, so it is no longer called a wrong clock. A device time AHEAD of
| the server's is: no queue can put an act in the future. Both times are still kept
| on every record, exactly as sent.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    Http::preventStrayRequests();

    Carbon::setTestNow(Carbon::parse('2026-09-24 14:00:00', 'UTC'));

    $this->site = FacilitiesFixture::ESTATE;
    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Skew Gate', 'type' => 'gate', 'is_active' => true]);
    $this->guard = Guard::create([
        'full_name' => 'Skew Probe', 'employee_number' => 'GS-SKW-1', 'psra_number' => 'PSRA-SKW-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->site, 'post_id' => $this->post->id,
    ]);
    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Skew handset')['token'];

    $this->checkpoint = DB::connection('mysql')->table('patrol_checkpoints')->insertGetId([
        'tenant_id' => $this->site, 'post_id' => $this->post->id, 'label' => 'Skew pool gate', 'code' => 'SKEW-POOL-1', 'sequence' => 1, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // On duty since 11:00: a patrol scan belongs to a running shift (M21 §1.1), and every scan here is on this one.
    Shift::create([
        'tenant_id' => $this->site, 'guard_id' => $this->guard->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->subHours(3), 'rostered_end' => now()->addHours(9), 'actual_start' => now()->subHours(3), 'status' => 'on_duty',
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** A write as the handset, carrying the device time it would carry. */
function skewWrite(TestCase $test, string $token, string $uri, array $body, Carbon $deviceTime, string $key): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->postJson($uri, $body, [
        'Idempotency-Key' => $key, 'X-Device-Time' => $deviceTime->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

it('does not tell a guard their clock is wrong because a scan captured with no signal synced twenty minutes later', function () {
    $scan = skewWrite($this, $this->token, '/api/v1/checkpoints/'.$this->checkpoint.'/scan', ['code' => 'SKEW-POOL-1', 'captured_offline' => true], now()->subMinutes(20), 'skew-scan-late');

    $stored = DB::connection('mysql')->table('checkpoint_scans')->where('idempotency_key', 'skew-scan-late')->first();

    expect($scan->status())->toBe(201)
        ->and($scan->json('clock_skewed'))->toBeFalse()
        ->and((bool) $stored->clock_skewed)->toBeFalse()
        // Both times are still kept as they were: the scan happened twenty minutes ago.
        ->and(Carbon::parse((string) $stored->device_time)->diffInMinutes(Carbon::parse((string) $stored->server_time)))->toEqual(20.0)
        ->and((bool) $stored->captured_offline)->toBeTrue();
});

it('shows a checkpoint scanned with no signal at the moment it was scanned, not when it synced (D-192)', function () {
    // Scanned at 13:40 with no signal, synced at 14:00: the guard's tour must say 13:40.
    skewWrite($this, $this->token, '/api/v1/checkpoints/'.$this->checkpoint.'/scan', ['code' => 'SKEW-POOL-1', 'captured_offline' => true], now()->subMinutes(20), 'skew-scan-reached');

    app('auth')->forgetGuards();
    $tour = $this->withToken($this->token)->getJson('/api/v1/sites/'.$this->site.'/checkpoints');
    FacilitiesFixture::boot();

    $row = collect($tour->json('items'))->firstWhere('id', $this->checkpoint);
    expect($tour->status())->toBe(200)
        ->and(Carbon::parse((string) $row['last_scanned_at'])->equalTo(now()->subMinutes(20)))->toBeTrue()
        ->and($tour->json('tour.scanned_today'))->toBe(1);

    // A scan from a clock running AHEAD is placed where the server received it, as before: a fast clock is not trusted.
    skewWrite($this, $this->token, '/api/v1/checkpoints/'.$this->checkpoint.'/scan', ['code' => 'SKEW-POOL-1', 'captured_offline' => true], now()->addMinutes(10), 'skew-scan-fast');
    app('auth')->forgetGuards();
    $again = $this->withToken($this->token)->getJson('/api/v1/sites/'.$this->site.'/checkpoints');
    FacilitiesFixture::boot();

    expect(Carbon::parse((string) collect($again->json('items'))->firstWhere('id', $this->checkpoint)['last_scanned_at'])->equalTo(now()))->toBeTrue();
});

it('still tells a guard whose clock runs ahead of the server\'s, on the scan and in the record', function () {
    $scan = skewWrite($this, $this->token, '/api/v1/checkpoints/'.$this->checkpoint.'/scan', ['code' => 'SKEW-POOL-1'], now()->addMinutes(10), 'skew-scan-ahead');

    expect($scan->status())->toBe(201)
        ->and($scan->json('clock_skewed'))->toBeTrue()
        ->and((bool) DB::connection('mysql')->table('checkpoint_scans')->where('idempotency_key', 'skew-scan-ahead')->value('clock_skewed'))->toBeTrue();

    // Within the two minutes, either way, is agreement.
    $close = skewWrite($this, $this->token, '/api/v1/checkpoints/'.$this->checkpoint.'/scan', ['code' => 'SKEW-POOL-1'], now()->addSeconds(90), 'skew-scan-close');
    expect($close->json('clock_skewed'))->toBeFalse();
});

it('gives the same answer on every write, an alert and a gate event included', function () {
    // A report written with no signal and sent half an hour later: the generic write answer.
    $report = skewWrite($this, $this->token, '/api/v1/incidents', [
        'kind' => 'Suspicious vehicle', 'severity' => 'low', 'detail' => 'A car idled by the east wall for ten minutes, then left.', 'location' => 'East wall',
    ], now()->subMinutes(30), 'skew-incident-late');

    expect($report->status())->toBe(201)->and($report->json('clock_skewed'))->toBeFalse();

    // A duress captured offline and delivered ten minutes on: dispatch must not read "device clock disagrees".
    $late = skewWrite($this, $this->token, '/api/v1/duress', ['mode' => 'silent', 'captured_offline' => true], now()->subMinutes(10), 'skew-duress-late');
    $ahead = skewWrite($this, $this->token, '/api/v1/duress', ['mode' => 'silent'], now()->addMinutes(10), 'skew-duress-ahead');

    expect($late->status())->toBe(201)
        ->and($late->json('clock_skewed'))->toBeFalse()
        ->and((bool) DuressAlert::query()->findOrFail($late->json('id'))->clock_skewed)->toBeFalse()
        ->and($ahead->json('clock_skewed'))->toBeTrue()
        ->and((bool) DuressAlert::query()->findOrFail($ahead->json('id'))->clock_skewed)->toBeTrue();

    // A departure logged with no signal and synced later; and one from a handset running fast.
    $exit = skewWrite($this, $this->token, '/api/v1/gate/exit', ['category' => 'Visitor', 'subject' => 'Late departure'], now()->subMinutes(15), 'skew-exit-late');
    $fast = skewWrite($this, $this->token, '/api/v1/gate/exit', ['category' => 'Visitor', 'subject' => 'Fast departure'], now()->addMinutes(15), 'skew-exit-ahead');

    expect($exit->status())->toBe(201)
        ->and($exit->json('clock_skewed'))->toBeFalse()
        ->and($fast->json('clock_skewed'))->toBeTrue();
});
