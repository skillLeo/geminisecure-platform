/**
 * Charge fee asks first, and does not look like View — `Pages/Estate/Facilities/Bookings.vue` (M68 §2.5, AME-1).
 *
 *   "View" and "Charge fee" were the same amber link, stacked: one opens a record, the other posts money to a
 *   household's ledger. Charge fee is now a button of its own look, and it asks first, naming the amount (the server's
 *   `fee_label`) and the household; only the dialog's own "Charge" posts. Charging tells nobody by message, and the
 *   question says only what it does.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Bookings } = await import('../../resources/js/Pages/Estate/Facilities/Bookings.vue')

const ROW = {
    id: 8, reference: 'BK-0008', amenity: 'Clubhouse', booked_by: 'Lot 47 · Marcia Bennett', unit: 'Lot 47', when: 'Sat 17 Oct 2026, 2:00 PM to 6:00 PM',
    fee_minor: 300000, fee_label: '$3,000.00', deposit: null, is_charged: false, charged_label: null, status: 'confirmed', status_label: 'Confirmed',
    decidable: false, icon: 'clubhouse',
}

const open = async (row = ROW) => {
    resetInertia({ auth: { user: { id: 68501, name: 'Kayla Morrison', role_label: 'Treasurer' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/amenities/bookings')
    const view = await mount(Bookings, {
        sourceBadge: { source: 'resident', simulated: false },
        estate: { name: 'Phoenix Park' },
        amenities: [{ key: 'clubhouse', label: 'Clubhouse' }],
        filter: '',
        pending: 0,
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

    const charge = () => view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Charge fee')
    const viewLink = () => view.one((el) => el.tag === 'a' && textOf(el).trim() === 'View')
    const asking = () => view.all((el) => el.attrs?.role === 'dialog')[0]
    const inAsk = (cls) => findAll(asking(), byClass(cls))[0]
    const posts = () => visits.filter((v) => v.method === 'post')

    return { view, charge, viewLink, asking, inAsk, posts }
}

describe('Charge fee (AME-1)', () => {
    it('does not look like View', async () => {
        const { charge, viewLink } = await open()

        assert.ok(charge() && viewLink())
        assert.ok(byClass('text-link-sm')(viewLink()), 'View stays the link it is')
        assert.ok(!byClass('text-link-sm')(charge()), 'Charge fee is not drawn as the same link')
        assert.ok(byClass('bk-charge')(charge()), 'it is a button of its own look')
    })

    it('asks first, naming the amount and the household, and posts nothing', async () => {
        const { view, charge, inAsk, posts } = await open()
        await view.click(charge())

        assert.equal(posts().length, 0, 'nothing is posted on the first press')
        assert.equal(textOf(inAsk('cd-title')).trim(), 'Charge $3,000.00 to Lot 47?')
        const body = textOf(inAsk('cd-body'))
        assert.match(body, /BK-0008 · Clubhouse · Sat 17 Oct 2026, 2:00 PM to 6:00 PM · Lot 47 · Marcia Bennett\./)
        assert.match(body, /It goes on the household's ledger and their statement\. A booking is charged once\./)
        assert.equal(textOf(inAsk('cd-confirm')).trim(), 'Charge')
    })

    it('Cancel posts nothing; the dialog\'s Charge posts once, to the fee', async () => {
        const first = await open()
        await first.view.click(first.charge())
        await first.view.click(first.inAsk('cd-cancel'))
        assert.equal(first.asking(), undefined)
        assert.equal(first.posts().length, 0)

        const second = await open()
        await second.view.click(second.charge())
        await second.view.click(second.inAsk('cd-confirm'))
        assert.equal(second.posts().length, 1)
        assert.match(second.posts()[0].url, /\/facilities\/amenities\/bookings\/8\/fee$/)
    })
})
