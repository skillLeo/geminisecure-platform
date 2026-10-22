/**
 * THE RELAY'S NUDGE (M11 Part C, design §1.5).
 *
 * A poison publish — one Reverb refused five times — is given up on, and the client
 * recovers it by catch-up. When it was its channel's LAST event, no later event reveals the
 * gap, so the relay sends an ephemeral `live.nudge` (`{t: "live.nudge", q: null}`, no data)
 * on each of that event's channels. A client that receives one catches that channel up
 * after 300 ms: exactly one catch-up, however many nudges came in the window, and it is
 * the catch-up that applies the event the socket never carried.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { nextTick } from 'vue'
import { resetInertia, visits } from './support/inertia.mjs'
import { liveServer, liveSocket } from './support/live-socket.mjs'

const { startLive, stopLive } = await import('../../resources/js/live/index.js')
const store = await import('../../resources/js/live/store.js')

const T0 = Date.parse('2026-10-02T14:02:31Z')
const CHANNEL = 'estate.pp.facilities'
const NUDGE = { t: 'live.nudge', q: null }

async function settle(turns = 30) {
    for (let i = 0; i < turns; i++) {
        await Promise.resolve()
    }

    await nextTick()
}

async function tick(ms) {
    let left = ms

    do {
        const step = Math.min(left, 50)
        mock.timers.tick(step)
        left -= step
        await settle()
    } while (left > 0)
}

const catchUpsFor = (name) =>
    liveServer.requests.filter((request) => request.url === '/live/catch-up' && request.body.channels.some((entry) => entry.name === name))

let seen = []
let unregister = () => {}

beforeEach(async () => {
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: T0 })
    liveServer.reset()
    liveServer.heads = { c: 100, e: 50 }
    liveServer.install()
    liveSocket.reset()
    resetInertia({}, '/estate/pp/facilities/maintenance')

    startLive({ shell: ['user.7', CHANNEL, 'estate.pp.security'], heads: { c: 100, e: 50 }, principal: 'u-7', build: 'build-1' })
    liveSocket.open()
    await tick(20)
    await tick(50)
    liveSocket.pulse()
    await settle()

    seen = []
    unregister = store.register({
        channels: { has: (name) => name === CHANNEL },
        accepts: () => true,
        apply: (env) => seen.push(env.t === 'live.nudge' ? 'nudge' : env.q),
        resync() {},
    })
})

afterEach(async () => {
    unregister()
    stopLive()
    await settle()
    mock.timers.reset()
})

describe('a live.nudge', () => {
    it('runs exactly one catch-up for its channel, after 300 ms, and the catch-up applies the event the socket never carried', async () => {
        const before = catchUpsFor(CHANNEL).length

        // The relay gave up publishing this one: it is in the log, never on the socket.
        liveServer.publish(CHANNEL, { i: 'poison' })
        liveSocket.deliver(CHANNEL, NUDGE)
        await tick(250)
        assert.equal(catchUpsFor(CHANNEL).length, before, 'not before 300 ms')

        await tick(50)
        assert.equal(catchUpsFor(CHANNEL).length, before + 1)
        assert.deepEqual(catchUpsFor(CHANNEL).pop().body.channels.map((entry) => entry.name), [CHANNEL], 'that channel only')
        assert.deepEqual(seen, [51])

        await tick(5_000)
        assert.equal(catchUpsFor(CHANNEL).length, before + 1, 'and only one')
    })

    it('runs one catch-up for several nudges in the window', async () => {
        const before = catchUpsFor(CHANNEL).length

        liveSocket.deliver(CHANNEL, NUDGE)
        await tick(100)
        liveSocket.deliver(CHANNEL, NUDGE)
        liveSocket.deliver(CHANNEL, NUDGE)
        await tick(1_000)

        assert.equal(catchUpsFor(CHANNEL).length, before + 1)
    })

    it('is not an event: it moves no cursor, reaches no binding and reloads nothing', async () => {
        liveSocket.deliver(CHANNEL, NUDGE)
        await tick(1_000)

        assert.deepEqual(seen, [])
        assert.equal(visits.filter((visit) => visit.method === 'reload').length, 0)

        // The next event is applied as the next event: the cursor did not move.
        liveSocket.deliver(CHANNEL, liveServer.publish(CHANNEL))
        await settle()
        assert.deepEqual(seen, [51])
    })

    it('catches up only the channel it came on', async () => {
        const other = catchUpsFor('estate.pp.security').length

        liveSocket.deliver(CHANNEL, NUDGE)
        await tick(1_000)

        assert.equal(catchUpsFor('estate.pp.security').length, other)
    })

    it('arriving while that channel is already being caught up, asks once more when that answer is in', async () => {
        liveServer.holdCatchUps = true
        store.requestCatchUp([CHANNEL], 0, 'test')
        await tick(0)
        const before = catchUpsFor(CHANNEL).length

        liveSocket.deliver(CHANNEL, NUDGE)
        await tick(1_000)
        assert.equal(catchUpsFor(CHANNEL).length, before)

        liveServer.holdCatchUps = false
        liveServer.releaseCatchUps()
        await tick(100)
        assert.equal(catchUpsFor(CHANNEL).length, before + 1)
    })
})
