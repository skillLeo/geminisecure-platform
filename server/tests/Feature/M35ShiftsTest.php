<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Gemini\Roster;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M35 §2, §3, §4: the shift form prevents, an open shift has nobody on it, and the classic rows can change and remove
|--------------------------------------------------------------------------
|
| §3  6 PM to 7 AM is a thirteen-hour night shift, stored as one, never refused; a start before today and a shift over
|     24 hours are refused by the server as the form already prevents them; a clash at the gate is said before saving.
| §4  posting an open shift attaches nobody — through the classic roster and the schedule alike. The schedule's grid
|     shows the shift; the classic grid, which draws where each officer normally works, does not move.
| §2  the classic day list knows, row by row, whether its Remove may be pressed, and the panel it opens has each
|     client's gates.
|
| Frozen at Wednesday 7 October 2026, 10:00 AM in Kingston.
|
*/

const M35_SHIFTS = 'm35shifts';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'UTC'));
    FacilitiesFixture::platform();

    DB::connection('mysql')->table('tenants')->where('id', M35_SHIFTS)->delete();
    DB::connection('mysql')->table('tenants')->insert(['id' => M35_SHIFTS, 'name' => 'Shift Form Estate', 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    SiteTime::flush();

    $this->main = Post::query()->create(['tenant_id' => M35_SHIFTS, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);

    $n = random_int(100000, 999999);
    $this->andre = Guard::query()->create([
        'full_name' => 'Andre Formcheck', 'employee_number' => 'GS-F'.$n, 'psra_number' => 'PSRA-F'.$n, 'psra_expires_on' => '2027-06-23',
        'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => M35_SHIFTS, 'post_id' => $this->main->id,
    ]);

    $this->director = User::factory()->create(['email' => 'm35-shifts-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $this->director->syncRoles([Role::DIRECTOR]);
    $this->director = $this->director->fresh();

    $this->ask = fn (array $form): array => $this->actingAs($this->director)->postJson('/guards/schedule/check', $form)->assertOk()->json();
    $this->form = fn (array $over = []): array => ['post_id' => $this->main->id, 'date' => '2026-10-20', 'starts' => '06:00', 'ends' => '18:00', 'duty' => 'post', 'repeat' => 'once', 'guard_id' => null, ...$over];
    $this->made = fn (): ?Shift => Shift::query()->where('post_id', $this->main->id)->orderByDesc('id')->first();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
});

/* ── §3 · the form prevents; the server agrees ───────────────────────────────── */

it('accepts 6 PM to 7 AM and stores it as one thirteen-hour shift ending the next morning', function () {
    $answer = ($this->ask)(($this->form)(['starts' => '18:00', 'ends' => '07:00']));

    expect($answer['ok'])->toBeTrue()
        ->and($answer['hours'])->toBe('13 hours')
        ->and(collect($answer['checks'])->where('ok', false)->all())->toBe([]);

    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['starts' => '18:00', 'ends' => '07:00']))->assertRedirect()->assertSessionHasNoErrors();

    $shift = ($this->made)();
    $endsAtTheEstate = $shift->rostered_end->copy()->setTimezone('America/Jamaica');

    expect($shift->guard_id)->toBeNull()
        ->and($shift->rostered_start->diffInMinutes($shift->rostered_end))->toEqual(13 * 60)
        // 6 PM in Kingston is 23:00 UTC; 7 AM the next morning is 12:00 UTC on the 21st.
        ->and($shift->rostered_start->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-10-20 23:00')
        ->and($shift->rostered_end->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-10-21 12:00')
        ->and($endsAtTheEstate->format('Y-m-d g:i A'))->toBe('2026-10-21 7:00 AM');
});

it('accepts the screenshot\'s shift, 5:59 PM to 7:00 AM, as thirteen hours and a minute — the classic form refused it', function () {
    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['starts' => '17:59', 'ends' => '07:00']))->assertSessionHasNoErrors();

    $shift = ($this->made)();
    expect($shift->rostered_start->diffInMinutes($shift->rostered_end))->toEqual(13 * 60 + 1);
});

it('refuses a start before today at the estate, which the form never offers', function () {
    $answer = ($this->ask)(($this->form)(['date' => '2026-10-06']));

    expect($answer['ok'])->toBeFalse()
        ->and(collect($answer['checks'])->where('ok', false)->pluck('text')->implode(' '))->toContain('in the past');

    $before = Shift::query()->count();
    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['date' => '2026-10-06']))->assertSessionHasErrors('panel');
    expect(Shift::query()->count())->toBe($before);

    // Today itself is offered, and accepted.
    expect(($this->ask)(($this->form)(['date' => '2026-10-07', 'starts' => '18:00', 'ends' => '23:00']))['ok'])->toBeTrue();
});

it('never takes a shift over 24 hours: the form cannot say one, and the server refuses one sent anyway', function () {
    // The longest the form can say: an end at the start's own time, the next morning — 24 hours, accepted.
    $longest = ($this->ask)(($this->form)(['starts' => '07:00', 'ends' => '07:00']));
    expect($longest['ok'])->toBeTrue()->and($longest['hours'])->toBe('24 hours');

    // A longer one, sent to the classic roster's endpoint by hand, is refused.
    $before = Shift::query()->count();
    $this->actingAs($this->director)
        ->post('/guards/roster/shifts', ['post_id' => $this->main->id, 'starts_at' => '2026-10-20 06:00', 'ends_at' => '2026-10-21 07:00'])
        ->assertSessionHasErrors(['starts_at' => 'A shift runs up to twenty-four hours. Longer than that is two shifts, and posting it as one would hide the handover.']);
    expect(Shift::query()->count())->toBe($before);
});

it('marks a shift that meets another at the same gate before it is saved, naming that shift, and the server refuses it', function () {
    app(Roster::class)->postOpenShift($this->main, '2026-10-20 07:00', '2026-10-20 17:00', $this->director);

    $answer = ($this->ask)(($this->form)());
    $cross = collect($answer['checks'])->where('ok', false)->pluck('text')->first();

    expect($answer['ok'])->toBeFalse()
        ->and($cross)->toContain('Main Gate')
        ->and($cross)->toContain('7:00 AM');

    $before = Shift::query()->count();
    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)())->assertSessionHasErrors('panel');
    expect(Shift::query()->count())->toBe($before);
});

it('keeps why an open shift is open, as the classic form did — read by whoever picks it up', function () {
    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['reason' => '  Covering Andre\'s leave  ']))->assertSessionHasNoErrors();

    expect(($this->made)()->released_reason)->toBe('Covering Andre\'s leave');

    // A guard chosen: nothing is said to be open.
    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['date' => '2026-10-21', 'guard_id' => $this->andre->id, 'reason' => 'ignored']))->assertSessionHasNoErrors();
    expect(($this->made)()->released_reason)->toBeNull();
});

/* ── §4 · an open shift has nobody on it ─────────────────────────────────────── */

it('posts an open shift with nobody on it, on the classic roster and the schedule; both grids show it on its own day, as Open', function () {
    $roster = fn (array $query = []): array => $this->actingAs($this->director)->get('/guards/roster?'.http_build_query($query))->assertOk()->viewData('page')['props'];

    // The classic form's endpoint.
    $this->actingAs($this->director)->post('/guards/roster/shifts', ['post_id' => $this->main->id, 'starts_at' => '2026-10-20 07:00', 'ends_at' => '2026-10-20 17:00', 'reason' => 'lalalal'])->assertSessionHasNoErrors();
    $classic = ($this->made)();
    expect($classic->guard_id)->toBeNull()->and($classic->status)->toBe(Shift::OPEN);

    // The schedule's.
    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['date' => '2026-10-21']))->assertSessionHasNoErrors();
    $schedule = ($this->made)();
    expect($schedule->guard_id)->toBeNull()->and($schedule->status)->toBe(Shift::OPEN);

    // Nobody was put on either by anything, after.
    expect(Shift::query()->whereKey([$classic->id, $schedule->id])->whereNotNull('guard_id')->count())->toBe(0)
        ->and(DB::connection('mysql')->table('audit_log')->where('entity_type', 'Shift')->whereIn('entity_id', [(string) $classic->id, (string) $schedule->id])->pluck('action')->unique()->values()->all())->toBe(['roster.shift_posted']);

    // The schedule's grid draws them, on their days, as needing a guard.
    $week = $this->actingAs($this->director)->get('/guards/schedule?'.http_build_query(['client' => M35_SHIFTS, 'week' => '2026-10-19']))->assertOk()->viewData('page')['props'];
    $cells = collect($week['rows'])->firstWhere('id', $this->main->id)['cells'];
    expect(collect($cells[1])->pluck('id')->all())->toContain($classic->id)   // Tuesday 20 Oct
        ->and(collect($cells[2])->pluck('id')->all())->toContain($schedule->id) // Wednesday 21 Oct
        ->and(collect($cells[1])->firstWhere('id', $classic->id)['guard'])->toBeNull();

    // The classic day list counts it open with nobody on it.
    $after = $roster(['date' => '2026-10-20']);
    $row = collect($after['day']['rows'])->firstWhere('id', $classic->id);
    expect($row['officer'])->toBeNull()
        ->and($row['is_open'])->toBeTrue()
        ->and($after['day']['open'])->toBeGreaterThanOrEqual(1);

    // And the classic grid is the week's shifts now (M35 §4, 903dd56b): each on the day it starts, as Open, and no
    // officer drawn on a day nobody is rostered — the "guard put on all seven days" the client saw was the old grid.
    $grid = $roster(['week' => '2026-10-19']);
    $line = collect(collect($grid['groups'])->firstWhere('id', M35_SHIFTS)['lines'])->firstWhere('key', M35_SHIFTS.'-'.$this->main->id);
    expect(collect($line['cells'][1])->firstWhere('id', $classic->id)['label'])->toBe('Open')   // Tuesday 20 Oct
        ->and(collect($line['cells'][2])->firstWhere('id', $schedule->id)['label'])->toBe('Open') // Wednesday 21 Oct
        ->and(collect([0, 3, 4, 5, 6])->map(fn (int $i): int => count($line['cells'][$i]))->sum())->toBe(0)
        ->and($grid['weekDates'][1])->toBe('2026-10-20');
});

/* ── §2 · the classic rows' own controls ─────────────────────────────────────── */

it('tells the classic day list, row by row, whether Remove may be pressed, and gives the panel every client\'s gates', function () {
    $ahead = app(Roster::class)->postShift($this->main, '2026-10-07 18:00', '2026-10-08 06:00', $this->director, 'post', $this->andre);
    $started = Shift::query()->create(['tenant_id' => M35_SHIFTS, 'post_id' => $this->main->id, 'guard_id' => null, 'status' => Shift::OPEN, 'rostered_start' => Carbon::parse('2026-10-07 06:00', 'America/Jamaica')->utc(), 'rostered_end' => Carbon::parse('2026-10-07 17:00', 'America/Jamaica')->utc()]);

    $props = $this->actingAs($this->director)->get('/guards/roster?date=2026-10-07')->assertOk()->viewData('page')['props'];
    $rows = collect($props['day']['rows'])->keyBy('id');

    expect($rows[$ahead->id]['can_remove'])->toBeTrue()
        ->and($rows[$ahead->id]['locked'])->toBeNull()
        // Its rostered start has passed: who stands it can still change, but it is not removed.
        ->and($rows[$started->id]['can_remove'])->toBeFalse()
        ->and($rows[$started->id]['locked'])->toBeNull()
        ->and($props['shiftPanels'][M35_SHIFTS]['client']['name'])->toBe('Shift Form Estate')
        ->and($props['shiftPanels'][M35_SHIFTS]['zone']['estateTag'])->toBe('JA')
        ->and(collect($props['shiftPanels'][M35_SHIFTS]['posts'])->pluck('name')->all())->toBe(['Main Gate']);

    // The panel's Remove, opened from the classic roster, goes back to it, and the shift is gone.
    $this->actingAs($this->director)->from('/guards/roster?date=2026-10-07')
        ->post('/guards/schedule/shifts/'.$ahead->id.'/remove', ['reason' => 'Gate closed for works'])
        ->assertRedirect('/guards/roster?date=2026-10-07');
    expect(Shift::query()->whereKey($ahead->id)->exists())->toBeFalse();

    // And the server refuses to remove one that has started, whatever a page sends.
    $this->actingAs($this->director)->post('/guards/schedule/shifts/'.$started->id.'/remove', ['reason' => 'Gate closed for works'])->assertSessionHasErrors('panel');
    expect(Shift::query()->whereKey($started->id)->exists())->toBeTrue();
});

it('hands the schedule the claims waiting, where the Roster tab opens now', function () {
    $open = app(Roster::class)->postOpenShift($this->main, '2026-10-20 07:00', '2026-10-20 17:00', $this->director);
    DB::connection('mysql')->table('shift_claims')->insert(['shift_id' => $open->id, 'guard_id' => $this->andre->id, 'status' => 'pending', 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $props = $this->actingAs($this->director)->get('/guards/schedule?'.http_build_query(['client' => M35_SHIFTS]))->assertOk()->viewData('page')['props'];
    $claim = collect($props['claimsWaiting'])->firstWhere('shift_id', $open->id);

    expect($claim)->not->toBeNull()
        ->and($claim['guard'])->toBe('Andre Formcheck')
        ->and($claim['post'])->toBe('Main Gate');
});
