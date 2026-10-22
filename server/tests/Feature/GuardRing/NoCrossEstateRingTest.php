<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Jobs\Ring\SendRingPush;
use App\Models\DuressAlert;
use App\Models\DuressAlertRingEvent;
use App\Models\Guard;
use App\Models\Role;
use App\Services\Dispatch\AlertIntake;
use App\Services\Push\PushTokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| NEVER ANOTHER ESTATE'S PHONE — the guard ring §3.2 item 4, G7
|--------------------------------------------------------------------------
|
| A push address is ONE ROW, re-pointed to whoever registers the install next — another
| guard, another estate. So every send re-checks who may be sent it, at the moment it
| sends: a phone that moved between the ring and the stop gets estate A's stop from
| nothing — not the queued job, not the safety net.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-25T22:00:00Z'));

    $this->post = RingFixture::post();
    [$this->g1, , $this->t1] = RingFixture::guard('GS-RX-1', push: RingFixture::ready(), postId: $this->post->id);
    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);

    [$this->b, , $this->tb] = RingFixture::guard('GS-RX-B', RingFixture::OTHER_ESTATE, push: RingFixture::ready());

    $this->alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.cross@example.com', 'Lot 822'), 'duress.store', ['mode' => 'audible'])->json('id'));
    RingFixture::runJobs([RingRound::class]);
});

afterEach(fn () => RingFixture::tearDown());

it('sends no stop, no late sweep stop and no queued ring batch to a phone re-pointed to another estate', function () {
    expect(RingFixture::to($this->t1, 'site_ring'))->toHaveCount(1);

    // A ring batch for round 1 is queued for G1's phone…
    $late = new SendRingPush([$this->t1->id], $this->alert->id, 1, SendRingPush::RING);

    // …and before it runs, the same install is registered by a guard at ESTATE B.
    tenancy()->initialize(\App\Models\Tenant::query()->findOrFail(FacilitiesFixture::ESTATE));
    app(PushTokens::class)->register(new DeviceContext(AppMatrix::GUARD, RingFixture::OTHER_ESTATE, $this->b, null, Carbon::now()), null, $this->t1->expo_token, 'android', [], ['v' => 1]);
    tenancy()->end();
    FacilitiesFixture::boot();

    app()->call([$late, 'handle']);

    expect(RingFixture::sent()->where('data.r', 1))->toBeEmpty()
        ->and(DuressAlertRingEvent::query()->where('duress_alert_id', $this->alert->id)->where('push_token_id', $this->t1->id)->where('event', 'refused')->value('detail'))->toBe('entitlement');

    // The alert is answered: the stop, queued and swept, reaches nobody at estate B.
    app(AlertIntake::class)->acknowledge($this->alert, FacilitiesFixture::geminiViewer(Role::DISPATCHER));
    RingFixture::runJobs([SendRingPush::class]);

    Carbon::setTestNow(now()->addSeconds(30));
    $this->artisan('ring:sweep')->assertSuccessful();

    expect(RingFixture::sent()->where('data.type', 'ring_stop'))->toBeEmpty();
});

it('rings nobody whose guard has moved to another estate, even with the address still filed here', function () {
    // G1 transferred to estate B by a write that raised no event: the address is still filed at A.
    Guard::query()->whereKey($this->g1->id)->update(['tenant_id' => RingFixture::OTHER_ESTATE]);

    Carbon::setTestNow($this->alert->server_time->copy()->addSeconds(60));
    RingFixture::runJobs([RingRound::class]);

    expect(RingFixture::to($this->t1, 'site_ring')->where('data.r', 1))->toBeEmpty()
        ->and(RingFixture::to($this->tb))->toBeEmpty();
});
