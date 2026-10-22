<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\AuditEntry;
use App\Models\Estate\AutopayArrangement;
use App\Models\Estate\Household;
use App\Models\Estate\HouseholdCard;
use App\Models\Estate\Payment;
use App\Models\ResidentAccount;
use App\Services\Estate\Autopay;
use App\Services\Estate\Dues;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M50 §4.2 #3, §6.6 — Save card works, and AutoPay turns on, stays on, and pays
|--------------------------------------------------------------------------
|
| "Save card" said saving a card was not available; "Turn on AutoPay" answered that the estate "takes dues by hand,
| not by card". With the demonstration checkout on (DEMO_MODE), a resident saves a TEST card — what names it, never
| its number — and AutoPay pays what the household owes on its day, up to its limit, through the same checkout as Pay:
| a real receipt, posted to 1090, no money moved. Nothing owed is nothing charged; a declining card says Stripe's words.
|
| The estate database is shared by the run and not rolled back: this file stands on Lots 9501–9504 of its own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    config(['payments.demo_card' => true]);
    $this->dues = app(Dues::class);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** A home on its own lot, a resident account signed in to it, and what it owes. @return array{0: \App\Models\Estate\Unit, 1: ResidentAccount, 2: string} */
function payHome(string $lot, int $owedMinor): array
{
    $unit = FacilitiesFixture::scratchUnit($lot);
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => $lot.' household', 'access_restricted' => false]);
    $resident = Tests\Support\HouseholdHolder::of($household, 'Pat '.$lot);
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'pat.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Pat '.$lot, 'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    // Whatever the shared estate left on this lot, it owes exactly what this test says.
    HouseholdCard::query()->where('unit_id', $unit->id)->delete();
    AutopayArrangement::query()->where('unit_id', $unit->id)->delete();
    $balance = app(Dues::class)->balanceOf($unit)->getMinorAmount()->toInt();
    if ($owedMinor - $balance > 0) {
        app(Dues::class)->charge($unit, Money::ofMinor($owedMinor - $balance, 'JMD'), 'AutoPay test dues', SiteTime::date());
    }

    return [$unit, $account, $account->createToken('resident-app:pay-'.bin2hex(random_bytes(3)), AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

function payCall(TestCase $test, string $token, string $method, string $uri, array $body = [], int $status = 200, ?string $contract = null): TestResponse
{
    app('auth')->forgetGuards();
    $response = $test->withToken($token)->json($method, '/api/v1/'.$uri, $body);
    $response->assertStatus($status);

    if ($contract !== null) {
        ApiContract::assertMatches($response, $contract);
    }

    FacilitiesFixture::boot();

    return $response;
}

it('saves a test card by what names it — never a real one, never an expired one — and the first is the default', function () {
    [, , $token] = payHome('Lot 9501', 0);

    $saved = payCall($this, $token, 'POST', 'me/cards', ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030, 'holder' => '  Pat   Lot 9501 '], 201, 'cards.store');
    expect($saved->json())->toMatchArray(['label' => 'Visa •••• 4242', 'expires' => '08/30', 'holder' => 'Pat Lot 9501', 'default' => true, 'demonstration' => true]);

    payCall($this, $token, 'POST', 'me/cards', ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 1, 'exp_year' => 31, 'default' => true], 201);
    $list = payCall($this, $token, 'GET', 'me/cards', [], 200, 'cards.index');
    expect(collect($list->json('items'))->pluck('label')->all())->toBe(['Mastercard •••• 4444', 'Visa •••• 4242'])
        ->and($list->json('items.0.default'))->toBeTrue()
        ->and($list->json('items.1.default'))->toBeFalse();

    $real = payCall($this, $token, 'POST', 'me/cards', ['brand' => 'visa', 'last4' => '1111', 'exp_month' => 8, 'exp_year' => 2030], 422);
    expect($real->json('error.code'))->toBe('card_refused')->and($real->json('error.message'))->toContain('test cards');
    payCall($this, $token, 'POST', 'me/cards', ['brand' => 'visa', 'last4' => '5556', 'exp_month' => 1, 'exp_year' => 2020], 422);

    // Nothing that names a full card is stored: four columns and a name.
    expect(collect(DB::connection('tenant')->getSchemaBuilder()->getColumnListing('household_cards'))->filter(static fn (string $c): bool => str_contains($c, 'number') || str_contains($c, 'cvc') || str_contains($c, 'cvv'))->all())->toBe([]);
});

it('keeps a person\'s cards their own', function () {
    [, , $mine] = payHome('Lot 9502', 0);
    payCall($this, $mine, 'POST', 'me/cards', ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030], 201);

    [, , $theirs] = payHome('Lot 9503', 0);
    expect(payCall($this, $theirs, 'GET', 'me/cards')->json('items'))->toBe([]);
});

it('turns AutoPay on with the board\'s terms, stays on, says when it next pays — and turns off', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00', SiteTime::zone(FacilitiesFixture::ESTATE)));
    [, , $token] = payHome('Lot 9504', 0);
    $card = payCall($this, $token, 'POST', 'me/cards', ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030], 201)->json('id');

    $on = payCall($this, $token, 'POST', 'autopay', ['card' => $card], 201, 'autopay.store');
    expect($on->json())->toMatchArray(['available' => true, 'on' => true, 'limit' => '15000.00', 'day' => 28, 'next_charge_on' => '2026-10-28'])
        ->and($on->json('card.label'))->toBe('Visa •••• 4242');

    $shown = payCall($this, $token, 'GET', 'autopay', [], 200, 'autopay.show');
    expect($shown->json('on'))->toBeTrue()->and($shown->json('id'))->toBe($on->json('id'));

    // On again changes it; it never stacks.
    payCall($this, $token, 'POST', 'autopay', ['card' => $card, 'limit' => '20000', 'day' => 5], 201);
    expect(payCall($this, $token, 'GET', 'autopay')->json())->toMatchArray(['limit' => '20000.00', 'day' => 5, 'next_charge_on' => '2026-11-05']);

    payCall($this, $token, 'DELETE', 'autopay/'.$on->json('id'), [], 200, 'autopay.destroy');
    expect(payCall($this, $token, 'GET', 'autopay')->json('on'))->toBeFalse();
    expect(AuditEntry::query()->where('action', 'autopay.on')->count())->toBeGreaterThan(0);
});

it('pays what is owed on its day, up to its limit — nothing owed is nothing charged, and a decline is said in Stripe\'s words', function () {
    $today = Carbon::parse('2026-10-28 06:15', SiteTime::zone(FacilitiesFixture::ESTATE));
    Carbon::setTestNow($today);
    $autopay = app(Autopay::class);

    [$owing, $account] = payHome('Lot 9501', 20_000_00);
    $card = HouseholdCard::query()->create(['unit_id' => $owing->id, 'resident_account_id' => $account->id, 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030, 'is_default' => true]);
    $capped = $autopay->turnOn($owing, $account, $card, 15_000_00, 28, $today);

    [$settled, $settledAccount] = payHome('Lot 9502', 0);
    $settledCard = HouseholdCard::query()->create(['unit_id' => $settled->id, 'resident_account_id' => $settledAccount->id, 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030, 'is_default' => true]);
    $nothing = $autopay->turnOn($settled, $settledAccount, $settledCard, 15_000_00, 28, $today);

    [$declining, $decliningAccount] = payHome('Lot 9503', 5_000_00);
    $declined = HouseholdCard::query()->create(['unit_id' => $declining->id, 'resident_account_id' => $decliningAccount->id, 'brand' => 'visa', 'last4' => '0002', 'exp_month' => 8, 'exp_year' => 2030, 'is_default' => true]);
    $refused = $autopay->turnOn($declining, $decliningAccount, $declined, 15_000_00, 28, $today);

    $report = $autopay->runDue(SiteTime::today(FacilitiesFixture::ESTATE));

    expect($capped->refresh()->last_outcome)->toStartWith('Paid $15,000.00 (the limit; the rest is still owed) with Visa •••• 4242 — receipt ')
        ->and($this->dues->balanceOf($owing)->getMinorAmount()->toInt())->toBe(5_000_00)
        ->and(Payment::query()->find($capped->last_payment_id)?->is_demonstration)->toBeTrue()
        ->and($nothing->refresh()->last_outcome)->toBe('Nothing was owed, so nothing was charged.')
        ->and($refused->refresh()->last_outcome)->toBe('Not charged: Your card was declined. Nothing was charged. Try another card.')
        ->and($this->dues->balanceOf($declining)->getMinorAmount()->toInt())->toBe(5_000_00)
        ->and(count($report['paid']))->toBeGreaterThanOrEqual(1);

    // Once on its day: a second run pays nothing more.
    $autopay->runDue(SiteTime::today(FacilitiesFixture::ESTATE));
    expect($this->dues->balanceOf($owing)->getMinorAmount()->toInt())->toBe(5_000_00);
});

it('turns off — and says why — when the card it used is removed, and refuses to start with card payments off', function () {
    [$unit, $account, $token] = payHome('Lot 9504', 0);
    $card = payCall($this, $token, 'POST', 'me/cards', ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030], 201)->json('id');
    payCall($this, $token, 'POST', 'autopay', ['card' => $card], 201);

    payCall($this, $token, 'DELETE', 'me/cards/'.$card, [], 200, 'cards.destroy');
    $after = payCall($this, $token, 'GET', 'autopay');
    expect($after->json('on'))->toBeFalse();
    expect(AutopayArrangement::query()->where('unit_id', $unit->id)->latest('id')->value('last_outcome'))->toBe('Turned off: the card it used was removed.');

    config(['payments.demo_card' => false]);
    expect(payCall($this, $token, 'GET', 'autopay')->json())->toMatchArray(['available' => false]);
    payCall($this, $token, 'POST', 'me/cards', ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030], 422);
});
