<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Guard\ShiftsController;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The night minutes counted in closed form are the minutes the walk counts — M11 A2
|--------------------------------------------------------------------------
|
| `GET /shifts/me` reports the hours of each shift worked between 22:00 and 06:00 on the
| site's clock. It used to find them by stepping through the shift a minute at a time —
| 720 steps of date arithmetic for a twelve-hour watch, three hundred milliseconds for a
| month of them. `nightMinutesCounted()` answers in closed form wherever the zone keeps one
| offset through the shift, and hands back to the walk wherever it does not.
|
| The walk is kept, and it is the oracle: for 120 intervals in each of five zones — whole
| watches, short spans, microsecond ends, midnight crossings, thirty-hour spans; a zone with
| no DST, UTC, a half-hour offset, and two whose clocks change inside some intervals — the
| answer must be the walk's, to the minute.
|
*/

function nightByWalk(Carbon $from, Carbon $end): int
{
    return (new ReflectionMethod(ShiftsController::class, 'nightMinutesWalked'))->invoke(null, $from->copy(), $end);
}

function nightByCount(Carbon $from, Carbon $end): ?int
{
    return (new ReflectionMethod(ShiftsController::class, 'nightMinutesCounted'))->invoke(null, $from->copy(), $end);
}

it('counts the same night minutes as the minute-by-minute walk', function (string $zone): void {
    mt_srand(20260924);

    $checked = 0;
    $counted = 0;

    for ($i = 0; $i < 120; $i++) {
        // A start anywhere in 2026, on a whole minute of the site's clock, as `nightHours()` makes it.
        $start = Carbon::createFromTimestamp(1767225600 + mt_rand(0, 365 * 86400), 'UTC')
            ->setTimezone($zone)
            ->startOfMinute();

        // Mostly watch-length, some short, some a day and more — and an end to the microsecond.
        // (The walk is the slow half of every case; that is the reason for the closed form.)
        $seconds = match ($i % 5) {
            0 => mt_rand(0, 3600),
            1, 2 => mt_rand(8 * 3600, 13 * 3600),
            default => mt_rand(20 * 3600, 30 * 3600),
        };
        $end = Carbon::createFromTimestamp($start->getTimestamp() + $seconds, 'UTC')->addMicroseconds(mt_rand(0, 999_999) * ($i % 3 === 0 ? 1 : 0));

        $fast = nightByCount($start, $end);
        $walk = nightByWalk($start, $end);

        // Where the closed form declines — an offset change inside — `nightHours()` walks.
        expect($fast ?? $walk)->toBe($walk, "{$zone}: {$start->toIso8601String()} to {$end->toIso8601String()}");

        $checked++;
        $counted += $fast === null ? 0 : 1;
    }

    expect($checked)->toBe(120)->and($counted)->toBeGreaterThan(0);
})->with(['America/Jamaica', 'UTC', 'Asia/Kolkata', 'America/New_York', 'Europe/London']);

it('declines, rather than guesses, when the site\'s clock changes inside the interval', function (): void {
    // The night the clocks go forward in New York: 02:00 EST becomes 03:00 EDT.
    $start = Carbon::parse('2026-03-07 20:00:00', 'America/New_York');
    $end = Carbon::parse('2026-03-08 08:00:00', 'America/New_York')->utc();

    expect(nightByCount($start, $end))->toBeNull()
        // And the walk's answer is what the shift reports.
        ->and(nightByWalk($start, $end))->toBeGreaterThan(0);
});

it('counts the board\'s own cases exactly', function (): void {
    $zone = 'America/Jamaica';
    $at = static fn (string $local): Carbon => Carbon::parse($local, $zone);

    // A night watch, 18:00 to 06:00: eight hours between 22:00 and 06:00.
    expect(nightByCount($at('2026-09-20 18:00'), $at('2026-09-21 06:00')->utc()))->toBe(480)
        // A day watch, 06:00 to 18:00: none.
        ->and(nightByCount($at('2026-09-20 06:00'), $at('2026-09-20 18:00')->utc()))->toBe(0)
        // 21:59 to 22:01: the one minute from 22:00.
        ->and(nightByCount($at('2026-09-20 21:59'), $at('2026-09-20 22:01')->utc()))->toBe(1)
        // An end a microsecond past a whole minute still counts that minute's start, as the walk does.
        ->and(nightByCount($at('2026-09-20 23:00'), $at('2026-09-20 23:01')->utc()->addMicrosecond()))->toBe(2)
        // Nothing worked, nothing counted.
        ->and(nightByCount($at('2026-09-20 23:00'), $at('2026-09-20 23:00')->utc()))->toBe(0);
});
