/**
 * Live panic, followed through every step with no refresh: `Pages/Gemini/Dispatch/Alerts.vue` and
 * `Pages/Gemini/Dispatch/Alert.vue` (M98, the security lane's second proof; Hassam, 11 Oct: "A resident or guard
 * raises panic, it appears immediately, and he can follow it through acknowledged, dispatched, on the way, on scene,
 * resolved, without refreshing").
 *
 *   The console's own live store on the socket double, as the shell starts it: a panic raised reaches the queue, and
 *   each of the five steps reaches the alert's own screen, each as one read of the screen's data within a fifth of a
 *   second, with no reload of the page and nobody pressing anything. The server half, that each step publishes on the
 *   dispatch feed, is tests/Feature/Live/AlertLiveTest.php.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { liveServer, liveSocket } from './support/live-socket.mjs'
import { socket } from './support/echo.mjs'
import { alertScreenProps, geminiShared, RAISED } from './support/alerts.mjs'

const { stopLive } = await import('../../resources/js/live/index.js')
const { default: AlertsQueue } = await import('../../resources/js/Pages/Gemini/Dispatch/Alerts.vue')
const { default: AlertScreen } = await import('../../resources/js/Pages/Gemini/Dispatch/Alert.vue')

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

const live = { shell: ['user.77', 'gemini.dispatch'], heads: { c: 100, e: 50 }, principal: 'u-77', build: 'build-1' }

let view = null

const open = async (component, props, url) => {
    resetInertia(geminiShared({ live }), url)
    view = await mount(component, props)
    await settle()

    return view
}

async function connect() {
    liveSocket.open()
    await tick(20)
    await tick(50)
    liveSocket.pulse({ build: 'build-1' })
    await settle()
}

const reads = () => visits.filter((v) => v.method === 'reload')
const navigations = () => visits.filter((v) => v.method === 'get' || v.method === 'visit')

/** The relay sends one step of alert 4 on the dispatch feed, as `AlertLive` publishes it. */
const step = (type) => liveSocket.deliver('gemini.dispatch', liveServer.publish('gemini.dispatch', { t: type, e: 'alert', i: '4' }))

beforeEach(() => {
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED + 60_000 })
    liveServer.reset()
    liveServer.heads = { c: 100, e: 50 }
    liveServer.install()
    liveSocket.reset()
    socket.reset()
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

describe('a panic raised reaches the queue by itself', () => {
    it('reads the queue again within a fifth of a second, with no reload and no press', async () => {
        await open(AlertsQueue, { sections: [], tabs: [], tab: 'all', alerts: [], estateIds: ['phoenixpark'], sourceBadge: { source: 'devices', simulated: false } }, '/dispatch/alerts')
        await connect()
        const before = reads().length

        assert.ok(step('alert.raised'), 'the dispatch feed is subscribed')
        await tick(200)

        assert.equal(reads().length, before + 1, 'one read of the queue, on the panic')
        assert.deepEqual(reads().at(-1).options.only, ['alerts'], 'the queue only, never the whole page')
        assert.equal(navigations().length, 0, 'never a reload of the page')
    })
})

describe('an alert\'s own screen follows it through every step by itself', () => {
    it('reads again at acknowledged, dispatched, on the way, on scene and resolved, each within a fifth of a second', async () => {
        await open(AlertScreen, alertScreenProps(), '/dispatch/alerts/4')
        await connect()

        for (const type of ['alert.acknowledged', 'alert.dispatched', 'alert.en_route', 'alert.on_scene', 'alert.resolved']) {
            const before = reads().length

            assert.ok(step(type), `${type} is delivered`)
            await tick(200)

            assert.equal(reads().length, before + 1, `one read at ${type}`)
            assert.ok(reads().at(-1).options.only.includes('timeline'), `${type}: the screen reads its timeline again`)
        }

        assert.equal(navigations().length, 0, 'never a reload of the page, at any step')
    })
})
