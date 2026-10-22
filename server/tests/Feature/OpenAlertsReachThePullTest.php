<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| An emergency at the site, for a guard's phone the push cannot reach
|--------------------------------------------------------------------------
|
| FOUND ON A TEST PHONE, 2026-09-22. A resident pressed panic; the alert was
| recorded and was on the control room's screen — and the guard clocked on at
| that gate, with the app open in their hand, never saw it. The only way a
| guard's phone heard of a panic was a push, and an Android build with no
| Firebase project behind it has no push token at all.
|
| So `sync.pull`, which the open app already calls every few seconds, now
| carries the open alerts at the site: the same audience as the push's first
| tier (on duty there, never the guard who raised it, never a simulated alert),
| read-only, and never counted in `alerted_handsets` — what the resident is told
| about who their panic reached is still the push's own honest count.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    Http::preventStrayRequests();

    $this->site = FacilitiesFixture::ESTATE;
    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Alerts Gate', 'type' => 'gate', 'is_active' => true]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** @return array{0: Guard, 1: string} a guard of this estate and their handset's API token */
function alertsGuard(object $test, string $number, bool $onDuty): array
{
    $guard = Guard::create([
        'full_name' => 'Officer '.$number, 'employee_number' => $number, 'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $test->site, 'post_id' => $test->post->id,
    ]);

    if ($onDuty) {
        Shift::create([
            'tenant_id' => $test->site, 'guard_id' => $guard->id, 'post_id' => $test->post->id,
            'rostered_start' => now()->subHour(), 'rostered_end' => now()->addHours(11),
            'actual_start' => now()->subHour(), 'status' => 'on_duty',
        ]);
    }

    return [$guard, app(DeviceEnrolment::class)->enrol($guard, 'Alerts handset '.$number)['token']];
}

/** The pull, as the phone makes it, held to its catalogue entry. */
function alertsPull(TestCase $test, string $token, ?string $since = null): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)
        ->getJson('/api/v1/'.Catalogue::endpoints()['sync.pull']['uri'].'?'.http_build_query(['since' => $since ?? now()->subHour()->toIso8601String()]))
        ->assertOk();

    ApiContract::assertMatches($response, 'sync.pull');
    FacilitiesFixture::boot();

    return $response;
}

function alertsRaise(object $test, string $kind, ?int $guardId = null, ?string $unit = null, bool $simulated = false): DuressAlert
{
    return app(AlertIntake::class)->record(
        tenantId: $test->site,
        kind: $kind,
        guardId: $guardId,
        raisedByName: $guardId === null ? 'Alerts Resident' : null,
        unitReference: $unit,
        deviceTime: now(),
        idempotencyKey: 'alerts-'.bin2hex(random_bytes(8)),
        isSimulated: $simulated,
    );
}

it('puts a resident\'s panic, and another officer\'s duress, in front of the guard on duty — but never the guard\'s own', function () {
    [$me, $myToken] = alertsGuard($this, 'GS-ALR-1', onDuty: true);
    [$colleague] = alertsGuard($this, 'GS-ALR-2', onDuty: true);

    $panic = alertsRaise($this, 'panic', unit: 'Lot 985');
    $theirs = alertsRaise($this, 'duress', guardId: $colleague->id);
    $mine = alertsRaise($this, 'duress', guardId: $me->id);

    $alerts = collect(alertsPull($this, $myToken)->json('alerts'))->keyBy('alert_id');

    expect($alerts->get($panic->id))->toMatchArray(['kind' => 'panic', 'where' => 'Lot 985'])
        ->and($alerts->get($panic->id)['raised_at'])->toEndWith('+00:00')
        ->and($alerts->get($theirs->id))->toMatchArray(['kind' => 'duress', 'where' => null])
        // A guard under SILENT duress must not be shown their own alert as news on the phone in their hand.
        ->and($alerts->has($mine->id))->toBeFalse()
        // And never a name: the push says "At Lot 985", and so does this.
        ->and(json_encode($alerts->all()))->not->toContain('Alerts Resident');
});

it('tells nobody who is off duty, and nothing that is closed, cancelled, simulated or older than the phone already knows', function () {
    [, $onToken] = alertsGuard($this, 'GS-ALR-3', onDuty: true);
    [, $offToken] = alertsGuard($this, 'GS-ALR-4', onDuty: false);

    $open = alertsRaise($this, 'panic', unit: 'Lot 985');
    $closed = alertsRaise($this, 'panic', unit: 'Lot 985');
    $closed->forceFill(['status' => 'resolved', 'resolved_at' => now()])->saveQuietly();
    $falseAlarm = alertsRaise($this, 'panic', unit: 'Lot 985');
    $falseAlarm->forceFill(['status' => 'false_alarm', 'resolved_at' => now()])->saveQuietly();
    $cancelled = alertsRaise($this, 'panic', unit: 'Lot 985');
    $cancelled->forceFill(['cancelled_at' => now()])->saveQuietly();
    $simulated = alertsRaise($this, 'panic', unit: 'Lot 985', simulated: true);

    expect(collect(alertsPull($this, $onToken)->json('alerts'))->pluck('alert_id')->all())->toBe([$open->id])
        // A guard at home is not on duty at that site: telling them would be asking them to respond.
        ->and(alertsPull($this, $offToken)->json('alerts'))->toBe([])
        // Once the phone has pulled past it, it is not news again.
        ->and(alertsPull($this, $onToken, now()->addSecond()->toIso8601String())->json('alerts'))->toBe([])
        ->and($simulated->is_simulated)->toBeTrue();
});

it('leaves what the resident is told untouched: a pull is not a handset reached', function () {
    [, $token] = alertsGuard($this, 'GS-ALR-5', onDuty: true);

    $panic = alertsRaise($this, 'panic', unit: 'Lot 985');
    $before = $panic->fresh()?->alerted_handsets;

    alertsPull($this, $token);

    expect($panic->fresh()?->alerted_handsets)->toBe($before);
});
