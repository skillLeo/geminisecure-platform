/**
 * A SIGN-IN LOOKS THE EMAIL UP FIRST (M80 §2.1, EST-B-03b) — the app's half of it.
 *
 *   - Log in says it is a sign-in (`intent: sign_in`) and which install asks (`device_uid`), so the server can look the
 *     address up and count the lookups per phone; Create account says `sign_up`; a call that names neither sends exactly
 *     what it always did.
 *   - An address the register does not hold is said beside the email field, in the server's words ("We can't find that
 *     email…"), and the code screen is never reached.
 *   - The code screen is headed "Code sent to h•••@example.com", from the server's masked hint, and its resend line reads
 *     "Didn't get it? Resend in 30s"; the resend asks with the intent the first request had.
 */

import { act, fireEvent, render as renderTree, renderHook, screen } from '@testing-library/react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { getStorage } from '@gs/core/lib'
import CodeScreen from '../screens/CodeScreen'
import LogInScreen from '../screens/LogInScreen'
import { signInDraft, useRequestCode } from '../useSignIn'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' } }))
jest.mock('expo-status-bar', () => ({ StatusBar: () => null }))
jest.mock('expo-device', () => ({ modelName: 'Pixel 7' }))
const mockPush = jest.fn()
jest.mock('expo-router', () => ({
  router: { push: (href: string) => mockPush(href), replace: jest.fn(), back: jest.fn(), canGoBack: () => false },
  Redirect: () => null,
  useLocalSearchParams: () => ({}),
  usePathname: () => '/(auth)/log-in',
  useFocusEffect: jest.fn(),
}))
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: () => undefined }))
jest.mock('@/features/auth/session', () => ({ lastEstate: { get: () => null, set: jest.fn() }, openSession: jest.fn(async () => 'home') }))
jest.mock('@gs/core/crypto/deviceUid', () => ({ deviceUid: async () => 'test-install' }))

type Answer = { data?: unknown; error?: { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: number | null } }
let mockAnswer: Answer = { data: { sent: true } }
const mockCalls: unknown[] = []
// One object for every render, as the real query hands back: a new one each time loops the offline cache for ever.
const mockEstateList = { isLoading: false, isFetching: false, data: { items: [{ id: 'phoenixparkvillage1', name: 'Phoenix Park Village 1', parish: 'St. Andrew', demo: false }] }, refetch: jest.fn() }
jest.mock('@gs/core/api/resident', () => ({
  residentApi: {
    useEstatesIndexQuery: () => mockEstateList,
    useAuthOtpRequestMutation: () => [async (arg: unknown) => { mockCalls.push(arg); return mockAnswer }],
    useAuthOtpVerifyMutation: () => [jest.fn()],
    useAuthRestoreMutation: () => [jest.fn()],
    useAuthDeviceKeyMutation: () => [jest.fn()],
  },
}))
jest.mock('@/features/auth/fingerprint', () => ({
  canUseFingerprint: () => false,
  fingerprintFor: async () => null,
  readKey: async () => ({ why: 'cancelled' }),
  forgetKey: jest.fn(async () => undefined),
  handsetId: async () => null,
  phoneName: () => null,
  keepKey: jest.fn(async () => true),
}))
jest.mock('@/features/auth/afterSignIn', () => ({ goAfterSignIn: jest.fn() }))

const METRICS = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 47, left: 0, right: 0, bottom: 34 } }
const render = (ui: React.ReactElement) => renderTree(<SafeAreaProvider initialMetrics={METRICS}>{ui}</SafeAreaProvider>)
const NOT_FOUND = "We can't find that email. Check the spelling, or ask your estate office to add it."

beforeEach(async () => {
  await getStorage().clearAll()
  mockAnswer = { data: { sent: true, destination_hint: 'h•••@example.com', resend_after: new Date(Date.now() + 30_000).toISOString() } }
  mockCalls.length = 0
  mockPush.mockClear()
  signInDraft.clear()
})

const ask = async (input: Parameters<ReturnType<typeof useRequestCode>['request']>[0]) => {
  const { result } = await renderHook(() => useRequestCode())
  let outcome: Awaited<ReturnType<typeof result.current.request>> | null = null
  await act(async () => {
    outcome = await result.current.request(input)
  })
  return outcome
}

describe('asking for a code says what it is for', () => {
  it('a sign-in sends its intent and the install that asks', async () => {
    await ask({ estate: 'phoenixparkvillage1', email: 'hassam.lookup@example.com', intent: 'sign_in' })
    expect(mockCalls).toEqual([{ estate: 'phoenixparkvillage1', channel: 'email', destination: 'hassam.lookup@example.com', intent: 'sign_in', device_uid: 'test-install' }])
  })

  it('Create account sends sign_up, and no install id', async () => {
    await ask({ estate: 'phoenixparkvillage1', email: 'new.lookup@example.com', fullName: 'New Person', intent: 'sign_up' })
    expect(mockCalls).toEqual([{ estate: 'phoenixparkvillage1', channel: 'email', destination: 'new.lookup@example.com', intent: 'sign_up' }])
  })

  it('keeps the intent for the code screen, so a resend asks the same way', async () => {
    await ask({ estate: 'phoenixparkvillage1', email: 'new.lookup@example.com', fullName: 'New Person', intent: 'sign_up' })
    expect(signInDraft.get().intent).toBe('sign_up')
  })
})

describe('an email the register does not hold', () => {
  it('is said beside the email field, in the server\'s words, and the code screen is not reached', async () => {
    mockAnswer = { error: { status: 422, code: 'email_not_found', message: NOT_FOUND, fieldErrors: {}, retryAfter: null } }
    const outcome = await ask({ estate: 'phoenixparkvillage1', email: 'nobody.lookup@example.com', intent: 'sign_in' })

    expect(outcome).toEqual({ ok: false, field: 'email', message: NOT_FOUND })
    expect(signInDraft.get().email).toBe('')
  })

  it('on Log in: under the email box, and no move to the code screen', async () => {
    mockAnswer = { error: { status: 422, code: 'email_not_found', message: NOT_FOUND, fieldErrors: {}, retryAfter: null } }
    await render(<LogInScreen />)
    await act(async () => fireEvent.changeText(screen.getByLabelText('Email address'), 'nobody.lookup@example.com'))
    await act(async () => fireEvent.press(screen.getByLabelText('Log in')))

    expect(mockCalls).toEqual([{ estate: 'phoenixparkvillage1', channel: 'email', destination: 'nobody.lookup@example.com', intent: 'sign_in', device_uid: 'test-install' }])
    expect(screen.getByText(NOT_FOUND)).toBeTruthy()
    expect(mockPush).not.toHaveBeenCalled()
  })
})

describe('the code screen', () => {
  const opened = async () => {
    signInDraft.set({ estate: 'phoenixparkvillage1', email: 'hassam.lookup@example.com', intent: 'sign_in', hint: 'h•••@example.com', resendAfter: new Date(Date.now() + 30_000).toISOString() })
    await render(<CodeScreen />)
  }

  it('is headed with where the code went, masked, and says when it can be sent again', async () => {
    await opened()
    expect(screen.getByText('Code sent to h•••@example.com')).toBeTruthy()
    expect(screen.getByText("Didn't get it? Resend in 30s")).toBeTruthy()
  })
})
