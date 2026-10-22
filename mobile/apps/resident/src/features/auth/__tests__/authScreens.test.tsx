/**
 * LOG IN AND CREATE ACCOUNT, AS THE BOARD DRAWS THEM (M47 §1–§2) — with the estate dropdown in the board's Password
 * (Log in) and invite-code (Create account) places.
 *
 *   - every element the board draws is on the screen (List A), in its words;
 *   - nothing the board does not draw is (List B): the community code, its switches, the no-password lines, the demo
 *     door, the second legal row, the alert panel, "Create an account" as a button;
 *   - the dropdown opens the list of estates in a sheet and the subtitle names the one chosen;
 *   - the list this phone last saw is drawn at once (M11 A4), and the one home estate there is is chosen already;
 *   - "Log in with biometrics" answers a tap — it is never a control that does nothing (M46 §12);
 *   - the placeholders are the board's own samples, drawn by the app in Inter (M47 U8).
 */

import { act, fireEvent, render as renderTree, screen } from '@testing-library/react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { getStorage, jsonStore } from '@gs/core/lib'
import { BIOMETRICS_NOTE } from '../useAuthForms'
import ForgotScreen from '../screens/ForgotScreen'
import LogInScreen from '../screens/LogInScreen'
import SignUpScreen from '../screens/SignUpScreen'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' } }))
jest.mock('expo-status-bar', () => ({ StatusBar: () => null }))
jest.mock('expo-device', () => ({ modelName: 'Pixel 7' }))
const mockPush = jest.fn()
const mockReplace = jest.fn()
jest.mock('expo-router', () => ({
  router: { push: (href: string) => mockPush(href), replace: (href: string) => mockReplace(href), back: jest.fn(), canGoBack: () => false },
  useLocalSearchParams: () => ({}),
  usePathname: () => '/(auth)/log-in',
  useFocusEffect: jest.fn(),
}))
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: () => undefined }))
let mockRemembered: { id: string; name: string } | null = null
jest.mock('@/features/auth/session', () => ({ lastEstate: { get: () => mockRemembered, set: jest.fn() }, openSession: jest.fn(async () => 'home') }))

type Estate = { id: string; name: string; parish: string | null; demo: boolean }
type Answer = { data?: { items: Estate[] }; isLoading: boolean; isFetching: boolean }
let mockEstates: Answer = { isLoading: true, isFetching: true }
const mockRefetch = jest.fn()
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useEstatesIndexQuery: () => ({ ...mockEstates, refetch: mockRefetch }),
    useAuthOtpRequestMutation: () => [jest.fn(async () => ({ data: { destination_hint: 'h•••@example.com' } }))],
    useAuthOtpVerifyMutation: () => [jest.fn()],
    useAuthRestoreMutation: () => [jest.fn()],
    useAuthDeviceKeyMutation: () => [mockDeviceKeySignIn],
  },
}))
/** Fingerprint sign-in on this phone (M50 §3): set up or not, and what the phone's prompt answers. */
let mockFingerprint: { estate: string; estateName: string; accountId: number; accountName: string; email: null } | null = null
let mockKeyRead: { key: string } | { why: 'cancelled' | 'unreadable' } = { key: 'the-key' }
const mockDeviceKeySignIn = jest.fn(async () => ({ data: { token: 't', account: { id: 7, status: 'active', full_name: 'Hassam Ahmed' }, estate: { id: 'phoenixparkvillage1', name: 'Phoenix Park Village 1', timezone: 'America/Jamaica' }, claim: null, next: 'home', reviewer: false, read_only: false, restored: false, abilities: [], fingerprint_sign_in: true } }))
jest.mock('@/features/auth/fingerprint', () => ({
  canUseFingerprint: () => true,
  fingerprintFor: async () => mockFingerprint,
  readKey: async () => mockKeyRead,
  forgetKey: jest.fn(async () => undefined),
  handsetId: async () => 'hw-1',
  phoneName: () => 'Galaxy S21',
  keepKey: jest.fn(async () => true),
}))
jest.mock('@/features/auth/afterSignIn', () => ({ goAfterSignIn: jest.fn() }))

const METRICS = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 47, left: 0, right: 0, bottom: 34 } }
const render = (ui: React.ReactElement) => renderTree(<SafeAreaProvider initialMetrics={METRICS}>{ui}</SafeAreaProvider>)
const saved = () => jsonStore<{ items: unknown[] }>(getStorage(), 'cache:estates.index', 'v1')

const PHOENIX: Estate = { id: 'phoenixparkvillage1', name: 'Phoenix Park Village 1', parish: 'St. Andrew', demo: false }
const SEAVIEW: Estate = { id: 'seaviewdemo', name: 'Seaview Demo Estate', parish: null, demo: true }
const GREENVALE: Estate = { id: 'greenvale', name: 'Greenvale Gardens', parish: 'St. Catherine', demo: false }

beforeEach(async () => {
  await getStorage().clearAll()
  mockRemembered = null
  mockEstates = { isLoading: false, isFetching: false, data: { items: [PHOENIX, SEAVIEW] } }
  mockPush.mockClear()
  mockReplace.mockClear()
  mockRefetch.mockClear()
})

const text = () => JSON.stringify(screen.toJSON())

describe('Log in', () => {
  it('draws every element of the board, in its words, with the one home estate chosen', async () => {
    await render(<LogInScreen />)
    for (const words of ['Welcome back', 'Log in to access Phoenix Park Village 1', 'Email address', 'Your estate', 'Phoenix Park Village 1', 'Trouble signing in?', 'Log in', 'Log in with biometrics']) {
      expect(screen.getAllByText(words).length).toBeGreaterThan(0)
    }
    // The divider's "or" is decoration, hidden from screen readers — drawn all the same.
    expect(screen.getByText('or', { includeHiddenElements: true })).toBeTruthy()
    expect(screen.getByLabelText("Don't have an account? Sign up")).toBeTruthy()
    expect(screen.getByLabelText('Back')).toBeTruthy()
    // The board's own sample, as a grey placeholder drawn in Inter by the app — on example.com (M42 §6).
    expect(screen.getByText('andrea.fletcher@example.com')).toBeTruthy()
  })

  it('draws nothing the board does not', async () => {
    await render(<LogInScreen />)
    const all = text()
    for (const gone of ['Community code', 'Choose your estate from the list instead', "My estate isn't listed", 'There is no password', 'Available once you have logged in', 'or just have a look', 'Explore a demo community', 'Seaview Demo Estate (demonstration data)', 'Create an account', 'YOUR EMERGENCY ALERT', 'Estate time']) {
      expect(all).not.toContain(gone)
    }
  })

  it('opens the estates in a sheet, the demonstration labelled, and the subtitle names the one chosen', async () => {
    mockEstates = { isLoading: false, isFetching: false, data: { items: [PHOENIX, GREENVALE, SEAVIEW] } }
    mockRemembered = { id: 'phoenixparkvillage1', name: 'Phoenix Park Village 1' }
    await render(<LogInScreen />)
    await act(async () => fireEvent.press(screen.getByLabelText('Your estate: Phoenix Park Village 1')))

    expect(screen.getByText('Seaview Demo Estate: demonstration, nobody lives here')).toBeTruthy()
    await act(async () => fireEvent.press(screen.getByLabelText('Greenvale Gardens · St. Catherine')))
    expect(screen.getByText('Log in to access Greenvale Gardens')).toBeTruthy()
  })

  it('"Log in with biometrics" with no key on this phone: disabled, and no gold excuse under it (M50 §3.2b–c)', async () => {
    mockFingerprint = null
    await render(<LogInScreen />)
    const button = screen.getByLabelText('Log in with biometrics')
    expect(button.props['aria-disabled'] ?? button.props.accessibilityState?.disabled).toBe(true)
    expect(screen.queryByText(BIOMETRICS_NOTE)).toBeNull()
    expect(mockDeviceKeySignIn).not.toHaveBeenCalled()
  })

  it('with fingerprint sign-in on for this phone, Log in asks the phone at once and signs in with no code (M50 §3.2a)', async () => {
    mockFingerprint = { estate: 'phoenixparkvillage1', estateName: 'Phoenix Park Village 1', accountId: 7, accountName: 'Hassam Ahmed', email: null }
    mockKeyRead = { key: 'the-key' }
    mockDeviceKeySignIn.mockClear()
    const { goAfterSignIn } = jest.requireMock('@/features/auth/afterSignIn') as { goAfterSignIn: jest.Mock }
    goAfterSignIn.mockClear()
    await render(<LogInScreen />)
    await act(async () => { await Promise.resolve() })
    expect(mockDeviceKeySignIn).toHaveBeenCalledWith(expect.objectContaining({ estate: 'phoenixparkvillage1', key: 'the-key', hardware_id: 'hw-1', device_name: 'Galaxy S21' }))
    expect(goAfterSignIn).toHaveBeenCalledWith('home')
    expect(mockPush).not.toHaveBeenCalledWith('/(auth)/code')
  })

  it('a cancelled fingerprint leaves the email and code exactly as they were: no message, no sign-in (M50 §3.2d)', async () => {
    mockFingerprint = { estate: 'phoenixparkvillage1', estateName: 'Phoenix Park Village 1', accountId: 7, accountName: 'Hassam Ahmed', email: null }
    mockKeyRead = { why: 'cancelled' }
    mockDeviceKeySignIn.mockClear()
    await render(<LogInScreen />)
    await act(async () => { await Promise.resolve() })
    expect(mockDeviceKeySignIn).not.toHaveBeenCalled()
    expect(screen.getByText('Email address')).toBeTruthy()
    expect(screen.queryByRole('alert')).toBeNull()
    mockFingerprint = null
  })

  it('goes to Create account from the footer line, and from the back chevron when nothing came before', async () => {
    await render(<LogInScreen />)
    await act(async () => fireEvent.press(screen.getByLabelText("Don't have an account? Sign up")))
    await act(async () => fireEvent.press(screen.getByLabelText('Back')))
    expect(mockReplace.mock.calls.map(([href]) => href)).toEqual(['/(auth)/sign-up', '/(auth)/sign-up'])
  })
})

describe('the estate list this phone last saw is drawn at once (M11 A4)', () => {
  it('with the request still on the wire, the saved list chooses and names the estate on the first render', async () => {
    saved().set({ items: [PHOENIX] })
    mockEstates = { isLoading: true, isFetching: true }
    await render(<LogInScreen />)
    expect(screen.getByText('Log in to access Phoenix Park Village 1')).toBeTruthy()
    expect(text()).not.toContain('Finding your estate')
  })

  it('a phone that never had the list says it is finding it', async () => {
    mockEstates = { isLoading: true, isFetching: true }
    await render(<LogInScreen />)
    expect(screen.getByText('Finding your estate…')).toBeTruthy()
  })

  it('with no list at all it shows the estate this phone last used, and a tap asks again', async () => {
    mockEstates = { isLoading: false, isFetching: false }
    mockRemembered = { id: 'phoenixparkvillage1', name: 'Phoenix Park Village 1' }
    await render(<LogInScreen />)
    await act(async () => fireEvent.press(screen.getByLabelText('Your estate: Phoenix Park Village 1')))
    expect(mockRefetch).toHaveBeenCalled()
  })

  it('the server’s answer is shown, and is what is kept for next time', async () => {
    saved().set({ items: [PHOENIX] })
    mockEstates = { isLoading: false, isFetching: false, data: { items: [GREENVALE] } }
    await render(<LogInScreen />)
    expect(screen.getByText('Log in to access Greenvale Gardens')).toBeTruthy()
    expect(saved().get()).toEqual({ items: [GREENVALE] })
  })
})

describe('Create account', () => {
  it('draws every element of the board, the estate dropdown in the invite code\'s place', async () => {
    await render(<SignUpScreen />)
    for (const words of ['Create account', 'Join your community on GeminiSecure', 'Your estate', 'Full name', 'Email address', 'Terms of Service', 'Privacy Policy']) {
      expect(screen.getAllByText(words).length).toBeGreaterThan(0)
    }
    expect(screen.getByRole('checkbox')).toBeTruthy()
    expect(screen.getByLabelText('Already have an account? Log in')).toBeTruthy()
    // The board's samples as grey placeholders — the short name, not the long one that wrapped.
    expect(screen.getByText('Andrea Fletcher')).toBeTruthy()
    expect(screen.getByText('you@email.com')).toBeTruthy()
  })

  it('draws nothing the board does not', async () => {
    await render(<SignUpScreen />)
    const all = text()
    for (const gone of ['Choose your estate below', "My estate isn't listed", 'There is no password to create', 'Terms of Use', 'I already have an account: log in', 'or just have a look', 'Explore a demo community', 'as your estate knows you', 'Community invite code', 'Estate time']) {
      expect(all).not.toContain(gone)
    }
  })

  it('asks for the terms before anything is sent, and goes back to Log in from the footer line', async () => {
    await render(<SignUpScreen />)
    await act(async () => fireEvent.press(screen.getByLabelText('Create account')))
    expect(screen.getByText('Please agree to the terms to continue.')).toBeTruthy()
    expect(mockPush).not.toHaveBeenCalled()

    await act(async () => fireEvent.press(screen.getByLabelText('Already have an account? Log in')))
    expect(mockReplace).toHaveBeenCalledWith('/(auth)/log-in')
  })
})

describe('Trouble signing in?: board 04 (Forgot Password), rebuilt on the sign-in parts (M50 §7)', () => {
  it('draws every element of the board, in words that are true: there is no password to forget', async () => {
    await render(<ForgotScreen />)
    for (const words of ['Trouble signing in?', "No problem: we'll email you a new sign-in code.", 'Email address', 'Your estate', 'Phoenix Park Village 1', 'Send sign-in code']) {
      expect(screen.getAllByText(words).length).toBeGreaterThan(0)
    }
    expect(screen.getByLabelText('Back to Log in')).toBeTruthy()
    const all = text()
    for (const gone of ['password', 'Password', 'Email or phone', 'spam', 'No connection']) expect(all).not.toContain(gone)
  })

  it('sends a fresh code for the estate chosen without asking, and goes on to the code screen', async () => {
    await render(<ForgotScreen />)
    await act(async () => fireEvent.changeText(screen.getByLabelText('Email address'), 'hassam@example.com'))
    await act(async () => fireEvent.press(screen.getByLabelText('Send sign-in code')))
    expect(mockPush).toHaveBeenCalledWith('/(auth)/code')
  })
})
