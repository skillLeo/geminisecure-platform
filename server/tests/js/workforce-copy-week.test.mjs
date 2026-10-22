/**
 * "Copy last week into this week" on the Schedule — `Pages/Gemini/Operations/Schedule.vue` (M30 §4.6).
 *
 * The button asks the server what the copy would do (`GET /guards/schedule/copy`) and says it before anything
 * happens, in the console's own dialog: the two weeks, how many shifts are created, who keeps a guard, who comes across
 * with none and why, what is not copied and why, and that nothing is overwritten. The button names the count. Pressing
 * it sends exactly the plan that was shown (its fingerprint), and the server refuses if the week changed meanwhile.
 */
import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, geminiShared } from './support/alerts.mjs'

Object.defineProperty(globalThis.navigator, 'onLine', { value: false, configurable: true })
window.addEventListener ??= () => {}
window.removeEventListener ??= () => {}

let plan = null
const asked = []

globalThis.fetch = async (url) => {
    asked.push(url)

    return { ok: plan !== null, json: async () => plan ?? { message: 'That client is not one this role can plan for.' } }
}

const { default: Schedule } = await import('../../resources/js/Pages/Gemini/Operations/Schedule.vue')

const PLAN = (over = {}) => ({
    client: 'phoenixpark',
    week: '2026-10-12',
    from: { label: 'Mon 5 Oct – Sun 11 Oct', shifts: 18 },
    to: { label: 'Mon 12 Oct – Sun 18 Oct', shifts: 2 },
    create: 17,
    keep_guard: 12,
    was_open: 1,
    no_guard: 4,
    no_guard_why: ['K. Blake is on leave Mon 12 Oct and Tue 13 Oct', 'O. Bennett\'s licence expires Thu 15 Oct'],
    skipped_past: 0,
    skipped_taken: 1,
    left_alone: 2,
    fingerprint: 'f'.repeat(64),
    ...over,
})

const days = ['12', '13', '14', '15', '16', '17', '18'].map((d, i) => ({ date: `2026-10-${d}`, weekday: ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'][i], day: d, month: 'Oct', today: false }))

const open = async () => {
    resetInertia(geminiShared({ errors: {} }), '/guards/schedule?client=phoenixpark&week=2026-10-12')

    const view = await mount(Schedule, {
        clients: [{ id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false }],
        client: { id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false },
        zone: { estate: 'America/Jamaica', estateTag: 'JA', shown: 'America/Jamaica', shownTag: 'JA', mine: false },
        week: { monday: '2026-10-12', label: 'Mon 12 Oct – Sun 18 Oct 2026', previous: '2026-10-05', next: '2026-10-19', this: '2026-10-05', isThisWeek: false },
        days,
        rows: [{ id: 7, name: 'Main Gate', also_called: null, type: 'gate', cells: [[], [], [], [], [], [], []] }],
        cards: [],
        canWrite: true,
        writeBlockedReason: '',
    })
    await flush(nextTick)

    return view
}

const button = (view, label) => view.all((el) => el.tag === 'button' && textOf(el) === label)[0] ?? null

beforeEach(() => {
    socket.reset()
    visits.length = 0
    asked.length = 0
    plan = PLAN()
})

describe('copy last week into this week', () => {
    it('says what it will do before it does it, in the order the milestone draws it', async () => {
        const view = await open()

        await view.click(button(view, 'Copy last week into this week'))
        await flush(nextTick)

        assert.equal(asked.at(-1), '/guards/schedule/copy?client=phoenixpark&week=2026-10-12')

        // The two weeks, a cell each, as the grid draws them.
        const weeks = view.one((el) => el.tag === 'dl')
        assert.deepEqual(weeks.children.map(textOf), ['From', 'Mon 5 Oct – Sun 11 Oct', '18 shifts', 'To', 'Mon 12 Oct – Sun 18 Oct', '2 shifts already there'])

        const said = view.text()
        for (const line of [
            '✓ 17 shifts will be created',
            '✓ 12 of them keep the same guard',
            '⚠ 1 of them had no guard last week, and still need one',
            '⚠ 4 of them come across with no guard, because:',
            '⚠ 1 shift from last week is not copied: its gate already has a shift then',
            '⚠ 2 shifts already in this week are left alone. Nothing is overwritten.',
        ]) {
            assert.ok(said.includes(line), `says: ${line}`)
        }

        // Who comes across with no guard, and why: a line each.
        const why = view.one((el) => el.tag === 'ul' && el.className.includes('sch-copy-why'))
        assert.deepEqual(why.children.filter((el) => el.tag === 'li').map(textOf), ['K. Blake is on leave Mon 12 Oct and Tue 13 Oct', 'O. Bennett\'s licence expires Thu 15 Oct'])

        assert.ok(button(view, 'Create 17 shifts'), 'the button names the count')
        assert.equal(visits.length, 0, 'nothing is done by asking')
        view.unmount()
    })

    it('sends exactly the plan that was shown, and nothing when there is nothing to copy', async () => {
        const view = await open()

        await view.click(button(view, 'Copy last week into this week'))
        await flush(nextTick)
        await view.click(button(view, 'Create 17 shifts'))

        assert.equal(visits.at(-1).method, 'post')
        assert.equal(visits.at(-1).url, '/guards/schedule/copy')
        assert.deepEqual(visits.at(-1).data, { client: 'phoenixpark', week: '2026-10-12', fingerprint: 'f'.repeat(64) })
        view.unmount()

        plan = PLAN({ create: 0, keep_guard: 0, was_open: 0, no_guard: 0, no_guard_why: [], skipped_past: 3 })
        const empty = await open()
        await empty.click(button(empty, 'Copy last week into this week'))
        await flush(nextTick)

        assert.ok(button(empty, 'Create 0 shifts').attrs.disabled)
        assert.ok(empty.text().includes('⚠ 3 shifts from last week are not copied: their days this week have passed'))
        empty.unmount()
    })
})
