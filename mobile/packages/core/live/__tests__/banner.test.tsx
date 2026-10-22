/**
 * THE HONESTY LINE (DESIGN §8.5, rule 7, C7): a screen never looks live when it is not — on BOTH apps, with the
 * SAME conditions (revision 1 checked the pulse on the guard app only, and the resident app looked live with the
 * relay dead). One test per state, on the guard's dark tone and the resident's light one; then Refresh, for real,
 * on the fake transport.
 */

import { useContext, useState } from 'react'
import { act, fireEvent, render, screen } from '@testing-library/react-native'
import { Text } from 'react-native'
import { SafeAreaInsetsContext } from 'react-native-safe-area-context'
import type { Tone } from '../../ui/States'
import { LiveStatusBanner } from '../../ui/LiveStatusBanner'
import { BANNER_GRACE_MS, PULSE_STALE_MS, liveStatus, type StatusFacts } from '../lifecycle'
import { PASSES, advance, open, resetLive, startHarness } from './fakes'

// Fidelity mode, switchable per test: every reader goes through the module's binding, so a getter is enough. Defined
// with defineProperty — an object spread is compiled to a helper that reads a getter once, when the mock is made.
let mockFidelity = false
jest.mock('../../fidelity', () => {
  const mocked = Object.assign({}, jest.requireActual('../../fidelity'))
  Object.defineProperty(mocked, 'isFidelity', { enumerable: true, get: () => mockFidelity })
  return mocked
})

// 2:00 PM in Kingston, where the default site zone is.
const T0 = new Date('2026-10-02T19:00:00.000Z').getTime()

let facts: StatusFacts
const LIVE = (): StatusFacts => ({
  started: true,
  fidelity: false,
  sessionLive: true,
  online: true,
  foreground: true,
  socket: 'open',
  channels: ['live', 'live'],
  catchUpFailing: false,
  relayOk: true,
  lastPulseAt: Date.now(),
  openedAt: Date.now() - 60_000,
})

/**
 * Live from 2:00 PM, its pulse arriving all along; at 2:02 PM `change` happens; then `grace` passes. The clock is
 * moved to 2:02 rather than run there: run with one pulse at 2:00, the status would honestly go `delayed` at 2:00:25.
 */
async function after(change: Partial<StatusFacts>, grace = BANNER_GRACE_MS) {
  facts = LIVE()
  await act(async () => liveStatus.attach(() => facts))
  jest.setSystemTime(T0 + 120_000)
  facts = LIVE()
  await act(async () => liveStatus.recompute())
  facts = { ...LIVE(), ...change }
  await act(async () => liveStatus.recompute())
  await act(async () => {
    await jest.advanceTimersByTimeAsync(grace)
  })
}

beforeEach(() => {
  jest.useFakeTimers({ now: T0, doNotFake: ['nextTick', 'queueMicrotask', 'setImmediate'] })
})

afterEach(async () => {
  await act(async () => liveStatus.reset())
  resetLive()
  jest.useRealTimers()
})

describe.each<[string, Tone]>([
  ['the guard app', 'dark'],
  ['the resident app', 'light'],
])('%s', (_, tone) => {
  it('while live: nothing at all, and wrapped content is drawn with no line', async () => {
    await after({ lastPulseAt: T0 + 120_000 })
    await render(<LiveStatusBanner tone={tone} />)
    expect(screen.toJSON()).toBeNull()
    await render(
      <LiveStatusBanner tone={tone}>
        <Text>the screen</Text>
      </LiveStatusBanner>,
    )
    expect(screen.getByText('the screen')).toBeOnTheScreen()
    expect(screen.queryByRole('status')).toBeNull()
  })

  it('down: "Live updates paused · updated just now · Refresh", after 3 s and not before', async () => {
    await after({ socket: 'closed' }, BANNER_GRACE_MS - 1)
    await render(<LiveStatusBanner tone={tone} />)
    expect(screen.toJSON()).toBeNull()
    await act(async () => {
      await jest.advanceTimersByTimeAsync(1)
    })
    expect(screen.getByText('Live updates paused · updated just now')).toBeOnTheScreen()
    // Not one accessibility element on purpose: that would hide Refresh inside it from VoiceOver.
    expect(screen.getByLabelText('Live updates paused · updated just now')).toHaveProp('role', 'status')
    expect(screen.getByRole('button', { name: 'Refresh live updates' })).toBeOnTheScreen()
    // M19A §4.1: a recent moment in words that belong to no clock, redrawn as the minutes turn.
    await act(async () => {
      await jest.advanceTimersByTimeAsync(3 * 60_000)
    })
    expect(screen.getByText('Live updates paused · updated 3 minutes ago')).toBeOnTheScreen()
  })

  it('catch-up failing: paused, with Refresh', async () => {
    await after({ catchUpFailing: true })
    await render(<LiveStatusBanner tone={tone} />)
    expect(screen.getByText('Live updates paused · updated just now')).toBeOnTheScreen()
    expect(screen.getByRole('button', { name: 'Refresh live updates' })).toBeOnTheScreen()
  })

  it('the pulse stale: the relay dead with the socket up: delayed, on this app too', async () => {
    await after({ lastPulseAt: T0 + 120_000 - PULSE_STALE_MS - 1 })
    await render(<LiveStatusBanner tone={tone} />)
    expect(screen.getByText('Live updates are delayed · updated just now')).toBeOnTheScreen()
    expect(screen.getByRole('button', { name: 'Refresh live updates' })).toBeOnTheScreen()
  })

  it('the relay behind: delayed', async () => {
    await after({ relayOk: false })
    await render(<LiveStatusBanner tone={tone} />)
    expect(screen.getByText('Live updates are delayed · updated just now')).toBeOnTheScreen()
  })

  it('refused: said, in grey, with no Refresh: refreshing cannot give back a permission', async () => {
    await after({ channels: ['live', 'refused'] })
    await render(<LiveStatusBanner tone={tone} />)
    expect(screen.getByText('Some live updates are not available to you · updated just now')).toBeOnTheScreen()
    expect(screen.queryByRole('button', { name: 'Refresh live updates' })).toBeNull()
  })

  it('offline: nothing: the app’s own offline line already says so', async () => {
    await after({ online: false, socket: 'closed' }, 60_000)
    await render(<LiveStatusBanner tone={tone} />)
    expect(screen.toJSON()).toBeNull()
  })

  /*
   * M13, 26 Sep: the navigator used to be handed back bare while live and inside two Views while not, so React built
   * a new navigator at every change of status. Coming back from the photo picker (the app in the background, the
   * socket closed, then open again) emptied a half-written maintenance request.
   */
  it('the line coming and going never rebuilds what it wraps: a half-written screen keeps what is on it', async () => {
    let mounts = 0
    function HalfWritten() {
      const [typed] = useState(() => {
        mounts += 1
        return 'Kitchen tap is leaking'
      })
      const insets = useContext(SafeAreaInsetsContext)
      return <Text>{`${typed} · top ${insets?.top}`}</Text>
    }
    const phone = { top: 24, right: 0, bottom: 16, left: 0 }

    await after({ lastPulseAt: T0 + 120_000 })
    await render(
      <SafeAreaInsetsContext.Provider value={phone}>
        <LiveStatusBanner tone={tone}>
          <HalfWritten />
        </LiveStatusBanner>
      </SafeAreaInsetsContext.Provider>,
    )
    expect(screen.getByText('Kitchen tap is leaking · top 24')).toBeOnTheScreen()

    // The app goes into the background: the socket closes, and after 3 s the line comes up and takes the inset.
    facts = { ...LIVE(), socket: 'closed' }
    await act(async () => liveStatus.recompute())
    await act(async () => {
      await jest.advanceTimersByTimeAsync(BANNER_GRACE_MS)
    })
    expect(screen.getByText(/^Live updates paused/)).toBeOnTheScreen()
    expect(screen.getByText('Kitchen tap is leaking · top 0')).toBeOnTheScreen()

    // Back, and live again: the line goes, the inset comes back, and the screen is the one that was there.
    facts = LIVE()
    await act(async () => liveStatus.recompute())
    expect(screen.queryByRole('status')).toBeNull()
    expect(screen.getByText('Kitchen tap is leaking · top 24')).toBeOnTheScreen()
    expect(mounts).toBe(1)
  })

  it('a reconnect loop keeps the line up: going between down and connecting does not restart the 3 s', async () => {
    await after({ socket: 'closed' }, 2000)
    for (let i = 0; i < 6; i++) {
      facts = { ...facts, socket: i % 2 === 0 ? 'connecting' : 'closed' }
      await act(async () => liveStatus.recompute())
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1000)
      })
    }
    await render(<LiveStatusBanner tone={tone} />)
    expect(screen.getByText('Live updates paused · updated just now')).toBeOnTheScreen()
  })
})

describe('fidelity mode', () => {
  afterEach(() => {
    mockFidelity = false
  })

  it('draws nothing, whatever the status says', async () => {
    await after({ socket: 'closed' })
    expect(liveStatus.get().banner).toBe('paused')
    mockFidelity = true
    await render(<LiveStatusBanner tone="dark" />)
    expect(screen.toJSON()).toBeNull()
    await render(
      <LiveStatusBanner tone="light">
        <Text>the screen</Text>
      </LiveStatusBanner>,
    )
    expect(screen.getByText('the screen')).toBeOnTheScreen()
    expect(screen.queryByText(/Live updates/)).toBeNull()
  })
})

describe('Refresh really refreshes (gate:dead-controls)', () => {
  it('pressed on a dropped socket, it runs a catch-up on the transport', async () => {
    const h = await startHarness()
    await open(h)
    // Reverb went away: the socket reports the drop, the reconnect keeps failing, the line comes up.
    h.sockets.last.events.closed()
    await advance(10_000)
    await render(<LiveStatusBanner tone="dark" />)
    expect(screen.getByText(/^Live updates paused/)).toBeOnTheScreen()

    const before = h.http.catchUps.length
    await fireEvent.press(screen.getByRole('button', { name: 'Refresh live updates' }))
    await advance(0)
    expect(h.http.catchUps.length).toBe(before + 1)
    expect(h.http.catchUps.at(-1)?.channels.map((c) => c.name)).toEqual([PASSES])
  })
})
