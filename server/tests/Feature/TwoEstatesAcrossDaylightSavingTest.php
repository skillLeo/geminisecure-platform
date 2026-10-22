<?php

declare(strict_types=1);

use App\Console\Commands\PushReminders;
use App\Models\Estate\Ballot;
use App\Models\Invoice;
use App\Services\Push\PushAnnouncer;
use App\Support\LocalSchedule;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| TWO ESTATES, TWO ZONES, ACROSS A DAYLIGHT-SAVING CHANGE, AT ONCE (M11 §4.4)
|--------------------------------------------------------------------------
|
| Kingston keeps no daylight saving; New York does, and on Sunday 1 November 2026 at 02:00 its clocks go back
| from EDT (UTC−4) to EST (UTC−5). Jamaica not observing it is exactly why an offset hard-coded anywhere would
| hide until the first estate somewhere else. So everything here is done for both estates at the same moment,
| on the Friday before the change and the Monday after:
|
|   a shift at 07:00, a visitor window 18:00–22:00, a meeting at 19:00, a ballot closing at 20:00
|       stored as the right UTC instant for each date, read back at the same wall-clock time, and — the ballot,
|       the window — open or closed at the right moment
|   an invoice due on a date                  a date, never shifted, whatever the zone
|   the arrears run at 09:00                  fires at each estate's own 09:00 on both days, once per its date
|   "today"                                   each estate's own calendar date, late in its evening
|
| Rolled back; the estates are central rows only — nothing here reads an estate's database.
|
*/

const TZ_KINGSTON = 'tzkingston';
const TZ_NEW_YORK = 'tznewyork';
const BEFORE = '2026-10-30'; // Friday, EDT in New York (UTC−4)
const AFTER = '2026-11-02'; // Monday, EST in New York (UTC−5)

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();

    foreach ([TZ_KINGSTON => 'America/Jamaica', TZ_NEW_YORK => 'America/New_York'] as $id => $zone) {
        DB::table('tenants')->insert(['id' => $id, 'name' => $id, 'status' => 'active', 'timezone' => $zone, 'created_at' => now(), 'updated_at' => now()]);
    }

    SiteTime::flush();
});

afterEach(function () {
    Carbon::setTestNow();
    SiteTime::flush();
    DB::connection('mysql')->rollBack();
});

/** What the office typed at an estate, as the UTC instant stored. */
function typedAt(string $estate, string $localDateTime): string
{
    return SiteTime::parse($localDateTime, $estate)->format('Y-m-d H:i');
}

/** A stored instant, read back on an estate's clock. */
function readAt(string $estate, string $utc): string
{
    return SiteTime::local(Carbon::parse($utc, 'UTC'), $estate)->format('Y-m-d H:i T');
}

describe('an instant typed at the estate is stored in UTC, and read back at the time that was typed', function () {
    it('a 07:00 shift', function () {
        expect(typedAt(TZ_KINGSTON, BEFORE.' 07:00'))->toBe(BEFORE.' 12:00')
            ->and(typedAt(TZ_KINGSTON, AFTER.' 07:00'))->toBe(AFTER.' 12:00')
            ->and(typedAt(TZ_NEW_YORK, BEFORE.' 07:00'))->toBe(BEFORE.' 11:00') // EDT
            ->and(typedAt(TZ_NEW_YORK, AFTER.' 07:00'))->toBe(AFTER.' 12:00'); // EST

        expect(readAt(TZ_NEW_YORK, BEFORE.' 11:00'))->toBe(BEFORE.' 07:00 EDT')
            ->and(readAt(TZ_NEW_YORK, AFTER.' 12:00'))->toBe(AFTER.' 07:00 EST')
            ->and(readAt(TZ_KINGSTON, AFTER.' 12:00'))->toBe(AFTER.' 07:00 EST');
    });

    it('a visitor window 18:00–22:00 stays 18:00–22:00 at the estate through the change, and admits at the right moment', function () {
        foreach ([BEFORE, AFTER] as $day) {
            foreach ([TZ_KINGSTON, TZ_NEW_YORK] as $estate) {
                $from = SiteTime::parse("{$day} 18:00", $estate);
                $to = SiteTime::parse("{$day} 22:00", $estate);

                expect(SiteTime::local($from, $estate)->format('H:i'))->toBe('18:00')
                    ->and(SiteTime::local($to, $estate)->format('H:i'))->toBe('22:00');

                // The gate compares instants (GateController: valid_from / valid_to timestamps).
                $admits = static fn (Carbon $at): bool => $from->getTimestamp() <= $at->getTimestamp() && $to->getTimestamp() >= $at->getTimestamp();
                expect($admits(SiteTime::parse("{$day} 17:59", $estate)))->toBeFalse("{$estate} {$day} 17:59")
                    ->and($admits(SiteTime::parse("{$day} 18:00", $estate)))->toBeTrue("{$estate} {$day} 18:00")
                    ->and($admits(SiteTime::parse("{$day} 22:01", $estate)))->toBeFalse("{$estate} {$day} 22:01");
            }
        }

        // The same local window is a different UTC window in New York either side, and the same in Kingston.
        expect(typedAt(TZ_NEW_YORK, BEFORE.' 18:00'))->toBe(BEFORE.' 22:00')
            ->and(typedAt(TZ_NEW_YORK, AFTER.' 18:00'))->toBe(AFTER.' 23:00')
            ->and(typedAt(TZ_KINGSTON, BEFORE.' 18:00'))->toBe(BEFORE.' 23:00')
            ->and(typedAt(TZ_KINGSTON, AFTER.' 18:00'))->toBe(AFTER.' 23:00');
    });

    it('a time the change skips or doubles is read as the phone reads it (packages/core/lib/siteTime.ts)', function () {
        // 02:30 on 8 March never happens in New York: forward to 03:30 EDT. 01:30 on 1 November happens twice: the first.
        expect(typedAt(TZ_NEW_YORK, '2026-03-08 02:30'))->toBe('2026-03-08 07:30')
            ->and(typedAt(TZ_NEW_YORK, '2026-11-01 01:30'))->toBe('2026-11-01 05:30')
            ->and(typedAt(TZ_KINGSTON, '2026-03-08 02:30'))->toBe('2026-03-08 07:30');
    });

    it('a meeting at 19:00', function () {
        expect(readAt(TZ_NEW_YORK, SiteTime::parse(BEFORE.' 19:00', TZ_NEW_YORK)->format('Y-m-d H:i')))->toBe(BEFORE.' 19:00 EDT')
            ->and(readAt(TZ_NEW_YORK, SiteTime::parse(AFTER.' 19:00', TZ_NEW_YORK)->format('Y-m-d H:i')))->toBe(AFTER.' 19:00 EST')
            ->and(readAt(TZ_KINGSTON, SiteTime::parse(AFTER.' 19:00', TZ_KINGSTON)->format('Y-m-d H:i')))->toBe(AFTER.' 19:00 EST');
    });

    it('a ballot closing at 20:00 closes at 20:00 at each estate, on both days', function () {
        foreach ([BEFORE, AFTER] as $day) {
            foreach ([TZ_KINGSTON, TZ_NEW_YORK] as $estate) {
                $ballot = (new Ballot)->forceFill(['stage' => Ballot::VOTING_OPEN, 'opens_at' => SiteTime::parse("{$day} 08:00", $estate), 'closes_at' => SiteTime::parse("{$day} 20:00", $estate)]);

                expect($ballot->isOpenForVoting(SiteTime::parse("{$day} 19:59", $estate)))->toBeTrue("{$estate} {$day} 19:59")
                    ->and($ballot->isOpenForVoting(SiteTime::parse("{$day} 20:00", $estate)))->toBeFalse("{$estate} {$day} 20:00");
            }
        }
    });
});

describe('the spring-forward too, and a shift that spans each change (M19A §8.6, §10.5)', function () {
    // Sunday 8 March 2026, 02:00 EST → 03:00 EDT in New York; Kingston does not move.
    $springBefore = '2026-03-06'; // Friday, EST (UTC−5)
    $springAfter = '2026-03-09'; // Monday, EDT (UTC−4)

    it('a 07:00 shift and a 19:00 meeting read back at 07:00 and 19:00 either side of the spring-forward', function () use ($springBefore, $springAfter) {
        expect(typedAt(TZ_NEW_YORK, $springBefore.' 07:00'))->toBe($springBefore.' 12:00') // EST
            ->and(typedAt(TZ_NEW_YORK, $springAfter.' 07:00'))->toBe($springAfter.' 11:00') // EDT
            ->and(typedAt(TZ_KINGSTON, $springBefore.' 07:00'))->toBe($springBefore.' 12:00')
            ->and(typedAt(TZ_KINGSTON, $springAfter.' 07:00'))->toBe($springAfter.' 12:00');

        foreach ([$springBefore, $springAfter] as $day) {
            foreach ([TZ_KINGSTON, TZ_NEW_YORK] as $estate) {
                foreach (['07:00', '19:00'] as $time) {
                    expect(SiteTime::local(SiteTime::parse("{$day} {$time}", $estate), $estate)->format('Y-m-d H:i'))->toBe("{$day} {$time}", "{$estate} {$day} {$time}");
                }
            }
        }

        // The meeting's label says whose clock, and the digits carry the change: 7:00 PM NY on both evenings.
        expect(SiteTime::stamp(SiteTime::parse($springBefore.' 19:00', TZ_NEW_YORK), TZ_NEW_YORK, 'M j, g:i A'))->toBe('Mar 6, 7:00 PM NY')
            ->and(SiteTime::stamp(SiteTime::parse($springAfter.' 19:00', TZ_NEW_YORK), TZ_NEW_YORK, 'M j, g:i A'))->toBe('Mar 9, 7:00 PM NY');
    });

    it('an overnight shift 00:00–08:00 across each change is the hours that really pass: 7 in spring, 9 in autumn, 8 in Kingston', function () {
        $hours = static fn (string $estate, string $day): float => SiteTime::parse("{$day} 00:00", $estate)->diffInMinutes(SiteTime::parse("{$day} 08:00", $estate)) / 60;

        expect($hours(TZ_NEW_YORK, '2026-03-08'))->toBe(7.0)
            ->and($hours(TZ_NEW_YORK, '2026-11-01'))->toBe(9.0)
            ->and($hours(TZ_KINGSTON, '2026-03-08'))->toBe(8.0)
            ->and($hours(TZ_KINGSTON, '2026-11-01'))->toBe(8.0);

        // And both ends still read as the roster says, on the estate's clock.
        expect(readAt(TZ_NEW_YORK, typedAt(TZ_NEW_YORK, '2026-03-08 08:00')))->toBe('2026-03-08 08:00 EDT')
            ->and(readAt(TZ_NEW_YORK, typedAt(TZ_NEW_YORK, '2026-11-01 08:00')))->toBe('2026-11-01 08:00 EST');
    });
});

describe('Gemini\'s screens list every estate, each on its own clock, and every time says whose (§4.1; M19A §4.2, §7)', function () {
    it('a New York time says NY on both sides of the change, and a Kingston time says JA — Gemini\'s own clock too', function () {
        $shift = static fn (string $estate, string $day): string => SiteTime::stamp(SiteTime::parse("{$day} 07:00", $estate), $estate, 'M j, g:i A');

        // Daylight saving is in the digits (the zone's rules), not the label: 7:00 AM is 7:00 AM at the gate both days.
        expect($shift(TZ_NEW_YORK, BEFORE))->toBe('Oct 30, 7:00 AM NY')
            ->and($shift(TZ_NEW_YORK, AFTER))->toBe('Nov 2, 7:00 AM NY')
            ->and($shift(TZ_KINGSTON, BEFORE))->toBe('Oct 30, 7:00 AM JA')
            ->and($shift(TZ_KINGSTON, AFTER))->toBe('Nov 2, 7:00 AM JA');
    });

    it('the doubled hour is told apart by the zone\'s own abbreviation, and only that hour', function () {
        // 01:30 on 1 November happens twice in New York: 05:30 UTC (EDT) and 06:30 UTC (EST).
        expect(SiteTime::stampIn(Carbon::parse('2026-11-01 05:30', 'UTC'), 'America/New_York', 'g:i A'))->toBe('1:30 AM NY (EDT)')
            ->and(SiteTime::stampIn(Carbon::parse('2026-11-01 06:30', 'UTC'), 'America/New_York', 'g:i A'))->toBe('1:30 AM NY (EST)')
            ->and(SiteTime::stampIn(Carbon::parse('2026-11-01 08:30', 'UTC'), 'America/New_York', 'g:i A'))->toBe('3:30 AM NY');
    });

    it('a zone nobody recognises falls back to the platform\'s, and still says whose', function () {
        expect(SiteTime::stampIn(Carbon::parse(AFTER.' 12:00', 'UTC'), 'Not/AZone', 'g:i A'))->toBe('7:00 AM JA')
            ->and(SiteTime::stampIn(Carbon::parse(AFTER.' 12:00', 'UTC'), null, 'g:i A'))->toBe('7:00 AM JA');
    });

    it('"my time" reads every stamp on the viewer\'s clock, with their tag (M19A §7)', function () {
        SiteTime::viewAs('Asia/Karachi');

        try {
            expect(SiteTime::stampIn(Carbon::parse(AFTER.' 12:00', 'UTC'), 'America/Jamaica', 'g:i A'))->toBe('5:00 PM KA');
        } finally {
            SiteTime::viewAs(null);
        }

        expect(SiteTime::stampIn(Carbon::parse(AFTER.' 12:00', 'UTC'), 'America/Jamaica', 'g:i A'))->toBe('7:00 AM JA');
    });
});

describe('reminders reach an estate in its waking hours, and name the day by its calendar (§4.2)', function () {
    it('the hourly run reminds each estate only between 08:00 and 20:59 on its own clock', function () {
        // 07:30 UTC on the Monday: 02:30 in both estates (EST now) — night; 13:30 UTC: 08:30 — morning.
        $awake = static fn (string $utc, string $estate): bool => PushReminders::awake(SiteTime::local(Carbon::parse($utc, 'UTC'), $estate));

        expect($awake(AFTER.' 07:30', TZ_NEW_YORK))->toBeFalse()
            ->and($awake(AFTER.' 13:30', TZ_NEW_YORK))->toBeTrue()
            ->and($awake(AFTER.' 13:30', TZ_KINGSTON))->toBeTrue()
            // 01:30 UTC on the Friday: 21:30 EDT on Thursday in New York, 20:30 in Kingston.
            ->and($awake(BEFORE.' 01:30', TZ_NEW_YORK))->toBeFalse()
            ->and($awake(BEFORE.' 01:30', TZ_KINGSTON))->toBeTrue();
    });

    it('a meeting this evening is "today", and one tomorrow evening is "tomorrow", at each estate', function () {
        $nowNy = SiteTime::local(Carbon::parse(AFTER.' 15:00', 'UTC'), TZ_NEW_YORK); // 10:00 EST Monday

        expect(PushAnnouncer::reminderTitle(SiteTime::local(SiteTime::parse(AFTER.' 19:00', TZ_NEW_YORK), TZ_NEW_YORK), $nowNy))->toBe('Meeting today')
            ->and(PushAnnouncer::reminderTitle(SiteTime::local(SiteTime::parse('2026-11-03 09:00', TZ_NEW_YORK), TZ_NEW_YORK), $nowNy))->toBe('Meeting tomorrow');
    });
});

it('a due date is a date: never shifted by a zone', function () {
    $invoice = (new Invoice)->forceFill(['due_on' => AFTER]);

    expect($invoice->due_on->toDateString())->toBe(AFTER);

    // Even with the process itself on a zone fourteen hours either side of UTC, the date is the date.
    $was = date_default_timezone_get();

    try {
        foreach (['America/Jamaica', 'America/New_York', 'Pacific/Kiritimati', 'Pacific/Pago_Pago'] as $zone) {
            date_default_timezone_set($zone);
            expect((new Invoice)->forceFill(['due_on' => AFTER])->due_on->toDateString())->toBe(AFTER, $zone);
        }
    } finally {
        date_default_timezone_set($was);
    }
});

it('"today" is each estate\'s own date — at 23:30 in New York it is still that day there', function () {
    // 23:30 EST on Monday is 04:30 UTC on Tuesday.
    Carbon::setTestNow(Carbon::parse('2026-11-03 04:30', 'UTC'));

    expect(SiteTime::date(TZ_NEW_YORK)->toDateString())->toBe(AFTER)
        ->and(SiteTime::date(TZ_KINGSTON)->toDateString())->toBe(AFTER) // 23:30 in Kingston too
        ->and(SiteTime::date(TZ_NEW_YORK)->tzName)->toBe('UTC'); // comparable with a date column as it is read back
});

describe('the morning jobs fire at each estate\'s own hour (§4.2)', function () {
    /** Which estates the arrears run claims at this UTC moment. */
    function claimedAt(string $utc): array
    {
        $now = Carbon::parse($utc, 'UTC');

        return array_values(array_filter([TZ_KINGSTON, TZ_NEW_YORK], static fn (string $estate): bool => LocalSchedule::claim('dunning:run', $estate, '09:00', $now)));
    }

    it('before the change: New York at 13:00 UTC (09:00 EDT), Kingston at 14:00 UTC (09:00 EST)', function () {
        expect(claimedAt(BEFORE.' 12:45'))->toBe([])
            ->and(claimedAt(BEFORE.' 13:00'))->toBe([TZ_NEW_YORK])
            ->and(claimedAt(BEFORE.' 13:45'))->toBe([])
            ->and(claimedAt(BEFORE.' 14:00'))->toBe([TZ_KINGSTON])
            ->and(claimedAt(BEFORE.' 18:00'))->toBe([]); // once per date
    });

    it('after the change: both at 14:00 UTC — each at its own 09:00, New York\'s having moved with its clocks', function () {
        expect(claimedAt(AFTER.' 13:00'))->toBe([]) // 08:00 in New York now
            ->and(claimedAt(AFTER.' 14:00'))->toBe([TZ_KINGSTON, TZ_NEW_YORK]);
    });

    it('a failed run gives the day back, and the next tick runs it', function () {
        $at = Carbon::parse(AFTER.' 14:00', 'UTC');

        expect(LocalSchedule::claim('dunning:run', TZ_NEW_YORK, '09:00', $at))->toBeTrue();
        LocalSchedule::release('dunning:run', TZ_NEW_YORK, $at);
        expect(LocalSchedule::claim('dunning:run', TZ_NEW_YORK, '09:00', $at->copy()->addMinutes(15)))->toBeTrue()
            ->and(LocalSchedule::claim('dunning:run', TZ_NEW_YORK, '09:00', $at->copy()->addMinutes(30)))->toBeFalse();
    });

    it('a dry run looks without spending the day', function () {
        $at = Carbon::parse(AFTER.' 14:00', 'UTC');

        expect(LocalSchedule::due('dunning:run', TZ_NEW_YORK, '09:00', $at))->toBeTrue()
            ->and(LocalSchedule::due('dunning:run', TZ_NEW_YORK, '09:00', $at))->toBeTrue()
            ->and(LocalSchedule::claim('dunning:run', TZ_NEW_YORK, '09:00', $at))->toBeTrue()
            ->and(LocalSchedule::due('dunning:run', TZ_NEW_YORK, '09:00', $at))->toBeFalse();
    });

    it('the schedule ticks both jobs every fifteen minutes with their local hour — no fixed zone left in it', function () {
        $console = (string) file_get_contents(base_path('routes/console.php'));

        expect($console)->toContain("Schedule::command('dunning:run --at=09:00')")
            ->and($console)->toContain("Schedule::command('amenities:settle --at=06:30')")
            ->and($console)->not->toContain("->timezone('America/Jamaica')");
    });
});
