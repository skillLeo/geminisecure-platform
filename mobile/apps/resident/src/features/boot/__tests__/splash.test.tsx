/**
 * "AUTO-ADVANCES AFTER ~1.5s, OR STRAIGHT TO LOG IN IF A SESSION IS ALREADY SAVED" (M11 A4).
 *
 * The board asks for the ~1.5 s once: for a phone that has never been used. The screen used to spend
 * 1.65 s on EVERY launch before a real screen appeared. These tests mount the real screen with the
 * clock stopped and prove:
 *   - a brand-new phone still sees the brand for the board's ~1.5 s, then LOG IN (M32 F10: the app
 *     opens on Log in; creating an account is a button there, and happens once);
 *   - a phone that has signed in before, or holds a session (active or pending), goes STRAIGHT —
 *     no timer has to run;
 *   - a boot that is still running is waited for, however long, whichever kind of phone it is;
 *   - it hands over ONCE: the timer that runs out afterwards sends it nowhere;
 *   - WHERE it goes is unchanged.
 */

import { act, render } from '@testing-library/react-native'
import SplashScreen, { nextRoute, waitsForBrand } from '../screens/SplashScreen'

const mockReplace = jest.fn()
jest.mock('expo-router', () => ({ router: { replace: (href: string) => mockReplace(href) } }))
jest.mock('expo-status-bar', () => ({ StatusBar: () => null }))

const mockState = { session: { status: 'booting' as string } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

let mockEstate: { id: string; name: string } | null = null
let mockAsked = false
// What the phone has answered (M47 §3): known at once here unless a test says the phone is still being asked.
let mockPhoneKnown = true
let mockPhoneAnswers: Promise<void> = Promise.resolve()
jest.mock('@/features/auth/session', () => ({ lastEstate: { get: () => mockEstate } }))
jest.mock('@/features/auth/usePermissions', () => ({ permissionsAsked: { get: () => mockAsked, known: () => mockPhoneKnown, hydrate: () => mockPhoneAnswers } }))
jest.mock('@/fidelity/fixtures', () => ({ bootFixture: { loaderProgress: 0.62 } }))

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
  return {
    // What the design tokens are made with (apps/resident/src/design/tokens.ts).
    a: () => 'transparent',
    c: () => '#000000',
    sh: () => '',
    alpha: () => 'transparent',
    palette: { 'amber-500': '#000000' },
    BrandMark: () => null,
    Gradient: ({ children }: { children: unknown }) => <View>{children as never}</View>,
    Text: ({ children }: { children: unknown }) => <Text>{children as never}</Text>,
    isFidelity: false,
    useFidelityReady: () => undefined,
  }
})

const PHOENIX = { id: 'phoenixpark', name: 'Phoenix Park Village 1' }

const flush = async () => {
  await act(async () => {
    await Promise.resolve()
  })
}
const advance = async (ms: number) => {
  await act(async () => {
    jest.advanceTimersByTime(ms)
  })
}

beforeEach(() => {
  jest.useFakeTimers()
  mockReplace.mockClear()
  mockState.session = { status: 'booting' }
  mockEstate = null
  mockAsked = false
  mockPhoneKnown = true
  mockPhoneAnswers = Promise.resolve()
})

afterEach(() => jest.useRealTimers())

describe('the permissions are explained once per phone (M47 §3)', () => {
  it('an active session waits for the phone to say what it has answered, then goes', async () => {
    mockState.session = { status: 'active' }
    mockPhoneKnown = false
    let answered: () => void = () => undefined
    mockPhoneAnswers = new Promise<void>((resolve) => (answered = resolve))
    await render(<SplashScreen />)
    await flush()
    expect(mockReplace).not.toHaveBeenCalled()

    mockAsked = true
    await act(async () => answered())
    await flush()
    expect(mockReplace).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/(tabs)')
  })

  it('a signed-out phone does not wait for it', async () => {
    mockState.session = { status: 'signedOut' }
    mockEstate = PHOENIX
    mockPhoneKnown = false
    mockPhoneAnswers = new Promise<void>(() => undefined)
    await render(<SplashScreen />)
    await flush()
    expect(mockReplace).toHaveBeenCalledWith('/(auth)/log-in')
  })
})

describe('a brand-new phone sees the brand for the board’s ~1.5 s', () => {
  it('waits, then Log In, never Create account (M32 F10)', async () => {
    mockState.session = { status: 'signedOut' }
    await render(<SplashScreen />)
    await flush()
    await advance(1_600)
    expect(mockReplace).not.toHaveBeenCalled()
    await advance(60)
    expect(mockReplace).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/(auth)/log-in')
  })
})

describe('every other launch goes straight: no timer has to run', () => {
  it('a phone that has signed in here before → Log In', async () => {
    mockState.session = { status: 'signedOut' }
    mockEstate = PHOENIX
    await render(<SplashScreen />)
    await flush()
    expect(mockReplace).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/(auth)/log-in')
  })

  it('a saved session → Home (or the permissions explained once, first)', async () => {
    mockState.session = { status: 'active' }
    mockAsked = true
    await render(<SplashScreen />)
    await flush()
    expect(mockReplace).toHaveBeenCalledWith('/(tabs)')
  })

  it('a claim waiting for the estate → its holding screen', async () => {
    mockState.session = { status: 'pending' }
    await render(<SplashScreen />)
    await flush()
    expect(mockReplace).toHaveBeenCalledWith('/(auth)/pending')
  })
})

describe('the boot is always waited for, and the hand-over happens once', () => {
  it('a boot still running holds even a returning phone, and it goes the moment the boot answers', async () => {
    mockEstate = PHOENIX
    const view = await render(<SplashScreen />)
    await flush()
    await advance(10_000)
    expect(mockReplace).not.toHaveBeenCalled()
    mockState.session = { status: 'signedOut' }
    await view.rerender(<SplashScreen />)
    await flush()
    expect(mockReplace).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/(auth)/log-in')
  })

  it('the timer that runs out after the splash has gone sends it nowhere', async () => {
    mockState.session = { status: 'active' }
    mockAsked = true
    await render(<SplashScreen />)
    await flush()
    await advance(5_000)
    expect(mockReplace).toHaveBeenCalledTimes(1)
  })
})

describe('where it goes, and who waits, decided from disk', () => {
  it('nextRoute is the board’s destinations, with Log In for every phone signed out (M32 F10)', () => {
    expect(nextRoute('signedOut')).toBe('/(auth)/log-in')
    mockEstate = PHOENIX
    expect(nextRoute('signedOut')).toBe('/(auth)/log-in')
    expect(nextRoute('pending')).toBe('/(auth)/pending')
    expect(nextRoute('active')).toBe('/permissions')
    mockAsked = true
    expect(nextRoute('active')).toBe('/(tabs)')
  })

  it('only a phone with no session and no remembered estate waits for the brand', () => {
    expect(waitsForBrand('signedOut')).toBe(true)
    expect(waitsForBrand('active')).toBe(false)
    expect(waitsForBrand('pending')).toBe(false)
    mockEstate = PHOENIX
    expect(waitsForBrand('signedOut')).toBe(false)
  })
})
