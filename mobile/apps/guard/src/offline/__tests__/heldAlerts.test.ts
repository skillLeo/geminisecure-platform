/**
 * A HELD DURESS NEVER WAITS FOR ANYTHING ELSE THE PHONE IS DOING — the Guard App's own runtime, driven
 * with the real outbox and a scripted server.
 *
 * B125: when signal returned, the runtime refreshed the gate's pass knowledge FIRST (most of a minute on weak
 * 3G) and only then drained the queue, oldest act first — so a duress held on the phone went last, after the
 * pull and every queued scan, and never while an older act kept failing.
 */

type Action = { endpoint: string; arg: Record<string, unknown> }
type Answer = { data: unknown } | { error: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null } }

const mockSent: Action[] = []
let mockAnswer: (a: Action) => Answer | Promise<Answer> = () => ({ data: { id: 1 } })
let mockNet: ((s: { isConnected: boolean; isInternetReachable: boolean }) => void) | null = null
const mockRefresh = jest.fn(() => new Promise<'ok' | 'failed'>(() => undefined)) // a pull on weak signal that never finishes

jest.mock('@react-native-community/netinfo', () => ({ __esModule: true, default: { addEventListener: (fn: typeof mockNet) => { mockNet = fn; return () => undefined } } }))
jest.mock('@gs/core/api/guard', () => {
  const endpoint = (name: string) => ({ initiate: (arg: Record<string, unknown>) => ({ endpoint: name, arg }) })
  return { guardApi: { endpoints: { duressStore: endpoint('duress.store'), patrolScan: endpoint('patrol.scan'), incidentsMedia: endpoint('incidents.media') } } }
})
jest.mock('@/store', () => ({
  store: {
    getState: () => ({ session: { status: 'active', principal: { reference: 'GS-9001' } }, network: { online: true } }),
    dispatch: (action: Action | { type: string }) => {
      if (!('endpoint' in action)) return action
      mockSent.push(action)
      return Promise.resolve(mockAnswer(action))
    },
  },
}))
jest.mock('@/features/gate/knowledge', () => ({ gateKnowledge: { get: () => ({ siteId: '4' }), refresh: () => mockRefresh() } }))
jest.mock('@/features/enrolment/onboardingState', () => ({ onboarding: { get: () => ({ site: null }) } }))

import { getStorage } from '@gs/core/lib'
import { outbox, startOfflineRuntime, watchNetwork } from '../runtime'

const duress = { endpoint: 'duress.store' as const, params: {}, body: { mode: 'silent' }, label: 'Duress alert', kind: 'silent', owner: 'GS-9001' }
const scan = (i: number) => ({ endpoint: 'patrol.scan' as const, params: { checkpoint: i }, body: null, label: `Checkpoint ${i}`, kind: 'patrol', owner: 'GS-9001' })
const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve() }

beforeEach(async () => {
  mockSent.length = 0
  mockAnswer = () => ({ data: { id: 1 } })
  await getStorage().clearAll()
  outbox.reset()
})

describe('signal returns with a duress held on the phone', () => {
  it('the duress goes at once: it does not wait for the pass-knowledge pull, nor for the queue in front of it', async () => {
    for (let i = 0; i < 3; i++) await outbox.enqueue(scan(i))
    const alert = await outbox.enqueue(duress)
    watchNetwork()
    mockNet!({ isConnected: false, isInternetReachable: false })
    mockNet!({ isConnected: true, isInternetReachable: true })
    await flush()

    expect(mockRefresh).toHaveBeenCalled() // the pull is still going, and will be for a long time
    expect(mockSent.map((a) => a.endpoint)).toEqual(['duress.store'])
    expect(mockSent[0]!.arg).toMatchObject({ mode: 'silent', __stamp: alert.stamp }) // the captured key, not a new one
    expect(outbox.all().find((o) => o.id === alert.id)?.state).toBe('confirmed')
    expect(outbox.all().filter((o) => o.endpoint === 'patrol.scan').every((o) => o.state === 'pending')).toBe(true) // they wait for the pull, as the sync contract says
  })
})

/*
 * THE M11 §1.7 AIRPLANE TEST (26 Sep): an officer enrolled again after the idle sign-out (which wipes the gate's
 * knowledge with everything else), clocked in, went into a dead spot, and the first pass scanned read "this phone
 * does not yet hold the key this pass was signed with". The knowledge was pulled only on a reconnect or when a gate
 * screen opened. A session start pulls it now, before the queue, as a reconnect does.
 */
describe('a session starts', () => {
  it('pulls the gate’s pass knowledge: without waiting for a reconnect or a gate screen', async () => {
    mockRefresh.mockClear()
    await startOfflineRuntime()
    await flush()
    expect(mockRefresh).toHaveBeenCalledTimes(1)
  })
})

/*
 * B128. A silent duress raised on a slow line or against a failing server is held and offered again every few
 * seconds — by a timer that lives in memory. The coercer kills the app; on relaunch the runtime drained ONCE,
 * that failed too, and nothing offered it again unless the guard opened the Duress screen, which a coerced guard
 * does not do. The phone never reported a reconnect (it had never lost signal), so the alert sat unsent.
 */
describe('the app is killed with a duress held on the phone, and started again', () => {
  afterEach(() => jest.useRealTimers())
  const fault: Answer = { error: { status: 503, code: 'server_error', message: 'Down.', fieldErrors: {}, retryAfter: null } }

  it('the runtime itself keeps offering it, from launch, with no screen open, until the server answers', async () => {
    jest.useFakeTimers()
    const alert = await outbox.enqueue(duress)
    outbox.reset() // the process died: what was in memory is gone, what is on disk is not
    let serverUp = false
    mockAnswer = () => (serverUp ? { data: { id: 700, status: 'open' } } : fault)

    await startOfflineRuntime()
    await flush()
    const atLaunch = mockSent.filter((a) => a.endpoint === 'duress.store').length
    expect(atLaunch).toBeGreaterThanOrEqual(1)

    for (let i = 0; i < 12; i++) { jest.advanceTimersByTime(5_000); await flush() } // a minute on Home, the server still down
    expect(mockSent.filter((a) => a.endpoint === 'duress.store').length).toBeGreaterThanOrEqual(atLaunch + 10)
    expect(outbox.all().find((o) => o.id === alert.id)?.state).toBe('pending')

    serverUp = true
    jest.advanceTimersByTime(5_000)
    await flush()
    expect(outbox.all().find((o) => o.id === alert.id)).toMatchObject({ state: 'confirmed', result: { id: 700 } })
    expect(new Set(mockSent.filter((a) => a.endpoint === 'duress.store').map((a) => (a.arg.__stamp as { idempotencyKey: string }).idempotencyKey)).size).toBe(1) // one alert, however many tries

    const after = mockSent.length
    jest.advanceTimersByTime(30_000)
    await flush()
    expect(mockSent.length).toBe(after) // answered: offering stops
  })
})
