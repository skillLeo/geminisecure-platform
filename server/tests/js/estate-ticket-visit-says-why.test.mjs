/**
 * The vendor's visit says why, under the field, and a refusal never closes the panel (FAC-11, register DR-083).
 *
 *   `Pages/Estate/Facilities/Ticket.vue`: an ETA that ended before it started, or a window the server refused as
 *   already passed, closed the Assign panel and saved nothing, with no word, the button live. Now a window that ends
 *   before it starts turns Assign off with the reason under "ETA to", and a refusal keeps the panel and what was typed,
 *   its words under the field they are about. A visit that saves still closes the panel, as before.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Ticket } = await import('../../resources/js/Pages/Estate/Facilities/Ticket.vue')

const TICKET = {
    id: 42, number: 1042, heading: 'Ticket #1042', summary_title: 'Leaking standpipe', title: 'Leaking standpipe', location: 'Phase 1 entrance',
    category: 'Plumbing', description: null, priority: 'medium', priority_label: 'Medium', status: 'submitted', status_label: 'Submitted',
    meta: 'Raised by the office', access: null, reported_at: '9 Oct 2026, 9:00 AM', age: '2 days', is_overdue: false,
    sla_due: '12 Oct 2026, 9:00 AM', resolution: null, closed_at: null, is_closed: false,
}

const open = async () => {
    resetInertia({ auth: { user: { id: 91102, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance/1042')
    const view = await mount(Ticket, {
        sourceBadge: { source: 'office', simulated: false },
        estateNow: '9:00 AM, Sun 11 Oct',
        estate: { name: 'Phoenix Park' },
        ticket: TICKET,
        timeline: [],
        vendor: null,
        history: [],
        vendors: [{ id: 1, name: 'Island Electric Services' }, { id: 2, name: 'AquaTech Pool Services' }],
        priorities: { high: 'High', medium: 'Medium', low: 'Low' },
        canUpdate: true,
        blockedReason: '',
        reasons: {},
        photos: [],
    })
    await flush(nextTick)

    await view.click(view.all((el) => el.tag === 'button' && /Assign vendor/.test(textOf(el)))[0])

    const select = () => view.one((el) => el.tag === 'select' && el.attrs?.id === 'act-vendor')
    const field = (id) => view.one((el) => el.tag === 'input' && el.attrs?.id === id)
    // Assign is the console's modal's own button since the M97 pass (M95 §1), and the form is the modal.
    const submit = () => view.one((el) => el.tag === 'button' && byClass('cd-confirm')(el))
    const panel = () => view.one((el) => el.attrs?.role === 'dialog')

    for (const option of select().options) {
        option.selected = String(option.value) === '1'
    }
    fire(select(), 'change')
    await nextTick()

    return { view, field, submit, panel }
}

describe('the vendor\'s visit says why (FAC-11)', () => {
    it('turns Assign off for a visit that ends before it starts, with the reason under "ETA to"', async () => {
        const { view, field, submit } = await open()
        try {
            await view.type(field('act-eta-from'), '2026-10-12T11:00')
            await view.type(field('act-eta-to'), '2026-10-12T09:00')

            assert.equal(submit().attrs.disabled, true, 'Assign is off')
            assert.equal(submit().attrs.title, 'The visit must end after it starts.', 'and says why in one line')
            assert.match(textOf(field('act-eta-to').parent), /The visit must end after it starts\./, 'the reason sits under the field')

            await view.type(field('act-eta-to'), '2026-10-12T13:00')

            assert.ok(!submit().attrs.disabled, 'a window that ends after it starts can be sent')
        } finally {
            view.unmount()
        }
    })

    it('keeps the panel and what was typed when the server refuses, its words under the field they are about', async () => {
        const { view, field, submit, panel } = await open()
        try {
            await view.type(field('act-technician'), 'Owen Grant')
            await view.type(field('act-eta-from'), '2026-10-10T09:00')
            await view.type(field('act-eta-to'), '2026-10-10T11:00')

            visits.length = 0
            await view.click(submit())
            await nextTick()

            const post = visits.find((v) => v.method === 'post')
            assert.ok(post, 'the visit is posted')
            // Inertia's post keeps the page unless told otherwise, as every save in the console does (M98 audit).
            const keep = post.options.preserveState
            assert.equal(keep === undefined || keep === true, true, 'a refusal keeps the panel and the fields, whatever it names')

            post.options.onError({ eta_ends_at: 'That visit window has already passed at the estate. Choose a time still to come.' })
            await nextTick()

            assert.ok(panel(), 'the panel is still open')
            assert.equal(field('act-technician').value, 'Owen Grant', 'what was typed is still there')
            assert.match(textOf(field('act-eta-to').parent), /already passed at the estate/, 'the refusal sits under its field')
            assert.ok(submit(), 'and Assign is there to press again')
        } finally {
            view.unmount()
        }
    })
})
