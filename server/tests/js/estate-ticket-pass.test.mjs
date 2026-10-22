/**
 * A work order, after the M97 pass (`Pages/Estate/Facilities/Ticket.vue`).
 *
 *   Mark completed, Reopen, Assign, Message resident and the priority each open the console's modal, never a panel
 *   under the card (M95 §1), each greyed button saying why on the screen; the way back to the queue is in words (M95
 *   §3); a refused priority is said in its open modal (Hassam, 12 Oct; it was a toast, M95 §2); an office work order
 *   has no Message resident, a role that can never act
 *   has no action stack, and Assign waits with its reason on the card while no supplier is registered (M96).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Ticket } = await import('../../resources/js/Pages/Estate/Facilities/Ticket.vue')
const { toasts, resetToasts } = await import('../../resources/js/lib/toasts.js')

const TICKET = {
    id: 43, number: 1043, heading: 'Ticket #1043', summary_title: 'Standpipe hissing', title: 'Standpipe hissing', location: 'Lot 47',
    category: 'Plumbing', description: null, priority: 'medium', priority_label: 'Medium', status: 'submitted', status_label: 'Submitted',
    meta: 'Submitted by Marcia Bennett · Lot 47', access: null, reported_at: '11 Oct 2026, 9:00 AM', age: '3 hours', is_overdue: false,
    sla_due: '14 Oct 2026, 9:00 AM', resolution: null, closed_at: null, is_closed: false, can_acknowledge: false, can_start: false,
}

const open = async ({ canUpdate = true, vendors = [{ id: 1, name: 'Island Electric Services' }], message = null } = {}) => {
    resetInertia({ auth: { user: { id: 97006, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance/1043')
    const view = await mount(Ticket, {
        sourceBadge: { source: 'resident', simulated: false },
        estateNow: '12:00 PM, Sun 11 Oct',
        estate: { name: 'Phoenix Park' },
        ticket: TICKET,
        timeline: [],
        vendor: null,
        history: [],
        vendors,
        priorities: { high: 'High', medium: 'Medium', low: 'Low' },
        canUpdate,
        blockedReason: 'Changing a ticket is not part of your role.',
        reasons: { message },
        photos: [],
    })
    await flush(nextTick)

    return view
}

const button = (view, label) => view.all((el) => el.tag === 'button' && textOf(el).trim() === label)[0] ?? null

afterEach(() => resetToasts())

describe('A work order, after the M97 pass', () => {
    it('opens Mark completed in the console\'s modal, saying why it waits until something is written', async () => {
        const view = await open()
        try {
            await view.click(button(view, 'Mark completed'))

            assert.ok(view.one(byClass('act-panel')) === null, 'no panel under the card')
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Mark Ticket #1043 completed?')
            assert.equal(textOf(view.one(byClass('cd-why'))), 'Say what was done.')

            await view.type(view.one((el) => el.attrs?.id === 'act-resolution'), 'Valve replaced.')
            await view.click(view.one(byClass('cd-confirm')))
            await nextTick()
            assert.equal(visits.at(-1).url, '/facilities/maintenance/1043/resolve')
        } finally {
            view.unmount()
        }
    })

    // Hassam, 12 Oct: a refusal of an open dialog is said inside it, the dialog staying open; never a toast behind it.
    it('asks for a priority in the modal and says a refusal inside it', async () => {
        const view = await open()
        try {
            await view.click(view.one((el) => el.tag === 'button' && byClass('priority-dot')(el)))
            assert.equal(textOf(view.one(byClass('cd-title'))), 'Change the priority of Ticket #1043?')

            await view.click(view.one((el) => el.attrs?.role === 'radio' && textOf(el) === 'High'))
            await view.click(view.one(byClass('cd-confirm')))
            visits.at(-1).options.onError({ priority: 'Ticket #1043 was closed a moment ago.' })
            await nextTick()

            assert.ok(view.one(byClass('ticket-refusal')) === null, 'no banner')
            assert.ok(view.one(byClass('cd-panel')) !== null, 'the modal is still open')
            assert.equal(textOf(view.one(byClass('cd-error'))).trim(), 'Ticket #1043 was closed a moment ago.')
            assert.ok(!toasts.some((each) => each.tone === 'error'), 'no toast while the modal is open')
        } finally {
            view.unmount()
        }
    })

    it('goes back to the queue in words', async () => {
        const view = await open()
        try {
            const back = view.one((el) => el.tag === 'a' && textOf(el) === 'Back to maintenance')
            assert.ok(back)
            assert.equal(back.attrs.href, '/facilities/maintenance')
        } finally {
            view.unmount()
        }
    })

    it('offers no control that can never work, and says on the card why Assign waits', async () => {
        const office = await open({ message: 'Only a request a resident reported has a resident to message.', vendors: [] })
        try {
            assert.ok(button(office, 'Message resident') === null, 'no Message resident on an office work order')
            assert.equal(button(office, 'Assign vendor').attrs.disabled, true, 'Assign waits for a supplier')
            assert.match(textOf(office.one(byClass('tk-why'))), /The supplier register holds no active vendor/)
        } finally {
            office.unmount()
        }

        const reader = await open({ canUpdate: false })
        try {
            assert.ok(reader.one(byClass('action-stack')) === null, 'no action stack for a role that can never act')
            assert.ok(reader.one((el) => el.tag === 'button' && byClass('priority-dot')(el)) === null, 'the priority is a fact')
        } finally {
            reader.unmount()
        }
    })
})
