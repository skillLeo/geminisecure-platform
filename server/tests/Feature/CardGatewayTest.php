<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Estate\Household;
use App\Models\Estate\Payment;
use App\Models\Estate\PaymentIntent;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Services\Estate\CardGateway;
use App\Services\Estate\Dues;
use App\Services\Payments\GatewayDrivers;
use App\Services\Payments\GatewayEvent;
use App\Services\Payments\SimulatedGateway;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| THE CARD GATEWAY — M16 §2.2, M17 §1.2
|--------------------------------------------------------------------------
|
| Everything above the card provider, proven with the simulated provider:
|
|   OFF BY DEFAULT      no driver, or the simulator on a live system, is "no card payments".
|   ONE TAP, ONE INTENT the Idempotency-Key finds the intent it already made; a different
|                       payment under the same key is refused.
|   SIGNED OR REFUSED   a webhook with a bad signature, or a stale one, is refused and stored
|                       nowhere; a genuine one is applied once, and its redelivery is a duplicate.
|   ANY ORDER           every ordering of authorised / captured / settled ends in the same books.
|   THE BOOKS TIE       capture Dr 1080 / Cr 1200; payout Dr 1000 net + Dr 5910 fee / Cr 1080;
|                       refunds out of transit before the payout and out of the bank after it.
|
| The estate database is shared by the run and not rolled back, so this file stands on lots of
| its own (Lots 9501–9549) and asserts on the DIFFERENCE its own acts make to each account.
|
*/

const GW_SECRET = 'whsec_test_m17_card_gateway';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    config([
        'demo.enabled' => true,
        'payments.gateway.driver' => SimulatedGateway::NAME,
        'payments.gateway.webhook_secret' => GW_SECRET,
        'payments.gateway.webhook_tolerance_seconds' => 300,
    ]);

    $this->gateway = app(CardGateway::class);
    $this->dues = app(Dues::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** One account's debits less credits across the estate. */
function gwNet(string $code): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', [$code])->net;
}

/** A scratch lot owing `$owedMinor`, and an intent for `$payMinor` of it. */
function gwIntent(TestCase $test, string $lot, int $owedMinor, int $payMinor, ?string $key = null): PaymentIntent
{
    $unit = FacilitiesFixture::scratchUnit($lot);
    $test->dues->charge($unit, Money::ofMinor($owedMinor, 'JMD'), 'Card gateway dues', SiteTime::date());

    return $test->gateway->open($unit, Money::ofMinor($payMinor, 'JMD'), $key ?? (string) Str::uuid(), null, 'Gateway Payer', 'https://example.org')['intent'];
}

/** @param  array<string, mixed>  $extra */
function gwEvent(PaymentIntent $intent, string $type, array $extra = []): GatewayEvent
{
    return new GatewayEvent(
        eventId: 'evt_'.bin2hex(random_bytes(8)),
        type: $type,
        intentReference: $intent->reference,
        providerRef: $intent->provider_ref,
        amountMinor: $extra['amount_minor'] ?? null,
        feeMinor: $extra['fee_minor'] ?? 0,
        occurredAt: Carbon::now(),
        declineCode: $extra['decline_code'] ?? null,
    );
}

/** @param  array<string, mixed>  $payload */
function gwPost(TestCase $test, array $payload, ?string $header = null): TestResponse
{
    $body = (string) json_encode($payload);
    $response = $test->call('POST', '/api/webhooks/payments/'.SimulatedGateway::NAME.'/'.FacilitiesFixture::ESTATE, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_GATEWAY_SIGNATURE' => $header ?? SimulatedGateway::header($body, GW_SECRET),
    ], $body);

    FacilitiesFixture::boot();

    return $response;
}

/* ─────────────────────────────────────────────────────────────────────────
 | Off by default
 ───────────────────────────────────────────────────────────────────────── */

it('is off with no driver, and the simulator is refused on a live system', function () {
    config(['payments.gateway.driver' => null]);
    expect(GatewayDrivers::current())->toBeNull()->and(CardGateway::enabled())->toBeFalse();

    $unit = FacilitiesFixture::scratchUnit('Lot 9501');
    expect(fn () => $this->gateway->open($unit, Money::ofMinor(1_00, 'JMD'), 'k-off', null, 'X', 'https://example.org'))
        ->toThrow(DomainException::class, 'Card payment is not offered');

    // A name with no driver behind it is off too — never a guess.
    config(['payments.gateway.driver' => 'nosuchprovider']);
    expect(GatewayDrivers::current())->toBeNull();

    // The simulator on a live system (DEMO_MODE off) is "no gateway", not pretend payments.
    config(['payments.gateway.driver' => SimulatedGateway::NAME, 'demo.enabled' => false, 'demo.review' => false]);
    expect(GatewayDrivers::current())->toBeNull();
});

/* ─────────────────────────────────────────────────────────────────────────
 | One tap, one intent
 ───────────────────────────────────────────────────────────────────────── */

it('finds the intent a key already made, and refuses a different payment under the same key', function () {
    $key = (string) Str::uuid();
    $first = gwIntent($this, 'Lot 9502', 10_000_00, 4_000_00, $key);

    $again = $this->gateway->open($first->unit, Money::ofMinor(4_000_00, 'JMD'), $key, null, 'Gateway Payer', 'https://example.org');

    expect($again['replayed'])->toBeTrue()
        ->and($again['intent']->id)->toBe($first->id)
        ->and(PaymentIntent::query()->where('idempotency_key', $key)->count())->toBe(1);

    expect(fn () => $this->gateway->open($first->unit, Money::ofMinor(4_500_00, 'JMD'), $key, null, 'Gateway Payer', 'https://example.org'))
        ->toThrow(DomainException::class, 'already used for a different payment');

    // More than is owed is refused in words.
    expect(fn () => gwIntent($this, 'Lot 9503', 1_000_00, 5_000_00))->toThrow(DomainException::class, 'more than is owed');
});

/* ─────────────────────────────────────────────────────────────────────────
 | Any order ends in the same books
 ───────────────────────────────────────────────────────────────────────── */

it('ends every ordering of authorised, captured and settled in the same books, and applies nothing twice', function () {
    $orders = [
        [GatewayEvent::AUTHORISED, GatewayEvent::CAPTURED, GatewayEvent::SETTLED],
        [GatewayEvent::AUTHORISED, GatewayEvent::SETTLED, GatewayEvent::CAPTURED],
        [GatewayEvent::CAPTURED, GatewayEvent::AUTHORISED, GatewayEvent::SETTLED],
        [GatewayEvent::CAPTURED, GatewayEvent::SETTLED, GatewayEvent::AUTHORISED],
        [GatewayEvent::SETTLED, GatewayEvent::AUTHORISED, GatewayEvent::CAPTURED],
        [GatewayEvent::SETTLED, GatewayEvent::CAPTURED, GatewayEvent::AUTHORISED],
    ];

    $before = ['1000' => gwNet('1000'), '1080' => gwNet('1080'), '1200' => gwNet('1200'), '5910' => gwNet('5910')];

    foreach ($orders as $i => $order) {
        $intent = gwIntent($this, 'Lot '.(9510 + $i), 5_000_00, 3_000_00);
        $events = array_map(static fn (string $type): GatewayEvent => gwEvent($intent, $type, ['fee_minor' => $type === GatewayEvent::SETTLED ? 90_00 : 0]), $order);

        $outcomes = array_map(fn (GatewayEvent $e): string => $this->gateway->apply(SimulatedGateway::NAME, $e, hash('sha256', $e->eventId)), $events);

        // The first event always moves it; the rest either move it or are stale — never an error.
        expect($outcomes[0])->toBe(CardGateway::APPLIED)
            ->and(array_diff($outcomes, [CardGateway::APPLIED, CardGateway::STALE]))->toBe([]);

        // Every one delivered again is a duplicate, and changes nothing.
        foreach ($events as $e) {
            expect($this->gateway->apply(SimulatedGateway::NAME, $e, hash('sha256', $e->eventId)))->toBe(CardGateway::DUPLICATE);
        }

        $intent->refresh();
        expect($intent->status)->toBe(PaymentIntent::SETTLED, 'order '.implode(' → ', $order))
            ->and($intent->captured_minor)->toBe(3_000_00)
            ->and($intent->fee_minor)->toBe(90_00)
            ->and($intent->settled_net_minor)->toBe(2_910_00)
            ->and(Payment::query()->where('unit_id', $intent->unit_id)->where('method', Payment::CARD)->count())->toBe(1);
    }

    // Six payments of 3,000.00 with a 90.00 fee each, however they arrived.
    expect(gwNet('1000') - $before['1000'])->toBe(6 * 2_910_00)
        ->and(gwNet('5910') - $before['5910'])->toBe(6 * 90_00)
        ->and(gwNet('1080') - $before['1080'])->toBe(0)
        ->and(gwNet('1200') - $before['1200'])->toBe(6 * (5_000_00 - 3_000_00));

    // And the books as a whole still balance.
    $totals = DB::connection('tenant')->selectOne('SELECT SUM(debit_minor) AS dr, SUM(credit_minor) AS cr FROM journal_lines');
    expect((int) $totals->dr)->toBe((int) $totals->cr);
});

/* ─────────────────────────────────────────────────────────────────────────
 | Refunds, chargebacks and declines
 ───────────────────────────────────────────────────────────────────────── */

it('refunds out of transit before the payout and out of the bank after it', function () {
    $transit = gwNet('1080');
    $bank = gwNet('1000');

    // Refund 500.00 of 2,000.00 before the payout: out of transit. The payout then moves only what is left.
    $a = gwIntent($this, 'Lot 9520', 2_000_00, 2_000_00);
    $this->gateway->apply('simulated', gwEvent($a, GatewayEvent::CAPTURED), 'h1');
    $this->gateway->apply('simulated', gwEvent($a, GatewayEvent::REFUNDED, ['amount_minor' => 500_00]), 'h2');
    $this->gateway->apply('simulated', gwEvent($a, GatewayEvent::SETTLED, ['fee_minor' => 60_00]), 'h3');

    $a->refresh();
    expect($a->refunded_minor)->toBe(500_00)
        ->and($a->refunded_before_settlement_minor)->toBe(500_00)
        ->and($a->settled_net_minor)->toBe(1_500_00 - 60_00)
        ->and(gwNet('1080'))->toBe($transit)
        ->and(gwNet('1000') - $bank)->toBe(1_440_00)
        ->and($this->dues->balanceOf($a->unit)->getMinorAmount()->toInt())->toBe(500_00);

    // Paid out, then refunded in full: out of the bank, and the household owes it again.
    $bank = gwNet('1000');
    $b = gwIntent($this, 'Lot 9521', 1_200_00, 1_200_00);
    $this->gateway->apply('simulated', gwEvent($b, GatewayEvent::CAPTURED), 'h4');
    $this->gateway->apply('simulated', gwEvent($b, GatewayEvent::SETTLED, ['fee_minor' => 0]), 'h5');
    $this->gateway->apply('simulated', gwEvent($b, GatewayEvent::CHARGED_BACK), 'h6');

    $b->refresh();
    expect($b->status)->toBe(PaymentIntent::REFUNDED)
        ->and($b->charged_back)->toBeTrue()
        ->and(gwNet('1000') - $bank)->toBe(0)
        ->and($this->dues->balanceOf($b->unit)->getMinorAmount()->toInt())->toBe(1_200_00);

    // Nothing left to refund is stale, not a second refund.
    expect($this->gateway->apply('simulated', gwEvent($b, GatewayEvent::REFUNDED), 'h7'))->toBe(CardGateway::STALE);
});

it('declines with a sentence and posts nothing; a capture after a decline stands, a decline after a capture does not', function () {
    $receivable = gwNet('1200');

    $declined = gwIntent($this, 'Lot 9530', 800_00, 800_00);
    expect($this->gateway->apply('simulated', gwEvent($declined, GatewayEvent::DECLINED, ['decline_code' => 'insufficient_funds']), 'd1'))->toBe(CardGateway::APPLIED);

    $declined->refresh();
    expect($declined->status)->toBe(PaymentIntent::DECLINED)
        ->and($declined->decline_reason)->toBe(CardGateway::DECLINE['insufficient_funds'])
        ->and($declined->payment_id)->toBeNull()
        ->and(gwNet('1200') - $receivable)->toBe(800_00); // only the charge — nothing paid

    // The provider then says the money was captured after all: the money arrived, so it is recorded.
    expect($this->gateway->apply('simulated', gwEvent($declined, GatewayEvent::CAPTURED), 'd2'))->toBe(CardGateway::APPLIED);
    expect($declined->refresh()->isCaptured())->toBeTrue();

    // Captured first, declined later: the decline is stale.
    $captured = gwIntent($this, 'Lot 9531', 700_00, 700_00);
    $this->gateway->apply('simulated', gwEvent($captured, GatewayEvent::CAPTURED), 'd3');
    expect($this->gateway->apply('simulated', gwEvent($captured, GatewayEvent::DECLINED), 'd4'))->toBe(CardGateway::STALE)
        ->and($captured->refresh()->status)->toBe(PaymentIntent::CAPTURED);
});

/* ─────────────────────────────────────────────────────────────────────────
 | The webhook door: signed, fresh, once
 ───────────────────────────────────────────────────────────────────────── */

it('applies a signed webhook once, answers its redelivery as a duplicate, and refuses a forged or stale one', function () {
    $intent = gwIntent($this, 'Lot 9540', 3_000_00, 3_000_00);
    $payload = ['id' => 'evt_http_'.bin2hex(random_bytes(6)), 'type' => GatewayEvent::CAPTURED, 'intent' => $intent->reference, 'provider_ref' => $intent->provider_ref, 'occurred_at' => now()->toIso8601String()];

    gwPost($this, $payload)->assertOk()->assertJsonPath('outcome', CardGateway::APPLIED);
    gwPost($this, $payload)->assertOk()->assertJsonPath('outcome', CardGateway::DUPLICATE);

    expect(DB::connection('tenant')->table('payment_gateway_events')->where('event_id', $payload['id'])->count())->toBe(1)
        ->and(PaymentIntent::query()->find($intent->id)?->isCaptured())->toBeTrue();

    // A forged signature: refused, and stored nowhere.
    $forged = ['id' => 'evt_forged_'.bin2hex(random_bytes(6)), 'type' => GatewayEvent::SETTLED, 'intent' => $intent->reference];
    gwPost($this, $forged, 't='.now()->getTimestamp().',v1='.str_repeat('0', 64))->assertStatus(401)->assertJsonPath('error.code', 'signature_invalid');

    // A genuine signature, replayed outside the window: refused.
    $body = (string) json_encode($forged);
    gwPost($this, $forged, SimulatedGateway::header($body, GW_SECRET, now()->subMinutes(10)->getTimestamp()))->assertStatus(401);

    expect(DB::connection('tenant')->table('payment_gateway_events')->where('event_id', $forged['id'])->exists())->toBeFalse();

    // An intent this estate does not have: answered (so the provider stops), recorded, not applied.
    gwPost($this, ['id' => 'evt_unknown_'.bin2hex(random_bytes(6)), 'type' => GatewayEvent::CAPTURED, 'intent' => 'PI-000000000000'])
        ->assertOk()->assertJsonPath('outcome', CardGateway::UNKNOWN_INTENT);

    // No secret set: the door is closed. No driver: there is no door.
    config(['payments.gateway.webhook_secret' => null]);
    gwPost($this, $payload)->assertStatus(503);
    config(['payments.gateway.driver' => null]);
    gwPost($this, $payload)->assertStatus(404);
});

/* ─────────────────────────────────────────────────────────────────────────
 | The resident's door, through its real route
 ───────────────────────────────────────────────────────────────────────── */

it('opens one card payment per key through the resident API, and says so plainly when card payment is off', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 9549');
    $this->dues->charge($unit, Money::ofMinor(9_000_00, 'JMD'), 'Card gateway dues', SiteTime::date());

    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Gateway household', 'access_restricted' => false]);
    $resident = Resident::query()->firstOrCreate(
        ['household_id' => $household->id, 'email' => 'gateway.payer@example.org'],
        ['full_name' => 'Gateway Payer', 'relationship' => 'owner', 'is_primary' => true],
    );

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'gateway.payer.'.bin2hex(random_bytes(3)).'@example.org',
        'full_name' => 'Gateway Payer', 'status' => ResidentAccount::ACTIVE,
        'resident_id' => $resident->id, 'unit_id' => $unit->id, 'verified_at' => now(),
    ]);
    $token = $account->createToken('resident-app:gateway', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    FacilitiesFixture::boot();

    $key = (string) Str::uuid();
    $send = fn (string $amount, string $k): TestResponse => tap(
        $this->withToken($token)->postJson('/api/v1/payments/intents', ['amount' => $amount], ['Idempotency-Key' => $k, 'X-Device-Time' => now()->toIso8601String()]),
        static fn () => FacilitiesFixture::boot(),
    );

    $first = $send('2500.00', $key)->assertCreated()->assertJsonPath('status', PaymentIntent::CREATED);
    $reference = (string) $first->json('reference');

    // The same tap again is the same answer — the middleware replays it byte for byte.
    $send('2500.00', $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('reference', $reference);
    expect(PaymentIntent::query()->where('unit_id', $unit->id)->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/payments/intents/'.$reference)->assertOk()->assertJsonPath('status', PaymentIntent::CREATED);
    FacilitiesFixture::boot();

    // With no provider connected, the answer is the sentence the rest of the app gives.
    config(['payments.gateway.driver' => null]);
    app('auth')->forgetGuards();
    $send('100.00', (string) Str::uuid())->assertStatus(409)->assertJsonPath('error.code', 'payment_refused');
});
