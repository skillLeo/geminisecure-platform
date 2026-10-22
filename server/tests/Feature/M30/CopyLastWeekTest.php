<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Guard;
use App\Models\GuardRequest;
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
| M30 §4.6, §13: "Copy last week into this week" says what it will do, then does exactly that
|--------------------------------------------------------------------------
|
| The week on screen is Mon 12 – Sun 18 October; last week, Mon 5 – Sun 11,
| holds 18 shifts: Andre Campbell on the Main Gate's days all week, Kemar Blake on
| the Back Gate Monday to Friday, Oral Bennett on the Main Gate's nights Monday to
| Friday, and one open Back Gate shift on the Saturday. This week already has two:
| an open Main Gate day on Wednesday (so Andre's Wednesday is not copied over it)
| and an open Back Gate morning on the Sunday. Kemar is on leave Monday 12 and
| Tuesday 13; Oral's licence runs to Thursday 15, so his nights from Thursday
| would end after it.
|
| Frozen at Wednesday 7 October 2026, 10:00 AM in Kingston.
|
*/

const M30_COPY = 'm30copy';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'UTC'));
    FacilitiesFixture::platform();

    DB::connection('mysql')->table('tenants')->where('id', M30_COPY)->delete();
    DB::connection('mysql')->table('tenants')->insert(['id' => M30_COPY, 'name' => 'Copy Test Estate', 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    SiteTime::flush();

    $this->main = Post::query()->create(['tenant_id' => M30_COPY, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $this->back = Post::query()->create(['tenant_id' => M30_COPY, 'name' => 'Back Gate', 'type' => 'gate', 'is_active' => true]);

    $guard = static function (string $name, string $licence = '2027-06-23'): Guard {
        $n = random_int(100000, 999999);

        return Guard::query()->create([
            'full_name' => $name, 'employee_number' => 'GS-W'.$n, 'psra_number' => 'PSRA-W'.$n, 'psra_expires_on' => $licence,
            'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => M30_COPY,
        ]);
    };

    $this->andre = $guard('Andre Campbell');
    $this->kemar = $guard('Kemar Blake');
    $this->oral = $guard('Oral Bennett', '2026-10-15');

    // A shift as it stands on the roster, written straight in: last week's days before today could not be posted now.
    $this->shift = static function (Post $post, string $day, string $from, string $to, ?Guard $who): Shift {
        $start = Carbon::parse($day.' '.$from, 'America/Jamaica');
        $end = Carbon::parse($day.' '.$to, 'America/Jamaica');
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return Shift::query()->create([
            'tenant_id' => M30_COPY, 'post_id' => $post->id, 'guard_id' => $who?->id, 'duty' => 'post',
            'rostered_start' => $start->copy()->utc(), 'rostered_end' => $end->copy()->utc(),
            'status' => $who === null ? Shift::OPEN : Shift::ROSTERED,
        ]);
    };

    foreach (['05', '06', '07', '08', '09', '10', '11'] as $i => $d) {
        ($this->shift)($this->main, '2026-10-'.$d, '06:00', '18:00', $this->andre);
        if ($i < 5) {
            ($this->shift)($this->back, '2026-10-'.$d, '09:00', '17:00', $this->kemar);
            ($this->shift)($this->main, '2026-10-'.$d, '18:00', '06:00', $this->oral);
        }
    }
    ($this->shift)($this->back, '2026-10-10', '09:00', '17:00', null);

    // Already in the week on screen.
    $this->wednesday = ($this->shift)($this->main, '2026-10-14', '06:00', '18:00', null);
    $this->sunday = ($this->shift)($this->back, '2026-10-18', '08:00', '12:00', null);

    GuardRequest::query()->create(['tenant_id' => M30_COPY, 'guard_id' => $this->kemar->id, 'kind' => 'leave', 'subject' => 'vacation', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-13', 'reason' => 'family', 'status' => 'approved']);

    $this->director = User::factory()->create(['email' => 'm30-copy-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $this->director->syncRoles([Role::DIRECTOR]);
    $this->director = $this->director->fresh();

    $this->plan = fn (string $week = '2026-10-12'): array => $this->actingAs($this->director)->getJson('/guards/schedule/copy?client='.M30_COPY.'&week='.$week)->assertOk()->json();
    $this->inWeek = static fn (string $monday): int => Shift::query()->where('tenant_id', M30_COPY)
        ->where('rostered_start', '>=', Carbon::parse($monday, 'America/Jamaica')->utc())
        ->where('rostered_start', '<', Carbon::parse($monday, 'America/Jamaica')->addWeek()->utc())
        ->count();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
});

it('says what it will do before it does it: what is created, who keeps a guard, who does not and why, what is left alone', function () {
    $plan = ($this->plan)();

    expect($plan)->toMatchArray([
        'from' => ['label' => 'Mon 5 Oct – Sun 11 Oct', 'shifts' => 18],
        'to' => ['label' => 'Mon 12 Oct – Sun 18 Oct', 'shifts' => 2],
        'create' => 17,
        'keep_guard' => 12,
        'was_open' => 1,
        'no_guard' => 4,
        'skipped_past' => 0,
        'skipped_taken' => 1,
        'left_alone' => 2,
    ])->and($plan['no_guard_why'])->toBe([
        'K. Blake is on leave Mon 12 Oct and Tue 13 Oct',
        'O. Bennett\'s licence expires Thu 15 Oct',
    ]);

    // Nothing is written by asking.
    expect(($this->inWeek)('2026-10-12'))->toBe(2);
});

it('creates exactly the shifts the plan said, overwrites nothing, and brings a guard who cannot stand one across as Needs a guard', function () {
    $plan = ($this->plan)();

    $this->actingAs($this->director)->post('/guards/schedule/copy', ['client' => M30_COPY, 'week' => '2026-10-12', 'fingerprint' => $plan['fingerprint']])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', '17 shifts copied from last week.');

    expect(($this->inWeek)('2026-10-12'))->toBe(2 + 17)
        // The two already there are as they were.
        ->and($this->wednesday->fresh()->only(['guard_id', 'status']))->toBe(['guard_id' => null, 'status' => Shift::OPEN])
        ->and($this->sunday->fresh()->rostered_end->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-10-18 17:00');

    $copies = Shift::query()->where('tenant_id', M30_COPY)->where('rostered_start', '>=', Carbon::parse('2026-10-12', 'America/Jamaica')->utc())->whereKeyNot([$this->wednesday->id, $this->sunday->id])->get();
    $atLocal = static fn (Shift $s): string => SiteTime::local($s->rostered_start, M30_COPY)->format('D H:i');

    // Kemar's Monday and Tuesday come across with nobody on them; his Wednesday to Friday keep him.
    expect($copies->where('post_id', $this->back->id)->filter(static fn (Shift $s): bool => in_array($atLocal($s), ['Mon 09:00', 'Tue 09:00'], true))->pluck('guard_id')->all())->toBe([null, null])
        ->and($copies->where('guard_id', $this->kemar->id)->count())->toBe(3)
        // Oral's nights from Thursday would end after his licence: nobody on them; Monday to Wednesday keep him.
        ->and($copies->where('guard_id', $this->oral->id)->count())->toBe(3)
        // Andre keeps all six he could: his Wednesday was not copied over the shift already there.
        ->and($copies->where('guard_id', $this->andre->id)->count())->toBe(6)
        ->and($copies->whereNull('guard_id')->count())->toBe(5);
});

it('copies nothing if the week changed after the plan was shown, and says so', function () {
    $plan = ($this->plan)();

    // Somebody puts a shift at the Back Gate on Monday 12 meanwhile: Kemar's Monday copy would land on it.
    ($this->shift)($this->back, '2026-10-12', '10:00', '14:00', null);

    $this->actingAs($this->director)->post('/guards/schedule/copy', ['client' => M30_COPY, 'week' => '2026-10-12', 'fingerprint' => $plan['fingerprint']])
        ->assertSessionHasErrors(['copy' => 'The week changed since you looked. Nothing was copied: open "Copy last week" again to see what it would do now.']);

    expect(($this->inWeek)('2026-10-12'))->toBe(3);
});

it('reads what the plan needs in a handful of queries, however many shifts the week holds', function () {
    // A heavier week: the same again at a third and fourth gate, so last week holds 36 shifts.
    foreach (['Third Gate', 'Fourth Gate'] as $name) {
        $gate = Post::query()->create(['tenant_id' => M30_COPY, 'name' => $name, 'type' => 'gate', 'is_active' => true]);
        foreach (['05', '06', '07', '08', '09', '10', '11', '12', '13'] as $d) {
            ($this->shift)($gate, '2026-10-'.$d, '06:00', '18:00', null);
        }
    }

    $plan = assertQueryBudget(40, fn () => $this->actingAs($this->director)->getJson('/guards/schedule/copy?client='.M30_COPY.'&week=2026-10-12')->assertOk(), 'The copy plan')->json();
    expect($plan['from']['shifts'])->toBe(18 + 14);
});

it('does not copy into days of this week that have passed', function () {
    // The week on screen is this one, Mon 5 – Sun 11 October; it is Wednesday. Last week is empty but for two shifts.
    ($this->shift)($this->main, '2026-09-28', '06:00', '18:00', $this->andre);
    ($this->shift)($this->main, '2026-10-02', '06:00', '18:00', $this->andre);

    $plan = ($this->plan)('2026-10-05');

    // Monday's copy would be on the 5th, which has passed; Friday's lands on Andre's Friday shift at the same gate.
    expect($plan['skipped_past'])->toBe(1)
        ->and($plan['skipped_taken'])->toBe(1)
        ->and($plan['create'])->toBe(0);

    $this->actingAs($this->director)->post('/guards/schedule/copy', ['client' => M30_COPY, 'week' => '2026-10-05', 'fingerprint' => $plan['fingerprint']])
        ->assertSessionHasErrors(['copy' => 'There is nothing to copy into this week.']);
});
