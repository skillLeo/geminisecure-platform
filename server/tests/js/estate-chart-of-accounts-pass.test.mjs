/**
 * Chart of accounts, after the M97 pass (`Pages/Estate/Accounting/ChartOfAccounts.vue`).
 *
 *   Add account opens the console's modal, never a panel on the page (M95 §1); a role that can never change the chart
 *   has no Add account at all (M96 level 3); the greyed Add account in the modal says why on the screen (level 2); an
 *   archived account reads Closed, the one vocabulary's word (M96 §2), and every type reads as a word, never the stored
 *   one; and archiving asks first, naming the account, where it used to act on one click.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const { default: ChartOfAccounts } = await import('../../resources/js/Pages/Estate/Accounting/ChartOfAccounts.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const ROW = (over = {}) => ({ id: 1, code: '5300', name: 'Security Services', type: 'expense', is_active: true, balance_minor: 1250000, derived: false, ...over })

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 97001, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/accounting/chart-of-accounts')

    return mount(ChartOfAccounts, {
        estate: { name: 'Phoenix Park Village 1' },
        groups: [{ label: 'Expenses', rows: [ROW(), ROW({ id: 2, code: '5400', name: 'Old Contractor', is_active: false })], total_minor: 1250000 }],
        period: 'October 2026',
        asAt: 'Oct 11, 2026',
        kpis: [],
        tabs: [{ key: 'chart', label: 'Chart of accounts' }, { key: 'vendors', label: 'Vendors' }],
        canConfigure: true,
        blockedReason: 'Changing the chart needs Accounting configure.',
        parents: [],
        ...props,
    })
}

const button = (view, words) => view.all(byTag('button')).find((el) => textOf(el).includes(words)) ?? null

afterEach(() => resetToasts())

describe('Chart of accounts, after the M97 pass', () => {
    it('opens Add account in the console\'s modal, never as a panel on the page', async () => {
        const view = await open()

        try {
            assert.equal(view.one(byClass('coa-panel')), null)
            await view.click(button(view, 'Add account'))

            assert.equal(textOf(view.one(byClass('cd-title'))), 'Add an account', 'the title says what it does')
            assert.ok(view.one(byClass('cd-form')), 'the form size: wider, full screen on a phone')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Enter a code and a name first.', 'why it is greyed, on the screen')
        } finally {
            view.unmount()
        }
    })

    it('has no Add account at all for a role that can never change the chart', async () => {
        const view = await open({ canConfigure: false })

        try {
            assert.equal(button(view, 'Add account'), null)
            assert.equal(button(view, 'archive'), null, 'and no archive either')
        } finally {
            view.unmount()
        }
    })

    it('reads an archived account as Closed and every type as a word', async () => {
        const view = await open()

        try {
            const cells = (code) => view.all(byTag('tr')).find((row) => textOf(row).startsWith(code)).children.filter((child) => child.tag === 'td').map(textOf)

            const name = view.all(byTag('tr')).find((row) => textOf(row).startsWith('5400'))
            assert.equal(textOf(name.children.filter((child) => child.tag === 'td')[1].children.find((child) => child.tag === 'span')), 'Closed', 'the vocabulary\'s word for an account closed to new entries')
            assert.ok(cells('5400')[1].startsWith('Old Contractor'))
            assert.ok(!view.text().includes('archived'), 'not the old word on the row')
            assert.equal(cells('5300')[2], 'Expense', 'the type as a word, never the stored one')
        } finally {
            view.unmount()
        }
    })

    it('asks before archiving, naming the account, and posts only when confirmed', async () => {
        const view = await open()

        try {
            await view.click(button(view, 'archive'))
            assert.equal(visits.length, 0, 'one click no longer archives')
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Archive 5300 Security Services?')

            await view.click(button(view, 'Archive the account'))
            await nextTick()
            assert.equal(visits.at(-1).url, '/accounting/chart-of-accounts/1/archive')
        } finally {
            view.unmount()
        }
    })
})
