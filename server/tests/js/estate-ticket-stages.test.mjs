/**
 * Acknowledge and Start work, on the ticket (FAC-10, register DR-082).
 *
 *   `Pages/Estate/Facilities/Ticket.vue`: the spine has six stages and the office could stamp four. Now a ticket the
 *   server says can take them offers Acknowledge and Start work, each one press, each posting to its own act; a ticket
 *   past them does not offer them; a viewer who cannot change tickets sees both off with the reason; and a refusal is
 *   said at the top of the page, as a refused priority is.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Ticket } = await import('../../resources/js/Pages/Estate/Facilities/Ticket.vue')

const TICKET = {
    id: 43, number: 1043, heading: 'Ticket #1043', summary_title: 'Standpipe hissing', title: 'Standpipe hissing', location: 'Lot 47',
    category: 'Plumbing', description: null, priority: 'medium', priority_label: 'Medium', status: 'submitted', status_label: 'Submitted',
    meta: 'Submitted by Marcia Bennett · Lot 47', access: null, reported_at: '11 Oct 2026, 9:00 AM', age: '3 hours', is_overdue: false,
    sla_due: '14 Oct 2026, 9:00 AM', resolution: null, closed_at: null, is_closed: false, can_acknowledge: true, can_start: true,
}

const open = async ({ ticket = {}, canUpdate = true, errors = {} } = {}) => {
    resetInertia({ auth: { user: { id: 91104, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors, flash: {} }, '/facilities/maintenance/1043')
    const view = await mount(Ticket, {
        sourceBadge: { source: 'resident', simulated: false },
        estateNow: '12:00 PM, Sun 11 Oct',
        estate: { name: 'Phoenix Park' },
        ticket: { ...TICKET, ...ticket },
        timeline: [],
        vendor: null,
        history: [],
        vendors: [{ id: 1, name: 'Island Electric Services' }],
        priorities: { high: 'High', medium: 'Medium', low: 'Low' },
        canUpdate,
        blockedReason: 'Changing a ticket is not part of your role.',
        reasons: { message: null },
        photos: [],
    })
    await flush(nextTick)

    const button = (label) => view.one((el) => el.tag === 'button' && textOf(el).trim() === label)

    return { view, button }
}

describe('Acknowledge and Start work (FAC-10)', () => {
    it('offers both on a ticket that can take them, each posting to its own act', async () => {
        const { view, button } = await open()
        try {
            assert.ok(button('Acknowledge'), 'Acknowledge is offered')
            assert.ok(button('Start work'), 'Start work is offered')
            assert.ok(!button('Acknowledge').attrs.disabled && !button('Start work').attrs.disabled, 'both can be pressed')

            visits.length = 0
            await view.click(button('Acknowledge'))
            await view.click(button('Start work'))

            assert.deepEqual(
                visits.filter((v) => v.method === 'post').map((v) => v.url),
                ['/facilities/maintenance/1043/acknowledge', '/facilities/maintenance/1043/start']
            )
        } finally {
            view.unmount()
        }
    })

    it('does not offer them on a ticket past them', async () => {
        const { view, button } = await open({ ticket: { status: 'in_progress', status_label: 'In progress', can_acknowledge: false, can_start: false } })
        try {
            assert.equal(button('Acknowledge'), null)
            assert.equal(button('Start work'), null)
        } finally {
            view.unmount()
        }
    })

    // M96 level 3 (M97 pass): a viewer who can never change tickets is offered neither, not two greyed buttons.
    it('offers neither to a viewer who cannot change tickets', async () => {
        const { view, button } = await open({ canUpdate: false })
        try {
            for (const label of ['Acknowledge', 'Start work']) {
                assert.ok(button(label) === null, `no ${label}`)
            }
        } finally {
            view.unmount()
        }
    })

    // M95 §2 (M97 pass): a refusal is a toast that stays until closed, not a banner at the top of the page.
    it('says a refusal as a toast', async () => {
        const { toasts, resetToasts } = await import('../../resources/js/lib/toasts.js')
        const { view, button } = await open()
        try {
            await view.click(button('Acknowledge'))
            visits.at(-1).options.onError({ stage: 'Ticket #1043 is already acknowledged.' })
            await nextTick()

            assert.ok(toasts.some((each) => each.tone === 'error' && each.text === 'Ticket #1043 is already acknowledged.'))
        } finally {
            view.unmount()
            resetToasts()
        }
    })
})
