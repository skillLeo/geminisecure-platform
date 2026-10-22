<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Role;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A charge credits an income account in use — bug hunt B024
|--------------------------------------------------------------------------
|
| `Dues::charge()` posts Dr 1200 against the unit and Cr the account it is
| handed. Handed 1200 itself, the entry is Dr 1200 (unit) / Cr 1200 (no unit):
| the control account does not move, the unit's sub-ledger rises, and an
| unlinked credit appears under the empty key — the household is chased for a
| debt the control account does not carry, gate:ledger's tie fails, and the
| append-only ledger has no way to take it back. Handed 1000, the bank is
| credited instead of income.
|
| The form only ever offered income accounts; the server took whatever code the
| request carried. The rule is now the server's, at the route AND in the
| service every caller shares — the same rule `ChargeSchedules::create()`
| already kept for a recurring charge.
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

    FacilitiesFixture::boot();

    Account::query()->where('code', '4990')->delete();
});

/** The receivable control account's unlinked total — every line on 1200 that names no unit. */
function chargeAccountUnlinked(): int
{
    FacilitiesFixture::boot();

    return app(Ledger::class)->subsidiaryBalances(Account::query()->where('code', '1200')->firstOrFail())[''] ?? 0;
}

it('refuses a single or whole-estate charge against anything but an income account in use', function (string $code) {
    // The Treasurer keys charges: the Admin Assistant holds no money module (M53 §1 Law 4).
    $keyer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::scratchUnit('Lot 7421');

    if ($code === '4990') {
        // An income account, archived: it holds history and takes no new posting.
        Account::query()->create([
            'code' => '4990', 'name' => 'Retired levy income', 'type' => Account::INCOME,
            'is_control' => false, 'is_bank_account' => false, 'is_active' => false,
        ]);
    }

    $unlinked = chargeAccountUnlinked();
    $charges = DB::connection('tenant')->table('charges')->count();

    $this->actingAs($keyer)
        ->post(FacilitiesFixture::url('/finance/charges'), [
            'unit' => $unit->reference,
            'type' => 'fine',
            'amount' => '100.00',
            'description' => 'Charge account test',
            'due_on' => now()->toDateString(),
            'account' => $code,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('account');

    $this->actingAs($keyer)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'estate',
            'type' => 'fine',
            'amount' => '100.00',
            'description' => 'Charge account test',
            'due_on' => now()->toDateString(),
            'account' => $code,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('account');

    expect(DB::connection('tenant')->table('charges')->count())->toBe($charges)
        ->and(chargeAccountUnlinked())->toBe($unlinked);
})->with([
    'the receivable control account itself' => '1200',
    'the bank' => '1000',
    'the payables control account' => '2000',
    'an expense' => '5100',
    'an archived income account' => '4990',
    'a code the chart does not have' => '4999',
]);

it('refuses it in the service too, for every caller and not only the form', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 7422');
    $dues = app(Dues::class);
    $unlinked = chargeAccountUnlinked();

    expect(fn () => $dues->charge(unit: $unit, amount: Money::of('100.00', 'JMD'), description: 'x', dueOn: now(), account: '1200'))
        ->toThrow(DomainException::class, 'income account')
        ->and(fn () => $dues->chargeMany(unitIds: [$unit->id], amount: Money::of('100.00', 'JMD'), description: 'x', dueOn: now(), type: 'fine', account: '1000', by: FacilitiesFixture::viewer(Role::TREASURER)))
        ->toThrow(DomainException::class, 'income account')
        ->and(fn () => $dues->chargeRun(rows: [['unit' => $unit, 'amount' => Money::of('100.00', 'JMD'), 'description' => 'x']], dueOn: now(), memo: 'x', account: '1200'))
        ->toThrow(DomainException::class, 'income account');

    expect(chargeAccountUnlinked())->toBe($unlinked);
});

it('still posts a charge to an income account in use', function () {
    // The Treasurer keys charges: the Admin Assistant holds no money module (M53 §1 Law 4).
    $keyer = FacilitiesFixture::viewer(Role::TREASURER);

    // The preview posts nothing, so the positive control leaves no mark on the estate.
    $this->actingAs($keyer)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'estate',
            'type' => 'amenity',
            'amount' => '100.00',
            'description' => 'Charge account test',
            'due_on' => now()->toDateString(),
            'account' => '4100',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirectContains('/finance/charges/new?preview=');
});
