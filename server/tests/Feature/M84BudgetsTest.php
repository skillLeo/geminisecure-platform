<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\JournalLine;
use App\Models\Role;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use App\Services\Estate\Reports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M84 §3.3: budgets, the smallest honest version (10 October 2026)
|--------------------------------------------------------------------------
|
| A budget line is an account, a month and an amount; setting one posts nothing. Budget vs Actual reads, for a period:
| account, budget, actual, variance, and the variance as a percentage. Behind FEATURE_BUDGETS, off by default: off,
| the Budgets page answers 404, the chart offers no Budgets tab, and Budget vs Actual stays the card that says budgets
| are coming.
|
| The done test (M84 §3.3): a budget of a known amount against a known account gives the right variance, and its actual
| ties to the ledger. The fixture's ledger holds no income or expense on or before today, so this file posts 500.00 of
| income once, to an account of its own against a liability of its own; the actual is read back from the ledger.
|
| `budget_lines` is mutable (a plan, not a record): every row this file writes is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    config(['features.budgets' => true]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    // Only this file writes budget_lines, so every row in it is this file's.
    DB::connection('tenant')->table('budget_lines')->delete();
});

/**
 * This file's income account, with 500.00 posted to it this month (once per run, against a liability of its own), and
 * the actual the LEDGER gives for the month on the account's normal side (credits less debits), read back by SQL.
 *
 * @return array{account: Account, period: string, actual: int}
 */
function m84BudgetedSpend(): array
{
    FacilitiesFixture::boot();
    $ledger = app(Ledger::class);
    $income = Account::query()->where('code', '4999-84')->first();

    if ($income === null) {
        $income = $ledger->addAccount('4999-84', 'M84 Budget Income', Account::INCOME);
        $ledger->addAccount('2998-84', 'M84 Budget Holding', Account::LIABILITY);
        $ledger->post('M84 budget income', [Posting::debit('2998-84', 500_00), Posting::credit('4999-84', 500_00)]);
    }

    $period = Ledger::today()->format('Y-m');
    $actual = (int) DB::connection('tenant')->selectOne("
        SELECT COALESCE(SUM(l.credit_minor) - SUM(l.debit_minor), 0) AS actual
          FROM journal_lines l
          JOIN journals j ON j.reference = l.entry_ref
         WHERE l.account_id = ? AND DATE_FORMAT(j.posted_on, '%Y-%m') = ?
    ", [$income->id, $period])->actual;

    expect($actual)->toBe(500_00);

    return ['account' => $income, 'period' => $period, 'actual' => $actual];
}

it('is absent while its switch is off: no page, no tab, and Budget vs Actual does not run', function () {
    config(['features.budgets' => false]);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/budgets'))->assertNotFound();
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tabs', fn ($tabs): bool => collect($tabs)->pluck('key')->doesntContain('budgets')));

    expect(Reports::built())->not->toContain('budget_vs_actual')
        ->and(Reports::built())->toBe(Reports::BUILT);
});

it('sets a month\'s budget for an account, and posts nothing', function () {
    ['account' => $account, 'period' => $period] = m84BudgetedSpend();
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $lines = JournalLine::query()->count();

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tabs', fn ($tabs): bool => collect($tabs)->pluck('key')->contains('budgets')));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/budgets'), ['period' => $period, 'amounts' => [$account->id => '1,250.50']])
        ->assertSessionHasNoErrors()->assertRedirect();

    FacilitiesFixture::boot();
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/budgets?period='.$period))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Accounting/Budgets')
            ->where('period', $period)
            ->where('rows', fn ($rows): bool => collect($rows)->firstWhere('id', $account->id)['amount_minor'] === 125050));

    expect(JournalLine::query()->count())->toBe($lines);
});

it('refuses an amount that is not money, under its account, and saves nothing', function () {
    ['account' => $account, 'period' => $period] = m84BudgetedSpend();

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->post(FacilitiesFixture::url('/accounting/budgets'), ['period' => $period, 'amounts' => [$account->id => 'twelve']])
        ->assertSessionHasErrors('amounts.'.$account->id);

    FacilitiesFixture::boot();
    expect(DB::connection('tenant')->table('budget_lines')->where('account_id', $account->id)->where('period', $period)->exists())->toBeFalse();
});

it('reads budget against actual for the month: the variance is right and the actual is the ledger\'s', function () {
    ['account' => $account, 'period' => $period, 'actual' => $actual] = m84BudgetedSpend();
    $budget = $actual + 600_00;

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->post(FacilitiesFixture::url('/accounting/budgets'), ['period' => $period, 'amounts' => [$account->id => number_format($budget / 100, 2, '.', '')]])
        ->assertSessionHasNoErrors();

    FacilitiesFixture::boot();
    $start = Carbon::parse($period.'-01');
    $report = app(Reports::class)->run('budget_vs_actual', $start->toDateString(), $start->copy()->endOfMonth()->toDateString());
    $row = collect($report['rows'])->firstWhere(1, $account->name);

    expect($report['columns'])->toBe(['Account', 'Name', 'Budget', 'Actual', 'Variance', 'Variance %'])
        ->and($row)->not->toBeNull()
        ->and($row[0])->toBe($account->code)
        ->and($row[2])->toBe('$'.number_format($budget / 100, 2))
        ->and($row[3])->toBe('$'.number_format($actual / 100, 2))
        ->and($row[4])->toBe('-$600.00')
        ->and($row[5])->toBe(number_format(-60000 / $budget * 100, 1).'%');
});
