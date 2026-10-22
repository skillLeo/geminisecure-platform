<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Guard;
use App\Models\GuardRequest;
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
| M30 §5, §6, §10: the shift panel adds a shift with its guard, and says no first
|--------------------------------------------------------------------------
|
| The panel asks /guards/schedule/check what the server would say as the form
| changes: a line per rule, and every guard with why they cannot stand it. Adding
| goes through /guards/schedule/shifts, which asks again and writes through the
| Roster, which refuses on its own. Every refusal is tested on both.
|
| Frozen at Wednesday 7 October 2026, 10:00 AM in Kingston.
|
*/

const M30_PANEL = 'm30panel';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'UTC'));
    FacilitiesFixture::platform();

    DB::connection('mysql')->table('tenants')->where('id', M30_PANEL)->delete();
    DB::connection('mysql')->table('tenants')->insert(['id' => M30_PANEL, 'name' => 'Panel Test Estate', 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    SiteTime::flush();

    $this->main = Post::query()->create(['tenant_id' => M30_PANEL, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $this->back = Post::query()->create(['tenant_id' => M30_PANEL, 'name' => 'Back Gate', 'type' => 'gate', 'is_active' => true]);

    $this->guard = static function (string $name, array $extra = []): Guard {
        $n = random_int(100000, 999999);

        return Guard::query()->create([
            'full_name' => $name, 'employee_number' => 'GS-P'.$n, 'psra_number' => 'PSRA-P'.$n, 'psra_expires_on' => '2027-06-23',
            'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => M30_PANEL, ...$extra,
        ]);
    };

    $this->director = User::factory()->create(['email' => 'm30-panel-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $this->director->syncRoles([Role::DIRECTOR]);
    $this->director = $this->director->fresh();

    $this->ask = fn (array $form): array => $this->actingAs($this->director)->postJson('/guards/schedule/check', $form)->assertOk()->json();
    $this->form = fn (array $over = []): array => ['post_id' => $this->main->id, 'date' => '2026-10-09', 'starts' => '06:00', 'ends' => '18:00', 'duty' => 'post', 'repeat' => 'once', ...$over];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
});

it('adds a shift with its guard in one request, as one row with the guard on it', function () {
    $andre = ($this->guard)('Andre Campbell');

    $answer = ($this->ask)(($this->form)(['guard_id' => $andre->id]));
    expect($answer['ok'])->toBeTrue()
        ->and($answer['count'])->toBe(1)
        ->and($answer['hours'])->toBe('12 hours')
        // Every rule a line (§10), in the milestone's order (§5.1): the gate, the guard, then the time.
        ->and(collect($answer['checks'])->pluck('text')->all())->toBe([
            'Main Gate is free at this time',
            'Andre Campbell is free',
            'Andre Campbell\'s licence runs to 23 Jun 2027',
            'Andre Campbell works at Panel Test Estate',
            'Starts today or later',
            '12 hours, within 24',
            'Within the next six months',
        ]);

    $before = Shift::query()->where('post_id', $this->main->id)->count();

    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['guard_id' => $andre->id]))->assertRedirect()->assertSessionHasNoErrors();

    $made = Shift::query()->where('post_id', $this->main->id)->get();
    expect($made)->toHaveCount($before + 1)
        ->and($made->last()->guard_id)->toBe($andre->id)
        ->and($made->last()->status)->toBe(Shift::ROSTERED)
        // 6 AM to 6 PM in Kingston is 11:00 to 23:00 UTC.
        ->and($made->last()->rostered_start->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-10-09 11:00')
        ->and($made->last()->rostered_end->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-10-09 23:00')
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'roster.shift_posted')->where('entity_id', (string) $made->last()->id)->exists())->toBeTrue();
});

it('reads an end at or before the start as the next morning: a night shift is typed as it is said', function () {
    $answer = ($this->ask)(($this->form)(['starts' => '18:00', 'ends' => '06:00']));

    expect($answer['ok'])->toBeTrue()
        ->and($answer['hours'])->toBe('12 hours')
        ->and($answer['occurrences'][0]['ends_at'])->toBe('2026-10-10T11:00:00+00:00');
});

it('crosses out a guard already on a shift at that time, naming the shift, and the server refuses him too', function () {
    $kadian = ($this->guard)('Kadian Walters');
    app(Roster::class)->postShift($this->back, '2026-10-09 09:00', '2026-10-09 17:00', $this->director, 'post', $kadian);

    $row = collect(($this->ask)(($this->form)())['guards'])->firstWhere('id', $kadian->id);
    expect($row['free'])->toBeFalse()
        ->and($row['reason'])->toBe('on another shift 9:00 AM–5:00 PM JA, Back Gate');

    $answer = ($this->ask)(($this->form)(['guard_id' => $kadian->id]));
    expect($answer['ok'])->toBeFalse()
        ->and(collect($answer['checks'])->where('ok', false)->pluck('text')->first())->toBe('Kadian Walters is on another shift: Back Gate, Fri 9 Oct, 9:00 AM to 5:00 PM JA');

    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['guard_id' => $kadian->id]))->assertSessionHasErrors('panel');

    // And the Roster refuses it on its own, for the classic roster too.
    $open = app(Roster::class)->postOpenShift($this->main, '2026-10-09 06:00', '2026-10-09 18:00', $this->director);
    expect(fn () => app(Roster::class)->assign($open, $kadian, $this->director))->toThrow(DomainException::class, 'One officer cannot stand two shifts at once.');
});

it('crosses out a guard on approved leave that day, and the server refuses him', function () {
    $damion = ($this->guard)('Damion Reid');
    GuardRequest::query()->create(['tenant_id' => M30_PANEL, 'guard_id' => $damion->id, 'kind' => 'leave', 'subject' => 'vacation', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-10', 'reason' => 'family', 'status' => 'approved']);

    $row = collect(($this->ask)(($this->form)())['guards'])->firstWhere('id', $damion->id);
    expect($row['free'])->toBeFalse()->and($row['reason'])->toBe('on leave this day');

    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['guard_id' => $damion->id]))->assertSessionHasErrors('panel');

    $open = app(Roster::class)->postOpenShift($this->main, '2026-10-09 06:00', '2026-10-09 18:00', $this->director);
    expect(fn () => app(Roster::class)->assign($open, $damion, $this->director))->toThrow(DomainException::class, 'approved leave');
});

it('crosses out a guard whose licence runs out before the shift ends, naming the date', function () {
    $chambers = ($this->guard)('Andre Chambers', ['psra_expires_on' => '2026-10-08']);

    $row = collect(($this->ask)(($this->form)())['guards'])->firstWhere('id', $chambers->id);
    expect($row['free'])->toBeFalse()->and($row['reason'])->toBe('licence runs out before this shift ends, 8 Oct 2026');

    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['guard_id' => $chambers->id]))->assertSessionHasErrors('panel');
});

it('lists the free guards first, then the rest crossed with reasons, and never an empty list', function () {
    $free = ($this->guard)('Zed Free Officer');
    $suspended = ($this->guard)('Aaron Suspended', ['status' => 'suspended']);

    $guards = collect(($this->ask)(($this->form)())['guards']);
    $ids = $guards->pluck('id')->all();

    expect(array_search($free->id, $ids, true))->toBeLessThan(array_search($suspended->id, $ids, true))
        ->and($guards->firstWhere('id', $suspended->id)['reason'])->toBe('suspended');
});

it('refuses a shift in the past, and one overlapping another on the same gate, in the panel and on the server', function () {
    $past = ($this->ask)(($this->form)(['date' => '2026-10-06']));
    expect($past['ok'])->toBeFalse()->and(collect($past['checks'])->where('ok', false)->pluck('text')->first())->toContain('is in the past');
    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['date' => '2026-10-06']))->assertSessionHasErrors('panel');

    app(Roster::class)->postOpenShift($this->main, '2026-10-09 10:00', '2026-10-09 14:00', $this->director);
    $overlap = ($this->ask)(($this->form)());
    expect($overlap['ok'])->toBeFalse()->and(collect($overlap['checks'])->where('ok', false)->pluck('text')->first())->toBe('Main Gate already has a shift at that time: Fri 9 Oct, 10:00 AM to 2:00 PM JA');
    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)())->assertSessionHasErrors('panel');

    // Longer than a day cannot be typed in the panel; the server refuses it all the same.
    expect(fn () => app(Roster::class)->postShift($this->back, '2026-10-12 06:00', '2026-10-13 07:00', $this->director))->toThrow(DomainException::class, 'twenty-four hours');
});

it('repeats a shift every day, or on chosen days, to an end date, and says how many it will add', function () {
    $daily = ($this->ask)(($this->form)(['repeat' => 'daily', 'until' => '2026-10-15']));
    expect($daily['count'])->toBe(7);

    // Mondays, Wednesdays and Fridays from Friday 9 to Friday 16 October: 9, 12, 14, 16.
    $days = ($this->ask)(($this->form)(['repeat' => 'days', 'until' => '2026-10-16', 'days' => [1, 3, 5]]));
    expect(array_column($days['occurrences'], 'date'))->toBe(['2026-10-09', '2026-10-12', '2026-10-14', '2026-10-16']);

    $andre = ($this->guard)('Andre Campbell');
    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['repeat' => 'daily', 'until' => '2026-10-15', 'guard_id' => $andre->id]))
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', '7 shifts added.');

    expect(Shift::query()->where('post_id', $this->main->id)->where('guard_id', $andre->id)->count())->toBe(7);
});

it('answers a field left empty, or a repeat that ends before it starts, in one sentence and not a validation failure', function () {
    $empty = ($this->ask)(($this->form)(['date' => '']));
    expect($empty['ok'])->toBeFalse()->and($empty['checks'][0]['text'])->toBe('Choose a date.');

    $noGate = ($this->ask)(($this->form)(['post_id' => null]));
    expect($noGate['checks'][0]['text'])->toBe('Choose a gate at this client.');

    $backwards = ($this->ask)(($this->form)(['repeat' => 'daily', 'until' => '2026-10-08']));
    expect($backwards['checks'][0]['text'])->toBe('Choose the last day it repeats on, on or after Fri 9 Oct.');

    $noDays = ($this->ask)(($this->form)(['repeat' => 'days', 'until' => '2026-10-16', 'days' => []]));
    expect($noDays['checks'][0]['text'])->toBe('Choose at least one day of the week for it to repeat on.');

    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['date' => '']))->assertSessionHasErrors(['panel' => 'Choose a date.']);
});

it('checks a two-month repeat in a handful of queries, and adds every shift of it', function () {
    $andre = ($this->guard)('Andre Campbell');
    $form = ($this->form)(['repeat' => 'daily', 'until' => '2026-12-09', 'guard_id' => $andre->id]);

    $answer = assertQueryBudget(30, fn () => $this->actingAs($this->director)->postJson('/guards/schedule/check', $form)->assertOk(), 'The panel\'s check')->json();
    expect($answer['count'])->toBe(62)->and($answer['ok'])->toBeTrue()->and($answer['checks'][0]['text'])->toBe('Main Gate is free at these times');

    $this->actingAs($this->director)->post('/guards/schedule/shifts', $form)->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', '62 shifts added.');
    expect(Shift::query()->where('post_id', $this->main->id)->where('guard_id', $andre->id)->count())->toBe(62);
});

it('adds nothing at all when one day of a repeat is refused', function () {
    app(Roster::class)->postOpenShift($this->main, '2026-10-11 10:00', '2026-10-11 14:00', $this->director);
    $before = Shift::query()->where('post_id', $this->main->id)->count();

    $this->actingAs($this->director)->post('/guards/schedule/shifts', ($this->form)(['repeat' => 'daily', 'until' => '2026-10-13']))->assertSessionHasErrors('panel');

    expect(Shift::query()->where('post_id', $this->main->id)->count())->toBe($before);
});
