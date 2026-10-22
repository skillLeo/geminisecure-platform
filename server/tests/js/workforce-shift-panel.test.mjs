/**
 * The Schedule's shift panel — `Pages/Gemini/Operations/ShiftPanel.vue` (M30 §5, §6).
 *
 * The panel asks the server what it would say about the shift it describes, and draws the answer: "Leave it open,
 * any guard can ask for it" first, the free guards marked free, everybody else crossed out with why and nothing to choose;
 * a tick or a cross per rule, and a button that cannot be pressed while any line is a cross. The server is stood in
 * for here by `fetch`; what the panel sends and what it draws are the test.
 */
import { beforeEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, fire, mount, nextTick, textOf } from './support/render.mjs'
import { page, resetInertia, visits } from './support/inertia.mjs'

const { default: ShiftPanel } = await import('../../resources/js/Pages/Gemini/Operations/ShiftPanel.vue')

let answer = null
let shiftView = null
const asked = []

globalThis.fetch = async (url, init = {}) => {
    // Opening on a shift reads it; everything else is the panel's question.
    if ((init.method ?? 'GET') === 'GET') {
        asked.push({ url, method: 'GET' })

        return { ok: shiftView !== null, json: async () => shiftView }
    }

    asked.push({ url, method: 'POST', body: JSON.parse(init.body), headers: init.headers })

    return { ok: true, json: async () => answer }
}

const SHIFT = (over = {}) => ({
    id: 55,
    title: 'Main Gate · Fri 9 Oct · 6:00 AM – 6:00 PM JA',
    form: { post_id: 7, date: '2026-10-09', starts: '06:00', ends: '18:00', duty: 'post', guard_id: 1 },
    guard: { id: 1, name: 'Andre Campbell' },
    locked: null,
    started: false,
    can: { change_times: true, change_guard: true, take_off: true, remove: true },
    note: null,
    history: [{ text: 'Added by Dora Director, with Andre Campbell on it', at: 'Sat 3 Oct, 9:12 AM JA', ago: '1 day ago' }],
    ...over,
})

const ANSWER = (over = {}) => ({
    ok: true,
    count: 1,
    hours: '12 hours',
    zone: 'America/Jamaica',
    occurrences: [{ date: '2026-10-09', starts_at: '2026-10-09T11:00:00+00:00', ends_at: '2026-10-09T23:00:00+00:00' }],
    guards: [
        { id: 1, name: 'Andre Campbell', employee_number: 'GS-001', free: true, reason: null },
        { id: 3, name: 'Shanice Clarke', employee_number: 'GS-003', free: true, reason: null },
        { id: 2, name: 'Kadian Walters', employee_number: 'GS-002', free: false, reason: 'on another shift 9:00 AM–5:00 PM JA, Back Gate' },
        { id: 4, name: 'Damion Reid', employee_number: 'GS-004', free: false, reason: 'on leave this day' },
    ],
    checks: [
        { ok: true, text: 'Starts today or later' },
        { ok: true, text: 'Main Gate is free at this time' },
        { ok: true, text: 'Left open: guards at this client can ask for it' },
    ],
    ...over,
})

const PROPS = (over = {}) => ({
    open: true,
    client: { id: 'phoenixpark', name: 'Phoenix Park Village 1' },
    zone: { estate: 'America/Jamaica', estateTag: 'JA', shown: 'America/Jamaica', shownTag: 'JA', mine: false },
    posts: [
        { id: 7, name: 'Main Gate', also_called: 'Gate 1', type: 'gate' },
        { id: 8, name: 'North Patrol', also_called: null, type: 'patrol' },
    ],
    start: { post_id: 7, date: '2026-10-09' },
    ...over,
})

// The panel waits a moment after each change before it asks; then the answer is drawn.
const settle = async () => {
    await new Promise((resolve) => setTimeout(resolve, 360))
    await nextTick()
}

const button = (view, label) => view.all((el) => el.tag === 'button' && textOf(el) === label)[0] ?? null
const rows = (view) => view.one(byClass('sp-list')).children.filter((el) => el.className?.split(/\s+/).includes('sp-option'))

// A row as it reads: its parts (name, "free", the reason), each in its own place.
const reads = (row) => row.children.map(textOf).filter((part) => part !== '').join(' ')

beforeEach(() => {
    resetInertia({ errors: {}, flash: {} }, '/guards/schedule?client=phoenixpark')
    asked.length = 0
    answer = ANSWER()
    shiftView = null
})

describe('the shift panel', () => {
    it('asks the server about the shift it describes, in the client\'s time, with the gate and day it was opened on', async () => {
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        const last = asked.at(-1)
        assert.equal(last.url, '/guards/schedule/check')
        assert.deepEqual(
            { post_id: last.body.post_id, date: last.body.date, starts: last.body.starts, ends: last.body.ends, duty: last.body.duty, guard_id: last.body.guard_id, repeat: last.body.repeat },
            { post_id: 7, date: '2026-10-09', starts: '06:00', ends: '18:00', duty: 'post', guard_id: null, repeat: 'once' },
        )
        assert.equal(last.headers['Content-Type'], 'application/json')
        assert.match(view.text(), /Starts \(JA\)/)
        // Worked out as it is typed (M35 §3), on the client's clock — and the viewer's, where that differs. A day shift
        // ends the day it starts, so nothing says when it ends.
        assert.match(view.text(), /12 hours, 6:00 AM to 6:00 PM JA/)
        assert.doesNotMatch(view.text(), /Ends \d+ \w+, /)
        view.unmount()
    })

    it('lists "Leave it open, any guard can ask for it" first, then the free guards marked free, then the rest crossed out with why and nothing to choose', async () => {
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        const list = rows(view)
        assert.deepEqual(list.map(reads), [
            'Leave it open, any guard can ask for it',
            'Andre Campbell free',
            'Shanice Clarke free',
            '✗ Kadian Walters on another shift 9:00 AM–5:00 PM JA, Back Gate',
            '✗ Damion Reid on leave this day',
        ])

        const radios = (el) => el.children.filter((child) => child.tag === 'input')
        assert.equal(radios(list[0]).length, 1)
        assert.equal(radios(list[1]).length, 1)
        assert.equal(radios(list[3]).length, 0, 'a guard who cannot stand it has nothing to choose')
        assert.equal(radios(list[4]).length, 0)
        assert.ok(list[3].className.includes('sp-blocked'))
        view.unmount()
    })

    it('searches by name and by employee number, and the open choice never leaves the list', async () => {
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        const search = view.one((el) => el.tag === 'input' && el.attrs.type === 'search')
        await view.type(search, 'GS-002')
        assert.deepEqual(rows(view).map(reads), ['Leave it open, any guard can ask for it', '✗ Kadian Walters on another shift 9:00 AM–5:00 PM JA, Back Gate'])

        await view.type(search, 'shan')
        assert.deepEqual(rows(view).map(reads), ['Leave it open, any guard can ask for it', 'Shanice Clarke free'])
        view.unmount()
    })

    // M32 F4: the same search as the register's — a guard's phone however it is typed, or stored.
    it('finds a guard by phone, spaced, run together or with the country code, and never by a lot-sized number', async () => {
        answer = ANSWER({
            guards: [
                { id: 1, name: 'Andre Campbell', employee_number: 'GS-001', phone: '+1 876 555 0101', free: true, reason: null },
                { id: 3, name: 'Shanice Clarke', employee_number: 'GS-003', phone: '876-555-0303', free: true, reason: null },
                { id: 2, name: 'Kadian Walters', employee_number: 'GS-002', phone: null, free: false, reason: 'on another shift 9:00 AM–5:00 PM JA, Back Gate' },
            ],
        })
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        const search = view.one((el) => el.tag === 'input' && el.attrs.type === 'search')
        for (const typed of ['876 555 0303', '8765550303', '+18765550303']) {
            await view.type(search, typed)
            assert.deepEqual(rows(view).map(reads), ['Leave it open, any guard can ask for it', 'Shanice Clarke free'], typed)
        }

        // Three digits are a lot or a post, not a phone: nobody's number is searched for them.
        await view.type(search, '555')
        assert.deepEqual(rows(view).map(reads), ['Leave it open, any guard can ask for it'])
        view.unmount()
    })

    it('cannot be pressed while any line is a cross, and says how many shifts it will add', async () => {
        answer = ANSWER({ ok: false, checks: [{ ok: false, text: 'Main Gate already has a shift at that time: Fri 9 Oct, 10:00 AM to 2:00 PM JA' }, { ok: true, text: 'Starts today or later' }] })
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        assert.match(view.text(), /✗ Main Gate already has a shift at that time: Fri 9 Oct, 10:00 AM to 2:00 PM JA/)
        assert.ok(button(view, 'Add shift').attrs.disabled, 'a cross keeps the button down')

        answer = ANSWER({ count: 3 })
        await view.type(view.all((el) => el.tag === 'input' && el.attrs.type === 'time')[0], '07:00')
        await settle()

        const add = button(view, 'Add 3 shifts')
        assert.ok(add, 'the label names the count')
        assert.equal(add.attrs.disabled, undefined)
        view.unmount()
    })

    it('adds through the server, shows its refusal in its own words, and closes only on success', async () => {
        let closed = 0
        const view = await mount(ShiftPanel, { ...PROPS(), onClose: () => closed++ })
        await settle()

        await view.click(button(view, 'Add shift'))
        const visit = visits.at(-1)
        assert.equal(visit.method, 'post')
        assert.equal(visit.url, '/guards/schedule/shifts')
        assert.equal(visit.data.post_id, 7)

        visit.options.onError({ panel: 'Kadian Walters is on another shift: Back Gate, Fri 9 Oct, 9:00 AM to 5:00 PM JA' })
        visit.options.onFinish()
        await nextTick()
        assert.match(view.text(), /Kadian Walters is on another shift: Back Gate, Fri 9 Oct, 9:00 AM to 5:00 PM JA/)
        assert.equal(closed, 0)

        await view.click(button(view, 'Add shift'))
        page.props.errors = {}
        visits.at(-1).options.onSuccess()
        visits.at(-1).options.onFinish()
        assert.equal(closed, 1)
        view.unmount()
    })

    it('opens a patrol start on "Starts a patrol here"', async () => {
        const view = await mount(ShiftPanel, PROPS({ start: { post_id: 8, date: '2026-10-09' } }))
        await settle()

        assert.equal(asked.at(-1).body.duty, 'patrol')
        view.unmount()
    })

    it('repeats on the chosen days of the week', async () => {
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        const radio = view.one((el) => el.tag === 'input' && el.attrs.type === 'radio' && el.value === 'days')
        radio.checked = true
        fire(radio, 'change')
        await nextTick()

        await view.click(view.one((el) => el.tag === 'button' && el.attrs['aria-label'] === 'Monday'))
        await view.click(view.one((el) => el.tag === 'button' && el.attrs['aria-label'] === 'Friday'))
        await settle()

        assert.equal(asked.at(-1).body.repeat, 'days')
        assert.deepEqual(asked.at(-1).body.days, [1, 5])
        view.unmount()
    })
})

describe('the shift panel, opened on a shift', () => {
    const radioFor = (view, name) => rows(view).find((row) => reads(row).startsWith(name))?.children.find((el) => el.tag === 'input')

    it('opens with the shift as it is, its guard first, and its history; nothing to save until something changes', async () => {
        shiftView = SHIFT()
        answer = ANSWER({ guards: [{ id: 1, name: 'Andre Campbell', employee_number: 'GS-001', free: true, reason: null, asked: false }, ...ANSWER().guards.slice(1)] })
        const view = await mount(ShiftPanel, PROPS({ shiftId: 55 }))
        await settle()

        assert.equal(asked[0].url, '/guards/schedule/shifts/55')
        const question = asked.at(-1)
        assert.equal(question.body.shift_id, 55)
        assert.equal(question.body.guard_id, 1)
        assert.match(view.text(), /Main Gate · Fri 9 Oct · 6:00 AM – 6:00 PM JA/)
        assert.match(view.text(), /Added by Dora Director, with Andre Campbell on it · Sat 3 Oct, 9:12 AM JA · 1 day ago/)
        assert.equal(reads(rows(view)[1]), 'Andre Campbell on it now')
        assert.ok(button(view, 'Save changes').attrs.disabled, 'nothing has changed yet')
        assert.equal(view.all((el) => el.tag === 'fieldset' && textOf(el).startsWith('Repeat')).length, 0, 'a shift being changed does not repeat')
        view.unmount()
    })

    it('asks why, in one line, when the guard on it is replaced, and sends the reason with the change', async () => {
        shiftView = SHIFT()
        const view = await mount(ShiftPanel, PROPS({ shiftId: 55 }))
        await settle()

        const shanice = radioFor(view, 'Shanice Clarke')
        shanice.checked = true
        fire(shanice, 'change')
        await nextTick()
        assert.match(view.text(), /Why is Andre Campbell coming off this shift\? Their phone is told\./)

        const why = view.one((el) => el.tag === 'input' && el.attrs.placeholder === 'e.g. swapped with Kemoy, as asked')
        await view.type(why, 'Asked to swap')
        await settle()
        assert.equal(asked.at(-1).body.reason, 'Asked to swap')
        assert.equal(asked.at(-1).body.guard_id, 3)

        await view.click(button(view, 'Save changes'))
        assert.equal(visits.at(-1).url, '/guards/schedule/shifts/55')
        assert.equal(visits.at(-1).data.reason, 'Asked to swap')
        assert.equal(visits.at(-1).data.guard_id, 3)
        view.unmount()
    })

    it('takes the guard off, or removes the shift, only with a reason and a one-line confirmation naming it', async () => {
        shiftView = SHIFT()
        const view = await mount(ShiftPanel, PROPS({ shiftId: 55 }))
        await settle()

        await view.click(button(view, 'Take the guard off this shift'))
        assert.match(view.text(), /Take Andre Campbell off this shift\? It will need a guard, and their phone is told\./)
        assert.ok(button(view, 'Take them off').attrs.disabled, 'no reason, nothing to press')

        await view.type(view.one((el) => el.tag === 'input' && el.attrs.placeholder === 'Why, in a few words'), 'Off sick')
        await view.click(button(view, 'Take them off'))
        assert.equal(visits.at(-1).url, '/guards/schedule/shifts/55/take-off')
        assert.deepEqual(visits.at(-1).data, { reason: 'Off sick' })
        visits.at(-1).options.onFinish()
        await nextTick()

        await view.click(button(view, 'Keep them on'))
        await view.click(button(view, 'Remove this shift'))
        assert.match(view.text(), /Remove Main Gate, Fri 9 Oct, 6:00 AM – 6:00 PM JA\? Andre Campbell's phone is told\./)
        await view.type(view.one((el) => el.tag === 'input' && el.attrs.placeholder === 'Why, in a few words'), 'Gate closed')
        await view.click(button(view, 'Remove'))
        assert.equal(visits.at(-1).url, '/guards/schedule/shifts/55/remove')
        assert.deepEqual(visits.at(-1).data, { reason: 'Gate closed' })
        view.unmount()
    })

    it('opens a shift being worked to read it: why it cannot change, nothing to press but Close, nothing asked', async () => {
        shiftView = SHIFT({ locked: 'Andre Campbell has clocked in to this shift. Nothing on it changes until they clock out.', can: { change_times: false, change_guard: false, take_off: false, remove: false } })
        const view = await mount(ShiftPanel, PROPS({ shiftId: 55 }))
        await settle()

        assert.match(view.text(), /Andre Campbell has clocked in to this shift\. Nothing on it changes until they clock out\./)
        assert.equal(button(view, 'Save changes'), null)
        assert.ok(button(view, 'Close'))
        assert.ok(view.one((el) => el.tag === 'select').attrs.disabled)
        assert.deepEqual(asked.map((a) => a.method), ['GET'], 'a shift that cannot change is not checked')
        view.unmount()
    })

    it('opens for a role that only reads the schedule, saying so, with nothing to change', async () => {
        shiftView = SHIFT()
        const view = await mount(ShiftPanel, PROPS({ shiftId: 55, canWrite: false }))
        await settle()

        assert.match(view.text(), /Your role can see this shift\. Changing it is for a role that plans the workforce\./)
        assert.match(view.text(), /Who stands it ?Andre Campbell/)
        assert.equal(button(view, 'Take the guard off this shift'), null)
        assert.deepEqual(asked.map((a) => a.method), ['GET'])
        view.unmount()
    })
})

describe('the shift panel, preventing rather than refusing (M35 §3)', () => {
    it('says what may be posted in one line before anything is typed, and the reasons behind an i', async () => {
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        assert.match(view.text(), /Shifts are posted from today onward, up to 24 hours each\. A night shift ending the next morning is fine\./)
        assert.doesNotMatch(view.text(), /never into the past/, 'the long reasons are not on the form')

        await view.click(view.one((el) => el.tag === 'button' && el.attrs['data-info-button'] !== undefined))
        assert.match(view.text(), /A shift is posted forward from today at the estate, never into the past/)
        view.unmount()
    })

    it('offers no date before today at the client, and no repeat ending before its first day', async () => {
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Jamaica', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
        const [first, ...untils] = view.all((el) => el.tag === 'input' && el.attrs.type === 'date')

        assert.equal(first.attrs.min, today)
        assert.ok(untils.length > 0)
        assert.ok(untils.every((until) => until.attrs.min === '2026-10-09'), 'a repeat never ends before its first day')
        view.unmount()
    })

    it('says a shift ending earlier in the day ends the next morning, with its length, and sends it as typed', async () => {
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        const [starts, ends] = view.all((el) => el.tag === 'input' && el.attrs.type === 'time')
        await view.type(starts, '18:00')
        await view.type(ends, '07:00')

        // At once — before the server has answered: its length on the client's clock, and the quiet line saying it
        // ends the next morning.
        assert.match(view.text(), /13 hours, 6:00 PM to 7:00 AM JA/)
        assert.match(view.text(), /Ends 10 Oct, 7:00 AM JA — 13 hours/)

        await settle()
        assert.deepEqual([asked.at(-1).body.starts, asked.at(-1).body.ends], ['18:00', '07:00'], 'the server reads it as the next morning too')
        view.unmount()
    })

    it('asks why an open shift is open, and sends it with the shift — read by whoever picks it up', async () => {
        const view = await mount(ShiftPanel, PROPS())
        await settle()

        await view.type(view.one((el) => el.tag === 'input' && el.attrs.placeholder === 'Optional, e.g. covering a guard on leave'), 'Covering leave')
        await view.click(button(view, 'Add shift'))

        assert.equal(visits.at(-1).url, '/guards/schedule/shifts')
        assert.equal(visits.at(-1).data.guard_id, null)
        assert.equal(visits.at(-1).data.reason, 'Covering leave')
        view.unmount()
    })
})

describe('the shift panel, opened from the classic roster (M35 §2)', () => {
    it('opens straight at "Remove this shift" from a row\'s Remove, naming the post, the day and the time', async () => {
        shiftView = SHIFT()
        const view = await mount(ShiftPanel, PROPS({ shiftId: 55, act: 'remove' }))
        await settle()

        assert.match(view.text(), /Remove Main Gate, Fri 9 Oct, 6:00 AM – 6:00 PM JA\? Andre Campbell's phone is told\./)
        assert.ok(button(view, 'Remove').attrs.disabled, 'a reason first')
        view.unmount()
    })

    it('does not open at Remove a shift that cannot be removed', async () => {
        shiftView = SHIFT({ can: { change_times: false, change_guard: true, take_off: true, remove: false }, started: true })
        const view = await mount(ShiftPanel, PROPS({ shiftId: 55, act: 'remove' }))
        await settle()

        assert.doesNotMatch(view.text(), /Remove Main Gate/)
        assert.equal(button(view, 'Remove this shift'), null)
        view.unmount()
    })

    it('offers the roster\'s clients to add a shift at, and says which one was chosen', async () => {
        const chosen = []
        const view = await mount(ShiftPanel, {
            ...PROPS({ clients: [{ id: 'phoenixpark', name: 'Phoenix Park Village 1' }, { id: 'oceanview', name: 'Ocean View' }] }),
            onClient: (id) => chosen.push(id),
        })
        await settle()

        const select = view.all((el) => el.tag === 'select')[0]
        assert.deepEqual(select.children.filter((o) => o.tag === 'option').map(textOf), ['Phoenix Park Village 1', 'Ocean View'])
        select.value = 'oceanview'
        fire(select, 'change')
        assert.deepEqual(chosen, ['oceanview'])
        view.unmount()
    })
})
