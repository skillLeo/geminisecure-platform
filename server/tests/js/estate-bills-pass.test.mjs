/**
 * Bills & payments, after the M97 pass (`Pages/Estate/Accounting/Bills.vue`).
 *
 *   Record bill, Void and Pay open the console's modal, each titled with what it does and naming the bill (M95 §1),
 *   never a panel on the page or under a row; a role that can never record, approve or pay has no such button (M96
 *   level 3); a bill that cannot be paid yet has Pay now greyed with the short reason under it on the screen (level 2).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'

const { default: Bills } = await import('../../resources/js/Pages/Estate/Accounting/Bills.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const TABLE = { sort: null, dir: null, default: { sort: 'due', dir: 'asc' }, sortable: [], page: 1, last: 1, per: 25, sizes: [25], from: 1, to: 2, total: 2, q: null }

const ROW = (over = {}) => ({
    id: 1, vendor: { name: 'Island Electric Services', initials: 'IE', has_trn: true }, reference: 'AP-2026-10-0001', is_ticket: false, ticket_id: null,
    amount_minor: 4500000, outstanding_minor: 4500000, due_on: 'Nov 10, 2026', status: 'unpaid', status_label: 'Unpaid', settled_payment_id: null, ...over,
})

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 97004, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/accounting/bills')

    return mount(Bills, {
        estate: { name: 'Phoenix Park Village 1' },
        kpis: [],
        rows: [ROW({ id: 1, status: 'draft', status_label: 'Draft' }), ROW({ id: 2, reference: 'AP-2026-10-0002' })],
        table: TABLE,
        canPay: true,
        canApprove: true,
        canOpenTicket: true,
        blockedReason: 'Paying a bill needs Accounting create.',
        reasons: { ticket: 'Opening a work order needs Facilities view.' },
        billVendors: [{ id: 3, name: 'Island Electric Services', has_trn: true }],
        expenseAccounts: [{ code: '5100', label: '5100 · Repairs and maintenance' }],
        openTickets: [],
        today: '2026-10-11',
        ...props,
    })
}

const button = (view, words) => view.all(byTag('button')).find((el) => textOf(el) === words) ?? null

afterEach(() => resetToasts())

describe('Bills & payments, after the M97 pass', () => {
    it('records a bill in the console\'s modal, saying why Record bill is greyed', async () => {
        const view = await open()

        try {
            await view.click(view.all(byTag('button')).find((el) => textOf(el).includes('Record bill')))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Record a bill')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Enter the invoice amount.')
            assert.equal(view.one(byClass('record-panel')), null, 'no panel on the page')
        } finally {
            view.unmount()
        }
    })

    it('voids a draft in a modal that names it, and pays in one that names the supplier and what is owed', async () => {
        const view = await open()

        try {
            await view.click(button(view, 'Void'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Void AP-2026-10-0001 from Island Electric Services?')
            await view.click(view.one(byClass('cd-close')))

            await view.click(button(view, 'Pay now'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Pay Island Electric Services')
            assert.ok(view.text().includes('outstanding on AP-2026-10-0002.'))
            assert.equal(view.one(byClass('pay-cell')), null, 'never a panel under the row')
        } finally {
            view.unmount()
        }
    })

    it('has no Record bill, Approve, Void or Pay now for a role that can never do them', async () => {
        const view = await open({ canPay: false, canApprove: false })

        try {
            for (const words of ['Approve', 'Void', 'Pay now']) {
                assert.equal(button(view, words), null, `${words} is not there`)
            }
            assert.ok(!view.all(byTag('button')).some((el) => textOf(el).includes('Record bill')))
            assert.equal(visits.length, 0)
        } finally {
            view.unmount()
        }
    })

    it('greys Pay now for a supplier with no TRN, with the reason under it on the screen', async () => {
        const view = await open({ rows: [ROW({ vendor: { name: 'Island Electric Services', initials: 'IE', has_trn: false } })] })

        try {
            assert.ok(button(view, 'Pay now').attrs.disabled)
            assert.equal(textOf(view.one(byClass('row-why'))), 'No TRN on file')
        } finally {
            view.unmount()
        }
    })
})
