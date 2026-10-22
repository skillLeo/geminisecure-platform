<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| Every estate's clock is read in one query, not one per estate — M11 A2
|--------------------------------------------------------------------------
|
| The Gemini console prints every row on the clock of the estate it belongs to, and a
| PHP-FPM worker starts each request remembering no zone at all — so `SiteTime::zone()`
| asked `tenants` once for each estate a page met: twelve queries on the dashboard, the
| coverage board and the roster at twelve clients. It now reads every estate's zone the
| first time any is asked for.
|
| The oracle is the old per-estate read: each estate's own `timezone`, made valid. Every
| zone must be what that read gave, for estates on another clock, on none, and on a name
| that is no zone at all.
|
*/

beforeEach(function (): void {
    DB::connection('mysql')->beginTransaction();
    SiteTime::flush();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
});

/** @return list<string> the estates made, each on a clock of its own */
function estatesOnClocks(array $zones): array
{
    $now = Carbon::now();
    $tag = (string) random_int(100000, 999999);
    $ids = [];

    foreach ($zones as $n => $zone) {
        $ids[] = $id = 'm11zone'.$tag.'n'.$n;

        DB::connection('mysql')->table('tenants')->insert([
            'id' => $id, 'name' => 'Clock Estate '.$tag.' '.$n, 'status' => 'active', 'demo' => 0, 'data' => '{}',
            'timezone' => $zone, 'provisioned_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    return $ids;
}

function zoneByTheOldRead(string $tenantId): string
{
    return SiteTime::valid(Tenant::query()->whereKey($tenantId)->value('timezone'));
}

it('gives every estate the zone its own row gives, from one read of them all', function (): void {
    $ids = estatesOnClocks(['America/Jamaica', 'America/New_York', 'Asia/Kolkata', 'UTC', 'Europe/London', 'Not/AZone', '', 'America/Port_of_Spain', 'Pacific/Chatham', 'America/Jamaica', 'Australia/Lord_Howe', 'America/St_Lucia']);

    $read = QueryBudget::measure(fn (): array => array_map(static fn (string $id): string => SiteTime::zone($id), $ids));

    expect($read['count'])->toBe(1)
        ->and($read['result'])->toBe(array_map(zoneByTheOldRead(...), $ids))
        // The two that name no zone fall back to the platform's, as they always did.
        ->and($read['result'][5])->toBe(SiteTime::platformZone())
        ->and($read['result'][6])->toBe(SiteTime::platformZone())
        ->and($read['result'][8])->toBe('Pacific/Chatham');

    // Asked again, nothing is asked.
    expect(QueryBudget::measure(fn (): string => SiteTime::zone($ids[3]))['count'])->toBe(0);
});

it('still finds an estate made after the read, a missing one, and a moved clock', function (): void {
    [$first] = estatesOnClocks(['Asia/Kolkata']);
    expect(SiteTime::zone($first))->toBe('Asia/Kolkata');

    // Made after every zone was read, by a query that fires no event: read on its own.
    [$later] = estatesOnClocks(['America/New_York']);
    $found = QueryBudget::measure(fn (): string => SiteTime::zone($later));
    expect($found['result'])->toBe('America/New_York')->and($found['count'])->toBe(1);

    // An estate that does not exist is on the platform's clock.
    expect(SiteTime::zone('m11-no-such-estate'))->toBe(SiteTime::platformZone());

    // A saved estate forgets what was read, and the next zone is its new one.
    $estate = Tenant::query()->findOrFail($first);
    $estate->timezone = 'Europe/London';
    $estate->save();

    $moved = QueryBudget::measure(fn (): string => SiteTime::zone($first));
    expect($moved['result'])->toBe('Europe/London')->and($moved['count'])->toBe(1);
});
