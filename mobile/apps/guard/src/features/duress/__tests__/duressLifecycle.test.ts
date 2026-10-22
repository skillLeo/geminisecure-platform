/**
 * A DURESS ALERT THAT IS NO LONGER LIVE MUST NEVER STAND IN THE WAY OF THE NEXT ONE.
 *
 *   B124  a silent duress that dispatch closed — or one the phone simply stopped hearing about — stayed
 *         "active" on the phone for good, because only the Duress screen ever learned its fate and a silent
 *         alert's guard never opens it. Every later duress, silent or audible, was dropped without being sent,
 *         alertness checks were suppressed, and sign-out stayed refused.
 *   B126  a duress the server REFUSED (a suspended guard's handset: 403 guard_not_active) sat on "NOT RECEIVED
 *         YET … no connection … sent by itself" for good: the server's sentence was dropped, nothing could be
 *         raised again from the phone, and nothing could clear it.
 *
 * Driven through the module's own exports, with the network and the queue scripted. The one active alert lives in
 * module memory, as on a phone; between tests it is cleared the way a guard clears it — Done on the Duress screen.
 */
import { act, renderHook } from '@testing-library/react-native'

jest.mock('expo-haptics', () => ({ notificationAsync: jest.fn(async () => undefined), impactAsync: jest.fn(async () => undefined), NotificationFeedbackType: { Warning: 'warning' }, ImpactFeedbackStyle: { Medium: 'medium' } }))
jest.mock('expo-location', () => ({ getForegroundPermissionsAsync: jest.fn(async () => ({ granted: false })), getLastKnownPositionAsync: jest.fn(), getCurrentPositionAsync: jest.fn(), Accuracy: { Balanced: 3 } }))

type Answer = { data: unknown } | { error: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null } }
const OPEN = { id: 501, status: 'open', cancellable_until: '2026-09-04T14:41:12Z', acknowledged_at: null, cancelled_at: null, server_time: '2026-09-04T14:41:02Z' }
const mockSent: Record<string, unknown>[] = []
let mockAnswer: () => Answer = () => ({ data: OPEN })
let mockLive: { data: unknown; fulfilledTimeStamp?: number } = { data: undefined }
const mockState = { network: { online: true }, session: { status: 'active', principal: { reference: 'GS-9001' } } }

jest.mock('@gs/core/api/guard', () => ({
  guardApi: {
    endpoints: { duressStore: { initiate: jest.fn((arg: Record<string, unknown>) => ({ type: 'duressStore', arg })) } },
    useDuressShowQuery: jest.fn(() => mockLive),
    useDuressCancelMutation: jest.fn(() => [jest.fn()]),
  },
}))
jest.mock('@/store', () => ({
  store: {
    getState: () => mockState,
    dispatch: jest.fn(async (action: { arg: Record<string, unknown> }) => { mockSent.push(action.arg); return mockAnswer() }),
  },
}))

type Op = { id: string; endpoint: string; kind: string; state: string; result: unknown; error: { status: number | string; code: string; message: string } | null }
const mockOps: Op[] = []
let mockOpCount = 0
jest.mock('@/offline/runtime', () => ({
  outbox: {
    enqueue: jest.fn(async (input: { endpoint: string; kind: string }) => { const op = { id: `op-${++mockOpCount}`, endpoint: input.endpoint, kind: input.kind, state: 'pending', result: null, error: null }; mockOps.push(op); return op }),
    settle: jest.fn(async (id: string, result: unknown) => { const op = mockOps.find((o) => o.id === id); if (op) { op.state = 'confirmed'; op.result = result } }),
    all: () => mockOps.map((o) => ({ ...o })),
  },
  offer: jest.fn(async () => undefined),
}))

import { getStorage } from '@gs/core/lib'
import { raiseDuress, useDuress } from '../useDuress'

const T0 = new Date('2026-09-04T14:41:03Z').getTime()
const settle = async () => { await act(async () => { for (let i = 0; i < 10; i++) await Promise.resolve() }) }
const refused = (code: string, message: string): Answer => ({ error: { status: 403, code, message, fieldErrors: {}, retryAfter: null } })
const screen = async () => { const view = await renderHook(() => useDuress(false, () => undefined)); await settle(); return view }
type Extra = { failure?: string | null; heldLine?: string }
const extra = (m: unknown): Extra => m as Extra

beforeEach(async () => {
  jest.useFakeTimers({ now: T0 })
  mockLive = { data: undefined }
  // Whatever the last test left on the phone is cleared the way a guard clears it: Done.
  const view = await screen()
  await act(async () => { view.result.current.finish() })
  await view.unmount()
  await getStorage().clearAll()
  mockSent.length = 0
  mockOps.length = 0
  mockAnswer = () => ({ data: OPEN })
  mockState.network.online = true
})
afterEach(() => jest.useRealTimers())

describe('B124: a duress that is no longer live does not swallow the next one', () => {
  it('dispatch closed it: once the phone knows, the next silent duress IS sent', async () => {
    await raiseDuress('silent')
    expect(mockSent).toHaveLength(1)

    // The phone learns it was resolved (here through the read-back the Duress screen polls).
    mockLive = { data: { ...OPEN, status: 'resolved', resolved_at: '2026-09-04T14:50:00Z' } }
    const view = await screen()
    expect(view.result.current.phase).toBe('closed')
    await view.unmount()

    await raiseDuress('silent') // coerced again, days later
    expect(mockSent).toHaveLength(2)
  })

  it('the phone has not heard about it for minutes: a new press is not swallowed by what may be a closed alert', async () => {
    await raiseDuress('silent')
    jest.setSystemTime(T0 + 5 * 60_000) // nothing heard from the server since
    await raiseDuress('silent')
    expect(mockSent).toHaveLength(2)
  })

  it('a double press while the alert is open and the server has just said so still does nothing', async () => {
    await raiseDuress('silent')
    await raiseDuress('silent')
    expect(mockSent).toHaveLength(1)
  })

  it('a closed alert holds nothing: its screen can raise a new one without Done first', async () => {
    await raiseDuress('audible')
    mockLive = { data: { ...OPEN, status: 'false_alarm', cancelled_at: '2026-09-04T14:41:08Z', resolved_at: '2026-09-04T14:41:08Z' } }
    const view = await screen()
    expect(view.result.current.phase).toBe('closed')
    mockLive = { data: undefined }
    await act(async () => { view.result.current.fire() })
    await settle()
    expect(mockSent).toHaveLength(2)
    await view.unmount()
  })
})

describe('B132: the ten seconds to cancel are counted on the server’s clock, not the phone’s', () => {
  it('a phone fifteen seconds fast still offers the cancel, for the ten seconds the server allows', async () => {
    jest.setSystemTime(T0 + 15_000) // the answer (server 14:41:02) arrives when this phone says 14:41:18
    await raiseDuress('audible')
    const view = await screen()
    expect(view.result.current.canCancel).toBe(true)
    expect(view.result.current.cancelSecondsLeft).toBe(10)
    await view.unmount()
  })

  it('a phone a minute slow stops offering it when the server’s window closes, not a minute later', async () => {
    jest.setSystemTime(T0 - 60_000)
    await raiseDuress('audible')
    const view = await screen()
    expect(view.result.current.canCancel).toBe(true)
    await act(async () => { jest.advanceTimersByTime(10_500) })
    expect(view.result.current.canCancel).toBe(false)
    await view.unmount()
  })
})

describe('B126: a refused duress says so, and never locks the phone', () => {
  it('the server refused it: the screen is not "held, no connection", it carries the server’s sentence, and a new alert can be raised', async () => {
    mockAnswer = () => refused('guard_not_active', 'Your account is not active. Speak to your supervisor.')
    await raiseDuress('audible')
    // The queue's own attempt is refused as well.
    mockOps[0]!.state = 'refused'
    mockOps[0]!.error = { status: 403, code: 'guard_not_active', message: 'Your account is not active. Speak to your supervisor.' }

    const view = await screen()
    expect(view.result.current.phase).not.toBe('queued')
    expect(view.result.current.phase).toBe('failed')
    expect(extra(view.result.current).failure).toBe('Your account is not active. Speak to your supervisor.')

    mockAnswer = () => ({ data: OPEN }) // reinstated
    await act(async () => { view.result.current.fire() })
    await settle()
    expect(mockSent).toHaveLength(2)
    await view.unmount()
  })

  it('refused by the queue later (the direct send only timed out): the phone finds out without the guard doing anything', async () => {
    mockAnswer = () => ({ error: { status: 'timeout', code: 'timeout', message: 'slow', fieldErrors: {}, retryAfter: null } })
    await raiseDuress('silent')
    mockOps[0]!.state = 'refused'
    mockOps[0]!.error = { status: 409, code: 'idempotent_answer_missing', message: 'This alert could not be confirmed. Raise it again.' }
    const view = await screen()
    expect(view.result.current.phase).toBe('failed')
    expect(extra(view.result.current).failure).toBe('This alert could not be confirmed. Raise it again.')
    await view.unmount()
  })

  it('Done clears it', async () => {
    mockAnswer = () => refused('no_site', 'This handset is not posted to a site.')
    await raiseDuress('audible')
    mockOps[0]!.state = 'refused'
    mockOps[0]!.error = { status: 403, code: 'no_site', message: 'This handset is not posted to a site.' }
    const view = await screen()
    expect(view.result.current.phase).toBe('failed')
    await act(async () => { view.result.current.finish() })
    await settle()
    expect(view.result.current.phase).toBe('arm')
    await view.unmount()
  })

  it('a held alert is described by WHY it is held: "no connection" only when that is the reason', async () => {
    mockAnswer = () => ({ error: { status: 503, code: 'server_error', message: 'x', fieldErrors: {}, retryAfter: null } })
    await raiseDuress('audible')
    const server = await screen()
    expect(server.result.current.phase).toBe('queued')
    expect(extra(server.result.current).heldLine).toMatch(/\w/)
    expect(extra(server.result.current).heldLine).not.toMatch(/no connection/i)
    await act(async () => { server.result.current.finish() })
    await server.unmount()

    mockState.network.online = false
    mockAnswer = () => ({ error: { status: 'network', code: 'network_unreachable', message: 'x', fieldErrors: {}, retryAfter: null } })
    await raiseDuress('audible')
    const offline = await screen()
    expect(offline.result.current.phase).toBe('queued')
    expect(extra(offline.result.current).heldLine).toMatch(/no connection/i)
    await offline.unmount()
  })
})
