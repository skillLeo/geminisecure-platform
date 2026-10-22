/**
 * A meeting is dated up to eighteen months ahead, and the form says so (GV-10, register DR-098).
 *
 *   `Pages/Estate/Governance/MeetingScheduler.vue`: the date had a floor (the notice period) and no ceiling, so 2030
 *   was taken. The field now carries the server's latest day as its `max`, and a date past it turns Schedule off with
 *   the server's own sentence, on the button and under the date.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: MeetingScheduler } = await import('../../resources/js/Pages/Estate/Governance/MeetingScheduler.vue')

const LATEST_LINE = 'A meeting is scheduled up to 18 months ahead. The latest day is 11 Apr 2028.'

const PROPS = {
    estate: { name: 'Phoenix Park' },
    types: [{ value: 'committee', label: 'Committee', eyebrow: 'COMMITTEE MEETING', title: 'Committee meeting' }],
    phases: ['Phase 1'],
    defaults: { type: 'committee', title: 'Committee meeting', audience_scope: 'committee', audience_label: 'Whole estate', quorum_percent: 25, recording_enabled: false, recording_consent_notice: false },
    eligibleHouseholds: 443,
    committeeMembers: 7,
    notice: { days: { committee: 7 }, earliest: { committee: '2026-10-18' }, latest: '2028-04-11', latest_line: LATEST_LINE },
    canSchedule: true,
    canPublish: true,
    blockedReason: '',
}

describe('the meeting date has a ceiling (GV-10)', () => {
    it('carries the latest day as the field\'s max, and turns Schedule off with the sentence for a date past it', async () => {
        resetInertia({ auth: { user: { id: 91112, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/meetings/new')
        const view = await mount(MeetingScheduler, PROPS)
        await flush(nextTick)
        try {
            const date = () => view.one((el) => el.attrs?.id === 'starts-on')
            const schedule = () => view.one((el) => el.tag === 'button' && el.attrs?.form === 'meeting-form')

            assert.equal(date().attrs.max, '2028-04-11', 'the field stops at the latest day')

            await view.type(date(), '2030-01-10')

            assert.equal(schedule().attrs.disabled, true, 'Schedule is off')
            // The server's sentence is said once, under the date; beside Schedule, a pointer to it (batch 1 item 5).
            assert.equal(schedule().attrs.title, 'The date is too far ahead: see the Date field.', 'and says why')
            assert.match(textOf(date().parent.parent.parent), /The latest day is 11 Apr 2028\./, 'the line sits under the date')

            await view.type(date(), '2027-03-01')

            assert.notEqual(schedule().attrs.title, 'The date is too far ahead: see the Date field.', 'inside the window, that reason is gone')
        } finally {
            view.unmount()
        }
    })
})
