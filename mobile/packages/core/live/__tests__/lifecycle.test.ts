/**
 * WHEN THE SOCKET IS OPEN (DESIGN §8.3, §5.4, §5.6), and what the phone may say about it (§8.5).
 *
 * The controller is driven directly with a recording driver: every open, close, catch-up and poll it asks for is
 * in `calls`, in order, with the fake clock's time. Then the same rules through the real client.
 */

import { dataLight } from '../../lib/dataLight'
import { refreshLive } from '../client'
import type { LiveApp } from '../configure'
import {
  BACKOFF_STEPS_S,
  DATA_LIGHT_CAP_S,
  GUARD_CADENCE_MS,
  JITTER,
  RESIDENT_CADENCE_MS,
  SAFETY_CADENCE_MS,
  backoffMs,
  backoffStepS,
  downCadenceMs,
  holdSafetyScreen,
  serverCloseDelayMs,
} from '../fallback'
import { ATTEMPT_TIMEOUT_MS, BACKGROUND_GRACE_MS, BANNER_GRACE_MS, Lifecycle, PULSE_STALE_MS, SAFETY_CATCH_UP_MS, bannerFor, deriveState, type StatusFacts } from '../lifecycle'
import { PASSES, advance, fakeEnvironment, open, resetLive, setOnline, startHarness } from './fakes'

/**
 * A controller with a recording driver. `refuse`: every attempt fails at once, as on a network that blocks
 * WebSockets (the socket reports the close a moment after it was asked to open).
 */
function controller(over: { app?: LiveApp; fidelity?: boolean; session?: boolean; online?: boolean; appState?: string; refuse?: boolean } = {}) {
  let session = over.session ?? true
  let online = over.online ?? true
  const t0 = Date.now()
  const calls: string[] = []
  const env = fakeEnvironment(0.5)
  if (over.appState) env.set(over.appState)
  const note = (what: string) => calls.push(`${what}@${Date.now() - t0}`)
  const lc: Lifecycle = new Lifecycle(
    { app: over.app ?? 'guard', fidelity: over.fidelity ?? false, sessionLive: () => session, online: () => online },
    env.environment,
    {
      open: () => {
        note('open')
        if (over.refuse) void Promise.resolve().then(() => lc.closed())
      },
      close: () => note('close'),
      catchUpAll: (reason) => note(`catch-up:${reason}`),
      poll: () => note('poll'),
      changed: () => undefined,
    },
  )
  const times = (what: string) => calls.filter((c) => c.startsWith(`${what}@`)).map((c) => Number(c.slice(what.length + 1)))
  return {
    lc,
    calls,
    env,
    elapsed: () => Date.now() - t0,
    /** Only the opens, as the times they happened at. */
    opens: () => times('open'),
    polls: () => times('poll'),
    setOnline(v: boolean) {
      online = v
      lc.factsChanged()
    },
    setSession(v: boolean) {
      session = v
      lc.factsChanged()
    },
  }
}

beforeEach(() => {
  jest.useFakeTimers()
})

afterEach(() => {
  resetLive()
  dataLight.set(false)
  jest.useRealTimers()
})

describe('connect only while everything holds (§8.3)', () => {
  it('a started session, in the foreground, online: it opens at once', () => {
    const c = controller()
    c.lc.start()
    expect(c.calls).toEqual(['open@0'])
    expect(c.lc.socket).toBe('connecting')
  })

  it.each([
    ['no session', { session: false }],
    ['offline', { online: false }],
    ['in the background', { appState: 'background' }],
    ['the fidelity harness', { fidelity: true }],
  ])('%s: nothing opens', (_, over) => {
    const c = controller(over)
    c.lc.start()
    expect(c.calls).toEqual([])
  })

  it('a session that ends closes the socket; a new one opens it again', () => {
    const c = controller()
    c.lc.start()
    c.lc.opened()
    c.setSession(false)
    expect(c.calls).toEqual(['open@0', 'close@0'])
    c.setSession(true)
    expect(c.calls).toEqual(['open@0', 'close@0', 'open@0'])
  })
})

describe('the reconnect backoff (§8.3)', () => {
  it('1, 2, 4, 8, 15, then 30 s: each attempt that fails waits the next step, to the millisecond', async () => {
    const c = controller()
    c.lc.start()
    // random() is 0.5, so the jitter is exactly nothing and the steps show through.
    for (const step of [1000, 2000, 4000, 8000, 15000, 30000, 30000]) {
      const opened = c.opens().length
      c.lc.closed() // the attempt failed
      await advance(step - 1)
      expect(c.opens()).toHaveLength(opened)
      await advance(1)
      expect(c.opens()).toHaveLength(opened + 1)
    }
  })

  it('the jitter is ±30 % and never more', () => {
    for (const [attempt, step] of BACKOFF_STEPS_S.map((s, i) => [i + 1, s] as const)) {
      expect(backoffMs(attempt, 'guard', 0)).toBe(Math.round(step * 1000 * (1 - JITTER)))
      expect(backoffMs(attempt, 'guard', 0.999999)).toBeLessThanOrEqual(Math.round(step * 1000 * (1 + JITTER)))
      expect(backoffMs(attempt, 'guard', 0.999999)).toBeGreaterThan(Math.round(step * 1000 * (1 + JITTER)) - 2)
    }
  })

  it('an attempt that has not opened in 15 s has failed', async () => {
    const c = controller()
    c.lc.start()
    const socketCalls = () => c.calls.filter((x) => !x.startsWith('poll@'))
    await advance(ATTEMPT_TIMEOUT_MS - 1)
    expect(socketCalls()).toEqual(['open@0'])
    await advance(1)
    expect(socketCalls()).toEqual(['open@0', `close@${ATTEMPT_TIMEOUT_MS}`])
    await advance(1000)
    expect(c.opens()).toEqual([0, ATTEMPT_TIMEOUT_MS + 1000])
  })

  it('a server close of a working socket waits 0.5–3 s, to spread the reconnect herd, and does not count as a failure', async () => {
    expect(serverCloseDelayMs(0)).toBe(500)
    expect(serverCloseDelayMs(0.999999)).toBe(3000)
    const c = controller()
    c.lc.start()
    c.lc.opened()
    c.lc.closed()
    await advance(serverCloseDelayMs(0.5) - 1)
    expect(c.opens()).toEqual([0])
    await advance(1)
    expect(c.opens()).toEqual([0, 1750])
    // The next failure starts the backoff from its first step.
    c.lc.closed()
    await advance(1000)
    expect(c.opens()).toEqual([0, 1750, 2750])
  })

  it('the NetInfo edge resets the backoff and reconnects AT ONCE, with a catch-up for everything', async () => {
    const c = controller()
    c.lc.start()
    for (let i = 0; i < 4; i++) {
      c.lc.closed()
      await advance(60_000)
    }
    c.setOnline(false)
    const before = c.calls.length
    c.setOnline(true)
    const t = c.elapsed()
    expect(c.calls.slice(before)).toEqual([`catch-up:online@${t}`, `open@${t}`])
    // …and the next failure waits the FIRST step again.
    c.lc.closed()
    await advance(1000)
    expect(c.opens().at(-1)).toBe(t + 1000)
  })

  it('the resident app in data-light mode with no safety screen open may wait up to 120 s; the guard app never does', () => {
    expect(backoffStepS(7, 'resident', true, false)).toBe(60)
    expect(backoffStepS(8, 'resident', true, false)).toBe(120)
    expect(backoffStepS(20, 'resident', true, false)).toBe(DATA_LIGHT_CAP_S)
    expect(backoffStepS(20, 'resident', false, false)).toBe(30)
    expect(backoffStepS(20, 'guard', true, false)).toBe(30)
    expect(backoffStepS(20, 'resident', true, true)).toBe(30)
  })
})

describe('background and resume (§8.3, §5.4)', () => {
  it('the background disconnects after 10 s, not before', async () => {
    const c = controller()
    c.lc.start()
    c.lc.opened()
    c.env.set('background')
    await advance(BACKGROUND_GRACE_MS - 1)
    expect(c.calls).toEqual(['open@0'])
    await advance(1)
    expect(c.calls).toEqual(['open@0', `close@${BACKGROUND_GRACE_MS}`])
  })

  it('resume catches everything up and reconnects in the same moment, never one after the other', async () => {
    const c = controller()
    c.lc.start()
    c.lc.opened()
    c.env.set('background')
    await advance(BACKGROUND_GRACE_MS + 60_000)
    const before = c.calls.length
    c.env.set('active')
    const now = BACKGROUND_GRACE_MS + 60_000
    expect(c.calls.slice(before)).toEqual([`catch-up:resume@${now}`, `open@${now}`])
  })

  it('back within the grace, it still catches up and reconnects: a socket that slept may be a zombie', async () => {
    const c = controller()
    c.lc.start()
    c.lc.opened()
    c.env.set('background')
    await advance(3000)
    c.env.set('active')
    expect(c.calls).toEqual(['open@0', 'catch-up:resume@3000', 'close@3000', 'open@3000'])
    await advance(BACKGROUND_GRACE_MS)
    expect(c.calls.filter((x) => x.startsWith('close'))).toHaveLength(1) // the grace timer was cancelled
  })

  it('iOS’s inactive (the pull-down shade) is not a departure', async () => {
    const c = controller()
    c.lc.start()
    c.lc.opened()
    c.env.set('inactive')
    c.env.set('active')
    await advance(BACKGROUND_GRACE_MS * 2)
    expect(c.calls.filter((x) => !x.startsWith('poll'))).toEqual(['open@0'])
  })
})

describe('while the socket is down, catch-up is the poll (§5.6); while it is up, every 5 minutes', () => {
  it.each<[LiveApp, boolean, number]>([
    ['guard', false, GUARD_CADENCE_MS],
    ['guard', true, GUARD_CADENCE_MS],
    ['resident', false, RESIDENT_CADENCE_MS],
    ['resident', true, RESIDENT_CADENCE_MS * 4],
  ])('%s app, data-light %s: every %i ms', (app, light, every) => {
    dataLight.set(light)
    expect(downCadenceMs(app)).toBe(every)
  })

  it('the poll keeps its time while every attempt is refused at once: it is not put off by each failure', async () => {
    const c = controller({ app: 'guard', refuse: true })
    c.lc.start()
    await advance(GUARD_CADENCE_MS * 3)
    expect(c.opens().length).toBeGreaterThan(3) // attempts at 0, 1, 3, 7, 15, 30 s…
    expect(c.polls()).toEqual([GUARD_CADENCE_MS, GUARD_CADENCE_MS * 2, GUARD_CADENCE_MS * 3])
  })

  it('useLiveFallback({safety: true}) holds it at 5 s on either app, and data-light does not stretch it', () => {
    dataLight.set(true)
    const release = holdSafetyScreen()
    expect(downCadenceMs('resident')).toBe(SAFETY_CADENCE_MS)
    expect(downCadenceMs('guard')).toBe(SAFETY_CADENCE_MS)
    release()
    release() // counted once
    expect(downCadenceMs('guard')).toBe(GUARD_CADENCE_MS)
  })

  it('on a safety screen with the socket refused, the phone catches up every 5 s from the first 5 s: as today', async () => {
    dataLight.set(true)
    const release = holdSafetyScreen()
    const c = controller({ app: 'resident', refuse: true })
    c.lc.start()
    await advance(SAFETY_CADENCE_MS * 4)
    expect(c.polls()).toEqual([5000, 10000, 15000, 20000])
    release()
  })

  it('a safety screen opening while down moves the next poll to 5 s from then', async () => {
    const c = controller({ app: 'resident' })
    c.lc.start()
    c.lc.closed()
    await advance(2000)
    const release = holdSafetyScreen()
    c.lc.cadenceChanged()
    await advance(SAFETY_CADENCE_MS)
    expect(c.polls()).toEqual([2000 + SAFETY_CADENCE_MS])
    release()
  })

  it('while the socket is up, a safety catch-up runs every 5 minutes', async () => {
    const c = controller()
    c.lc.start()
    c.lc.opened()
    await advance(SAFETY_CATCH_UP_MS - 1)
    expect(c.calls.filter((x) => x.startsWith('poll'))).toHaveLength(0)
    await advance(1)
    expect(c.calls.filter((x) => x.startsWith('poll'))).toEqual([`poll@${SAFETY_CATCH_UP_MS}`])
  })
})

describe('Refresh (§8.5, gate:dead-controls)', () => {
  it('catches up now, and when the socket is down tries it again now', () => {
    const c = controller()
    c.lc.start()
    c.lc.closed()
    const before = c.calls.length
    c.lc.refresh()
    expect(c.calls.slice(before)).toEqual(['catch-up:refresh@0', 'open@0'])
  })

  it('through the real client: the banner’s Refresh runs a catch-up on the transport', async () => {
    const h = await startHarness()
    await open(h)
    const before = h.http.catchUps.length
    refreshLive()
    await advance(0)
    expect(h.http.catchUps.length).toBe(before + 1)
    expect(h.http.catchUps.at(-1)?.channels.map((c) => c.name)).toEqual([PASSES])
  })
})

describe('the real client obeys the same rules', () => {
  it('offline at start: no socket is made until the phone is online', async () => {
    const h = await startHarness({ online: false })
    expect(h.sockets.made).toHaveLength(0)
    h.store.dispatch(setOnline(true))
    await advance(0)
    expect(h.sockets.made).toHaveLength(1)
    expect(h.sockets.last.connects).toBe(1)
  })

  it('a pending resident’s claim is live too: its account follows its own channel (§3.4)', async () => {
    const h = await startHarness({ app: 'resident', status: 'pending' })
    expect(h.sockets.made).toHaveLength(1)
  })

  it('a signed-out phone makes no socket', async () => {
    const h = await startHarness({ status: 'signedOut' })
    expect(h.sockets.made).toHaveLength(0)
  })

  it('fidelity mode starts no socket at all', async () => {
    await jest.isolateModulesAsync(async () => {
      jest.doMock('../../fidelity', () => ({ isFidelity: true, now: () => new Date() }))
      const fakes = require('./fakes') as typeof import('./fakes')
      const h = await fakes.startHarness()
      expect(h.sockets.made).toHaveLength(0)
      expect(h.http.configs).toBe(0)
      fakes.resetLive()
    })
  })
})

describe('the status: the same conditions on both apps and the web (rule 7)', () => {
  const live: StatusFacts = {
    started: true,
    fidelity: false,
    sessionLive: true,
    online: true,
    foreground: true,
    socket: 'open',
    channels: ['live', 'live'],
    catchUpFailing: false,
    relayOk: true,
    lastPulseAt: 99_000,
    openedAt: 0,
  }
  const at = 100_000

  it.each<[string, Partial<StatusFacts>, ReturnType<typeof deriveState>]>([
    ['everything holds', {}, 'live'],
    ['not started', { started: false }, 'off'],
    ['fidelity', { fidelity: true }, 'off'],
    ['in the background', { foreground: false }, 'off'],
    ['offline', { online: false }, 'offline'],
    ['the socket down', { socket: 'closed' }, 'down'],
    ['the socket connecting', { socket: 'connecting' }, 'connecting'],
    ['catch-up failing', { catchUpFailing: true }, 'failing'],
    ['a channel still catching up', { channels: ['live', 'catching'] }, 'catching-up'],
    ['the relay behind', { relayOk: false }, 'delayed'],
    ['the pulse stale (the relay dead)', { lastPulseAt: at - PULSE_STALE_MS - 1, openedAt: 0 }, 'delayed'],
    ['a channel refused', { channels: ['live', 'refused'] }, 'refused'],
  ])('%s → %s', (_, over, state) => {
    expect(deriveState({ ...live, ...over }, at)).toBe(state)
  })

  it('no pulse yet is not a dead relay until 25 s after the socket opened', () => {
    expect(deriveState({ ...live, lastPulseAt: null, openedAt: at - PULSE_STALE_MS }, at)).toBe('live')
    expect(deriveState({ ...live, lastPulseAt: null, openedAt: at - PULSE_STALE_MS - 1 }, at)).toBe('delayed')
  })

  it('a line is drawn only after 3 s, and never while live, off or offline', () => {
    expect(bannerFor('down', 0, BANNER_GRACE_MS - 1)).toBe('none')
    expect(bannerFor('down', 0, BANNER_GRACE_MS)).toBe('paused')
    expect(bannerFor('failing', 0, BANNER_GRACE_MS)).toBe('paused')
    expect(bannerFor('catching-up', 0, BANNER_GRACE_MS)).toBe('paused')
    expect(bannerFor('delayed', 0, BANNER_GRACE_MS)).toBe('delayed')
    expect(bannerFor('refused', 0, BANNER_GRACE_MS)).toBe('refused')
    for (const quiet of ['live', 'off', 'offline'] as const) expect(bannerFor(quiet, 0, 60_000)).toBe('none')
  })
})
