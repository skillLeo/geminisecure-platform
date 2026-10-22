/**
 * Live gate activity moves by itself: `Pages/Gemini/Operations/GateActivity.vue` (M98, the security lane's first proof;
 * Hassam, 11 Oct: "Someone scans at the gate, it appears on screen within seconds").
 *
 *   The console's own live store, on the socket double: with the live channel up, a gate event on `gemini.workforce`
 *   makes the page read its feed and its figures again, those two props only, within a fifth of a second, with no
 *   reload of the page and nobody pressing anything; the five-second poll does not run while live. With the channel
 *   down the poll runs every five seconds and the page says so in words, never a dot that only looks live. And the
 *   five states (M98 §3.8): loading, empty that says nothing has happened yet, populated, error, denied.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { liveServer, liveSocket } from './support/live-socket.mjs'
import { geminiShared } from './support/alerts.mjs'

const { stopLive } = await import('../../resources/js/live/index.js')
const { default: GateActivity } = await import('../../resources/js/Pages/Gemini/Operations/GateActivity.vue')

const T0 = Date.parse('2026-10-11T16:40:00Z')

async function settle(turns = 30) {
    for (let i = 0; i < turns; i++) {
        await Promise.resolve()
    }

    await nextTick()
}

/* The clock in small steps, as live-store.test.mjs moves it. */
async function tick(ms) {
    let left = ms

    do {
        const step = Math.min(left, 100)
        mock.timers.tick(step)
        left -= step
        await settle()
    } while (left > 0)
}

const ROW = { verdict: 'admit', headline: 'Kimberly Douglas: Pass verified · Lot 18', detail: 'Main Gate · Marcus Whyte', estate: 'Phoenix Park Village 1', time: '11:40 AM' }

const KPIS = [
    { key: 'on_post', icon: 'guards', value: '4', label: 'Guards on post, platform-wide' },
    { key: 'admits', icon: 'check', value: '12', label: 'Admits today, all clients' },
]

const live = { shell: ['user.77', 'gemini.workforce'], heads: { c: 100, e: 50 }, principal: 'u-77', build: 'build-1' }

let view = null

const open = async (props = {}, shared = {}) => {
    resetInertia(geminiShared({ live, ...shared }), '/guards/activity')
    view = await mount(GateActivity, { kpis: KPIS, feed: [ROW], sourceBadge: { source: 'devices', simulated: false }, ...props })
    await settle()

    return view
}

/** The socket connects, the batched auth and catch-up go, and the relay's pulse arrives: the store is live. */
async function connect() {
    liveSocket.open()
    await tick(20)
    await tick(50)
    liveSocket.pulse({ build: 'build-1' })
    await settle()
}

/** Every partial read of this page's feed and figures, which is what "it moved" means here. */
const feedReads = () => visits.filter((v) => v.method === 'reload' && JSON.stringify(v.options?.only ?? []) === JSON.stringify(['kpis', 'feed']))
const navigations = () => visits.filter((v) => v.method === 'get' || v.method === 'visit')

beforeEach(() => {
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: T0 })
    liveServer.reset()
    liveServer.heads = { c: 100, e: 50 }
    liveServer.install()
    liveSocket.reset()
    globalThis.document.cookie = 'XSRF-TOKEN=token%3D1; other=x'
})

afterEach(async () => {
    view?.unmount()
    view = null
    stopLive()
    await settle()
    mock.timers.reset()
    delete globalThis.document.cookie
})

describe('live gate activity, with the live channel up', () => {
    it('reads its feed again within a fifth of a second of a gate event, with no reload and no press', async () => {
        await open()
        await connect()

        assert.ok(textOf(view.root).includes('Live'), 'the page says it is live')
        const before = feedReads().length

        // Six seconds of nothing: the five-second poll does not run while the channel is live.
        await tick(6000)
        assert.equal(feedReads().length, before, 'no poll while live')

        // A guard admits somebody at a gate: the relay sends `gate.admit` on the workforce channel.
        assert.ok(liveSocket.deliver('gemini.workforce', liveServer.publish('gemini.workforce', { t: 'gate.admit', e: 'gate', i: '501' })), 'the page\'s channel is subscribed')
        await tick(200)

        assert.equal(feedReads().length, before + 1, 'one read of the feed and the figures, on the event')
        assert.equal(navigations().length, 0, 'never a reload of the page')
        assert.ok(textOf(view.root).includes('Live'))
    })
})

describe('live gate activity, when the live channel drops under an open page', () => {
    it('starts reading every five seconds the moment the channel is down, and says so', async () => {
        await open()
        await connect()
        assert.ok(textOf(view.root).includes('Live'))

        liveSocket.drop()
        await tick(1000)
        const before = feedReads().length
        await tick(12_000)

        assert.ok(textOf(view.root).includes('Live updates down · checking every 5 seconds'), 'it says the channel is down')
        assert.ok(feedReads().length - before >= 2, 'and it really is checking, every five seconds')
        assert.equal(navigations().length, 0)
    })
})

describe('live gate activity, with the live channel down', () => {
    it('reads every five seconds and says in words that live updates are down', async () => {
        await open()
        await tick(100)

        assert.ok(textOf(view.root).includes('Live updates down · checking every 5 seconds'), 'the words, not a dot that looks live')
        const before = feedReads().length
        await tick(10_100)

        assert.ok(feedReads().length - before >= 2, 'the poll, every five seconds')
        assert.equal(navigations().length, 0)
    })
})

describe('the five states (M98 §3.8)', () => {
    it('says nothing has happened yet on an empty feed, in words, not a blank box', async () => {
        await open({ feed: [] })

        assert.ok(textOf(view.root).includes('Nothing at the gates yet today'))
    })

    it('draws a failed read as a failure with Try again, and a refused role as a refusal', async () => {
        await open({}, { screenState: 'error' })
        assert.ok(textOf(view.root).includes('The gate feed could not be read'), 'the error state')
        assert.ok(view.all((el) => el.tag === 'button' && textOf(el) === 'Try again').length === 1, 'and its Try again')
        view.unmount()

        await open({}, { screenState: 'denied' })
        assert.ok(textOf(view.root).includes('Live gate activity is not part of your role'), 'the denied state')
        assert.ok(!textOf(view.root).includes('Kimberly Douglas'), 'and nothing of the feed')
    })

    it('draws the skeleton while loading', async () => {
        await open({}, { screenState: 'loading' })

        assert.ok(!textOf(view.root).includes('Kimberly Douglas'), 'no rows while loading')
        assert.ok(!textOf(view.root).includes('Nothing at the gates'), 'and no empty state either')
    })
})
