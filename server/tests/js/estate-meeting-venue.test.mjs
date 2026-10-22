/**
 * A meeting says where, or says deliberately that it is to be confirmed — `Pages/Estate/Governance/MeetingScheduler.vue`
 * (M68 §2.7, GV-6).
 *
 *   The AGM went out with "No venue recorded". The server already refused an in-person meeting with no venue and an
 *   online one with no link, but the form let Schedule be pressed into that refusal, and its preview said "Venue to be
 *   confirmed" for an empty field nobody had chosen. Now: in person needs a venue, or "The venue is to be confirmed"
 *   ticked on purpose; online needs its link; Schedule is off with the reason until then (Law 1).
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
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

const open = async () => {
    resetInertia({ auth: { user: { id: 68701, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/meetings/new')
    const view = await mount(MeetingScheduler, PROPS)
    await flush(nextTick)

    // A title, a day and a time first: with any empty, Schedule is off for that reason (M98, 12 Oct), whatever the
    // venue. The title is typed now: the form no longer fills it in (batch 1 item 7).
    await view.type(view.one((el) => el.attrs?.id === 'meeting-title'), 'Committee meeting')
    await view.type(view.one((el) => el.attrs?.id === 'starts-on'), '2026-11-20')
    await view.type(view.one((el) => el.attrs?.id === 'starts-on-time'), '18:30')

    const schedule = () => view.one((el) => el.tag === 'button' && el.attrs?.type === 'submit' && el.attrs?.form === 'meeting-form')
    const tbc = () => view.one((el) => el.tag === 'input' && el.attrs?.id === 'venue-tbc')
    const tick = async (on) => {
        tbc().checked = on
        fire(tbc(), 'change')
        await nextTick()
    }

    return { view, schedule, tbc, tick }
}

describe('where the meeting is (GV-6)', () => {
    it('in person with no venue: Schedule is off, and says why', async () => {
        const { schedule } = await open()

        assert.ok(schedule().attrs.disabled, 'off with no venue')
        assert.equal(schedule().attrs.title, 'Add the venue, or tick that it is to be confirmed.')
    })

    it('"The venue is to be confirmed", ticked on purpose: Schedule is on, the preview says so, and the save carries it', async () => {
        const { view, schedule, tick } = await open()
        await tick(true)

        assert.ok(!schedule().attrs.disabled, 'on, the choice made deliberately')
        assert.match(view.text(), /Venue to be confirmed/)

        fire(view.one((el) => el.tag === 'form' && el.attrs?.id === 'meeting-form'), 'submit')
        await nextTick()

        const post = visits.filter((v) => v.method === 'post').at(-1)
        assert.ok(post, 'the draft is posted')
        assert.equal(post.data.venue_tbc, true)
        assert.equal(post.data.venue, null)
    })

    it('a venue typed: Schedule is on, and the preview names it', async () => {
        const { view, schedule } = await open()
        await view.type(view.one((el) => el.tag === 'input' && el.attrs?.id === 'venue'), 'Community Centre')

        assert.ok(!schedule().attrs.disabled)
        assert.match(view.text(), /Community Centre/)
        assert.doesNotMatch(view.text(), /Venue to be confirmed/)
    })

    it('online with no link: Schedule is off, and says why', async () => {
        const { view, schedule } = await open()
        await view.click(view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Zoom'))

        assert.ok(schedule().attrs.disabled)
        assert.equal(schedule().attrs.title, 'Add the joining link.')
    })
})
