/**
 * The Gemini console's panic bar — `composables/useConsolePanic.js`.
 *
 *   B090  an alert an officer took from the handset before anybody in the control room
 *         acknowledged it is still outstanding: the bar keeps blinking, and the poll keeps
 *         asking about it, until the SERVER says the control room acknowledged it.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { defineComponent, h } from 'vue'
import { mount, nextTick } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, RAISED as NOW, watchRow as row } from './support/alerts.mjs'

const { useConsolePanic } = await import('../../resources/js/composables/useConsolePanic.js')

const watchWith = (alerts) => ({
    estates: [{ id: 'phoenixpark', name: 'Phoenix Park' }],
    alerts,
    poll: '/dispatch/panic-watch',
    checked_at: new Date(NOW).toISOString(),
})

let owner = 1000
const asked = []
let answer = { alerts: [], checked_at: new Date(NOW).toISOString() }

const mountPanic = async (watch) => {
    let panic = null
    const Host = defineComponent({
        setup() {
            // A new owner each time: the console's state is per visit, and each test is a new one.
            panic = useConsolePanic(watch, { owner: ++owner })

            return () => h('div')
        },
    })

    const view = await mount(Host)

    return { panic, view }
}

beforeEach(() => {
    resetInertia()
    socket.reset()
    asked.length = 0
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: NOW })

    globalThis.fetch = async (url) => {
        asked.push(String(url))

        return { ok: true, json: async () => answer }
    }
})

afterEach(() => {
    mock.timers.reset()
})

describe('B090 — an officer on the way is not an acknowledgement', () => {
    it('keeps the bar blinking for an alert that is responding with no acknowledgement', async () => {
        const { panic, view } = await mountPanic(watchWith([
            row({ status: 'responding', en_route_at: new Date(NOW - 30_000).toISOString() }),
        ]))

        assert.equal(panic.blinking.value, true, 'nobody in the control room has acknowledged it')
        assert.equal(panic.outstanding.value, 1)
        assert.equal(panic.current.value?.id, 11)

        view.unmount()
    })

    it('keeps asking the server about it, and stops only when the server says it was acknowledged', async () => {
        const { panic, view } = await mountPanic(watchWith([row()]))

        assert.equal(panic.blinking.value, true)

        // An officer takes it from the handset. The poll says so — still no acknowledgement.
        answer = { alerts: [row({ status: 'responding', en_route_at: new Date(NOW).toISOString() })], checked_at: new Date(NOW + 15_000).toISOString() }
        mock.timers.tick(15_000)
        await flush(nextTick)

        assert.match(asked.at(-1), /ids%5B%5D=11|ids\[\]=11/, 'the poll asked about the alert it is showing')
        assert.equal(panic.blinking.value, true, 'still blinking: the control room has not acknowledged it')

        // The next poll asks about it again, because it is still outstanding.
        answer = {
            alerts: [row({ status: 'responding', en_route_at: new Date(NOW).toISOString(), acknowledged_at: new Date(NOW + 20_000).toISOString() })],
            checked_at: new Date(NOW + 30_000).toISOString(),
        }
        mock.timers.tick(15_000)
        await flush(nextTick)

        assert.match(asked.at(-1), /ids%5B%5D=11|ids\[\]=11/, 'an alert still outstanding is still asked about')
        assert.equal(panic.blinking.value, false, 'the control room acknowledged it, and the server said so')

        view.unmount()
    })
})
