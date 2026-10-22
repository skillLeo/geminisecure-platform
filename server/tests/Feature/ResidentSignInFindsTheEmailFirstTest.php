<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Notifications\ResidentSignInCode;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A sign-in looks the email up first (M80 §2.1, EST-B-03b)
|--------------------------------------------------------------------------
|
| The app took any email to the code screen, so a resident whose estate holds an old address waited for a code that
| was going to an address nobody had on file. Signing in (`intent: sign_in`) now asks the register first: an address no
| resident and no app account at this estate holds is told so at once, "We can't find that email. Check the spelling,
| or ask your estate office to add it.", and no code is made or sent. A found one gets its code, with where it went,
| masked. Saying who is on the register is the trade Hassam accepted (M80 §2.1), with its limit: five lookups an hour
| per address and thirty per device, every lookup logged by a hash, never the address.
|
| "Create account" (`intent: sign_up`) starts from an address nobody holds yet, and an app already on a phone sends no
| intent: both are answered exactly as before. Lot 9467 is this file's own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 9467');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Sign-in lookup household', 'access_restricted' => false]);
    DB::connection('tenant')->table('residents')->where('household_id', $this->household->id)->delete();

    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    if (tenancy()->initialized) {
        tenancy()->end();
    }
    FacilitiesFixture::boot();
    DB::connection('tenant')->table('residents')->where('household_id', $this->household->id)->delete();
});

function signInLookup(string $destination, ?string $intent = 'sign_in', array $extra = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/otp/request', array_filter([
        'estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $destination, 'intent' => $intent, ...$extra,
    ], static fn ($v): bool => $v !== null));
}

function signInLookupCodes(): int
{
    return DB::connection('mysql')->table('resident_otps')->where('tenant_id', FacilitiesFixture::ESTATE)->where('channel', 'email')->count();
}

it('tells a sign-in at once that an email is not on the register, makes and sends nothing, and logs it without the address', function () {
    Log::spy();

    // Not found says only that: no masked address, no echo, nothing else about the address (Hassam, 10 Oct).
    $answer = signInLookup('nobody.lookup@example.org', 'sign_in', ['device_uid' => 'lookup-device-0'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'email_not_found')
        ->assertJsonPath('error.message', "We can't find that email. Check the spelling, or ask your estate office to add it.")
        ->assertJsonMissingPath('sent')
        ->assertJsonMissingPath('destination_hint')
        ->assertJsonMissingPath('destination')
        ->assertJsonMissingPath('error.fields');

    expect(json_encode($answer->json()))->not->toContain('nobody')
        ->and(signInLookupCodes())->toBe(0);
    Notification::assertNothingSent();

    // Logged by the address's hash; the phone's id, a claim the phone makes, is never written beside it.
    Log::shouldHaveReceived('info')->withArgs(static fn (string $message, array $context): bool => $message === 'resident.sign_in.lookup'
        && $context['found'] === false && $context['tenant'] === FacilitiesFixture::ESTATE
        && ! array_key_exists('device', $context)
        && ! str_contains((string) json_encode($context), 'nobody.lookup')
        && ! str_contains((string) json_encode($context), 'lookup-device-0'))->once();
});

it('sends the code for an email a resident on the register holds, and says where it went, masked', function () {
    Resident::query()->create([
        'household_id' => $this->household->id, 'full_name' => 'Found Lookup', 'relationship' => 'owner', 'is_primary' => true,
        'status' => Resident::VERIFIED, 'email' => 'Found.Lookup@example.org',
    ]);

    signInLookup('found.lookup@example.org')
        ->assertStatus(202)
        ->assertJsonPath('sent', true)
        ->assertJsonPath('destination_hint', 'f•••@example.org');

    Notification::assertSentOnDemandTimes(ResidentSignInCode::class, 1);
});

it('sends the code for an email an app account at this estate already holds', function () {
    ResidentAccount::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'account.lookup@example.org', 'status' => ResidentAccount::ACTIVE]);

    signInLookup('account.lookup@example.org')->assertStatus(202)->assertJsonPath('sent', true);
    Notification::assertSentOnDemandTimes(ResidentSignInCode::class, 1);
});

it('answers as before for Create account and for an app that does not say it is signing in', function () {
    signInLookup('newcomer.lookup@example.org', 'sign_up')->assertStatus(202)->assertJsonPath('sent', true);
    signInLookup('older.app.lookup@example.org', null)->assertStatus(202)->assertJsonPath('sent', true);

    Notification::assertSentOnDemandTimes(ResidentSignInCode::class, 2);
});

it('waits thirty seconds, not sixty, before a second code can be asked for', function () {
    Resident::query()->create([
        'household_id' => $this->household->id, 'full_name' => 'Resend Lookup', 'relationship' => 'owner', 'is_primary' => true,
        'status' => Resident::VERIFIED, 'email' => 'resend.lookup@example.org',
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));

    $answer = signInLookup('resend.lookup@example.org')->assertStatus(202)->json();

    expect(Carbon::parse('2026-10-10 12:00:00')->diffInSeconds(Carbon::parse($answer['resend_after'])))->toEqual(30.0);
});

it('takes ten lookups an hour at one address the register does not hold, then refuses in its own words', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));

    // Spread across minutes, so the route's own ten-a-minute limit is never what answers.
    foreach (range(1, 10) as $try) {
        if ($try % 9 === 0) {
            Carbon::setTestNow(Carbon::now()->addSeconds(61));
        }
        signInLookup('typo.lookup@example.org')->assertStatus(422)->assertJsonPath('error.code', 'email_not_found');
    }

    $refused = signInLookup('typo.lookup@example.org')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    expect($refused->json('error.message'))->toMatch('/^Too many tries at this address in the last hour\. Try again in \d+ minutes\.$/');
});

it('counts a found address\'s lookups as well, so the limit says nothing about who is on the register', function () {
    Resident::query()->create([
        'household_id' => $this->household->id, 'full_name' => 'Counted Lookup', 'relationship' => 'owner', 'is_primary' => true,
        'status' => Resident::VERIFIED, 'email' => 'counted.lookup@example.org',
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));

    // Nine inside the first half-minute: one code, re-offered eight times, each still a lookup.
    foreach (range(1, 9) as $try) {
        signInLookup('counted.lookup@example.org')->assertStatus(202);
    }

    // The tenth after the half-minute (a second code), and the eleventh is refused as a lookup, not as a code.
    Carbon::setTestNow(Carbon::now()->addSeconds(61));
    signInLookup('counted.lookup@example.org')->assertStatus(202);

    $refused = signInLookup('counted.lookup@example.org')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    expect($refused->json('error.message'))->toMatch('/^Too many tries at this address in the last hour\. Try again in \d+ minutes\.$/');
});

it('takes thirty lookups an hour from one device, whatever the addresses, then refuses', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00'));

    // Spread across minutes, so the route's own ten-a-minute limit is never what answers.
    foreach (range(1, 30) as $try) {
        if ($try % 9 === 0) {
            Carbon::setTestNow(Carbon::now()->addSeconds(61));
        }
        signInLookup("guess{$try}.lookup@example.org", 'sign_in', ['device_uid' => 'lookup-device-1'])->assertStatus(422);
    }

    $refused = signInLookup('guess31.lookup@example.org', 'sign_in', ['device_uid' => 'lookup-device-1'])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited');

    // The clock moved on three minutes above, so the hour has a little less to run.
    expect($refused->json('error.message'))->toMatch('/^Too many sign-in tries from this phone in the last hour\. Try again in \d+ minutes\.$/');
});
