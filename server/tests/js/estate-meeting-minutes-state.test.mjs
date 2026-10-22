/**
 * The minutes say one thing about where they stand — `Pages/Estate/Governance/Meeting.vue` (M68 §2.6, GV-5).
 *
 *   The minutes line read "Not yet adopted · … · published to the households Sep 28" on the same row: published and,
 *   apparently, not. Hassam: pick one. Published minutes say when they were published; an adoption is said once it has
 *   happened, and "Not yet adopted" is never printed beside a publication or anywhere else.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Meeting } = await import('../../resources/js/Pages/Estate/Governance/Meeting.vue')

const MEETING = {
    id: 3, title: 'Gate contract renewal', type: 'committee', type_label: 'Committee meeting', stage: 'minutes_published', stage_label: 'Minutes published',
    when: '27 Sep 2026, 6:00 PM', starts_local: '2026-09-27T18:00', earliest_local: '2026-09-20T18:00', duration_minutes: 90, description: '',
    held_as: 'in_person', venue: 'Clubhouse', virtual_link: null, platform_name: null, recording_enabled: false, recording: null,
    audience: 'Committee members', phase: null, phases: [], households_told: 0, counts_households: false, publish_refusal: null, move_label: null,
    acts: [], register: [], agenda_items: [], agenda: [], notice: null, notice_line: null, cancelled: null, postponed: null, archived: null,
    quorum: { state: 'done', label: 'Quorum met', basis: 'members', required: 2, present: 5, apologies: 0, absent: 2 },
    rsvps: null, has_minutes: true, can_minute: false,
}

const MINUTES = { body: 'The committee met and renewed the gate contract.', recorded_by: 'Kayla Morrison', adopted: 'Not yet adopted', published: 'Sep 28, 2026 · 9:00 AM JA' }

globalThis.window.location ??= { search: '', pathname: '/governance/meetings/3' }

const open = async (minutes) => {
    resetInertia({ auth: { user: { id: 68601, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/meetings/3')
    const view = await mount(Meeting, { estate: { name: 'Phoenix Park' }, meeting: { ...MEETING, minutes }, documents: [], canPublish: true, publishReason: '' })
    await flush(nextTick)

    return view.one(byClass('mt-minutes-state'))
}

describe('the minutes line (GV-5)', () => {
    it('published and not adopted: says it was published, and nothing about adoption', async () => {
        const line = await open(MINUTES)

        assert.ok(line, 'the minutes line')
        assert.match(textOf(line), /Published to the households Sep 28, 2026 · 9:00 AM JA/)
        assert.doesNotMatch(textOf(line), /Not yet adopted/)
        assert.match(textOf(line), /recorded by Kayla Morrison/)
    })

    it('published and adopted: says both, each as it happened', async () => {
        const line = await open({ ...MINUTES, adopted: 'Adopted Oct 5, 2026 by Kayla Morrison' })

        assert.match(textOf(line), /Published to the households Sep 28, 2026 · 9:00 AM JA/)
        assert.match(textOf(line), /adopted Oct 5, 2026 by Kayla Morrison/i)
    })

    it('not yet published: says that, and nothing about adoption', async () => {
        const line = await open({ ...MINUTES, published: null })

        assert.match(textOf(line), /Not yet published to the households/)
        assert.doesNotMatch(textOf(line), /Not yet adopted/)
    })
})
