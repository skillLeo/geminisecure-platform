<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Services\Passes\VisitorPasses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A pass whose window has ended is not edited or shared — bug hunt B065
|--------------------------------------------------------------------------
|
| Nothing ever writes "expired" onto a pass: it stays `active` in the table and the
| API calls it `expired` on the way out, once its window has ended. `update` and
| `share` asked the STORED status, so a pass that ended yesterday was edited with a
| 200 whose own body said `expired`, and shared with a message telling the visitor
| they were expected yesterday. The catalogue has always promised `409
| pass_not_active` for "used, cancelled or expired".
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    Carbon::setTestNow(Carbon::parse('2026-09-24 14:00:00', 'UTC'));

    $this->unit = FacilitiesFixture::scratchUnit('Lot 7806');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Expired household', 'access_restricted' => false]);
    $this->household->forceFill(['access_restricted' => false])->save();
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Joan Expired'],
        ['relationship' => 'owner', 'is_primary' => true],
    );

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'expired.'.bin2hex(random_bytes(4)).'@example.com',
        'full_name' => 'Joan Expired', 'resident_id' => $this->resident->id, 'unit_id' => $this->unit->id,
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->token = $account->createToken('resident-app:expired', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
});

function expiredPass(object $test, Carbon $from, Carbon $to): VisitorPass
{
    FacilitiesFixture::boot();

    return app(VisitorPasses::class)->issue(FacilitiesFixture::ESTATE, $test->unit, $test->resident->id, 'Joan Expired', [
        'category' => 'recurring', 'visitor_name' => 'Yesterday Caller', 'valid_from' => $from, 'valid_to' => $to,
    ]);
}

function expiredCall(TestCase $test, string $token, string $method, string $uri, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($method, $uri, $body, [
        'Idempotency-Key' => 'exp-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String(),
    ]);

    FacilitiesFixture::boot();

    return $response;
}

it('refuses to edit or share a pass whose window has ended, and changes nothing on it', function () {
    $ended = expiredPass($this, now()->subDay()->setTime(9, 0), now()->subDay()->setTime(17, 0));

    expect($ended->status)->toBe(VisitorPass::ACTIVE);

    $share = expiredCall($this, $this->token, 'POST', '/api/v1/visitor-passes/'.$ended->id.'/share', ['channel' => 'whatsapp']);

    expect($share->status())->toBe(409)
        ->and($share->json('error.code'))->toBe('pass_not_active')
        ->and($share->json('error.message'))->toContain('expired');

    $edit = expiredCall($this, $this->token, 'PATCH', '/api/v1/visitor-passes/'.$ended->id, ['visitor_name' => 'Somebody Else']);

    expect($edit->status())->toBe(409)
        ->and($edit->json('error.code'))->toBe('pass_not_active')
        ->and($edit->json('error.message'))->toContain('expired');

    $stored = $ended->fresh();

    expect((int) $stored?->share_count)->toBe(0)
        ->and($stored?->visitor_name)->toBe('Yesterday Caller');
});

it('still edits and shares a pass that is inside its window', function () {
    $live = expiredPass($this, now()->subHour(), now()->addHours(5));

    $share = expiredCall($this, $this->token, 'POST', '/api/v1/visitor-passes/'.$live->id.'/share', ['channel' => 'sms'])->assertOk();
    ApiContract::assertMatches($share, 'visitor_passes.share');

    $edit = expiredCall($this, $this->token, 'PATCH', '/api/v1/visitor-passes/'.$live->id, ['visitor_name' => 'Today Caller'])->assertOk();
    ApiContract::assertMatches($edit, 'visitor_passes.update');

    expect($share->json('share_count'))->toBe(1)
        ->and($edit->json('visitor_name'))->toBe('Today Caller')
        ->and($edit->json('status'))->toBe('active');
});
