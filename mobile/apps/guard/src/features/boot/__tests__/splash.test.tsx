/**
 * THE SPLASH LEAVES WHEN THE SESSION IS KNOWN, AND NOT A MOMENT LATER (M11 A4).
 *
 * The board's rule is "auto-advances to clock-in once the guard's device session is verified". The
 * screen used to add a 1.65 s timer of its own on top of that, on every cold start. These tests mount
 * the real screen with the clock stopped and prove:
 *   - with the boot already answered, it hands over at once — no timer has to run for it to go;
 *   - while the boot is still running it waits, however long that takes, and goes the moment it answers;
 *   - it hands over ONCE: a later change of session is the SessionGate's to route, not the splash's;
 *   - WHERE it goes is unchanged (the same destinations, decided from disk by ../nextRoute.ts; the
 *     clock-in still waiting on the phone, B127, is proven in shift/__tests__/clockInWayOut.test.ts).
 */

import { act, render } from '@testing-library/react-native'
import { getStorage, jsonStore } from '@gs/core/lib'
import { onboarding } from '@/features/enrolment/onboardingState'
import { nextRoute } from '../nextRoute'
import SplashScreen from '../screens/SplashScreen'

const mockReplace = jest.fn()
jest.mock('expo-router', () => ({ router: { replace: (href: string) => mockReplace(href) } }))

const mockState = { session: { status: 'booting' as string, principal: null as { siteName: string } | null } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.mock('react-native-reanimated', () => {
  const { View } = jest.requireActual('react-native')
  return {
    __esModule: true,
    default: { View },
    Easing: { out: () => () => 0, cubic: () => 0 },
    useSharedValue: (value: number) => ({ value }),
    useAnimatedStyle: () => ({}),
    withTiming: (value: number) => value,
  }
})

jest.mock('@gs/core', () => {
  const { View, Text } = jest.requireActual('react-native')
  const lib = jest.requireActual('@gs/core/lib')
  return {
    alpha: () => 'transparent',
    palette: { 'amber-500': '#000000' },
    BrandMark: () => null,
    Gradient: ({ children }: { children: unknown }) => <View>{children as never}</View>,
    Text: ({ children }: { children: unknown }) => <Text>{children as never}</Text>,
    getStorage: lib.getStorage,
    jsonStore: lib.jsonStore,
    isFidelity: false,
    useFidelityReady: () => undefined,
  }
})
jest.mock('@/fidelity/fixtures', () => ({ bootFixture: { loaderProgress: 0.7, siteName: 'Board' } }))

const FINISHED = { detailsConfirmedAt: 'a', identity: 'confirmed' as const, kitAcknowledgedAt: 'a', notifications: 'granted' as const, notificationsAskedAt: 'a', locationDisclosedAt: 'a', finishedAt: 'a' }

const flush = async () => {
  await act(async () => {
    await Promise.resolve()
  })
}

beforeEach(async () => {
  jest.useFakeTimers()
  mockReplace.mockClear()
  mockState.session = { status: 'booting', principal: null }
  await getStorage().clearAll()
})

afterEach(() => jest.useRealTimers())

describe('the guard splash hands over as soon as the session is known', () => {
  it('goes at once when the boot has already answered: no timer has to run', async () => {
    mockState.session = { status: 'signedOut', principal: null }
    await render(<SplashScreen />)
    await flush()
    expect(mockReplace).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/enrol')
  })

  it('waits for a boot that is still running, however long, and goes the moment it answers', async () => {
    onboarding.update(FINISHED)
    const view = await render(<SplashScreen />)
    await flush()
    await act(async () => {
      jest.advanceTimersByTime(10_000)
    })
    expect(mockReplace).not.toHaveBeenCalled()

    mockState.session = { status: 'active', principal: { siteName: 'Phoenix Park Village 1' } }
    await view.rerender(<SplashScreen />)
    await flush()
    expect(mockReplace).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/clock-in')
  })

  it('hands over once: a later change of session is not the splash’s to route', async () => {
    mockState.session = { status: 'active', principal: null }
    onboarding.update(FINISHED)
    const view = await render(<SplashScreen />)
    await flush()
    mockState.session = { status: 'signedOut', principal: null }
    await view.rerender(<SplashScreen />)
    await act(async () => {
      jest.advanceTimersByTime(5_000)
    })
    expect(mockReplace).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/clock-in')
  })
})

describe('where it goes is unchanged: decided from disk alone', () => {
  it('no session → enrolment', () => {
    expect(nextRoute('signedOut')).toBe('/enrol')
    expect(nextRoute('pending')).toBe('/enrol')
  })

  it('setup interrupted → the step it stopped at', () => {
    onboarding.update({ detailsConfirmedAt: 'a' })
    expect(nextRoute('active')).toMatch(/^\/onboarding\//)
  })

  it('on duty by the last known shift → the dashboard; otherwise clock-in', () => {
    onboarding.update(FINISHED)
    expect(nextRoute('active')).toBe('/clock-in')
    jsonStore<{ shift: { status: string } }>(getStorage(), 'cache:shift.current', 'v1').set({ shift: { status: 'on_duty' } })
    expect(nextRoute('active')).toBe('/(tabs)')
  })
})
