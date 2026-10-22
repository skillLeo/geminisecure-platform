/**
 * The meetings table reads as a register — `Pages/Estate/Governance/Meetings.vue` (M70 §1.1, E2E-M6; step 6).
 *
 *   Hassam, 10 Oct: keep the order (upcoming soonest first, then past newest first) and make it look deliberate, with a
 *   "Past meetings" heading above the past group. The Date heading carries the arrow in that order. The action cell
 *   holds one action and the menu, nothing else: a draft inside its notice period says so on its Publish, and "Move to
 *   16 Oct" is in the menu. The Quorum column, the same words on every row, is gone (the meeting's page has quorum).
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Meetings } = await import('../../resources/js/Pages/Estate/Governance/Meetings.vue')

const row = (id, title, upcoming, extra = {}) => ({
    id, title, upcoming, starts_ts: 1_760_000_000 + id, type: 'committee', type_label: 'Committee meeting', date: `${id} Oct 2026`, audience: 'Committee members',
    quorum_label: 'Counted at the meeting', quorum_state: 'upcoming', stage: upcoming ? 'scheduled' : 'held', stage_label: upcoming ? 'Scheduled' : 'Held',
    publish_refusal: null, move_label: null, acts: [], action: 'View agenda', has_minutes: false, published: true, ...extra,
})

const ROWS = [
    row(10, 'AGM 2026', true),
    row(17, 'Phase 2 boundary wall discussion', true),
    row(21, 'Committee draft', true, { stage: 'draft', stage_label: 'Draft', published: false, publish_refusal: "Needs 7 days' notice. Earliest 16 Oct.", move_label: 'Move to 16 Oct', acts: ['edit', 'move_to_earliest', 'delete'] }),
    row(8, 'Gate contract renewal', false),
]

const TABLE = { q: '', sort: null, dir: null, default: { sort: 'agenda', dir: 'asc' }, sortable: ['agenda', 'title', 'type', 'date', 'audience'], page: 1, last: 1, from: 1, to: 4, total: 4, per: 25 }

const open = async (table = TABLE, rows = ROWS) => {
    resetInertia({ auth: { user: { id: 70101, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors: {}, flash: {} }, '/governance/meetings')
    const view = await mount(Meetings, {
        estate: { name: 'Phoenix Park' },
        rows,
        table,
        electionYear: 2026,
        canSchedule: true,
        canPublish: true,
        publishReason: '',
        reasons: {},
        canEdit: true,
        canDelete: true,
    })
    await flush(nextTick)

    const bodyRows = () => findAll(view.one(byTag('tbody')), byTag('tr'))
    const header = (label) => view.all(byTag('th')).find((th) => textOf(th).trim() === label)

    return { view, bodyRows, header }
}

describe('the meetings table (E2E-M6)', () => {
    it('opens the past group under a "Past meetings" heading, in the register\'s own order', async () => {
        const { bodyRows } = await open()
        const texts = bodyRows().map((tr) => textOf(tr))

        assert.equal(texts.length, 5, 'four meetings and the heading')
        assert.equal(texts[3], 'Past meetings', 'the heading sits right above the first past meeting')
        assert.match(texts[4], /Gate contract renewal/)
    })

    it('draws no heading when a heading orders the table by its own column', async () => {
        const { bodyRows } = await open({ ...TABLE, sort: 'title', dir: 'asc' })

        assert.ok(!bodyRows().some((tr) => textOf(tr) === 'Past meetings'))
    })

    it('puts the arrow on Date in the register\'s own order', async () => {
        const { header } = await open()
        const date = header('Date')

        assert.ok(date, 'a Date heading')
        assert.equal(findAll(date, byClass('dt-arrow')).length, 1, 'the arrow on Date')
        assert.equal(date.attrs['aria-sort'], 'ascending')
    })

    it('holds one action and the menu: the notice line is Publish\'s reason, and Move is in the menu', async () => {
        const { view, bodyRows } = await open()
        const draft = bodyRows()[2]
        const cell = findAll(draft, byTag('td')).at(-1)

        assert.equal(findAll(cell, byClass('mt-refusal-line')).length, 0, 'no guidance in the cell')
        const actions = findAll(cell, (el) => (el.tag === 'button' || el.tag === 'a') && !byClass('row-menu-button')(el))
        assert.equal(actions.length, 1, 'one action besides the menu')
        assert.equal(textOf(actions[0]).trim(), 'Publish')
        assert.ok(actions[0].attrs.disabled, 'Publish is off inside the notice period')
        assert.equal(actions[0].attrs.title, "Needs 7 days' notice. Earliest 16 Oct.")

        await view.click(findAll(cell, byClass('row-menu-button'))[0])
        const move = findAll(cell, byClass('row-menu-item')).find((el) => textOf(el).trim() === 'Move to 16 Oct')
        assert.ok(move, 'Move is in the menu')
        assert.equal(move.attrs.method, 'post')
        assert.match(move.attrs.href, /\/governance\/meetings\/21\/move-to-earliest$/)
    })

    it('has no Quorum column', async () => {
        const { view } = await open()

        assert.ok(!view.all(byTag('th')).some((th) => textOf(th).trim() === 'Quorum'))
    })
})
