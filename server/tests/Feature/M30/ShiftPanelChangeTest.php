<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Gemini\Roster;
use App\Services\Push\PushAnnouncer;
use App\Services\Push\PushMessage;
use App\Services\Push\PushNotifier;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M30 §5.2, §13: the shift panel opened on a shift — change it, take its guard off, remove it
|--------------------------------------------------------------------------
|
| The panel reads one shift (`GET /guards/schedule/shifts/{id}`) and changes it
| through the Roster, which holds a change to every rule a new shift and an
| assignment are held to. A guard replaced or taken off is told on his phone,
| with the reason; the guard put on it is told by the shift's own change. A shift
| that has started keeps its gate and times; one being worked, or worked, keeps
| everything. Only a shift that has not started can be removed.
|
| The phones are caught at the notifier, as they would leave. The transaction
| manager is Laravel's own for tests, as `DatabaseTransactions` installs it, so
| what waits for a commit runs once only this file's wrapping transaction is left.
|
| Frozen at Wednesday 7 October 2026, 10:00 AM in Kingston.
|
*/

const M30_CHANGE = 'm30change';

beforeEach(function () {
    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'UTC'));
    FacilitiesFixture::platform();

    DB::connection('mysql')->table('tenants')->where('id', M30_CHANGE)->delete();
    DB::connection('mysql')->table('tenants')->insert(['id' => M30_CHANGE, 'name' => 'Change Test Estate', 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    SiteTime::flush();

    $this->main = Post::query()->create(['tenant_id' => M30_CHANGE, 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $this->back = Post::query()->create(['tenant_id' => M30_CHANGE, 'name' => 'Back Gate', 'type' => 'gate', 'is_active' => true]);

    $this->guard = static function (string $name): Guard {
        $n = random_int(100000, 999999);

        return Guard::query()->create([
            'full_name' => $name, 'employee_number' => 'GS-C'.$n, 'psra_number' => 'PSRA-C'.$n, 'psra_expires_on' => '2027-06-23',
            'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => M30_CHANGE,
        ]);
    };

    $this->andre = ($this->guard)('Andre Campbell');
    $this->kemoy = ($this->guard)('Kemoy Allen');

    $this->director = User::factory()->create(['name' => 'Dora Director', 'email' => 'm30-change-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $this->director->syncRoles([Role::DIRECTOR]);
    $this->director = $this->director->fresh();

    // Friday 9 October, 6 AM to 6 PM at the Main Gate, Andre Campbell on it.
    $this->shift = app(Roster::class)->postShift($this->main, '2026-10-09 06:00', '2026-10-09 18:00', $this->director, 'post', $this->andre);

    // Every push, as it would leave for a guard's phone.
    $this->pushed = [];
    $notifier = Mockery::mock(PushNotifier::class);
    $notifier->shouldReceive('toGuard')->andReturnUsing(function (string $tenant, int $guardId, PushMessage $message): int {
        $this->pushed[] = ['guard' => $guardId, 'title' => $message->title, 'body' => $message->body];

        return 1;
    });
    $notifier->shouldReceive('toGuards')->andReturn(0);
    app()->instance(PushNotifier::class, $notifier);
    app()->forgetInstance(PushAnnouncer::class);

    $this->change = fn (array $over = [], ?Shift $shift = null): TestResponse => $this->actingAs($this->director)->post('/guards/schedule/shifts/'.($shift ?? $this->shift)->id, [
        'post_id' => $this->main->id, 'date' => '2026-10-09', 'starts' => '06:00', 'ends' => '18:00', 'duty' => 'post', 'guard_id' => $this->andre->id, ...$over,
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    app()->forgetInstance(PushNotifier::class);
});

it('opens on a shift with its form on the client\'s clock, what can still change, and its history', function () {
    $view = $this->actingAs($this->director)->getJson('/guards/schedule/shifts/'.$this->shift->id)->assertOk()->json();

    expect($view['title'])->toBe('Main Gate · Fri 9 Oct · 6:00 AM – 6:00 PM JA')
        ->and($view['form'])->toMatchArray(['post_id' => $this->main->id, 'date' => '2026-10-09', 'starts' => '06:00', 'ends' => '18:00', 'duty' => 'post', 'guard_id' => $this->andre->id])
        ->and($view['can'])->toBe(['change_times' => true, 'change_guard' => true, 'take_off' => true, 'remove' => true])
        ->and($view['history'][0]['text'])->toBe('Added by Dora Director, with Andre Campbell on it')
        ->and($view['history'][0]['at'])->toEndWith(' JA');
});

it('changes the guard with a reason, records who changed it, and tells both guards\' phones', function () {
    ($this->change)(['guard_id' => $this->kemoy->id, 'reason' => 'Andre asked to swap'])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Shift changed.');

    expect($this->shift->fresh()->guard_id)->toBe($this->kemoy->id);

    $logged = DB::connection('mysql')->table('audit_log')->where('action', 'roster.shift_changed')->where('entity_id', (string) $this->shift->id)->first();
    expect($logged)->not->toBeNull()
        ->and($logged->actor_name)->toBe('Dora Director')
        ->and(json_decode((string) $logged->before, true)['guard'])->toBe('Andre Campbell')
        ->and(json_decode((string) $logged->after, true))->toMatchArray(['guard' => 'Kemoy Allen', 'reason' => 'Andre asked to swap', 'by' => 'Dora Director']);

    $toAndre = collect($this->pushed)->firstWhere('guard', $this->andre->id);
    $toKemoy = collect($this->pushed)->firstWhere('guard', $this->kemoy->id);
    expect($toAndre['title'])->toBe('A shift is no longer yours')
        ->and($toAndre['body'])->toBe('Main Gate · Fri 9 Oct, 6:00 AM JA. Reason: Andre asked to swap. Do not report for it.')
        ->and($toKemoy['title'])->toBe('Your roster changed');

    $history = $this->actingAs($this->director)->getJson('/guards/schedule/shifts/'.$this->shift->id)->json('history');
    expect(collect($history)->pluck('text')->last())->toBe('Changed by Dora Director: guard Andre Campbell to Kemoy Allen (Andre asked to swap)');
});

it('asks why before a guard is replaced, in the panel and on the server', function () {
    $answer = $this->actingAs($this->director)->postJson('/guards/schedule/check', [
        'shift_id' => $this->shift->id, 'post_id' => $this->main->id, 'date' => '2026-10-09', 'starts' => '06:00', 'ends' => '18:00', 'duty' => 'post', 'guard_id' => $this->kemoy->id,
    ])->assertOk()->json();

    expect($answer['ok'])->toBeFalse()
        ->and(collect($answer['checks'])->where('ok', false)->pluck('text')->all())->toBe(['Say why Andre Campbell comes off this shift. Their phone is told.'])
        // Its own gate and times are not asked again: they were met when it was added.
        ->and(collect($answer['checks'])->pluck('text')->first())->toBe('Same gate and times as before');

    ($this->change)(['guard_id' => $this->kemoy->id])->assertSessionHasErrors(['panel' => 'Say why Andre Campbell comes off this shift. Their phone is told.']);
    expect($this->shift->fresh()->guard_id)->toBe($this->andre->id)->and($this->pushed)->toBe([]);
});

it('moves a shift to another gate and time, held to the same rules as a new one', function () {
    ($this->change)(['post_id' => $this->back->id, 'starts' => '07:00', 'ends' => '19:00'])->assertRedirect()->assertSessionHasNoErrors();

    $moved = $this->shift->fresh();
    expect($moved->post_id)->toBe($this->back->id)
        ->and($moved->rostered_start->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-10-09 12:00')
        ->and(collect($this->pushed)->firstWhere('guard', $this->andre->id)['title'])->toBe('Your roster changed');

    // Onto a gate that already has a shift at that time: refused, by the panel's rule and by the server.
    app(Roster::class)->postOpenShift($this->main, '2026-10-10 06:00', '2026-10-10 18:00', $this->director);
    ($this->change)(['post_id' => $this->main->id, 'date' => '2026-10-10', 'starts' => '10:00', 'ends' => '14:00'])->assertSessionHasErrors('panel');
    expect($this->shift->fresh()->post_id)->toBe($this->back->id);
});

it('takes the guard off with the reason on the shift, which then needs a guard, and tells his phone', function () {
    $this->actingAs($this->director)->post('/guards/schedule/shifts/'.$this->shift->id.'/take-off', ['reason' => 'Off sick'])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Andre Campbell was taken off the shift. It needs a guard.');

    $open = $this->shift->fresh();
    expect($open->guard_id)->toBeNull()
        ->and($open->status)->toBe(Shift::OPEN)
        ->and($open->released_reason)->toBe('Off sick')
        ->and(collect($this->pushed)->firstWhere('guard', $this->andre->id)['body'])->toContain('Reason: Off sick');

    $view = $this->actingAs($this->director)->getJson('/guards/schedule/shifts/'.$this->shift->id)->json();
    expect($view['note'])->toBe('Off sick')
        ->and(collect($view['history'])->pluck('text')->last())->toBe('Andre Campbell taken off by Dora Director: Off sick');

    // Without a reason, nothing happens.
    $other = app(Roster::class)->postShift($this->back, '2026-10-12 06:00', '2026-10-12 18:00', $this->director, 'post', $this->kemoy);
    $this->actingAs($this->director)->post('/guards/schedule/shifts/'.$other->id.'/take-off', ['reason' => ''])->assertSessionHasErrors('panel');
    expect($other->fresh()->guard_id)->toBe($this->kemoy->id);
});

it('refuses to change or take off a guard who has clocked in', function () {
    $now = app(Roster::class)->postShift($this->back, '2026-10-07 06:00', '2026-10-07 18:00', $this->director, 'post', $this->kemoy);
    $now->forceFill(['actual_start' => Carbon::parse('2026-10-07 11:05:00', 'UTC'), 'status' => 'on_duty'])->save();

    $this->actingAs($this->director)->post('/guards/schedule/shifts/'.$now->id.'/take-off', ['reason' => 'Off sick'])
        ->assertSessionHasErrors(['panel' => 'Kemoy Allen has clocked in to this shift. Nothing on it changes until they clock out.']);

    ($this->change)(['post_id' => $this->back->id, 'date' => '2026-10-07', 'guard_id' => $this->andre->id, 'reason' => 'swap'], $now)->assertSessionHasErrors('panel');
    expect($now->fresh()->guard_id)->toBe($this->kemoy->id);
});

it('keeps a started shift\'s gate and times, and can still change who stands it', function () {
    // Started at 6 AM today, nobody clocked in: a no-show to cover.
    $started = app(Roster::class)->postShift($this->back, '2026-10-07 06:00', '2026-10-07 18:00', $this->director, 'post', $this->kemoy);

    ($this->change)(['post_id' => $this->back->id, 'date' => '2026-10-07', 'starts' => '08:00', 'guard_id' => $this->kemoy->id], $started)
        ->assertSessionHasErrors(['panel' => 'This shift has started, so its gate and times stay as they are. Who stands it can still change.']);

    // Andre's own shift is on Friday; today he is free.
    ($this->change)(['post_id' => $this->back->id, 'date' => '2026-10-07', 'guard_id' => $this->andre->id, 'reason' => 'Kemoy did not arrive'], $started)->assertRedirect()->assertSessionHasNoErrors();
    expect($started->fresh()->guard_id)->toBe($this->andre->id);
});

it('removes a shift that has not started, records the reason, and tells the guard who was on it', function () {
    $this->actingAs($this->director)->post('/guards/schedule/shifts/'.$this->shift->id.'/remove', ['reason' => 'Gate closed for repairs'])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Shift removed.');

    expect(Shift::query()->find($this->shift->id))->toBeNull();

    $logged = DB::connection('mysql')->table('audit_log')->where('action', 'roster.shift_removed')->where('entity_id', (string) $this->shift->id)->first();
    expect(json_decode((string) $logged->after, true))->toMatchArray(['reason' => 'Gate closed for repairs', 'by' => 'Dora Director'])
        ->and(json_decode((string) $logged->before, true))->toMatchArray(['post' => 'Main Gate', 'guard' => 'Andre Campbell'])
        ->and(collect($this->pushed)->firstWhere('guard', $this->andre->id)['body'])->toBe('Main Gate · Fri 9 Oct, 6:00 AM JA. It was removed from the roster. Reason: Gate closed for repairs. Do not report for it.');
});

it('refuses to remove a shift that has started', function () {
    $started = app(Roster::class)->postOpenShift($this->back, '2026-10-07 06:00', '2026-10-07 18:00', $this->director);

    $this->actingAs($this->director)->post('/guards/schedule/shifts/'.$started->id.'/remove', ['reason' => 'Not needed'])
        ->assertSessionHasErrors(['panel' => 'This shift has started, so it stays on the record. Only a shift that has not started can be removed.']);

    expect(Shift::query()->find($started->id))->not->toBeNull();
});

it('answers the claims on an open shift when it is given to one of the guards who asked', function () {
    $open = app(Roster::class)->postOpenShift($this->back, '2026-10-12 06:00', '2026-10-12 18:00', $this->director);
    foreach ([$this->andre, $this->kemoy] as $asker) {
        DB::connection('mysql')->table('shift_claims')->insert(['shift_id' => $open->id, 'guard_id' => $asker->id, 'status' => 'pending', 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    $asked = $this->actingAs($this->director)->postJson('/guards/schedule/check', ['shift_id' => $open->id, 'post_id' => $this->back->id, 'date' => '2026-10-12', 'starts' => '06:00', 'ends' => '18:00'])->json('guards');
    expect(collect($asked)->firstWhere('id', $this->kemoy->id)['asked'])->toBeTrue();

    ($this->change)(['post_id' => $this->back->id, 'date' => '2026-10-12', 'guard_id' => $this->kemoy->id], $open)->assertRedirect()->assertSessionHasNoErrors();

    $claims = DB::connection('mysql')->table('shift_claims')->where('shift_id', $open->id)->pluck('status', 'guard_id');
    expect($claims[$this->kemoy->id])->toBe('approved')
        ->and($claims[$this->andre->id])->toBe('declined');
});

it('does not show or change a shift at an estate the viewer does not reach', function () {
    $scoped = User::factory()->create(['email' => 'm30-change-hos-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $scoped->syncRoles([Role::HEAD_OF_SECURITY]);
    EstateAssignment::query()->create(['user_id' => $scoped->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true]);
    $scoped = $scoped->fresh();

    $this->actingAs($scoped)->getJson('/guards/schedule/shifts/'.$this->shift->id)->assertNotFound();
    $this->actingAs($scoped)->post('/guards/schedule/shifts/'.$this->shift->id.'/remove', ['reason' => 'Not needed'])->assertNotFound();
    expect(Shift::query()->find($this->shift->id))->not->toBeNull();
});
