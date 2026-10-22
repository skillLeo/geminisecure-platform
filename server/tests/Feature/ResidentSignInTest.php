<?php

declare(strict_types=1);

use App\Models\ResidentAccount;
use App\Notifications\ResidentSignInCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Resident App sign-in by one-time code — 13 D3
|--------------------------------------------------------------------------
|
| The code is stored as an HMAC, good once, for ten minutes and five tries; a
| second request inside a minute sends nothing new; and the answer to a request
| is the same whether an account exists or not.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function requestSignInCode(string $destination): array
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/otp/request', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $destination])
        ->assertStatus(202)
        ->json();
}

it('answers alike for an address with an account and one without, and keeps no code in the clear', function () {
    ResidentAccount::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'known@example.com', 'status' => ResidentAccount::ACTIVE]);

    $known = requestSignInCode('known@example.com');
    $unknown = requestSignInCode('stranger@example.com');

    expect(array_keys($known))->toBe(array_keys($unknown))
        ->and($known['sent'])->toBe($unknown['sent']);

    $codes = [];
    Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n) use (&$codes): bool {
        $codes[] = $n->code;

        return true;
    });

    $stored = DB::connection('mysql')->table('resident_otps')->where('tenant_id', FacilitiesFixture::ESTATE)->pluck('code_hash')->implode(' ');

    foreach ($codes as $code) {
        expect($stored)->not->toContain($code);
    }

    // No account is created by asking for a code.
    expect(ResidentAccount::query()->where('destination', 'stranger@example.com')->exists())->toBeFalse();
});

it('sends nothing new inside a minute, and locks after five wrong codes', function () {
    requestSignInCode('twice@example.com');
    requestSignInCode('twice@example.com');

    Notification::assertSentOnDemandTimes(ResidentSignInCode::class, 1);

    $attempt = fn (string $code) => test()->postJson('/api/v1/auth/otp/verify', [
        'estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'twice@example.com',
        'code' => $code, 'device_uid' => 'lock-test', 'platform' => 'android',
    ]);

    $real = null;
    Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n) use (&$real): bool {
        $real = $n->code;

        return true;
    });

    $wrong = $real === '999999' ? '999998' : '999999';

    foreach (range(1, 4) as $try) {
        $attempt($wrong)->assertStatus(422)->assertJsonPath('error.code', 'otp_invalid');
    }

    $attempt($wrong)->assertStatus(423)->assertJsonPath('error.code', 'otp_locked');

    // Locked means locked, even for the right code.
    $attempt($real)->assertStatus(423)->assertJsonPath('error.code', 'otp_locked');
});

it('answers 503 mail_unavailable when the mail server refuses the code, withdraws it, and never answers "sent" for it', function () {
    // The real mail channel against a relay that answers nothing (as ResidentInvitesAreSentTest does).
    Notification::swap(new Illuminate\Notifications\ChannelManager(app()));
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);

    $ask = fn () => test()->postJson('/api/v1/auth/otp/request', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'relay.down@example.com']);

    app('auth')->forgetGuards();
    $ask()->assertStatus(503)
        ->assertJsonPath('error.code', 'mail_unavailable')
        ->assertJsonPath('error.message', 'The code could not be emailed just now — nothing was sent. Try again in a minute.');

    $live = fn (): int => DB::connection('mysql')->table('resident_otps')
        ->where('tenant_id', FacilitiesFixture::ESTATE)->where('channel', 'email')->whereNull('consumed_at')->count();

    // Withdrawn: the resend window has nothing to re-offer.
    expect($live())->toBe(0);

    // Inside the minute, it TRIES AGAIN rather than answering "sent" from the window — and says so again.
    app('auth')->forgetGuards();
    $ask()->assertStatus(503)->assertJsonPath('error.code', 'mail_unavailable');
    expect($live())->toBe(0);

    // The relay back: the next request mints a code and sends it.
    Notification::fake();
    app('auth')->forgetGuards();
    $ask()->assertStatus(202)->assertJsonPath('sent', true);

    Notification::assertSentOnDemandTimes(ResidentSignInCode::class, 1);
    expect($live())->toBe(1);
});
