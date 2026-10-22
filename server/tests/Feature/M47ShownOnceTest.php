<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Services\ResidentApp\AccountDeletion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M47 §3 — shown once means once for the ACCOUNT, on every phone
|--------------------------------------------------------------------------
|
| The first-run tour and the "This estate is on Jamaica time" card came back at every sign-in: "seen" was written to
| the phone's own key-value store, which every chosen sign-out, every reinstall and every new phone wiped. The record
| is now the account's (`ShownOnce`, `resident_accounts.shown`): `me.show` says what it has been shown, `me.shown`
| records it, the first time stands, and nothing the phone does can lose it.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 4747');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Shown-once household', 'access_restricted' => false]);
});

afterEach(function () {
    Carbon::setTestNow();
    FacilitiesFixture::boot();
    Resident::withRemoved()->where('household_id', $this->household->id)->delete();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** @return array{0: ResidentAccount, 1: string} an active account on this file's lot, and one phone's token */
function m47Account(Household $household, bool $primary = true): array
{
    FacilitiesFixture::boot();
    $email = 'shown.'.bin2hex(random_bytes(3)).'@example.com';

    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => 'Hassam Shown', 'email' => $email, 'phone' => '+1 876 555 0147', 'relationship' => $primary ? 'owner' : 'spouse', 'is_primary' => $primary, 'status' => Resident::VERIFIED])->save();

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Hassam Shown',
        'resident_id' => $person->id, 'unit_id' => $household->unit_id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$account, m47Phone($account, 'phone-1')];
}

/** Another sign-in for the same account: a second phone, or the same phone after a reinstall. */
function m47Phone(ResidentAccount $account, string $install): string
{
    return $account->createToken('resident-app:'.$install, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
}

/** @param  array<string, mixed>  $body */
function m47Call(TestCase $test, string $token, string $name, array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $headers = $entry['write'] ? ['Idempotency-Key' => 'shown-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $response = $test->withToken($token)->json($entry['method'], '/api/v1/'.$entry['uri'], $body, $headers);
    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

it('tells a new account it has been shown nothing, and keeps the tour done once it is finished or skipped', function () {
    $at = Carbon::now('UTC')->startOfSecond();
    Carbon::setTestNow($at);
    [, $token] = m47Account($this->household);

    m47Call($this, $token, 'me.show')->assertJsonPath('account.shown', ['tour' => null]);
    m47Call($this, $token, 'me.shown', ['key' => 'tour'])->assertJsonPath('shown.tour', $at->toIso8601String());
    m47Call($this, $token, 'me.show')->assertJsonPath('account.shown.tour', $at->toIso8601String());
});

it('holds on every phone and after a reinstall: the record is the account\'s, not the phone\'s', function () {
    [$account, $first] = m47Account($this->household);
    m47Call($this, $first, 'me.shown', ['key' => 'tour']);

    // A sign-out the person chose, a reinstall, a new phone: each is a new sign-in, a new token.
    $first = null;
    $account->tokens()->delete();
    $another = m47Phone($account->refresh(), 'phone-2');

    expect(m47Call($this, $another, 'me.show')->json('account.shown.tour'))->toBeString();
});

it('lets the first time stand: a second phone marking it later changes nothing', function () {
    $first = Carbon::now('UTC')->startOfSecond();
    Carbon::setTestNow($first);
    [$account, $token] = m47Account($this->household);
    m47Call($this, $token, 'me.shown', ['key' => 'tour']);

    // Two days on, on another phone signed in then.
    Carbon::setTestNow($first->copy()->addDays(2));
    m47Call($this, m47Phone($account, 'phone-2'), 'me.shown', ['key' => 'tour'])->assertJsonPath('shown.tour', $first->toIso8601String());
});

it('refuses a thing the app does not show once', function () {
    [, $token] = m47Account($this->household);

    m47Call($this, $token, 'me.shown', ['key' => 'jamaica_time_card'], 422)->assertJsonPath('error.code', 'validation_failed');
    m47Call($this, $token, 'me.shown', [], 422);
    expect(m47Call($this, $token, 'me.show')->json('account.shown.tour'))->toBeNull();
});

it('keeps it per account: somebody else in the household has their own', function () {
    [, $mine] = m47Account($this->household);
    [, $theirs] = m47Account($this->household, primary: false);

    m47Call($this, $mine, 'me.shown', ['key' => 'tour']);

    expect(m47Call($this, $theirs, 'me.show')->json('account.shown.tour'))->toBeNull();
});

it('goes with everything else about the person when the account is anonymised', function () {
    [$account, $token] = m47Account($this->household);
    m47Call($this, $token, 'me.shown', ['key' => 'tour']);

    app(AccountDeletion::class)->anonymise($account->refresh(), AccountDeletion::WINDOW_CLOSED);

    expect(DB::connection('mysql')->table('resident_accounts')->where('id', $account->id)->value('shown'))->toBeNull();
});
