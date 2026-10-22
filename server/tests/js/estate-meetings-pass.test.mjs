/**
 * Meetings, after the M98 pass (`Pages/Estate/Governance/Meetings.vue`).
 *
 *   A role that can never publish has no Publish on a draft, and a role that can never schedule has no Schedule meeting
 *   (M96 level 3); a draft inside its notice period has Publish greyed with its reason under it on the screen (level 2);
 *   every stage reads as a word of the workflow set (M96 §2, M98 §3.5), never Scheduled, Held, Minutes published,
 *   Archived or Postponed; a refusal is a toast top right, never a banner on the page (M95 §2); and the row's one action
 *   and its menu sit side by side in one box, so the menu never sits over the action (M94 §1.4).
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush } from './support/alerts.mjs'

const { default: Meetings } = await import('../../resources/js/Pages/Estate/Governance/Meetings.vue')
const { resetToasts, toasts } = await import('../../resources/js/lib/toasts.js')

const ROW = (id, title, stage, over = {}) => ({
    id, title, upcoming: ['draft', 'scheduled', 'postponed'].includes(stage), starts_ts: 1_760_000_000 + id, type: 'committee',
    type_label: 'Committee meeting', date: `${id} Oct 2026`, audience: 'Committee members', quorum_label: 'Counted at the meeting',
    quorum_state: 'upcoming', stage, stage_label: stage, publish_refusal: null, move_label: null, acts: [], action: 'View agenda',
    has_minutes: false, published: stage !== 'draft', ...over,
})

const TABLE = { q: '', sort: 'title', dir: 'asc', default: { sort: 'agenda', dir: 'asc' }, sortable: ['agenda', 'title'], page: 1, last: 1, from: 1, to: 1, total: 1, per: 25 }

const open = async (props = {}, errors = {}) => {
    resetInertia({ auth: { user: { id: 98301, name: 'Kayla Morrison', role_label: 'Secretary' } }, estateNav: [], panicWatch: null, errors, flash: {} }, '/governance/meetings')
    const view = await mount(Meetings, {
        estate: { name: 'Phoenix Park Village 1' },
        rows: [ROW(21, 'Committee draft', 'draft', { publish_refusal: "Needs 7 days' notice. Earliest 16 Oct." })],
        table: TABLE,
        electionYear: 2026,
        canSchedule: true,
        canPublish: true,
        publishReason: 'Publishing a meeting needs Governance approve.',
        reasons: {},
        canEdit: true,
        canDelete: true,
        ...props,
    })
    await flush(nextTick)

    return view
}

const buttonCalled = (view, words) => view.all((el) => (el.tag === 'button' || el.tag === 'a') && textOf(el).trim() === words)[0] ?? null

afterEach(() => resetToasts())

describe('Meetings, after the M98 pass', () => {
    it('has no Publish and no Schedule meeting for a role that can never do them', async () => {
        const view = await open({ canPublish: false, canSchedule: false })

        try {
            // Booleans, never an element: a failing comparison of a rendered tree prints the whole tree.
            assert.equal(buttonCalled(view, 'Publish') === null, true, 'no Publish on the draft (level 3)')
            assert.equal(buttonCalled(view, 'Schedule meeting') === null, true, 'no Schedule meeting (level 3)')
            assert.ok(view.text().includes('Draft'), 'the draft still says what it is')
        } finally {
            view.unmount()
        }
    })

    it('has no "Schedule the first meeting" on an empty register for a role that can never schedule', async () => {
        const view = await open({ canSchedule: false, rows: [], table: { ...TABLE, total: 0, from: 0, to: 0 } })

        try {
            assert.equal(buttonCalled(view, 'Schedule the first meeting') === null, true, 'no first-meeting button (level 3)')
        } finally {
            view.unmount()
        }
    })

    it('greys Publish inside the notice period with the reason under it, on the screen', async () => {
        const view = await open()

        try {
            const publish = buttonCalled(view, 'Publish')
            assert.ok(publish, 'Publish is there for a role that may publish')
            assert.ok(publish.attrs.disabled, 'greyed inside the notice period')
            assert.equal(textOf(view.one(byClass('row-why'))), "Needs 7 days' notice. Earliest 16 Oct.", 'the reason on the screen, not only on hover')
        } finally {
            view.unmount()
        }
    })

    it('reads every stage in the workflow words and never the old ones', async () => {
        const rows = [
            ROW(1, 'A scheduled meeting', 'scheduled'),
            ROW(2, 'A held meeting', 'held'),
            ROW(3, 'Minutes out', 'minutes_published', { has_minutes: true, action: 'View minutes' }),
            ROW(4, 'Filed away', 'archived', { has_minutes: true, action: 'View minutes' }),
            ROW(5, 'Put off', 'postponed'),
            ROW(6, 'Called off', 'cancelled'),
            ROW(7, 'Sitting now', 'in_progress'),
        ]
        const view = await open({ rows, table: { ...TABLE, total: rows.length, to: rows.length } })

        try {
            const chip = (title) => textOf(findAll(view.all(byTag('tr')).find((tr) => textOf(tr).includes(title)), byClass('mt-stage'))[0])

            assert.equal(chip('A scheduled meeting'), 'Confirmed')
            assert.equal(chip('A held meeting'), 'Completed')
            assert.equal(chip('Minutes out'), 'Closed')
            assert.equal(chip('Filed away'), 'Closed')
            assert.equal(chip('Put off'), 'Pending')
            assert.equal(chip('Called off'), 'Cancelled')
            assert.equal(chip('Sitting now'), 'In progress')
        } finally {
            view.unmount()
        }
    })

    it('says a refused paper as a toast that stays, and a refusal handed with the page as one too, never as a banner', async () => {
        const rows = [ROW(1, 'A held meeting', 'held')]
        const view = await open({ rows, table: { ...TABLE, total: 1 } }, { starts_at: 'That day is inside the notice period now.' })

        try {
            assert.equal(view.one(byClass('gov-refusal')) === null, true, 'no banner on the page')
            assert.equal(toasts[0]?.text, 'That day is inside the notice period now.')
            assert.equal(toasts[0]?.tone, 'warning', 'amber: red is kept for panic and life safety')

            await view.click(buttonCalled(view, 'View agenda'))
            const post = visits.filter((v) => v.method === 'post').at(-1)
            assert.match(post.url, /\/governance\/meetings\/1\/agenda$/)
            post.options.onError({ meeting: 'The agenda could not be issued.' })
            await nextTick()

            assert.equal(toasts[0]?.text, 'The agenda could not be issued.')
            assert.equal(toasts[0]?.tone, 'warning', 'amber: red is kept for panic and life safety')
            assert.equal(view.one(byClass('gov-refusal')) === null, true, 'still no banner')
        } finally {
            view.unmount()
        }
    })

    it('keeps the row\'s one action and its menu side by side in one box (M94 §1.4)', async () => {
        const rows = [ROW(1, 'A held meeting', 'held', { acts: ['attendance', 'minutes'] })]
        const view = await open({ rows, table: { ...TABLE, total: 1 } })

        try {
            const cell = findAll(view.all(byTag('tr')).find((tr) => textOf(tr).includes('A held meeting')), byTag('td')).at(-1)
            const box = findAll(cell, byClass('mt-acts'))[0]

            assert.ok(box, 'one box for the action and the menu')
            assert.equal(findAll(box, byClass('row-menu-button')).length, 1, 'the menu is inside it')
            assert.equal(findAll(box, (el) => el.tag === 'button' && textOf(el).trim() === 'View agenda').length, 1, 'and the action')
        } finally {
            view.unmount()
        }
    })
})
