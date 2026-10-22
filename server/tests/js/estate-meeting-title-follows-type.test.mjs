/**
 * The meeting title's example follows its type; nothing is typed in for the Secretary (GV-2, register DR-096; Hassam,
 * 12 Oct, batch 1 item 7).
 *
 *   `Pages/Estate/Governance/MeetingScheduler.vue`: the form opened titled for its default type, a value nobody chose.
 *   It opens with the title empty and the chosen type's name as the placeholder's example ("e.g. Committee meeting"),
 *   which follows the type; a title somebody typed is theirs whatever the type.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: MeetingScheduler } = await import('../../resources/js/Pages/Estate/Governance/MeetingScheduler.vue')

const PROPS = {
    estate: { name: 'Phoenix Park' },
    // As `Governance` sends them: the short label on the toggle, the full title the form gives the meeting.
    types: [
        { value: 'agm', label: 'AGM', eyebrow: 'ANNUAL GENERAL MEETING', title: 'Annual General Meeting' },
        { value: 'committee', label: 'Committee', eyebrow: 'COMMITTEE MEETING', title: 'Committee meeting' },
        { value: 'phase', label: 'Phase meeting', eyebrow: 'PHASE MEETING', title: 'Phase meeting' },
    ],
    phases: ['Phase 1', 'Phase 2'],
    defaults: { type: 'committee', title: 'Committee meeting', audience_scope: 'committee', audience_label: 'Whole estate', quorum_percent: 25, recording_enabled: false, recording_consent_notice: false },
    eligibleHouseholds: 443,
    committeeMembers: 7,
    notice: { days: { agm: 21, committee: 7, phase: 7 }, earliest: { agm: '2026-10-31', committee: '2026-10-17', phase: '2026-10-17' } },
    canSchedule: true,
    canPublish: true,
    blockedReason: '',
}

const open = async () => {
    resetInertia({ auth: { user: { id: 91109, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/meetings/new')
    const view = await mount(MeetingScheduler, PROPS)
    await flush(nextTick)

    const title = () => view.one((el) => el.attrs?.id === 'meeting-title')
    const choose = (label) => view.click(view.all((el) => byClass('seg-item')(el) && textOf(el).trim() === label)[0])

    return { view, title, choose }
}

describe('the title\'s example follows the type (GV-2, batch 1 item 7)', () => {
    it('opens with no title, and offers each type\'s name as the example as it is chosen', async () => {
        const { view, title, choose } = await open()
        try {
            assert.equal(title().value ?? '', '', 'nothing is typed in for the Secretary')
            assert.equal(title().attrs.placeholder, 'e.g. Committee meeting')

            await choose('AGM')
            assert.equal(title().attrs.placeholder, 'e.g. Annual General Meeting', 'the AGM is offered as one')
            assert.equal(title().value ?? '', '')

            await choose('Phase meeting')
            assert.equal(title().attrs.placeholder, 'e.g. Phase meeting')
        } finally {
            view.unmount()
        }
    })

    it('leaves a title somebody typed alone when the type changes', async () => {
        const { view, title, choose } = await open()
        try {
            await view.type(title(), 'Budget review, Phase 2')
            await choose('AGM')

            assert.equal(title().value, 'Budget review, Phase 2')
        } finally {
            view.unmount()
        }
    })
})
