<?php

declare(strict_types=1);

use App\Jobs\Ring\SendRingPush;
use App\Models\DuressAlertRingEvent;
use App\Services\Ring\RingTicket;
use Illuminate\Support\Carbon;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| "SEND A TEST RING" — the guard ring §6.6 ring.test, G24
|--------------------------------------------------------------------------
|
| A real push down the whole chain to this sign-in's own phone and nobody else's. Its
| `shown` receipt is what proves the phone; and a second test a minute later sends and
| records exactly like the first.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    RingFixture::expo();

    Carbon::setTestNow(Carbon::parse('2026-09-25T20:00:00Z'));

    [$this->guard, $this->bearer, $this->token] = RingFixture::guard('GS-RT-1', push: RingFixture::ready());
    [, , $this->other] = RingFixture::guard('GS-RT-2', push: RingFixture::ready());
});

afterEach(fn () => RingFixture::tearDown());

it('sends one test ring to this phone only, marked a drill, with a test ticket', function () {
    $answer = RingFixture::call($this, $this->bearer, 'ring.test');

    expect($answer->json('sent'))->toBeTrue()
        ->and($answer->json('ring_state'))->toBe('ready');

    RingFixture::runJobs([SendRingPush::class]);

    $sent = RingFixture::sent();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['to'])->toBe($this->token->expo_token)
        ->and(array_keys($sent[0]))->toBe(['to', 'priority', 'ttl', 'data'])
        ->and($sent[0]['ttl'])->toBe(60)
        ->and($sent[0]['data'])->toMatchArray(['type' => 'ring_test', 'v' => 1, 'drill' => 1, 'estate' => 'Phoenix Park', 'hold' => 15])
        ->and(RingTicket::verify($sent[0]['data']['rt']))->toMatchArray(['a' => 0, 'k' => 'test', 's' => 1, 'p' => $this->token->id, 'g' => $this->guard->id])
        ->and(RingFixture::to($this->other))->toBeEmpty();
});

it('refuses a phone with no push address, and one that has not reported call-style alerts', function () {
    [, $bare] = RingFixture::guard('GS-RT-3', push: null);
    expect(RingFixture::call($this, $bare, 'ring.test', status: 409)->json('error.code'))->toBe('no_push_address');

    [, $old] = RingFixture::guard('GS-RT-4', push: []);
    expect(RingFixture::call($this, $old, 'ring.test', status: 409)->json('error.code'))->toBe('ring_not_supported');
});

it('allows one a minute, and a second test a minute later sends and records like the first (G24)', function () {
    RingFixture::call($this, $this->bearer, 'ring.test');
    RingFixture::call($this, $this->bearer, 'ring.test', status: 429);
    RingFixture::runJobs([SendRingPush::class]);

    $first = RingFixture::ticketFor($this->token);
    RingFixture::call($this, null, 'ring.receipt', ['rt' => $first, 'event' => 'shown']);

    Carbon::setTestNow(now()->addSeconds(61));

    RingFixture::call($this, $this->bearer, 'ring.test');
    RingFixture::runJobs([SendRingPush::class]);

    $second = RingFixture::ticketFor($this->token);
    RingFixture::call($this, null, 'ring.receipt', ['rt' => $second, 'event' => 'shown']);

    $events = DuressAlertRingEvent::query()->where('duress_alert_id', 0)->where('push_token_id', $this->token->id);

    expect(RingFixture::sent())->toHaveCount(2)
        ->and(RingTicket::verify($second)['s'])->toBe(2)
        ->and((clone $events)->where('event', 'sent_test')->pluck('test_seq')->sort()->values()->all())->toBe([1, 2])
        ->and((clone $events)->where('event', 'shown')->pluck('test_seq')->sort()->values()->all())->toBe([1, 2])
        ->and($this->token->fresh()->ring_proven_at)->not->toBeNull();
});
