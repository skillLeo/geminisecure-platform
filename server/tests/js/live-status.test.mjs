/**
 * THE HONESTY INDICATOR (M11 Part C, design §6.4) — `Components/LiveStatus.vue`.
 *
 *   green "Live" (a dispatch screen) only when the socket is up, every channel is
 *   subscribed, catch-up is done, relay.ok is true and a pulse arrived within 25 s;
 *   otherwise amber, in the design's words, and Refresh runs a catch-up;
 *   a data page with no bindings says "This screen does not update by itself" with a
 *   working Refresh; a form page shows nothing; a report shows its as-of line;
 *   "Connecting…" only after 2 s; a refusal is grey; a 401 or 419 says the session ended;
 *   a new build says so, quietly.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { defineComponent, h } from 'vue'
import { byClass, byTag, mount, nextTick } from './support/render.mjs'
import { page, resetInertia, visits } from './support/inertia.mjs'
import { liveServer, liveSocket } from './support/live-socket.mjs'

const { startLive, stopLive, useLive, liveList } = await import('../../resources/js/live/index.js')
const { default: LiveStatus } = await import('../../resources/js/Components/LiveStatus.vue')

const T0 = Date.parse('2026-10-02T14:02:31Z')
const CHANNEL = 'estate.pp.facilities'

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

/**
 * A page as a shell draws it: its bindings (none, for an unwired page) and the indicator
 * after the byline. `pageLive` is the page's live prop; the shell's part is merged in.
 */
async function draw(pageLive, { bind = true, dispatch = false, start = true } = {}) {
    const live = pageLive === null ? undefined : { shell: ['user.7'], heads: { c: 100, e: 50 }, principal: 'u-7', build: 'build-1', ...pageLive }
    resetInertia({ rows: [], ...(live ? { live } : {}) }, '/estate/pp/facilities/maintenance')

    if (start) {
        startLive(page.props.live)
    }

    const Page = defineComponent({
        setup() {
            if (bind && live) {
                useLive(page.props.live, { rows: liveList('rows', { entity: 'ticket', rowsUrl: '/rows' }) }, { dispatch })
            }

            return () => h('div', [h(LiveStatus)])
        },
    })

    const view = await mount(Page)
    opened.add(view)

    return view
}

const bound = (extra = {}) => ({ channels: [CHANNEL], bindings: ['rows'], kind: 'data', ...extra })

/** Everything the green state needs: connected, subscribed, caught up, a pulse. */
async function allLive() {
    liveSocket.open()
    await tick(20)
    await tick(50)
    liveSocket.pulse({ build: 'build-1' })
    await settle()
}

const status = (view) => view.one(byClass('live-status'))
const tone = (view) => (status(view)?.className.match(/live-status--(\w+)/) ?? [])[1] ?? null
const text = (view) => (status(view) ? view.text() : '')
const refreshButton = (view) => view.all(byTag('button')).find((button) => view.text && button.children.some((child) => child.text === 'Refresh')) ?? null
const catchUps = () => liveServer.count('/live/catch-up')

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

describe('pages that are not live, said so', () => {
    it('shows nothing, and makes no socket, on a page with no live prop (the sign-in page)', async () => {
        const view = await draw(null, { start: true })

        assert.equal(status(view), null)
        assert.equal(liveSocket.instances.length, 0)
    })

    it('says a data page with no bindings does not update by itself, with when it was drawn, and a working Refresh', async () => {
        const view = await draw({ channels: [], bindings: [], kind: 'data' }, { bind: false })
        await allLive()

        // How long ago, not the reader's clock (M19A §4.1).
        assert.equal(text(view), 'This screen does not update by itself · updated just now · Refresh')
        assert.equal(tone(view), 'grey')

        await view.click(refreshButton(view))

        const reloads = visits.filter((visit) => visit.method === 'reload')
        assert.equal(reloads.length, 1)
        assert.equal(reloads[0].options.preserveScroll, true)
    })

    it('says it too when the page declares bindings the page never made — it would not update', async () => {
        const view = await draw(bound(), { bind: false })
        await allLive()

        assert.match(text(view), /^This screen does not update by itself/)
    })

    it('keeps saying it however long the page stays open, and whatever the socket does', async () => {
        const view = await draw({ channels: [], bindings: [], kind: 'data' }, { bind: false })
        liveSocket.open()
        await tick(60_000)

        assert.match(text(view), /^This screen does not update by itself/)
    })

    it('shows nothing on a form page, which displays no record', async () => {
        const view = await draw({ channels: [], bindings: [], kind: 'form' }, { bind: false })
        await tick(10_000)

        assert.equal(status(view), null)
    })

    it('shows a report\'s as-of line', async () => {
        const view = await draw({ channels: [], bindings: [], kind: 'snapshot', as_of: new Date(T0 - 3_600_000).toISOString() }, { bind: false })

        assert.equal(text(view), 'As of an hour ago · Refresh')
    })
})

describe('green only when everything holds', () => {
    it('says "Live" on a dispatch screen once connected, subscribed, caught up, with a fresh pulse', async () => {
        const view = await draw(bound(), { dispatch: true })
        await allLive()

        assert.equal(text(view), 'Live')
        assert.equal(tone(view), 'green')
    })

    it('shows nothing while live on any other bound page', async () => {
        const view = await draw(bound())
        await allLive()

        assert.equal(status(view), null)
    })

    it('is not green before the first pulse on this connection, and shows a small dot, no words, once that has taken 2 s (M78 §3)', async () => {
        const view = await draw(bound(), { dispatch: true })
        liveSocket.open()
        await tick(1_900)
        assert.equal(status(view), null)

        await tick(200)
        assert.equal(tone(view), 'grey')
        assert.equal(text(view), '')
        assert.equal(view.one(byClass('live-status__dot')) !== null, true)
        assert.equal(status(view).attrs['aria-label'], 'Connecting')

        liveSocket.pulse()
        await settle()
        assert.equal(text(view), 'Live')
    })

    it('takes the dot away once connected on a page that is not a dispatch screen (M78 §3)', async () => {
        const view = await draw(bound())
        liveSocket.open()
        await tick(2_100)
        assert.equal(view.one(byClass('live-status__dot')) !== null, true)

        liveSocket.pulse()
        await settle()
        assert.equal(status(view), null)
    })

    it('goes amber when the socket has been down for 3 s, in the design\'s words, and Refresh catches up', async () => {
        const view = await draw(bound(), { dispatch: true })
        await allLive()
        const synced = Date.now()

        liveSocket.drop()
        await tick(2_000)
        assert.notEqual(tone(view), 'amber', 'not for a blip')

        await tick(1_000)
        assert.equal(tone(view), 'amber')
        // M78 §4: no em dash in anything a person reads.
        assert.equal(text(view), 'Live updates are down. This screen may be out of date · updated just now · checking every 3 s · Refresh')
        assert.ok(synced > 0)

        const before = catchUps()
        await view.click(refreshButton(view))
        await tick(0)
        assert.equal(catchUps(), before + 1)
    })

    it('says "checking every 10 s" off the dispatch screens', async () => {
        const view = await draw(bound())
        await allLive()

        liveSocket.drop()
        await tick(3_000)

        assert.match(text(view), /· checking every 10 s · Refresh$/)
    })

    it('goes amber when a channel is still not subscribed after 3 s', async () => {
        liveSocket.autoSucceed = false
        const view = await draw(bound(), { dispatch: true })
        liveSocket.open()
        liveSocket.pulse()
        await tick(3_000)

        assert.equal(tone(view), 'amber')
        assert.match(text(view), /^Live updates are down/)
    })

    it('goes amber when catch-up has not finished after 3 s', async () => {
        liveServer.holdCatchUps = true
        const view = await draw(bound(), { dispatch: true })
        liveSocket.open()
        liveSocket.pulse()
        await tick(3_100)

        assert.equal(tone(view), 'amber')

        liveServer.releaseCatchUps()
        await settle()
        assert.equal(text(view), 'Live')
    })

    it('goes amber when the catch-up keeps failing, and back once it answers', async () => {
        liveServer.catchUpStatus = 500
        const view = await draw(bound(), { dispatch: true })
        liveSocket.open()
        liveSocket.pulse()
        await tick(3_100)

        assert.match(text(view), /^Live updates are down/)

        liveServer.catchUpStatus = null
        await tick(3_000)
        liveSocket.pulse()
        await settle()
        assert.equal(text(view), 'Live')
    })

    it('says "Live updates are delayed" when the relay says it is behind', async () => {
        liveServer.relay = { ok: false, lag_ms: 9_000 }
        const view = await draw(bound(), { dispatch: true })
        await allLive()

        assert.equal(tone(view), 'amber')
        assert.match(text(view), /^Live updates are delayed · updated just now · Refresh$/)
    })

    it('says "Live updates are delayed" when no pulse has come for 25 s', async () => {
        const view = await draw(bound(), { dispatch: true })
        await allLive()

        await tick(24_000)
        assert.equal(text(view), 'Live')

        await tick(1_000)
        assert.equal(tone(view), 'amber')
        assert.match(text(view), /^Live updates are delayed/)

        liveSocket.pulse()
        await settle()
        assert.equal(text(view), 'Live')
    })
})

describe('the other things it says', () => {
    it('is grey, not green, when a channel was refused', async () => {
        liveServer.refused.add(CHANNEL)
        const view = await draw(bound(), { dispatch: true })
        await allLive()

        assert.equal(tone(view), 'grey')
        assert.equal(text(view), 'Some live updates are not available to you · Refresh')
    })

    it('says the session ended on a 419, with a way to sign in again', async () => {
        liveServer.catchUpStatus = 419
        const view = await draw(bound(), { dispatch: true })
        await allLive()

        assert.equal(text(view), 'Your session ended. Sign in')
        assert.equal(view.one(byTag('a')).attrs.href, '/estate/pp/facilities/maintenance')
    })

    it('says so quietly when the relay is running a newer build', async () => {
        const view = await draw(bound())
        await allLive()

        liveSocket.pulse({ build: 'build-2' })
        await settle()

        assert.equal(tone(view), 'quiet')
        assert.equal(text(view), 'New version. Reload')
    })

    it('says a screen was refreshed after a reset, for a while', async () => {
        liveServer.resets.add(CHANNEL)
        const view = await draw(bound())
        await allLive()

        assert.equal(text(view), 'Refreshed: you were away longer than live updates are kept.')

        await tick(10_000)
        liveSocket.pulse()
        await settle()
        assert.equal(status(view), null)
    })
})
