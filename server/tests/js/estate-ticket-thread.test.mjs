/**
 * The ticket page draws its own thread — `Pages/Estate/Facilities/Ticket.vue` (M68 §2.1, FAC-7).
 *
 *   The server sends every line of a ticket's history (reported, assigned, reassigned, a message, completed,
 *   reopened), each with what happened, the words, who and when. The page drew only the six-stage strip, so a message
 *   sent to the resident was accepted and appeared nowhere. It now draws the thread, oldest first; every stage shows
 *   who and when, with its note beneath it; the reopen reason and the resolver's name and time appear.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Ticket } = await import('../../resources/js/Pages/Estate/Facilities/Ticket.vue')

const TICKET = {
    id: 41, number: 1041, heading: 'Ticket #1041', summary_title: 'Gate light out Lot 47', title: 'Gate light out', location: 'Lot 47',
    category: 'Electrical', description: 'The light by the gate is out.', priority: 'medium', priority_label: 'Medium', status: 'assigned',
    status_label: 'Assigned', meta: 'Submitted by Marcia Bennett · Lot 47 · Oct 8, 9:00 AM JA · Category: Electrical', access: null,
    reported_at: 'Oct 8, 9:00 AM JA', age: '2 days', is_overdue: false, sla_due: 'Oct 11, 9:00 AM JA', resolution: null, closed_at: null,
    is_closed: false,
}

const TIMELINE = [
    { key: 'submitted', label: 'Submitted', state: 'done', line: 'Oct 8, 9:00 AM JA · Marcia Bennett', note: null },
    { key: 'acknowledged', label: 'Acknowledged', state: 'done', line: 'Oct 8, 9:30 AM JA · Kayla Morrison', note: null },
    { key: 'assigned', label: 'Assigned to vendor', state: 'done', line: 'Oct 8, 10:00 AM JA · Island Electric Services', note: null },
    { key: 'in_progress', label: 'In progress', state: 'pending', line: null, note: null },
    { key: 'completed', label: 'Completed', state: 'done', line: 'Oct 9, 4:00 PM JA · Trevor Wright', note: 'Replaced the bulb and the photocell.' },
    { key: 'verified', label: 'Verified by resident', state: 'pending', line: null, note: null },
]

// What `Maintenance::ticketBoard()` sends: each line composed on the server (what, words, who, when), oldest first.
const HISTORY = [
    { id: 1, event: 'reported', kind: 'resident', what: 'Reported', words: null, who: 'Marcia Bennett', when: 'Oct 8, 9:00 AM JA' },
    { id: 2, event: 'acknowledged', kind: 'staff', what: 'Acknowledged', words: null, who: 'Kayla Morrison', when: 'Oct 8, 9:30 AM JA' },
    { id: 3, event: 'assigned', kind: 'vendor', what: 'Assigned to Island Electric Services', words: null, who: null, when: 'Oct 8, 10:00 AM JA' },
    { id: 4, event: 'reassigned', kind: 'vendor', what: 'Reassigned from Island Electric Services to AquaTech Pool Services', words: null, who: null, when: 'Oct 9, 1:10 AM JA' },
    { id: 5, event: 'note', kind: 'staff', what: 'Message to the household', words: 'The electrician comes on Monday morning.', who: 'Kayla Morrison', when: 'Oct 9, 2:05 PM JA' },
    { id: 6, event: 'resolved', kind: 'staff', what: 'Marked completed', words: 'Replaced the bulb and the photocell.', who: 'Trevor Wright', when: 'Oct 9, 4:00 PM JA' },
    { id: 7, event: 'reopened', kind: 'resident', what: 'Reopened', words: 'The light went out again the same night.', who: 'Marcia Bennett', when: 'Oct 10, 8:00 AM JA' },
]

const open = async (props = {}) => {
    resetInertia({ auth: { user: { id: 68101, name: 'Trevor Wright', role_label: 'Property Manager' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/facilities/maintenance/1041')
    const view = await mount(Ticket, {
        sourceBadge: { source: 'resident', simulated: false },
        estateNow: '9:00 AM, Sat 10 Oct',
        estate: { name: 'Phoenix Park' },
        ticket: TICKET,
        timeline: TIMELINE,
        vendor: { id: 2, name: 'AquaTech Pool Services', initials: 'AP', detail: '', technician: null, phone: null },
        history: HISTORY,
        vendors: [{ id: 1, name: 'Island Electric Services' }, { id: 2, name: 'AquaTech Pool Services' }],
        priorities: { high: 'High', medium: 'Medium', low: 'Low' },
        canUpdate: true,
        blockedReason: '',
        reasons: {},
        photos: [],
        ...props,
    })
    await flush(nextTick)

    const entries = () => view.all(byClass('tk-entry'))
    const stage = (label) => view.all(byClass('tl2-row')).find((row) => textOf(findAll(row, byClass('tt1'))[0] ?? row).trim() === label)

    return { view, entries, stage }
}

describe('the ticket draws its thread (FAC-7)', () => {
    it('draws every line of the history, oldest first, with what happened, the words, who and when', async () => {
        const { entries } = await open()

        assert.equal(entries().length, 7, 'one entry per line of the history')
        assert.match(textOf(entries()[0]), /Reported/)
        assert.match(textOf(entries()[3]), /Reassigned from Island Electric Services to AquaTech Pool Services/)

        const message = textOf(entries()[4])
        assert.match(message, /Message to the household/)
        assert.match(message, /The electrician comes on Monday morning\./)
        assert.match(message, /Kayla Morrison/)
        assert.match(message, /Oct 9, 2:05 PM JA/)
    })

    it('the reopen reason appears, with who reopened it and when', async () => {
        const { entries } = await open()
        const reopened = textOf(entries()[6])

        assert.match(reopened, /Reopened/)
        assert.match(reopened, /The light went out again the same night\./)
        assert.match(reopened, /Marcia Bennett/)
        assert.match(reopened, /Oct 10, 8:00 AM JA/)
    })

    it('every stage shows who and when, and a stage with a note shows its note as well', async () => {
        const { stage } = await open()

        const completed = textOf(stage('Completed'))
        assert.match(completed, /Oct 9, 4:00 PM JA · Trevor Wright/, "the resolver's name and time")
        assert.match(completed, /Replaced the bulb and the photocell\./)

        assert.match(textOf(stage('Submitted')), /Oct 8, 9:00 AM JA · Marcia Bennett/, 'who reported it, and when')
    })

    it('a ticket with no history draws no thread', async () => {
        const { entries } = await open({ history: [] })

        assert.equal(entries().length, 0)
    })
})
