/**
 * The meeting form says what saving does — `Pages/Estate/Governance/MeetingScheduler.vue` (DR-013; step 6 of the walk).
 *
 *   "Schedule meeting" saves a draft that nobody sees, and the line under the preview said "Residents will see this on
 *   their Notices feed and in Meetings once scheduled". They see it once it is published. The button keeps the name
 *   the walk uses (M72 §1.3 step 6); the line now says when residents see it, and that until then it is a draft.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: MeetingScheduler } = await import('../../resources/js/Pages/Estate/Governance/MeetingScheduler.vue')

const PROPS = {
    estate: { name: 'Phoenix Park' },
    types: [
        { value: 'agm', label: 'Annual general meeting', eyebrow: 'AGM' },
        { value: 'committee', label: 'Committee meeting', eyebrow: 'Committee' },
    ],
    phases: ['Phase 1', 'Phase 2'],
    defaults: { type: 'committee', title: 'Committee meeting', audience_scope: 'committee', audience_label: 'Whole estate', quorum_percent: 25, recording_enabled: false, recording_consent_notice: false },
    eligibleHouseholds: 443,
    committeeMembers: 7,
    notice: { days: { agm: 21, committee: 7 }, earliest: { agm: '2026-10-31', committee: '2026-10-17' } },
    canSchedule: true,
    canPublish: true,
    blockedReason: '',
}

describe('what saving a meeting does (DR-013)', () => {
    it('says residents see it once it is published, not once it is saved', async () => {
        resetInertia({ auth: { user: { id: 68702, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/meetings/new')
        const view = await mount(MeetingScheduler, PROPS)
        await flush(nextTick)
        const text = view.text()

        assert.doesNotMatch(text, /once scheduled/)
        assert.match(text, /Residents see this in Meetings and on their Notices feed once it is published\. Until then it is a draft that nobody sees\./)
        assert.equal(textOf(view.one((el) => el.tag === 'button' && el.attrs?.form === 'meeting-form')).trim(), 'Schedule meeting')
        view.unmount()
    })
})
