<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\DuressAlert;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use App\Services\Dispatch\PanicWatch;
use App\Services\Dispatch\SiteEscalation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| "May this account reach these estates?" — asked once, not once a row (M11 A2)
|--------------------------------------------------------------------------
|
| `User::canAccessEstate()` is the one question every tenant-scoped read asks, and two
| places asked it once a row: the sounding siren's poll (`PanicWatch::stillAwaiting`),
| once for each of up to fifty alerts every few seconds, and the Heads of Security a
| panic, an override or an auto-closed shift is reported to, twice for each of them.
| `canAccessEstates()` answers for many estates and `whoCanAccessEstate()` for many
| accounts, each in one read, and `canAccessEstate()` is now the first of them for one.
|
| TENANT ISOLATION RESTS ON THIS ANSWER, so the oracle is the rule as it stood before M11,
| written out here: for every account — a Director, a Head of Security assigned, unassigned,
| assigned only by an inactive row, suspended, or holding a site row that carries an ESTATE
| role (B067), an Admin Assistant, a committee member, a committee member elsewhere and a
| suspended one — and every estate, all three answers must be the old one.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/** `User::canAccessEstate()` as it stood before M11 A2 (a342c56). */
function canAccessByTheOldRule(User $user, string $tenantId): bool
{
    if ($user->status !== 'active') {
        return false;
    }

    $rows = $user->assignments()->where('is_active', true);

    if ($user->console === Console::Gemini) {
        $rows->whereHas('role', static fn ($role) => $role->where('console', Console::Gemini->value));
    }

    $hasAssignment = $rows->where('tenant_id', $tenantId)->exists();

    if ($user->console === Console::Estate) {
        return $hasAssignment;
    }

    return $user->widestScope() === AccessScope::All || $hasAssignment;
}

/**
 * Every kind of account the rule tells apart, and the estates they are asked about.
 *
 * @return array{users: array<string, User>, estates: list<string>}
 */
function accessWorld(): array
{
    $tag = (string) random_int(100000, 999999);
    [$a, $b, $c] = ['m11acc'.$tag.'a', 'm11acc'.$tag.'b', 'm11acc'.$tag.'c'];

    $gemini = static function (string $role, string $status = 'active'): User {
        $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => $status]);
        $user->syncRoles([$role]);

        return $user;
    };

    $assign = static function (User $user, string $tenant, int $roleId, bool $active = true): void {
        EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => $tenant, 'role_id' => $roleId, 'is_active' => $active]);
    };

    $head = Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id;

    $estateRole = Role::findOrCreate('test.m11_access_committee', 'web');
    $estateRole->forceFill(['console' => Console::Estate->value])->save();

    $estateUser = static function (string $status = 'active') use ($estateRole): User {
        $user = User::factory()->create(['console' => Console::Estate->value, 'status' => $status]);
        $user->syncRoles([$estateRole->name]);

        return $user;
    };

    $users = [
        'director' => $gemini(Role::DIRECTOR),
        'admin assistant' => $gemini(Role::ADMIN_ASSISTANT),
        'head at a and b' => $gemini(Role::HEAD_OF_SECURITY),
        'head unassigned' => $gemini(Role::HEAD_OF_SECURITY),
        'head inactive row' => $gemini(Role::HEAD_OF_SECURITY),
        'head suspended' => $gemini(Role::HEAD_OF_SECURITY, 'suspended'),
        'head with an estate role row' => $gemini(Role::HEAD_OF_SECURITY),
        'committee at a' => $estateUser(),
        'committee at c' => $estateUser(),
        'committee suspended' => $estateUser('suspended'),
    ];

    $assign($users['head at a and b'], $a, $head);
    $assign($users['head at a and b'], $b, $head);
    $assign($users['head inactive row'], $a, $head, active: false);
    $assign($users['head suspended'], $a, $head);
    $assign($users['head with an estate role row'], $c, $estateRole->id);
    $assign($users['committee at a'], $a, $estateRole->id);
    $assign($users['committee at c'], $c, $estateRole->id);
    $assign($users['committee suspended'], $a, $estateRole->id);
    // The Director's own site row changes nothing: their scope is every estate.
    $assign($users['director'], $b, $head);

    return [
        'users' => array_map(static fn (User $user): User => $user->fresh(), $users),
        'estates' => [$a, $b, $c, 'm11acc'.$tag.'nowhere'],
    ];
}

it('answers every account about every estate exactly as the rule always did, one at a time, many at a time, and for many accounts at once', function (): void {
    $w = accessWorld();
    $yes = 0;
    $no = 0;

    foreach ($w['users'] as $label => $user) {
        $many = $user->canAccessEstates($w['estates']);

        foreach ($w['estates'] as $estate) {
            $old = canAccessByTheOldRule($user, $estate);
            $old ? $yes++ : $no++;

            expect($user->canAccessEstate($estate))->toBe($old, "{$label} at {$estate}, one estate")
                ->and($many[$estate])->toBe($old, "{$label} at {$estate}, many estates");
        }
    }

    // And for every account at once, at each estate: the same accounts, in the same order.
    $all = new Illuminate\Database\Eloquent\Collection(array_values(array_map(static fn (User $user): User => $user->fresh(), $w['users'])));

    foreach ($w['estates'] as $estate) {
        $expected = $all->filter(static fn (User $user): bool => canAccessByTheOldRule($user, $estate))->modelKeys();

        expect(User::whoCanAccessEstate($all, $estate)->modelKeys())->toBe($expected, "who may reach {$estate}");
    }

    // The comparison is only a proof if both answers were given, for every kind of account.
    expect($yes)->toBeGreaterThan(8)->and($no)->toBeGreaterThan(8)
        ->and($w['users']['head at a and b']->canAccessEstate($w['estates'][2]))->toBeFalse()
        ->and($w['users']['head with an estate role row']->canAccessEstate($w['estates'][2]))->toBeFalse()
        ->and($w['users']['committee at c']->canAccessEstate($w['estates'][2]))->toBeTrue()
        ->and($w['users']['director']->canAccessEstate($w['estates'][3]))->toBeTrue()
        ->and($w['users']['head suspended']->canAccessEstate($w['estates'][0]))->toBeFalse()
        ->and($w['users']['head inactive row']->canAccessEstate($w['estates'][0]))->toBeFalse();

    // Asked about nothing, nothing is read.
    expect(QueryBudget::measure(fn () => $w['users']['director']->canAccessEstates([]))['count'])->toBe(0)
        ->and(QueryBudget::measure(fn () => User::whoCanAccessEstate(new Illuminate\Database\Eloquent\Collection, $w['estates'][0]))['count'])->toBe(0);
});

it('answers the sounding siren about five alerts or fifty with the same reads', function (): void {
    $w = accessWorld();
    $watch = app(PanicWatch::class);
    $now = Carbon::now();

    $raise = static function (string $tenant) use ($now): int {
        return (int) DB::connection('mysql')->table('duress_alerts')->insertGetId([
            'tenant_id' => $tenant, 'kind' => 'panic', 'raised_by_name' => 'Siren Probe', 'unit_reference' => 'Lot 1',
            'status' => 'open', 'is_simulated' => false, 'is_drill' => false, 'server_time' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    };

    $asked = [];
    foreach (range(1, 5) as $n) {
        $asked[] = $raise($w['estates'][$n % 4]);
    }

    $head = $w['users']['head at a and b'];
    $count = static fn (array $ids): array => QueryBudget::measure(fn () => $watch->stillAwaiting($head->fresh(), $ids));

    $five = $count($asked);

    foreach (range(1, 45) as $n) {
        $asked[] = $raise($w['estates'][$n % 4]);
    }

    $fifty = $count($asked);

    // Only the alerts at the Head of Security's two estates, as the old per-alert question answered.
    $expected = DuressAlert::query()->whereKey($asked)->get()
        ->filter(static fn (DuressAlert $alert): bool => canAccessByTheOldRule($head, (string) $alert->tenant_id))
        ->map(static fn (DuressAlert $alert): int => (int) $alert->id)->sort()->values()->all();

    $answered = collect($fifty['result'])->sort()->values()->all();

    expect($answered)->toBe($expected)
        ->and(count($answered))->toBeGreaterThan(0)
        ->and(count($answered))->toBeLessThan(50)
        ->and($fifty['count'])->toBe($five['count']);
});

it('finds the Heads of Security a panic is reported to with the same reads for two of them or eight', function (): void {
    $w = accessWorld();
    $estate = $w['estates'][0];
    $head = Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id;
    $escalation = app(SiteEscalation::class);

    $count = static fn (): array => QueryBudget::measure(fn () => $escalation->headsOfSecurity($estate, null));
    $before = $count();

    foreach (range(1, 6) as $n) {
        $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
        $user->syncRoles([Role::HEAD_OF_SECURITY]);

        if ($n % 2 === 0) {
            EstateAssignment::create(['user_id' => $user->id, 'tenant_id' => $estate, 'role_id' => $head, 'is_active' => true]);
        }
    }

    $after = $count();

    $everyHead = User::query()->role(Role::HEAD_OF_SECURITY, 'web')->where('status', 'active')->get();
    $expected = $everyHead->filter(static fn (User $user): bool => canAccessByTheOldRule($user, $estate))->modelKeys();

    expect($after['result']->modelKeys())->toBe(array_values($expected))
        ->and(count($after['result']))->toBe(count($before['result']) + 3)
        ->and($after['count'])->toBe($before['count']);
});
