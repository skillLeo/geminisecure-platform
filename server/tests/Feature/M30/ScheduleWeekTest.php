<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M30 §4: the Schedule draws one client's week of shifts, on the client's clock
|--------------------------------------------------------------------------
|
| Rows are gates, columns the seven days with their dates, a cell every shift that
| starts there that day with its time and guard. The clock is the client's unless
| the viewer chose "My time". The four cards each count one named thing.
|
| The clock is frozen at Wednesday 7 October 2026, 10:00 AM in Kingston, so the
| week on screen is Monday 5 to Sunday 11 October.
|
*/

const M30_SCHED = 'm30schedule';

function m30SchedEstate(string $id = M30_SCHED, string $name = 'Schedule Test Estate', bool $listed = true): string
{
    DB::connection('mysql')->table('tenants')->where('id', $id)->delete();
    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'status' => 'active',
        'timezone' => 'America/Jamaica',
        'listed' => $listed,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    SiteTime::flush();

    return $id;
}

function m30SchedPost(string $name, string $type = 'gate', ?string $also = null, string $estate = M30_SCHED): Post
{
    return Post::query()->create(['tenant_id' => $estate, 'name' => $name, 'type' => $type, 'is_active' => true, 'also_called' => $also]);
}

function m30SchedGuard(string $name, string $estate = M30_SCHED): Guard
{
    $n = random_int(100000, 999999);

    return Guard::query()->create([
        'full_name' => $name, 'employee_number' => 'GS-SC'.$n, 'psra_number' => 'PSRA-SC'.$n,
        'psra_expires_on' => '2027-06-23', 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => $estate,
    ]);
}

/** A shift from a wall-clock start in Kingston, for so many hours. */
function m30SchedShift(Post $post, string $kingston, int $hours, ?Guard $guard = null, array $extra = []): Shift
{
    $start = Carbon::parse($kingston, 'America/Jamaica')->utc();

    return Shift::query()->create([
        'tenant_id' => $post->tenant_id, 'post_id' => $post->id, 'guard_id' => $guard?->id,
        'rostered_start' => $start, 'rostered_end' => $start->copy()->addHours($hours),
        'status' => $guard === null ? Shift::OPEN : Shift::ROSTERED,
        ...$extra,
    ]);
}

function m30SchedViewer(array $attributes = []): User
{
    FacilitiesFixture::platform();

    $user = User::factory()->create([
        'email' => 'm30-schedule-'.uniqid().'@example.com',
        'console' => Console::Gemini->value,
        'status' => 'active',
        ...$attributes,
    ]);
    $user->syncRoles([Role::DIRECTOR]);

    return $user->fresh();
}

/** The Schedule's props, as the page receives them. */
function m30SchedProps($test, User $viewer, array $query = []): array
{
    $response = $test->actingAs($viewer)->get('/guards/schedule?'.http_build_query(['client' => M30_SCHED, ...$query]));
    $response->assertOk();

    return $response->viewData('page')['props'];
}

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'UTC'));
    m30SchedEstate();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
});

it('draws a shift on its day and at its time on the client\'s clock, for a viewer whose own clock is ten hours ahead', function () {
    $gate = m30SchedPost('Main Gate');
    $andre = m30SchedGuard('Andre Campbell');
    m30SchedShift($gate, '2026-10-05 06:00', 12, $andre);

    // A viewer in Lahore on Site time, the default.
    $props = m30SchedProps($this, m30SchedViewer(['console_zone' => 'Asia/Karachi', 'console_time_view' => 'site']));

    expect($props['week']['monday'])->toBe('2026-10-05')
        ->and(array_column($props['days'], 'date'))->toBe(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'])
        ->and($props['days'][2]['today'])->toBeTrue()
        ->and($props['zone']['estateTag'])->toBe('JA')
        ->and($props['zone']['mine'])->toBeFalse();

    $row = collect($props['rows'])->firstWhere('name', 'Main Gate');

    expect($row['cells'][0])->toHaveCount(1)
        ->and($row['cells'][0][0]['time'])->toBe('6 AM – 6 PM JA')
        ->and($row['cells'][0][0]['guard']['short'])->toBe('A. Campbell')
        ->and($row['cells'][0][0]['state'])->toBe('ended')
        ->and($row['cells'][1])->toBe([]);
});

it('draws a shift that runs past midnight in the day it starts, with its end shown', function () {
    $gate = m30SchedPost('Main Gate');
    m30SchedShift($gate, '2026-10-08 18:00', 12, m30SchedGuard('Oral Bennett'));

    $row = collect(m30SchedProps($this, m30SchedViewer())['rows'])->firstWhere('name', 'Main Gate');

    expect($row['cells'][3])->toHaveCount(1)
        ->and($row['cells'][3][0]['time'])->toBe('6 PM – 6 AM Fri JA')
        ->and($row['cells'][4])->toBe([]);
});

it('draws an open shift as needing a guard, a shift under way as on duty, and gates before the rest, with their other names', function () {
    $back = m30SchedPost('Back Gate', 'gate', 'Gate 5, Phase 5 Gate');
    $main = m30SchedPost('Main Gate');
    m30SchedPost('Relief Point', 'relief');

    m30SchedShift($main, '2026-10-07 06:00', 12, m30SchedGuard('Andre Campbell'), ['actual_start' => Carbon::parse('2026-10-07 11:05:00', 'UTC')]);
    m30SchedShift($back, '2026-10-07 09:00', 8);

    $props = m30SchedProps($this, m30SchedViewer());
    $rows = collect($props['rows']);

    expect($rows->pluck('name')->all())->toBe(['Back Gate', 'Main Gate', 'Relief Point'])
        ->and($rows[0]['also_called'])->toBe('Gate 5, Phase 5 Gate')
        ->and($rows[0]['cells'][2][0]['guard'])->toBeNull()
        ->and($rows[1]['cells'][2][0]['state'])->toBe('on_duty');
});

it('counts the four cards from a known week', function () {
    $main = m30SchedPost('Main Gate');
    $back = m30SchedPost('Back Gate');
    m30SchedPost('Relief Point', 'relief');

    $andre = m30SchedGuard('Andre Campbell');
    $kemar = m30SchedGuard('Kemar Blake');

    // Two clocked in now, one of them on a shift outside the week shown (it began last Sunday night).
    m30SchedShift($main, '2026-10-07 06:00', 12, $andre, ['actual_start' => Carbon::parse('2026-10-07 11:00:00', 'UTC')]);
    m30SchedShift($back, '2026-10-04 20:00', 24, $kemar, ['actual_start' => Carbon::parse('2026-10-05 01:00:00', 'UTC')]);
    // Three more in the week, two with nobody on them.
    m30SchedShift($main, '2026-10-08 06:00', 12, $andre);
    m30SchedShift($back, '2026-10-09 09:00', 8);
    m30SchedShift($back, '2026-10-11 09:00', 8);
    // And one the week after, not counted.
    m30SchedShift($main, '2026-10-12 06:00', 12);

    $cards = collect(m30SchedProps($this, m30SchedViewer())['cards'])->keyBy('key');

    expect($cards['clocked_in']['value'])->toBe(2)
        ->and($cards['clocked_in']['label'])->toBe('Clocked in right now')
        ->and($cards['shifts']['value'])->toBe(4)
        ->and($cards['shifts']['label'])->toBe('Shifts this week')
        ->and($cards['no_guard']['value'])->toBe(2)
        ->and($cards['no_guard']['label'])->toBe('Shifts with no guard')
        ->and($cards['gates']['value'])->toBe(2)
        ->and($cards['gates']['label'])->toBe('Gates at this client');
});

it('moves by exactly seven days, and the address carries the week', function () {
    m30SchedPost('Main Gate');
    $viewer = m30SchedViewer();

    $props = m30SchedProps($this, $viewer);
    expect($props['week']['previous'])->toBe('2026-09-28')
        ->and($props['week']['next'])->toBe('2026-10-12')
        ->and($props['week']['isThisWeek'])->toBeTrue();

    $next = m30SchedProps($this, $viewer, ['week' => $props['week']['next']]);
    expect($next['week']['monday'])->toBe('2026-10-12')
        ->and($next['week']['previous'])->toBe('2026-10-05')
        ->and($next['week']['isThisWeek'])->toBeFalse()
        ->and($next['week']['this'])->toBe('2026-10-05');

    // Any day of a week opens that week; a day that is not a day opens this week.
    expect(m30SchedProps($this, $viewer, ['week' => '2026-10-15'])['week']['monday'])->toBe('2026-10-12')
        ->and(m30SchedProps($this, $viewer, ['week' => '2026-13-45'])['week']['monday'])->toBe('2026-10-05');
});

it('draws the times on the viewer\'s own clock when they chose My time, every shift on the client\'s own day (M31 B1.5)', function () {
    $gate = m30SchedPost('Main Gate');
    // Monday 6 PM in Kingston is Tuesday 4 AM in Lahore; Monday 6 AM in Kingston is Monday 4 PM there.
    m30SchedShift($gate, '2026-10-05 18:00', 12, m30SchedGuard('Oral Bennett'));
    m30SchedShift($gate, '2026-10-06 06:00', 12, m30SchedGuard('Kemar Blake'));

    $site = collect(m30SchedProps($this, m30SchedViewer(['console_zone' => 'Asia/Karachi', 'console_time_view' => 'site']))['rows'])->firstWhere('name', 'Main Gate');
    $props = m30SchedProps($this, m30SchedViewer(['console_zone' => 'Asia/Karachi', 'console_time_view' => 'mine']));
    $mine = collect($props['rows'])->firstWhere('name', 'Main Gate');

    // The same shifts on the same days on both clocks; only the times on them change, and a block whose time on the
    // viewer's clock starts on another day says which.
    $days = static fn (array $row): array => array_map(static fn (array $cell): array => array_column(array_column($cell, 'guard'), 'short'), $row['cells']);
    expect($props['zone']['mine'])->toBeTrue()
        ->and($props['zone']['shownTag'])->toBe('KA')
        ->and(array_column($props['days'], 'date'))->toBe(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'])
        ->and($days($mine))->toBe($days($site))
        ->and($site['cells'][0][0]['time'])->toBe('6 PM – 6 AM Tue JA')
        ->and($mine['cells'][0][0]['time'])->toBe('Tue 4 AM – 4 PM KA')
        ->and($mine['cells'][1][0]['time'])->toBe('4 PM – 4 AM Wed KA');
});

it('opens on the first real client, and lists an internal estate below the real ones, never chosen by default', function () {
    m30SchedEstate('m30aaainternal', 'Aaa Internal Test Estate', listed: false);
    m30SchedEstate('m30zzzreal', 'Zzz Real Client');

    $head = User::factory()->create(['email' => 'm30-head-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $head->syncRoles([Role::HEAD_OF_SECURITY]);
    foreach (['m30aaainternal', 'm30zzzreal'] as $estate) {
        EstateAssignment::query()->create(['user_id' => $head->id, 'tenant_id' => $estate, 'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true]);
    }

    $props = $this->actingAs($head->fresh())->get('/guards/schedule')->assertOk()->viewData('page')['props'];

    expect($props['client']['id'])->toBe('m30zzzreal')
        ->and(array_column($props['clients'], 'id'))->toBe(['m30zzzreal', 'm30aaainternal'])
        ->and($props['clients'][1]['internal'])->toBeTrue();

    // Chosen on purpose, it opens.
    expect($this->actingAs($head->fresh())->get('/guards/schedule?client=m30aaainternal')->viewData('page')['props']['client']['id'])->toBe('m30aaainternal');
});
