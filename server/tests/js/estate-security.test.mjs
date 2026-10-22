/**
 * The estate's own Security screen, live — `Pages/Estate/Security/Index.vue`.
 *
 *   B108  the screen the panic pop-up sends a committee to keeps itself current: it re-reads
 *         its lists when the estate's channel says something moved (a new alert, an officer
 *         sent or on the way, a guard clocking on or off), and every fifteen seconds while an
 *         alert on it is open — an acknowledgement is never broadcast — or while the channel is
 *         down; an alert's age counts on; and the screen says which of those it is doing.
 */
import { afterEach, beforeEach, describe, it, mock } from 'node:test'
import assert from 'node:assert/strict'
import { mount, nextTick } from './support/render.mjs'
import { resetInertia, visits } from './support/inertia.mjs'
import { socket } from './support/echo.mjs'
import { flush, RAISED } from './support/alerts.mjs'

const { default: SecurityScreen } = await import('../../resources/js/Pages/Estate/Security/Index.vue')

const CHANNEL = 'estate.phoenixpark.alerts'

let user = 9000
const opened = new Set()

const securityAlert = (overrides = {}) => ({
    id: 31,
    kind: 'panic',
    kind_label: 'Panic button',
    unit: 'Lot 47',
    who: 'Jane Brown',
    status: 'open',
    open: true,
    at: '24 Sep, 5:40 PM',
    ago: 'just now',
    raised_at: new Date(RAISED).toISOString(),
    acknowledged: false,
    is_drill: false,
    ...overrides,
})

/** A table's state as the server sends it (M32 F5, `TableQuery::meta`), for a page of these rows. */
const tableOf = (rows, prefix, sort) => ({
    prefix,
    sort: null,
    dir: null,
    default: { sort, dir: 'desc', per: 25 },
    sortable: [sort],
    page: 1,
    last: 1,
    per: 25,
    sizes: [25, 50, 100, 200],
    from: rows.length ? 1 : null,
    to: rows.length || null,
    total: rows.length,
    q: '',
})

const open = async ({ alerts = [], openAlerts = alerts.filter((a) => a.open).length } = {}) => {
    resetInertia(
        {
            auth: { user: { id: ++user, name: 'Hugh Head', role_label: 'Head of Security', receives_alerts: true } },
            estateNav: [],
            panicWatch: { estate: 'phoenixpark', alerts: [], poll: '/security/panic-watch', checked_at: new Date(RAISED).toISOString() },
        },
        '/estate/phoenixpark/security',
    )

    const view = await mount(SecurityScreen, {
        estate: { name: 'Phoenix Park' },
        alerts,
        incidents: [],
        gate: [],
        alertsTable: tableOf(alerts, 'alerts_', 'state'),
        incidentsTable: tableOf([], 'incidents_', 'occurred'),
        gateTable: tableOf([], 'gate_', 'occurred'),
        presence: { count: 1, officers: [{ name: 'Marcus Reid', post: 'Main Gate', since: '6:00 AM' }] },
        counts: { open_alerts: openAlerts, open_incidents: 0, admitted_30d: 0, refused_30d: 0 },
        sourceBadge: { source: 'devices', simulated: false },
    })

    opened.add(view)
    await flush(nextTick)

    return view
}

const live = async () => {
    socket.setState('connected')
    socket.subscribe(CHANNEL)
    await flush(nextTick)
}

const reloads = () => visits.filter((visit) => visit.method === 'reload')

const tick = async (ms) => {
    mock.timers.tick(ms)
    await flush(nextTick)
}

beforeEach(() => {
    socket.reset()
    mock.timers.enable({ apis: ['Date', 'setInterval', 'setTimeout'], now: RAISED + 1_000 })
    globalThis.fetch = async () => ({ ok: true, json: async () => ({ alerts: [] }) })
})

afterEach(async () => {
    for (const view of opened) {
        view.unmount()
    }

    opened.clear()
    await tick(11_000)
    mock.timers.reset()
})

describe('B108 — the Security screen keeps itself current', () => {
    it('re-reads its lists when the channel says an alert was raised, sent an officer, or a guard clocked on', async () => {
        const view = await open()
        await live()
        visits.length = 0

        for (const [event, payload] of [
            ['.alert.raised', { id: 32, kind: 'panic', estate: 'phoenixpark', is_urgent: true, server_time: new Date(RAISED).toISOString(), unit: 'Lot 9', who: 'A resident' }],
            ['.alert.progressed', { id: 32, stage: 'dispatched', estate: 'phoenixpark' }],
            ['.shift.clocked', { estate: 'phoenixpark' }],
        ]) {
            const before = reloads().length

            socket.emit(CHANNEL, event, payload)
            await tick(1_000)

            assert.ok(reloads().length > before, `${event} re-reads the screen`)
        }

        const only = reloads().at(-1).options.only

        for (const prop of ['alerts', 'alertsTable', 'counts', 'presence', 'incidents', 'incidentsTable', 'gate', 'gateTable']) {
            assert.ok(only.includes(prop), `the re-read carries ${prop}`)
        }

        assert.ok(view.text().length > 0)
    })

    it('asks again every fifteen seconds while an alert on it is open — an acknowledgement is never broadcast', async () => {
        await open({ alerts: [securityAlert()] })
        await live()
        visits.length = 0

        await tick(15_000)

        assert.ok(reloads().length >= 1, 'an open alert: the screen re-reads itself')
    })

    it('does not poll a screen with nothing open while the channel is live', async () => {
        await open({ alerts: [securityAlert({ status: 'resolved', open: false })] })
        await live()
        visits.length = 0

        await tick(45_000)

        assert.equal(reloads().length, 0, 'nothing open, channel live: the pushes are enough')
    })

    it('polls while the channel is down, whatever is on the screen', async () => {
        await open()

        socket.setState('connected')
        socket.refuse(CHANNEL)
        await flush(nextTick)
        visits.length = 0

        await tick(15_000)

        assert.ok(reloads().length >= 1)
    })

    it('counts an alert\'s age on', async () => {
        const view = await open({ alerts: [securityAlert({ ago: 'just now' })] })
        await live()

        await tick(5 * 60_000)

        assert.match(view.text(), /5 minutes ago/)
    })

    it('names a settled alert as its own page does, never "Closed" beside "Resolved" (SEC-7)', async () => {
        const view = await open({
            alerts: [securityAlert({ id: 41, status: 'resolved', open: false }), securityAlert({ id: 42, unit: 'Lot 9', status: 'false_alarm', open: false })],
        })

        assert.match(view.text(), /Resolved/)
        // The Emergency list's word (Hassam, 11 Oct): False alarm, as the alert's own page and dispatch say it.
        assert.match(view.text(), /False alarm/)
        assert.doesNotMatch(view.text(), /Closed(?! as a false alarm)/)
    })

    it('says plainly whether it is live, and does not promise what it cannot (M78 §2: never how the screen works)', async () => {
        const view = await open()
        await live()

        assert.equal(/Live\./.test(view.text()), true)
        assert.equal(/the moment it is raised|re-read|alert channel/i.test(view.text()), false)

        socket.setState('unavailable')
        await flush(nextTick)

        assert.equal(/Live alerts are down\. This list is checked every 15 seconds\./.test(view.text()), true)
    })
})
