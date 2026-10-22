<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\AuditEntry;
use App\Models\PersonalAccessToken;
use App\Models\ResidentAccount;
use App\Services\ResidentApp\ResidentSessions;
use App\Support\SessionWindow;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Every phone signed in to one Resident App account — Security, "Signed-in phones"
|--------------------------------------------------------------------------
|
| The client, 26 Sep: "i am logged in with 2 different devices on the same account … none of them showing the
| other device". Each phone could only ever say "this phone", because nothing on the server named a sign-in. Now
| the sign-in keeps what the phone calls itself, `me.sessions` lists them all, and `me.sessions.end` signs one of
| the OTHER phones out — its token and its push address with it.
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

function sessAccount(string $who, bool $reviewer = false): ResidentAccount
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'channel' => 'email',
        'destination' => $who.'.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Two Phones '.$who,
        'status' => ResidentAccount::ACTIVE,
        'verified_at' => now(),
    ]);

    if ($reviewer) {
        $account->forceFill(['reviewer' => true])->save();
    }

    return $account;
}

/** One phone's sign-in, named as the app names it at `auth.otp.verify`. @return array{0: string, 1: int} */
function sessPhone(ResidentAccount $account, string $install, ?string $label, string $platform = 'android'): array
{
    $issued = $account->createToken('resident-app:'.$install, AppMatrix::abilitiesFor(AppMatrix::RESIDENT));
    $issued->accessToken->forceFill(['device_label' => $label, 'device_platform' => $platform])->save();

    return [$issued->plainTextToken, (int) $issued->accessToken->getKey()];
}

/** @param  array<string, scalar>  $params */
function sessCall(TestCase $test, string $token, string $name, array $params = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'sess-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $response = $test->withToken($token)->json($entry['method'], $uri, [], $headers);
    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

it('lists every phone signed in to the account — this phone first, each named as it named itself', function () {
    $account = sessAccount('hassam');
    [$first, $firstId] = sessPhone($account, 'install-a', 'SM-G991U1');
    [$second, $secondId] = sessPhone($account, 'install-b', 'SM-S938B');

    $fromFirst = sessCall($this, $first, 'me.sessions')->json('items');
    expect($fromFirst)->toHaveCount(2)
        ->and($fromFirst[0])->toMatchArray(['id' => $firstId, 'device' => 'SM-G991U1', 'platform' => 'android', 'current' => true, 'timed_out' => false])
        ->and($fromFirst[1])->toMatchArray(['id' => $secondId, 'device' => 'SM-S938B', 'current' => false, 'timed_out' => false]);

    // And the other phone sees the first one the same way round: itself first.
    $fromSecond = sessCall($this, $second, 'me.sessions')->json('items');
    expect(array_column($fromSecond, 'id'))->toBe([$secondId, $firstId])
        ->and($fromSecond[0]['current'])->toBeTrue();
});

it('never lists another account\'s phones', function () {
    $mine = sessAccount('mine');
    $theirs = sessAccount('theirs');
    [$token] = sessPhone($mine, 'install-m', 'Pixel 8');
    sessPhone($theirs, 'install-t', 'iPhone 15', 'ios');

    expect(array_column(sessCall($this, $token, 'me.sessions')->json('items'), 'device'))->toBe(['Pixel 8']);
});

it('signs another phone out: its next request is refused, its push address goes, and the record says so', function () {
    $account = sessAccount('lost');
    [$kept] = sessPhone($account, 'install-kept', 'SM-G991U1');
    [$lost, $lostId] = sessPhone($account, 'install-lost', 'SM-S938B');

    if (DB::connection('mysql')->getSchemaBuilder()->hasTable('device_push_tokens')) {
        DB::connection('mysql')->table('device_push_tokens')->insert([
            'tenant_id' => FacilitiesFixture::ESTATE, 'principal_type' => 'resident', 'principal_id' => $account->id,
            'access_token_id' => $lostId, 'app' => 'resident', 'expo_token' => 'ExponentPushToken[sess-'.bin2hex(random_bytes(4)).']',
            'platform' => 'android', 'last_registered_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(sessCall($this, $kept, 'me.sessions.end', ['session' => $lostId])->json())->toMatchArray(['id' => $lostId, 'signed_out' => true]);

    expect(PersonalAccessToken::query()->whereKey($lostId)->exists())->toBeFalse();
    app('auth')->forgetGuards();
    $this->withToken($lost)->getJson('/api/v1/me')->assertUnauthorized();
    FacilitiesFixture::boot();

    if (DB::connection('mysql')->getSchemaBuilder()->hasTable('device_push_tokens')) {
        expect(DB::connection('mysql')->table('device_push_tokens')->where('access_token_id', $lostId)->exists())->toBeFalse();
    }

    $entry = AuditEntry::query()->where('action', 'resident_app.phone_signed_out')->where('entity_id', (string) $account->id)->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->before['device'] ?? null)->toBe('SM-S938B');

    // The phone that did it is still signed in, and now the only one listed.
    expect(array_column(sessCall($this, $kept, 'me.sessions')->json('items'), 'device'))->toBe(['SM-G991U1']);
});

it('will not sign out the phone asking, nor reach a phone that is not this account\'s', function () {
    $account = sessAccount('asker');
    $other = sessAccount('other');
    [$token, $tokenId] = sessPhone($account, 'install-asker', 'SM-G991U1');
    [, $theirsId] = sessPhone($other, 'install-other', 'iPhone 15', 'ios');

    expect(sessCall($this, $token, 'me.sessions.end', ['session' => $tokenId], 409)->json('error.code'))->toBe('this_phone');
    expect(sessCall($this, $token, 'me.sessions.end', ['session' => $theirsId], 404)->json('error.code'))->toBe('not_found');
    expect(sessCall($this, $token, 'me.sessions.end', ['session' => 999_999_999], 404)->json('error.code'))->toBe('not_found');

    // Neither was touched.
    expect(PersonalAccessToken::query()->whereKey([$tokenId, $theirsId])->count())->toBe(2);
});

it('lists a phone left idle past the window as timed out — it still gets notifications, so it can still be signed out', function () {
    $account = sessAccount('idle');
    [$token] = sessPhone($account, 'install-now', 'SM-G991U1');
    [, $idleId] = sessPhone($account, 'install-drawer', null);
    PersonalAccessToken::query()->whereKey($idleId)->update(['last_used_at' => now()->subMinutes(SessionWindow::minutes() + 90)]);

    $items = sessCall($this, $token, 'me.sessions')->json('items');
    expect($items[1])->toMatchArray(['id' => $idleId, 'device' => null, 'timed_out' => true, 'current' => false]);

    sessCall($this, $token, 'me.sessions.end', ['session' => $idleId]);
    expect(PersonalAccessToken::query()->whereKey($idleId)->exists())->toBeFalse();
});

it('ends a sign-in nobody has used for thirty days, as Sign out does, and leaves one used this month (M54 §2)', function () {
    $account = sessAccount('drawer');
    [$token, $usedId] = sessPhone($account, 'install-used', 'SM-S938B');
    [, $oldId] = sessPhone($account, 'install-old', 'SM-S938B');
    [, $neverId] = sessPhone($account, 'install-never', 'SM-G991U1');
    PersonalAccessToken::query()->whereKey($usedId)->update(['last_used_at' => now()->subDays(2)]);
    PersonalAccessToken::query()->whereKey($oldId)->update(['last_used_at' => now()->subDays(31)]);
    // Signed in 40 days ago and never used since.
    PersonalAccessToken::query()->whereKey($neverId)->update(['last_used_at' => null, 'created_at' => now()->subDays(40)]);

    $this->artisan('resident-sessions:end-idle')->assertSuccessful();
    FacilitiesFixture::boot();

    expect(PersonalAccessToken::query()->whereKey([$usedId, $oldId, $neverId])->pluck('id')->all())->toBe([$usedId]);

    $ended = AuditEntry::query()->where('action', 'resident_app.phone_signed_out')->where('entity_id', (string) $account->id)->get();
    expect($ended)->toHaveCount(2)
        ->and($ended->pluck('after.by')->unique()->all())->toBe(['unused for 30 days']);

    // The demonstration account's sign-ins are other people's: never ended here.
    $demo = sessAccount('demo', reviewer: true);
    [, $demoId] = sessPhone($demo, 'install-demo', 'Pixel 8');
    PersonalAccessToken::query()->whereKey($demoId)->update(['last_used_at' => now()->subDays(60)]);
    $this->artisan('resident-sessions:end-idle')->assertSuccessful();
    FacilitiesFixture::boot();
    expect(PersonalAccessToken::query()->whereKey($demoId)->exists())->toBeTrue();
});

it('shows the demonstration account only the phone asking — every other session there is somebody else\'s', function () {
    $demo = sessAccount('demo', reviewer: true);
    [$reviewer, $reviewerId] = sessPhone($demo, 'install-reviewer', 'iPad');
    [, $strangerId] = sessPhone($demo, 'install-stranger', 'SM-A515F');

    expect(array_column(sessCall($this, $reviewer, 'me.sessions')->json('items'), 'id'))->toBe([$reviewerId]);
    sessCall($this, $reviewer, 'me.sessions.end', ['session' => $strangerId], 404);
    expect(PersonalAccessToken::query()->whereKey($strangerId)->exists())->toBeTrue();
});

it('keeps what the phone calls itself as one printable line of at most eighty characters', function () {
    expect(ResidentSessions::label("  SM-S938B\u{0000}\n "))->toBe('SM-S938B')
        ->and(ResidentSessions::label("iPhone\t15  Pro"))->toBe('iPhone 15 Pro')
        ->and(ResidentSessions::label("\u{202E}evil"))->toBe('evil') // no direction override to turn a name round
        ->and(ResidentSessions::label('   '))->toBeNull()
        ->and(ResidentSessions::label(null))->toBeNull()
        ->and(mb_strlen((string) ResidentSessions::label(str_repeat('x', 200))))->toBe(80);
});
