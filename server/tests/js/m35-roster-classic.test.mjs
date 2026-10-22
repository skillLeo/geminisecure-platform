/**
 * M35 on the classic Roster (`Pages/Gemini/Operations/Roster.vue`) and the Schedule's claims.
 *
 *   §4  the grid is the week's shifts, each on its own day (m10/partc's 903dd56b, brought to production), and a shift
 *       on it opens the schedule's panel
 *   §2  every row of the day list has Change guard (Assign, on an open shift) and Remove — the schedule's own panel,
 *       opened on that shift; Remove held down, with the reason, once the shift has started
 *   §3  "Post open shift" opens the same panel, with its one line before anything is typed, instead of a form of its own
 *   §1  the Roster tab opens the Schedule now, so a guard's claim on an open shift is answered there too
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

const SHIFT = {
    id: 55,
    title: 'Main Gate · Fri 9 Oct · 6:00 AM – 6:00 PM JA',
    form: { post_id: 7, date: '2026-10-09', starts: '06:00', ends: '18:00', duty: 'post', guard_id: 1 },
    guard: { id: 1, name: 'Andre Campbell' },
    locked: null,
    started: false,
    can: { change_times: true, change_guard: true, take_off: true, remove: true },
    note: null,
    history: [],
}

const fetched = []
globalThis.fetch = async (url, init = {}) => {
    fetched.push({ url, method: init.method ?? 'GET' })

    return (init.method ?? 'GET') === 'GET'
        ? { ok: true, json: async () => SHIFT }
        : { ok: true, json: async () => ({ ok: true, count: 1, hours: '12 hours', occurrences: [], guards: [], checks: [] }) }
}

const { default: Roster } = await import('../../resources/js/Pages/Gemini/Operations/Roster.vue')
const { default: Schedule } = await import('../../resources/js/Pages/Gemini/Operations/Schedule.vue')

const ZONE = { estate: 'America/Jamaica', estateTag: 'JA', shown: 'America/Jamaica', shownTag: 'JA', mine: false }
const PANELS = {
    phoenixpark: {
        client: { id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false },
        zone: ZONE,
        posts: [{ id: 7, name: 'Main Gate', also_called: null, type: 'gate' }],
    },
    oceanview: {
        client: { id: 'oceanview', name: 'Ocean View', internal: false },
        zone: ZONE,
        posts: [{ id: 9, name: 'North Gate', also_called: null, type: 'gate' }],
    },
}

const ROW = (over = {}) => ({
    id: 55,
    tenant_id: 'phoenixpark',
    licence_needed_to: '2026-10-09',
    post: 'Main Gate',
    duty: 'post',
    estate: 'Phoenix Park Village 1',
    window: '6:00 AM – 6:00 PM JA',
    officer: 'Andre Campbell',
    is_open: false,
    released_reason: null,
    status: 'rostered',
    claims: [],
    locked: null,
    can_remove: true,
    ...over,
})

const roster = async (rows) => {
    resetInertia(geminiShared({ errors: {}, flash: {} }), '/guards/roster?date=2026-10-09')

    const view = await mount(Roster, {
        days: ['Mon 5', 'Tue 6', 'Wed 7', 'Thu 8', 'Fri 9', 'Sat 10', 'Sun 11'],
        week: 'Oct 5 – Oct 11, 2026',
        // The week's shifts (903dd56b, M35 §4): a cell is every shift that starts at that post that day — here one on
        // Friday with an officer and one open on Saturday, and nothing on the other five days.
        groups: [{
            id: 'phoenixpark',
            name: 'Phoenix Park Village 1',
            lines: [{
                key: 'l1',
                post: 'Main Gate',
                cells: [[], [], [], [], [{ id: 55, time: '6:00 AM – 6:00 PM JA', label: 'A. Campbell', tone: 'day', title: 'Andre Campbell · 6:00 AM – 6:00 PM JA.' }], [{ id: 58, time: '6:00 PM – 6:00 AM JA', label: 'Open', tone: 'open', title: 'Nobody is on it.' }], []],
            }],
        }],
        previousWeek: '2026-09-28',
        nextWeek: '2026-10-12',
        thisWeek: true,
        kpis: [],
        scoped: false,
        day: { date: '2026-10-09', previous: '2026-10-08', next: '2026-10-10', rows, open: rows.filter((r) => r.is_open).length },
        dayRequested: true,
        weekDates: ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'],
        posts: [],
        officers: [],
        canWrite: true,
        writeBlockedReason: 'needs Guard workforce update access',
        claimsWaiting: [],
        shiftPanels: PANELS,
    })
    await flush(nextTick)

    return view
}

const buttons = (view, label) => view.all((el) => el.tag === 'button' && textOf(el) === label)
const settle = async () => {
    await new Promise((resolve) => setTimeout(resolve, 360))
    await flush(nextTick)
}

beforeEach(() => {
    socket.reset()
    visits.length = 0
    fetched.length = 0
})

describe('the classic Roster (M35)', () => {
    it('draws the week\'s shifts on their own days — an open shift only on its day — and a shift clicked opens the panel on it', async () => {
        const view = await roster([ROW()])

        assert.match(view.text(), /Week of Oct 5 – Oct 11, 2026 \(this week\) · every shift, on the day it starts, with its time/)
        // Five days with nothing on them say so; no officer's name repeats across the week.
        assert.equal(view.all((el) => el.attrs.class === 'rst-no-shift').length, 5)
        assert.equal((view.text().match(/A\. Campbell/g) ?? []).length, 1)

        const open = view.one((el) => el.tag === 'button' && /Open/.test(textOf(el)) && String(el.attrs.class).includes('rst-shift'))
        await view.click(open)
        await settle()
        // The schedule's own panel, read for that shift: Change guard, Take off and Remove live there.
        assert.ok(fetched.some((f) => f.url === '/guards/schedule/shifts/58'), 'the panel reads the shift it was opened on')
        view.unmount()
    })

    it('gives every row Change guard and Remove, an open one Assign and Remove, held down with the reason when the rule says no', async () => {
        const view = await roster([
            ROW(),
            ROW({ id: 56, officer: null, is_open: true }),
            ROW({ id: 57, can_remove: false }),
            ROW({ id: 58, locked: 'This shift has ended. It stays as it was worked.', can_remove: false }),
        ])

        const change = buttons(view, 'Change guard')
        const assign = buttons(view, 'Assign')
        const remove = buttons(view, 'Remove')

        assert.equal(change.length, 3)
        assert.equal(assign.length, 1)
        assert.equal(remove.length, 4)

        // Rostered, not started: both can be pressed.
        assert.equal(change[0].attrs.disabled, undefined)
        assert.equal(remove[0].attrs.disabled, undefined)
        assert.equal(assign[0].attrs.disabled, undefined)

        // Started: Remove is held down, and says why; who stands it can still change.
        assert.ok(remove[2].attrs.disabled)
        assert.match(remove[2].attrs.title, /It has started, so it is not removed/)
        assert.equal(change[1].attrs.disabled, undefined)

        // Ended: nothing changes, and the rule's own sentence is the reason.
        assert.ok(change[2].attrs.disabled)
        assert.equal(change[2].attrs.title, 'This shift has ended. It stays as it was worked.')
        assert.ok(remove[3].attrs.disabled)
        view.unmount()
    })

    it('opens the schedule\'s panel on the shift: Remove at its one-line confirmation, naming the post, the day and the time', async () => {
        const view = await roster([ROW()])

        await view.click(buttons(view, 'Remove')[0])
        await settle()

        assert.ok(fetched.some((f) => f.url === '/guards/schedule/shifts/55' && f.method === 'GET'), 'the panel reads the shift')
        assert.match(view.text(), /Remove Main Gate, Fri 9 Oct, 6:00 AM – 6:00 PM JA\? Andre Campbell's phone is told\./)
        view.unmount()
    })

    it('opens the same panel for Post open shift: left open, the one line first, a choice of client — and no form of its own', async () => {
        const view = await roster([ROW()])

        assert.doesNotMatch(view.text(), /never into the past/, 'the old paragraph is behind the panel\'s i now')
        assert.equal(view.all((el) => el.tag === 'input' && el.attrs.type === 'datetime-local').length, 0)

        await view.click(view.all((el) => el.tag === 'button' && textOf(el) === 'Post open shift')[0])
        await settle()

        assert.match(view.text(), /Add a shift/)
        assert.match(view.text(), /Shifts are posted from today onward, up to 24 hours each\. A night shift ending the next morning is fine\./)
        assert.match(view.text(), /Leave it open, any guard can ask for it/)

        const open = view.one((el) => el.tag === 'input' && el.attrs.type === 'radio' && el.attrs.name === 'sp-guard')
        assert.equal(open.checked, true, 'nobody is put on it')

        const client = view.all((el) => el.tag === 'select')[0]
        assert.deepEqual(client.children.filter((o) => o.tag === 'option').map(textOf), ['Phoenix Park Village 1', 'Ocean View'])
        view.unmount()
    })
})

describe('claims on the Schedule (M35 §1)', () => {
    const CLAIM = { id: 31, shift_id: 93, guard: 'Kemar Blake', employee_number: 'GS-2002', claimed_at: '2026-10-06T15:00:00+00:00', claimed: 'Oct 6, 10:00 AM', post: 'Main Gate', estate: 'Phoenix Park Village 1', day: '2026-10-20', window: 'Tue 20 Oct, 7:00 AM – 5:00 PM JA' }

    it('answers a guard\'s claim on an open shift where the Roster tab now opens, by the same routes as the classic roster', async () => {
        resetInertia(geminiShared({ errors: {} }), '/guards/schedule?client=phoenixpark')
        const view = await mount(Schedule, {
            clients: [PANELS.phoenixpark.client],
            client: PANELS.phoenixpark.client,
            zone: ZONE,
            week: { monday: '2026-10-19', label: 'Mon 19 Oct – Sun 25 Oct 2026', previous: '2026-10-12', next: '2026-10-26', this: '2026-10-05', isThisWeek: false },
            days: [],
            rows: [],
            cards: [],
            canWrite: true,
            writeBlockedReason: '',
            leave: [],
            leaveWaiting: [],
            canDecideLeave: true,
            claimsWaiting: [CLAIM],
        })
        await flush(nextTick)

        // In the Schedule's own words (M30 §9): no "claim", no "open shift".
        assert.match(view.text(), /Waiting for your answer — a guard has asked for a shift that needs a guard/)
        assert.match(view.text(), /Kemar Blake · Main Gate · Tue 20 Oct, 7:00 AM – 5:00 PM JA/)

        // Each answer asks first, naming who is told (DR-075), and goes from the question's own button.
        const confirm = () => view.all((el) => el.tag === 'button' && String(el.attrs.class ?? '').split(/\s+/).includes('cd-confirm'))[0]

        await view.click(buttons(view, 'Approve')[0])
        await view.click(confirm())
        assert.equal(visits.at(-1).method, 'post')
        assert.equal(visits.at(-1).url, '/guards/roster/claims/31/approve')
        visits.at(-1).options.onFinish()
        await nextTick()

        await view.click(buttons(view, 'Decline')[0])
        await view.click(confirm())
        assert.equal(visits.at(-1).url, '/guards/roster/claims/31/decline')
        view.unmount()
    })
})
