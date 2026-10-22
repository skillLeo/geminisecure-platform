/**
 * Answering a guard's request for a shift asks first — `Pages/Gemini/Operations/Schedule.vue` and the classic
 * `Roster.vue` (DR-075, Part 7's rule; the words are `lib/claimQuestion.js`).
 *
 *   Approve put the guard on the shift and told them, and told every other guard who asked for it no, at the first
 *   press; Decline told the guard no at the first press. A control that starts contact confirms first, naming who is
 *   told and how: the question names the guard and the shift, the other guards on the same shift by name (and nobody
 *   on another shift), and only the dialog's own button answers.
 */
import { afterEach, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, findAll, mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { flush, geminiShared } from './support/alerts.mjs'

Object.defineProperty(globalThis.navigator, 'onLine', { value: false, configurable: true })
window.addEventListener ??= () => {}
window.removeEventListener ??= () => {}
globalThis.fetch = async () => ({ ok: true, json: async () => ({}) })

const { default: Schedule } = await import('../../resources/js/Pages/Gemini/Operations/Schedule.vue')
const { default: Roster } = await import('../../resources/js/Pages/Gemini/Operations/Roster.vue')

const mounted = []

afterEach(() => {
    mounted.splice(0).forEach((view) => view.unmount())
    visits.length = 0
})

const ZONE = { estate: 'America/Jamaica', estateTag: 'JA', shown: 'America/Jamaica', shownTag: 'JA', mine: false }
const CLIENT = { id: 'phoenixpark', name: 'Phoenix Park Village 1', internal: false }
const WINDOW = 'Tue 20 Oct, 7:00 AM – 5:00 PM JA'
const CLAIMS = [
    { id: 31, shift_id: 93, guard: 'Kemar Blake', employee_number: 'GS-2002', claimed_at: '2026-10-06T15:00:00+00:00', claimed: 'Oct 6, 10:00 AM', post: 'Main Gate', estate: 'Phoenix Park Village 1', day: '2026-10-20', window: WINDOW },
    { id: 32, shift_id: 93, guard: 'Andre Campbell', employee_number: 'GS-2003', claimed_at: '2026-10-06T16:00:00+00:00', claimed: 'Oct 6, 11:00 AM', post: 'Main Gate', estate: 'Phoenix Park Village 1', day: '2026-10-20', window: WINDOW },
    { id: 33, shift_id: 94, guard: 'Ricardo Shaw', employee_number: 'GS-2004', claimed_at: '2026-10-06T17:00:00+00:00', claimed: 'Oct 6, 12:00 PM', post: 'Back Gate', estate: 'Phoenix Park Village 1', day: '2026-10-21', window: 'Wed 21 Oct, 7:00 AM – 5:00 PM JA' },
]

const schedule = async () => {
    resetInertia(geminiShared({ errors: {}, flash: {} }), '/guards/schedule?client=phoenixpark')
    const view = await mount(Schedule, {
        clients: [CLIENT],
        client: CLIENT,
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
        claimsWaiting: CLAIMS,
    })
    mounted.push(view)
    await flush(nextTick)

    return view
}

const roster = async () => {
    resetInertia(geminiShared({ errors: {}, flash: {} }), '/guards/roster?date=2026-10-09')
    const view = await mount(Roster, {
        days: ['Mon 5', 'Tue 6', 'Wed 7', 'Thu 8', 'Fri 9', 'Sat 10', 'Sun 11'],
        week: 'Oct 5 – Oct 11, 2026',
        groups: [],
        previousWeek: '2026-09-28',
        nextWeek: '2026-10-12',
        thisWeek: true,
        kpis: [],
        scoped: false,
        day: { date: '2026-10-09', previous: '2026-10-08', next: '2026-10-10', rows: [], open: 0 },
        dayRequested: true,
        weekDates: ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'],
        canWrite: true,
        writeBlockedReason: 'needs Guard workforce update access',
        claimsWaiting: CLAIMS,
        shiftPanels: { phoenixpark: { client: CLIENT, zone: ZONE, posts: [] } },
    })
    mounted.push(view)
    await flush(nextTick)

    return view
}

const pressFirst = (view, label) => view.click(view.all((el) => el.tag === 'button' && textOf(el).trim() === label)[0])
const asking = (view) => view.all((el) => el.attrs?.role === 'dialog').find((d) => /^(Put|Decline) /.test(textOf(findAll(d, byClass('cd-title'))[0] ?? d).trim()))
const inAsk = (view, cls) => findAll(asking(view), byClass(cls))[0]
const posts = () => visits.filter((v) => v.method === 'post')

describe('answering a guard\'s request for a shift asks first (DR-075)', () => {
    it('Schedule, Approve: names the guard, the shift and the other guard on it, and nobody on another shift; nothing goes on the first press', async () => {
        const view = await schedule()
        await pressFirst(view, 'Approve')

        assert.equal(posts().length, 0, 'nothing is answered on the first press')
        assert.ok(asking(view), 'a question opens')
        assert.equal(textOf(inAsk(view, 'cd-title')).trim(), `Put Kemar Blake on Main Gate, ${WINDOW}?`)
        const body = textOf(inAsk(view, 'cd-body'))
        assert.match(body, /Kemar Blake is told in the Guard App that the shift is theirs\./)
        assert.match(body, /Andre Campbell, who also asked for it, is told no\./)
        assert.match(body, /Nobody else is told\./)
        assert.doesNotMatch(body, /Ricardo Shaw/, 'a guard who asked for another shift is not told')

        await view.click(inAsk(view, 'cd-confirm'))
        assert.equal(posts().length, 1)
        assert.equal(posts()[0].url, '/guards/roster/claims/31/approve')
    })

    it('Schedule, Decline: names the guard and says the shift stays open to others; Cancel sends nothing, the dialog\'s button declines once', async () => {
        const view = await schedule()
        await pressFirst(view, 'Decline')

        assert.equal(posts().length, 0)
        assert.equal(textOf(inAsk(view, 'cd-title')).trim(), `Decline Kemar Blake for Main Gate, ${WINDOW}?`)
        const body = textOf(inAsk(view, 'cd-body'))
        assert.match(body, /Kemar Blake is told no in the Guard App\. The shift still needs a guard, and any other guard can ask for it\./)
        assert.match(body, /Nobody else is told\./)

        await view.click(inAsk(view, 'cd-cancel'))
        assert.equal(asking(view), undefined)
        assert.equal(posts().length, 0)

        await pressFirst(view, 'Decline')
        await view.click(inAsk(view, 'cd-confirm'))
        assert.equal(posts().length, 1)
        assert.equal(posts()[0].url, '/guards/roster/claims/31/decline')
    })

    it('the classic Roster asks the same question, and only its button approves', async () => {
        const view = await roster()
        await pressFirst(view, 'Approve')

        assert.equal(posts().length, 0, 'nothing is answered on the first press')
        assert.equal(textOf(inAsk(view, 'cd-title')).trim(), `Put Kemar Blake on Main Gate, ${WINDOW}?`)
        assert.match(textOf(inAsk(view, 'cd-body')), /Andre Campbell, who also asked for it, is told no\./)

        await view.click(inAsk(view, 'cd-confirm'))
        assert.equal(posts().length, 1)
        assert.equal(posts()[0].url, '/guards/roster/claims/31/approve')
    })
})
