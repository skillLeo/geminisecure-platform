/**
 * WHAT A LIVE EVENT DOES TO A SCREEN (M11 Part C, design §6.2, §6.3, §4.3, §4.4).
 *
 *   liveList     a row changed elsewhere is upserted IN PLACE; a null answer removes it; a
 *                new row inside the window is inserted (the last row trimmed), one outside
 *                it counts towards the "N new · show" pill; a row being worked on is not
 *                moved or removed but marked "changed elsewhere"; membership is the server's
 *   refetches    one record is never asked for twice at once: a second event while the
 *                first answer is out becomes one rerun after it, so a stale answer can never
 *                land on top of a newer one
 *   counters and tiles move without a reload
 *   v-live-flash the quiet highlight: var(--navy-100) fading out, keyed by record; a static
 *                tint and no animation under reduced motion; never for this tab's own write
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { defineComponent, h } from 'vue'
import { mount, nextTick } from './support/render.mjs'
import { http, page, resetInertia, visits } from './support/inertia.mjs'
import { liveServer, liveSocket } from './support/live-socket.mjs'

const { liveCounter, liveList, liveRecord, liveTiles, startLive, stopLive, useLive } = await import('../../resources/js/live/index.js')
const store = await import('../../resources/js/live/store.js')
const { vLiveFlash } = await import('../../resources/js/directives/liveFlash.js')

const T0 = Date.parse('2026-10-02T14:02:31Z')
const CHANNEL = 'estate.pp.facilities'
const ROWS_URL = '/estate/pp/facilities/maintenance/live'

const opened = new Set()

async function settle(turns = 30) {
    for (let i = 0; i < turns; i++) {
        await Promise.resolve()
    }

    await nextTick()
}

async function tick(ms) {
    let left = ms

    do {
        const step = Math.min(left, 100)
        mock.timers.tick(step)
        left -= step
        await settle()
    } while (left > 0)
}

/** A page bound to CHANNEL, with the shell started and every channel live. */
async function livePage(props, specs, options = {}) {
    resetInertia({ ...props, live: { channels: [CHANNEL], heads: { c: 100, e: 50 }, bindings: Object.keys(specs), kind: 'data' } }, '/estate/pp/facilities/maintenance')
    startLive({ shell: ['user.7'], heads: { c: 100, e: 50 }, principal: 'u-7', build: 'build-1' })

    let handle = null
    const Page = defineComponent({
        setup() {
            handle = useLive(page.props.live, specs, options)

            return () => h('div', `${handle.status}`)
        },
    })

    const view = await mount(Page)
    opened.add(view)
    liveSocket.open()
    await tick(20)
    await tick(50)
    liveSocket.pulse()
    await settle()

    return { view, live: handle }
}

/** An event published by the relay and delivered on the socket. */
async function event(fields, channel = CHANNEL) {
    const env = liveServer.publish(channel, fields)
    liveSocket.deliver(channel, env)
    await settle()

    return env
}

const rowsAsked = () =>
    liveServer.requests
        .filter((request) => request.url.startsWith(ROWS_URL))
        .map((request) => new URL(request.url, 'http://estate.test'))

/** The rows endpoint answers with these rows (by key), as the page's own endpoint would. */
const answerRows = (rows, extra = {}) => liveServer.routes.set(ROWS_URL, () => [200, { rows, as_of: new Date().toISOString(), ...extra }])

const ids = (list) => list.map((row) => row.id)

beforeEach(() => {
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: T0 })
    liveServer.reset()
    liveServer.heads = { c: 100, e: 50 }
    liveServer.install()
    liveSocket.reset()
})

afterEach(async () => {
    for (const view of opened) {
        view.unmount()
    }

    opened.clear()
    stopLive()
    await settle()
    mock.timers.reset()
})

const tickets = () => [
    { id: 10, status: 'open', reported: 30 },
    { id: 11, status: 'open', reported: 20 },
    { id: 12, status: 'open', reported: 10 },
]

const newestFirst = (a, b) => b.reported - a.reported

describe('liveList', () => {
    it('upserts a changed row in place, asking only for that row, with the page\'s filter', async () => {
        answerRows({ 11: { id: 11, status: 'resolved', reported: 20 } })
        await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL, query: () => ({ status: 'open', page: 1 }) }),
        })

        await event({ i: '11' })
        await tick(100)

        assert.deepEqual(ids(page.props.rows), [10, 11, 12])
        assert.equal(page.props.rows[1].status, 'resolved')

        const [asked] = rowsAsked()
        assert.deepEqual(asked.searchParams.getAll('ids[]'), ['11'])
        assert.equal(asked.searchParams.get('status'), 'open')
        assert.equal(asked.searchParams.get('page'), '1')
    })

    it('removes a row the server answers null for: deleted, or no longer in this filter', async () => {
        answerRows({ 11: null })
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })

        await event({ i: '11' })
        await tick(100)

        assert.deepEqual(ids(page.props.rows), [10, 12])
    })

    it('asks once for several rows changed within 100 ms', async () => {
        answerRows({ 10: { id: 10, status: 'x', reported: 30 }, 12: { id: 12, status: 'y', reported: 10 } })
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })

        await event({ i: '10' })
        await tick(50)
        await event({ i: '12' })
        await tick(100)

        assert.equal(rowsAsked().length, 1)
        assert.deepEqual(rowsAsked()[0].searchParams.getAll('ids[]').sort(), ['10', '12'])
    })

    it('inserts a new row where it sorts, inside the window, and trims the last', async () => {
        answerRows({ 13: { id: 13, status: 'open', reported: 25 } })
        const { live } = await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL, compare: newestFirst, pageSize: 3 }),
        })

        await event({ i: '13' })
        await tick(100)

        assert.deepEqual(ids(page.props.rows), [10, 13, 11])
        assert.equal(live.rows.pill, 0)
    })

    it('counts a new row that sorts past a full window towards the pill, and does not insert it', async () => {
        answerRows({ 14: { id: 14, status: 'open', reported: 5 } })
        const { live } = await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL, compare: newestFirst, pageSize: 3 }),
        })

        await event({ i: '14' })
        await tick(100)

        assert.deepEqual(ids(page.props.rows), [10, 11, 12])
        assert.equal(live.rows.pill, 1)
    })

    it('counts a new row that belongs above page 2\'s first row towards the pill', async () => {
        answerRows({ 15: { id: 15, status: 'open', reported: 99 } })
        const { live } = await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL, compare: newestFirst, pageSize: 3, firstPage: () => false }),
        })

        await event({ i: '15' })
        await tick(100)

        assert.deepEqual(ids(page.props.rows), [10, 11, 12])
        assert.equal(live.rows.pill, 1)
    })

    it('counts one row once, however many events it gets, and forgets it when it goes', async () => {
        const { live } = await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL, compare: newestFirst, pageSize: 3 }),
        })

        answerRows({ 14: { id: 14, status: 'open', reported: 5 } })
        await event({ i: '14' })
        await tick(100)
        await event({ i: '14' })
        await tick(100)
        assert.equal(live.rows.pill, 1)

        answerRows({ 14: null })
        await event({ i: '14' })
        await tick(100)
        assert.equal(live.rows.pill, 0)
    })

    it('reloads only this list when the reader presses "show" — never on its own', async () => {
        answerRows({ 14: { id: 14, status: 'open', reported: 5 } })
        const { live } = await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL, compare: newestFirst, pageSize: 3 }),
        })

        await event({ i: '14' })
        await tick(100)
        assert.equal(visits.filter((visit) => visit.method === 'reload').length, 0)

        live.rows.showNew()

        const reloads = visits.filter((visit) => visit.method === 'reload')
        assert.equal(reloads.length, 1)
        assert.deepEqual(reloads[0].options.only, ['rows'])
        assert.equal(live.rows.pill, 0)
    })

    it('does not move or remove a row being worked on: it is marked "changed elsewhere" until released', async () => {
        let editing = 11
        answerRows({ 11: null })
        const { live } = await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL, hold: (row) => row.id === editing }),
        })

        await event({ i: '11' })
        await tick(100)

        assert.deepEqual(ids(page.props.rows), [10, 11, 12])
        assert.equal(live.rows.changedElsewhere(11), true)

        // The reader closes the form: the next live change applies what was held.
        editing = null
        answerRows({ 10: { id: 10, status: 'x', reported: 30 } })
        await event({ i: '10' })
        await tick(100)

        assert.deepEqual(ids(page.props.rows), [10, 12])
        assert.equal(live.rows.changedElsewhere(11), false)
    })

    it('releases a held change on request', async () => {
        answerRows({ 11: { id: 11, status: 'resolved', reported: 20 } })
        const { live } = await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL, hold: () => true }),
        })

        await event({ i: '11' })
        await tick(100)
        assert.equal(page.props.rows[1].status, 'open')

        live.rows.release(11)
        assert.equal(page.props.rows[1].status, 'resolved')
    })

    it('does not move a row the reader has the focus inside', async () => {
        answerRows({ 11: null })
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })

        const input = { tag: 'input' }
        const row = { classList: { add() {}, remove() {} }, contains: (el) => el === input }
        vLiveFlash.mounted(row, { arg: 'ticket', value: 11 })
        globalThis.document.activeElement = input

        try {
            await event({ i: '11' })
            await tick(100)

            assert.deepEqual(ids(page.props.rows), [10, 11, 12])
        } finally {
            globalThis.document.activeElement = null
            vLiveFlash.beforeUnmount(row)
        }
    })

    it('applies an inline row as it comes, with no request', async () => {
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'gate_event', rowsUrl: ROWS_URL }) })

        await event({ e: 'gate_event', i: '11', m: 'inline', d: { id: 11, status: 'admitted', reported: 20 } })
        await tick(100)

        assert.equal(page.props.rows[1].status, 'admitted')
        assert.equal(rowsAsked().length, 0)
    })

    it('ignores events of other entities, and of other types when it names its types', async () => {
        answerRows({ 11: null })
        await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', types: ['ticket.resolved'], rowsUrl: ROWS_URL }),
        })

        await event({ e: 'booking', i: '11' })
        await event({ t: 'ticket.updated', i: '11' })
        await tick(200)

        assert.equal(rowsAsked().length, 0)
        assert.deepEqual(ids(page.props.rows), [10, 11, 12])
    })

    it('on a reset, asks again for every row it shows and says the list may be missing some', async () => {
        const { live } = await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })
        answerRows({ 10: { id: 10, status: 'x', reported: 30 }, 11: null, 12: { id: 12, status: 'y', reported: 10 } })

        liveServer.resets.add(CHANNEL)
        store.requestCatchUp([CHANNEL], 0, 'test')
        await tick(200)

        assert.deepEqual(rowsAsked()[0].searchParams.getAll('ids[]').sort(), ['10', '11', '12'])
        assert.deepEqual(ids(page.props.rows), [10, 12])
        assert.equal(live.rows.stale, true)
        assert.equal(visits.filter((visit) => visit.method === 'reload').length, 0)
    })

    it('drops its "N new" count and its held changes when the server replaces the list while the page stays', async () => {
        const { live } = await livePage({ rows: tickets() }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL, compare: newestFirst, pageSize: 3, hold: (row) => row.id === 11 }),
        })

        answerRows({ 14: { id: 14, status: 'open', reported: 5 }, 11: { id: 11, status: 'resolved', reported: 20 } })
        await event({ i: '14' })
        await event({ i: '11' })
        await tick(100)
        assert.equal(live.rows.pill, 1)
        assert.equal(live.rows.changedElsewhere(11), true)

        // A write that redirects back: the page stays, and its list and live props are the server's again.
        liveServer.routes.delete(ROWS_URL)
        page.props.rows = [{ id: 14, status: 'open', reported: 5 }, ...tickets()].sort(newestFirst).slice(0, 3)
        page.props.live = { ...page.props.live, heads: { c: 100, e: liveServer.heads.e } }
        await tick(100)

        assert.equal(live.rows.pill, 0)
        assert.equal(live.rows.changedElsewhere(11), false)
        assert.equal(live.rows.stale, false)
    })

    it('hands the tiles its endpoint answered with to the tiles that read from it', async () => {
        answerRows({ 11: { id: 11, status: 'resolved', reported: 20 } }, { tiles: { open: 2 } })
        await livePage({ rows: tickets(), kpis: { open: 3, overdue: 1 } }, {
            rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }),
            kpis: liveTiles('kpis', { from: 'rows' }),
        })

        await event({ i: '11' })
        await tick(100)

        assert.deepEqual(page.props.kpis, { open: 2, overdue: 1 })
    })
})

describe('one record is never asked for twice at once', () => {
    it('turns an event that lands while the record is being asked for into one rerun after it', async () => {
        const answers = []
        let version = 1
        liveServer.routes.set('/estate/pp/tickets/55/live', () => new Promise((resolve) => {
            const mine = version
            answers.push(() => resolve([200, { record: { number: 55, status: `v${mine}` } }]))
        }))

        await livePage({ ticket: { number: 55, status: 'v0' } }, {
            ticket: liveRecord('ticket', { entity: 'ticket', id: () => 55, url: '/estate/pp/tickets/55/live' }),
        })

        await event({ i: '55' })
        await tick(100)
        assert.equal(answers.length, 1)

        // It changes again while the first answer is out: no second request yet.
        version = 2
        await event({ i: '55' })
        await tick(300)
        assert.equal(answers.length, 1)

        answers[0]()
        await settle()
        assert.equal(page.props.ticket.status, 'v1')

        await tick(100)
        assert.equal(answers.length, 2)
        answers[1]()
        await settle()

        assert.equal(page.props.ticket.status, 'v2')
    })

    it('never lets an older answer overwrite a newer one for a list row either', async () => {
        const answers = []
        let version = 1
        liveServer.routes.set(ROWS_URL, () => new Promise((resolve) => {
            const mine = version
            answers.push(() => resolve([200, { rows: { 11: { id: 11, status: `v${mine}`, reported: 20 } } }]))
        }))
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })

        await event({ i: '11' })
        await tick(100)
        version = 2
        await event({ i: '11' })
        await tick(100)
        assert.equal(answers.length, 1, 'no second request while the first is out')

        answers[0]()
        await settle()
        assert.equal(page.props.rows[1].status, 'v1')

        await tick(100)
        assert.equal(answers.length, 2, 'then exactly one rerun')
        answers[1]()
        await settle()

        assert.equal(page.props.rows[1].status, 'v2')
    })

    it('gives up on a row read that never answers after 10 s, so the row is not frozen behind it', async () => {
        let hang = true
        liveServer.routes.set(ROWS_URL, (url, request) => {
            if (hang) {
                hang = false

                // A connection that died without saying so: no answer, until the client drops it.
                return liveServer.wait(request).then(() => [200, { rows: {} }])
            }

            return [200, { rows: { 11: { id: 11, status: 'resolved', reported: 20 } } }]
        })
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })

        await event({ i: '11' })
        await tick(100)
        await event({ i: '11' })
        await tick(9_000)
        assert.equal(rowsAsked().length, 1, 'the second change waits for the first read, as the per-record rule says')

        await tick(1_200)
        const reads = liveServer.requests.filter((request) => request.url.startsWith(ROWS_URL))
        assert.equal(reads[0].signal?.aborted, true, 'given up on at 10 s')
        assert.equal(reads.length, 2)
        assert.equal(page.props.rows[1].status, 'resolved')
    })

    it('marks a record gone when its endpoint answers null, and leaves the page to say so', async () => {
        liveServer.routes.set('/estate/pp/tickets/55/live', () => [200, { record: null }])
        const { live } = await livePage({ ticket: { number: 55 } }, {
            ticket: liveRecord('ticket', { entity: 'ticket', id: () => 55, url: '/estate/pp/tickets/55/live' }),
        })

        await event({ i: '55' })
        await tick(100)

        assert.equal(live.ticket.gone, true)
        assert.deepEqual(page.props.ticket, { number: 55 })
    })

    it('ignores an event for another record', async () => {
        liveServer.routes.set('/estate/pp/tickets/55/live', () => [200, { record: { number: 55 } }])
        await livePage({ ticket: { number: 55 } }, {
            ticket: liveRecord('ticket', { entity: 'ticket', id: () => 55, url: '/estate/pp/tickets/55/live' }),
        })

        await event({ i: '56' })
        await tick(200)

        assert.equal(liveServer.count('/estate/pp/tickets/55/live'), 0)
    })
})

describe('counters and tiles', () => {
    it('moves a counter from its tile, once for a burst of events', async () => {
        liveServer.routes.set('/live/tiles', (url) => [200, { tiles: { open_tickets: 7 } }])
        await livePage({ counts: { open_tickets: 3 } }, {
            open: liveCounter('counts.open_tickets', { entities: ['ticket'], tile: 'open_tickets' }),
        })

        await event({ i: '1' })
        await event({ i: '2' })
        await event({ i: '3' })
        await tick(300)

        assert.equal(page.props.counts.open_tickets, 7)
        assert.equal(liveServer.count('/live/tiles'), 1)
        assert.deepEqual(new URL(liveServer.last('/live/tiles').url, 'http://x').searchParams.getAll('keys[]'), ['open_tickets'])
    })

    it('asks for its tiles on a tile event for one of them, and not for another', async () => {
        liveServer.routes.set('/live/tiles', () => [200, { tiles: { duress_open: 2, open_maintenance: 9 } }])
        await livePage({ tiles: { duress_open: 0, open_maintenance: 8 } }, {
            tiles: liveTiles('tiles', { keys: ['duress_open', 'open_maintenance'], afterCatchUp: false }),
        })

        await event({ e: 'tile', i: 'someone_elses_tile', t: 'tile.changed' })
        await tick(300)
        assert.equal(liveServer.count('/live/tiles'), 0)

        await event({ e: 'tile', i: 'duress_open', t: 'tile.changed' })
        await tick(300)
        assert.deepEqual(page.props.tiles, { duress_open: 2, open_maintenance: 9 })
    })

    it('asks for its tiles once after a catch-up, as the dashboard\'s resync', async () => {
        liveServer.routes.set('/live/tiles', () => [200, { tiles: { duress_open: 1 } }])
        await livePage({ tiles: { duress_open: 0 } }, { tiles: liveTiles('tiles', { keys: ['duress_open'] }) })

        assert.equal(liveServer.count('/live/tiles'), 0)
        await tick(300)

        assert.equal(liveServer.count('/live/tiles'), 1)
        assert.deepEqual(page.props.tiles, { duress_open: 1 })
    })

    it('leaves a tile it may not see as it was', async () => {
        liveServer.routes.set('/live/tiles', () => [200, { tiles: {} }])
        await livePage({ tiles: { mrr: 'hidden' } }, { tiles: liveTiles('tiles', { keys: ['mrr'], afterCatchUp: false }) })

        await event({ e: 'tile', i: 'mrr', t: 'tile.changed' })
        await tick(300)

        assert.deepEqual(page.props.tiles, { mrr: 'hidden' })
    })
})

describe('the quiet highlight', () => {
    const element = () => {
        const classes = new Set()
        const el = {
            animations: [],
            classList: { add: (name) => classes.add(name), remove: (name) => classes.delete(name), contains: (name) => classes.has(name) },
            animate(keyframes, options) {
                this.animations.push({ keyframes, options })
            },
        }

        return el
    }

    const motion = (reduced) => {
        globalThis.matchMedia = (query) => ({ matches: reduced && query === '(prefers-reduced-motion: reduce)' })
    }

    afterEach(() => {
        delete globalThis.matchMedia
    })

    it('fades var(--navy-100) out over 1.6 s on the row whose record changed elsewhere', async () => {
        motion(false)
        answerRows({ 11: { id: 11, status: 'resolved', reported: 20 } })
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })
        const changed = element()
        const untouched = element()
        vLiveFlash.mounted(changed, { arg: 'ticket', value: 11 })
        vLiveFlash.mounted(untouched, { arg: 'ticket', value: 12 })

        await event({ i: '11' })
        await tick(100)

        assert.equal(changed.animations.length, 1)
        assert.deepEqual(changed.animations[0].keyframes, [{ backgroundColor: 'var(--navy-100)' }, { backgroundColor: 'transparent' }])
        assert.equal(changed.animations[0].options.duration, 1600)
        assert.equal(untouched.animations.length, 0)

        vLiveFlash.beforeUnmount(changed)
        vLiveFlash.beforeUnmount(untouched)
    })

    it('under reduced motion, tints still for 2 s and animates nothing', async () => {
        motion(true)
        answerRows({ 11: { id: 11, status: 'resolved', reported: 20 } })
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })
        const row = element()
        vLiveFlash.mounted(row, { arg: 'ticket', value: 11 })

        await event({ i: '11' })
        await tick(100)

        assert.equal(row.animations.length, 0)
        assert.equal(row.classList.contains('live-changed'), true)

        await tick(1_900)
        assert.equal(row.classList.contains('live-changed'), true)
        await tick(100)
        assert.equal(row.classList.contains('live-changed'), false)

        vLiveFlash.beforeUnmount(row)
    })

    it('does not flash this tab\'s own change', async () => {
        motion(false)
        answerRows({ 11: { id: 11, status: 'resolved', reported: 20 } })
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })
        const row = element()
        vLiveFlash.mounted(row, { arg: 'ticket', value: 11 })

        const write = await http.processRequest({ method: 'post', url: '/estate/pp/tickets/11/resolve', headers: {} })
        await event({ i: '11', o: write.headers['X-Live-Origin'] })
        await tick(100)

        assert.equal(page.props.rows[1].status, 'resolved', 'the change still lands')
        assert.equal(row.animations.length, 0)

        vLiveFlash.beforeUnmount(row)
    })

    it('flashes a row drawn a moment after its record changed, keyed by the record and not the node', async () => {
        motion(false)
        answerRows({ 11: { id: 11, status: 'resolved', reported: 20 } })
        await livePage({ rows: tickets() }, { rows: liveList('rows', { entity: 'ticket', rowsUrl: ROWS_URL }) })

        await event({ i: '11' })
        await tick(100)

        // A virtualised table draws the row now, with a new node.
        const drawnLater = element()
        vLiveFlash.mounted(drawnLater, { arg: 'ticket', value: 11 })
        assert.equal(drawnLater.animations.length, 1)
        vLiveFlash.beforeUnmount(drawnLater)

        // Long after, a new node for it does not flash.
        await tick(2_000)
        const muchLater = element()
        vLiveFlash.mounted(muchLater, { arg: 'ticket', value: 11 })
        assert.equal(muchLater.animations.length, 0)
        vLiveFlash.beforeUnmount(muchLater)
    })
})
