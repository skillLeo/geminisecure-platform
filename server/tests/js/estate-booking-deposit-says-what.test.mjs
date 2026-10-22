/**
 * The booking's deposit card: a badge for its state, and only the buttons that can be pressed (M94 §1.2, M96 §1;
 * Hassam 11 Oct: "none of the three buttons on /facilities/amenities/bookings/4 work", and they said nothing on hover).
 *
 *   `Pages/Estate/Facilities/Booking.vue`: the deposit's state is a badge at the top, with when. Level 3, done or never
 *   this role's: a settled deposit, a role that cannot record deposits, a forfeit the role can never approve, a receipt
 *   already recorded: no button. Level 2, blocked for now: refund and forfeit while the deposit is awaited, greyed,
 *   the reason on the card. Level 1: a normal button.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Booking } = await import('../../resources/js/Pages/Estate/Facilities/Booking.vue')

const BOOKING = {
    id: 4, reference: 'BK-2610-0004', amenity: 'Clubhouse', resident: 'Marcia Bennett', unit: 'Lot 12', when: 'Sat, Oct 3, 2026 · 2:00 PM–6:00 PM JA',
    guests: 40, status: 'confirmed', status_label: 'Confirmed', fee: '$7,500.00', fee_charged: true, cancellation: '48 hours', decision: null, notes: null,
}

const HELD = { state: 'held', settled: false, badge: { label: 'Deposit held · 1 Oct', tone: 'in_progress' } }

const open = async ({ deposit, canUpdate = true, canApprove = true, booking = {} }) => {
    resetInertia({ auth: { user: { id: 91114, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/amenities/bookings/4')
    const view = await mount(Booking, {
        sourceBadge: { source: 'resident', simulated: false },
        estate: { name: 'Phoenix Park' },
        booking: { ...BOOKING, ...booking },
        deposit: { amount: '$5,000.00', label: '', timeline: [], ...deposit },
        canUpdate,
        canApprove,
        updateReason: 'Recording a deposit is not part of your role.',
        approveReason: 'Keeping a deposit is not part of your role.',
    })
    await flush(nextTick)

    const button = (label) => view.one((el) => el.tag === 'button' && textOf(el).includes(label))
    const badge = () => view.one((el) => byClass('bk-badge')(el))

    return { view, button, badge }
}

const LABELS = ['Record deposit received', 'Refund deposit', 'Forfeit deposit']

describe('the deposit card: a badge, and only buttons that can be pressed (M94 §1.2, M96)', () => {
    it('shows a refunded deposit as a badge with when, and no buttons (level 3, done)', async () => {
        const { view, button, badge } = await open({
            deposit: { state: 'refunded', settled: true, badge: { label: 'Deposit refunded · 6 Oct', tone: 'completed' } },
            booking: { status: 'completed', status_label: 'Completed' },
        })
        try {
            assert.equal(textOf(badge()).trim(), 'Deposit refunded · 6 Oct')
            assert.ok(byClass('bk-completed')(badge()), 'in the completed colour')
            assert.ok(badge().children.some((el) => el.tag === 'svg'), 'with an icon, never colour alone')
            for (const label of LABELS) {
                assert.equal(button(label), null, `no ${label} button`)
            }
        } finally {
            view.unmount()
        }
    })

    it('shows a role that can never record deposits the badge and no buttons (level 3, never theirs)', async () => {
        const { view, button, badge } = await open({ deposit: HELD, canUpdate: false, canApprove: false })
        try {
            assert.equal(textOf(badge()).trim(), 'Deposit held · 1 Oct')
            for (const label of LABELS) {
                assert.equal(button(label), null, `no ${label} button`)
            }
        } finally {
            view.unmount()
        }
    })

    it('offers the receipt while the deposit is awaited, and greys refund and forfeit with the reason on the card (level 2)', async () => {
        const { view, button, badge } = await open({ deposit: { state: 'awaiting', settled: false, badge: { label: 'Deposit pending', tone: 'pending' } } })
        try {
            assert.equal(textOf(badge()).trim(), 'Deposit pending')
            assert.ok(!button('Record deposit received').attrs.disabled, 'the next step is a normal button')
            assert.equal(button('Refund deposit').attrs.disabled, true)
            assert.equal(button('Forfeit deposit').attrs.disabled, true)
            assert.match(view.text(), /Refund or forfeit the deposit once it is recorded as received\./, 'the reason is on the card')
        } finally {
            view.unmount()
        }
    })

    it('takes away the receipt once it is recorded, and offers refund and forfeit (level 1)', async () => {
        const { view, button } = await open({ deposit: HELD })
        try {
            assert.equal(button('Record deposit received'), null, 'a step already taken is not offered again')
            assert.ok(!button('Refund deposit').attrs.disabled)
            assert.ok(!button('Forfeit deposit').attrs.disabled)
        } finally {
            view.unmount()
        }
    })

    it('never shows Forfeit to a role that can never approve one (level 3), and still offers Refund', async () => {
        const { view, button } = await open({ deposit: HELD, canApprove: false })
        try {
            assert.equal(button('Forfeit deposit'), null)
            assert.ok(!button('Refund deposit').attrs.disabled)
        } finally {
            view.unmount()
        }
    })
})
