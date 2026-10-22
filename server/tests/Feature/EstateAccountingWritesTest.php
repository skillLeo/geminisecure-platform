<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\Bill;
use App\Models\Estate\Journal;
use App\Models\Estate\Vendor;
use App\Models\Role;
use App\Services\Estate\Ledger;
use App\Services\Estate\Payables;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Add vendor, record bill, add account — boards 25, 26, 27 and 39
|--------------------------------------------------------------------------
|
| Wave 1, items 5 to 7 (12 §2). Three writes, and what each may not do:
| adding a vendor lists a supplier and posts nothing; recording a bill makes
| a DRAFT and posts nothing; adding an account puts a line in the chart with
| no balance and cannot be undone by deletion. The TRN is asked for on the
| register and required at payment, as ruled.
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

/** A supplier's form, every field filled (M18C: a supplier nobody can call cannot be sent to a resident's house). */
function supplierForm(array $fields): array
{
    return [
        'category' => 'General',
        'contact_name' => 'Accounts desk',
        'contact_phone' => '876 555 0190',
        'contact_email' => 'accounts@supplier.example.com',
        ...$fields,
    ];
}

function accountingNetDebits(string $code): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', [$code])->net;
}

it('adds a vendor without a TRN, records a bill against them, and refuses the payment until the TRN arrives', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/accounting/vendors'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', true));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors'), supplierForm([
            'name' => 'Blue Mountain Landscaping',
            'category' => 'Grounds',
            'trn' => '',
            'contact_phone' => '(876) 555 0199',
        ]))
        ->assertRedirect();

    FacilitiesFixture::boot();

    $vendor = Vendor::query()->where('name', 'Blue Mountain Landscaping')->firstOrFail();

    expect($vendor->hasTrn())->toBeFalse()
        ->and($vendor->status)->toBe(Vendor::ACTIVE)
        ->and($vendor->category)->toBe('Grounds');

    // A bill can be recorded and approved against them — the estate owes what
    // it owes — and it is a draft that posts nothing until approved.
    $payable = accountingNetDebits('2000');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills'), [
            'vendor_id' => $vendor->id,
            'description' => 'Verge cutting, September',
            'amount' => '18500.00',
            'due_on' => now()->addDays(30)->toDateString(),
            'account' => '5100',
        ])
        ->assertRedirect(FacilitiesFixture::url('/accounting/bills'));

    FacilitiesFixture::boot();

    $bill = Bill::query()->where('vendor_id', $vendor->id)->firstOrFail();

    expect($bill->status)->toBe(Bill::DRAFT)
        ->and($bill->amount_minor)->toBe(18_500_00)
        ->and($bill->journal_ref)->toBeNull()
        ->and(accountingNetDebits('2000'))->toBe($payable);

    // Approved, the liability posts. Paid, the TRN refuses — the ruling's own
    // shape: required before a bill may be PAID.
    app(Payables::class)->approve($bill, $treasurer);

    FacilitiesFixture::boot();

    expect(accountingNetDebits('2000') - $payable)->toBe(-18_500_00);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills/'.$bill->id.'/pay'), [
            'amount' => '18500.00',
            'method' => 'bank',
            'paid_on' => Ledger::today()->toDateString(),
        ])
        ->assertSessionHasErrors('amount');

    FacilitiesFixture::boot();

    expect(Bill::query()->findOrFail($bill->id)->status)->toBe(Bill::APPROVED);
});

it('normalises a TRN, refuses a bad one and a duplicate name, and refuses the register to a reader', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors'), supplierForm(['name' => 'Kingston Glass Ltd', 'trn' => '100 555 123']))
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Vendor::query()->where('name', 'Kingston Glass Ltd')->value('trn'))->toBe('100-555-123');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors'), supplierForm(['name' => 'kingston glass ltd']))
        ->assertSessionHasErrors('name');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors'), supplierForm(['name' => 'Short TRN Co', 'trn' => '12345']))
        ->assertSessionHasErrors('trn');

    // The President reads Accounting and adds nothing to it.
    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/accounting/vendors'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/accounting/vendors'), ['name' => 'Refused Ltd'])
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(Vendor::query()->whereIn('name', ['Short TRN Co', 'Refused Ltd'])->count())->toBe(0);
});

it('records a bill from the vendor\'s own screen against an open work order, and refuses a number that is not one', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $vendor = FacilitiesFixture::vendor('Island Electric Services');
    $ticket = FacilitiesFixture::ticket(1042);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCreate', true)
            ->has('expenseAccounts')
            ->has('openTickets'));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills'), [
            'vendor_id' => $vendor->id,
            'description' => 'Photocell and two floodlights',
            'amount' => '42000.00',
            'due_on' => now()->addDays(14)->toDateString(),
            'account' => '5100',
            'ticket_number' => 1042,
            'from_vendor' => true,
        ])
        ->assertRedirect(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id));

    FacilitiesFixture::boot();

    $bill = Bill::query()->where('description', 'Photocell and two floodlights')->firstOrFail();

    expect($bill->ticket_id)->toBe($ticket->number)
        ->and($bill->ticket_label)->toBe($ticket->label())
        ->and($bill->status)->toBe(Bill::DRAFT);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills'), [
            'vendor_id' => $vendor->id,
            'description' => 'Against nothing',
            'amount' => '10.00',
            'due_on' => now()->toDateString(),
            'account' => '5100',
            'ticket_number' => 999999,
        ])
        ->assertSessionHasErrors('ticket_number');
});

it('adds an account after the review step, files it under its own type only, and archives rather than deletes', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $assistant = FacilitiesFixture::bookkeeper();

    // Entry on Accounting reads the chart and may not change it.
    $this->actingAs($assistant)
        ->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canConfigure', false));

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/accounting/chart-of-accounts'), ['code' => '5300', 'name' => 'Security Services', 'type' => 'expense', 'reviewed' => true])
        ->assertForbidden();

    // Without the review, refused; with it, added.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/accounting/chart-of-accounts'), ['code' => '5300', 'name' => 'Security Services', 'type' => 'expense'])
        ->assertSessionHasErrors('reviewed');

    $parent = Account::query()->where('code', '5100')->firstOrFail();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/accounting/chart-of-accounts'), [
            'code' => '5300',
            'name' => 'Security Services',
            'type' => 'expense',
            'parent_id' => $parent->id,
            'reviewed' => true,
        ])
        ->assertRedirect(FacilitiesFixture::url('/accounting/chart-of-accounts'));

    FacilitiesFixture::boot();

    $account = Account::query()->where('code', '5300')->firstOrFail();

    expect($account->type)->toBe('expense')
        ->and($account->parent_id)->toBe($parent->id)
        ->and($account->is_active)->toBeTrue()
        ->and(app(Ledger::class)->balanceOf($account)->getMinorAmount()->toInt())->toBe(0);

    // The two things a review cannot catch by eye.
    $ledger = app(Ledger::class);

    expect(fn () => $ledger->addAccount('5300', 'Again', 'expense'))
        ->toThrow(DomainException::class, 'already exists')
        ->and(fn () => $ledger->addAccount('1900', 'Wrong parent', 'asset', $parent))
        ->toThrow(DomainException::class, 'has to be the same');

    // Archived, never deleted: the row stays with its history, closed to new entries.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/accounting/chart-of-accounts/'.$account->id.'/archive'))
        ->assertRedirect(FacilitiesFixture::url('/accounting/chart-of-accounts'));

    FacilitiesFixture::boot();

    $archived = Account::query()->findOrFail($account->id);

    expect($archived->is_active)->toBeFalse()
        ->and($archived->archived_at)->not->toBeNull();

    // A control account cannot be archived — the sub-ledger has to tie to it.
    $control = Account::query()->where('code', '1200')->firstOrFail();

    expect(fn () => $ledger->archiveAccount($control))->toThrow(DomainException::class, 'control account');

    // And there is no route that deletes an account, on purpose.
    $deletes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'estate.accounting.chart')
            && in_array('DELETE', $route->methods(), true));

    expect($deletes)->toBeEmpty();
});

it('edits a supplier — the TRN decides payment, and a supplier with an open bill cannot be deactivated', function () {
    // 12 §2, Wave 2. Editing changes who the estate is allowed to pay, so it
    // is `update`: the Admin Assistant's Entry cell on Accounting reaches it,
    // and a President reading the register does not.
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $vendor = app(Payables::class)->addVendor('Harbour Glass Ltd', 'Glazing');

    $this->withoutVite()->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', true));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id), [
            'name' => 'Harbour Glass Ltd', 'status' => 'active',
        ])
        ->assertForbidden();

    // A nine-digit TRN, keyed however the supplier wrote it, stored the way
    // the register prints it — and that is what unblocks payment.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id), supplierForm([
            'name' => 'Harbour Glass Limited',
            'category' => 'Glazing',
            'trn' => '100 482 517',
            'contact_name' => 'Dane Clarke',
            'contact_email' => 'ACCOUNTS@HARBOURGLASS.COM',
            'status' => 'active',
        ]))
        ->assertRedirect(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id));

    FacilitiesFixture::boot();
    $vendor = Vendor::query()->findOrFail($vendor->id);

    expect($vendor->name)->toBe('Harbour Glass Limited')
        ->and($vendor->trn)->toBe('100-482-517')
        ->and($vendor->contact_email)->toBe('accounts@harbourglass.com')
        ->and($vendor->hasTrn())->toBeTrue();

    // Eight digits is not a TRN, and the refusal says what to do instead.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id), supplierForm([
            'name' => 'Harbour Glass Limited', 'trn' => '10048251', 'status' => 'active',
        ]))
        ->assertSessionHasErrors('name');

    // A name another supplier already has would be two sub-ledgers for one debt.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id), supplierForm([
            'name' => 'Island Electric Services', 'status' => 'active',
        ]))
        ->assertSessionHasErrors('name');

    // A bill outstanding is a debt with somewhere to pay it. Deactivating is
    // refused until it is settled or voided; then it is allowed, and every
    // bill the supplier ever had stays exactly where it is.
    $bill = app(Payables::class)->recordBill(
        vendor: $vendor,
        amount: Money::of('45000.00', 'JMD'),
        description: 'Lobby pane replacement',
        dueOn: now()->addDays(21)->toDateString(),
        account: '5100',
    );

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id), supplierForm([
            'name' => 'Harbour Glass Limited', 'status' => 'inactive',
        ]))
        ->assertSessionHasErrors('name');

    FacilitiesFixture::boot();
    expect(Vendor::query()->findOrFail($vendor->id)->status)->toBe(Vendor::ACTIVE);

    Bill::query()->whereKey($bill->id)->update(['status' => Bill::VOID]);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id), supplierForm([
            'name' => 'Harbour Glass Limited', 'trn' => '100-482-517', 'status' => 'inactive',
        ]))
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Vendor::query()->findOrFail($vendor->id)->status)->toBe(Vendor::INACTIVE)
        ->and(Bill::query()->whereKey($bill->id)->exists())->toBeTrue();
});

/* ------------------------------------------------------------------ */
/* the bug hunt — each a screen or a request that said something untrue */
/* ------------------------------------------------------------------ */

it('answers an amount with more than two decimals, or one too large, on the amount field rather than with a 500', function () {
    /*
     * B022, B081. "6200.005" is one key too many and passed `numeric`; `Money::of`
     * then refused it with an exception nobody caught, and the office got an
     * error page instead of a sentence on the field. An absurd amount overflowed
     * the same way.
     */
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $vendor = FacilitiesFixture::vendor('Island Electric Services');

    foreach (['6200.005', '100000000000000000'] as $amount) {
        $this->actingAs($treasurer)
            ->post(FacilitiesFixture::url('/accounting/bills'), [
                'vendor_id' => $vendor->id,
                'description' => 'One key too many',
                'amount' => $amount,
                'due_on' => now()->addDays(30)->toDateString(),
                'account' => '5100',
            ])
            ->assertSessionHasErrors('amount');
    }

    FacilitiesFixture::boot();

    $bill = app(Payables::class)->recordBill(
        vendor: $vendor,
        amount: Money::of('100.00', 'JMD'),
        description: 'The pay form typo',
        dueOn: now()->addDays(30)->toDateString(),
    );

    foreach (['6200.005', '100000000000000000'] as $amount) {
        $this->actingAs($treasurer)
            ->post(FacilitiesFixture::url('/accounting/bills/'.$bill->id.'/pay'), [
                'amount' => $amount,
                'method' => 'bank',
                'paid_on' => Ledger::today()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');
    }

    FacilitiesFixture::boot();

    expect(Bill::query()->where('description', 'One key too many')->exists())->toBeFalse()
        ->and(Bill::query()->findOrFail($bill->id)->payments()->count())->toBe(0);

    Bill::query()->whereKey($bill->id)->delete();
});

it('records a bill only against an active expense account, and approves none against anything else', function () {
    /*
     * B024. The form offers expense accounts only; the server took any code. A
     * bill "for" 2000 posted Dr 2000 with no vendor and Cr 2000 with one — the
     * control balance did not move, the vendor's did, and the tie broke for good.
     */
    $assistant = FacilitiesFixture::bookkeeper();
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $vendor = FacilitiesFixture::vendor('Island Electric Services');

    foreach (['2000', '1200', '1000', '4000', '9999'] as $code) {
        $this->actingAs($assistant)
            ->post(FacilitiesFixture::url('/accounting/bills'), [
                'vendor_id' => $vendor->id,
                'description' => 'Crafted against '.$code,
                'amount' => '100.00',
                'due_on' => now()->addDays(30)->toDateString(),
                'account' => $code,
            ])
            ->assertSessionHasErrors('account');
    }

    FacilitiesFixture::boot();

    expect(Bill::query()->where('description', 'like', 'Crafted against %')->count())->toBe(0);

    expect(fn () => app(Payables::class)->recordBill(
        vendor: $vendor,
        amount: Money::of('100.00', 'JMD'),
        description: 'Crafted in the service',
        dueOn: now()->addDays(30)->toDateString(),
        account: '2000',
    ))->toThrow(DomainException::class, 'expense account');

    // A draft that already names a control account is refused at approval, and posts nothing.
    $crafted = Bill::query()->create([
        'vendor_id' => $vendor->id,
        'reference' => 'AP-CRAFTED-0001',
        'description' => 'A draft written before the rule',
        'amount_minor' => 100_00,
        'currency' => 'JMD',
        'due_on' => now()->addDays(30)->toDateString(),
        'account_id' => Account::query()->where('code', '2000')->firstOrFail()->id,
        'status' => Bill::DRAFT,
    ]);

    $payable = accountingNetDebits('2000');

    try {
        expect(fn () => app(Payables::class)->approve($crafted, $treasurer))
            ->toThrow(DomainException::class, 'expense account');

        expect(accountingNetDebits('2000'))->toBe($payable)
            ->and(Bill::query()->findOrFail($crafted->id)->status)->toBe(Bill::DRAFT);
    } finally {
        Bill::query()->whereKey($crafted->id)->delete();
    }
});

it('refuses a bill payment dated after the estate\'s today, as a receipt is refused', function () {
    /*
     * B033. "2027-09-24" for "2026-09-24" posted BPY-2027-09 a year ahead and
     * marked the bill paid today, while "Paid this month" left it out.
     */
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $bill = app(Payables::class)->recordBill(
        vendor: FacilitiesFixture::vendor('Island Electric Services'),
        amount: Money::of('100.00', 'JMD'),
        description: 'Paid a year early by a typo',
        dueOn: now()->addDays(30)->toDateString(),
    );

    app(Payables::class)->approve($bill, $treasurer);

    foreach ([SiteTime::today()->addDay(), SiteTime::today()->addYear()] as $later) {
        $this->actingAs($treasurer)
            ->post(FacilitiesFixture::url('/accounting/bills/'.$bill->id.'/pay'), [
                'amount' => '100.00',
                'method' => 'bank',
                'paid_on' => $later->toDateString(),
            ])
            ->assertSessionHasErrors('paid_on');
    }

    FacilitiesFixture::boot();

    expect(Bill::query()->findOrFail($bill->id)->status)->toBe(Bill::APPROVED)
        ->and(Bill::query()->findOrFail($bill->id)->payments()->count())->toBe(0);
});

it('voids a draft bill with a reason, posts nothing, and then lets its supplier be deactivated', function () {
    /*
     * B036. A draft recorded against the wrong supplier could never be removed,
     * and the refusal to deactivate the supplier told the office to "void" it —
     * something no screen could do.
     */
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $vendor = app(Payables::class)->addVendor('Coral Coast Plumbing', 'Plumbing');

    $bill = app(Payables::class)->recordBill(
        vendor: $vendor,
        amount: Money::of('12500.00', 'JMD'),
        description: 'Invoice recorded against the wrong supplier',
        dueOn: now()->addDays(30)->toDateString(),
    );

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id), supplierForm(['name' => 'Coral Coast Plumbing', 'status' => 'inactive']))
        ->assertSessionHasErrors('name');

    expect(session('errors')->first('name'))->toContain('Void');

    // Voiding decides the claim is not owed, which is the approver's verb.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/accounting/bills/'.$bill->id.'/void'), ['reason' => 'Not ours'])
        ->assertForbidden();

    // A void says why.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills/'.$bill->id.'/void'), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills/'.$bill->id.'/void'), ['reason' => 'Recorded against the wrong supplier'])
        ->assertRedirect(FacilitiesFixture::url('/accounting/bills'));

    FacilitiesFixture::boot();

    $voided = Bill::query()->findOrFail($bill->id);

    expect($voided->status)->toBe(Bill::VOID)
        ->and($voided->journal_ref)->toBeNull()
        ->and($voided->void_reason)->toBe('Recorded against the wrong supplier')
        ->and($voided->voided_by_name)->toBe($treasurer->name)
        ->and(Journal::query()->where('source', Ledger::SOURCE_BILL)->where('source_id', $bill->id)->exists())->toBeFalse();

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id), supplierForm(['name' => 'Coral Coast Plumbing', 'status' => 'inactive']))
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Vendor::query()->findOrFail($vendor->id)->status)->toBe(Vendor::INACTIVE);

    // An approved bill is a liability on 2000, and is paid — never voided.
    $owed = app(Payables::class)->recordBill(
        vendor: FacilitiesFixture::vendor('Island Electric Services'),
        amount: Money::of('50.00', 'JMD'),
        description: 'An agreed bill nobody may void',
        dueOn: now()->addDays(30)->toDateString(),
    );

    app(Payables::class)->approve($owed, $treasurer);

    expect(fn () => app(Payables::class)->void(Bill::query()->findOrFail($owed->id), 'Changed our mind', $treasurer))
        ->toThrow(DomainException::class, 'approved');
});

it('dates a new bill from the estate\'s calendar, not the server\'s UTC one', function () {
    /*
     * B106. At 8:30 PM on Sep 30 in Kingston the UTC date is already Oct 1, and
     * the pages built "today" from it. The server now sends the estate's today.
     */
    $this->travelTo(Carbon::parse('2026-10-01 01:30:00', 'UTC'));

    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $vendor = FacilitiesFixture::vendor('Island Electric Services');

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/accounting/bills'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('today', '2026-09-30'));

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('today', '2026-09-30'));
});
