<?php

declare(strict_types=1);

use App\Notifications\ResidentSignInCode;
use App\Services\ResidentApp\ResidentSignIn;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A Resident App code gets five guesses, however they arrive (B059)
|--------------------------------------------------------------------------
|
| `ResidentSignIn::verify()` read the code's row, checked `attempts < 5` against
| what it had read, compared the code, and only then incremented `attempts`. N
| guesses that all read the row before any increment landed all passed the check
| and were all compared: a code "good for five tries" gave as many tries as could
| be landed in the same instant, from as many addresses as an attacker had (the
| only brake was ten requests a minute per IP). And a new code, with a fresh
| counter, could be minted every sixty seconds for ever.
|
| Now a guess first CLAIMS one of the code's five tries — a single conditional
| UPDATE that only succeeds while fewer than five are spent — and only a claimed
| try is compared. And each estate + address gets five codes and ten tries an
| hour, whatever addresses the requests come from.
|
| THE RACE IS REPRODUCED, NOT ASSUMED. A test cannot send two requests at once, so
| the interleaving is made deterministic: the moment `verify()` has read the
| code's row, the rows it read are changed underneath it — exactly what the other
| requests' increments do in production. The old code then acted on what it had
| read; the new code does not act on a read at all.
|
| WRITTEN AGAINST THE OLD BEHAVIOUR FIRST: the right code was accepted after five
| guesses had already been spent on it, and the eleventh try in an hour was
| compared like the first.
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

/** Ask for a code from this address, and return the code the resident was sent. */
function racedCodeFor(string $destination, string $ip = '198.51.100.10'): string
{
    app('auth')->forgetGuards();

    test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/otp/request', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $destination])
        ->assertStatus(202);

    $code = null;
    Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n) use (&$code): bool {
        $code = $n->code;

        return true;
    });

    return (string) $code;
}

function racedVerify(string $destination, string $code, string $ip = '198.51.100.20'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/otp/verify', [
        'estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $destination,
        'code' => $code, 'device_uid' => 'race-test', 'platform' => 'android',
    ]);
}

/*
 * The hourly budgets, written out rather than read from the service, so this file states
 * what it expects of a service that did not have them yet — which is how it was run first.
 * `ResidentSignIn::CODES_PER_HOUR` and `::VERIFIES_PER_HOUR` must agree with these.
 */
const RACED_CODES_PER_HOUR = 5;

const RACED_TRIES_PER_HOUR = 10;

function wrongCodeBeside(string $real): string
{
    return $real === '999999' ? '999998' : '999999';
}

/**
 * The instant after `verify()` reads the code's row, the OTHER requests' increments land:
 * `attempts` becomes `$spent`, as it would with that many guesses in flight at once.
 */
function whileOtherGuessesLand(int $spent): void
{
    $landed = false;

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$landed, $spent): void {
        if ($landed || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, 'resident_otps')) {
            return;
        }

        $landed = true;

        DB::connection('mysql')->table('resident_otps')
            ->where('tenant_id', FacilitiesFixture::ESTATE)
            ->whereNull('consumed_at')
            ->update(['attempts' => $spent]);
    });
}

it('refuses even the right code once five guesses have been spent on it by requests in flight at the same moment', function () {
    $real = racedCodeFor('race.one@example.com');

    whileOtherGuessesLand(ResidentSignIn::MAX_ATTEMPTS);

    racedVerify('race.one@example.com', $real)
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'otp_locked');

    expect(DB::connection('mysql')->table('resident_otps')->where('tenant_id', FacilitiesFixture::ESTATE)->max('attempts'))
        ->toBe(ResidentSignIn::MAX_ATTEMPTS);
});

it('counts a wrong guess among the ones in flight, and says the code is locked rather than offering tries it does not have', function () {
    $real = racedCodeFor('race.two@example.com');

    // Four other guesses land while this one is between its read and its write.
    whileOtherGuessesLand(ResidentSignIn::MAX_ATTEMPTS - 1);

    racedVerify('race.two@example.com', wrongCodeBeside($real))
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'otp_locked');

    // The fifth claimed try was this one; none is left for anybody.
    racedVerify('race.two@example.com', $real, '198.51.100.21')
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'otp_locked');
});

it('sends one address at one estate five codes an hour, from however many addresses they are asked for', function () {
    foreach (range(1, RACED_CODES_PER_HOUR) as $n) {
        racedCodeFor('race.three@example.com', '203.0.113.'.$n);

        // Past the one-minute resend window, so each request mints and sends a new code.
        $this->travel(ResidentSignIn::RESEND_SECONDS + 1)->seconds();
    }

    Notification::assertSentOnDemandTimes(ResidentSignInCode::class, RACED_CODES_PER_HOUR);

    app('auth')->forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->postJson('/api/v1/auth/otp/request', ['estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'race.three@example.com'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    // Nothing was sent for the refused request: the answer is not "sent".
    Notification::assertSentOnDemandTimes(ResidentSignInCode::class, RACED_CODES_PER_HOUR);

    // Another address at the same estate is untouched.
    racedCodeFor('someone.else@example.com', '203.0.113.100');
});

it('gives one address at one estate ten tries an hour across every code, even from new addresses', function () {
    $tries = 0;

    foreach (range(1, 2) as $round) {
        $real = racedCodeFor('race.four@example.com', '192.0.2.'.$round);

        foreach (range(1, ResidentSignIn::MAX_ATTEMPTS) as $n) {
            racedVerify('race.four@example.com', wrongCodeBeside($real), '192.0.2.'.(10 * $round + $n))->assertStatus($n === ResidentSignIn::MAX_ATTEMPTS ? 423 : 422);
            $tries++;
        }

        $this->travel(ResidentSignIn::RESEND_SECONDS + 1)->seconds();
    }

    expect($tries)->toBe(RACED_TRIES_PER_HOUR);

    // A third code, the RIGHT code, from an address never seen before: the hour's tries are spent.
    $real = racedCodeFor('race.four@example.com', '192.0.2.200');

    racedVerify('race.four@example.com', $real, '192.0.2.201')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    // And an hour later the resident is let in with a new code.
    $this->travel(61)->minutes();

    $fresh = racedCodeFor('race.four@example.com', '192.0.2.202');

    racedVerify('race.four@example.com', $fresh, '192.0.2.203')->assertOk()->assertJsonStructure(['token']);
});

it('still signs a resident in with the right code on the first try, and spends it', function () {
    $real = racedCodeFor('race.five@example.com');

    racedVerify('race.five@example.com', $real)->assertOk()->assertJsonStructure(['token']);

    // Once.
    racedVerify('race.five@example.com', $real, '198.51.100.22')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'otp_expired');
});
