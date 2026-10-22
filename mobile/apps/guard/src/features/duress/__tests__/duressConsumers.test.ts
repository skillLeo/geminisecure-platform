/**
 * B124, the other half: everything else on the phone that reads the one duress alert. A closed or failed alert
 * used to count as "active" everywhere — alertness checks were suppressed and recorded as missed, sign-out was
 * refused with "wait for the control room to close it" (which could never unblock it), and Home's rings stayed
 * hidden, which a trained guard reads as "dispatch has it". And nothing but the Duress screen ever learned that
 * dispatch had closed an alert.
 */
import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, renderHook } from '@testing-library/react-native'

const OPEN = { id: 501, status: 'open', cancellable_until: '2026-09-04T14:41:12Z', acknowledged_at: null, cancelled_at: null, server_time: '2026-09-04T14:41:02Z' }
let mockLive: { data: unknown; fulfilledTimeStamp?: number } = { data: undefined }
const mockShowArgs: { arg: unknown; opts: { skip?: boolean; pollingInterval?: number } }[] = []
const mockState = { network: { online: true }, session: { status: 'active', principal: { reference: 'GS-9001' } }, outbox: { ops: [] as unknown[] } }

jest.mock('expo-router', () => ({ router: { replace: jest.fn(), push: jest.fn() }, usePathname: () => '/(tabs)' }))
jest.mock('@gs/core/api/guard', () => ({
  guardApi: {
    endpoints: { authSignOut: { initiate: jest.fn() }, duressStore: { initiate: jest.fn() } },
    useDuressShowQuery: jest.fn((arg: unknown, opts: { skip?: boolean; pollingInterval?: number }) => { mockShowArgs.push({ arg, opts }); return opts.skip ? { data: undefined } : mockLive }),
  },
}))
jest.mock('@gs/core/auth', () => ({ clearEverything: jest.fn(async () => undefined), endServerSession: jest.fn(async () => undefined), signedOut: () => ({ type: 'signedOut' }) }))
jest.mock('@gs/core/notify', () => ({ clearNotifications: jest.fn(async () => undefined) }))
jest.mock('@/features/notify/pushRegistration', () => ({ pushRegistration: { signOut: jest.fn(async () => undefined) } }))
jest.mock('@/offline/runtime', () => ({ drainNow: jest.fn(async () => undefined), forgetQueue: jest.fn(), outbox: { all: () => [] }, offer: jest.fn() }))
jest.mock('@/store', () => ({
  store: { getState: () => mockState, dispatch: jest.fn() },
  useAppDispatch: () => jest.fn(),
  useAppSelector: (select: (s: unknown) => unknown) => select(mockState),
}))

import { getStorage, jsonStore } from '@gs/core/lib'
import { SILENT_BLOCK, useSignOut } from '@/features/more/useSignOut'

const settle = async () => { await act(async () => { for (let i = 0; i < 10; i++) await Promise.resolve() }) }
const saved = (alert: Record<string, unknown> | null, extra: Record<string, unknown> = {}) =>
  jsonStore(getStorage(), 'duress.active', 'v1').set({ mode: 'audible', alert, opId: 'op-1', pressedAt: '2026-09-04T14:41:02Z', withPosition: false, ...extra })

beforeEach(async () => {
  await getStorage().clearAll()
  mockLive = { data: undefined }
  mockShowArgs.length = 0
})

describe('sign-out follows the alert, not the leftover record of one', () => {
  it('a CLOSED alert does not refuse sign-out: "wait for the control room to close it" is over', async () => {
    saved({ ...OPEN, status: 'resolved', resolved_at: '2026-09-04T14:50:00Z' })
    let out: ReturnType<typeof useSignOut> | undefined
    jest.isolateModules(() => { out = undefined })
    const view = await renderHook(() => useSignOut())
    await settle()
    out = view.result.current
    expect(out.blocked).toBeNull()
    await view.unmount()
  })
})

describe('B130: a silent alert is never named where somebody else can read it', () => {
  it('sign-out is refused while a SILENT alert is live, in words that say nothing about an alert', async () => {
    saved(OPEN, { mode: 'silent' })
    const view = await renderHook(() => useSignOut())
    await settle()
    expect(view.result.current.blocked).toBe(SILENT_BLOCK)
    expect(view.result.current.blocked).not.toMatch(/duress|alert|panic|control room/i)
    await view.unmount()
  })

  it('an AUDIBLE one still says what it is: the guard raised it openly', async () => {
    saved(OPEN)
    const view = await renderHook(() => useSignOut())
    await settle()
    expect(view.result.current.blocked).toMatch(/active duress alert/)
    await view.unmount()
  })

  it('the Delete-account screen shows the same sentence, from the same hook', () => {
    const close = readFileSync(path.join(__dirname, '..', '..', 'more', 'screens', 'CloseAccountScreen.tsx'), 'utf8')
    // The same hook, told it is a removal from this phone rather than a sign-out (M32 F3).
    expect(close).toMatch(/useSignOut\(\{ removeFromPhone: true \}\)/)
    expect(close).not.toMatch(/duress/i)
  })
})

describe('the rest of the phone reads whether the alert is LIVE', () => {
  const read = (rel: string) => readFileSync(path.join(__dirname, '..', '..', rel), 'utf8')

  it('alertness checks are held back only while an alert is live', () => {
    expect(read('alertness/AlertnessWatcher.tsx')).toMatch(/duressActive: isLive\(duress\)/)
  })

  it('Home’s rings are gone only while an alert is live: back once it is closed or did not get through', () => {
    const home = read('dashboard/screens/DashboardScreen.tsx')
    // Red live (the client, 27 Sep: "the alert button red"), the board's amber under the fidelity harness.
    expect(home).toMatch(/\{isLive\(activeDuress\) \? null : <View style=\{\[s\.fabRing, isFidelity \? null : s\.fabRingRed\]\} aria-hidden \/>\}/)
    expect(home).toMatch(/\{isLive\(activeDuress\) \? null : <View style=\{\[s\.fabRing2, isFidelity \? null : s\.fabRingRed\]\} aria-hidden \/>\}/)
  })

  it('a watcher mounted at the root keeps the alert’s fate up to date: the Duress screen need not be open', () => {
    const layout = readFileSync(path.join(__dirname, '..', '..', '..', '..', 'app', '_layout.tsx'), 'utf8')
    expect(layout).toMatch(/<DuressWatcher \/>/)
  })
})

describe('the root watcher', () => {
  it('polls the alert’s read-back while it is open, and writes what dispatch did: with no screen open', async () => {
    saved(OPEN)
    const { DuressWatcher } = require('../DuressWatcher') as { DuressWatcher: () => null }
    const { useActiveDuress } = require('../useDuress') as { useActiveDuress: () => { alert: { status: string } | null } | null }
    mockLive = { data: { ...OPEN, status: 'resolved', resolved_at: '2026-09-04T14:50:00Z' }, fulfilledTimeStamp: Date.now() }
    const view = await renderHook(() => { DuressWatcher(); return useActiveDuress() })
    await settle()
    const polled = mockShowArgs.find((c) => !c.opts.skip)
    expect(polled?.arg).toEqual({ alert: 501 })
    expect(polled?.opts.pollingInterval).toBeGreaterThan(0)
    expect(view.result.current?.alert?.status).toBe('resolved')
    await view.unmount()
  })

  it('asks nothing while there is no open alert', async () => {
    const { DuressWatcher } = require('../DuressWatcher') as { DuressWatcher: () => null }
    const view = await renderHook(() => DuressWatcher())
    await settle()
    expect(mockShowArgs.every((c) => c.opts.skip === true)).toBe(true)
    await view.unmount()
  })
})
