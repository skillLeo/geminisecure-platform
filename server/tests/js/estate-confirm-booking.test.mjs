/**
 * Approving a booking asks first — `Pages/Estate/Facilities/Bookings.vue` (DR-057, found by the audit, 10 Oct; step 8).
 *
 *   Approve confirmed the booking, charged its fee to the household's ledger and told the household, all at the first
 *   press. It now asks, naming the booking, the fee it charges, the household and the channel, and only the dialog's own
 *   "Approve" posts. Cancel sends nothing.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Bookings } = await import('../../resources/js/Pages/Estate/Facilities/Bookings.vue')

const ROW = {
    id: 8, reference: 'BK-0008', amenity: 'Clubhouse', booked_by: 'Lot 47 · Marcia Bennett', when: 'Sat 17 Oct 2026, 2:00 PM to 6:00 PM',
    // `fee_label` is the server's figure (Amenities::bookingsBoard); the diary never divides an amount itself.
    fee_minor: 750000, fee_label: '$7,500.00', deposit: null, is_charged: false, charged_label: null, status: 'requested', status_label: 'Waiting for the office',
    decidable: true, icon: 'clubhouse',
}

const open = async (row = ROW) => {
    resetInertia({ auth: { user: { id: 68001, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/amenities/bookings')
    const view = await mount(Bookings, {
        sourceBadge: { source: 'resident', simulated: false },
        estate: { name: 'Phoenix Park' },
        amenities: [{ key: 'clubhouse', label: 'Clubhouse' }],
        filter: '',
        pending: 1,
        rows: [row],
        table: { q: '', total: 1, page: 1, per: 20, last_page: 1, sort: 'when', dir: 'asc' },
        canUpdate: true,
        canCreate: true,
        canCharge: true,
        chargeReason: '',
        canViewVendors: false,
        reasons: {},
    })
    await flush(nextTick)

    const asking = () => view.all((el) => el.attrs?.role === 'dialog')[0]
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')
    const press = async () => view.click(view.one((el) => el.tag === 'button' && el.attrs?.['aria-label'] === `Approve booking ${row.reference}`))

    return { view, asking, inAsk, posts, press }
}

describe('Approve asks first (DR-057)', () => {
    it('names the booking, the fee it charges, the household and the channel, and posts nothing', async () => {
        const { asking, inAsk, posts, press } = await open()
        await press()

        assert.equal(posts().length, 0, 'nothing is posted on the first press')
        assert.ok(asking(), 'a confirmation opens')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Approve BK-0008 and charge $7,500.00?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /Clubhouse · Sat 17 Oct 2026, 2:00 PM to 6:00 PM · Lot 47 · Marcia Bennett\./)
        assert.match(body, /The fee goes on their ledger\. They are told in the app, and by email where they asked for it\./)
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Approve')
    })

    it('a booking with no fee to charge says only who is told', async () => {
        const { inAsk, press } = await open({ ...ROW, fee_minor: 0, fee_label: null })
        await press()

        assert.equal(textOf(inAsk('cd-title')).trim(), 'Approve BK-0008?')
        assert.doesNotMatch(textOf(inAsk('cd-body')), /ledger/)
    })

    it('Cancel closes it and posts nothing', async () => {
        const { view, asking, inAsk, posts, press } = await open()
        await press()
        await view.click(inAsk('cd-cancel'))

        assert.equal(asking(), undefined)
        assert.equal(posts().length, 0)
    })

    it("the dialog's Approve posts once", async () => {
        const { view, inAsk, posts, press } = await open()
        await press()
        assert.equal(posts().length, 0)
        assert.ok(inAsk('cd-confirm'))
        await view.click(inAsk('cd-confirm'))

        assert.equal(posts().length, 1)
        assert.match(posts()[0].url, /\/facilities\/amenities\/bookings\/8\/approve$/)
    })
})
