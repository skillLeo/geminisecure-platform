<?php

declare(strict_types=1);

use App\Models\Estate\Payment;
use App\Models\Role;
use App\Services\Estate\Dues;
use App\Services\Estate\Receipts;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Recording a payment, and the receipt register — boards 6 and 5
|--------------------------------------------------------------------------
|
| THE DAY-ONE COLLECTION PATH. No card gateway exists, so every dollar an
| estate collects is keyed from board 6's "Record manual payment" and lands
| here: Dr 1000 Bank, Cr 1200 Dues Receivable against the unit, summed below
| in raw SQL by a second route to the same figure.
|
| THE NUMBER IS RULED (12 §1): `{PREFIX}-R-{00001}`, sequential per estate,
| never reused, allocated at posting and never before, gaps recorded and
| visible. Each clause is a test.
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
});

function receiptsNetDebits(string $code, ?int $unitId = null): int
{
    $sql = '
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?'.($unitId === null ? '' : ' AND l.unit_id = ?');

    return (int) DB::connection('tenant')->selectOne($sql, $unitId === null ? [$code] : [$code, $unitId])->net;
}

function receiptsUrl(string $path): string
{
    return FacilitiesFixture::url($path);
}

it('records a manual payment from the ledger, posts it, and numbers the receipt from the estate\'s sequence', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::unit('Lot 47');

    $bank = receiptsNetDebits('1000');
    $receivable = receiptsNetDebits('1200', $unit->id);
    $issued = Payment::query()->count();

    /*
     * THE NUMBER COMES OFF THE SEQUENCE, SO THE EXPECTATION HAS TO COME OFF IT TOO (M6 3.1).
     *
     * This used to expect `$issued + 1` — the payment COUNT plus one — which is the next
     * receipt number only while no number has ever been allocated and then lost. The gap
     * test below deliberately allocates one and leaves it carried by nothing, in an estate
     * database this suite does not roll back, so from that moment on every receipt number is
     * one ahead of the count for good. Under shuffled ordering that is a failure whenever the
     * gap test draws first — but it was never really about ordering: this test owns neither
     * the payments table nor the sequence, and deriving one from the other is a claim about
     * rows it did not write. The next suite that banks a receipt here would break it in a
     * fixed order too.
     *
     * So the sequence is asked for its own high-water mark, and both assertions below are a
     * CHANGE: one more payment than there was, carrying the number after the last one issued.
     */
    $nextNo = 1 + (int) DB::connection('tenant')
        ->table('receipt_sequences')
        ->where('prefix', FacilitiesFixture::RECEIPT_PREFIX)
        ->value('last_no');

    $this->actingAs($treasurer)
        ->get(receiptsUrl('/finance/units/'.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Dues/UnitLedger')
            ->where('canRecord', true));

    $this->actingAs($treasurer)
        ->post(receiptsUrl('/finance/units/'.$unit->id.'/payments'), [
            'method' => 'cheque',
            'amount' => '12400.00',
            'received_on' => now()->subDays(3)->toDateString(),
            'reference' => 'NCB 004521',
        ])
        ->assertRedirect(receiptsUrl('/finance/units/'.$unit->id));

    FacilitiesFixture::boot();

    $payment = Payment::query()->orderByDesc('id')->firstOrFail();
    $prefix = FacilitiesFixture::RECEIPT_PREFIX;

    // The number came from the sequence, in the ruled format, and the paper's
    // own reference is on the record beside it.
    expect(Payment::query()->count())->toBe($issued + 1)
        ->and($payment->receipt_no)->toBe(sprintf('%s-R-%05d', $prefix, $nextNo))
        ->and($payment->method)->toBe('cheque')
        ->and($payment->reference)->toBe('NCB 004521')
        ->and($payment->amount_minor)->toBe(12_400_00)
        ->and($payment->received_by_name)->toBe($treasurer->name)
        ->and($payment->enteredLate())->toBeTrue()
        ->and($payment->journal_ref)->not->toBeNull();

    // Dr 1000 Bank, Cr 1200 against THIS unit — the bank up by the payment,
    // the household's receivable down by the same figure, summed independently.
    expect(receiptsNetDebits('1000') - $bank)->toBe(12_400_00)
        ->and(receiptsNetDebits('1200', $unit->id) - $receivable)->toBe(-12_400_00);
});

it('refuses the post to a role that reads the ledger and may not credit it', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $unit = FacilitiesFixture::unit('Lot 63');

    // The President holds Dues & ledger and Payments as View: the ledger opens,
    // the control is inert with its reason, and the route says no.
    $this->actingAs($president)
        ->get(receiptsUrl('/finance/units/'.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canRecord', false));

    $before = Payment::query()->count();

    $this->actingAs($president)
        ->post(receiptsUrl('/finance/units/'.$unit->id.'/payments'), [
            'method' => 'cash',
            'amount' => '500.00',
            'received_on' => now()->toDateString(),
        ])
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(Payment::query()->count())->toBe($before);
});

it('allocates a number only when the payment posts, so a refused one leaves no gap', function () {
    $dues = app(Dues::class);
    $receipts = app(Receipts::class);
    $unit = FacilitiesFixture::unit('Lot 88');

    $lastBefore = (int) DB::connection('tenant')->table('receipt_sequences')->where('prefix', FacilitiesFixture::RECEIPT_PREFIX)->value('last_no');

    // A future date is refused before the transaction opens.
    expect(fn () => $dues->receive($unit, Money::ofMinor(1_000_00, 'JMD'), 'cash', now()->addDay()))
        ->toThrow(DomainException::class, 'future');

    // A bank account that does not exist is refused INSIDE it — by the ledger,
    // after the number was handed out — and the rollback takes the number back.
    expect(fn () => $dues->receive($unit, Money::ofMinor(1_000_00, 'JMD'), 'cash', now(), bankAccount: '9999'))
        ->toThrow(DomainException::class, 'No account [9999]');

    expect((int) DB::connection('tenant')->table('receipt_sequences')->where('prefix', FacilitiesFixture::RECEIPT_PREFIX)->value('last_no'))->toBe($lastBefore);

    $payment = $dues->receive($unit, Money::ofMinor(1_000_00, 'JMD'), 'cash', now());

    expect($receipts->numberOf($payment->receipt_no))->toBe($lastBefore + 1);
});

it('draws the register in sequence and draws a missing number as a gap, in its place', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $dues = app(Dues::class);
    $unit = FacilitiesFixture::unit('Lot 3');

    /*
     * THE GAPS THIS TEST DID NOT MAKE ARE NOT ITS BUSINESS (M6 3.1).
     *
     * The register draws every number the sequence has ever handed out that no receipt
     * carries, across the WHOLE estate — and this estate's database is committed rather than
     * rolled back, so a gap any earlier test left is still there. Asserting `gaps === []`
     * was asserting a total this test does not own: it passed only while it happened to draw
     * before anything else that lost a number, and the next suite to lose one would break it
     * in a fixed order just as surely.
     *
     * What this test actually proves is that a number handed out and carried by nothing
     * becomes a gap IN ITS PLACE. That is a CHANGE, so it is asserted as one: the gaps
     * standing before are noted here, the two receipts below add none, and the deliberate
     * loss afterwards adds exactly one — its own.
     */
    $gapsBefore = array_column(app(Receipts::class)->register()['gaps'], 'number');

    $first = $dues->receive($unit, Money::ofMinor(2_000_00, 'JMD'), 'bank', now()->subDays(2), reference: 'JN 88120');
    $second = $dues->receive($unit, Money::ofMinor(3_000_00, 'JMD'), 'cash', now()->subDay());

    $this->actingAs($treasurer)
        ->get(receiptsUrl('/finance/receipts'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Dues/Receipts')
            ->where('prefix', FacilitiesFixture::RECEIPT_PREFIX)

            // Two receipts posted, two numbers used: no gap was opened by either.
            ->where('gaps', fn ($gaps): bool => collect($gaps)->pluck('number')->all() === $gapsBefore)
            ->where('rows', function ($rows) use ($first, $second): bool {
                $byNo = collect($rows)->keyBy('receipt_no');

                return $byNo[$second->receipt_no]['method_label'] === 'Cash'
                    && $byNo[$first->receipt_no]['reference'] === 'JN 88120'
                    && $byNo[$first->receipt_no]['amount'] === '$2,000.00'
                    && $byNo[$first->receipt_no]['journal_ref'] === $first->journal_ref;
            }));

    /*
     * A number handed out and carried by nothing — a payment lost to a restore,
     * or removed by hand. The sequence remembers, and the register says so
     * rather than closing the gap: "never reused" is the whole of the promise.
     */
    FacilitiesFixture::boot();

    DB::connection('tenant')->table('receipt_sequences')->where('prefix', FacilitiesFixture::RECEIPT_PREFIX)->increment('last_no');

    $missing = app(Receipts::class)->numberOf($second->receipt_no) + 1;

    // One more gap than there was, and it is this one: the number just lost, in its own
    // place in the sequence, carrying the receipt number nobody will ever be issued.
    $expectedGaps = [...$gapsBefore, $missing];
    $missingNo = app(Receipts::class)->format($missing);

    $this->actingAs($treasurer)
        ->get(receiptsUrl('/finance/receipts'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('gaps', count($expectedGaps))
            ->where('gaps', fn ($gaps): bool => collect($gaps)->pluck('number')->all() === $expectedGaps
                && (collect($gaps)->firstWhere('number', $missing)['receipt_no'] ?? null) === $missingNo));

    FacilitiesFixture::boot();

    // And the next receipt takes the number AFTER the gap, never the gap.
    $third = $dues->receive($unit, Money::ofMinor(100_00, 'JMD'), 'cash', now());

    expect(app(Receipts::class)->numberOf($third->receipt_no))->toBe($missing + 1);
});

it('prints the entry behind a receipt as its reference, not a link to a JSON probe — bug hunt B030', function () {
    /*
     * The register linked each reference to `records/journals/{id}` with an
     * Inertia <Link>. That route is an isolation probe returning the raw journal
     * header as JSON, so a click opened Inertia's invalid-response modal full of
     * JSON — none of the "Dr Bank, Cr Dues Receivable" lines the tooltip promised.
     * No screen shows a journal's lines yet, so the reference is text, and the
     * register no longer carries an id for a link it does not draw.
     */
    $screen = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/Receipts.vue'));

    // Printed in the shared table's cell (M32 F5), as text: no link, no id.
    expect($screen)->not->toContain('/records/journals/')
        ->and($screen)->toContain('{{ row.journal_ref }}')
        ->and($screen)->not->toMatch('/<Link[^>]*journal/');

    foreach (app(Receipts::class)->register()['rows'] as $row) {
        expect($row)->not->toHaveKey('journal_id')
            ->and($row)->toHaveKey('journal_ref');
    }
});

it('carries no household balance onto the register', function () {
    $board = app(Receipts::class)->register();

    $serialised = strtolower((string) json_encode($board));

    /*
     * `not->toContain($word, $message)` read the message as a second needle, which is never in the payload, so the case
     * could not fail: the withheld sentence said "owed" to every reader for as long as it stood. This one can.
     */
    foreach (['balance', 'arrear', 'bucket', 'owed', 'outstanding', 'ageing'] as $forbidden) {
        expect(str_contains($serialised, $forbidden))->toBeFalse(
            "the receipt register's payload contained the word [{$forbidden}], which is a household's financial position"
        );
    }
});

it('draws the month\'s takings off the ledger: the card is what this month\'s payments took off the households\' accounts', function () {
    /*
     * THE MONEY RULE (M97). "Received in {month}" sums the payments recorded this month. Each of them posted Cr 1200
     * against its unit, so the figure is read a second way here, in raw SQL off the journal lines those payments posted,
     * and the card has to be the one the books give. A payment in the register that never reached the ledger, or that
     * posted another amount, is the card disagreeing with the books.
     */
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::unit('Lot 88');

    // One payment of this file's own, so the month holds at least one whatever ran before it.
    app(Dues::class)->receive($unit, Money::ofMinor(1_500_00, 'JMD'), 'cash', Dues::siteDate());

    // The month as the register reads it: the estate's own today (bug hunt B023).
    $today = Dues::siteDate()->startOfDay();

    $taken = (int) DB::connection('tenant')->selectOne("
        SELECT COALESCE(SUM(l.credit_minor - l.debit_minor), 0) AS taken
          FROM payments p
          JOIN journal_lines l ON l.entry_ref = p.journal_ref
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = '1200'
           AND p.status = 'recorded'
           AND p.received_at BETWEEN ? AND ?",
        [$today->copy()->startOfMonth()->toDateTimeString(), $today->copy()->endOfMonth()->toDateTimeString()],
    )->taken;

    $props = [];

    $this->actingAs($treasurer)
        ->get(receiptsUrl('/finance/receipts'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    $card = collect($props['kpis'])->firstWhere('key', 'month');

    expect($taken)->toBeGreaterThanOrEqual(1_500_00)
        ->and($card['label'])->toBe('Received in '.$today->format('F'))
        ->and($card['value'])->toBe(MoneyFormatter::fromMinor($taken));
});

it('offers a receipt\'s PDF only to a reader the download would hand it to (M96 level 3)', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(receiptsUrl('/finance/receipts'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canReceipt', true));

    /*
     * A role built with Dues & ledger and without Payments, as the role editor can build one: the register opens, and
     * the receipt route refuses it, so the page draws no Download receipt to refuse.
     */
    $reader = FacilitiesFixture::viewer(Role::SECRETARY);

    expect($reader->can('estate.dues_ledger.view'))->toBeFalse();

    $reader->givePermissionTo('estate.dues_ledger.view');
    $reader = $reader->fresh();

    expect($reader->can('estate.dues_ledger.view'))->toBeTrue()
        ->and($reader->can('estate.payments.view'))->toBeFalse();

    $this->actingAs($reader)
        ->get(receiptsUrl('/finance/receipts'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canReceipt', false));

    FacilitiesFixture::boot();

    // A payment of this case's own: the shared estate's payments are other files' (and another file may have reversed
    // every one of them by the time this runs).
    $payment = app(Dues::class)->receive(FacilitiesFixture::unit('Lot 88'), Money::ofMinor(100_00, 'JMD'), 'cash', Dues::siteDate());

    $this->actingAs($reader)
        ->post(receiptsUrl('/finance/payments/'.$payment->id.'/receipt'))
        ->assertForbidden();

    // And the page draws the column only under the prop.
    $screen = (string) file_get_contents(resource_path('js/Pages/Estate/Dues/Receipts.vue'));

    expect($screen)->toContain("...(props.canReceipt ? [{ key: 'document', label: 'Document' }] : [])");
});
