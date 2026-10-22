<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Jobs\Push\SendExpoPush;
use App\Models\DevicePushToken;
use App\Models\ResidentAccount;
use App\Notifications\ResidentNewsEmailed;
use App\Services\Push\PushMessage;
use App\Services\Push\PushNotifier;
use App\Services\ResidentApp\ResidentSessions;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M50 §4.2 #4, #5 — Email and Quiet hours on board 20 do what they say
|--------------------------------------------------------------------------
|
| Both were drawn and had nothing behind them: a tap said "not available yet". Now quiet hours keep a phone from
| buzzing between the times it was given, on the estate's clock — and "Urgent alerts still come through" is literally
| true, because the urgent types ignore them. Email sends a resident who turned it on the news a push carries, at the
| address their account signs in with, for the categories they left on.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function quietAccount(string $who): ResidentAccount
{
    return ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'channel' => 'email',
        'destination' => $who.'.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Quiet '.$who,
        'status' => ResidentAccount::ACTIVE,
        'verified_at' => now(),
    ]);
}

/** A live push address for this account, as `push.register` writes it. */
function quietPhone(ResidentAccount $account, ?string $from = null, ?string $to = null): DevicePushToken
{
    $issued = $account->createToken(ResidentSessions::TOKEN_PREFIX.'install-'.bin2hex(random_bytes(3)), AppMatrix::abilitiesFor(AppMatrix::RESIDENT));

    return DevicePushToken::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'principal_type' => DevicePushToken::RESIDENT,
        'principal_id' => $account->id,
        'app' => AppMatrix::RESIDENT,
        'access_token_id' => $issued->accessToken->getKey(),
        'expo_token' => 'ExponentPushToken['.bin2hex(random_bytes(8)).']',
        'platform' => 'android',
        'muted' => [],
        'quiet_from' => $from,
        'quiet_to' => $to,
        'last_registered_at' => now(),
    ]);
}

/** The estate's clock set to HH:MM today. */
function estateClock(string $hhmm): void
{
    Carbon::setTestNow(Carbon::parse('2026-10-08 '.$hhmm, SiteTime::zone(FacilitiesFixture::ESTATE)));
}

describe('quiet hours on a phone', function () {
    it('are quiet from the start, up to but not at the end, and wrap over midnight', function () {
        $night = new DevicePushToken(['quiet_from' => '22:00', 'quiet_to' => '07:00']);
        $zone = SiteTime::zone(FacilitiesFixture::ESTATE);
        $at = static fn (string $hhmm): Carbon => Carbon::parse('2026-10-08 '.$hhmm, $zone);

        expect($night->quietAt($at('22:00')))->toBeTrue()
            ->and($night->quietAt($at('03:15')))->toBeTrue()
            ->and($night->quietAt($at('06:59')))->toBeTrue()
            ->and($night->quietAt($at('07:00')))->toBeFalse()
            ->and($night->quietAt($at('12:00')))->toBeFalse()
            ->and($night->quietAt($at('21:59')))->toBeFalse();

        $day = new DevicePushToken(['quiet_from' => '09:00', 'quiet_to' => '17:00']);
        expect($day->quietAt($at('09:00')))->toBeTrue()->and($day->quietAt($at('17:00')))->toBeFalse()->and($day->quietAt($at('08:59')))->toBeFalse();

        expect((new DevicePushToken)->quietAt($at('23:00')))->toBeFalse();
    });

    it('are read leniently: anything doubtful is no quiet hours, never a refusal', function () {
        expect(DevicePushToken::quietFrom(['on' => true, 'from' => '22:00', 'to' => '07:00']))->toBe(['quiet_from' => '22:00', 'quiet_to' => '07:00'])
            ->and(DevicePushToken::quietFrom(['on' => false, 'from' => '22:00', 'to' => '07:00']))->toBe(['quiet_from' => null, 'quiet_to' => null])
            ->and(DevicePushToken::quietFrom(['from' => '10 PM', 'to' => '07:00']))->toBe(['quiet_from' => null, 'quiet_to' => null])
            ->and(DevicePushToken::quietFrom(['from' => '22:00', 'to' => '22:00']))->toBe(['quiet_from' => null, 'quiet_to' => null])
            ->and(DevicePushToken::quietFrom('22:00-07:00'))->toBe(['quiet_from' => null, 'quiet_to' => null])
            ->and(DevicePushToken::quietFrom(null))->toBe(['quiet_from' => null, 'quiet_to' => null]);
    });

    it('come with the registration, and a registration without them clears them', function () {
        $account = quietAccount('register');
        $token = $account->createToken(ResidentSessions::TOKEN_PREFIX.'install-reg', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
        $expo = 'ExponentPushToken['.bin2hex(random_bytes(8)).']';
        $headers = ['Idempotency-Key' => 'q-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String()];

        $this->withToken($token)->postJson('/api/v1/push/token', ['token' => $expo, 'platform' => 'android', 'quiet' => ['on' => true, 'from' => '22:00', 'to' => '07:00']], $headers)
            ->assertOk()->assertJsonPath('quiet', ['from' => '22:00', 'to' => '07:00']);
        expect(DevicePushToken::query()->where('expo_token', $expo)->value('quiet_from'))->toBe('22:00');

        FacilitiesFixture::boot();
        app('auth')->forgetGuards();
        $headers['Idempotency-Key'] = 'q-'.bin2hex(random_bytes(6));
        $this->withToken($token)->postJson('/api/v1/push/token', ['token' => $expo, 'platform' => 'android'], $headers)
            ->assertOk()->assertJsonPath('quiet', null);
        expect(DevicePushToken::query()->where('expo_token', $expo)->value('quiet_from'))->toBeNull();
    });

    it('hold a notice back inside them, let it through outside them — and never hold an urgent push', function () {
        config(['push.enabled' => true, 'push.coalesce_seconds' => 0]);
        Bus::fake([SendExpoPush::class]);
        Notification::fake();

        $account = quietAccount('night');
        quietPhone($account, '22:00', '07:00');
        $push = app(PushNotifier::class);

        estateClock('23:30');
        expect($push->toAccount(FacilitiesFixture::ESTATE, (int) $account->id, new PushMessage('notice', 'Water off tomorrow', 'From 9 AM to noon.', 41)))->toBe(0);
        Bus::assertNotDispatched(SendExpoPush::class);

        // "Urgent alerts still come through": a panic update and somebody at the gate ignore quiet hours.
        expect($push->toAccount(FacilitiesFixture::ESTATE, (int) $account->id, new PushMessage('panic_update', 'Help is on the way', 'An officer acknowledged your alert.', 7)))->toBe(1)
            ->and($push->toAccount(FacilitiesFixture::ESTATE, (int) $account->id, new PushMessage('approval_request', 'Someone is at the gate', 'Tap to answer.', 8)))->toBe(1);

        estateClock('07:00');
        expect($push->toAccount(FacilitiesFixture::ESTATE, (int) $account->id, new PushMessage('notice', 'Water off tomorrow', 'From 9 AM to noon.', 41)))->toBe(1);
        Bus::assertDispatchedTimes(SendExpoPush::class, 3);
    });
});

describe('email notifications on an account', function () {
    it('are off until the resident turns them on, and the switch is the account\'s', function () {
        $account = quietAccount('email');
        $token = $account->createToken(ResidentSessions::TOKEN_PREFIX.'install-mail', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me/notifications/email')
            ->assertOk()->assertJson(['on' => false, 'address' => $account->destination, 'muted' => []]);

        FacilitiesFixture::boot();
        app('auth')->forgetGuards();
        $this->withToken($token)->putJson('/api/v1/me/notifications/email', ['on' => true, 'muted' => ['notices', 'panic', 'nonsense']])
            ->assertOk()->assertJson(['on' => true, 'muted' => ['notices']]);

        expect($account->fresh()->notify_email)->toBeTrue()
            ->and($account->fresh()->notify_email_muted)->toBe(['notices']);
    });

    it('send what a push says to the sign-in address — phone or none — for the categories left on', function () {
        config(['push.enabled' => true, 'push.coalesce_seconds' => 300]);
        Bus::fake([SendExpoPush::class]);
        Notification::fake();

        $on = quietAccount('on');
        $on->forceFill(['notify_email' => true, 'notify_email_muted' => ['notices']])->save();
        $off = quietAccount('off');
        quietPhone($off);
        $push = app(PushNotifier::class);

        // A maintenance update: emailed to the account with Email on — it has no phone registered at all.
        $push->toAccounts(FacilitiesFixture::ESTATE, [(int) $on->id, (int) $off->id], new PushMessage('ticket_update', 'Your request moved', 'The plumber is on the way.', 12));
        Notification::assertSentOnDemand(ResidentNewsEmailed::class, static fn (ResidentNewsEmailed $mail, array $channels, object $to): bool => $to->routes['mail'] === $on->destination && $mail->title === 'Your request moved');
        Notification::assertSentOnDemandTimes(ResidentNewsEmailed::class, 1);

        // Once per record inside the window, like the push.
        $push->toAccount(FacilitiesFixture::ESTATE, (int) $on->id, new PushMessage('ticket_update', 'Your request moved', 'Done.', 12));
        Notification::assertSentOnDemandTimes(ResidentNewsEmailed::class, 1);

        // A category it switched off, and somebody at the gate, are never emailed.
        $push->toAccount(FacilitiesFixture::ESTATE, (int) $on->id, new PushMessage('notice', 'Water off', 'Tomorrow.', 3));
        $push->toAccount(FacilitiesFixture::ESTATE, (int) $on->id, new PushMessage('approval_request', 'Someone is at the gate', 'Tap to answer.', 9));
        Notification::assertSentOnDemandTimes(ResidentNewsEmailed::class, 1);
    });

    it('send nothing to an account that is suspended, or does not sign in by email', function () {
        config(['push.enabled' => true, 'push.coalesce_seconds' => 0]);
        Bus::fake([SendExpoPush::class]);
        Notification::fake();

        $suspended = quietAccount('suspended');
        $suspended->forceFill(['notify_email' => true, 'status' => ResidentAccount::SUSPENDED])->save();

        app(PushNotifier::class)->toAccount(FacilitiesFixture::ESTATE, (int) $suspended->id, new PushMessage('ticket_update', 'Your request moved', 'Done.', 5));
        Notification::assertNothingSent();
    });
});
