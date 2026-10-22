<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\User;
use App\Services\Gemini\PlatformNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| "Mark all read" is one statement, and leaves the rows the loop left — M11 A2
|--------------------------------------------------------------------------
|
| The bell's "mark all read" sends every key on the list, up to two hundred, and each key
| was a look-up and then an insert or an update of its own. It is one upsert on the table's
| unique key now. The oracle is the old loop, written out here: the same keys, marked by
| each for a different person at the same moment, must leave the same rows — first marks
| and re-marks alike — and the same count.
|
*/

beforeEach(function (): void {
    DB::connection('mysql')->beginTransaction();
});

afterEach(function (): void {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
});

/** What `markRead()` did before M11: a look-up and a write per key. */
function marksByTheOldLoop(User $viewer, array $keys): int
{
    $now = Carbon::now();
    $marked = 0;

    foreach (array_unique($keys) as $key) {
        if (! is_string($key) || $key === '' || strlen($key) > 64) {
            continue;
        }

        DB::connection('mysql')->table('platform_notification_reads')->updateOrInsert(
            ['user_id' => $viewer->getKey(), 'item_key' => $key],
            ['read_at' => $now, 'updated_at' => $now, 'created_at' => $now],
        );

        $marked++;
    }

    return $marked;
}

/** @return list<array{item_key: string, read_at: string, created_at: string, updated_at: string}> */
function marksOf(User $viewer): array
{
    return DB::connection('mysql')->table('platform_notification_reads')
        ->where('user_id', $viewer->getKey())
        ->orderBy('item_key')
        ->get(['item_key', 'read_at', 'created_at', 'updated_at'])
        ->map(static fn (object $row): array => (array) $row)
        ->all();
}

it('leaves exactly the rows the per-key loop left, marking and marking again', function (): void {
    $viaLoop = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $viaUpsert = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $bystander = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);

    Carbon::setTestNow('2026-09-24 09:15:00');
    marksByTheOldLoop($bystander, ['alert:1:open', 'invoice:4']);
    $theirs = marksOf($bystander);

    $first = ['alert:12:open', 'licence:7:2026-10-02', 'incident:3', 'alert:12:open', '', str_repeat('x', 65), 42, null, 'request:9'];

    Carbon::setTestNow('2026-09-24 10:00:00');
    $loopCount = marksByTheOldLoop($viaLoop, $first);
    $upsertCount = app(PlatformNotifications::class)->markRead($viaUpsert, $first);

    expect($upsertCount)->toBe($loopCount)->toBe(4)
        ->and(marksOf($viaUpsert))->toBe(marksOf($viaLoop));

    // Later, some of the same again and some new: a re-mark moves all three stamps, as the
    // loop's update did.
    $again = ['incident:3', 'request:9', 'filing:2', 'override:88', 'filing:2'];

    Carbon::setTestNow('2026-09-24 16:45:30');
    $loopCount = marksByTheOldLoop($viaLoop, $again);
    $upsertCount = app(PlatformNotifications::class)->markRead($viaUpsert, $again);

    expect($upsertCount)->toBe($loopCount)->toBe(4)
        ->and(marksOf($viaUpsert))->toBe(marksOf($viaLoop))
        ->and(collect(marksOf($viaUpsert))->pluck('item_key')->all())->toBe(['alert:12:open', 'filing:2', 'incident:3', 'licence:7:2026-10-02', 'override:88', 'request:9'])
        // Only the marker's own rows move.
        ->and(marksOf($bystander))->toBe($theirs);
});

it('marks two hundred keys in one statement', function (): void {
    $viewer = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $keys = array_map(static fn (int $n): string => 'alert:'.$n.':open', range(1, 200));

    app(PlatformNotifications::class)->markRead($viewer, array_slice($keys, 0, 50));
    $measured = QueryBudget::measure(fn () => app(PlatformNotifications::class)->markRead($viewer, $keys));

    expect($measured['result'])->toBe(200)
        ->and($measured['count'])->toBe(1)
        ->and(count(marksOf($viewer)))->toBe(200);

    // And nothing to mark is nothing asked.
    expect(QueryBudget::measure(fn () => app(PlatformNotifications::class)->markRead($viewer, ['', null]))['count'])->toBe(0);
});
