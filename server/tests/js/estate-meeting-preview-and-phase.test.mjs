/**
 * The meeting preview shows the title typed, and a phase meeting's phase is chosen (GV-12, register DR-100).
 *
 *   `Pages/Estate/Governance/MeetingScheduler.vue`: the preview card ignored the title the secretary typed, and choosing
 *   "Phase meeting" quietly took Phase 1. The preview now shows the typed title; a phase meeting starts with no phase,
 *   the audience field asks for one, and Schedule is off, saying why, until one is chosen.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: MeetingScheduler } = await import('../../resources/js/Pages/Estate/Governance/MeetingScheduler.vue')

const PROPS = {
    estate: { name: 'Phoenix Park' },
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

const PHASE_REASON = 'Choose which phase the meeting is for.'

const open = async () => {
    resetInertia({ auth: { user: { id: 91111, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/meetings/new')
    const view = await mount(MeetingScheduler, PROPS)
    await flush(nextTick)

    const schedule = () => view.one((el) => el.tag === 'button' && el.attrs?.form === 'meeting-form')
    const audience = () => view.one((el) => el.attrs?.id === 'audience')
    const choose = (label) => view.click(view.all((el) => byClass('seg-item')(el) && textOf(el).trim() === label)[0])

    return { view, schedule, audience, choose }
}

describe('the meeting preview and a phase meeting\'s phase (GV-12)', () => {
    it('shows the title typed on the preview card', async () => {
        const { view } = await open()
        try {
            await view.type(view.one((el) => el.attrs?.id === 'meeting-title'), 'Budget review for Phase 2')

            assert.match(view.text(), /Budget review for Phase 2/)
        } finally {
            view.unmount()
        }
    })

    it('starts a phase meeting with no phase, asks for one, and keeps Schedule off until it is chosen', async () => {
        const { view, schedule, audience, choose } = await open()
        try {
            await choose('Phase meeting')

            // (This harness marks no option selected on a list drawn afresh, so the select's value reads blank: what is
            // checked is that no real phase is the one chosen, and Schedule's reason below says the same.)
            assert.ok(!audience().options.some((option) => option.selected && option.value !== 'phase:'), 'no phase is chosen for the secretary')
            assert.match(textOf(audience()), /Choose a phase/)
            assert.equal(schedule().attrs.disabled, true, 'Schedule is off')
            assert.equal(schedule().attrs.title, PHASE_REASON, 'and says why')
            assert.match(textOf(audience().parent.parent.parent), new RegExp(PHASE_REASON.replace('.', '\\.')), 'the reason sits under the field')

            visits.length = 0
            fire(view.one((el) => el.tag === 'form' && el.attrs?.id === 'meeting-form'), 'submit')
            await nextTick()
            assert.equal(visits.filter((v) => v.method === 'post').length, 0, 'Enter posts nothing')

            for (const option of audience().options) {
                option.selected = option.value === 'phase:Phase 2'
            }
            fire(audience(), 'change')
            await nextTick()

            assert.notEqual(schedule().attrs.title, PHASE_REASON, 'the phase chosen, that reason is gone')
        } finally {
            view.unmount()
        }
    })
})
