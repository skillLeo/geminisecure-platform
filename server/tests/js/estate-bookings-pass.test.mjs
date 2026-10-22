/**
 * Amenity bookings, after the M97 pass (`Pages/Estate/Facilities/Bookings.vue`).
 *
 *   Decline opens the console's modal, never a form inside the table under its row (M95 §1), with the reason's refusal
 *   under the field; a refused approval or charge is said in its open modal, not a toast and not a banner over the
 *   diary (Hassam, 12 Oct); a role that can
 *   never decide sees no approve and decline pair, a booking that can never be charged no Charge fee, and a role without
 *   the vendor list no Vendors tab (M96 level 3); every status badge carries an icon beside its word, and a declined
 *   booking reads Rejected in a red outline, not solid red and not amber (M96 §2; Hassam, 11 October).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Bookings } = await import('../../resources/js/Pages/Estate/Facilities/Bookings.vue')
const { toasts, resetToasts } = await import('../../resources/js/lib/toasts.js')

const WAITING = {
    id: 8, reference: 'BK-0008', amenity: 'Clubhouse', booked_by: 'Lot 12 · Marcia Bennett', when: 'Sat 17 Oct 2026, 2:00 PM to 6:00 PM',
    fee_minor: 750000, fee_label: '$7,500.00', deposit: null, is_charged: false, charged_label: null, status: 'pending', status_label: 'Pending',
    decidable: true, icon: 'clubhouse',
}

const DONE = { ...WAITING, id: 9, reference: 'BK-0009', status: 'confirmed', status_label: 'Confirmed', decidable: false }
const FREE = { ...DONE, id: 10, reference: 'BK-0010', fee_minor: 0, fee_label: null }
const REJECTED = { ...WAITING, id: 11, reference: 'BK-0011', status: 'declined', status_label: 'Rejected', decidable: false }

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 97003, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/amenities/bookings')
    const view = await mount(Bookings, {
        sourceBadge: { source: 'resident', simulated: false },
        estate: { name: 'Phoenix Park' },
        amenities: [{ key: 'clubhouse', label: 'Clubhouse' }],
        filter: '',
        pending: 1,
        rows: [WAITING, DONE, FREE, REJECTED],
        table: { q: '', total: 4, page: 1, per: 20, last_page: 1, sort: 'when', dir: 'asc' },
        canUpdate: true,
        canCreate: true,
        canCharge: true,
        chargeReason: 'Charging a fee needs Dues and ledger access.',
        canViewVendors: false,
        reasons: { vendors: 'The vendor list needs Facilities vendors access.' },
        ...props,
    })
    await flush(nextTick)

    return view
}

const byLabel = (view, label) => view.one((el) => el.tag === 'button' && el.attrs?.['aria-label'] === label)
const buttons = (view, words) => view.all((el) => el.tag === 'button' && textOf(el).trim() === words)
const absent = (el, message) => assert.ok(el === null || el === undefined, message)

afterEach(() => resetToasts())

describe('Amenity bookings, after the M97 pass', () => {
    it('opens Decline in the console\'s modal, naming the booking, and posts the reason only when given', async () => {
        const view = await open()
        try {
            await view.click(byLabel(view, 'Decline booking BK-0008'))

            absent(view.one(byClass('bk-panel')), 'no form inside the table')
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Decline BK-0008?')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Say why, in the words the household will be given.')

            await view.type(view.one((el) => el.attrs?.id === 'decline-reason'), 'The Clubhouse is closed that weekend for painting.')
            absent(view.one(byClass('cd-why')), 'nothing stands in the way once there is a reason')
            await view.click(view.one(byClass('cd-confirm')))
            await nextTick()

            const post = visits.at(-1)
            assert.equal(post.url, '/facilities/amenities/bookings/8/decline')
            assert.equal(post.data.reason, 'The Clubhouse is closed that weekend for painting.')
        } finally {
            view.unmount()
        }
    })

    it('keeps a refused reason under its field, inside the modal', async () => {
        const view = await open()
        try {
            await view.click(byLabel(view, 'Decline booking BK-0008'))
            await view.type(view.one((el) => el.attrs?.id === 'decline-reason'), 'x')
            await view.click(view.one(byClass('cd-confirm')))
            visits.at(-1).options.onError({ reason: 'Say a little more, so the household knows what to do next.' })
            await nextTick()

            const error = view.one(byClass('bk-error'))
            assert.equal(textOf(error), 'Say a little more, so the household knows what to do next.')
            assert.ok(error.parent.children.some((el) => el.attrs?.id === 'decline-reason'), 'under the reason field')
        } finally {
            view.unmount()
        }
    })

    // Hassam, 12 Oct: a refusal of an open dialog is said inside it, the dialog staying open; never a toast behind it.
    it('says a refused approval inside its open modal, never as a toast or a banner over the diary', async () => {
        const view = await open()
        try {
            await view.click(byLabel(view, 'Approve booking BK-0008'))
            await view.click(view.one(byClass('cd-confirm')))
            visits.at(-1).options.onError({ booking: 'BK-0008 was decided by somebody else a moment ago.' })
            await nextTick()

            absent(view.one(byClass('bk-refusal')), 'no banner')
            assert.ok(view.one(byClass('cd-panel')) !== null, 'the modal is still open')
            assert.equal(textOf(view.one(byClass('cd-error'))).trim(), 'BK-0008 was decided by somebody else a moment ago.')
            assert.ok(!toasts.some((each) => each.tone === 'error'), 'no toast while the modal is open')
        } finally {
            view.unmount()
        }
    })

    it('offers no pair that refuses and no Charge fee that cannot be pressed', async () => {
        const reader = await open({ canUpdate: false, canCharge: false })
        try {
            absent(byLabel(reader, 'Approve booking BK-0008'), 'no approve for a role that can never decide')
            absent(byLabel(reader, 'Decline booking BK-0008'), 'no decline either')
            assert.equal(buttons(reader, 'Charge fee').length, 0, 'no Charge fee for a role that never charges')
            assert.equal(reader.all((el) => el.tag === 'button' && el.attrs?.disabled !== undefined).length, 0, 'nothing greyed that never works')
        } finally {
            reader.unmount()
        }

        const office = await open()
        try {
            assert.equal(buttons(office, 'Charge fee').length, 1, 'only the confirmed booking with a fee')
            absent(office.one((el) => el.tag === 'button' && textOf(el).trim() === 'Vendors'), 'no Vendors tab without the vendor list')
        } finally {
            office.unmount()
        }
    })

    it('draws every status with an icon beside its word, and a declined booking as Rejected in a red outline', async () => {
        const view = await open()
        try {
            const badges = view.all(byClass('bk-status'))
            assert.equal(badges.length, 4)
            for (const badge of badges) {
                assert.ok(badge.children.some((el) => el.tag === 'svg'), `${textOf(badge)} has its icon`)
            }
            const rejected = badges.find((badge) => textOf(badge) === 'Rejected')
            const style = String(rejected.attrs.style ?? '')
            assert.equal(/inset 0 0 0 1px var\(--red-700\)/.test(style), true, 'a red outline')
            assert.equal(/background:var\(--red-|amber/.test(style), false, 'no red fill, no amber')
        } finally {
            view.unmount()
        }
    })
})
