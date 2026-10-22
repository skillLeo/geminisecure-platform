/**
 * Dragging a shift to another cell on the Schedule (M30 §4.3) — `lib/scheduleMove.js` and `Schedule.vue`.
 *
 * A block dropped on another cell keeps its times on the client's clock and moves to that gate and day. The server is
 * asked first: a move that breaks a rule springs back with the reason in one line; one that does not asks, in one
 * line, "Move Andre Campbell's 6:00 AM – 6:00 PM JA shift from Main Gate, Mon 12 Oct, to Back Gate, Tue 13 Oct?" with
 * Move and Cancel, and only Move saves it.
 */
import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, geminiShared } from './support/alerts.mjs'
import { addDays, plannedMove } from '../../resources/js/lib/scheduleMove.js'

Object.defineProperty(globalThis.navigator, 'onLine', { value: false, configurable: true })
window.addEventListener ??= () => {}
window.removeEventListener ??= () => {}

let answer = null
const asked = []

globalThis.fetch = async (url, init = {}) => {
    asked.push({ url, body: init.body ? JSON.parse(init.body) : null })

    return { ok: true, json: async () => answer }
}

const { default: Schedule } = await import('../../resources/js/Pages/Gemini/Operations/Schedule.vue')

const ZONE = 'America/Jamaica'
const MAIN = { id: 7, name: 'Main Gate' }
const BACK = { id: 8, name: 'Back Gate' }

// Monday 12 October 2026, 6 AM to 6 PM in Kingston (11:00 to 23:00 UTC), Andre Campbell on it.
const DAY = { id: 55, post_id: 7, starts_at: '2026-10-12T11:00:00+00:00', ends_at: '2026-10-12T23:00:00+00:00', time: '6 AM – 6 PM JA', guard: { id: 1, name: 'Andre Campbell', short: 'A. Campbell' }, duty: 'post', state: 'upcoming', asks: 0 }
// Its night: 6 PM to 6 AM, nobody on it.
const NIGHT = { ...DAY, id: 56, starts_at: '2026-10-12T23:00:00+00:00', ends_at: '2026-10-13T11:00:00+00:00', time: '6 PM – 6 AM Tue JA', guard: null }

describe('where a dragged shift lands', () => {
    it('keeps its times on the client\'s clock, moves by the days between the cells, and asks in the milestone\'s words', () => {
        const move = plannedMove({ block: DAY, fromPost: MAIN, fromDay: '2026-10-12', toPost: BACK, toDay: '2026-10-13', zone: ZONE, tag: 'JA' })

        assert.deepEqual({ ...move, sentence: undefined }, { post_id: 8, date: '2026-10-13', starts: '06:00', ends: '18:00', duty: 'post', guard_id: 1, sentence: undefined })
        assert.equal(move.sentence, 'Move Andre Campbell\'s 6:00 AM – 6:00 PM JA shift from Main Gate, Mon 12 Oct, to Back Gate, Tue 13 Oct?')
    })

    it('keeps a night shift a night shift, and names an open one as this one', () => {
        const move = plannedMove({ block: NIGHT, fromPost: MAIN, fromDay: '2026-10-12', toPost: MAIN, toDay: '2026-10-15', zone: ZONE, tag: 'JA' })

        assert.equal(move.date, '2026-10-15')
        assert.equal(move.starts, '18:00')
        assert.equal(move.ends, '06:00')
        assert.equal(move.guard_id, null)
        assert.equal(move.sentence, 'Move this 6:00 PM – 6:00 AM JA shift from Main Gate, Mon 12 Oct, to Main Gate, Thu 15 Oct?')
    })

    it('does nothing when it is dropped where it is, and counts days across a month\'s end', () => {
        assert.equal(plannedMove({ block: DAY, fromPost: MAIN, fromDay: '2026-10-12', toPost: MAIN, toDay: '2026-10-12', zone: ZONE, tag: 'JA' }), null)
        assert.equal(addDays('2026-10-31', 1), '2026-11-01')
    })
})

const days = ['12', '13', '14', '15', '16', '17', '18'].map((d, i) => ({ date: `2026-10-${d}`, weekday: ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'][i], day: d, month: 'Oct', today: false }))

const open = async () => {
    resetInertia(geminiShared({ errors: {} }), '/guards/schedule?client=phoenixpark&week=2026-10-12')

    const view = await mount(Schedule, {
        clients: [{ id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false }],
        client: { id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false },
        zone: { estate: ZONE, estateTag: 'JA', shown: ZONE, shownTag: 'JA', mine: false },
        week: { monday: '2026-10-12', label: 'Mon 12 Oct – Sun 18 Oct 2026', previous: '2026-10-05', next: '2026-10-19', this: '2026-10-05', isThisWeek: false },
        days,
        rows: [
            { id: 7, name: 'Main Gate', also_called: null, type: 'gate', cells: [[DAY], [], [], [], [], [], []] },
            { id: 8, name: 'Back Gate', also_called: null, type: 'gate', cells: [[], [], [], [], [], [], []] },
        ],
        cards: [],
        canWrite: true,
        writeBlockedReason: '',
    })
    await flush(nextTick)

    return view
}

// Drag the block from its cell and drop it on the Back Gate's Tuesday.
const dragToBackGateTuesday = async (view) => {
    const block = view.one(byClass('sch-block'))
    const rows = view.all((el) => el.className.split(/\s+/).includes('sch-row') && !el.className.includes('roster-head-row'))
    const target = rows[1].children.filter((el) => el.className?.includes('sch-cell'))[1]

    fire(block, 'dragstart', { dataTransfer: { setData() {}, effectAllowed: '' } })
    fire(target, 'dragover')
    fire(target, 'drop')
    await flush(nextTick)

    return block
}

beforeEach(() => {
    socket.reset()
    visits.length = 0
    asked.length = 0
})

describe('dragging a shift on the Schedule', () => {
    it('asks the server first, then asks in one line, and saves only on Move', async () => {
        answer = { ok: true, checks: [{ ok: true, text: 'Back Gate is free at this time' }] }
        const view = await open()

        await dragToBackGateTuesday(view)

        const question = asked.at(-1)
        assert.equal(question.url, '/guards/schedule/check')
        assert.deepEqual(question.body, { post_id: 8, date: '2026-10-13', starts: '06:00', ends: '18:00', duty: 'post', guard_id: 1, shift_id: 55, repeat: 'once' })
        assert.match(view.text(), /Move Andre Campbell's 6:00 AM – 6:00 PM JA shift from Main Gate, Mon 12 Oct, to Back Gate, Tue 13 Oct\?/)
        assert.equal(visits.length, 0, 'nothing is saved by dropping')

        await view.click(view.all((el) => el.tag === 'button' && textOf(el) === 'Move')[0])
        assert.equal(visits.at(-1).url, '/guards/schedule/shifts/55')
        assert.deepEqual(visits.at(-1).data, { post_id: 8, date: '2026-10-13', starts: '06:00', ends: '18:00', duty: 'post', guard_id: 1 })
        view.unmount()
    })

    it('springs back with the reason in one line when the move breaks a rule, and offers nothing to press but Close', async () => {
        answer = { ok: false, checks: [{ ok: true, text: 'Back Gate is free at this time' }, { ok: false, text: 'Andre Campbell is on another shift: Back Gate, Tue 13 Oct, 9:00 AM to 5:00 PM JA' }] }
        const view = await open()

        const block = await dragToBackGateTuesday(view)

        assert.match(view.text(), /✗ Andre Campbell is on another shift: Back Gate, Tue 13 Oct, 9:00 AM to 5:00 PM JA/)
        assert.ok(block.className.includes('sch-spring'), 'the block springs back')
        assert.equal(view.all((el) => el.tag === 'button' && textOf(el) === 'Move').length, 0)
        assert.ok(view.all((el) => el.tag === 'button' && textOf(el) === 'Close').length > 0)
        assert.equal(visits.length, 0)
        view.unmount()
    })
})
