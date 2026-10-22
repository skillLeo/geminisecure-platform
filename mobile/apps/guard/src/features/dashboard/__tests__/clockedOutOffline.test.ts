/**
 * A SHIFT ENDED WITH NO SIGNAL IS NOT DRAWN AS "ON POST" (the M11 §1.7 airplane test, 26 Sep).
 *
 * The saved shift still says `on_duty` until the clock-out reaches the server, and the card read "On post · 9h 28m
 * remaining" over a shift the officer had just ended in a dead spot. A clock-out waiting on the phone is now said as
 * exactly that — "Clocked out", the times, "Clock-out saved on this phone — pending sync" — with nothing to press.
 */

import { renderHook } from '@testing-library/react-native'
import { getStorage, timeCompact } from '@gs/core/lib'
import { useDashboard } from '../useDashboard'

const SHIFT = {
  id: 41,
  status: 'on_duty',
  on_break: false,
  actual_start: '2026-09-20T12:00:00.000Z',
  actual_end: null,
  rostered_start: '2026-09-20T12:00:00.000Z',
  rostered_end: '2026-09-21T00:00:00.000Z',
  post: { id: 3, name: 'Main Gate' },
  site: { id: 'phoenixpark', name: 'Phoenix Park Village 1' },
  colleagues: [],
  orders_to_acknowledge: 0,
}

let mockCurrent: unknown = null
jest.mock('@gs/core/api/guard', () => ({
  guardApi: { useShiftsMeCurrentQuery: () => (mockCurrent ??= { data: { shift: mockShift }, fulfilledTimeStamp: 1, isLoading: false, error: undefined, refetch: jest.fn() }) },
}))
jest.mock('@gs/core/api', () => ({ isApiError: () => false }))
jest.mock('@gs/core', () => ({ ...jest.requireActual('@gs/core/lib'), isFidelity: false, fidelityScreenId: () => null, now: () => mockNow }))
jest.mock('@/fidelity/dashboard', () => ({ dashboardFixtures: {} }))
jest.mock('@/features/alertness/AlertnessWatcher', () => ({ pullChecks: jest.fn(async () => undefined) }))
jest.mock('@/features/gate/knowledge', () => ({ gateKnowledge: { refresh: jest.fn(async () => 'ok') } }))
jest.mock('@/offline/warmCaches', () => ({ scheduleWarm: jest.fn() }))
let mockOps: { id: string; endpoint: string; state: string; capturedAt: string; label: string; kind: string; attempts: number; lastTriedAt: null; error: null }[] = []
jest.mock('@/store', () => ({
  useAppSelector: (select: (s: unknown) => unknown) => select({ session: { principal: { name: 'Marcus Whyte' } }, network: { online: false }, outbox: { ops: mockOps, waiting: mockOps.length, refused: 0 } }),
}))

const mockShift = SHIFT
const mockNow = new Date('2026-09-20T18:00:00.000Z')

beforeEach(async () => {
  await getStorage().clearAll()
  mockOps = []
})

it('on duty with nothing waiting: On post, and the card opens clock-out', async () => {
  const view = await renderHook(() => useDashboard())
  expect(view.result.current.card).toMatchObject({ badge: { label: 'On post' }, action: 'clock-out' })
})

it('a clock-out waiting on the phone: Clocked out, its times, pending sync, nothing to press', async () => {
  mockOps = [{ id: 'op-9', endpoint: 'shifts.clock_out', state: 'pending', capturedAt: '2026-09-20T17:30:00.000-05:00', label: 'Clock-out · Main Gate', kind: 'clock', attempts: 0, lastTriedAt: null, error: null }]
  const view = await renderHook(() => useDashboard())
  const card = view.result.current.card!
  expect(card.badge).toEqual({ label: 'Clocked out', tone: 'muted' })
  expect(card.cells).toEqual([
    { value: timeCompact(SHIFT.actual_start), label: 'CLOCKED IN' },
    { value: timeCompact('2026-09-20T17:30:00.000-05:00'), label: 'CLOCKED OUT' },
    { value: expect.any(String), label: 'ON POST' },
  ])
  expect(card.note).toBe('Clock-out saved on this phone: pending sync')
  expect(card.action).toBeNull()
})
