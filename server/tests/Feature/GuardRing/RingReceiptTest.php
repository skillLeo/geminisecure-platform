<?php

declare(strict_types=1);

use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Models\DuressAlert;
use App\Models\DuressAlertRingEvent;
use App\Models\DuressAlertRingTarget;
use App\Services\Ring\RingTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| WHAT THE PHONE SAYS IT DID WITH A RING — the guard ring §6.6
|--------------------------------------------------------------------------
|
| `shown` is the only thing the console may call "shown on the phone", and its detail is
| the phone's own sound check at that moment. Every receipt is recorded once per phone,
| round and event; `dismissed` and `opened` are recorded and neither is an answer.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-25T19:00:00Z'));

    $this->post = RingFixture::post();
    [$this->g1, , $this->t1] = RingFixture::guard('GS-RR-1', push: RingFixture::ready('partial'), postId: $this->post->id);
    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);

    $this->alert = DuressAlert::query()->findOrFail(RingFixture::call($this, RingFixture::residentToken('ring.receipt@example.com', 'Lot 816'), 'duress.store', ['mode' => 'audible'])->json('id'));
    RingFixture::runJobs([RingRound::class]);

    $this->rt = RingFixture::ticketFor($this->t1, 0);
    $this->receipt = fn (array $body, ?int $status = null) => RingFixture::call($this, null, 'ring.receipt', ['rt' => $this->rt, ...$body], status: $status);
    $this->events = fn (string $event) => DuressAlertRingEvent::query()->where('duress_alert_id', $this->alert->id)->where('push_token_id', $this->t1->id)->where('event', $event)->get();
});

afterEach(fn () => RingFixture::tearDown());

it('records a receipt once per phone, round and event, with the phone\'s own sound check', function () {
    ($this->receipt)(['event' => 'shown', 'detail' => 'imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=1']);
    ($this->receipt)(['event' => 'shown', 'detail' => 'imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=1']);

    $shown = ($this->events)('shown');

    expect($shown)->toHaveCount(1)
        ->and($shown->first()->round)->toBe(0)
        ->and($shown->first()->guard_id)->toBe($this->g1->id)
        ->and($shown->first()->detail)->toBe('imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=1')
        ->and($this->t1->fresh()->ring_last_shown_at)->not->toBeNull()
        // A real ring's `shown` does not prove the phone: only a test ring's does.
        ->and($this->t1->fresh()->ring_proven_at)->toBeNull();
});

it('updates the phone\'s readiness from the mask a receipt carries, and says when (G15)', function () {
    Carbon::setTestNow(now()->addMinutes(3));

    // Everything on: notifications, channel, full screen, DND bypass, battery, background, volume, tone, screen lock.
    ($this->receipt)(['event' => 'shown', 'ready' => 0b111111111]);

    $token = $this->t1->fresh();

    expect($token->ring_state)->toBe('ready')
        ->and($token->ring_checked_at->getTimestamp())->toBe(now()->getTimestamp())
        ->and($token->ring_readiness)->toMatchArray(['notifications' => 'on', 'alarm_volume' => 'ok', 'screen_lock' => 'on', 'configured' => true]);

    // Full-screen alerts switched off since: not ready, however good the rest.
    ($this->receipt)(['event' => 'opened', 'ready' => 0b111111011]);

    expect($this->t1->fresh()->ring_state)->toBe('not_ready');

    // A mask the server cannot read is ignored, never refused.
    ($this->receipt)(['event' => 'held', 'ready' => 'lots']);

    expect($this->t1->fresh()->ring_state)->toBe('not_ready');
});

it('records dismissed and opened as what they are — neither declines, neither answers (G12, G18)', function () {
    ($this->receipt)(['event' => 'dismissed']);
    ($this->receipt)(['event' => 'opened']);
    ($this->receipt)(['event' => 'full_screen']);

    expect(($this->events)('dismissed'))->toHaveCount(1)
        ->and(($this->events)('opened'))->toHaveCount(1)
        ->and(($this->events)('full_screen'))->toHaveCount(1)
        ->and(DuressAlertRingTarget::query()->where('duress_alert_id', $this->alert->id)->where('guard_id', $this->g1->id)->value('answer'))->toBeNull()
        ->and($this->alert->fresh()->responding_guard_id)->toBeNull();
});

it('keeps a detail it cannot read as it came, and it counts as sound not confirmed (G11)', function () {
    ($this->receipt)(['event' => 'shown', 'detail' => 'volume looked fine?']);

    expect(($this->events)('shown')->first()->detail)->toBe('volume looked fine?');
});

it('sets proven only on a TEST ring\'s shown', function () {
    $test = RingTicket::issue(['a' => 0, 't' => FacilitiesFixture::ESTATE, 'g' => $this->g1->id, 'p' => $this->t1->id, 'r' => 0, 's' => 1, 'k' => 'test']);

    RingFixture::call($this, null, 'ring.receipt', ['rt' => $test, 'event' => 'shown', 'detail' => 'imp=4;snd=1;vol=7/7;dnd=pass;fsi=1;lk=1']);

    expect($this->t1->fresh()->ring_proven_at)->not->toBeNull()
        ->and(DuressAlertRingEvent::query()->where('duress_alert_id', 0)->where('test_seq', 1)->where('push_token_id', $this->t1->id)->where('event', 'shown')->exists())->toBeTrue();
});

it('refuses an event it does not know, and a ticket it did not sign', function () {
    ($this->receipt)(['event' => 'rang'], 422);

    RingFixture::call($this, null, 'ring.receipt', ['rt' => 'v1.nope.nope', 'event' => 'shown'], status: 401);
});
