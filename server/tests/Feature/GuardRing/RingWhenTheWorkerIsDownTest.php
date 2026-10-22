<?php

declare(strict_types=1);

use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Models\DuressAlert;
use App\Models\DuressAlertRingEvent;
use App\Models\DuressAlertRingRound;
use App\Models\Role;
use App\Services\Dispatch\AlertIntake;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| THE RING KEEPS GOING WITH THE QUEUE WORKERS DEAD — the guard ring §4.3, §4.4
|--------------------------------------------------------------------------
|
| Nothing in this file runs a queued job. The two panic workers are "down"; only
| cron's `schedule:run` is alive, and with it `ring:sweep`, which sends inline. The
| clock is faked, so it is proven that the net never fires early and never sends a
| phone the same ring twice.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-25T16:00:00Z'));

    $this->post = RingFixture::post();

    [$this->g1, , $this->t1] = RingFixture::guard('GS-RD-1', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g3, , $this->t3] = RingFixture::guard('GS-RD-3', push: RingFixture::ready(), postId: $this->post->id);

    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);

    $this->alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.down@example.com', 'Lot 813'), 'duress.store', ['mode' => 'audible'])->json('id'));

    $this->at = fn (int $seconds) => Carbon::setTestNow($this->alert->server_time->copy()->addSeconds($seconds));
    $this->sweep = fn () => $this->artisan('ring:sweep')->assertSuccessful();
    $this->round = fn (int $r): DuressAlertRingRound => DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->where('round', $r)->firstOrFail();
});

afterEach(fn () => RingFixture::tearDown());

it('touches no network inside the panic\'s own request', function () {
    expect(Http::recorded()->all())->toBe([]);
});

it('never fires early: nothing at 5 or 9 seconds, round 0 at 12 by the safety net, and the late job then does nothing', function () {
    ($this->at)(5);
    ($this->sweep)();
    ($this->at)(9);
    ($this->sweep)();

    expect(RingFixture::sent())->toBeEmpty()
        ->and(($this->round)(0)->claimed_at)->toBeNull();

    ($this->at)(12);
    ($this->sweep)();

    expect(RingFixture::to($this->t1, 'site_ring'))->toHaveCount(1)
        ->and(($this->round)(0)->claimed_by)->toBe('sweep')
        ->and(($this->round)(0)->note)->toContain('Sent by the safety net 12 s late');

    // The queued round-0 job, run at last, finds the round already sent.
    RingFixture::runJobs([RingRound::class]);

    expect(RingFixture::sent())->toHaveCount(1);
});

it('does not claim the escalation at 59 seconds, and sends it at 72', function () {
    ($this->at)(12);
    ($this->sweep)();

    ($this->at)(59);
    ($this->sweep)();

    expect(($this->round)(1)->claimed_at)->toBeNull();

    ($this->at)(72);
    ($this->sweep)();

    expect(($this->round)(1)->outcome)->toBe('sent')
        ->and(($this->round)(1)->claimed_by)->toBe('sweep')
        ->and(RingFixture::to($this->t3, 'site_ring'))->toHaveCount(1);
});

it('collapses rounds that fell due while nothing ran into one, never firing them back to back', function () {
    ($this->at)(305);
    ($this->sweep)();

    expect(DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->orderBy('round')->pluck('outcome')->take(4)->all())
        ->toBe(['skipped_late', 'skipped_late', 'skipped_late', 'sent'])
        ->and(RingFixture::to($this->t1, 'site_ring'))->toHaveCount(1)
        ->and(RingFixture::to($this->t1, 'site_ring')->first()['data']['r'])->toBe(3)
        // The held-up escalation went out inside it: the off-duty officer is rung too, and it says so.
        ->and(RingFixture::to($this->t3, 'site_ring'))->toHaveCount(1)
        ->and(($this->round)(3)->note)->toContain('with the escalation that was held up')
        ->and(($this->round)(3)->heads_texted)->not->toBeNull();

    // Swept again, nothing is fired back to back.
    ($this->sweep)();
    expect(RingFixture::sent()->where('data.type', 'site_ring'))->toHaveCount(2);
});

it('sends the stop inline 10 seconds after an answer nobody\'s worker sent it for', function () {
    ($this->at)(12);
    ($this->sweep)();

    ($this->at)(20);
    app(AlertIntake::class)->acknowledge($this->alert, FacilitiesFixture::geminiViewer(Role::DISPATCHER));

    // The queued stop never runs. At 25 s the net still gives it its chance…
    ($this->at)(25);
    ($this->sweep)();
    expect(RingFixture::to($this->t1, 'ring_stop'))->toBeEmpty();

    // …and at 31 s sends it itself.
    ($this->at)(31);
    ($this->sweep)();

    expect(RingFixture::to($this->t1, 'ring_stop'))->toHaveCount(1);

    // Swept again, nothing more.
    ($this->at)(46);
    ($this->sweep)();

    expect(RingFixture::to($this->t1, 'ring_stop'))->toHaveCount(1);
});

it('takes over a claim that died, and rings nobody twice', function () {
    ($this->at)(12);
    ($this->sweep)();

    // Round 1 was claimed by a worker that died before finishing, having rung G1 only.
    ($this->at)(61);
    DuressAlertRingRound::query()->whereKey(($this->round)(1)->id)->update(['claimed_at' => now(), 'claimed_by' => 'job']);
    DuressAlertRingEvent::once(['duress_alert_id' => $this->alert->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'round' => 1, 'push_token_id' => $this->t1->id, 'event' => 'sent_ring', 'detail' => 'ready']);

    ($this->at)(120);
    ($this->sweep)();
    expect(($this->round)(1)->finished_at)->toBeNull();

    ($this->at)(152);
    ($this->sweep)();

    expect(($this->round)(1)->finished_at)->not->toBeNull()
        ->and(RingFixture::sent()->filter(fn (array $m): bool => ($m['data']['r'] ?? null) === 1 && $m['data']['type'] === 'site_ring')->pluck('to')->all())
        ->toBe([$this->t3->expo_token]);
});

it('repairs a stop one phone is still owed, per phone, not per alert (G6)', function () {
    ($this->at)(12);
    ($this->sweep)();

    ($this->at)(20);
    app(AlertIntake::class)->acknowledge($this->alert, FacilitiesFixture::geminiViewer(Role::DISPATCHER));
    ($this->at)(31);
    ($this->sweep)();

    // A ring that landed after the stop list was built: G3 was sent round 2 and never stopped.
    \App\Services\Ring\RingTargets::add($this->alert, [$this->g3->id], 'estate', 2);
    DuressAlertRingEvent::once(['duress_alert_id' => $this->alert->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'round' => 2, 'push_token_id' => $this->t3->id, 'event' => 'sent_ring', 'detail' => 'ready']);

    ($this->at)(45);
    ($this->sweep)();

    expect(RingFixture::to($this->t3, 'ring_stop'))->toHaveCount(1)
        ->and(DuressAlertRingEvent::query()->where('duress_alert_id', $this->alert->id)->where('push_token_id', $this->t3->id)->where('event', 'sent_stop')->value('round'))->toBe(2);
});
