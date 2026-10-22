/**
 * B120 — THE E-PASS IS COLLECTED AFRESH BEFORE IT RUNS OUT, WITH NO SCREEN OPEN.
 *
 * The e-Pass screen promised "the app collects a fresh one about an hour before it runs out", and nothing did:
 * the only request for the pass was made while that screen was open. A pass opened at 8 AM Monday was gone at
 * the gate at 8:30 AM Tuesday.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, renderHook } from '@testing-library/react-native'
import { AppState } from 'react-native'

const T0 = new Date('2026-09-24T13:00:00.000Z').getTime()
const HOUR = 3_600_000

jest.mock('@gs/core', () => {
  const lib = jest.requireActual('@gs/core/lib')
  return { useOfflineCache: lib.useOfflineCache, saveOfflineCache: lib.saveOfflineCache, readOfflineCache: lib.readOfflineCache, isFidelity: false, now: () => new Date(Date.now()) }
})

let mockData: Record<string, unknown> | undefined
let mockError: unknown
const mockRefetch = jest.fn(async () => undefined)
const mockQueryArgs: { skip?: boolean }[] = []
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useEpassShowQuery: (_arg: unknown, opts: { skip?: boolean }) => {
      mockQueryArgs.push(opts)
      return { data: opts.skip ? undefined : mockData, error: opts.skip ? undefined : mockError, refetch: mockRefetch }
    },
  },
}))

const mockState = { network: { online: true }, session: { status: 'active' } }
jest.mock('@/store', () => ({ store: { getState: () => mockState }, useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

import { getStorage } from '@gs/core/lib'
import { EpassKeeper, OPENED_WITHIN_MS, epassDue, refreshAtOf } from '../EpassKeeper'

let appStateHandler: ((state: string) => void) | null = null
beforeAll(() => {
  jest.spyOn(AppState, 'addEventListener').mockImplementation(((_type: string, fn: (state: string) => void) => {
    appStateHandler = fn
    return { remove: () => { appStateHandler = null } }
  }) as never)
})

const pass = (validTo: number, refreshAfter: number | null) => ({ valid_to: new Date(validTo).toISOString(), refresh_after: refreshAfter === null ? null : new Date(refreshAfter).toISOString() })

beforeEach(async () => {
  jest.useFakeTimers({ now: T0 })
  await getStorage().clearAll()
  mockRefetch.mockClear()
  mockQueryArgs.length = 0
  mockState.network.online = true
  mockState.session.status = 'active'
  mockData = pass(T0 + 2 * HOUR, T0 + HOUR)
  mockError = undefined
})
afterEach(() => jest.useRealTimers())

describe('B123: "No connection" only when there is none', () => {
  it('says why a saved copy is on screen: the server failing with the phone online is not a lost signal', () => {
    const { staleLeadOf } = require('../passes') as { staleLeadOf: (online: boolean) => string }
    expect(staleLeadOf(false)).toBe('No connection')
    expect(staleLeadOf(true)).toBe('This could not be refreshed just now')
  })

  // The Visitors tab draws no saved-copy note since its rebuild from board 08 (M42 §5 #7); the pass screen still does.
  it('the pass screen leads with it, and does not say "No connection" by itself any more', () => {
    for (const file of ['PassScreen.tsx']) {
      const screen = readFileSync(path.join(__dirname, '..', 'screens', file), 'utf8')
      expect(screen).toContain('${m.staleLead}:')
      expect(screen).not.toMatch(/'No connection: (showing the passes|this is the pass)/)
    }
  })
})

describe('when a fresh pass is due', () => {
  it('at the server’s refresh_after, or an hour before valid_to when a copy has none', () => {
    expect(refreshAtOf(pass(T0 + 2 * HOUR, T0 + HOUR))).toBe(T0 + HOUR)
    expect(refreshAtOf(pass(T0 + 5 * HOUR, null))).toBe(T0 + 4 * HOUR)
    expect(refreshAtOf(null)).toBeNull()
  })

  it('now, when there is no pass on the phone at all', () => {
    expect(epassDue(null, T0)).toBe(true)
    expect(epassDue(pass(T0 + 2 * HOUR, T0 + HOUR), T0)).toBe(false)
    expect(epassDue(pass(T0 + 2 * HOUR, T0 + HOUR), T0 + HOUR)).toBe(true)
  })
})

describe('the keeper, mounted at the root', () => {
  const mount = async () => {
    const view = await renderHook(() => EpassKeeper())
    await act(async () => { await Promise.resolve() })
    return view
  }

  it('collects the next pass at its refresh time, while the app stays open', async () => {
    const view = await mount()
    expect(mockRefetch).not.toHaveBeenCalled() // not due yet
    await act(async () => { jest.advanceTimersByTime(HOUR + 1000) })
    expect(mockRefetch).toHaveBeenCalled()
    await view.unmount()
  })

  it('with no connection it asks nothing, and asks the moment the connection returns', async () => {
    mockState.network.online = false
    const view = await mount()
    await act(async () => { jest.advanceTimersByTime(HOUR + 1000) })
    expect(mockRefetch).not.toHaveBeenCalled()
    mockState.network.online = true
    await view.rerender(undefined)
    await act(async () => { await Promise.resolve() })
    expect(mockRefetch).toHaveBeenCalled()
    await view.unmount()
  })

  it('coming back to the front after the refresh time collects it then', async () => {
    const view = await mount()
    jest.setSystemTime(T0 + HOUR + 60_000) // the phone slept through the timer
    mockRefetch.mockClear()
    await act(async () => { appStateHandler?.('active') })
    expect(mockRefetch).toHaveBeenCalled()
    await view.unmount()
  })

  it("a Helper's e-Pass turned off by the household: the day kept on this phone goes too (8 Oct 2026)", async () => {
    const { readOfflineCache } = jest.requireActual('@gs/core/lib') as { readOfflineCache: (key: string) => unknown }
    const view = await mount()
    expect(readOfflineCache('passes.epass')).toEqual(mockData)
    mockData = undefined
    mockError = { status: 403, code: 'access_paused', message: 'Your household has paused your e-Pass, so the gate will not admit you on it.' }
    await view.rerender(undefined)
    await act(async () => { await Promise.resolve() })
    expect(readOfflineCache('passes.epass')).toBeUndefined()
    await view.unmount()
  })

  it('any other failure keeps the day it holds: a gate with no signal still needs it', async () => {
    const { readOfflineCache } = jest.requireActual('@gs/core/lib') as { readOfflineCache: (key: string) => unknown }
    const view = await mount()
    const kept = mockData
    mockData = undefined
    mockError = { status: 'network', code: 'network', message: 'No connection' }
    await view.rerender(undefined)
    await act(async () => { await Promise.resolve() })
    expect(readOfflineCache('passes.epass')).toEqual(kept)
    await view.unmount()
  })

  it('asks for nothing while nobody is signed in', async () => {
    mockState.session.status = 'signedOut'
    const view = await mount()
    await act(async () => { jest.advanceTimersByTime(3 * HOUR) })
    expect(mockQueryArgs.every((o) => o.skip === true)).toBe(true)
    expect(mockRefetch).not.toHaveBeenCalled()
    await view.unmount()
  })

  it('is mounted in the root layout, and the screen says one short line (M47 §6c), not the 24-hour paragraph', () => {
    const root = path.join(__dirname, '..', '..', '..', '..')
    expect(readFileSync(path.join(root, 'app', '_layout.tsx'), 'utf8')).toMatch(/<EpassKeeper \/>/)
    const screen = readFileSync(path.join(__dirname, '..', 'screens', 'PassScreen.tsx'), 'utf8')
    expect(screen).not.toContain('Each pass lasts 24 hours')
    expect(screen).toContain('Show this at the gate. It works with no connection. Each code lasts 30 minutes')
  })

  it('collects nothing ahead for an app nobody has brought to the front for a day, and again once it is', async () => {
    const view = await mount()
    // The app sits in the background, still running: through its first day the timers collect as before…
    await act(async () => { jest.advanceTimersByTime(OPENED_WITHIN_MS - HOUR) })
    expect(mockRefetch).toHaveBeenCalled()
    // …and once a day has passed without it being brought to the front, they fire and ask for nothing.
    await act(async () => { jest.advanceTimersByTime(HOUR + 60_000) })
    mockRefetch.mockClear()
    await act(async () => { jest.advanceTimersByTime(3 * HOUR) })
    expect(mockRefetch).not.toHaveBeenCalled()
    // Opened again: the day ahead is collected at once.
    await act(async () => { appStateHandler?.('active') })
    expect(mockRefetch).toHaveBeenCalled()
    await view.unmount()
  })
})
