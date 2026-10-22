/**
 * The amber chip shows the bookings waiting on the office (AME-2, register DR-089).
 *
 *   `Pages/Estate/Facilities/Bookings.vue`: "2 pending approvals" was text, a count nobody could open. It is now the
 *   chip that keeps only those bookings (`?waiting=1`), pressed again to show every booking; the amenity chips keep it
 *   while it is on; and a view with nothing waiting says so, in its own words.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Bookings } = await import('../../resources/js/Pages/Estate/Facilities/Bookings.vue')

const ROW = {
    id: 8, reference: 'BK-0008', amenity: 'Clubhouse', booked_by: 'Lot 47 · Marcia Bennett', when: 'Sat 17 Oct 2026, 2:00 PM to 6:00 PM',
    fee_minor: 750000, fee_label: '$7,500.00', deposit: null, is_charged: false, charged_label: null, status: 'pending', status_label: 'Pending',
    decidable: true, icon: 'clubhouse',
}

const open = async ({ waiting = false, rows = [ROW], filter = '' } = {}) => {
    resetInertia({ auth: { user: { id: 91106, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/amenities/bookings')
    const view = await mount(Bookings, {
        sourceBadge: { source: 'resident', simulated: false },
        estate: { name: 'Phoenix Park' },
        amenities: ['Clubhouse', 'Gazebo'],
        filter,
        waiting,
        pending: 2,
        rows,
        table: { q: '', total: rows.length, page: 1, per: 20, last_page: 1, sort: 'when', dir: 'asc' },
        canUpdate: true,
        canCreate: true,
        canCharge: true,
        chargeReason: '',
        canViewVendors: false,
        reasons: {},
    })
    await flush(nextTick)

    const chip = (label) => view.all((el) => byClass('f-chip')(el) && textOf(el).trim().startsWith(label))[0]

    return { view, chip }
}

describe('the amber chip shows the bookings waiting (AME-2)', () => {
    it('is a link that keeps only the bookings waiting on a decision', async () => {
        const { view, chip } = await open()
        try {
            const amber = chip('2 pending approvals')
            assert.equal(amber.tag, 'a', 'a control, not text')
            assert.match(String(amber.attrs.href), /[?&]waiting=1\b/)
            assert.ok(!byClass('active')(amber), 'off until pressed')
            assert.doesNotMatch(String(chip('Gazebo').attrs.href), /waiting/, 'the amenity chips do not turn it on')
        } finally {
            view.unmount()
        }
    })

    it('when on, is pressed again to show every booking, and the amenity chips keep it', async () => {
        const { view, chip } = await open({ waiting: true })
        try {
            const amber = chip('2 pending approvals')
            assert.ok(byClass('active')(amber), 'drawn on')
            assert.doesNotMatch(String(amber.attrs.href), /waiting/, 'pressing it again turns it off')
            assert.match(String(chip('Gazebo').attrs.href), /amenity=Gazebo/)
            assert.match(String(chip('Gazebo').attrs.href), /[?&]waiting=1\b/, 'an amenity chip keeps it')
        } finally {
            view.unmount()
        }
    })

    it('says a view with nothing waiting in its own words', async () => {
        const { view } = await open({ waiting: true, rows: [], filter: 'Gazebo' })
        try {
            assert.match(textOf(view.root), /Nothing here is waiting on a decision/)
            assert.doesNotMatch(textOf(view.root), /Nothing is booked against this amenity/)
        } finally {
            view.unmount()
        }
    })
})
