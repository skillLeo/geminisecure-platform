<?php

declare(strict_types=1);

use App\Models\Shift;
use App\Services\Dispatch\ShiftAutoClose;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| The hourly auto-close reads every site at once — M11 A2
|--------------------------------------------------------------------------
|
| `ShiftAutoClose::run()` walked the sites with a shift open, and for each read its hours
| and then its overdue shifts: two queries a site, and the hours again for every shift it
| closed — a cross-estate loop with a query each. It reads every site's hours in one query
| and every candidate shift in another now, and each site keeps what its own cutoff kept.
|
| The oracle is the old walk, written out here as the reads it made: for sites on the
| default, on their own hours, on a nonsense value the clamp corrects, and at the edge of
| each cutoff to the second — with the moment given in UTC and on the site's own clock —
| the same shifts must be closed, with the same hours written on them, in the same order.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    Notification::fake();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/** The shifts the old `run()` closed, in the order it closed them, with the hours it wrote. */
function autoClosedByTheOldRun(ShiftAutoClose $rule, Carbon $now): array
{
    $sites = Shift::query()->whereNotNull('actual_start')->whereNull('actual_end')->whereNotNull('tenant_id')->distinct()->pluck('tenant_id')->map(static fn ($id): string => (string) $id)->all();
    $closed = [];

    foreach ($sites as $tenantId) {
        $cutoff = $now->copy()->subHours($rule->hoursFor($tenantId));

        $ids = Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->where('rostered_end', '<', $cutoff)
            ->where('actual_start', '<', $cutoff)
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $closed[] = [(int) $id, $rule->hoursFor($tenantId)];
        }
    }

    return ['closed' => $closed, 'sites' => count($sites)];
}

/**
 * Sites on their own hours, each with shifts either side of its cutoff, to the second.
 *
 * @return list<string>
 */
function autoCloseWorld(Carbon $now, int $rounds = 1): array
{
    $db = DB::connection('mysql');
    $made = Carbon::now();
    $tag = (string) random_int(100000, 999999);
    $sites = [];

    foreach (range(1, $rounds) as $round) {
        foreach (['default' => null, 'eight' => 8, 'one' => 1, 'most' => 72, 'nonsense' => 0, 'text' => 'soon'] as $label => $hours) {
            $site = 'm11close'.$tag.$round.substr(md5($label), 0, 4);
            $sites[] = $site;

            $db->table('tenants')->insert([
                'id' => $site, 'name' => 'Close Estate '.$label.' '.$tag, 'status' => 'active', 'demo' => 0, 'timezone' => 'America/Jamaica',
                'data' => json_encode($hours === null ? [] : [ShiftAutoClose::SITE_KEY => $hours]), 'provisioned_at' => $made, 'created_at' => $made, 'updated_at' => $made,
            ]);

            $post = (int) $db->table('posts')->insertGetId(['tenant_id' => $site, 'name' => 'Gate '.$label, 'type' => 'gate', 'is_active' => true, 'created_at' => $made, 'updated_at' => $made]);

            $n = 0;
            $clamped = max(ShiftAutoClose::MIN_HOURS, min(ShiftAutoClose::MAX_HOURS, is_int($hours) ? $hours : 4));
            $cutoff = $now->copy()->utc()->subHours($clamped)->startOfSecond();

            // Ended a second before the cutoff, at it, and a second after; started late, after its end; one still running.
            foreach ([
                [$cutoff->copy()->subSecond(), $cutoff->copy()->subHours(12)],
                [$cutoff->copy(), $cutoff->copy()->subHours(12)],
                [$cutoff->copy()->addSecond(), $cutoff->copy()->subHours(12)],
                [$cutoff->copy()->subHours(2), $cutoff->copy()->subSecond()],
                [$cutoff->copy()->subHours(2), $cutoff->copy()->addMinute()],
                [$now->copy()->utc()->addHours(3), $now->copy()->utc()->subHours(2)],
            ] as [$end, $clockedOn]) {
                $n++;
                $guard = (int) $db->table('guards')->insertGetId([
                    'full_name' => 'Close Officer '.$tag.$round.$label.$n, 'employee_number' => 'GS-CL-'.$tag.$round.substr(md5($label), 0, 3).$n,
                    'psra_number' => 'PSRA-CL-'.$tag.$round.substr(md5($label), 0, 3).$n, 'employment_type' => 'full_time', 'status' => 'active',
                    'reviewer' => false, 'tenant_id' => $site, 'post_id' => $post, 'created_at' => $made, 'updated_at' => $made,
                ]);

                $db->table('shifts')->insert([
                    'tenant_id' => $site, 'guard_id' => $guard, 'post_id' => $post,
                    'rostered_start' => $end->copy()->subHours(12)->format('Y-m-d H:i:s'), 'rostered_end' => $end->format('Y-m-d H:i:s'),
                    'actual_start' => $clockedOn->format('Y-m-d H:i:s'), 'status' => 'on_duty', 'is_simulated' => false,
                    'created_at' => $made, 'updated_at' => $made,
                ]);
            }
        }
    }

    return $sites;
}

it('closes the same shifts the site-by-site walk closed, at every cutoff to the second, with the same hours', function (string $zone): void {
    $rule = app(ShiftAutoClose::class);
    $now = Carbon::parse('2026-09-25 18:30:00.600000', 'UTC')->setTimezone($zone);
    $sites = autoCloseWorld($now);

    $old = autoClosedByTheOldRun($rule, $now);
    $open = Shift::query()->whereNotNull('actual_start')->whereNull('actual_end')->pluck('id')->all();

    Carbon::setTestNow($now);
    $ran = $rule->run($now);
    Carbon::setTestNow();

    // Every shift that was open and now carries an automatic close, with the hours written on it.
    $closed = Shift::query()->whereKey($open)->whereNotNull('auto_closed_at')->orderBy('id')->get(['id', 'auto_close_hours'])
        ->map(static fn (Shift $shift): array => [(int) $shift->id, (int) $shift->auto_close_hours])->all();

    $expected = $old['closed'];
    usort($expected, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

    expect($closed)->toBe($expected)
        ->and($ran)->toBe(['closed' => count($old['closed']), 'sites' => $old['sites']]);

    // The comparison is only a proof if the edges were there: at each site, the shift that ended
    // a second before its cutoff and the one clocked on a second before it were closed, and the
    // ones at the cutoff, after it, clocked on after it or still running were not — on the
    // default, on a site's own hours and on the clamp's, whichever clock the moment is given on
    // (a moment is bound in UTC, `UtcMySqlConnection`).
    $mine = array_values(array_filter($expected, static fn (array $row): bool => in_array((string) Shift::query()->whereKey($row[0])->value('tenant_id'), $sites, true)));

    expect(count($mine))->toBe(2 * count($sites))
        ->and(collect($mine)->pluck(1)->unique()->sort()->values()->all())->toBe([1, 4, 8, 72]);
})->with(['in UTC' => 'UTC', 'on the site\'s clock' => 'America/Jamaica']);

it('reads every site at once: the same queries for six sites as for eighteen, before it closes anything', function (): void {
    $rule = app(ShiftAutoClose::class);

    // Nothing overdue at these sites: the reads are the whole cost, and they must not grow with the sites.
    $now = Carbon::parse('2026-09-25 18:30:00', 'UTC');
    $count = static function () use ($rule): int {
        return QueryBudget::measure(fn () => $rule->run(Carbon::parse('2026-09-20 00:00:00', 'UTC')))['count'];
    };

    autoCloseWorld($now);

    // Once first, so any shift another file left open and overdue is closed before anything is counted.
    $count();
    $six = $count();

    autoCloseWorld($now, 2);
    $eighteen = $count();

    expect($eighteen)->toBe($six)->and($six)->toBeLessThanOrEqual(3);
});
