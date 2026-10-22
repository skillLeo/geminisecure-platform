/**
 * The chart of accounts balances on the screen — `Pages/Estate/Accounting/ChartOfAccounts.vue` (EST-S-07, M85 A5).
 *
 *   "Accumulated Fund reads 0, the current-year surplus appears nowhere, so assets ≠ liabilities + equity on screen."
 *   The server now carries the surplus in Equity and a total for every group; the page draws each group's total, the
 *   total of liabilities and equity under Equity, and the surplus as a row of its own that opens to nothing and cannot
 *   be archived, since it is not an account.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byTag, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'

const { default: ChartOfAccounts } = await import('../../resources/js/Pages/Estate/Accounting/ChartOfAccounts.vue')

const row = (id, code, name, type, balance) => ({ id, code, name, type, balance_minor: balance, is_active: true, for_period: false })

const groups = [
    { label: 'Assets', total_minor: 285_000_000, rows: [row(1, '1000', 'Operating Bank Account', 'Asset', 85_000_000), row(2, '1010', 'Reserve Fund Account', 'Asset', 200_000_000)] },
    { label: 'Liabilities', total_minor: 0, rows: [row(3, '2000', 'Accounts Payable', 'Liability', 0)] },
    {
        label: 'Equity',
        total_minor: 285_000_000,
        rows: [
            row(4, '3000', 'Accumulated Fund', 'Equity', 200_000_000),
            { ...row('surplus-earlier', '', 'Surplus from earlier years, not yet closed to the fund', 'Equity', 25_000_000), derived: true },
            { ...row('surplus-year', '', 'Surplus for 2026 so far', 'Equity', 60_000_000), derived: true },
        ],
    },
    { label: 'Expenses', total_minor: 40_000_000, rows: [{ ...row(5, '5050', 'Security Services (Gemini Security Ltd)', 'Expense', 40_000_000), for_period: true }] },
]

const open = async () => {
    resetInertia({ auth: { user: { id: 7, name: 'Tracey Reid', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {} }, '/estate/phoenixpark/accounting/chart-of-accounts')

    const view = await mount(ChartOfAccounts, {
        estate: { name: 'Phoenix Park' },
        groups,
        period: 'October 2026',
        asAt: 'Oct 11, 2026',
        kpis: [],
        tabs: [{ key: 'chart', label: 'Chart of accounts' }],
        canConfigure: true,
        blockedReason: '',
        parents: [],
        drilldown: true,
        balance: { assets_minor: 285_000_000, liabilities_and_equity_minor: 285_000_000 },
    })
    await nextTick()

    const rowOf = (name) => view.all(byTag('tr')).find((tr) => textOf(tr).includes(name)) ?? null

    return { view, rowOf }
}

describe('the chart of accounts balances on the screen', () => {
    it('totals each group, and liabilities and equity together under Equity', async () => {
        const page = await open()

        assert.match(textOf(page.rowOf('Total assets')), /\$2,850,000/)
        assert.match(textOf(page.rowOf('Total liabilities and equity')), /\$2,850,000/)
        assert.match(textOf(page.rowOf('Total equity')), /\$2,850,000/)
        assert.match(textOf(page.rowOf('Total expenses')), /\$400,000/)
    })

    it('draws the surplus as a row of its own that opens to nothing and cannot be archived', async () => {
        const page = await open()
        const surplus = page.rowOf('Surplus for 2026 so far')

        assert.ok(surplus, 'the surplus is not on the chart')
        assert.match(textOf(surplus), /\$600,000/)
        assert.equal(findAll(surplus, byTag('a')).length, 0, 'the surplus opens to an account page it does not have')
        assert.equal(findAll(surplus, byTag('button')).length, 0, 'the surplus offers an archive it cannot have')

        // A real account still opens and can be archived.
        assert.equal(findAll(page.rowOf('Accumulated Fund'), byTag('a')).length, 1)
        assert.equal(findAll(page.rowOf('Accumulated Fund'), byTag('button')).length, 1)
    })
})
