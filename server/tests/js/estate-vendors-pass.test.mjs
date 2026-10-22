/**
 * Vendors, after the M97 pass (`Pages/Estate/Accounting/Vendors.vue`).
 *
 *   Add vendor opens the console's modal, "Add a vendor", never a panel on the page (M95 §1); a role that can never add
 *   a supplier has no Add vendor at all (M96 level 3); the greyed Add to register says why on the screen (level 2); and
 *   the vendor with no TRN reads "No TRN, cannot be paid", with no em dash (M53 Law 3).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'

const { default: Vendors } = await import('../../resources/js/Pages/Estate/Accounting/Vendors.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const TABLE = { sort: null, dir: null, default: { sort: 'name', dir: 'asc' }, sortable: ['name'], page: 1, last: 1, per: 25, sizes: [25, 50], from: 1, to: 1, total: 1, q: null }

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 97002, name: 'Norman Case', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/accounting/vendors')

    return mount(Vendors, {
        estate: { name: 'Phoenix Park Village 1' },
        rows: [{ id: 3, name: 'Island Electric Services', initials: 'IE', trade: 'Electrical contractor', category: null, paid_ytd_minor: 0, status: 'active', status_label: 'Active', has_trn: false }],
        table: TABLE,
        paid_ytd_total_minor: 0,
        canCreate: true,
        blockedReason: 'Adding a supplier needs Accounting create.',
        ...props,
    })
}

const button = (view, words) => view.all(byTag('button')).find((el) => textOf(el).includes(words)) ?? null

afterEach(() => resetToasts())

describe('Vendors, after the M97 pass', () => {
    it('opens Add vendor in the console\'s modal, saying why Add to register is greyed', async () => {
        const view = await open()

        try {
            assert.equal(view.one(byClass('vnd-panel')), null)
            await view.click(button(view, 'Add vendor'))

            assert.equal(textOf(view.one(byClass('cd-title'))), 'Add a vendor')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Enter the supplier’s name.')
        } finally {
            view.unmount()
        }
    })

    it('has no Add vendor at all for a role that can never add a supplier', async () => {
        const view = await open({ canCreate: false })

        try {
            assert.equal(button(view, 'Add vendor'), null)
        } finally {
            view.unmount()
        }
    })

    it('says a vendor with no TRN cannot be paid, with no em dash', async () => {
        const view = await open()

        try {
            assert.ok(view.text().includes('No TRN, cannot be paid'))
            assert.ok(!view.text().includes('No TRN —'))
        } finally {
            view.unmount()
        }
    })
})
