<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\JournalLine;
use App\Models\Role;
use App\Services\Estate\AccountEntries;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M84 §3.1: an account opens to its entries (10 October 2026)
|--------------------------------------------------------------------------
|
| Clicking an account in the chart of accounts opens its entries: date, journal reference, memo, debit, credit and the
| running balance. A line opens the journal that posted it, and the journal names what made it (its `source`). Read
| only, paginated, filtered by date. Behind FEATURE_ACCOUNT_DRILLDOWN, off by default (M84 §0.1): off, the chart links
| nothing and both addresses answer 404.
|
| The done test (M84 §3.1): an account that went below zero shows the entry that took it there.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED, and journals are append-only: this file posts only to two accounts of its
| own, an asset and a liability, made once and never removed, so no other file's income, expense or cash moves.
|
*/

const M84_ASSET = '1019-84';
const M84_LIABILITY = '2999-84';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    config(['features.account_drilldown' => true]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/**
 * This file's asset account: +100.00, then -200.00, so it ends at -100.00. Made and posted once per run.
 */
function m84Asset(): Account
{
    FacilitiesFixture::boot();
    $ledger = app(Ledger::class);
    $asset = Account::query()->where('code', M84_ASSET)->first();

    if ($asset === null) {
        $asset = $ledger->addAccount(M84_ASSET, 'M84 Drill-down Reserve', Account::ASSET);
        $ledger->addAccount(M84_LIABILITY, 'M84 Drill-down Holding', Account::LIABILITY);
        $ledger->post('M84 opening float', [Posting::debit(M84_ASSET, 100_00), Posting::credit(M84_LIABILITY, 100_00)]);
        $ledger->post('M84 the withdrawal that overdrew it', [Posting::debit(M84_LIABILITY, 200_00), Posting::credit(M84_ASSET, 200_00)]);
    }

    return $asset;
}

it('is absent while its switch is off: the chart links nothing and both addresses answer 404', function () {
    $asset = m84Asset();
    $reference = (string) JournalLine::query()->where('account_id', $asset->id)->orderBy('id')->value('entry_ref');
    config(['features.account_drilldown' => false]);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('drilldown', false));

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/chart-of-accounts/'.$asset->id))->assertNotFound();
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/journals/'.$reference))->assertNotFound();
});

it('opens an account to its entries with the running balance, and shows the entry that took it below zero', function () {
    $asset = m84Asset();
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('drilldown', true));

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/chart-of-accounts/'.$asset->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Accounting/Account')
            ->where('account.code', M84_ASSET)
            ->where('opening_minor', 0)
            ->has('rows', 2)
            ->where('rows.0.memo', 'M84 opening float')
            ->where('rows.0.debit_minor', 100_00)
            ->where('rows.0.credit_minor', 0)
            ->where('rows.0.balance_minor', 100_00)
            ->where('rows.1.memo', 'M84 the withdrawal that overdrew it')
            ->where('rows.1.credit_minor', 200_00)
            ->where('rows.1.balance_minor', -100_00)
            // The entry that took it below zero is the one a reader is looking for.
            ->where('rows.1.below_zero', true)
            ->where('rows.0.below_zero', false)
            ->where('closing_minor', -100_00));
});

it('carries the balance brought forward into a period that starts after the entries', function () {
    $asset = m84Asset();
    $tomorrow = Ledger::today()->addDay()->toDateString();

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/accounting/chart-of-accounts/'.$asset->id.'?from='.$tomorrow.'&to='.$tomorrow))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rows', 0)
            ->where('opening_minor', -100_00)
            ->where('closing_minor', -100_00)
            ->where('filters.from', $tomorrow));
});

it('brings forward, on a later page, the balance that page starts from, so its running balance ties (M98)', function () {
    FacilitiesFixture::boot();
    $ledger = app(Ledger::class);
    $account = Account::query()->where('code', '1019-98')->first();

    // Accounts of this case's own, so no other case's rows move: fifty-one entries of $1.00, one more than a page.
    if ($account === null) {
        $account = $ledger->addAccount('1019-98', 'M98 Paged Reserve', Account::ASSET);
        $ledger->addAccount('2999-98', 'M98 Paged Holding', Account::LIABILITY);

        foreach (range(1, AccountEntries::PER_PAGE + 1) as $n) {
            $ledger->post('M98 paged entry '.$n, [Posting::debit('1019-98', 1_00), Posting::credit('2999-98', 1_00)]);
        }
    }

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/accounting/chart-of-accounts/'.$account->id.'?page=2'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('page', 2)
            ->has('rows', 1)
            ->where('opening_minor', 0)
            // Page two starts where page one ended: fifty entries of $1.00.
            ->where('carried_minor', AccountEntries::PER_PAGE * 1_00)
            ->where('rows.0.balance_minor', (AccountEntries::PER_PAGE + 1) * 1_00)
            ->where('closing_minor', (AccountEntries::PER_PAGE + 1) * 1_00));

    // And a refused period says so in plain words, naming no field.
    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/accounting/chart-of-accounts/'.$account->id.'?from=2026-10-11&to=2026-10-01'))
        ->assertSessionHasErrors(['to' => 'The last day is before the first. Choose the dates again.']);
});

it('opens the journal a line came from, with its lines and what made it', function () {
    $asset = m84Asset();
    $reference = (string) JournalLine::query()->where('account_id', $asset->id)->orderByDesc('id')->value('entry_ref');

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->get(FacilitiesFixture::url('/accounting/journals/'.$reference))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Accounting/Journal')
            ->where('journal.reference', $reference)
            ->where('journal.memo', 'M84 the withdrawal that overdrew it')
            ->where('journal.source', 'Manual entry')
            ->has('lines', 2)
            ->where('lines.0.account', M84_LIABILITY.' M84 Drill-down Holding')
            ->where('lines.0.debit_minor', 200_00)
            ->where('lines.1.account', M84_ASSET.' M84 Drill-down Reserve')
            ->where('lines.1.credit_minor', 200_00));
});

it('writes nothing: neither page has a form, and a post to either address is not a route', function () {
    $asset = m84Asset();
    $lines = JournalLine::query()->count();

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->post(FacilitiesFixture::url('/accounting/chart-of-accounts/'.$asset->id))->assertStatus(405);

    expect(JournalLine::query()->count())->toBe($lines);
});
