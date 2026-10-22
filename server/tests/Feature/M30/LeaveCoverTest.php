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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M30 §8, §13: leave, and the shifts it leaves without a guard
|--------------------------------------------------------------------------
|
| Kemar Blake asks for leave Mon 12 to Wed 14 October. He holds the Back Gate's
| 9 to 5 on the 12th, 13th and 14th, and on the 15th; Andre Campbell holds the
| Main Gate on the 13th. Approving the leave says first which of Kemar's shifts
| fall in those days, and the person approving chooses: take him off exactly
| those (each "Needs a guard", with the reason on it), or leave them as they are.
|
| Frozen at Wednesday 7 October 2026, 10:00 AM in Kingston.
|
*/

const M30_LEAVE = 'm30leave';

beforeEach(function () {
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'UTC'));
    FacilitiesFixture::platform();

    DB::connection('mysql')->table('tenants')->where('id', M30_LEAVE)->delete();
    DB::connection('mysql')->table('tenants')->insert(['id' => M30_LEAVE, 'name' => 'Leave Test Estate', 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    SiteTime::flush();

    $this->main = Post::query()->create(['tenant_id' => M30_LEAVE, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $this->back = Post::query()->create(['tenant_id' => M30_LEAVE, 'name' => 'Back Gate', 'type' => 'gate', 'is_active' => true]);

    $guard = static fn (string $name): Guard => Guard::query()->create([
        'full_name' => $name, 'employee_number' => 'GS-L'.random_int(100000, 999999), 'psra_number' => 'PSRA-L'.random_int(100000, 999999), 'psra_expires_on' => '2027-06-23',
        'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => M30_LEAVE,
    ]);
    $this->kemar = $guard('Kemar Blake');
    $this->andre = $guard('Andre Campbell');

    $this->director = User::factory()->create(['name' => 'Dora Director', 'email' => 'm30-leave-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $this->director->syncRoles([Role::DIRECTOR]);
    $this->director = $this->director->fresh();

    $roster = app(Roster::class);
    $this->kemars = collect(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'])->mapWithKeys(fn (string $day) => [$day => $roster->postShift($this->back, $day.' 09:00', $day.' 17:00', $this->director, 'post', $this->kemar)]);
    $this->andres = $roster->postShift($this->main, '2026-10-13 06:00', '2026-10-13 18:00', $this->director, 'post', $this->andre);

    $this->request = GuardRequest::query()->create(['tenant_id' => M30_LEAVE, 'guard_id' => $this->kemar->id, 'kind' => 'leave', 'subject' => 'vacation', 'starts_on' => '2026-10-12', 'ends_on' => '2026-10-14', 'reason' => 'family', 'status' => 'pending']);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
});

it('shows the leave waiting with the shifts he holds in those days, before anything is decided', function () {
    $this->actingAs($this->director)->get('/guards/schedule?client='.M30_LEAVE.'&week=2026-10-12')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canDecideLeave', true)
            ->where('leaveWaiting.0.guard.name', 'Kemar Blake')
            ->where('leaveWaiting.0.days', 'Mon 12 Oct to Wed 14 Oct')
            ->where('leaveWaiting.0.shifts', fn ($shifts) => collect($shifts)->pluck('text')->all() === [
                'Back Gate · Mon 12 Oct · 9:00 AM – 5:00 PM JA',
                'Back Gate · Tue 13 Oct · 9:00 AM – 5:00 PM JA',
                'Back Gate · Wed 14 Oct · 9:00 AM – 5:00 PM JA',
            ]));

    expect($this->request->fresh()->status)->toBe('pending');
});

it('approving and taking him off marks exactly those shifts Needs a guard, no others, each saying why', function () {
    $this->actingAs($this->director)->post('/guards/schedule/leave/'.$this->request->id, ['decision' => 'approved', 'take_off' => true])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'The leave is approved. 3 shifts need a guard now.');

    expect($this->request->fresh()->status)->toBe('approved');

    foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $day) {
        $shift = $this->kemars[$day]->fresh();
        expect($shift->guard_id)->toBeNull()
            ->and($shift->status)->toBe(Shift::OPEN)
            ->and($shift->released_reason)->toBe('Kemar Blake was taken off on 7 Oct because their leave was approved.');
    }

    // The day after his leave, and somebody else's shift in those days, are as they were.
    expect($this->kemars['2026-10-15']->fresh()->guard_id)->toBe($this->kemar->id)
        ->and($this->andres->fresh()->guard_id)->toBe($this->andre->id);
});

it('approving and leaving the shifts as they are changes no shift', function () {
    $this->actingAs($this->director)->post('/guards/schedule/leave/'.$this->request->id, ['decision' => 'approved', 'take_off' => false])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'The leave is approved. Their shifts are as they were.');

    expect($this->request->fresh()->status)->toBe('approved')
        ->and(Shift::query()->whereKey($this->kemars->pluck('id'))->pluck('guard_id')->unique()->all())->toBe([$this->kemar->id]);
});

it('declines only with a reason he is told, and changes no shift', function () {
    $this->actingAs($this->director)->post('/guards/schedule/leave/'.$this->request->id, ['decision' => 'denied', 'note' => ''])
        ->assertSessionHasErrors('note');
    expect($this->request->fresh()->status)->toBe('pending');

    $this->actingAs($this->director)->post('/guards/schedule/leave/'.$this->request->id, ['decision' => 'denied', 'note' => 'Two others are away that week'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($this->request->fresh()->status)->toBe('denied')
        ->and($this->request->fresh()->decision_note)->toBe('Two others are away that week')
        ->and($this->kemars['2026-10-12']->fresh()->guard_id)->toBe($this->kemar->id);
});

it('draws approved leave as a band across his days in the week, and as no shift', function () {
    $this->request->forceFill(['status' => 'approved'])->save();

    $this->actingAs($this->director)->get('/guards/schedule?client='.M30_LEAVE.'&week=2026-10-12')
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave', [['id' => $this->request->id, 'name' => 'Kemar Blake', 'short' => 'K. Blake', 'from' => 0, 'to' => 2, 'days' => 'Mon 12 Oct to Wed 14 Oct']])
            ->where('cards.1.value', 5));
});

it('reads the shifts of every waiting request at once, however many are waiting', function () {
    $roster = app(Roster::class);
    foreach (range(1, 8) as $n) {
        $g = Guard::query()->create(['full_name' => 'Waiting Guard '.$n, 'employee_number' => 'GS-LW'.$n, 'psra_number' => 'PSRA-LW'.$n, 'psra_expires_on' => '2027-06-23', 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => M30_LEAVE]);
        $gate = Post::query()->create(['tenant_id' => M30_LEAVE, 'name' => 'Gate '.$n, 'type' => 'gate', 'is_active' => true]);
        $roster->postShift($gate, '2026-10-16 06:00', '2026-10-16 18:00', $this->director, 'post', $g);
        GuardRequest::query()->create(['tenant_id' => M30_LEAVE, 'guard_id' => $g->id, 'kind' => 'leave', 'subject' => 'sick', 'starts_on' => '2026-10-16', 'ends_on' => '2026-10-16', 'reason' => 'unwell', 'status' => 'pending']);
    }

    $page = assertQueryBudget(60, fn () => $this->actingAs($this->director)->get('/guards/schedule?client='.M30_LEAVE.'&week=2026-10-12')->assertOk(), 'The Schedule with nine leave requests waiting');

    $page->assertInertia(fn (Assert $p) => $p->where('leaveWaiting', fn ($waiting) => count($waiting) === 9 && collect($waiting)->every(fn ($w) => count($w['shifts']) >= 1)));
});

it('decides leave only for a role that may decide it in Dispatch and take a guard off a shift', function () {
    $planner = User::factory()->create(['email' => 'm30-leave-planner-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $planner->givePermissionTo(['gemini.guard_workforce.view', 'gemini.guard_workforce.update']);
    $planner = $planner->fresh();

    $this->actingAs($planner)->post('/guards/schedule/leave/'.$this->request->id, ['decision' => 'approved', 'take_off' => true])->assertForbidden();
    expect($this->request->fresh()->status)->toBe('pending')
        ->and($this->kemars['2026-10-12']->fresh()->guard_id)->toBe($this->kemar->id);
});
