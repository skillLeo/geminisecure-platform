/**
 * RingBridge — the call-style ring's JS half (GUARD_RING_DESIGN §8.2). The native module is a fake: what is proved
 * here is what the app TELLS it, and when.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'
import { AppState } from 'react-native'
import { Provider } from 'react-redux'
import { act, render } from '@testing-library/react-native'

jest.mock('../native', () => {
  const readiness = {
    v: 1, configured: true, sdk: 35, maker: 'google', notifications: 'on', ringChannel: 'on', fullScreen: 'on', dndAccess: true, channelBypass: true,
    battery: 'exempt', background: 'allowed', alarmVolume: 5, alarmVolumeMax: 7, alarmTone: 'set', screenLock: 'on', biometric: false, interruptionFilter: 1, soundDetail: '',
  }
  const listeners: ((e: { type: string; id: string }) => void)[] = []
  type MockRing = { readinessValue: Record<string, unknown>; [mockKey: string]: unknown }
  const ring: MockRing = {
    readinessValue: readiness,
    listeners,
    configure: jest.fn(async () => true),
    readiness: jest.fn(async () => ({ ...ring.readinessValue })),
    openSetting: jest.fn(async () => true),
    setQuiet: jest.fn(async () => undefined),
    flushPending: jest.fn(async () => 0),
    consumeLaunch: jest.fn(async (): Promise<string | null> => null),
    restore: jest.fn(async () => 0),
    stopAll: jest.fn(async () => undefined),
    forget: jest.fn(async () => undefined),
    addListener: jest.fn((_: string, fn: (e: { type: string; id: string }) => void) => { listeners.push(fn); return { remove: () => undefined } }),
  }
  return { GuardRing: ring, availability: () => 'android' }
})

let mockDuress: unknown = null
let mockLive = false
jest.mock('@/features/duress/useDuress', () => ({
  useActiveDuress: () => mockDuress,
  isLive: () => mockLive,
}))

let mockPath = '/'
jest.mock('expo-router', () => ({ router: { push: jest.fn() }, usePathname: () => mockPath }))
jest.mock('@gs/core', () => ({ isFidelity: false }))
jest.mock('@gs/core/api/guard', () => ({ guardApi: { util: { invalidateTags: (tags: string[]) => ({ type: 'test/invalidate', payload: tags }) } } }))
jest.mock('@gs/core/lib', () => ({ apiUrl: () => 'https://ring.test/api/v1' }))
jest.mock('@gs/core/notify', () => ({ nudgePushRegistration: jest.fn() }))

jest.mock('@/store', () => {
  const { configureStore } = require('@reduxjs/toolkit')
  const { useSelector } = require('react-redux')
  type MockSession = { status: string; endedReason: string | null }
  const session = (state: MockSession = { status: 'booting', endedReason: null }, action: { type: string; payload?: Partial<MockSession> }): MockSession =>
    action.type === 'test/session' ? { ...state, ...action.payload } : state
  const network = (state = { online: true }, action: { type: string; payload?: boolean }) => (action.type === 'test/online' ? { online: action.payload } : state)
  const invalidated = (state: string[][] = [], action: { type: string; payload?: string[] }) => (action.type === 'test/invalidate' ? [...state, action.payload] : state)
  const store = configureStore({ reducer: { session, network, invalidated } })
  return { store, useAppSelector: useSelector }
})

import { router } from 'expo-router'
import { nudgePushRegistration } from '@gs/core/notify'
import { store } from '@/store'
import { QUIET_MS, RingBridge, forgetReadiness, quietFor } from '../RingBridge'

type Fake = {
  readinessValue: Record<string, unknown>
  listeners: ((e: { type: string; id: string }) => void)[]
  [k: string]: jest.Mock | unknown
}
const ring = (jest.requireMock('../native') as { GuardRing: Fake }).GuardRing
const fn = (name: string) => ring[name] as jest.Mock
const setSession = (status: string, endedReason: string | null = null) => act(() => { store.dispatch({ type: 'test/session', payload: { status, endedReason } }) })
const setOnline = (online: boolean) => act(() => { store.dispatch({ type: 'test/online', payload: online }) })

let appListeners: ((state: string) => void)[] = []
const cameToFront = async () => { await act(async () => { for (const l of appListeners) l('active') }) }
const settle = () => act(async () => { await new Promise((r) => setTimeout(r, 0)) })

beforeEach(() => {
  jest.clearAllMocks()
  appListeners = []
  jest.spyOn(AppState, 'addEventListener').mockImplementation(((_: string, l: (state: string) => void) => { appListeners.push(l); return { remove: () => undefined } }) as never)
  ring.listeners.length = 0
  mockDuress = null
  mockLive = false
  mockPath = '/'
  forgetReadiness()
})

async function mount(status = 'active', endedReason: string | null = null) {
  await setSession(status, endedReason)
  const view = await render(<Provider store={store}><RingBridge /></Provider>)
  await settle()
  return view
}

describe('RingBridge', () => {
  it('tells the module where the server is: the app\'s one API address', async () => {
    await mount()
    expect(fn('configure')).toHaveBeenCalledWith('https://ring.test/api/v1')
  })

  it('quiet follows the guard\'s own live duress, an hour at a time', async () => {
    mockDuress = { alert: { status: 'open' } }
    mockLive = true
    const before = Date.now()
    await mount()
    const until = fn('setQuiet').mock.calls.at(-1)![0] as number
    expect(until).toBeGreaterThanOrEqual(before + QUIET_MS)
    expect(until).toBeLessThanOrEqual(Date.now() + QUIET_MS)
  })

  it('a silent duress held on the phone and still to be sent keeps the ring quiet', async () => {
    mockDuress = { alert: null, opId: 'op-1', mode: 'silent' }
    mockLive = true
    await mount()
    expect(fn('setQuiet').mock.calls.at(-1)![0]).toEqual(expect.any(Number))
  })

  it('no duress at all: quiet is cleared', async () => {
    await mount()
    expect(fn('setQuiet')).toHaveBeenLastCalledWith(null)
  })

  it('a held alert whose fate cannot be read yet leaves quiet alone: it ends by itself, and a betrayed silent alarm cannot be taken back', () => {
    expect(quietFor({ alert: null, opId: 'op-1' } as never)).toBe('leave')
    expect(quietFor(null)).toBe('clear')
    mockLive = true
    expect(quietFor({ alert: null, opId: 'op-1' } as never)).toBe('quiet')
    mockLive = false
    expect(quietFor({ alert: { status: 'resolved' } } as never)).toBe('clear')
  })

  it('never touches quiet without a signed-in session, where the duress record cannot be read truthfully', async () => {
    mockDuress = { alert: { status: 'open' } }
    mockLive = false
    await mount('signedOut', 'expired')
    expect(fn('setQuiet')).not.toHaveBeenCalled()
  })

  it('sends what is waiting when signed in and online, and not when offline', async () => {
    await setOnline(false)
    await mount()
    expect(fn('flushPending')).not.toHaveBeenCalled()
    await setOnline(true)
    await settle()
    expect(fn('flushPending')).toHaveBeenCalled()
  })

  it('shows the alert whose ring screen was opened: only for a signed-in guard', async () => {
    fn('consumeLaunch').mockResolvedValueOnce('4812')
    await mount()
    expect(router.push).toHaveBeenCalledWith('/alert/4812')
  })

  it('a ring opened while nobody is signed in opens nothing', async () => {
    fn('consumeLaunch').mockResolvedValueOnce('4812')
    await mount('signedOut', 'expired')
    expect(router.push).not.toHaveBeenCalled()
  })

  it('an opened ring routes once, and not onto the alert already on screen', async () => {
    const view = await mount()
    mockPath = '/alert/4815' // the guard navigated: the router re-renders the layout
    await view.rerender(<Provider store={store}><RingBridge /></Provider>)
    await act(async () => { for (const l of ring.listeners) l({ type: 'opened', id: '4812' }) })
    expect(router.push).toHaveBeenCalledWith('/alert/4812')
    ;(router.push as jest.Mock).mockClear()
    await act(async () => { for (const l of ring.listeners) l({ type: 'opened', id: '4815' }) })
    expect(router.push).not.toHaveBeenCalled()
  })

  it('a stop or an answer refreshes the alert on screen', async () => {
    await mount()
    await act(async () => { for (const l of ring.listeners) l({ type: 'stopped', id: '4812' }) })
    expect((store.getState() as unknown as { invalidated: string[][] }).invalidated.at(-1)).toEqual(['alerts'])
  })

  it('stops and forgets every ring at sign-out and when the office takes the handset back, never when the session merely expired', async () => {
    await mount()
    await setSession('signedOut', 'expired')
    await settle()
    expect(fn('stopAll')).not.toHaveBeenCalled()
    expect(fn('forget')).not.toHaveBeenCalled()
    await setSession('active')
    await setSession('signedOut', 'revoked')
    await settle()
    expect(fn('stopAll')).toHaveBeenCalledTimes(1)
    expect(fn('forget')).toHaveBeenCalledTimes(1)
    await setSession('active')
    await setSession('signedOut', 'signed_out')
    await settle()
    expect(fn('stopAll')).toHaveBeenCalledTimes(2)
  })

  it('reads readiness again on every return to the app; a changed report nudges the registration, the same one does not', async () => {
    await mount()
    const reads = fn('readiness').mock.calls.length
    await cameToFront()
    await settle()
    expect(fn('readiness').mock.calls.length).toBeGreaterThan(reads)
    expect(nudgePushRegistration).not.toHaveBeenCalled()
    ring.readinessValue = { ...ring.readinessValue, fullScreen: 'off' }
    await cameToFront()
    await settle()
    expect(nudgePushRegistration).toHaveBeenCalledTimes(1)
  })
})

describe('what the guard app must never do', () => {
  it('no file under apps/guard calls setBadgeCountAsync: on Android it cancels every notification, a live ring included (G4)', () => {
    const root = path.resolve(__dirname, '..', '..', '..', '..')
    const walk = (dir: string): string[] => readdirSync(dir).flatMap((name) => {
      if (['node_modules', 'android', 'ios', '.expo', '__tests__'].includes(name)) return []
      const full = path.join(dir, name)
      return statSync(full).isDirectory() ? walk(full) : /\.(ts|tsx|js|cjs)$/.test(name) ? [full] : []
    })
    const offenders = walk(root).filter((f) => /setBadgeCountAsync/.test(readFileSync(f, 'utf8')))
    expect(offenders).toEqual([])
  })

  it('RingBridge only reads the duress record, and imports no sound', () => {
    const source = readFileSync(path.resolve(__dirname, '..', 'RingBridge.tsx'), 'utf8')
    expect(source).not.toMatch(/expo-av|expo-audio|Vibration|expo-haptics|expo-notifications/)
    expect(source).not.toMatch(/raiseDuress|useFollowDuress/)
  })
})
