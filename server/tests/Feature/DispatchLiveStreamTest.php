<?php

declare(strict_types=1);

use App\Events\AlertRaised;
use App\Events\ShiftClocked;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Dispatch\ShiftClock;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The dispatch screens' live stream — 13 C1, C2
|--------------------------------------------------------------------------
|
| Coverage turns on clock-ins, so a clock-in is pushed on the estate's private
| channel, thinly, and a Reverb outage never fails the clock-in itself. The
| client half — polling only while the channel is down, and the bar that says
| so — lives in `useLiveDispatch` and `DispatchConnectionBar`.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->post = Post::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'name' => 'Live Stream Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $this->guard = Guard::create([
        'full_name' => 'Live Stream Guard',
        'employee_number' => 'GS-LIVE-1',
        'psra_number' => 'PSRA-LIVE-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $this->post->id,
    ]);

    $this->shift = Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'guard_id' => $this->guard->id,
        'post_id' => $this->post->id,
        'rostered_start' => now()->subHour(),
        'rostered_end' => now()->addHours(11),
        'status' => 'rostered',
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('pushes a clock-in and a clock-out once each, on the estate\'s private channel, and nothing more', function () {
    Event::fake([ShiftClocked::class]);

    $clock = app(ShiftClock::class);

    $clock->clockIn($this->shift);
    $clock->clockIn($this->shift->refresh());   // a handset replaying its queue
    $clock->clockOut($this->shift->refresh());
    $clock->clockOut($this->shift->refresh());

    Event::assertDispatchedTimes(ShiftClocked::class, 2);

    Event::assertDispatched(ShiftClocked::class, function (ShiftClocked $event): bool {
        $channels = $event->broadcastOn();

        return $event->direction === ShiftClocked::IN
            && count($channels) === 1
            && $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-estate.'.FacilitiesFixture::ESTATE.'.alerts'
            && $event->broadcastAs() === 'shift.clocked'

            // Thin: which shift, which way — never the guard, the post or a time.
            && array_keys($event->broadcastWith()) === ['shift_id', 'direction', 'estate', 'is_simulated'];
    });
});

it('records the clock-in even when the push cannot be delivered', function () {
    Event::listen(ShiftClocked::class, static function (): never {
        throw new RuntimeException('Reverb is restarting.');
    });

    Event::listen(AlertRaised::class, static function (): never {
        throw new RuntimeException('Reverb is restarting.');
    });

    $shift = app(ShiftClock::class)->clockIn($this->shift);

    expect($shift->refresh()->actual_start)->not->toBeNull()
        ->and($shift->status)->toBe('on_duty');
});

it('gives the coverage board the estates it may listen on', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)
        ->get('/dispatch/coverage')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Dispatch/Coverage')
            ->where('estateIds', fn ($ids) => collect($ids)->contains(FacilitiesFixture::ESTATE)));
});
