<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Models\DuressAlert;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use App\Services\Passes\VisitorPasses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The pull's cursor loses nothing — bug hunt B056, B063
|--------------------------------------------------------------------------
|
| B056. `next_since` was the moment the pull began, cut to the whole second, and
| every list asks for `> since`. The columns hold whole seconds too. So a panic
| raised, or a pass cancelled, AFTER a pull's queries ran but inside the same
| second is stored at that second — and every later pull asks for what came after
| it. For a guard's phone with no push token the pull is the only way it hears of a
| panic, and a cancellation the gate never learns of goes on admitting offline.
|
| B063. Messages were capped at 100 and resident reports and alerts at 20, oldest
| first — and `next_since` still jumped to now, so whatever the cap cut off, the
| newest, was never pulled at all.
|
| The phone keeps every list by id (`rememberAlerts`, `mergeRevoked`, the notifier's
| keys), so a cursor that re-asks for a little is harmless; one that skips is not.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    // Push is off in the suite; nothing here may reach Expo.
    Http::preventStrayRequests();

    $this->site = FacilitiesFixture::ESTATE;
    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Cursor Gate', 'type' => 'gate', 'is_active' => true]);
    $this->unit = FacilitiesFixture::scratchUnit('Lot 7804');
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    // The estate database is committed: the passes this file issued come out again.
    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
    DB::connection('tenant')->table('maintenance_tickets')->where('number', '>=', 7_804_000)->where('number', '<', 7_805_000)->delete();
});

/** @return array{0: Guard, 1: string} a guard clocked on at this estate, and their handset's token */
function cursorGuard(object $test, string $number): array
{
    $guard = Guard::create([
        'full_name' => 'Officer '.$number, 'employee_number' => $number, 'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $test->site, 'post_id' => $test->post->id,
    ]);

    Shift::create([
        'tenant_id' => $test->site, 'guard_id' => $guard->id, 'post_id' => $test->post->id,
        'rostered_start' => now()->subHour(), 'rostered_end' => now()->addHours(11),
        'actual_start' => now()->subHour(), 'status' => 'on_duty',
    ]);

    return [$guard, app(DeviceEnrolment::class)->enrol($guard, 'Cursor handset '.$number)['token']];
}

/** The pull, as the phone makes it — `since` sent back untouched — held to its catalogue entry. */
function cursorPull(TestCase $test, string $token, string $since): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)
        ->getJson('/api/v1/'.Catalogue::endpoints()['sync.pull']['uri'].'?'.http_build_query(['since' => $since]))
        ->assertOk();

    ApiContract::assertMatches($response, 'sync.pull');
    FacilitiesFixture::boot();

    return $response;
}

/** An open alert at this site, written quietly: the fan-out is not what these tests are about. */
function cursorAlert(object $test, Carbon $at, int $n): DuressAlert
{
    return DuressAlert::withoutEvents(fn () => DuressAlert::query()->create([
        'tenant_id' => $test->site, 'kind' => 'panic', 'unit_reference' => 'Lot 7804', 'status' => 'open',
        'server_time' => $at, 'device_time' => $at, 'clock_skewed' => false, 'captured_offline' => false,
        'is_simulated' => false, 'idempotency_key' => 'cursor-alert-'.$n.'-'.bin2hex(random_bytes(4)),
    ]));
}

it('re-asks for the second a pull ran in, so a panic or a cancellation written just after its queries is not lost (B056)', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-24 11:59:00', 'UTC'));
    [, $token] = cursorGuard($this, 'GS-CUR-1');

    $pass = app(VisitorPasses::class)->issue($this->site, $this->unit, null, 'Cursor Resident', [
        'category' => 'single', 'visitor_name' => 'Cursor Visitor',
        'valid_from' => now()->subMinute(), 'valid_to' => now()->addDay(),
    ]);

    // The pull begins at 12:00:00.300.
    Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00.300', 'UTC'));
    $first = cursorPull($this, $token, '2026-09-24T11:00:00Z');

    expect($first->json('alerts'))->toBe([])
        ->and(collect($first->json('passes.revoked'))->pluck('pass_id'))->not->toContain($pass->pass_id);

    // Half a second later — after that pull's queries ran, in the same second — a resident
    // presses panic, and another cancels a visitor's pass. Both are stored at 12:00:00.
    Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00.800', 'UTC'));
    $panic = app(AlertIntake::class)->record(
        tenantId: $this->site, kind: 'panic', raisedByName: 'Cursor Resident', unitReference: 'Lot 7804',
        deviceTime: now(), idempotencyKey: 'cursor-panic-'.bin2hex(random_bytes(6)),
    );
    app(VisitorPasses::class)->cancel($pass);

    expect(Carbon::parse((string) $panic->fresh()?->getRawOriginal('server_time'))->format('H:i:s.u'))->toBe('12:00:00.000000');

    // The next pull, twenty seconds on, sends back exactly what it was given.
    Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:20', 'UTC'));
    $second = cursorPull($this, $token, (string) $first->json('next_since'));

    expect(collect($second->json('alerts'))->pluck('alert_id'))->toContain($panic->id)
        ->and(collect($second->json('passes.revoked'))->pluck('pass_id'))->toContain($pass->pass_id);

    // And a cursor never runs ahead of the moment the pull began.
    expect(Carbon::parse((string) $first->json('next_since'))->lessThan(Carbon::parse('2026-09-24 12:00:00', 'UTC')))->toBeTrue();
});

it('pages a long run of messages forward instead of skipping the newest (B063)', function () {
    Carbon::setTestNow(Carbon::parse('2027-03-01 12:00:00', 'UTC'));
    [$guard, $token] = cursorGuard($this, 'GS-CUR-2');

    // A phone dark for three days; dispatch broadcast 150 times over the last two.
    $ids = [];
    for ($i = 0; $i < 150; $i++) {
        $ids[] = (int) DB::connection('mysql')->table('dispatch_messages')->insertGetId([
            'tenant_id' => $this->site, 'direction' => 'broadcast', 'body' => 'Cursor broadcast '.$i,
            'sent_at' => now()->subDays(2)->addMinutes($i * 10), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $seen = [];
    $since = now()->subDays(3)->toIso8601String();

    for ($pull = 0; $pull < 5; $pull++) {
        $response = cursorPull($this, $token, $since);
        $seen = [...$seen, ...collect($response->json('messages'))->pluck('id')->all()];
        $since = (string) $response->json('next_since');
    }

    // Every one arrives — the newest included — and none is lost to the cap.
    expect(array_values(array_unique($seen)))->toEqualCanonicalizing($ids)
        ->and($seen)->toContain(end($ids));
});

it('never splits a second across two pages, so a burst larger than the cap is neither lost nor looped on (B063)', function () {
    Carbon::setTestNow(Carbon::parse('2027-03-02 12:00:00', 'UTC'));
    [, $token] = cursorGuard($this, 'GS-CUR-3');

    // 104 messages in one second, and one more a minute later.
    $burst = now()->subHour();
    $ids = [];
    for ($i = 0; $i < 104; $i++) {
        $ids[] = (int) DB::connection('mysql')->table('dispatch_messages')->insertGetId([
            'tenant_id' => $this->site, 'direction' => 'broadcast', 'body' => 'Burst '.$i, 'sent_at' => $burst, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $later = (int) DB::connection('mysql')->table('dispatch_messages')->insertGetId([
        'tenant_id' => $this->site, 'direction' => 'broadcast', 'body' => 'After the burst', 'sent_at' => $burst->copy()->addMinute(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $first = cursorPull($this, $token, now()->subDay()->toIso8601String());
    $second = cursorPull($this, $token, (string) $first->json('next_since'));

    $seen = [...collect($first->json('messages'))->pluck('id')->all(), ...collect($second->json('messages'))->pluck('id')->all()];

    expect(array_values(array_unique($seen)))->toEqualCanonicalizing([...$ids, $later])
        // The cursor moved: the second page is not the first one again.
        ->and(collect($second->json('messages'))->pluck('id')->all())->toContain($later);
});

it('pages the open alerts and the happening-now reports forward the same way (B063)', function () {
    Carbon::setTestNow(Carbon::parse('2027-03-03 12:00:00', 'UTC'));
    [, $token] = cursorGuard($this, 'GS-CUR-4');

    // Twenty-five open alerts in the last hour — more than the list carries at once.
    $alerts = collect(range(0, 24))->map(fn (int $i) => cursorAlert($this, now()->subMinutes(50)->addMinutes($i * 2), $i)->id)->all();

    // Twenty-five happening-now reports in the last twelve hours.
    FacilitiesFixture::boot();
    $reports = [];
    for ($i = 0; $i < 25; $i++) {
        $reports[] = (int) DB::connection('tenant')->table('maintenance_tickets')->insertGetId([
            'number' => 7_804_000 + $i, 'title' => 'Cursor report '.$i, 'location_label' => 'Lot 7804', 'unit_id' => $this->unit->id,
            'status' => 'submitted', 'reported_at' => now()->subHours(10)->addMinutes($i * 20), 'sla_hours' => 24,
            'happening_now' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $seenAlerts = [];
    $seenReports = [];
    $since = now()->subHours(13)->toIso8601String();

    for ($pull = 0; $pull < 3; $pull++) {
        $response = cursorPull($this, $token, $since);
        $seenAlerts = [...$seenAlerts, ...collect($response->json('alerts'))->pluck('alert_id')->all()];
        $seenReports = [...$seenReports, ...collect($response->json('resident_reports'))->pluck('report_id')->all()];
        $since = (string) $response->json('next_since');
    }

    expect(array_values(array_unique($seenAlerts)))->toEqualCanonicalizing($alerts)
        ->and(array_values(array_unique($seenReports)))->toEqualCanonicalizing($reports);
});
