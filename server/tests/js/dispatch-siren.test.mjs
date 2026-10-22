/**
 * The panic siren on the dispatch screens — `useLiveDispatch`, `usePanicAlarm` and the
 * alarm's controls (`PanicAlarmControls`).
 *
 *   B107  every dispatch screen that sounds the siren offers the control that quietens it —
 *         the live map, the coverage and alertness boards and an alert's own screen as well
 *         as the queue; the siren stops by itself once the alerts it is sounding for are
 *         acknowledged, in any session; and a fresh tab whose browser will not play sound
 *         says so before a panic arrives, rather than looking armed.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick, textOf } from './support/render.mjs'
import { resetInertia } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { audio, audioContexts } from './support/env.mjs'
import { alertScreenProps, flush, geminiShared, RAISED } from './support/alerts.mjs'

const screens = {
    'the alerts queue': {
        component: (await import('../../resources/js/Pages/Gemini/Dispatch/Alerts.vue')).default,
        props: { sections: [], tabs: [], tab: 'all', alerts: [] },
    },
    'the live map': {
        component: (await import('../../resources/js/Pages/Gemini/Dispatch/Map.vue')).default,
        props: { sections: [], kpis: [], regions: [], sites: [], legend: [], onDuty: [] },
    },
    'the coverage board': {
        component: (await import('../../resources/js/Pages/Gemini/Dispatch/Coverage.vue')).default,
        props: { sections: [], day: 'Thursday', date: '2026-09-24', isToday: true, kpis: [], windows: [], rows: [] },
    },
    'the alertness board': {
        component: (await import('../../resources/js/Pages/Gemini/Dispatch/Alertness.vue')).default,
        props: { sections: [], kpis: [], rows: [], policy: 'Every 30 minutes' },
    },
    'an alert\'s own screen': {
        component: (await import('../../resources/js/Pages/Gemini/Dispatch/Alert.vue')).default,
        props: alertScreenProps(),
    },
}

const CHANNEL = 'estate.phoenixpark.alerts'

let awaiting = []
const asked = []

const open = async (name) => {
    const { component, props } = screens[name]

    resetInertia(geminiShared(), '/dispatch/alerts')

    const view = await mount(component, {
        ...props,
        estateIds: ['phoenixpark'],
        sourceBadge: { source: 'devices', simulated: false },
    })

    await flush(nextTick)

    return view
}

const live = async () => {
    socket.setState('connected')
    socket.subscribe(CHANNEL)
    await flush(nextTick)
}

const panic = (id = 5) => socket.emit(CHANNEL, '.alert.raised', {
    id, kind: 'panic', kind_label: 'Panic button', estate: 'phoenixpark', is_urgent: true, server_time: new Date(RAISED).toISOString(),
    is_simulated: false, is_drill: false, alerted_handsets: null, alerted_via: null, unit: 'Lot 47', who: 'Jane Brown',
})

const chips = (view) => view.all((el) => el.tag === 'button' && el.className.split(' ').includes('alarm-chip'))
const chip = (view, label) => chips(view).find((el) => textOf(el) === label) ?? null

// Booleans, not elements: an element carries its whole tree, and a failure message would print it.
const has = (view, label) => chip(view, label) !== null

const tick = async (ms) => {
    mock.timers.tick(ms)
    await flush(nextTick)
}

beforeEach(() => {
    socket.reset()
    awaiting = []
    asked.length = 0
    audio.initialState = 'running'
    audio.started = 0
    document.title = 'Dispatch'
    localStorage.clear()
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED + 1_000 })

    globalThis.fetch = async (url) => {
        asked.push(String(url))

        return { ok: true, json: async () => ({ awaiting, checked_at: new Date().toISOString() }) }
    }
})

afterEach(() => {
    mock.timers.reset()
})

describe('B107 — the siren can be quietened wherever it sounds', () => {
    for (const name of Object.keys(screens)) {
        it(`offers "Silence alarm" on ${name}, and it silences`, async () => {
            const view = await open(name)
            await live()

            awaiting = [5]
            assert.ok(panic(), 'the screen listens on its estate\'s channel')
            await nextTick()

            assert.ok(audio.started > 0, 'the siren really is sounding')
            assert.ok(has(view, 'Silence alarm'), `${name} draws the control while the siren sounds`)

            await view.click(chip(view, 'Silence alarm'))

            assert.equal(has(view, 'Silence alarm'), false, 'silenced')
            assert.ok(has(view, 'Alarm on'))
            assert.equal(document.title, 'Dispatch', 'the tab stops flashing')

            view.unmount()
        })
    }

    it('offers the Mute toggle beside it on every screen, and Mute keeps it quiet', async () => {
        for (const name of Object.keys(screens)) {
            const view = await open(name)
            await live()

            assert.ok(has(view, 'Mute'), `${name} can turn the siren off for good`)

            await view.click(chip(view, 'Mute'))
            audio.started = 0
            panic(9)
            await nextTick()

            assert.equal(audio.started, 0, `${name}: muted, so no sound`)
            assert.ok(has(view, 'Alarm off'))

            view.unmount()
            localStorage.clear()
            socket.reset()
        }
    })
})

describe('B107 — the siren stops once the alert is acknowledged, in any session', () => {
    it('keeps sounding while the server says the alert is still unacknowledged, and stops when it is not', async () => {
        const view = await open('the live map')
        await live()

        awaiting = [5]
        panic(5)
        await nextTick()

        assert.ok(has(view, 'Silence alarm'))

        await tick(5_000)

        assert.ok(asked.some((url) => /^\/dispatch\/alarm\?ids(%5B%5D|\[\])=5$/.test(url)), `the screen asks about the alert it is sounding for: ${asked}`)
        assert.ok(has(view, 'Silence alarm'), 'still unacknowledged: still sounding')

        // A second dispatcher acknowledges it, from another session.
        awaiting = []
        await tick(5_000)

        assert.equal(has(view, 'Silence alarm'), false, 'acknowledged: the siren stops by itself')
        assert.ok(has(view, 'Alarm on'))
        assert.equal(document.title, 'Dispatch')

        view.unmount()
    })

    it('stops only when every alert it is sounding for is acknowledged', async () => {
        const view = await open('the alerts queue')
        await live()

        awaiting = [5, 6]
        panic(5)
        panic(6)
        await nextTick()

        awaiting = [6]
        await tick(5_000)

        assert.ok(has(view, 'Silence alarm'), 'the second panic is still unacknowledged')

        awaiting = []
        await tick(5_000)

        assert.equal(has(view, 'Silence alarm'), false)

        view.unmount()
    })

    it('goes on sounding when no answer comes — silence from the server is not an acknowledgement', async () => {
        const view = await open('the coverage board')
        await live()

        globalThis.fetch = async () => ({ ok: false, status: 500, json: async () => ({}) })

        panic(5)
        await nextTick()
        await tick(10_000)

        assert.ok(has(view, 'Silence alarm'))

        globalThis.fetch = async () => {
            throw new TypeError('Failed to fetch')
        }

        await tick(10_000)

        assert.ok(has(view, 'Silence alarm'))

        view.unmount()
    })

    it('asks nothing while the siren is quiet', async () => {
        const view = await open('the alertness board')
        await live()

        await tick(30_000)

        assert.equal(asked.filter((url) => url.startsWith('/dispatch/alarm')).length, 0)

        view.unmount()
    })
})

describe('B107 — a browser that will not play sound says so before a panic', () => {
    for (const name of Object.keys(screens)) {
        it(`says "Enable alarm sound" on a fresh tab on ${name}`, async () => {
            audio.initialState = 'suspended'

            const view = await open(name)

            assert.ok(has(view, 'Enable alarm sound'), `${name} does not look armed when it cannot sound`)

            await view.click(chip(view, 'Enable alarm sound'))
            await flush(nextTick)

            assert.ok(has(view, 'Alarm on'), 'one click arms it, and it says so')
            assert.equal(audioContexts.at(-1).state, 'running')

            view.unmount()
        })
    }
})
