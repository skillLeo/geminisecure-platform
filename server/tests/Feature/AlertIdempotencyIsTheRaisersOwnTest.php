<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\DuressAlert;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\EstateAssignment;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Services\Dispatch\AlertIntake;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| AN ALERT'S IDEMPOTENCY KEY ANSWERS ONLY FOR THE ONE WHO RAISED IT (bug hunt B095)
|--------------------------------------------------------------------------
|
| `AlertIntake::record()` looked the key up across the whole platform, and
| `DuressController` then wrote the caller's account onto whatever came back. A
| resident at one estate who sent a key an alert at ANOTHER estate already held got
| that estate's alert back with a 201 — its id, status and times — had it rewritten
| with their own account id, and raised nothing of their own.
|
| The key now returns an alert only to the estate and the raiser it belongs to.
| Anybody else's press is a NEW alert, always: a panic is never refused, and never
| handed somebody else's, because of a key.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    Http::preventStrayRequests();
    Notification::fake();

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();
    config(['services.sms.driver' => 'log']);

    Shift::query()->where('tenant_id', $this->site)->delete();
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    // ANOTHER ESTATE, holding an alert raised under a key anybody could guess.
    $this->elsewhere = 'idemelsewhere'.random_int(1000, 9999);
    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->elsewhere, 'name' => 'Harbour View Estate', 'status' => 'active', 'demo' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->theirs = DuressAlert::withoutEvents(fn (): DuressAlert => DuressAlert::create([
        'tenant_id' => $this->elsewhere, 'kind' => 'panic', 'raised_by_name' => 'Somebody Else', 'unit_reference' => 'Lot 2',
        'status' => 'open', 'server_time' => now()->subMinutes(3), 'idempotency_key' => 'sim-harbour-panic-online-3',
    ]));

    $this->unit = FacilitiesFixture::scratchUnit('Lot 612');
    $household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Idempotency household', 'access_restricted' => false]);
    $this->resident = Resident::query()->firstOrCreate(['household_id' => $household->id, 'full_name' => 'Rae Keyholder'], ['relationship' => 'owner', 'is_primary' => true]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

function idemAccount(TestCase $test, string $email): ResidentAccount
{
    return ResidentAccount::query()->create([
        'tenant_id' => $test->site, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Rae Keyholder',
        'resident_id' => $test->resident->id, 'unit_id' => $test->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
}

function idemPress(TestCase $test, ResidentAccount $account, string $key): Illuminate\Testing\TestResponse
{
    $token = $account->createToken('resident-app:'.$account->destination, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    app('auth')->forgetGuards();

    $response = $test->withToken($token)->postJson('/api/v1/duress', ['mode' => 'audible'], [
        'Idempotency-Key' => $key,
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

it('raises the resident\'s own alert, and hands them nothing of another estate\'s, when their key is one another estate holds', function () {
    $account = idemAccount($this, 'rae.keyholder@example.com');

    $answer = idemPress($this, $account, 'sim-harbour-panic-online-3')->assertCreated();

    // Their own panic, at their own estate, from their own account.
    $mine = DuressAlert::query()->findOrFail($answer->json('id'));

    expect($mine->id)->not->toBe($this->theirs->id)
        ->and($mine->tenant_id)->toBe($this->site)
        ->and($mine->raised_by_account_id)->toBe($account->id)
        ->and($mine->unit_reference)->toBe('Lot 612')
        ->and($mine->getAttribute('mode'))->toBe('audible');

    // The other estate's alert is exactly what it was.
    $theirs = $this->theirs->fresh();

    expect($theirs->raised_by_account_id)->toBeNull()
        ->and($theirs->getAttribute('mode'))->toBeNull()
        ->and($theirs->idempotency_key)->toBe('sim-harbour-panic-online-3');
});

it('keeps a retry of that press idempotent, on the key it was given', function () {
    $account = idemAccount($this, 'rae.retry@example.com');

    $first = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Rae Keyholder', idempotencyKey: 'sim-harbour-panic-online-3', raisedByAccountId: $account->id);
    $again = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Rae Keyholder', idempotencyKey: 'sim-harbour-panic-online-3', raisedByAccountId: $account->id);

    expect($again->id)->toBe($first->id)
        ->and($first->id)->not->toBe($this->theirs->id)
        ->and(DuressAlert::query()->where('tenant_id', $this->site)->where('raised_by_account_id', $account->id)->count())->toBe(1);
});

it('never answers one resident with a neighbour\'s alert at the same estate either', function () {
    Exceptions::fake();

    $neighbour = idemAccount($this, 'neighbour@example.com');
    $resident = idemAccount($this, 'resident@example.com');

    $theirs = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Neighbour', idempotencyKey: 'shared-key-1', raisedByAccountId: $neighbour->id);
    $mine = app(AlertIntake::class)->record(tenantId: $this->site, kind: 'panic', raisedByName: 'Resident', idempotencyKey: 'shared-key-1', raisedByAccountId: $resident->id);

    expect($mine->id)->not->toBe($theirs->id)
        ->and($mine->raised_by_account_id)->toBe($resident->id)
        ->and($theirs->fresh()->raised_by_account_id)->toBe($neighbour->id);
});
