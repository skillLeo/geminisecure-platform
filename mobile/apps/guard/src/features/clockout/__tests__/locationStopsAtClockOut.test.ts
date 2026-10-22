/**
 * "LOCATION STOPS WHEN THE SHIFT ENDS" — the claim both stores are asked to believe, and
 * until now the one definition-of-done item with no test behind it. PERMISSIONS.md cited this test by
 * name before it existed.
 *
 * It is proved twice over, because either alone would be weak:
 *
 *   BEHAVIOUR  the clock-out hook is mounted for a shift on duty, its one read is counted, the shift
 *              is clocked out, and then the clock is run on: ten minutes while the screen is open, the
 *              screen left, seventy minutes more, and the app going away and coming back. Not one
 *              further position is read, not one further permission asked, and every repeating timer
 *              the screen started is stopped when it goes. It subscribes to nothing — no app-state
 *              listener, so nothing arriving later has anything to call.
 *   SHAPE      the source of the clock-out and the dashboard contains no watcher, no background
 *              location updates and no platform geofencing — so there is no mechanism that COULD run
 *              after the read this test counted.
 *
 * It also covers the disclosure gate added with setup step 4: before the guard has been shown what a
 * position is read for, this screen may not put the phone's dialog up at all.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { AppState } from 'react-native'
import * as Location from 'expo-location'
import { act, renderHook } from '@testing-library/react-native'
import { getStorage } from '@gs/core/lib'
import { onboarding } from '@/features/enrolment/onboardingState'
import { useClockOut } from '../useClockOut'

const SHIFT = {
  id: 41,
  status: 'on_duty',
  actual_start: '2026-09-20T12:00:00.000Z',
  actual_end: null,
  rostered_start: '2026-09-20T12:00:00.000Z',
  rostered_end: '2026-09-21T00:00:00.000Z',
  post: { name: 'Main Gate' },
}

jest.mock('expo-location', () => ({
  requestForegroundPermissionsAsync: jest.fn(async () => ({ granted: true, canAskAgain: false, status: 'granted' })),
  getForegroundPermissionsAsync: jest.fn(async () => ({ granted: false, canAskAgain: true, status: 'undetermined' })),
  getCurrentPositionAsync: jest.fn(async () => ({ coords: { latitude: 18.01, longitude: -76.8, accuracy: 9 }, mocked: false })),
  getLastKnownPositionAsync: jest.fn(async () => null),
  Accuracy: { High: 5, Balanced: 3 },
}))

jest.mock('expo-local-authentication', () => ({
  hasHardwareAsync: jest.fn(async () => true),
  isEnrolledAsync: jest.fn(async () => true),
  authenticateAsync: jest.fn(async () => ({ success: true })),
}))

const mockPreflight = jest.fn(async () => ({ data: { within_geofence: true, distance_m: 8, radius_m: 50 } }))
const mockRefetch = jest.fn(async () => undefined)
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

type Captured = { endpoint: string; params: Record<string, unknown> }
const mockSubmit = jest.fn(async (_op: Captured) => ({ id: 'op-1', state: 'pending' }))
jest.mock('@/offline/runtime', () => ({ outbox: { all: () => [] }, submit: (op: unknown) => mockSubmit(op as Captured) }))
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select({ network: { online: true }, outbox: { ops: [] } }) }))

// Jest hoists the factories above everything but a `mock`-prefixed binding.
const mockShift = SHIFT

const ask = Location.requestForegroundPermissionsAsync as unknown as jest.Mock
const look = Location.getForegroundPermissionsAsync as unknown as jest.Mock
const read = Location.getCurrentPositionAsync as unknown as jest.Mock
const lastKnown = Location.getLastKnownPositionAsync as unknown as jest.Mock

const settle = async () => {
  await act(async () => {
    await Promise.resolve()
    await Promise.resolve()
    await Promise.resolve()
  })
}

describe('a position is read once, at the clock-out, and never again', () => {
  let setSpy: jest.SpyInstance
  let clearSpy: jest.SpyInstance

  beforeEach(async () => {
    jest.useFakeTimers()
    jest.clearAllMocks()
    setSpy = jest.spyOn(globalThis, 'setInterval')
    clearSpy = jest.spyOn(globalThis, 'clearInterval')
    await getStorage().clearAll()
    // The guard has been through setup, including the disclosure — the normal state of an enrolled handset.
    onboarding.update({ detailsConfirmedAt: 'a', identity: 'confirmed', kitAcknowledgedAt: 'a', notifications: 'granted', notificationsAskedAt: 'a', locationDisclosedAt: 'a', finishedAt: 'a' })
  })

  afterEach(() => {
    setSpy.mockRestore()
    clearSpy.mockRestore()
    jest.useRealTimers()
  })

  it('reads exactly one position while the screen is open, and none after the shift is clocked out', async () => {
    const onDone = jest.fn()
    const view = await renderHook(() => useClockOut(onDone))
    await settle()

    // BASELINE: one permission request, one position, nothing else.
    expect(ask).toHaveBeenCalledTimes(1)
    expect(read).toHaveBeenCalledTimes(1)
    expect(lastKnown).not.toHaveBeenCalled()
    expect(view.result.current.geo.state).toBe('inside')

    // Ten minutes of the screen simply sitting there — the banner's own timer runs, nothing else does.
    await act(async () => {
      jest.advanceTimersByTime(10 * 60_000)
    })
    await settle()
    expect(read).toHaveBeenCalledTimes(1)

    // The guard clocks out.
    await act(async () => {
      view.result.current.clockOut()
    })
    await settle()
    expect(mockSubmit).toHaveBeenCalledTimes(1)
    expect(mockSubmit.mock.calls[0]![0]).toMatchObject({ endpoint: 'shifts.clock_out', params: { shift: SHIFT.id } })
    expect(onDone).toHaveBeenCalledTimes(1)

    const afterClockOut = read.mock.calls.length
    expect(afterClockOut).toBe(1)

    // …and then everything that could wake a reader, with the screen gone. Every repeating timer the
    // screen started is stopped when it goes (the environment keeps timers of its own, so what is
    // counted is the screen's, not the total).
    await view.unmount()
    expect(clearSpy.mock.calls.length).toBeGreaterThanOrEqual(setSpy.mock.calls.length)

    await act(async () => {
      jest.advanceTimersByTime(10 * 60_000)
      AppState.currentState = 'background'
      AppState.currentState = 'active'
    })
    await settle()
    await act(async () => {
      jest.advanceTimersByTime(60 * 60_000)
    })
    await settle()

    expect(read).toHaveBeenCalledTimes(1)
    expect(lastKnown).not.toHaveBeenCalled()
    expect(ask).toHaveBeenCalledTimes(1)
  })

  it('the screen subscribes to nothing that could fire after it is gone', async () => {
    const addListener = jest.spyOn(AppState, 'addEventListener')
    const view = await renderHook(() => useClockOut(jest.fn()))
    await settle()
    // No app-state listener, so coming back to the app cannot start a read — the "back from Settings"
    // nudge in this app belongs to the push registration, and that reads no position.
    expect(addListener).not.toHaveBeenCalled()
    expect(setSpy.mock.calls.length).toBeGreaterThan(0) // the banner's own clock, while the screen is open
    await view.unmount()
    expect(clearSpy.mock.calls.length).toBeGreaterThanOrEqual(setSpy.mock.calls.length)
    addListener.mockRestore()
  })

  it('before setup’s disclosure it never puts the phone’s dialog up: it only looks', async () => {
    await getStorage().clearAll()
    onboarding.update({ detailsConfirmedAt: 'a', identity: 'confirmed', kitAcknowledgedAt: 'a', finishedAt: 'a' })
    expect(onboarding.locationDisclosed()).toBe(false)

    const view = await renderHook(() => useClockOut(jest.fn()))
    await settle()

    expect(ask).not.toHaveBeenCalled()
    expect(look).toHaveBeenCalledTimes(1)
    expect(read).not.toHaveBeenCalled()
    expect(view.result.current.geo).toEqual({ state: 'unavailable', why: 'permission' })
    await view.unmount()
  })
})

describe('there is no mechanism that could read a position after the shift', () => {
  const source = (rel: string) => readFileSync(path.resolve(__dirname, '..', '..', '..', '..', '..', '..', rel), 'utf8')
  const WATCHERS = [
    [/watchPositionAsync/, 'a continuous position watcher'],
    [/startLocationUpdatesAsync|stopLocationUpdatesAsync|hasStartedLocationUpdatesAsync/, 'background location updates'],
    [/startGeofencingAsync|hasStartedGeofencingAsync/, 'the platform geofencing service'],
    [/requestBackgroundPermissionsAsync|getBackgroundPermissionsAsync/, 'the background location permission'],
    [/expo-task-manager|TaskManager\.defineTask/, 'a background task'],
  ] as const

  it('the clock-out and the dashboard hold none of them', () => {
    for (const rel of ['apps/guard/src/features/clockout/useClockOut.ts', 'apps/guard/src/features/dashboard/useDashboard.ts', 'apps/guard/src/features/dashboard/screens/DashboardScreen.tsx']) {
      const text = source(rel)
      for (const [pattern, what] of WATCHERS) expect(`${rel} holds ${what}: ${pattern.test(text)}`).toBe(`${rel} holds ${what}: false`)
    }
  })

  it('the clock-out takes the LAST position of the shift: if that ever stopped, the record would not say where it ended', () => {
    expect(source('apps/guard/src/features/clockout/useClockOut.ts')).toMatch(/Location\.getCurrentPositionAsync/)
  })
})
