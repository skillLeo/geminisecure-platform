/**
 * A panic on the estate's own console — `Layouts/EstateConsole.vue` and `useEstatePanic`.
 *
 *   B039 / B103  the blinking bar outlives the page: it survives a sidebar link and the pop-up's
 *                own "Open Security", a reader who opens the console after the panic sees it
 *                naming the lot, an alert broadcast while the pages swap is not lost, and the
 *                bar stops when the SERVER says the control room acknowledged it;
 *   B110 / B039  a refused, dropped or silent channel is said on every estate page, rather than
 *                looking like a quiet night — and the poll covers for it.
 *
 * Every test mounts the real shell, as each estate page does, with the page's props as the
 * server shares them (`panicWatch`), a stand-in socket, and a stand-in server for the poll.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { byClass, byTag, mount, nextTick } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, RAISED, watchRow } from './support/alerts.mjs'

const { default: EstateConsole } = await import('../../resources/js/Layouts/EstateConsole.vue')

const CHANNEL = 'estate.phoenixpark.alerts'

/** A row as the estate's watch carries it: no Gemini link, and the two facts the pop-up says. */
const row = (overrides = {}) => {
    const { href, estate_name, ...rest } = watchRow({ is_simulated: false, control_room: true, ...overrides })

    return rest
}

const broadcast = (overrides = {}) => ({
    id: 11,
    kind: 'panic',
    kind_label: 'Panic button',
    estate: 'phoenixpark',
    is_urgent: true,
    server_time: new Date(RAISED).toISOString(),
    is_simulated: false,
    is_drill: false,
    alerted_handsets: null,
    alerted_via: null,
    control_room: true,
    call: null,
    unit: 'Lot 47',
    who: 'Jane Brown',
    ...overrides,
})

let user = 5000

/** What the stand-in server answers the estate's poll with: its list of what is outstanding. */
let answer = null
const asked = []
const opened = new Set()

/**
 * An estate page, as the server draws it for this reader: the shell and its shared props. The
 * server's poll answers with the same list until a test says otherwise.
 */
const page = async (alerts = [], { server = alerts, url = '/estate/phoenixpark/residents' } = {}) => {
    answer = { alerts: server, checked_at: new Date(RAISED).toISOString() }

    resetInertia(
        {
            auth: { user: { id: user, name: 'Paula President', role_label: 'President', receives_alerts: true } },
            estateNav: [],
            panicWatch: { estate: 'phoenixpark', alerts, poll: '/security/panic-watch', checked_at: new Date(RAISED).toISOString() },
        },
        url,
    )

    const view = await mount(EstateConsole, { title: 'Residents', estateName: 'Phoenix Park' })
    opened.add(view)
    await flush(nextTick)

    return view
}

/** The reader follows a link: this page's shell is taken down. */
const leave = (view) => {
    view.unmount()
    opened.delete(view)
}

// Booleans, not elements: an element carries its whole tree, and a failure message would print it.
const hasBar = (view) => view.one(byClass('panic-bar')) !== null
const hasCard = (view) => view.one(byClass('panic-card')) !== null
const hasNotice = (view) => view.one(byClass('panic-feed-down')) !== null
const barText = (view) => {
    const found = view.one(byClass('panic-bar'))

    return found === null ? '' : view.text()
}

const live = async () => {
    socket.setState('connected')
    socket.subscribe(CHANNEL)
    await flush(nextTick)
}

const tick = async (ms) => {
    mock.timers.tick(ms)
    await flush(nextTick)
}

beforeEach(() => {
    socket.reset()
    asked.length = 0
    user++
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED + 1_000 })

    globalThis.fetch = async (url) => {
        asked.push(String(url))

        if (answer === 'down') {
            throw new TypeError('Failed to fetch')
        }

        return { ok: true, json: async () => answer }
    }
})

afterEach(async () => {
    // Every page left, and the visit's channel and clocks let go under the same clock that
    // started them — a timer from one test must not be cleared against the next one's.
    for (const view of opened) {
        view.unmount()
    }

    opened.clear()
    await tick(11_000)
    mock.timers.reset()
})

describe('B039 / B103 — the bar outlives the page', () => {
    it('keeps blinking after a sidebar link, with nobody having acknowledged the alert', async () => {
        const residents = await page()
        await live()

        socket.emit(CHANNEL, '.alert.raised', broadcast())
        answer = { alerts: [row()] }
        await nextTick()

        assert.ok(hasBar(residents), 'the bar lights on the broadcast')
        assert.ok(hasCard(residents), 'and the pop-up opens')

        // The reader closes the pop-up and clicks "Security" in the sidebar: a new page, a new shell.
        await residents.click(residents.one((el) => el.tag === 'button' && el.className === 'panic-close'))
        leave(residents)

        const security = await page([row()])

        assert.ok(hasBar(security), 'the bar is still there on the next page')
        // M53 §4.9: the count first, "needs attention" (the lot is on the Security screen it opens).
        assert.match(barText(security), /1 emergency needs attention/)
        assert.equal(hasCard(security), false, 'the pop-up is not opened a second time for the same alert')
    })

    it('shows a reader who opens the console after the panic the bar, with how many need attention', async () => {
        // A Vice President, a minute later: the server's own list of what nobody has acknowledged.
        const view = await page([row({ server_time: new Date(RAISED - 60_000).toISOString() })])

        assert.ok(hasBar(view), 'an outstanding alert lights the bar on arrival')
        assert.match(barText(view), /1 emergency needs attention/)
    })

    it('does not lose an alert broadcast while one page is replaced by the next', async () => {
        const first = await page()
        await live()

        // The old shell is taken down before the new one is built.
        leave(first)
        const delivered = socket.emit(CHANNEL, '.alert.raised', broadcast({ id: 12, unit: 'Lot 9' }))

        assert.ok(delivered, 'the channel is still held in the gap between pages')

        // The server drew the next page a moment before the alert existed, so its list is empty;
        // its poll, a moment later, knows the alert.
        const next = await page([], { server: [row({ id: 12, unit: 'Lot 9' })] })

        assert.ok(hasBar(next), 'the alert that arrived in the gap is on the next page')
        assert.match(barText(next), /Lot 9/)
    })

    it('stops blinking when the server says the control room acknowledged it — and not before', async () => {
        const view = await page([row()])
        await live()

        // Still outstanding on the next check: the bar stays.
        await tick(15_000)

        assert.ok(asked.some((url) => url.startsWith('/estate/phoenixpark/security/panic-watch')), 'the estate asks its own server, under its own root')
        assert.ok(hasBar(view))

        // An officer takes it from the handset: responding, still no acknowledgement — the bar stays.
        answer = { alerts: [row({ status: 'responding', en_route_at: new Date(RAISED + 20_000).toISOString() })] }
        await tick(15_000)

        assert.ok(hasBar(view), 'an officer on the way is not an acknowledgement')

        // The control room acknowledges it.
        answer = { alerts: [row({ status: 'responding', acknowledged_at: new Date(RAISED + 40_000).toISOString() })] }
        await tick(15_000)

        assert.equal(hasBar(view), false, 'acknowledged, and the server said so')
    })

    it('clears by hand for what is on the bar now, and lights again for a new alert', async () => {
        const view = await page([row()])
        await live()

        await view.click(view.one((el) => el.tag === 'button' && el.className === 'panic-bar-btn'))

        assert.equal(hasBar(view), false, 'Clear stops the bar for this reader')

        leave(view)

        // It stays cleared on the next page, although the server still lists the alert.
        const next = await page([row()])

        assert.equal(hasBar(next), false)

        socket.emit(CHANNEL, '.alert.raised', broadcast({ id: 13, unit: 'Lot 3' }))
        answer = { alerts: [row(), row({ id: 13, unit: 'Lot 3' })] }
        await nextTick()

        assert.ok(hasBar(next), 'a new alert lights it again')
        assert.match(barText(next), /Lot 3/)
    })

    it('offers the Security screen from the bar, under this estate\'s own root', async () => {
        const view = await page([row()])
        const link = view.all(byTag('a')).find((el) => el.className === 'panic-bar-btn')

        assert.ok(link, 'the bar carries a link once the pop-up is closed')
        assert.equal(link.attrs.href, '/estate/phoenixpark/security')
    })

    it('finds the estate\'s root on its own home page too, where nothing follows the key', async () => {
        // `/estate/phoenixpark` exactly: the root came out empty here, and the link and the poll
        // went to the central host.
        const view = await page([row()], { url: '/estate/phoenixpark' })
        await live()

        const link = view.all(byTag('a')).find((el) => el.className === 'panic-bar-btn')

        assert.equal(link.attrs.href, '/estate/phoenixpark/security')

        await tick(15_000)

        assert.ok(asked.length > 0 && asked.every((url) => url.startsWith('/estate/phoenixpark/security/panic-watch')), `asked: ${asked}`)
    })

    it('uses no root on an estate\'s own hostname', async () => {
        const view = await page([row()], { url: '/residents' })
        const link = view.all(byTag('a')).find((el) => el.className === 'panic-bar-btn')

        assert.equal(link.attrs.href, '/security')
    })
})

describe('B110 / B039 — a dead channel is said, not silent', () => {
    it('says so on the page when the channel subscription is refused', async () => {
        const view = await page()

        socket.setState('connected')
        socket.refuse(CHANNEL)
        await flush(nextTick)

        assert.ok(hasNotice(view), 'a refused channel is not a quiet night')
        assert.match(view.text(), /live alert channel is down/i)
    })

    it('says so when a live socket drops, and stops saying so when it is back', async () => {
        const view = await page()
        await live()

        assert.equal(hasNotice(view), false, 'nothing to say while the channel is live')

        socket.setState('unavailable')
        await flush(nextTick)

        assert.ok(hasNotice(view))

        socket.setState('connected')
        socket.subscribe(CHANNEL)
        await flush(nextTick)

        assert.equal(hasNotice(view), false)
    })

    it('says so when a first connection never answers, after a short grace', async () => {
        const view = await page()

        assert.equal(hasNotice(view), false, 'not on every page load, while the socket is still connecting')

        await tick(5_000)

        assert.ok(hasNotice(view), 'five seconds and nothing: the reader is told')
    })

    it('asks the server while the channel is down, so a panic still reaches the screen — and says when that fails too', async () => {
        const view = await page()

        socket.setState('connected')
        socket.refuse(CHANNEL)
        await flush(nextTick)

        answer = { alerts: [row({ id: 21, unit: 'Lot 21' })] }
        await tick(15_000)

        assert.ok(hasBar(view), 'the poll brought the alert the channel could not')
        assert.match(barText(view), /Lot 21/)
        assert.match(view.text(), /every 15 seconds/i)

        answer = 'down'
        await tick(15_000)

        assert.ok(hasNotice(view))
        assert.match(view.text(), /not reaching this screen/i)
    })
})
