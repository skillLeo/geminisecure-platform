/**
 * The meetings register's Publish never tells anybody at the first press — `Pages/Estate/Governance/Meetings.vue`
 * (DR-056: the second door to DR-007, found by the audit Hassam asked for on 10 Oct).
 *
 *   Every draft row has its own Publish, and it posted at once: every household told, with no question. It now opens the
 *   meeting with `?act=publish`, where DR-007's question names the server's count of who is told, the way every other
 *   act on a row already opens its dialog on the meeting.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Meetings } = await import('../../resources/js/Pages/Estate/Governance/Meetings.vue')

const ROW = {
    id: 9, title: 'General Meeting', stage: 'draft', stage_label: 'Draft', type_label: 'Annual general meeting', audience: 'Whole estate',
    publish_refusal: null, move_label: null, acts: [], quorum_state: 'upcoming', quorum_label: 'Counted at the meeting', has_minutes: false,
    action: 'Issue agenda', when: '18 Oct 2026, 6:00 PM', venue: 'Community Centre',
}

describe('the register Publish (DR-056)', () => {
    it('posts nothing, and opens the meeting with its question', async () => {
        resetInertia({ auth: { user: { id: 67001, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/meetings')
        const view = await mount(Meetings, {
            estate: { name: 'Phoenix Park' },
            rows: [ROW],
            table: { q: '', total: 1, page: 1, per: 20, last_page: 1, sort: 'starts', dir: 'asc' },
            electionYear: 2026,
            canSchedule: true,
            canPublish: true,
            publishReason: '',
            reasons: {},
        })
        await flush(nextTick)

        const publish = view.one((el) => el.tag === 'button' && textOf(el).trim() === 'Publish')
        assert.ok(publish, "the row's Publish")
        await view.click(publish)

        assert.equal(visits.filter((v) => v.method === 'post').length, 0, 'nothing is posted')
        const went = visits.filter((v) => v.method !== 'post').at(-1)
        assert.ok(went, 'the meeting is opened')
        assert.match(went.url, /\/governance\/meetings\/9\?act=publish$/)
    })
})
