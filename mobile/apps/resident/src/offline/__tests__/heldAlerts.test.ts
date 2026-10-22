/**
 * A HELD PANIC IS OFFERED AGAIN BY THE RUNTIME, NOT BY A SCREEN — the Resident App's own runtime, driven with
 * the real outbox and a scripted server.
 *
 * The same gap as the Guard App's B128: the retry for a held alert was a timer in memory, re-armed after a
 * restart only by the panic screen. A resident who pressed panic on a slow line, lost the app (the OS reclaims
 * it) and reopened it on Home had an alert that was tried once at launch and then never again.
 */

type Action = { endpoint: string; arg: Record<string, unknown> }
type Answer = { data: unknown } | { error: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null } }

const mockSent: Action[] = []
let mockAnswer: (a: Action) => Answer = () => ({ data: { id: 1 } })

jest.mock('@react-native-community/netinfo', () => ({ __esModule: true, default: { addEventListener: () => () => undefined } }))
jest.mock('@gs/core/api/resident', () => {
  const endpoint = (name: string) => ({ initiate: (arg: Record<string, unknown>) => ({ endpoint: name, arg }) })
  return { residentApi: { endpoints: { duressStore: endpoint('duress.store'), noticesRead: endpoint('notices.read') } } }
})
jest.mock('@/store', () => ({
  store: {
    getState: () => ({ session: { status: 'active', demo: false, principal: { id: 41, name: 'Andrea' } }, network: { online: true } }),
    dispatch: (action: Action | { type: string }) => {
      if (!('endpoint' in action)) return action
      mockSent.push(action)
      return Promise.resolve(mockAnswer(action))
    },
  },
}))

import { getStorage } from '@gs/core/lib'
import { outbox, startOfflineRuntime } from '../runtime'

const panic = { endpoint: 'duress.store' as const, params: {}, body: { mode: 'audible' }, label: 'Emergency alert', kind: 'alert', owner: 'account:41' }
const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve() }
const fault: Answer = { error: { status: 503, code: 'server_error', message: 'Down.', fieldErrors: {}, retryAfter: null } }

afterEach(() => jest.useRealTimers())

it('a panic found held on the phone at launch is offered again every few seconds, with no screen open, until it is answered', async () => {
  jest.useFakeTimers()
  await getStorage().clearAll()
  const alert = await outbox.enqueue(panic)
  outbox.reset() // the app was killed: memory gone, disk kept
  let serverUp = false
  mockAnswer = () => (serverUp ? { data: { id: 812, status: 'open' } } : fault)

  await startOfflineRuntime()
  await flush()
  const atLaunch = mockSent.length
  for (let i = 0; i < 12; i++) { jest.advanceTimersByTime(5_000); await flush() }
  expect(mockSent.length).toBeGreaterThanOrEqual(atLaunch + 10)

  serverUp = true
  jest.advanceTimersByTime(5_000)
  await flush()
  expect(outbox.all().find((o) => o.id === alert.id)).toMatchObject({ state: 'confirmed', result: { id: 812 } })
})
