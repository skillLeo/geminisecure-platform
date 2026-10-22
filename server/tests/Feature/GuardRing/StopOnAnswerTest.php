<?php

declare(strict_types=1);

use App\Jobs\Push\SendExpoPush;
use App\Jobs\Ring\RingRound;
use App\Jobs\Ring\SendRingPush;
use App\Models\DuressAlert;
use App\Models\DuressAlertRing;
use App\Models\DuressAlertRingEvent;
use App\Models\DuressAlertRingRound;
use App\Models\Role;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\AlertProgress;
use App\Services\Ring\GuardRing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| EVERY PHONE STOPS THE MOMENT ANYBODY ANSWERS — the guard ring §5, §15.1
|--------------------------------------------------------------------------
|
| An alert rung at the raise and at the escalation: G1 on duty and G3 off duty are
| call-style ready, G2 has an older Guard App. Then it is answered — every way an alert
| can be answered — and every rung phone is sent its stop: the data-only stop to the
| phones sent the call-style ring, the visible stop to the phones sent a visible re-ring.
| The later rounds are not sent, and what the stop SAYS follows why (G3).
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    Bus::fake([SendExpoPush::class]);
    Notification::fake();
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-25T15:00:00Z'));

    $this->post = RingFixture::post();

    [$this->g1, $this->b1, $this->t1] = RingFixture::guard('GS-RS-1', push: RingFixture::ready(), postId: $this->post->id);
    [$this->g2, , $this->t2] = RingFixture::guard('GS-RS-2', postId: $this->post->id);
    [$this->g3, $this->b3, $this->t3] = RingFixture::guard('GS-RS-3', push: RingFixture::ready(), postId: $this->post->id);

    RingFixture::shift($this->g1, $this->post->id, clockedOn: true);
    RingFixture::shift($this->g2, $this->post->id, clockedOn: false);

    $this->residentToken = RingFixture::residentToken('ring.stop@example.com', 'Lot 812');
    $this->alert = DuressAlert::query()->findOrFail(RingFixture::call($this, $this->residentToken, 'duress.store', ['mode' => 'audible'])->json('id'));

    $this->dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    $this->ringBoth = function (): void {
        RingFixture::runJobs([RingRound::class]);
        Carbon::setTestNow($this->alert->server_time->copy()->addSeconds(60));
        RingFixture::runJobs([RingRound::class]);
    };

    $this->stops = function (string $type, ?bool $visible = null): array {
        return RingFixture::sent()
            ->filter(fn (array $m): bool => ($m['data']['type'] ?? null) === 'ring_stop' && ($visible === null || array_key_exists('title', $m) === $visible))
            ->pluck('to')->sort()->values()->all();
    };
});

afterEach(fn () => RingFixture::tearDown());

function ringStopTokens(array $tokens): array
{
    return collect($tokens)->map(fn ($t) => $t->expo_token)->sort()->values()->all();
}

it('stops every rung phone on a console acknowledge, and sends none of the later rounds', function () {
    ($this->ringBoth)();

    Carbon::setTestNow(now()->addSeconds(10));
    app(AlertIntake::class)->acknowledge($this->alert, $this->dispatcher);

    RingFixture::runJobs([SendRingPush::class]);

    // Data-only to the phones sent the call-style ring; visible to the phones sent a visible re-ring.
    expect(($this->stops)('ring_stop', false))->toBe(ringStopTokens([$this->t1, $this->t3]))
        ->and(($this->stops)('ring_stop', true))->toBe(ringStopTokens([$this->t1, $this->t2, $this->t3]));

    $data = RingFixture::to($this->t1, 'ring_stop')->first(fn (array $m): bool => ! isset($m['title']));

    expect(array_keys($data))->toBe(['to', 'priority', 'ttl', 'data'])
        ->and($data['priority'])->toBe('high')
        ->and($data['data'])->toBe(['type' => 'ring_stop', 'v' => 1, 'id' => (string) $this->alert->id, 'why' => 'acknowledged']);

    // THE WORDS (G3): an acknowledge is not a stand-down.
    $visible = RingFixture::to($this->t2, 'ring_stop')->first();

    expect($visible['title'])->toBe('Ringing stopped · Panic alert')
        ->and($visible['body'])->toBe('At Lot 812: the control room has this alert. Follow dispatch\'s instructions.')
        ->and($visible['title'].$visible['body'])->not->toContain('Stood down')
        ->and($visible['body'])->not->toContain('Nothing more for you to do')
        ->and($visible['tag'])->toBe('ring-'.$this->alert->id)
        ->and($visible['channelId'])->toBe('general');

    // The rounds after it are settled as not sent, and a late job for one sends nothing.
    $later = DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->where('round', '>=', 2)->pluck('outcome')->unique()->all();
    expect($later)->toBe(['skipped_answered']);

    $before = RingFixture::sent()->count();
    Carbon::setTestNow($this->alert->server_time->copy()->addSeconds(200));
    app()->call([new RingRound($this->alert->id, 2), 'handle']);

    expect(RingFixture::sent()->count())->toBe($before)
        ->and(DuressAlertRing::query()->where('duress_alert_id', $this->alert->id)->value('answered_why'))->toBe('acknowledged');
});

it('stops on an acknowledge made while an officer is already responding, where the status does not change', function () {
    ($this->ringBoth)();

    // G1 takes it from their own handset first: the status becomes `responding`…
    RingFixture::call($this, $this->b1, 'alerts.en_route', path: ['alert' => $this->alert->id]);
    RingFixture::runJobs([SendRingPush::class]);
    $first = RingFixture::sent()->where('data.type', 'ring_stop')->count();

    // …and the control room acknowledges afterwards: no status event, and nothing new owed.
    app(AlertIntake::class)->acknowledge($this->alert->fresh(), $this->dispatcher);
    RingFixture::runJobs([SendRingPush::class]);

    expect($this->alert->fresh()->status)->toBe('responding')
        ->and($first)->toBe(5)
        ->and(RingFixture::sent()->where('data.type', 'ring_stop')->count())->toBe(5);

    // A guard's "on my way", from inside the estate's own request, stopped the ring in the central record (G5).
    $header = DuressAlertRing::query()->where('duress_alert_id', $this->alert->id)->firstOrFail();

    expect($header->answered_why)->toBe('responding')
        ->and($header->answered_at)->not->toBeNull()
        ->and(RingFixture::to($this->t2, 'ring_stop')->first()['body'])->toBe('At Lot 812: Officer GS-RS-1 is responding. Follow dispatch\'s instructions.')
        ->and(RingFixture::to($this->t3, 'ring_stop')->first(fn (array $m): bool => ! isset($m['title']))['data']['who'])->toBe('Officer GS-RS-1');
});

it('stops on a dispatch, on scene, a resolve and a false alarm — and two racing answers give one stop per phone', function (string $how) {
    ($this->ringBoth)();

    match ($how) {
        'dispatch' => app(AlertProgress::class)->dispatch($this->alert, $this->g1, $this->dispatcher),
        'on scene' => RingFixture::call($this, $this->b1, 'alerts.on_scene', path: ['alert' => $this->alert->id]),
        'resolve' => app(AlertIntake::class)->resolve($this->alert, $this->dispatcher, 'Handled.', classification: 'genuine_other'),
        'false alarm' => app(AlertIntake::class)->resolve($this->alert, $this->dispatcher, 'Pocket press.', falseAlarm: true, classification: 'false_alarm'),
    };

    // A second answer, racing the first: acknowledged too.
    app(AlertIntake::class)->acknowledge($this->alert->fresh(), $this->dispatcher);

    RingFixture::runJobs([SendRingPush::class]);

    $perPhone = RingFixture::sent()->where('data.type', 'ring_stop')->countBy(fn (array $m): string => $m['to'].(isset($m['title']) ? ':visible' : ':data'));

    expect($perPhone->values()->unique()->all())->toBe([1])
        ->and($perPhone)->toHaveCount(5);

    $why = DuressAlertRing::query()->where('duress_alert_id', $this->alert->id)->value('answered_why');
    $words = RingFixture::to($this->t2, 'ring_stop')->first()['title'];

    if (in_array($how, ['resolve', 'false alarm'], true)) {
        expect($why)->toBe('closed')->and($words)->toBe('Stood down · Panic alert')
            ->and(RingFixture::to($this->t2, 'ring_stop')->first()['body'])->toBe('At Lot 812: the alert was closed. Nothing more for you to do.');
    } else {
        expect($why)->toBe('responding')->and($words)->toBe('Ringing stopped · Panic alert');
    }
})->with(['dispatch', 'on scene', 'resolve', 'false alarm']);

it('stops on the raiser\'s own ten-second cancel, and says the alert was cancelled', function () {
    RingFixture::runJobs([RingRound::class]);

    Carbon::setTestNow(now()->addSeconds(5));
    RingFixture::call($this, $this->residentToken, 'duress.cancel', path: ['alert' => $this->alert->id]);
    RingFixture::runJobs([SendRingPush::class]);

    $stop = RingFixture::to($this->t1, 'ring_stop')->first();

    expect($stop['data']['why'])->toBe('cancelled')
        ->and(DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->where('round', 1)->value('outcome'))->toBe('skipped_answered');

    // At 60 s the escalation's job finds nothing to send.
    Carbon::setTestNow($this->alert->server_time->copy()->addSeconds(60));
    $before = RingFixture::sent()->count();
    RingFixture::runJobs([RingRound::class]);

    expect(RingFixture::sent()->count())->toBe($before);
});

it('does not send the rest of a round once the alert is answered between two of its batches, and stops the phones the first batch reached (G6)', function () {
    config(['push.batch' => 1]);

    [, , $t4] = RingFixture::guard('GS-RS-4', push: RingFixture::ready(), postId: $this->post->id);
    RingFixture::shift(\App\Models\Guard::query()->findOrFail($t4->principal_id), $this->post->id, clockedOn: true);

    RingFixture::runJobs([RingRound::class]);

    // At 60 s, the first batch Expo is handed is answered by the control room in the same breath.
    Carbon::setTestNow($this->alert->server_time->copy()->addSeconds(60));
    $answered = false;

    \Illuminate\Support\Facades\Http::fake([
        'exp.host/--/api/v2/push/send' => function (\Illuminate\Http\Client\Request $request) use (&$answered) {
            if (! $answered) {
                $answered = true;
                app(AlertIntake::class)->acknowledge($this->alert->fresh(), $this->dispatcher);
            }

            return \Illuminate\Support\Facades\Http::response(['data' => array_map(fn () => ['status' => 'ok', 'id' => (string) \Illuminate\Support\Str::uuid()], $request->data())]);
        },
    ]);

    RingFixture::runJobs([RingRound::class]);

    $round1 = DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->where('round', 1)->firstOrFail();

    expect($round1->outcome)->toBe('partly_sent')
        ->and($round1->note)->toContain('answered while it was going');

    // The one phone the first batch reached is owed its stop, and is sent it.
    RingFixture::runJobs([SendRingPush::class]);

    $rung = DuressAlertRingEvent::query()->where('duress_alert_id', $this->alert->id)->where('round', 1)->where('event', 'sent_ring')->pluck('push_token_id')->all();
    $stopped = DuressAlertRingEvent::query()->where('duress_alert_id', $this->alert->id)->where('round', 1)->where('event', 'sent_stop')->pluck('push_token_id')->all();

    expect($rung)->toHaveCount(1)->and($stopped)->toBe($rung);
});

it('under ruling R9\'s alternative, stops the phones on an acknowledge but still escalates at 60 seconds', function () {
    config(['ring.ack_cancels_escalation' => false]);

    RingFixture::runJobs([RingRound::class]);

    Carbon::setTestNow(now()->addSeconds(5));
    app(AlertIntake::class)->acknowledge($this->alert, $this->dispatcher);
    RingFixture::runJobs([SendRingPush::class]);

    // The phones rung at the raise are told the control room has it.
    expect(RingFixture::to($this->t1, 'ring_stop'))->toHaveCount(1);

    // The escalation still goes at 60 s — to the off-duty officer too — and rounds 2+ do not.
    Carbon::setTestNow($this->alert->server_time->copy()->addSeconds(60));
    RingFixture::runJobs([RingRound::class]);

    expect(RingFixture::to($this->t3, 'site_ring'))->toHaveCount(1)
        ->and(DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->where('round', 1)->value('outcome'))->toBe('sent')
        ->and(DuressAlertRingRound::query()->where('duress_alert_id', $this->alert->id)->where('round', '>=', 2)->pluck('outcome')->unique()->all())->toBe(['skipped_answered']);

    // And an officer sent afterwards stops the escalation's ringing too.
    app(AlertProgress::class)->dispatch($this->alert->fresh(), $this->g1, $this->dispatcher);
    RingFixture::runJobs([SendRingPush::class]);

    expect(RingFixture::to($this->t3, 'ring_stop'))->not->toBeEmpty();
});

it('can be asked to stop again and again, and sends each phone its stop once', function () {
    ($this->ringBoth)();

    app(AlertIntake::class)->acknowledge($this->alert, $this->dispatcher);

    foreach (range(1, 3) as $ignored) {
        app(GuardRing::class)->stop($this->alert->fresh());
    }

    RingFixture::runJobs([SendRingPush::class]);

    expect(RingFixture::sent()->where('data.type', 'ring_stop')->count())->toBe(5);
});
