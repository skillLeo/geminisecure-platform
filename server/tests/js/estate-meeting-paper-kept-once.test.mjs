/**
 * A meeting's issued paper says how long it is kept once — `Pages/Estate/Governance/Meeting.vue` (step 6 of the walk).
 *
 *   The AGM's page read "Issued Oct 9, 2026 2:36 AM JA. Kept until Oct 9, 2033. · kept until Oct 9, 2033" under its
 *   agenda: the server's status line (`Document::statusLine()`) already says how long a paper is kept, and the page
 *   added it again. The household ledger shows the line alone; the meeting's papers do the same now.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Meeting } = await import('../../resources/js/Pages/Estate/Governance/Meeting.vue')

const MEETING = {
    id: 1, title: 'AGM 2026', type: 'agm', type_label: 'Annual general meeting', stage: 'scheduled', stage_label: 'Scheduled',
    when: '10 Oct 2026, 10:00 AM', starts_local: '2026-10-10T10:00', earliest_local: '2026-10-31T10:00', duration_minutes: 120, description: '',
    held_as: 'in_person', venue: null, virtual_link: null, platform_name: null, recording_enabled: true, recording: null,
    audience: 'Whole estate', phase: null, phases: [], households_told: 441, counts_households: true, publish_refusal: null, move_label: null,
    acts: [], register: [], agenda_items: [], agenda: [], notice: null, notice_line: null, cancelled: null, postponed: null, archived: null,
    quorum: { state: 'pending', label: 'Counted at the meeting', basis: '25% of 441 eligible households', required: 111, present: 0, apologies: 0, absent: 0 },
    rsvps: null, has_minutes: false, can_minute: false, minutes: null,
}

const PAPER = { id: 8, title: 'Agenda · AGM 2026', status: 'ready', is_ready: true, status_line: 'Issued Oct 9, 2026 2:36 AM JA. Kept until Oct 9, 2033.', retain_until: 'Oct 9, 2033' }

globalThis.window.location ??= { search: '', pathname: '/governance/meetings/1' }

describe('a meeting\'s issued paper', () => {
    it('says how long it is kept once, in the server\'s words', async () => {
        resetInertia({ auth: { user: { id: 68611, name: 'Dahlia Reid', role_label: 'Community Super Admin' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/meetings/1')
        const view = await mount(Meeting, { estate: { name: 'Phoenix Park' }, meeting: MEETING, documents: [PAPER], canPublish: true, publishReason: '' })
        await flush(nextTick)

        const paper = textOf(view.one(byClass('mt-doc')))
        assert.match(paper, /Issued Oct 9, 2026 2:36 AM JA\. Kept until Oct 9, 2033\./)
        assert.equal((paper.match(/kept until/gi) ?? []).length, 1, `the paper says how long it is kept more than once: ${paper}`)
        view.unmount()
    })
})
