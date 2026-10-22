<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Household;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Services\Estate\AmenityCheckout;
use App\Services\Estate\Dues;
use App\Services\Passes\OfflinePassVerifier;
use App\Services\Passes\PassSigningKeys;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M47 §8–§10 — book an amenity the way the design does
|--------------------------------------------------------------------------
|
| Board 21 prices by the hour and describes each place; board 22 books two-hour blocks, takes the house rules and
| reads "Confirm & pay $9,000"; board 31 is "Booking confirmed" with a code and a QR for the gate. The user's answer
| (7 October): confirmed on payment. Under test:
|
|   THE LIST     what each place is, where, its rate by the hour, its blocks, its rules
|   THE QUOTE    the server's: 2 hrs × $2,000, the deposit, $9,000 due today — and anything owed before, said
|   THE ACT      all or nothing: confirmed, the fee paid with a receipt, the deposit held, a code a guard can read
|                aloud, and a pass the gate accepts in the booking's hours and not outside them
|   REFUSED      a declined card, a changed total, a taken slot, the rules not accepted: nothing is made
|   CANCELLED    the guests' pass goes with the booking
|   THE COMMAND  the design's four, said first and written only with --confirm
|
| The estate database is shared by the run and is not rolled back: each test books on a lot of its own (Lots
| 4791–4799), on its own amenity.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    config(['payments.demo_card' => true]);

    // 10:00 AM at the estate (UTC−5) on Wednesday 14 October 2026.
    Carbon::setTestNow(Carbon::parse('2026-10-14 15:00:00', 'UTC'));

    $this->gazebo = Amenity::query()->create([
        'name' => 'M47 Gazebo '.bin2hex(random_bytes(2)), 'icon_key' => 'gazebo', 'capacity' => 30, 'currency' => 'JMD',
        'booking_fee_minor' => 3_000_00, 'deposit_minor' => 5_000_00, 'opens_at' => '09:00:00', 'closes_at' => '21:00:00',
        'booking_window_days' => 60, 'cancellation_hours' => 48, 'sort_order' => 99, 'is_active' => true, 'is_bookable' => true,
        // The pay-in-app flow is an amenity whose approval is switched off (M53 §2); on, its bookings wait for the office.
        'requires_approval' => false,
        'description' => 'garden setting', 'place' => 'Phase 2', 'hourly_rate_minor' => 2_000_00, 'slot_minutes' => 120,
        'rules_summary' => 'noise cut-off at 9:00 PM and no glassware on the lawn', 'rules' => "Noise cut-off at 9:00 PM.\nNo glassware on the lawn.",
    ]);
});

afterEach(function () {
    FacilitiesFixture::boot();
    // Not deleted — its bookings carry posted entries — but out of every list the next test reads.
    $this->gazebo->forceFill(['is_active' => false, 'is_bookable' => false])->save();
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** A household of one on its own lot, its member signed in. @return array{0: Unit, 1: string} */
function m47cHome(string $lot): array
{
    FacilitiesFixture::boot();
    $unit = FacilitiesFixture::scratchUnit($lot);
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Checkout '.$lot, 'access_restricted' => false]);
    $email = 'checkout.'.bin2hex(random_bytes(3)).'@example.com';

    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => 'Hassam Ahmed', 'email' => $email, 'phone' => '+1 876 555 0171', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Hassam Ahmed',
        'resident_id' => $person->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$unit, $account->createToken('resident-app:checkout', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

/** @param  array<string, mixed>  $body */
function m47cCall(TestCase $test, string $token, string $name, array $params = [], array $body = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    app('auth')->forgetGuards();

    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson($uri.($body === [] ? '' : '?'.http_build_query($body)))
        : $test->withToken($token)->postJson($uri, $body, ['Idempotency-Key' => 'm47c-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String()]);
    FacilitiesFixture::boot();

    return $response;
}

/** Saturday 24 October 2026 at the estate, from `$from` to `$to` o'clock. @return array{0: string, 1: string} */
function m47cHours(int $from, int $to): array
{
    return [sprintf('2026-10-24T%02d:00:00-05:00', $from), sprintf('2026-10-24T%02d:00:00-05:00', $to)];
}

/** @param  array<string, mixed>  $over */
function m47cBook(TestCase $test, string $token, Amenity $amenity, array $over = []): TestResponse
{
    [$from, $to] = m47cHours(11, 13);

    return m47cCall($test, $token, 'bookings.book', body: [
        'amenity_id' => $amenity->id, 'starts_at' => $from, 'ends_at' => $to,
        'brand' => 'visa', 'last4' => '4242', 'total' => '9000.00', 'rules_accepted' => true, ...$over,
    ]);
}

function m47cGate(string $token, string $at): string
{
    FacilitiesFixture::boot();
    $keys = app(PassSigningKeys::class)->publicKeys(FacilitiesFixture::ESTATE);

    return app(OfflinePassVerifier::class)->verify($token, [FacilitiesFixture::ESTATE => $keys], FacilitiesFixture::ESTATE, Carbon::parse($at))['verdict'];
}

it('lists each amenity as the design does: what it is, where, by the hour, in two-hour blocks, with its rules', function () {
    [, $token] = m47cHome('Lot 4791');

    $index = m47cCall($this, $token, 'amenities.index')->assertOk();
    ApiContract::assertMatches($index, 'amenities.index');
    $item = collect($index->json('items'))->firstWhere('id', $this->gazebo->id);

    expect($index->json('card_payments'))->toBeTrue()
        ->and($item)->toMatchArray([
            'capacity' => 30, 'description' => 'garden setting', 'place' => 'Phase 2', 'hourly_rate' => '2000.00', 'deposit' => '5000.00', 'slot_minutes' => 120,
            'rules_summary' => 'noise cut-off at 9:00 PM and no glassware on the lawn', 'rules' => "Noise cut-off at 9:00 PM.\nNo glassware on the lawn.",
        ]);
});

it('quotes two hours as the design does — 2 hrs × $2,000, the deposit, $9,000 due today — and adds nothing on the phone', function () {
    [, $token] = m47cHome('Lot 4792');
    [$from, $to] = m47cHours(11, 13);

    $quote = m47cCall($this, $token, 'bookings.quote', ['amenity' => $this->gazebo->id], ['starts_at' => $from, 'ends_at' => $to])->assertOk();
    ApiContract::assertMatches($quote, 'bookings.quote');

    expect($quote->json())->toMatchArray(['minutes' => 120, 'hourly_rate' => '2000.00', 'fee' => '4000.00', 'owed_before' => '0.00', 'credit' => '0.00', 'deposit' => '5000.00', 'total' => '9000.00']);

    // Four hours: twice the fee, the deposit once.
    [$from, $to] = m47cHours(11, 15);
    expect(m47cCall($this, $token, 'bookings.quote', ['amenity' => $this->gazebo->id], ['starts_at' => $from, 'ends_at' => $to])->json('total'))->toBe('13000.00');
});

it('books, pays and confirms at once: the receipt, the deposit held, a code a guard can read aloud, and a pass the gate takes in its hours', function () {
    [$unit, $token] = m47cHome('Lot 4793');

    $booked = m47cBook($this, $token, $this->gazebo)->assertCreated();
    ApiContract::assertMatches($booked, 'bookings.book');

    expect($booked->json())->toMatchArray([
        'status' => 'confirmed', 'booking_fee' => '4000.00', 'deposit' => '5000.00', 'deposit_state' => 'held', 'deposit_demonstration' => true,
        'fee_paid' => true, 'paid_with' => 'Visa •••• 4242',
    ])
        // The place's letters, the event's year and month, a letter and a digit nobody misreads.
        ->and($booked->json('confirmation_code'))->toMatch('/^MG-2610-[A-HJ-NP-Z][2-9]$/')
        ->and($booked->json('receipt.amount'))->toBe('4000.00')
        ->and($booked->json('receipt.is_demonstration'))->toBeTrue();

    // The QR is the guests' pass: the gate takes it from an hour before the event to its end, and at no other time.
    $gate = (string) $booked->json('gate_token');
    expect(m47cGate($gate, '2026-10-24 15:30:00 UTC'))->toBe(OfflinePassVerifier::VALID_OFFLINE)
        ->and(m47cGate($gate, '2026-10-24 17:59:00 UTC'))->toBe(OfflinePassVerifier::VALID_OFFLINE)
        ->and(m47cGate($gate, '2026-10-24 18:30:00 UTC'))->toBe(OfflinePassVerifier::EXPIRED)
        ->and(m47cGate($gate, '2026-10-23 16:00:00 UTC'))->toBe(OfflinePassVerifier::NOT_YET_VALID);

    // It is the household's: on its Visitors list, for the event's day.
    $guests = collect(m47cCall($this, $token, 'visitor_passes.index')->json('items'))->firstWhere('visitor_name', $this->gazebo->name.' guests');
    expect($guests)->not->toBeNull()->and($guests['category'])->toBe('recurring');

    // My bookings says the same.
    $listed = collect(m47cCall($this, $token, 'bookings.index')->json('items'))->firstWhere('id', $booked->json('id'));
    expect($listed['confirmation_code'])->toBe($booked->json('confirmation_code'))
        ->and($listed['gate_token'])->toBe($gate);

    // The ledger: the fee is a charge on the unit, paid; nothing is owed.
    expect(app(Dues::class)->balanceOf($unit)->getMinorAmount()->toInt())->toBe(0);
});

it('makes nothing at all for a card Stripe declines, or a total that changed since it was shown', function () {
    [$unit, $token] = m47cHome('Lot 4794');
    $payments = Payment::query()->count();

    m47cBook($this, $token, $this->gazebo, ['last4' => '0002'])->assertStatus(422)->assertJsonPath('error.code', 'card_declined');
    m47cBook($this, $token, $this->gazebo, ['total' => '8000.00'])->assertStatus(409)->assertJsonPath('error.code', 'payment_refused');

    FacilitiesFixture::boot();
    expect(AmenityBooking::query()->where('unit_id', $unit->id)->count())->toBe(0)
        ->and(VisitorPass::query()->where('unit_id', $unit->id)->count())->toBe(0)
        ->and(Payment::query()->count())->toBe($payments);
});

it('takes the house rules ticked, and refuses a slot already taken in the household\'s words — paying nothing for it', function () {
    [$unit, $token] = m47cHome('Lot 4795');

    m47cBook($this, $token, $this->gazebo, ['rules_accepted' => false])->assertStatus(422)->assertJsonPath('errors.rules_accepted.0', 'Tick the box to accept the house rules.');

    m47cBook($this, $token, $this->gazebo)->assertCreated();
    $payments = Payment::query()->count();
    $again = m47cBook($this, $token, $this->gazebo)->assertStatus(422)->assertJsonPath('error.code', 'booking_refused');

    expect($again->json('error.message'))->toContain('already booked')
        ->and(Payment::query()->count())->toBe($payments)
        ->and(AmenityBooking::query()->where('unit_id', $unit->id)->count())->toBe(1);
});

it('a household that owes from before is told so, and pays exactly what it was told — payments clear the oldest first', function () {
    [$unit, $token] = m47cHome('Lot 4796');
    app(Dues::class)->charge($unit, Money::ofMinor(1_500_00, 'JMD'), 'Older dues', SiteTime::date()->subDays(10));
    [$from, $to] = m47cHours(11, 13);

    $quote = m47cCall($this, $token, 'bookings.quote', ['amenity' => $this->gazebo->id], ['starts_at' => $from, 'ends_at' => $to]);
    expect($quote->json())->toMatchArray(['fee' => '4000.00', 'owed_before' => '1500.00', 'deposit' => '5000.00', 'total' => '10500.00']);

    $booked = m47cBook($this, $token, $this->gazebo, ['total' => '10500.00'])->assertCreated();
    expect($booked->json('receipt.amount'))->toBe('5500.00')
        ->and($booked->json('fee_paid'))->toBeTrue()
        ->and(app(Dues::class)->balanceOf($unit)->getMinorAmount()->toInt())->toBe(0);
});

it('cancelled, the guests\' pass goes with the booking, and the deposit goes back', function () {
    [, $token] = m47cHome('Lot 4797');
    $booked = m47cBook($this, $token, $this->gazebo)->assertCreated();

    $cancelled = m47cCall($this, $token, 'bookings.cancel', ['booking' => $booked->json('id')])->assertOk();
    expect($cancelled->json('status'))->toBe('cancelled')
        ->and($cancelled->json('deposit_state'))->toBe('refunded')
        ->and($cancelled->json('gate_token'))->toBeNull();

    $pass = VisitorPass::query()->where('pass_id', AmenityBooking::query()->findOrFail($booked->json('id'))->gate_pass_id)->firstOrFail();
    expect($pass->status)->toBe(VisitorPass::CANCELLED);
});

it('names each place by two letters a guard can read aloud', function () {
    expect(AmenityCheckout::initials('Gazebo'))->toBe('GZ')
        ->and(AmenityCheckout::initials('Community Centre'))->toBe('CC')
        ->and(AmenityCheckout::initials('Club House'))->toBe('CH')
        ->and(AmenityCheckout::initials('Pool Deck'))->toBe('PD');
});

it('sets the design\'s four amenities only with --confirm, and says what it would do first', function () {
    FacilitiesFixture::boot();
    $names = ['Community Centre', 'Club House', 'Gazebo', 'Pool Deck'];
    $fields = ['sort_order', 'capacity', 'description', 'place', 'hourly_rate_minor', 'deposit_minor', 'slot_minutes', 'opens_at', 'closes_at', 'rules_summary', 'rules'];
    $before = Amenity::query()->whereIn('name', $names)->get()->mapWithKeys(static fn (Amenity $a): array => [$a->name => $a->only($fields)])->all();
    expect(array_keys($before))->toEqualCanonicalizing($names);

    try {
        expect(Artisan::call('amenities:design-terms', ['--estate' => FacilitiesFixture::ESTATE]))->toBe(0);
        $dry = Artisan::output();
        FacilitiesFixture::boot();
        expect($dry)->toContain('Nothing is written without --confirm')->toContain('garden setting')
            ->and(Amenity::query()->whereIn('name', $names)->get()->mapWithKeys(static fn (Amenity $a): array => [$a->name => $a->only($fields)])->all())->toBe($before);

        expect(Artisan::call('amenities:design-terms', ['--estate' => FacilitiesFixture::ESTATE, '--confirm' => true]))->toBe(0);
        FacilitiesFixture::boot();
        $gazebo = Amenity::query()->where('name', 'Gazebo')->sole();
        expect($gazebo->only(['capacity', 'description', 'place', 'hourly_rate_minor', 'deposit_minor', 'slot_minutes', 'sort_order']))
            ->toBe(['capacity' => 30, 'description' => 'garden setting', 'place' => 'Phase 2', 'hourly_rate_minor' => 2_000_00, 'deposit_minor' => 5_000_00, 'slot_minutes' => 120, 'sort_order' => 3])
            ->and(Amenity::query()->where('name', 'Community Centre')->value('sort_order'))->toBe(1);
    } finally {
        // The fixture's rate card is shared by the run: put it back as it was.
        FacilitiesFixture::boot();
        foreach ($before as $name => $values) {
            Amenity::query()->where('name', $name)->sole()->forceFill($values)->save();
        }
    }
});
