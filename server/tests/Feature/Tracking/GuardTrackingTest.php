<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Enums\AccessScope;
use App\Enums\Console;
use App\Live\Events\GuardPositionLive;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\LiveMap;
use App\Services\Estate\EstateSecurity;
use App\Services\Estate\EstateSecurityMap;
use App\Services\Tracking\GuardTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| On-duty guard tracking (M11 §3; D-174)
|--------------------------------------------------------------------------
|
| The server is the rule, whatever a phone does:
|   - a position is kept only while THIS shift is open when it arrives, clocked in and not clocked out (D-232):
|     a batch for a shift that is not open is refused whole, a fix taken during the shift included, and nothing
|     in it is stored; within an open shift, one taken before the clock-in is refused, on the server's clock;
|   - only with the officer's consent to the CURRENT policy; a refusal is recorded, and the map says
|     "position not shared";
|   - a resent fix is kept once; another estate's shift is not a shift;
|   - positions go after the retention window, and the purge is real;
|   - Gemini's map draws the pins with their age; an estate's console sees none unless its contract says so.
|
*/

function trkEstate(string $id = 'trackest'): string
{
    DB::table('tenants')->updateOrInsert(['id' => $id], ['name' => 'Tracking Estate '.$id, 'data' => json_encode(['name' => 'Tracking Estate '.$id]), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** @return array{guard: Guard, token: string, shift: Shift, post: Post} */
function trkOnDuty(string $estate = 'trackest', bool $started = true): array
{
    static $n = 0;
    $n++;

    $estate = trkEstate($estate);
    $guard = Guard::create([
        'full_name' => 'Tracked Guard '.$n, 'employee_number' => 'GS-TRK-'.$n, 'psra_number' => 'PSRA-TRK-'.$n,
        'psra_expires_on' => now()->addYear(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
    ]);
    $post = Post::create(['tenant_id' => $estate, 'name' => 'Main Gate '.$n, 'type' => 'gate', 'is_active' => true, 'latitude' => 18.0179, 'longitude' => -76.8099, 'geofence_radius_m' => 60]);
    $shift = Shift::create([
        'tenant_id' => $estate, 'guard_id' => $guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subHours(2), 'rostered_end' => now()->addHours(10),
        'actual_start' => $started ? now()->subHour() : null, 'status' => $started ? 'on_duty' : 'rostered',
    ]);
    $token = $guard->createToken('Tracked handset', AppMatrix::abilitiesFor(AppMatrix::GUARD))->plainTextToken;

    return ['guard' => $guard, 'token' => $token, 'shift' => $shift, 'post' => $post];
}

/**
 * Speak as this handset. The auth guard remembers the first token of a test, so a second officer's request would
 * go out as the first one's — it is forgotten before every switch, as a real second phone would never share it.
 */
function trkAs(array $h): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($h['token']);
}

function trkWrite(): array
{
    return ['Idempotency-Key' => 'trk-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()];
}

function trkConsent(array $h, string $decision = 'granted'): void
{
    trkAs($h)->postJson('/api/v1/guards/me/tracking/consent', ['decision' => $decision, 'policy_version' => GuardTracking::policyVersion()], trkWrite())->assertOk();
}

function trkFix(string $at, string $source = 'interval', ?string $id = null): array
{
    return ['id' => $id ?? (string) Str::uuid(), 'latitude' => 18.0181, 'longitude' => -76.8102, 'accuracy_m' => 8, 'source' => $source, 'battery_pct' => 71, 'captured_at' => $at];
}

it('tells the phone the flag, the policy to show, the officer\'s answer and how to sample', function () {
    $h = trkOnDuty();

    trkAs($h)->getJson('/api/v1/guards/me/tracking')->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('policy_version', GuardTracking::policyVersion())
        ->assertJsonPath('consent', null)
        ->assertJsonPath('retention_days', 90)
        ->assertJsonPath('sampling.low_battery_pct', 20);

    trkConsent($h);

    trkAs($h)->getJson('/api/v1/guards/me/tracking')->assertOk()->assertJsonPath('consent.decision', 'granted');

    // Audited: an HR matter either way.
    expect(DB::table('audit_log')->where('action', 'guard.location_sharing_granted')->where('entity_id', (string) $h['guard']->id)->exists())->toBeTrue();
});

it('keeps a fix taken during the shift, with its capture time corrected to the server\'s clock', function () {
    $h = trkOnDuty();
    trkConsent($h);

    // The phone's clock is three minutes FAST: X-Device-Time says 12:03 when the server says 12:00.
    $deviceNow = now()->addMinutes(3);
    $fix = trkFix($deviceNow->copy()->subMinutes(10)->toIso8601String());

    trkAs($h)
        ->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [$fix]], ['Idempotency-Key' => 'trk-a', 'X-Device-Time' => $deviceNow->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('accepted', [$fix['id']])
        ->assertJsonPath('refused', []);

    $row = DB::table('guard_positions')->where('client_id', $fix['id'])->first();
    expect($row)->not->toBeNull()
        ->and((int) $row->shift_id)->toBe($h['shift']->id)
        ->and((string) $row->tenant_id)->toBe('trackest')
        ->and($row->source)->toBe('interval');

    // Ten minutes ago on the SERVER's clock, not the phone's.
    expect(Carbon::parse($row->captured_at)->diffInSeconds(now()->subMinutes(10), true))->toBeLessThan(3);
    expect(Carbon::parse($row->device_captured_at)->diffInSeconds($deviceNow->copy()->subMinutes(10), true))->toBeLessThan(3);
});

it('refuses, and does not store, a fix from before the clock-in, or anything that arrives after the clock-out', function () {
    $h = trkOnDuty();
    trkConsent($h);

    $before = trkFix(now()->subHours(2)->toIso8601String());
    $during = trkFix(now()->subMinutes(30)->toIso8601String());

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [$before, $during]], trkWrite())
        ->assertOk()
        ->assertJsonPath('accepted', [$during['id']])
        ->assertJsonPath('refused.0.id', $before['id'])
        ->assertJsonPath('refused.0.code', 'outside_shift');

    // Clock out, and the batch that arrives after it is refused whole, even from a phone that did not stop: a fix
    // taken after the clock-out, and one queued offline DURING the shift too (D-232, which replaced D-174's rule).
    $h['shift']->forceFill(['actual_end' => now()->subMinutes(5), 'status' => 'completed'])->save();
    $after = trkFix(now()->toIso8601String());
    $queued = trkFix(now()->subMinutes(20)->toIso8601String(), 'movement');

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [$queued, $after]], trkWrite())
        ->assertOk()
        ->assertJsonPath('accepted', [])
        ->assertJsonPath('refused.0.id', $queued['id'])
        ->assertJsonPath('refused.0.code', 'no_open_shift')
        ->assertJsonPath('refused.1.id', $after['id'])
        ->assertJsonPath('refused.1.code', 'no_open_shift');

    expect(DB::table('guard_positions')->whereIn('client_id', [$before['id'], $queued['id'], $after['id']])->count())->toBe(0)
        ->and(DB::table('guard_positions')->where('shift_id', $h['shift']->id)->count())->toBe(1);
});

it('stores nothing for a shift that has not started, and nothing from the future', function () {
    $h = trkOnDuty(started: false);
    trkConsent($h);

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [trkFix(now()->toIso8601String())]], trkWrite())
        ->assertOk()->assertJsonPath('accepted', [])->assertJsonPath('refused.0.code', 'no_open_shift');

    $on = trkOnDuty();
    trkConsent($on);

    trkAs($on)->postJson("/api/v1/shifts/{$on['shift']->id}/positions", ['positions' => [trkFix(now()->addMinutes(30)->toIso8601String())]], trkWrite())
        ->assertOk()->assertJsonPath('refused.0.code', 'in_future');

    expect(DB::table('guard_positions')->count())->toBe(0);
});

it('keeps nothing of an officer on a day off: a phone that never stopped is refused whole (D-232)', function () {
    $h = trkOnDuty();
    trkConsent($h);

    // Yesterday's shift, clocked out last night. Today is a day off, and the phone still sends on that shift.
    $h['shift']->forceFill(['actual_start' => now()->subDay()->subHours(8), 'actual_end' => now()->subDay(), 'status' => 'completed'])->save();
    $today = [trkFix(now()->subMinutes(30)->toIso8601String()), trkFix(now()->subMinute()->toIso8601String(), 'movement')];

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => $today], trkWrite())
        ->assertOk()
        ->assertJsonPath('accepted', [])
        ->assertJsonCount(2, 'refused')
        ->assertJsonPath('refused.0.code', 'no_open_shift')
        ->assertJsonPath('refused.1.code', 'no_open_shift');

    // A shift the platform closed because nobody clocked out (ShiftAutoClose) is closed for this too.
    $forgot = trkOnDuty();
    trkConsent($forgot);
    $forgot['shift']->forceFill(['actual_end' => now()->subHours(3), 'auto_closed_at' => now()->subHour(), 'status' => 'completed'])->save();

    trkAs($forgot)->postJson("/api/v1/shifts/{$forgot['shift']->id}/positions", ['positions' => [trkFix(now()->subMinutes(5)->toIso8601String())]], trkWrite())
        ->assertOk()->assertJsonPath('accepted', [])->assertJsonPath('refused.0.code', 'no_open_shift');

    expect(DB::table('guard_positions')->count())->toBe(0)
        ->and(Shift::openFor($h['guard']->id))->toBeNull()
        ->and(Shift::openFor($forgot['guard']->id))->toBeNull();
});

it('keeps a batch only on the shift that is open now, never on an older one the phone still names', function () {
    $h = trkOnDuty();
    trkConsent($h);

    // Last night's shift, closed; the one open now is the fixture's own.
    $old = Shift::create([
        'tenant_id' => 'trackest', 'guard_id' => $h['guard']->id, 'post_id' => $h['post']->id,
        'rostered_start' => now()->subDay()->subHours(8), 'rostered_end' => now()->subDay(),
        'actual_start' => now()->subDay()->subHours(8), 'actual_end' => now()->subDay(), 'status' => 'completed',
    ]);
    $fix = trkFix(now()->subMinutes(2)->toIso8601String());

    trkAs($h)->postJson("/api/v1/shifts/{$old->id}/positions", ['positions' => [$fix]], trkWrite())
        ->assertOk()->assertJsonPath('accepted', [])->assertJsonPath('refused.0.code', 'no_open_shift');

    // Named for an endpoint that is not given a shift: the one clocked in to now.
    expect(Shift::openFor($h['guard']->id)?->id)->toBe($h['shift']->id);

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [$fix]], trkWrite())
        ->assertOk()->assertJsonPath('accepted', [$fix['id']]);

    expect(DB::table('guard_positions')->where('shift_id', $old->id)->count())->toBe(0)
        ->and(DB::table('guard_positions')->where('shift_id', $h['shift']->id)->count())->toBe(1);
});

it('asks again just before it writes: a clock-out that lands while a batch is read keeps nothing of it', function () {
    $h = trkOnDuty();
    trkConsent($h);

    // The clock-out lands after the batch was found open and before it is written: here, as the estate's boundary is
    // read, which falls between the two.
    $landed = false;
    DB::listen(function ($query) use (&$landed, $h): void {
        if (! $landed && str_contains($query->sql, 'estate_sites')) {
            $landed = true;
            // Central, by name: inside the request the default connection is the estate's.
            DB::connection('mysql')->table('shifts')->where('id', $h['shift']->id)->update(['actual_end' => now(), 'status' => 'completed']);
        }
    });

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [trkFix(now()->subMinutes(4)->toIso8601String())]], trkWrite())
        ->assertOk()->assertJsonPath('accepted', [])->assertJsonPath('refused.0.code', 'no_open_shift');

    expect($landed)->toBeTrue()
        ->and(DB::table('guard_positions')->count())->toBe(0);
});

it('has one writer of guard_positions, so no endpoint keeps a position the open shift rule did not see', function () {
    $writers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match("/['\"]guard_positions['\"]\\)\\s*->\\s*(insert|insertOrIgnore|insertGetId|insertUsing|upsert|update|updateOrInsert)\\b/", $source) === 1) {
            $writers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
        }
    }

    expect($writers)->toBe(['Services/Tracking/GuardTracking.php']);
});

it('stores nothing without consent to the CURRENT policy — and a refusal is an answer, not a crash', function () {
    $h = trkOnDuty();
    $fix = ['positions' => [trkFix(now()->subMinutes(5)->toIso8601String())]];

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", $fix, trkWrite())
        ->assertForbidden()->assertJsonPath('error.code', 'consent_required');

    trkConsent($h, 'refused');

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", $fix, trkWrite())
        ->assertForbidden()->assertJsonPath('error.code', 'consent_required');

    // An answer to an older policy's words does not count.
    DB::table('guard_tracking_consents')->insert(['guard_id' => $h['guard']->id, 'policy_version' => '2020-01-01', 'decision' => 'granted', 'decided_at' => now()]);

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", $fix, trkWrite())->assertForbidden();

    // And a phone that showed old words is told to show the new ones.
    trkAs($h)->postJson('/api/v1/guards/me/tracking/consent', ['decision' => 'granted', 'policy_version' => '2020-01-01'], trkWrite())
        ->assertStatus(409)->assertJsonPath('error.code', 'policy_changed');

    expect(DB::table('guard_positions')->count())->toBe(0);
});

it('stores nothing with the feature switched off', function () {
    $h = trkOnDuty();
    trkConsent($h);
    config(['tracking.enabled' => false]);

    trkAs($h)->getJson('/api/v1/guards/me/tracking')->assertOk()->assertJsonPath('enabled', false);
    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [trkFix(now()->subMinute()->toIso8601String())]], trkWrite())
        ->assertForbidden()->assertJsonPath('error.code', 'tracking_off');

    expect(DB::table('guard_positions')->count())->toBe(0);
});

it('keeps a resent fix once', function () {
    $h = trkOnDuty();
    trkConsent($h);
    $fix = trkFix(now()->subMinutes(3)->toIso8601String());

    foreach ([1, 2] as $attempt) {
        trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [$fix]], trkWrite())
            ->assertOk()->assertJsonPath('accepted', [$fix['id']]);
    }

    expect(DB::table('guard_positions')->where('client_id', $fix['id'])->count())->toBe(1);
});

it('treats another estate\'s shift as no shift, and a colleague\'s as not theirs', function () {
    $mine = trkOnDuty('trackest');
    trkConsent($mine);
    $elsewhere = trkOnDuty('otherest');
    $colleague = trkOnDuty('trackest');
    $fix = ['positions' => [trkFix(now()->subMinutes(2)->toIso8601String())]];

    trkAs($mine)->postJson("/api/v1/shifts/{$elsewhere['shift']->id}/positions", $fix, trkWrite())->assertNotFound();
    trkAs($mine)->postJson("/api/v1/shifts/{$colleague['shift']->id}/positions", $fix, trkWrite())
        ->assertForbidden()->assertJsonPath('error.code', 'not_your_shift');

    expect(DB::table('guard_positions')->count())->toBe(0);
});

it('purges what is older than the retention window, and keeps what is inside it', function () {
    $h = trkOnDuty();
    $row = static fn (string $id, Carbon $at): array => [
        'guard_id' => $h['guard']->id, 'shift_id' => $h['shift']->id, 'tenant_id' => 'trackest', 'client_id' => $id,
        'latitude' => 18.0, 'longitude' => -76.8, 'source' => 'interval', 'captured_at' => $at, 'received_at' => $at,
    ];

    DB::table('guard_positions')->insert([
        $row('old', now()->subDays(91)),
        $row('edge', now()->subDays(89)),
        $row('new', now()->subHour()),
    ]);

    Artisan::call('gs:guard-positions-purge');

    expect(DB::table('guard_positions')->pluck('client_id')->sort()->values()->all())->toBe(['edge', 'new']);

    config(['tracking.retention_days' => 30]);
    Artisan::call('gs:guard-positions-purge');

    expect(DB::table('guard_positions')->pluck('client_id')->all())->toBe(['new']);
});

it('tells the dispatch map that an officer moved — a reference, no position on the channel', function () {
    $h = trkOnDuty();
    trkConsent($h);

    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [trkFix(now()->subMinute()->toIso8601String())]], trkWrite())->assertOk();

    $events = DB::connection('mysql')->table('live_events')->where('type', GuardPositionLive::TYPE)->get();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect((string) $event->entity_id)->toBe((string) $h['guard']->id)
            ->and($event->mode)->toBe('ref')
            ->and(json_encode($event))->not->toContain('18.018')
            // Gemini's dispatch feed only: this estate's contract does not let its console see guard pins.
            ->and((string) $event->channels)->toContain('dispatch')
            ->and((string) $event->channels)->not->toContain('estate.');
    }
});

it('draws each officer on Gemini\'s map with the age of their fix, and names those with none', function () {
    $shared = trkOnDuty();
    trkConsent($shared);
    $refused = trkOnDuty();
    trkConsent($refused, 'refused');
    $silent = trkOnDuty();
    trkConsent($silent);

    trkAs($shared)->postJson("/api/v1/shifts/{$shared['shift']->id}/positions", ['positions' => [trkFix(now()->subMinutes(12)->toIso8601String())]], trkWrite())->assertOk();

    $role = Role::findOrCreate('test.tracking_map', 'web');
    $role->forceFill(['console' => Console::Gemini->value, 'scope_default' => AccessScope::All->value])->save();
    $viewer = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $viewer->syncRoles([$role->name]);

    $geo = app(LiveMap::class)->forViewer($viewer->fresh())['geo'];

    expect($geo['tracking'])->toBeTrue()
        ->and($geo['guards'])->toHaveCount(1)
        ->and($geo['guards'][0]['id'])->toBe('guard-'.$shared['guard']->id)
        ->and($geo['guards'][0]['stale'])->toBeTrue() // twelve minutes old is not "now"
        ->and($geo['guards'][0]['lat'])->toBe(18.0181);

    $why = collect($geo['unshared'])->pluck('why', 'id');
    // Clocked in an hour ago and nothing since: past the five-minute tolerance, No signal (12 Oct 2026, GuardFreshness).
    expect($why['guard-'.$refused['guard']->id])->toBe('Position not shared')
        ->and(str_starts_with((string) $why['guard-'.$silent['guard']->id], 'No signal since clock-in at '))->toBeTrue();
});

it('refuses a fix kilometres outside the estate\'s boundary as impossible, and keeps one on the estate (M57.5 §4.5)', function () {
    $h = trkOnDuty();
    trkConsent($h);

    // A small square round the fixture's usual fix (18.0181, -76.8102).
    DB::table('estate_sites')->updateOrInsert(['tenant_id' => 'trackest'], [
        'boundary' => json_encode([[18.010, -76.820], [18.026, -76.820], [18.026, -76.800], [18.010, -76.800]]),
        'boundary_source' => 'A test outline', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $there = trkFix(now()->subMinutes(2)->toIso8601String());
    $pakistan = ['latitude' => 31.5204, 'longitude' => 74.3587] + trkFix(now()->subMinute()->toIso8601String());

    $answer = trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [$there, $pakistan]], trkWrite())->assertOk();

    expect($answer->json('accepted'))->toBe([$there['id']])
        ->and($answer->json('refused.0.id'))->toBe($pakistan['id'])
        ->and($answer->json('refused.0.code'))->toBe('off_estate')
        ->and($answer->json('refused.0.message'))->toContain('km outside the estate\'s boundary')
        ->and(DB::table('guard_positions')->where('client_id', $pakistan['id'])->exists())->toBeFalse();
});

it('shows the estate "guards on site" and no pins — unless its contract says so', function () {
    $h = trkOnDuty();
    trkConsent($h);
    trkAs($h)->postJson("/api/v1/shifts/{$h['shift']->id}/positions", ['positions' => [trkFix(now()->subMinute()->toIso8601String())]], trkWrite())->assertOk();

    $plan = DB::connection('mysql')->table('plans')->insertGetId(['key' => 'trk-plan', 'name' => 'Tracking plan', 'price_per_unit_minor' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::connection('mysql')->table('subscriptions')->insert(['tenant_id' => 'trackest', 'plan_id' => $plan, 'unit_count' => 10, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    // The estate's map is its own feed now (EstateSecurityMap, 12 Oct 2026): its guards, without a position until allowed.
    $map = app(EstateSecurityMap::class)->for('trackest');
    expect($map['positions'])->toBeFalse()
        ->and($map['guards'])->toHaveCount(1)
        ->and($map['guards'][0])->not->toHaveKey('lat')
        ->and(app(EstateSecurity::class)->geo('trackest'))->not->toHaveKey('guards')
        ->and(app(EstateSecurity::class)->guardsOnSite('trackest')['count'])->toBe(1);

    DB::connection('mysql')->table('subscriptions')->where('tenant_id', 'trackest')->update(['estate_sees_guard_positions' => true]);

    // M57 §9.1: held from every estate until Hassam has confirmed it with Omar, whatever the contract switch says.
    expect(app(EstateSecurityMap::class)->for('trackest')['guards'][0])->not->toHaveKey('lat');

    config(['tracking.estate_positions_held' => false]);

    $map = app(EstateSecurityMap::class)->for('trackest');
    expect($map['positions'])->toBeTrue()
        ->and($map['guards'][0]['lat'])->toBe(18.0181)
        ->and($map['guards'][0])->not->toHaveKey('href')
        ->and($map['guards'][0])->not->toHaveKey('guard_id');
});
