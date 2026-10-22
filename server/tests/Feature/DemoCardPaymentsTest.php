<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Payment;
use App\Services\Estate\Amenities;
use App\Services\Estate\DemoCardDeclined;
use App\Services\Estate\DemoCardPayments;
use App\Services\Estate\Dues;
use App\Services\Estate\Receipts;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A test card's payment is real in the books and never in the bank — M12 §2.6, §2.14; D-179
|--------------------------------------------------------------------------
|
| The client asked for a checkout that goes all the way to a receipt with Stripe's test cards, overriding D-077's
| "no card payment in the app". What is under test is that it cannot lie to the treasurer:
|
|   THE BOOKS      a success is Dr 1090 Demonstration Card Payments / Cr the unit's receivable — never 1000 Bank —
|                  with a receipt number from the estate's own sequence, and the balance goes down by what was paid.
|   A DECLINE      posts nothing at all: Stripe's sentence, and the account exactly as it was.
|   A DEPOSIT      a test card's deposit is held against 1090 and refunded back to 1090.
|   THE RECEIPT    says "demonstration" on the office's list and on the document.
|   THE REVERSAL   `payments:reverse-demonstrations` undoes every demonstration — the payment mirrored and marked
|                  reversed, the household owing again, a held demonstration deposit refunded — and never touches a
|                  real payment.
|   THE FEE        payments clear the oldest charge first, so a booking's fee takes everything owed before it.
|
| The estate database is shared by the run and is not rolled back: this file stands on lots of its own (Lots
| 9401–9404) and asserts on its own rows or on the DIFFERENCE its own acts made to an account.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    config(['payments.demo_card' => true]);
    $this->cards = app(DemoCardPayments::class);
    $this->dues = app(Dues::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** One account's debits less credits across the estate. */
function demoNet(string $code): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', [$code])->net;
}

/** @return array<string, array{0: int, 1: int}> one entry's lines by account, proven to balance */
function demoEntry(string $reference): array
{
    $lines = collect(DB::connection('tenant')->select('
        SELECT a.code, SUM(l.debit_minor) AS dr, SUM(l.credit_minor) AS cr
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE l.entry_ref = ?
         GROUP BY a.code
    ', [$reference]));

    expect($lines)->not->toBeEmpty()
        ->and((int) $lines->sum('dr'))->toBe((int) $lines->sum('cr'));

    return $lines->mapWithKeys(static fn ($line): array => [(string) $line->code => [(int) $line->dr, (int) $line->cr]])->all();
}

it('posts a test card\'s payment to 1090 and the receivable, never the bank, with a receipt that says demonstration', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 9401');
    $this->dues->charge($unit, Money::ofMinor(8_000_00, 'JMD'), 'Demo dues', SiteTime::date());
    $before = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();
    $bank = demoNet('1000');
    $demo = demoNet('1090');

    $payment = $this->cards->payDues($unit, Money::ofMinor(2_500_00, 'JMD'), 'visa', '4242', 'Demo Payer');

    expect($payment->is_demonstration)->toBeTrue()
        ->and($payment->method)->toBe(Payment::CARD)
        ->and($payment->receipt_no)->not->toBeEmpty()
        ->and($payment->reference)->toBe('Demonstration card payment · Visa •••• 4242')
        ->and($this->dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe($before - 2_500_00)
        ->and(demoNet('1000'))->toBe($bank)
        ->and(demoNet('1090'))->toBe($demo + 2_500_00);

    $lines = demoEntry((string) $payment->journal_ref);
    expect($lines['1090'])->toBe([2_500_00, 0])
        ->and(array_key_exists('1000', $lines))->toBeFalse();

    // The office's receipts register says so.
    $register = app(Receipts::class)->registerPage(table: Receipts::registerTable(Request::create('/finance/receipts', 'GET', ['q' => (string) $payment->receipt_no])));
    $row = collect($register['rows'])->firstWhere('receipt_no', $payment->receipt_no);
    expect($row)->not->toBeNull()
        ->and($row['is_demonstration'])->toBeTrue()
        ->and($row['method_label'])->toBe('Card (demonstration)');

    // More than is owed, a card that is not a test card, and a switched-off checkout are refused in words.
    expect(fn () => $this->cards->payDues($unit, Money::ofMinor(99_999_00, 'JMD'), 'visa', '4242', 'Demo Payer'))
        ->toThrow(DomainException::class, 'That is more than is owed')
        ->and(fn () => $this->cards->payDues($unit, Money::ofMinor(1_00, 'JMD'), 'visa', '1111', 'Demo Payer'))
        ->toThrow(DomainException::class, 'not one of Stripe\'s test cards');

    config(['payments.demo_card' => false]);
    expect(fn () => $this->cards->payDues($unit, Money::ofMinor(1_00, 'JMD'), 'visa', '4242', 'Demo Payer'))
        ->toThrow(DomainException::class, 'not switched on');
});

it('posts nothing at all for a card Stripe declines, and says Stripe\'s sentence', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 9402');
    $this->dues->charge($unit, Money::ofMinor(1_000_00, 'JMD'), 'Demo dues', SiteTime::date());
    $before = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();
    $payments = Payment::query()->count();
    $demo = demoNet('1090');

    foreach (['0002' => 'declined', '9995' => 'insufficient funds', '0069' => 'expired', '0127' => 'security code'] as $last4 => $words) {
        try {
            $this->cards->payDues($unit, Money::ofMinor(500_00, 'JMD'), 'visa', (string) $last4, 'Demo Payer');
            $this->fail('A declining test card was taken: '.$last4);
        } catch (DemoCardDeclined $declined) {
            expect($declined->getMessage())->toContain($words)->toContain('Nothing was charged');
        }
    }

    expect(Payment::query()->count())->toBe($payments)
        ->and($this->dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe($before)
        ->and(demoNet('1090'))->toBe($demo);
});

it('holds a test card\'s deposit against 1090 and refunds it back there, never out of the bank', function () {
    $amenities = app(Amenities::class);
    $day = Carbon::today()->addDays(12)->format('Y-m-d');
    $booking = $amenities->book(
        amenity: FacilitiesFixture::amenity('Club House'),
        unit: FacilitiesFixture::scratchUnit('Lot 9403'),
        residentName: 'Demo Depositor',
        startsAt: $day.' 15:00',
        endsAt: $day.' 16:00',
    );
    $bank = demoNet('1000');

    $held = $this->cards->payDeposit($booking, 'mastercard', '4444', 'Demo Depositor');
    expect($held->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($held->deposit_demonstration)->toBeTrue()
        ->and(demoEntry((string) $held->deposit_journal_ref)['1090'])->toBe([$booking->deposit_minor, 0])
        ->and(demoNet('1000'))->toBe($bank);

    expect(fn () => $this->cards->payDeposit($held->refresh(), 'mastercard', '4444', 'Demo Depositor'))
        ->toThrow(DomainException::class, 'already paid and held');

    $refunded = $amenities->refundDeposit($held->refresh());
    expect($refunded->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and(demoNet('1000'))->toBe($bank);
});

it('reverses every demonstration and never a real payment', function () {
    // A lot no other test pays on: the reversal undoes EVERY demonstration at the estate, so this lot's balance
    // moves by this test's payment alone.
    $unit = FacilitiesFixture::scratchUnit('Lot 9404');
    $this->dues->charge($unit, Money::ofMinor(4_000_00, 'JMD'), 'Demo dues', SiteTime::date());
    $real = $this->dues->receive($unit, Money::ofMinor(1_000_00, 'JMD'), Payment::BANK, Carbon::now());
    $before = $this->dues->balanceOf($unit)->getMinorAmount()->toInt();

    $payment = $this->cards->payDues($unit, Money::ofMinor(1_500_00, 'JMD'), 'amex', '0005', 'Demo Payer');
    expect($this->dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe($before - 1_500_00);

    $done = $this->cards->reverseAll();

    expect($done['payments'])->toContain($payment->receipt_no)
        ->and($done['payments'])->not->toContain($real->receipt_no)
        ->and($payment->refresh()->status)->toBe('reversed')
        ->and($real->refresh()->status)->toBe('recorded')
        ->and($this->dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe($before)
        ->and(Payment::query()->where('is_demonstration', true)->where('status', 'recorded')->exists())->toBeFalse();

    // A second run finds nothing left to undo.
    expect($this->cards->reverseAll()['payments'])->toBe([]);
});

it('reverses from the command only with --confirm, and says what it would do first', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 9404');
    $this->dues->charge($unit, Money::ofMinor(600_00, 'JMD'), 'Demo dues', SiteTime::date());
    $payment = $this->cards->payDues($unit, Money::ofMinor(600_00, 'JMD'), 'discover', '1117', 'Demo Payer');

    expect(Artisan::call('payments:reverse-demonstrations', ['estate' => FacilitiesFixture::ESTATE]))->toBe(0);
    $said = Artisan::output();
    FacilitiesFixture::boot();
    expect($said)->toContain('Would reverse')->toContain((string) $payment->receipt_no)->toContain('--confirm')
        ->and($payment->refresh()->status)->toBe('recorded');

    expect(Artisan::call('payments:reverse-demonstrations', ['estate' => FacilitiesFixture::ESTATE, '--confirm' => true]))->toBe(0);
    $said = Artisan::output();
    FacilitiesFixture::boot();
    expect($said)->toContain('Reversed')->toContain((string) $payment->receipt_no)
        ->and($payment->refresh()->status)->toBe('reversed');

    expect(Artisan::call('payments:reverse-demonstrations', ['estate' => 'no-such-estate']))->toBe(1);
});

it('pays a booking\'s fee with everything owed before it, since payments clear the oldest charge first', function () {
    $older = ['balance' => 0, 'charges' => []];

    // J$12,400 of dues owed, then a J$3,000 fee raised today: the fee takes J$15,400 to read paid.
    $ledger = ['balance' => 15_400_00, 'charges' => [
        ['id' => 9, 'due' => '2026-09-25', 'amount' => 3_000_00],
        ['id' => 4, 'due' => '2026-09-01', 'amount' => 12_400_00],
    ]];
    expect(Dues::walk($ledger))->toBe([9 => 3_000_00, 4 => 12_400_00])
        ->and(Dues::owedThrough($ledger, 9))->toBe(15_400_00)
        ->and(Dues::owedThrough($ledger, 4))->toBe(12_400_00);

    // Paid J$3,000 alone: the dues are cleared by it, and the fee is still owing — the reason for the rule.
    $paidFeeAlone = ['balance' => 12_400_00, 'charges' => $ledger['charges']];
    expect(Dues::walk($paidFeeAlone)[9])->toBe(3_000_00);

    // A fee not raised yet is walked as it will be: the newest of today, behind a charge already due later — so it
    // takes itself and the J$380 still owed on the older J$500, and the later J$12,500 is left to its own day.
    $withLater = ['balance' => 12_880_00, 'charges' => [
        ['id' => 12, 'due' => '2026-10-05', 'amount' => 12_500_00],
        ['id' => 11, 'due' => '2026-09-25', 'amount' => 500_00],
    ]];
    expect(Dues::owedThrough($withLater, null, 3_000_00, '2026-09-25'))->toBe(3_380_00)
        ->and(Dues::owedThrough($older, null, 3_000_00, '2026-09-25'))->toBe(3_000_00)
        // A household in credit by more than the fee owes nothing on it.
        ->and(Dues::owedThrough(['balance' => -5_000_00, 'charges' => []], null, 3_000_00, '2026-09-25'))->toBe(0);
});
