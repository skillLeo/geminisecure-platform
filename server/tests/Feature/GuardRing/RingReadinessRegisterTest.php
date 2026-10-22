<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\DevicePushToken;
use App\Models\ResidentAccount;
use App\Services\Ring\RingReadiness;
use Tests\Support\FacilitiesFixture;
use Tests\Support\RingFixture;

/*
|--------------------------------------------------------------------------
| A PHONE SAYS WHETHER IT CAN RING LIKE A CALL — the guard ring §6.6 push.register
|--------------------------------------------------------------------------
|
| The Guard App reports its readiness with every registration, and the SERVER decides
| what it adds up to. The one thing that must never happen (G1): a report the server
| does not understand costing the phone its push address — and with it every panic.
|
*/

beforeEach(function () {
    RingFixture::boot($this);
    RingFixture::expo();

    [$this->guard, $this->bearer] = RingFixture::guard('GS-RP-1', push: null);

    $this->register = fn (array $body, ?string $bearer = null) => RingFixture::call($this, $bearer ?? $this->bearer, 'push.register', [
        'token' => RingFixture::expoToken('readiness'), 'platform' => 'android', ...$body,
    ]);

    $this->row = fn (): DevicePushToken => DevicePushToken::query()->where('expo_token', RingFixture::expoToken('readiness'))->firstOrFail();

    $this->report = ['v' => 1, 'configured' => true, 'notifications' => 'on', 'ring_channel' => 'on', 'full_screen' => 'on', 'dnd_bypass' => 'on',
        'battery' => 'exempt', 'background' => 'allowed', 'alarm_volume' => 'ok', 'alarm_tone' => 'set', 'screen_lock' => 'on', 'sdk' => 35, 'maker' => 'samsung'];
});

afterEach(fn () => RingFixture::tearDown());

it('reads a full report as ready, keeps what it knows and drops what it does not', function () {
    $answer = ($this->register)(['ring' => [...$this->report, 'wallpaper' => 'beach']]);

    expect($answer->json('ring_state'))->toBe('ready')
        ->and(($this->row)()->ring_version)->toBe(1)
        ->and(($this->row)()->ring_checked_at)->not->toBeNull()
        ->and(($this->row)()->ring_readiness)->not->toHaveKey('wallpaper')
        ->and(($this->row)()->ring_readiness)->toMatchArray(['maker' => 'samsung', 'sdk' => 35]);
});

it('decides the state itself: not ready, partly ready, ready', function (array $change, string $state) {
    expect(($this->register)(['ring' => [...$this->report, ...$change]])->json('ring_state'))->toBe($state);
})->with([
    'notifications off' => [['notifications' => 'off'], 'not_ready'],
    'full screen off' => [['full_screen' => 'off'], 'not_ready'],
    'alarm volume off' => [['alarm_volume' => 'off'], 'not_ready'],
    'no alarm tone' => [['alarm_tone' => 'none'], 'not_ready'],
    'not configured' => [['configured' => false], 'not_ready'],
    'full screen not needed' => [['full_screen' => 'not_needed'], 'ready'],
    'battery optimised' => [['battery' => 'optimised'], 'partial'],
    'DND bypass unknown' => [['dnd_bypass' => 'unknown'], 'partial'],
    'background restricted' => [['background' => 'restricted'], 'partial'],
    'alarm volume low' => [['alarm_volume' => 'low'], 'partial'],
    'no screen lock' => [['screen_lock' => 'off'], 'partial'],
]);

it('never refuses a registration over a report it cannot read, and stores the push address (G1)', function (mixed $ring, ?string $state) {
    $answer = ($this->register)(['ring' => $ring]);

    expect($answer->json('registered'))->toBeTrue()
        ->and($answer->json('ring_state'))->toBe($state)
        ->and(($this->row)()->revoked_at)->toBeNull()
        ->and(($this->row)()->principal_id)->toBe($this->guard->id);
})->with([
    'a string' => ['on', null],
    'a list' => [['on', 'off'], null],
    'a value it does not know' => [['v' => 1, 'configured' => true, 'notifications' => 'on', 'ring_channel' => 'on', 'full_screen' => 'maybe', 'alarm_volume' => 'ok', 'alarm_tone' => 'set', 'maker' => 'Sam sung!', 'sdk' => 9000], 'not_ready'],
]);

it('clears the ring contract when a registration carries no report, so that phone gets the ordinary alert', function () {
    ($this->register)(['ring' => $this->report]);
    expect(($this->row)()->ring_version)->toBe(1);

    $answer = ($this->register)([]);

    expect($answer->json('ring_state'))->toBeNull()
        ->and(($this->row)()->ring_version)->toBeNull()
        ->and(($this->row)()->ring_state)->toBeNull();
});

it('ignores a resident\'s report: only the Guard App rings', function () {
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'ring.register@example.com', 'full_name' => 'Resident Ring',
        'unit_id' => FacilitiesFixture::scratchUnit('Lot 817')->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $token = $account->createToken('resident-app:ring', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $answer = RingFixture::call($this, $token, 'push.register', ['token' => RingFixture::expoToken('residentring'), 'platform' => 'android', 'ring' => $this->report]);

    expect($answer->json('ring_state'))->toBeNull()
        ->and(DevicePushToken::query()->where('expo_token', RingFixture::expoToken('residentring'))->value('ring_version'))->toBeNull();
});

it('reads the receipt bitmask in the order the catalogue documents', function () {
    expect(RingReadiness::fromBits(0b111111111))->toMatchArray([
        'notifications' => 'on', 'ring_channel' => 'on', 'full_screen' => 'on', 'dnd_bypass' => 'on', 'battery' => 'exempt',
        'background' => 'allowed', 'alarm_volume' => 'ok', 'alarm_tone' => 'set', 'screen_lock' => 'on',
    ])
        ->and(RingReadiness::fromBits(0))->toMatchArray(['notifications' => 'off', 'alarm_volume' => 'off', 'screen_lock' => 'off'])
        ->and(RingReadiness::state(RingReadiness::fromBits(0b011111111)))->toBe('partial')
        ->and(RingReadiness::fromBits(-1))->toBeNull()
        ->and(RingReadiness::fromBits('x'))->toBeNull();
});
