/**
 * A booking, after the M97 pass (`Pages/Estate/Facilities/Booking.vue`).
 *
 *   Record deposit received, Refund deposit and Forfeit deposit each open the console's modal, never a panel under the
 *   card (M95 §1); the service's refusal is said inside that dialog and never as a banner on the page, a field's under
 *   its field (M95 §2); the way back to the diary is in words, top left (M95 §3); the status badge carries its icon and
 *   a declined booking reads Rejected in a red outline, not solid red and not amber (M96; Hassam, 11 October).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Booking } = await import('../../resources/js/Pages/Estate/Facilities/Booking.vue')
const { resetToasts } = await import('../../resources/js/lib/toasts.js')

const BOOKING = {
    id: 4, reference: 'BK-2610-0004', amenity: 'Clubhouse', resident: 'Marcia Bennett', unit: 'Lot 12', when: 'Sat, Oct 3, 2026 · 2:00 PM–6:00 PM JA',
    guests: 40, status: 'confirmed', status_label: 'Confirmed', fee: '$7,500.00', fee_charged: true, cancellation: '48 hours', decision: null, notes: null,
}

const open = async ({ deposit = {}, booking = {} } = {}) => {
    resetInertia({ auth: { user: { id: 97004, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/amenities/bookings/4')
    const view = await mount(Booking, {
        sourceBadge: { source: 'resident', simulated: false },
        estate: { name: 'Phoenix Park' },
        booking: { ...BOOKING, ...booking },
        deposit: { amount: '$5,000.00', label: '', timeline: [], state: 'held', settled: false, badge: { label: 'Deposit held · 1 Oct', tone: 'in_progress' }, ...deposit },
        canUpdate: true,
        canApprove: true,
        updateReason: 'Recording a deposit is not part of your role.',
        approveReason: 'Keeping a deposit is not part of your role.',
    })
    await flush(nextTick)

    return view
}

const button = (view, words) => view.all((el) => el.tag === 'button' && textOf(el).includes(words))[0] ?? null
const absent = (el, message) => assert.ok(el === null || el === undefined, message)

afterEach(() => resetToasts())

describe('A booking, after the M97 pass', () => {
    it('opens Refund deposit in the console\'s modal and posts only from it', async () => {
        const view = await open()
        try {
            await view.click(button(view, 'Refund deposit'))

            absent(view.one(byClass('act-panel')), 'no panel under the card')
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Refund the $5,000.00 deposit?')
            assert.equal(visits.length, 0, 'opening asks; it posts nothing')

            await view.click(view.one(byClass('cd-confirm')))
            await nextTick()
            assert.equal(visits.at(-1).url, '/facilities/amenities/bookings/4/deposit/refund')
        } finally {
            view.unmount()
        }
    })

    it('says the service\'s refusal inside the dialog, never as a banner on the page', async () => {
        const view = await open()
        try {
            await view.click(button(view, 'Refund deposit'))
            await view.click(view.one(byClass('cd-confirm')))
            visits.at(-1).options.onError({ deposit: 'This deposit was refunded by somebody else a moment ago.' })
            await nextTick()

            absent(view.one(byClass('bk-refusal')), 'no banner')
            assert.equal(textOf(view.one(byClass('cd-error'))), 'This deposit was refunded by somebody else a moment ago.')
        } finally {
            view.unmount()
        }
    })

    it('asks for the forfeit\'s reason in the modal, saying why it waits, with its refusal under the field', async () => {
        const view = await open()
        try {
            await view.click(button(view, 'Forfeit deposit'))

            assert.equal(textOf(view.one(byClass('cd-title'))), 'Keep the $5,000.00 deposit?')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Say why the estate is keeping it.')
            assert.doesNotMatch(String(view.one((el) => el.attrs?.id === 'act-forfeit-amount').attrs.placeholder ?? ''), /\d/, 'no amount as a placeholder')

            await view.type(view.one((el) => el.attrs?.id === 'act-forfeit-reason'), 'Two chairs broken.')
            await view.click(view.one(byClass('cd-confirm')))
            visits.at(-1).options.onError({ amount: 'Keep at most the $5,000.00 held.' })
            await nextTick()

            const error = view.one((el) => byClass('act-error')(el) && textOf(el) === 'Keep at most the $5,000.00 held.')
            assert.ok(error.parent.children.some((el) => el.attrs?.id === 'act-forfeit-amount'), 'under the amount field')
        } finally {
            view.unmount()
        }
    })

    it('goes back to the diary in words, top left', async () => {
        const view = await open()
        try {
            const back = view.one((el) => el.tag === 'a' && textOf(el) === 'Back to bookings')
            assert.ok(back, 'a back control in words')
            assert.equal(back.attrs.href, '/facilities/amenities/bookings')
        } finally {
            view.unmount()
        }
    })

    it('draws the status with its icon, and a declined booking as Rejected in a red outline', async () => {
        const view = await open({ booking: { status: 'declined', status_label: 'Rejected' }, deposit: { amount: null } })
        try {
            const badge = view.one(byClass('bk-status'))
            assert.equal(textOf(badge), 'Rejected')
            assert.ok(badge.children.some((el) => el.tag === 'svg'), 'with its icon')
            const style = String(badge.attrs.style ?? '')
            assert.equal(/inset 0 0 0 1px var\(--red-700\)/.test(style), true, 'a red outline')
            assert.equal(/background:var\(--red-|amber/.test(style), false, 'no red fill, no amber')
        } finally {
            view.unmount()
        }
    })
})
