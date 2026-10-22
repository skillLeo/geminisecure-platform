<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\EstateAssignment;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Enums\Console;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\SupervisorOverride;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\QueryBudget;
use Tests\Support\ScaleFixture;
use Tests\Support\ScaleVolume;

/*
|--------------------------------------------------------------------------
| The app writes this unit touched, at production volume, inside a query budget — M11 A2
|--------------------------------------------------------------------------
|
| The household invites (members.store, members.invite, members.invite_cancel,
| members.destroy), an officer's incident report, a supervisor's clock-in override — which
| tells every Head of Security covering the site — and the two panic buttons, all made at
| the 2,000-lot scale estate among twelve clients (`ScaleVolume`), each held to:
|
|   1. ITS CATALOGUE ENTRY (`ApiContract`) and its status;
|   2. ITS BUDGET — the count measured after the fixes, plus four, counted as a new request;
|   3. NO GROWTH — the platform built a second time, six more Heads of Security, and the
|      household given six more members, and every write made again: a count that grew is
|      a query per row.
|
| WHAT THIS DOES NOT COUNT. A panic's fan-out waits for its transaction to commit
| (`PushServiceProvider::afterCommit`), and a test's writes never commit, so the pushes,
| mails and texts are not in these counts: the production-shaped harness (tools/perf,
| m11-baseline) measures them on a real server. What is counted is the request's own work.
|
| The estate database is committed and shared: every member this file adds to Lot 47's
| household, and their invites, are taken back after each test.
|
*/

const WRITE_BUDGET_PIN = '739164';

beforeEach(function (): void {
    ScaleFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();

    ScaleFixture::boot();

    if (isset($this->writeWorld)) {
        $added = Resident::withRemoved()->where('household_id', $this->writeWorld['household'])->whereNotIn('id', $this->writeWorld['members_before'])->pluck('id');
        DB::connection('tenant')->table('resident_invites')->whereIn('resident_id', $added)->delete();
        DB::connection('tenant')->table('residents')->whereIn('id', $added)->delete();
    }
});

/**
 * The officer's and the resident's tokens at the scale estate, a supervisor with a PIN, and a
 * shift the officer has not started.
 *
 * @param  array<string, mixed>  $v
 * @return array<string, mixed>
 */
function writeBudgetWorld(array $v): array
{
    $guard = $v['scale_on_duty_guard'];
    $guardToken = app(DeviceEnrolment::class)->enrol($guard, 'Write budget handset')['token'];

    $supervisor = ScaleVolume::geminiUser(Role::DISPATCHER);
    app(SupervisorOverride::class)->setPin($supervisor, WRITE_BUDGET_PIN);

    ScaleFixture::boot();

    $unit = Unit::query()->where('reference', 'Lot 47')->firstOrFail();
    $household = Household::query()->where('unit_id', $unit->id)->firstOrFail();
    $primary = Resident::query()->where('household_id', $household->id)->where('is_primary', true)->orderBy('id')->firstOrFail();

    $account = ResidentAccount::query()->create([
        'tenant_id' => ScaleFixture::ESTATE, 'channel' => 'email', 'destination' => 'writes.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => (string) $primary->full_name, 'resident_id' => $primary->id, 'unit_id' => $unit->id,
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [
        'guard' => $guard,
        'guard_token' => $guardToken,
        'resident_token' => $account->createToken('resident-app:writes', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken,
        'supervisor' => $supervisor,
        'household' => $household->id,
        'members_before' => Resident::withRemoved()->where('household_id', $household->id)->pluck('id')->all(),
    ];
}

/** A shift the officer is rostered on now and has not started — what an override starts. */
function unstartedShift(object $guard): int
{
    return (int) DB::connection('mysql')->table('shifts')->insertGetId([
        'tenant_id' => (string) $guard->tenant_id, 'guard_id' => $guard->id, 'post_id' => $guard->post_id,
        'rostered_start' => now()->subMinutes(5), 'rostered_end' => now()->addHours(11), 'status' => 'rostered',
        'is_simulated' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * One write, as a new request, held to its catalogue entry.
 *
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 * @return array{response: TestResponse, count: int, repeats: array<string, int>}
 */
function writeCount(object $test, string $token, string $name, array $params, array $body, int $status): array
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    QueryBudget::asANewRequest();
    app('auth')->forgetGuards();

    $m = QueryBudget::measure(fn (): TestResponse => $test->withToken($token)->json($entry['method'], $uri, $body, [
        'Idempotency-Key' => 'writes-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String(),
    ]));

    ScaleFixture::boot();

    $m['result']->assertStatus($status);
    ApiContract::assertMatches($m['result'], $name);

    // The token's last-used stamp is the clock's doing, not the endpoint's (see `AppApiQueryBudgetTest`).
    $counted = array_filter($m['statements'], static fn (array $s): bool => ! str_starts_with(strtolower(ltrim($s['sql'])), 'update `personal_access_tokens` set `last_used_at`'));

    return ['response' => $m['result'], 'count' => count($counted), 'repeats' => $m['repeats']];
}

/**
 * Every write, in the order a household and an officer make them, with its count.
 *
 * @param  array<string, mixed>  $w
 * @return array<string, array{count: int, repeats: array<string, int>}>
 */
function everyWrite(object $test, array $w, string $tag): array
{
    $counts = [];
    $record = static function (string $label, array $m) use (&$counts): TestResponse {
        $counts[$label] = ['count' => $m['count'], 'repeats' => $m['repeats']];

        return $m['response'];
    };

    $r = $w['resident_token'];
    $g = $w['guard_token'];

    $added = $record('members.store', writeCount($test, $r, 'members.store', [], ['full_name' => 'Budget Member '.$tag, 'relationship' => 'child', 'phone' => '876 555 0100', 'email' =>'budget.'.$tag.'.'.bin2hex(random_bytes(3)).'@example.com'], 201));
    $member = (int) $added->json('id');

    // The resend timer: an invite may go again once its minute is up.
    test()->travel(2)->minutes();
    $record('members.invite', writeCount($test, $r, 'members.invite', ['member' => $member], [], 200));
    $record('members.invite_cancel', writeCount($test, $r, 'members.invite_cancel', ['member' => $member], [], 200));
    $record('members.destroy', writeCount($test, $r, 'members.destroy', ['member' => $member], [], 200));

    $record('incidents.store', writeCount($test, $g, 'incidents.store', [], ['kind' => 'Budget', 'severity' => 'low', 'location' => 'Gate', 'detail' => 'A budget incident, measured by M11 A2.'], 201));

    $record('shifts.clock_in_override', writeCount($test, $g, 'shifts.clock_in_override', ['shift' => unstartedShift($w['guard'])], [
        'supervisor' => $w['supervisor']->email, 'pin' => WRITE_BUDGET_PIN, 'reason' => 'biometric_failed', 'mock_location' => false,
    ], 200));

    $record('duress.store (resident)', writeCount($test, $r, 'duress.store', [], ['mode' => 'audible'], 201));
    $record('duress.store (guard)', writeCount($test, $g, 'duress.store', [], ['mode' => 'silent'], 201));
    $record('alerts.store (guard)', writeCount($test, $g, 'alerts.store', [], ['kind' => 'medical'], 201));

    return $counts;
}

/**
 * Every write, with its budget: the count measured on 25 September 2026 after the fixes, as a
 * new request, plus four.
 *
 * @return array<string, int>
 */
function writeBudgets(): array
{
    return [
        // +1 (M18H §7.2): the new member tells the register live — one row on the estate's live log.
        // +1 (M54 §2, 8 Oct 2026): the same person twice is refused, so the household's current members are asked first.
        'members.store' => 29,
        'members.invite' => 27,
        'members.invite_cancel' => 20,
        // +2 (M18H §7.2): the removal tells the register live, and so does the member's suspended account — two rows.
        'members.destroy' => 21,
        'incidents.store' => 16,
        'shifts.clock_in_override' => 31,
        'duress.store (resident)' => 17,
        'duress.store (guard)' => 16,
        'alerts.store (guard)' => 13,
    ];
}

/** Heads of Security covering the scale estate, each with a proven address (the factory's), so an override tells them. */
function headsAtTheScaleEstate(int $count): void
{
    $head = Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id;

    foreach (range(1, $count) as $n) {
        $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
        $user->syncRoles([Role::HEAD_OF_SECURITY]);
        EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => ScaleFixture::ESTATE, 'role_id' => $head, 'is_active' => true]);
    }
}

it('makes every write this unit touched at volume within its budget, and asks no more of a larger platform and a larger household', function (): void {
    $v = ScaleVolume::build();
    $w = writeBudgetWorld($v);
    $this->writeWorld = $w;

    // Somebody for an override to tell, before anything is counted: the first Head of Security
    // told costs the mail's own reads once, whoever and however many they are.
    headsAtTheScaleEstate(2);
    ScaleFixture::boot();

    // Once, uncounted: the first request of a process pays for what every later one reuses.
    everyWrite($this, $w, 'warm');
    $first = everyWrite($this, $w, 'first');

    // Twice the platform, six more Heads of Security at the site, and six more members at home.
    ScaleVolume::build();
    headsAtTheScaleEstate(6);
    ScaleFixture::boot();
    foreach (range(1, 6) as $n) {
        $resident = new Resident;
        $resident->forceFill(['household_id' => $w['household'], 'full_name' => 'Budget Relative '.$n, 'relationship' => 'relative', 'is_primary' => false, 'status' => Resident::PENDING])->save();
    }

    // Giving out a role empties the permission cache, as it does on the server; one uncounted pass
    // fills it again, so the second count is the writes' own and not the cache's.
    everyWrite($this, $w, 'rewarm');
    $second = everyWrite($this, $w, 'second');

    foreach (writeBudgets() as $label => $budget) {
        expect($first[$label]['count'])->toBeLessThanOrEqual($budget, "{$label} asked {$first[$label]['count']} queries against a budget of {$budget}")
            ->and($second[$label]['count'])->toBeLessThanOrEqual($first[$label]['count'], "{$label} asked {$first[$label]['count']} queries, and {$second[$label]['count']} of a larger platform: ".json_encode($second[$label]['repeats']));
    }
});
