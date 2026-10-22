/**
 * Leave on the Schedule — `Pages/Gemini/Operations/Schedule.vue` (M30 §8).
 *
 * Approving a guard's leave says first which of the guard's shifts fall in those days, and nothing is chosen for the
 * person approving: the button waits until they pick "Take Kemar Blake off those 3 shifts" or "Leave the shifts as they
 * are" (or, for one shift, "that shift"). What they picked is what is sent. Approved leave is drawn as a band across its
 * days, and opens nothing.
 */
import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { fire, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, geminiShared } from './support/alerts.mjs'

Object.defineProperty(globalThis.navigator, 'onLine', { value: false, configurable: true })
window.addEventListener ??= () => {}
window.removeEventListener ??= () => {}
globalThis.fetch = async () => ({ ok: true, json: async () => ({}) })

const { default: Schedule } = await import('../../resources/js/Pages/Gemini/Operations/Schedule.vue')

const days = ['12', '13', '14', '15', '16', '17', '18'].map((d, i) => ({ date: `2026-10-${d}`, weekday: ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'][i], day: d, month: 'Oct', today: false }))

const WAITING = {
    id: 90,
    guard: { id: 4, name: 'Kemar Blake' },
    subject: 'vacation',
    reason: 'family',
    starts_on: '2026-10-12',
    ends_on: '2026-10-14',
    days: 'Mon 12 Oct to Wed 14 Oct',
    shifts: [
        { id: 1, text: 'Back Gate · Mon 12 Oct · 9:00 AM – 5:00 PM JA' },
        { id: 2, text: 'Back Gate · Tue 13 Oct · 9:00 AM – 5:00 PM JA' },
        { id: 3, text: 'Back Gate · Wed 14 Oct · 9:00 AM – 5:00 PM JA' },
    ],
}

const open = async (over = {}) => {
    resetInertia(geminiShared({ errors: {} }), '/guards/schedule?client=phoenixpark&week=2026-10-12')

    const view = await mount(Schedule, {
        clients: [{ id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false }],
        client: { id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false },
        zone: { estate: 'America/Jamaica', estateTag: 'JA', shown: 'America/Jamaica', shownTag: 'JA', mine: false },
        week: { monday: '2026-10-12', label: 'Mon 12 Oct – Sun 18 Oct 2026', previous: '2026-10-05', next: '2026-10-19', this: '2026-10-05', isThisWeek: false },
        days,
        rows: [{ id: 8, name: 'Back Gate', also_called: null, type: 'gate', cells: [[], [], [], [], [], [], []] }],
        cards: [],
        canWrite: true,
        writeBlockedReason: '',
        leave: [],
        leaveWaiting: [WAITING],
        canDecideLeave: true,
        ...over,
    })
    await flush(nextTick)

    return view
}

const button = (view, label) => view.all((el) => el.tag === 'button' && textOf(el) === label)[0] ?? null

beforeEach(() => {
    socket.reset()
    visits.length = 0
})

describe('leave on the Schedule', () => {
    it('says which of his shifts fall in those days, chooses nothing for the person, and sends what they chose', async () => {
        const view = await open()

        await view.click(button(view, 'Approve'))
        assert.match(view.text(), /Approve Kemar Blake's leave, Mon 12 Oct to Wed 14 Oct\?/)
        assert.match(view.text(), /Kemar Blake is on 3 shifts in those days:/)
        for (const s of WAITING.shifts) assert.ok(view.text().includes(s.text), s.text)
        assert.match(view.text(), /Take Kemar Blake off those 3 shifts and mark them "Needs a guard"/)
        assert.match(view.text(), /Leave the shifts as they are, I will sort them out/)

        const approve = button(view, 'Approve leave')
        assert.ok(approve.attrs.disabled, 'nothing is chosen for the person approving')

        const takeOff = view.one((el) => el.tag === 'input' && el.attrs.type === 'radio' && el.value === 'take_off')
        takeOff.checked = true
        fire(takeOff, 'change')
        await nextTick()

        await view.click(button(view, 'Approve leave'))
        assert.equal(visits.at(-1).url, '/guards/schedule/leave/90')
        assert.deepEqual(visits.at(-1).data, { decision: 'approved', note: null, take_off: true })
        view.unmount()
    })

    it('with one shift in those days, speaks of that one shift', async () => {
        const view = await open({ leaveWaiting: [{ ...WAITING, shifts: WAITING.shifts.slice(0, 1) }] })

        await view.click(button(view, 'Approve'))
        assert.match(view.text(), /Kemar Blake is on 1 shift in those days:/)
        assert.match(view.text(), /Take Kemar Blake off that shift and mark it "Needs a guard"/)
        assert.match(view.text(), /Leave that shift as it is, I will sort it out/)
        assert.doesNotMatch(view.text(), /shifts as they are|those 1 shifts/)
        view.unmount()
    })

    it('declines only with a reason, which is sent', async () => {
        const view = await open()

        await view.click(button(view, 'Decline'))
        assert.ok(button(view, 'Decline leave').attrs.disabled)

        await view.type(view.one((el) => el.tag === 'textarea'), 'Two others are away that week')
        await view.click(button(view, 'Decline leave'))
        assert.deepEqual(visits.at(-1).data, { decision: 'denied', note: 'Two others are away that week', take_off: false })
        view.unmount()
    })

    it('draws approved leave as a band across its days, which opens nothing', async () => {
        const view = await open({ leaveWaiting: [], leave: [{ id: 91, name: 'Kemar Blake', short: 'K. Blake', from: 0, to: 2, days: 'Mon 12 Oct to Wed 14 Oct' }] })

        const band = view.one((el) => el.tag === 'span' && el.className.includes('sch-band'))
        assert.equal(textOf(band), 'K. Blake on leave')
        assert.deepEqual(band.attrs.style, { gridColumn: '1 / 4' })
        assert.equal(band.handlers.click, undefined, 'a band is not a shift')
        view.unmount()
    })
})
