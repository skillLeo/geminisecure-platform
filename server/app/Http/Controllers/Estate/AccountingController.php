<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Account;
use App\Models\Estate\Journal;
use App\Services\Estate\AccountEntries;
use App\Services\Estate\Budgets;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Response;

/**
 * Accounting — board screens community-admin-25 to 28.
 *
 * All four are built. The subnav links them.
 *
 * EVERY FIGURE HERE IS THE LEDGER READ BACK. No balance is stored on an
 * account, so there is nothing that could drift from the entries it is made of
 * — see the double-entry migration for why that is structural rather than a
 * convention.
 *
 * CHANGING THE CHART IS `configure`, NOT `create` (12 §2, Wave 1). Adding an
 * account changes what every future entry can post against, and an account
 * with history can only be archived, never removed — so it is the verb the
 * matrix gives to Full alone, and the screen carries a review step before the
 * press. Nothing here deletes.
 */
class AccountingController extends Controller
{
    public const NO_CONFIGURE_ACCESS = 'Changing the chart of accounts needs Accounting configure access.';

    /**
     * M84 §3.3, §3.4: the two Accounting tabs that are absent unless switched on. Every Accounting screen with a tab row
     * draws them from here, so the row is the same on each (Hassam, 12 Oct: the chart showed six, the others four).
     *
     * @return list<array{key: string, label: string, route: string}>
     */
    public static function optionalTabs(): array
    {
        return [
            ...(config('features.budgets') ? [['key' => 'budgets', 'label' => 'Budgets', 'route' => 'estate.accounting.budgets']] : []),
            ...(config('features.manual_journals') ? [['key' => 'manual-journals', 'label' => 'Manual journals', 'route' => 'estate.accounting.manual_journals']] : []),
        ];
    }

    /**
     * Chart of accounts — board community-admin-25.
     *
     * THE ACCOUNTS FOR EVERYBODY IN ACCOUNTING; THE BALANCES FOR THOSE WHO MAY
     * READ THE ESTATE'S POSITION (M10 §5). 1200 on this screen is the estate's
     * total arrears and 4000 its month's dues — the same figures the dashboard
     * tile withholds — so the chart asks the same question of
     * `Dues::maySeeEstatePosition()` and hands the answer down.
     */
    public function chart(Request $request, Ledger $ledger): Response
    {
        return inertia('Estate/Accounting/ChartOfAccounts', [
            'estate' => ['name' => (string) tenant()->name],
            ...$ledger->chartOfAccounts(
                maySeePosition: Dues::maySeeEstatePosition($request->user(), 'accounting_posting'),
            ),

            /*
             * The other three Accounting screens now exist, so the subnav links
             * them. They were disabled here with "not built yet" reasons while
             * they were being built; leaving those in place would have left a
             * console telling an accountant three screens are missing while
             * they sit one click away.
             */
            'tabs' => [
                ['key' => 'chart', 'label' => 'Chart of accounts', 'route' => 'estate.accounting.chart'],
                ['key' => 'vendors', 'label' => 'Vendors', 'route' => 'estate.accounting.vendors'],
                ['key' => 'bills', 'label' => 'Bills & payments', 'route' => 'estate.accounting.bills'],
                ['key' => 'reconciliation', 'label' => 'Bank reconciliation', 'route' => 'estate.accounting.reconciliation'],
                ...self::optionalTabs(),
            ],
            'canConfigure' => $request->user()->can('estate.accounting_posting.configure'),
            'blockedReason' => self::NO_CONFIGURE_ACCESS,

            // M84 §3.1: whether an account opens to its entries. Off (the switch, or no right to the balances): no link.
            'drilldown' => self::mayDrillDown($request),

            // What a new account may be filed under: every active account, by
            // type, so the form can offer only parents of the type chosen.
            'parents' => Account::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type'])
                ->map(static fn (Account $account): array => [
                    'id' => $account->id,
                    'type' => $account->type,
                    'label' => $account->optionLabel(),
                ])
                ->all(),
        ]);
    }

    /**
     * One account's entries — M84 §3.1, behind FEATURE_ACCOUNT_DRILLDOWN. Read only.
     *
     * ABSENT, NOT REFUSING, when it may not be shown: the switch off, or a reader who may not see the estate's balances
     * (the chart withholds them from that reader too) is answered 404, and the chart links nothing.
     */
    public function account(Request $request, Account $account, AccountEntries $entries): Response
    {
        abort_unless(self::mayDrillDown($request), 404);

        // In plain words, one line each (DR-028): Laravel's own named the fields ("The to field must be a date after...").
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], [
            'from.date_format' => 'Choose the first day from the calendar.',
            'to.date_format' => 'Choose the last day from the calendar.',
            'to.after_or_equal' => 'The last day is before the first. Choose the dates again.',
            'page.integer' => 'That page of entries does not exist.',
            'page.min' => 'That page of entries does not exist.',
        ]);

        $from = isset($data['from']) ? Carbon::parse($data['from']) : null;
        $to = isset($data['to']) ? Carbon::parse($data['to']) : Ledger::today();

        return inertia('Estate/Accounting/Account', [
            'estate' => ['name' => (string) tenant()->name],
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => ucfirst($account->type)],
            'filters' => ['from' => $from?->toDateString(), 'to' => $to->toDateString()],
            ...$entries->page($account, $from, $to, (int) ($data['page'] ?? 1)),
        ]);
    }

    /** One journal, from a line of an account — M84 §3.1, behind the same switch. Read only. */
    public function journal(Request $request, string $reference, AccountEntries $entries): Response
    {
        abort_unless(self::mayDrillDown($request), 404);

        $journal = Journal::query()->where('reference', $reference)->firstOrFail();

        return inertia('Estate/Accounting/Journal', [
            'estate' => ['name' => (string) tenant()->name],
            ...$entries->journal($journal),
        ]);
    }

    /**
     * A month's budget, account by account — M84 §3.3, behind FEATURE_BUDGETS. Absent (404) while it is off.
     */
    public function budgets(Request $request, Budgets $budgets): Response
    {
        abort_unless((bool) config('features.budgets'), 404);

        // In plain words (DR-028): Laravel's own read "The period field must match the format Y-m."
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m']], ['period.date_format' => 'Choose a month from the calendar.']);
        $period = $data['period'] ?? Ledger::today()->format('Y-m');

        return inertia('Estate/Accounting/Budgets', [
            'estate' => ['name' => (string) tenant()->name],
            ...$budgets->month($period),
            'canEdit' => $request->user()->can('estate.accounting_posting.create'),
            'blockedReason' => 'Setting a budget needs Accounting create access.',
        ]);
    }

    /**
     * Save a month's budget. POSTS NOTHING: a budget is a plan beside the ledger, never in it. An amount that is not
     * money is refused under its own account, and nothing of the month is saved.
     */
    public function saveBudgets(Request $request, Budgets $budgets): RedirectResponse
    {
        abort_unless((bool) config('features.budgets'), 404);

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'amounts' => ['array'],
        ], [
            'period.required' => 'Choose a month from the calendar.',
            'period.date_format' => 'Choose a month from the calendar.',
        ]);

        $amounts = [];
        $errors = [];

        foreach ((array) ($data['amounts'] ?? []) as $accountId => $typed) {
            $text = str_replace([',', '$', ' '], '', trim((string) $typed));

            if ($text === '') {
                $amounts[(int) $accountId] = null;

                continue;
            }

            if (preg_match('/^\d{1,11}(\.\d{1,2})?$/', $text) !== 1) {
                $errors['amounts.'.$accountId] = 'That does not look like an amount. Example: 12,500.00';

                continue;
            }

            $amounts[(int) $accountId] = (int) round(((float) $text) * 100);
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        $budgets->saveMonth($data['period'], $amounts, $request->user());

        return redirect()
            ->to($this->path('/accounting/budgets').'?period='.$data['period'])
            ->with('success', 'Budget saved.');
    }

    /** The switch, and the right to the estate's balances that the chart asks for. */
    private static function mayDrillDown(Request $request): bool
    {
        return (bool) config('features.account_drilldown')
            && Dues::maySeeEstatePosition($request->user(), 'accounting_posting');
    }

    /** Add an account — after the screen's review step. */
    public function addAccount(Request $request, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:asset,liability,equity,income,expense'],
            'parent_id' => ['nullable', 'integer'],
            'is_bank_account' => ['nullable', 'boolean'],

            // THE REVIEW STEP, enforced server-side: the person adding it has
            // read the code, the name and the type back and confirmed them.
            'reviewed' => ['accepted'],
        ]);

        $parent = isset($data['parent_id']) ? Account::query()->find($data['parent_id']) : null;

        if (isset($data['parent_id']) && $parent === null) {
            return back()->withErrors(['parent_id' => 'That parent account is not in this chart.'])->withInput();
        }

        try {
            $account = $ledger->addAccount(
                code: $data['code'],
                name: $data['name'],
                type: $data['type'],
                parent: $parent,
                isBankAccount: $request->boolean('is_bank_account'),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['code' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/accounting/chart-of-accounts'))
            ->with('success', $account->code.' '.$account->name.' added to the chart. Every future entry may post to it; it can be archived and never removed.');
    }

    /** Archive an account. Never delete. */
    public function archiveAccount(Request $request, Account $account, Ledger $ledger): RedirectResponse
    {
        try {
            $ledger->archiveAccount($account);
        } catch (DomainException $refused) {
            return back()->withErrors(['account' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/chart-of-accounts'))
            ->with('success', $account->code.' '.$account->name.' archived. Nothing already posted to it has moved; nothing new may post to it.');
    }

    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
