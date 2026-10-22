/**
 * THE FIVE-MINUTE LISTS WAIT FOR THE FIRST SCREEN; THE GATE NEVER WAITS (M11 A4).
 *
 * At launch the Resident notifier used to ask for tickets, payments, every pass and every booking in the
 * same instant as everything Home needs. Proved on the real component, with the API hooks recorded:
 *   - the moment the session is active, the gate (me.household, every 15 s) is asked — and only it;
 *   - SLOW_START_MS later the five-minute lists are asked, with their five-minute polling;
 *   - signing out stops them again, and a new session waits again;
 *   - with no signal, nothing is asked at all (as before).
 */

import { act, render } from '@testing-library/react-native'
import { ResidentNotifier, SLOW_START_MS } from '../ResidentNotifier'

type Opts = { skip?: boolean; pollingInterval?: number }
const mockCalls: Record<string, Opts> = {}
const hook = (name: string) => (_arg: unknown, opts: Opts) => {
  mockCalls[name] = opts
  return { data: undefined }
}
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useMeHouseholdQuery: hook('household'),
    useNoticesIndexQuery: hook('notices'),
    useTicketsIndexQuery: hook('tickets'),
    usePaymentsIndexQuery: hook('payments'),
    useVisitorPassesIndexQuery: hook('passes'),
    useBookingsIndexQuery: hook('bookings'),
  },
}))
jest.mock('@gs/core', () => ({ isFidelity: false, now: () => new Date('2026-09-24T12:00:00Z') }))
jest.mock('@gs/core/lib', () => ({
  flags: { amenityBookings: true },
  getStorage: () => ({ getString: () => null, set: () => undefined }),
  jsonStore: () => ({ get: () => null, set: () => undefined }),
  pollEvery: (ms: number) => ms,
  useDataLight: () => false,
}))
jest.mock('@gs/core/notify', () => ({
  CATEGORY: {},
  configureNotifications: jest.fn(async () => undefined),
  onOpen: () => () => undefined,
  residentDigest: () => ({ notes: [], next: {} }),
  show: jest.fn(),
  usePushRegistration: () => undefined,
}))
jest.mock('expo-router', () => ({ router: { push: jest.fn() }, usePathname: () => '/(tabs)' }))
jest.mock('../prefs', () => ({ allows: () => true }))
jest.mock('../pushRegistration', () => ({ mutedCategories: () => [], pushRegistration: {} }))

let mockState = { session: { status: 'active', principal: { id: 7 }, endedReason: null as string | null, demo: false }, network: { online: true } }
jest.mock('@/store', () => ({
  store: { getState: () => mockState, subscribe: () => () => undefined },
  useAppSelector: (select: (s: unknown) => unknown) => select(mockState),
}))

const SLOW = ['notices', 'tickets', 'payments', 'passes', 'bookings'] as const
const asked = (name: string) => mockCalls[name]?.skip === false

beforeEach(() => {
  jest.useFakeTimers()
  for (const k of Object.keys(mockCalls)) delete mockCalls[k]
  mockState = { session: { status: 'active', principal: { id: 7 }, endedReason: null, demo: false }, network: { online: true } }
})
afterEach(() => jest.useRealTimers())

it('asks for the gate at once and for nothing else until SLOW_START_MS has passed', async () => {
  await render(<ResidentNotifier />)
  expect(asked('household')).toBe(true)
  expect(mockCalls.household?.pollingInterval).toBe(15_000)
  for (const name of SLOW) expect(asked(name)).toBe(false)

  await act(async () => {
    jest.advanceTimersByTime(SLOW_START_MS - 1)
  })
  for (const name of SLOW) expect(asked(name)).toBe(false)

  await act(async () => {
    jest.advanceTimersByTime(1)
  })
  for (const name of SLOW) {
    expect(asked(name)).toBe(true)
    expect(mockCalls[name]?.pollingInterval).toBe(300_000)
  }
  expect(asked('household')).toBe(true)
})

it('a sign-out stops them, and the next session waits again', async () => {
  const view = await render(<ResidentNotifier />)
  await act(async () => {
    jest.advanceTimersByTime(SLOW_START_MS)
  })
  expect(asked('tickets')).toBe(true)

  mockState = { ...mockState, session: { ...mockState.session, status: 'signedOut' } }
  await view.rerender(<ResidentNotifier />)
  expect(asked('tickets')).toBe(false)
  expect(asked('household')).toBe(false)

  mockState = { ...mockState, session: { ...mockState.session, status: 'active' } }
  await view.rerender(<ResidentNotifier />)
  expect(asked('household')).toBe(true)
  expect(asked('tickets')).toBe(false)
  await act(async () => {
    jest.advanceTimersByTime(SLOW_START_MS)
  })
  expect(asked('tickets')).toBe(true)
})

it('with no signal nothing is asked, the gate included', async () => {
  mockState = { ...mockState, network: { online: false } }
  await render(<ResidentNotifier />)
  await act(async () => {
    jest.advanceTimersByTime(SLOW_START_MS)
  })
  expect(asked('household')).toBe(false)
  for (const name of SLOW) expect(asked(name)).toBe(false)
})
