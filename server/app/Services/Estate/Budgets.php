<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\BudgetLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BUDGETS, THE SMALLEST HONEST VERSION (M84 §3.3), behind FEATURE_BUDGETS.
 *
 * A budget line is an income or expense account, a month and an amount. Setting one POSTS NOTHING: the ledger is never
 * written here. Budget vs Actual sets each account's budget for a period beside what the ledger says happened, on the
 * account's normal side (income: credits less debits; expense: debits less credits), as the Profit & Loss sums it.
 */
final class Budgets
{
    /**
     * Every open income and expense account, with its budget for the month (null where none is set).
     *
     * @return array{period: string, label: string, rows: list<array{id: int, code: string, name: string, type: string, amount_minor: ?int}>}
     */
    public function month(string $period): array
    {
        $amounts = BudgetLine::query()->where('period', $period)->pluck('amount_minor', 'account_id');

        $rows = Account::query()
            ->whereIn('type', [Account::INCOME, Account::EXPENSE])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type'])
            ->map(static fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => ucfirst($account->type),
                'amount_minor' => isset($amounts[$account->id]) ? (int) $amounts[$account->id] : null,
            ])
            ->values()
            ->all();

        return ['period' => $period, 'label' => Carbon::parse($period.'-01')->format('F Y'), 'rows' => $rows];
    }

    /**
     * Save a month's budget: an amount sets an account's line, nothing or zero removes it. Posts nothing.
     *
     * @param  array<int, int|null>  $amounts  account id => minor units, or null for no budget
     */
    public function saveMonth(string $period, array $amounts, User $by): void
    {
        $budgetable = Account::query()
            ->whereIn('type', [Account::INCOME, Account::EXPENSE])
            ->where('is_active', true)
            ->whereIn('id', array_keys($amounts))
            ->pluck('id')
            ->all();

        DB::connection('tenant')->transaction(function () use ($period, $amounts, $budgetable, $by): void {
            foreach ($amounts as $accountId => $minor) {
                if (! in_array((int) $accountId, $budgetable, true)) {
                    continue;
                }

                if ($minor === null || $minor === 0) {
                    BudgetLine::query()->where('account_id', $accountId)->where('period', $period)->delete();

                    continue;
                }

                BudgetLine::query()->updateOrCreate(
                    ['account_id' => (int) $accountId, 'period' => $period],
                    ['amount_minor' => $minor, 'currency' => 'JMD', 'set_by' => $by->getKey(), 'set_by_name' => $by->name],
                );
            }
        });
    }

    /**
     * Budget against actual for every account with a budget in the months the period covers.
     *
     * @return list<array{code: string, name: string, budget_minor: int, actual_minor: int, variance_minor: int, variance_pct: ?float}>
     */
    public function versusActual(Carbon $start, Carbon $end): array
    {
        $periods = [];
        for ($month = $start->copy()->startOfMonth(); $month->lessThanOrEqualTo($end); $month->addMonth()) {
            $periods[] = $month->format('Y-m');
        }

        $budgets = DB::connection('tenant')->table('budget_lines as b')
            ->join('accounts as a', 'a.id', '=', 'b.account_id')
            ->whereIn('b.period', $periods)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, a.type, SUM(b.amount_minor) AS budget')
            ->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        $actuals = DB::connection('tenant')->table('journal_lines as l')
            ->join('journals as j', 'j.reference', '=', 'l.entry_ref')
            ->whereIn('l.account_id', $budgets->pluck('id')->all())
            ->whereBetween('j.posted_on', [$start->toDateString(), $end->toDateString()])
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit_minor) AS dr, SUM(l.credit_minor) AS cr')
            ->get()
            ->keyBy('account_id');

        $rows = [];

        foreach ($budgets as $line) {
            $totals = $actuals->get($line->id);
            $dr = (int) ($totals->dr ?? 0);
            $cr = (int) ($totals->cr ?? 0);
            $actual = $line->type === Account::INCOME ? $cr - $dr : $dr - $cr;
            $budget = (int) $line->budget;
            $variance = $actual - $budget;

            $rows[] = [
                'code' => (string) $line->code,
                'name' => (string) $line->name,
                'budget_minor' => $budget,
                'actual_minor' => $actual,
                'variance_minor' => $variance,
                'variance_pct' => $budget === 0 ? null : $variance * 100 / $budget,
            ];
        }

        return $rows;
    }
}
