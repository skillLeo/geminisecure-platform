<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\PersonalAccessToken;
use App\Models\ResidentAccount;
use App\Models\ResidentDeviceKey;
use App\Services\ResidentApp\ResidentDeviceKeys;
use App\Services\ResidentApp\ResidentSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M49 — a resident's phone is known again, and signs back in with its fingerprint
|--------------------------------------------------------------------------
|
| The user, 8 October: "even the option in the setting for biometric is on, it's still not asking me for the
| fingerprint and it's asking me to add the OTP" — and "seven active sessions across two phones, four of them the
| same phone, one ten days old". One cause: nothing let the server recognise a phone it had already seen. Now
| "Face ID / fingerprint" gives the install a key that signs it back in with no code, the handset's own id ends its
| earlier sign-ins, and a sign-in past its idle window is no longer listed as an active session.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function fpAccount(string $who): ResidentAccount
{
    return ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'channel' => 'email',
        'destination' => $who.'.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Fingerprint '.$who,
        'status' => ResidentAccount::ACTIVE,
        'verified_at' => now(),
    ]);
}

/** One phone's sign-in, as `auth.otp.verify` names it. @return array{0: string, 1: int} */
function fpPhone(ResidentAccount $account, string $install, ?string $hardware = null, ?string $label = 'SM-S938B'): array
{
    $issued = $account->createToken(ResidentSessions::TOKEN_PREFIX.$install, AppMatrix::abilitiesFor(AppMatrix::RESIDENT));
    $issued->accessToken->forceFill(['device_label' => $label, 'device_platform' => 'android', 'device_hardware' => ResidentDeviceKeys::hardwareHash($hardware)])->save();

    return [$issued->plainTextToken, (int) $issued->accessToken->getKey()];
}

/** @param  array<string, mixed>  $body */
function fpCall(TestCase $test, ?string $token, string $name, array $body = [], ?int $status = null, array $params = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'fp-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $request = $token === null ? $test : $test->withToken($token);
    $response = $request->json($entry['method'], $uri, $body, $headers);
    $response->assertStatus($status ?? $entry['status']);

    if ($status === null) {
        ApiContract::assertMatches($response, $name);
    }

    FacilitiesFixture::boot();

    return $response;
}

/** "Log in with biometrics", as the phone sends it. */
function fpSignIn(TestCase $test, string $key, string $install, ?string $hardware = null, ?int $status = null, string $estate = FacilitiesFixture::ESTATE): TestResponse
{
    // A guest request: no bearer header left over from a call made with a token.
    $test->flushHeaders();

    return fpCall($test, null, 'auth.device_key', ['estate' => $estate, 'key' => $key, 'device_uid' => $install, 'platform' => 'android', 'device_name' => 'SM-S938B', 'hardware_id' => $hardware], $status);
}

it('turned on, the install gets a key once — and after signing out, the key signs the phone back in with no code', function () {
    $account = fpAccount('hassam');
    [$token, $tokenId] = fpPhone($account, 'install-a', 'hw-s25');

    $key = fpCall($this, $token, 'me.device_key.store', ['platform' => 'android', 'device_name' => 'SM-S938B', 'hardware_id' => 'hw-s25'])->json('key');
    expect($key)->toBeString()->and(strlen($key))->toBeGreaterThan(30);

    // Only the hash is kept, and nothing about it is ever sent again.
    $row = ResidentDeviceKey::query()->where('resident_account_id', $account->id)->sole();
    expect($row->key_hash)->toBe(hash('sha256', $key))->and($row->device_uid)->toBe('install-a')->and($row->isLive())->toBeTrue();

    // "Sign out of this phone": the token is gone — the key is not.
    PersonalAccessToken::query()->whereKey($tokenId)->delete();

    $answer = fpSignIn($this, $key, 'install-a', 'hw-s25');
    // The same answer a proven code gives this account (it has no unit linked yet, so its next screen is the claim).
    expect($answer->json('account.id'))->toBe($account->id)
        ->and($answer->json('next'))->toBeIn(['home', 'claim_unit', 'await_approval'])
        ->and($answer->json('fingerprint_sign_in'))->toBeTrue()
        ->and($answer->json('token'))->toBeString();

    // A real session: the phone can read its own sessions with the new token.
    expect(fpCall($this, $answer->json('token'), 'me.sessions')->json('items'))->toHaveCount(1);
});

it('the key works only on its own install, at its own estate — never a guess, never another phone', function () {
    $account = fpAccount('owner');
    [$token] = fpPhone($account, 'install-own', 'hw-own');
    $key = fpCall($this, $token, 'me.device_key.store', ['platform' => 'android'])->json('key');

    expect(fpSignIn($this, $key, 'install-thief', null, 401)->json('error.code'))->toBe('device_key_invalid');
    expect(fpSignIn($this, 'not-'.$key, 'install-own', null, 401)->json('error.code'))->toBe('device_key_invalid');
    expect(fpSignIn($this, $key, 'install-own', null, 404, 'no-such-estate')->json('error.code'))->toBe('estate_not_found');
});

it('turned off, the key stops working at once', function () {
    $account = fpAccount('off');
    [$token] = fpPhone($account, 'install-off');
    $key = fpCall($this, $token, 'me.device_key.store', ['platform' => 'android'])->json('key');

    expect(fpCall($this, $token, 'me.device_key.destroy')->json('revoked'))->toBe(1);
    expect(fpSignIn($this, $key, 'install-off', null, 401)->json('error.message'))->toBe(ResidentDeviceKeys::REFUSED);
});

it('signed out from another phone, that phone\'s key stops working too', function () {
    $account = fpAccount('two');
    [$lost, $lostId] = fpPhone($account, 'install-lost');
    [$kept] = fpPhone($account, 'install-kept');
    $key = fpCall($this, $lost, 'me.device_key.store', ['platform' => 'android'])->json('key');

    fpCall($this, $kept, 'me.sessions.end', [], null, ['session' => $lostId]);

    expect(fpSignIn($this, $key, 'install-lost', null, 401)->json('error.code'))->toBe('device_key_invalid');
    expect(ResidentDeviceKey::query()->where('device_uid', 'install-lost')->value('revoked_reason'))->toBe('signed_out_elsewhere');
});

it('a suspended or revoked account gets no token from its key, and every key of the account stops working', function () {
    foreach ([ResidentAccount::SUSPENDED => 'account_suspended', ResidentAccount::REVOKED => 'account_revoked'] as $status => $code) {
        $account = fpAccount('closed-'.$status);
        [$token] = fpPhone($account, 'install-'.$status);
        $key = fpCall($this, $token, 'me.device_key.store', ['platform' => 'android'])->json('key');

        $account->forceFill(['status' => $status])->save();

        expect(fpSignIn($this, $key, 'install-'.$status, null, 403)->json('error.code'))->toBe($code);
        expect(ResidentDeviceKey::query()->where('resident_account_id', $account->id)->whereNull('revoked_at')->count())->toBe(0);
    }
});

it('the same handset signing in again ends its earlier sign-in instead of adding one beside it', function () {
    $account = fpAccount('reinstalled');
    // An earlier install of the app on this same phone, still signed in.
    [, $oldId] = fpPhone($account, 'install-before-reinstall', 'hw-same-phone');
    // Another phone of the account, which must be left alone.
    [, $otherId] = fpPhone($account, 'install-other-phone', 'hw-other-phone', 'SM-G991U1');
    [$token] = fpPhone($account, 'install-now', 'hw-same-phone');
    $key = fpCall($this, $token, 'me.device_key.store', ['platform' => 'android', 'hardware_id' => 'hw-same-phone'])->json('key');

    fpSignIn($this, $key, 'install-now', 'hw-same-phone');

    expect(PersonalAccessToken::query()->whereKey($oldId)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->whereKey($otherId)->exists())->toBeTrue()
        ->and(PersonalAccessToken::query()->where('tokenable_id', $account->id)->where('device_hardware', ResidentDeviceKeys::hardwareHash('hw-same-phone'))->count())->toBe(1);
});

it('a phone ten days idle stays listed, timed out, so it can still be signed out — until that handset signs in again', function () {
    $account = fpAccount('stale');
    [, $staleId] = fpPhone($account, 'install-ten-days-ago', 'hw-stale', 'SM-S938B');
    PersonalAccessToken::query()->whereKey($staleId)->update(['created_at' => now()->subDays(10), 'last_used_at' => now()->subDays(10)]);
    [$token] = fpPhone($account, 'install-today', 'hw-today');

    $items = fpCall($this, $token, 'me.sessions')->json('items');
    expect(collect($items)->firstWhere('id', $staleId)['timed_out'] ?? null)->toBeTrue();

    // The same handset signs in again (a new install of the app on it): its ten-day-old sign-in goes.
    [$again] = fpPhone($account, 'install-reinstalled', 'hw-stale');
    $key = fpCall($this, $again, 'me.device_key.store', ['platform' => 'android', 'hardware_id' => 'hw-stale'])->json('key');
    fpSignIn($this, $key, 'install-reinstalled', 'hw-stale');
    expect(PersonalAccessToken::query()->whereKey($staleId)->exists())->toBeFalse();
});

it('another person signing in on the phone ends the last person\'s fingerprint key for it', function () {
    $first = fpAccount('first-owner');
    [$token] = fpPhone($first, 'install-shared');
    $key = fpCall($this, $token, 'me.device_key.store', ['platform' => 'android'])->json('key');

    // The phone changes hands: somebody else signs in on the same install (their own key, the same door a code uses).
    $second = fpAccount('second-owner');
    [$theirs] = fpPhone($second, 'install-other-for-now');
    $theirKey = fpCall($this, $theirs, 'me.device_key.store', ['platform' => 'android'])->json('key');
    ResidentDeviceKey::query()->where('resident_account_id', $second->id)->update(['device_uid' => 'install-shared']);
    fpSignIn($this, $theirKey, 'install-shared');

    expect(fpSignIn($this, $key, 'install-shared', null, 401)->json('error.code'))->toBe('device_key_invalid')
        ->and(ResidentDeviceKey::query()->where('resident_account_id', $first->id)->value('revoked_reason'))->toBe('another_account');
});

it('a demonstration session cannot turn fingerprint sign-in on', function () {
    $account = fpAccount('demo');
    $token = $account->createToken('resident-app-demo:install-x', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    expect(fpCall($this, $token, 'me.device_key.store', ['platform' => 'android'], 403)->json('error.code'))->toBe('not_this_phone');
});

it('names the Android emulator as a person reads it, not by its build', function () {
    expect(ResidentSessions::label('sdk_gphone64_x86_64'))->toBe('Android emulator')
        ->and(ResidentSessions::label('SM-S938B'))->toBe('SM-S938B');
});
