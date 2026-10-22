/**
 * A vendor's page, after the M97 pass (`Pages/Estate/Accounting/Vendor.vue`).
 *
 *   The way back says where it goes, "Back to vendors", top left before the title (M95 §3); Record new bill and Edit
 *   vendor open the console's modal, each titled with what it does (M95 §1); a role that can never record or edit has
 *   neither button (M96 level 3); a supplier taken off the register greys Record new bill with why on the screen (level
 *   2); and the TRN note says it without an em dash.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'

const { default: Vendor } = await import('../../resources/js/Pages/Estate/Accounting/Vendor.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const TABLE = { sort: null, dir: null, default: { sort: 'date', dir: 'desc' }, sortable: [], page: 1, last: 1, per: 25, sizes: [25], from: 0, to: 0, total: 0, q: null }

const VENDOR = (over = {}) => ({
    id: 3, name: 'Island Electric Services', sub: 'Electrical contractor', trade: 'Electrical contractor', trn: null,
    contact_name: 'Owen Grant', contact_phone: '876 555 0110', contact_email: 'owen@example.org', status: 'active', status_label: 'Active', ...over,
})

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 97003, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/accounting/vendors/3')

    return mount(Vendor, {
        estate: { name: 'Phoenix Park Village 1' },
        vendor: VENDOR(),
        stats: [],
        bills: [],
        table: TABLE,
        reasons: { ticket: 'Opening a work order needs Facilities view.' },
        canOpenTicket: true,
        canCreate: true,
        canEdit: true,
        blockedReason: 'Recording a bill needs Accounting create.',
        editBlockedReason: 'Editing a supplier needs Accounting update.',
        expenseAccounts: [{ code: '5100', label: '5100 · Repairs and maintenance' }],
        openTickets: [],
        today: '2026-10-11',
        ...props,
    })
}

const button = (view, words) => view.all(byTag('button')).find((el) => textOf(el).includes(words)) ?? null

afterEach(() => resetToasts())

describe('a vendor\'s page, after the M97 pass', () => {
    it('says the way back in words, first in the bar, to the register', async () => {
        const view = await open()

        try {
            const back = view.one(byClass('back-link'))
            assert.equal(textOf(back), 'Back to vendors')
            assert.equal(back.attrs.href, '/accounting/vendors')
        } finally {
            view.unmount()
        }
    })

    it('records a bill and edits the vendor in the console\'s modal, each titled with what it does', async () => {
        const view = await open()

        try {
            await view.click(button(view, 'Record new bill'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Record a bill from Island Electric Services')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Enter the invoice amount.')
            await view.click(view.one(byClass('cd-close')))

            await view.click(button(view, 'Edit vendor'))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Edit Island Electric Services')
            assert.equal(view.one(byClass('bill-panel')), null, 'no panel on the page')
        } finally {
            view.unmount()
        }
    })

    it('has neither button for a role that can never record or edit', async () => {
        const view = await open({ canCreate: false, canEdit: false })

        try {
            assert.equal(button(view, 'Record new bill'), null)
            assert.equal(button(view, 'Edit vendor'), null)
        } finally {
            view.unmount()
        }
    })

    it('greys Record new bill for a supplier off the register, with why on the screen', async () => {
        const view = await open({ vendor: VENDOR({ status: 'inactive', status_label: 'Inactive' }) })

        try {
            assert.ok(button(view, 'Record new bill').attrs.disabled)
            assert.equal(textOf(view.one(byClass('stack-why'))), 'This supplier is inactive. A bill cannot be recorded against a vendor the estate no longer deals with.')
        } finally {
            view.unmount()
        }
    })

    it('says the TRN rule without an em dash', async () => {
        const view = await open()

        try {
            const note = textOf(view.one(byClass('trn-note')))
            assert.ok(note.startsWith('No taxpayer registration number on file.'))
            assert.ok(!note.includes('—'))
        } finally {
            view.unmount()
        }
    })
})
