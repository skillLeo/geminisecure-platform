/**
 * AN OFFICER IN A DEAD SPOT IS NEVER KEPT ON POST BY A REPORT THAT CANNOT SEND (D-M164; M11 §1.2, §1.7).
 *
 * Build Spec GD-20 blocked the clock-out while an incident report or an alert was still waiting to sync. The M11
 * §1.7 airplane test (26 Sep) filed an incident with a photo, scanned a checkpoint, and then could not clock out at
 * all — the button did nothing until signal came back. GD-20's purpose is kept by the queue itself: it sends in the
 * order things were saved and stops at the first that fails, so a clock-out saved after the report reaches the
 * office after it. Proved here: the report is still NAMED, the clock-out is allowed, and it is saved.
 */

import { act, renderHook } from '@testing-library/react-native'
import { getStorage } from '@gs/core/lib'
import { onboarding } from '@/features/enrolment/onboardingState'
import { useClockOut } from '../useClockOut'

jest.mock('expo-location', () => ({
  requestForegroundPermissionsAsync: jest.fn(async () => ({ granted: true, canAskAgain: false, status: 'granted' })),
  getForegroundPermissionsAsync: jest.fn(async () => ({ granted: true, canAskAgain: false, status: 'granted' })),
  getCurrentPositionAsync: jest.fn(async () => ({ coords: { latitude: 18.01, longitude: -76.8, accuracy: 9 }, mocked: false })),
  getLastKnownPositionAsync: jest.fn(async () => null),
  Accuracy: { High: 5, Balanced: 3 },
}))
jest.mock('expo-local-authentication', () => ({
  hasHardwareAsync: jest.fn(async () => true),
  isEnrolledAsync: jest.fn(async () => true),
  authenticateAsync: jest.fn(async () => ({ success: true })),
}))

const mockShift = { id: 41, status: 'on_duty', actual_start: '2026-09-20T12:00:00.000Z', actual_end: null, rostered_start: '2026-09-20T12:00:00.000Z', rostered_end: '2026-09-21T00:00:00.000Z', post: { name: 'Main Gate' } }
const mockRefetch = jest.fn(async () => undefined)
// ONE function for every render, as the real hook gives: a new one each time re-runs the screen's effects for ever.
const mockPreflight = jest.fn(async () => ({ error: { status: 'network' } }))
jest.mock('@gs/core/api/guard', () => ({
  guardApi: {
    useShiftsMeCurrentQuery: () => ({ data: { shift: mockShift }, isLoading: false, error: undefined, refetch: mockRefetch }),
    usePresenceSummaryQuery: () => ({ data: undefined, isLoading: false, error: undefined, refetch: mockRefetch }),
    useShiftsPreflightMutation: () => [mockPreflight],
  },
}))
jest.mock('@gs/core/api', () => ({ isApiError: () => false }))
jest.mock('@gs/core/crypto', () => ({ deviceUid: async () => 'device-uid' }))
jest.mock('@gs/core/offline', () => ({ SILENT_KIND: 'duress_silent' }))
jest.mock('@gs/core', () => ({
  isFidelity: false,
  fidelityScreenId: () => null,
  now: () => new Date('2026-09-20T18:00:00.000Z'),
  useOfflineCache: (_key: string, data: unknown) => data,
}))
jest.mock('@/fidelity/clockout', () => ({ clockOutFixtures: {} }))

// An incident report filed with no signal, still waiting — and a SILENT duress, which is never named on a screen.
const mockQueue = [
  { id: 'inc-1', endpoint: 'incidents.store', state: 'pending', kind: 'incident', label: 'Incident report · Equipment fault', params: {} },
  { id: 'dur-1', endpoint: 'duress.store', state: 'pending', kind: 'duress_silent', label: 'Duress alert', params: {} },
]
const mockSubmit = jest.fn(async (_op: unknown) => ({ id: 'op-2', state: 'pending' }))
jest.mock('@/offline/runtime', () => ({ outbox: { all: () => mockQueue }, submit: (op: unknown) => mockSubmit(op) }))
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select({ network: { online: false }, outbox: { ops: mockQueue } }) }))

const settle = async () => {
  await act(async () => {
    for (let i = 0; i < 5; i++) await Promise.resolve()
  })
}

beforeEach(async () => {
  jest.clearAllMocks()
  await getStorage().clearAll()
  onboarding.update({ detailsConfirmedAt: 'a', identity: 'confirmed', kitAcknowledgedAt: 'a', notifications: 'granted', notificationsAskedAt: 'a', locationDisclosedAt: 'a', finishedAt: 'a' })
})

it('names the report still waiting, and lets the officer clock out behind it', async () => {
  const onDone = jest.fn()
  const view = await renderHook(() => useClockOut(onDone))
  await settle()

  expect(view.result.current.blockers).toEqual(['Incident report · Equipment fault']) // named; the silent duress never is
  expect(view.result.current.canClockOut).toBe(true)
  expect(view.result.current.blockedReason).toBeNull()

  await act(async () => {
    view.result.current.clockOut()
  })
  await settle()

  expect(mockSubmit).toHaveBeenCalledTimes(1)
  // Saved after the report, and NOT made to depend on it: a report the office refuses must not refuse the clock-out.
  expect(mockSubmit.mock.calls[0]![0]).toMatchObject({ endpoint: 'shifts.clock_out', params: { shift: 41 }, after: null })
  expect(onDone).toHaveBeenCalledTimes(1)
  await view.unmount()
})
