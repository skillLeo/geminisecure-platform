/**
 * THE GUARD APP SIGNS BACK IN WITH A FINGERPRINT, NEVER A CODE (M32 F3, §6.4) — on the phone, end to end, with the
 * server scripted and the secure store real enough to outlive the app's own memory.
 *
 *   - a simulated update preserves the binding: the app's code and memory start again from nothing, and the token,
 *     the phone's key, its install id and its binding record are all still there, so the guard is still himself;
 *   - clearing the app's data loses the binding, and the enrolment screen says the phone has not been set up;
 *   - a phone the server removed loses its binding when it asks, and the enrolment screen names the removal;
 *   - a 30-minute sign-out keeps the binding and the setup, and asks for the fingerprint only — no code, no setup;
 *   - three misses, or a phone with no fingerprint, open the supervisor's PIN, which goes once and is dropped;
 *   - a phone signed in by an earlier version is given its binding, and one whose session ended before the update is
 *     sent to the fingerprint, not the code;
 *   - "not my phone" goes to enrolment saying whose it is, and a code for somebody else walks them through setup;
 *   - (M46 §1) a key that could not be read just now is tried again and nothing is forgotten; a key that is gone keeps
 *     the binding and says the phone's own code sets it up again; the handset's identity goes with enrolment and with
 *     every sign-back-in, and a spent code typed again on the same phone comes back as a recovery.
 *
 * The server's half (the challenge, the signature, the removal's date and who did it) is the web repository's
 * `tests/Feature/M32GuardResumeTest.php`, and the recovery's is `tests/Feature/M46GuardRecoveryTest.php`.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import nacl from 'tweetnacl'
import { act, render, renderHook } from '@testing-library/react-native'
import * as LocalAuthentication from 'expo-local-authentication'
import * as SecureStore from 'expo-secure-store'
import { bootSession, clearEverything, tokenStorage } from '@gs/core/auth'
import { base64UrlDecode, devicePublicKey, deviceUid, resumeMessage } from '@gs/core/crypto'
import { getStorage } from '@gs/core/lib'
import { nextRoute } from '@/features/boot/nextRoute'
import { SessionGate } from '@/features/boot/SessionGate'
import { KEEP_FOR_SIGNING_BACK_IN, REMOVED_BY_YOU, binding } from '@/features/enrolment/binding'
import { onboarding } from '@/features/enrolment/onboardingState'
import { openSession } from '@/features/enrolment/openSession'
import { useEnrolCode } from '@/features/enrolment/useEnrolCode'
import { KEEP_ON_SESSION_END } from '@/features/tracking/trackingStore'
import { KEY_LOST, NOT_SET_UP, UNREADABLE } from '../resume'
import { useUnlock } from '../useUnlock'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))

// The secure store keeps its values OUTSIDE the module registry, as Android keeps them outside the app's memory: an
// update (`jest.resetModules()`) starts every module again and finds them still there.
jest.mock('expo-secure-store', () => {
  const vault: Map<string, string> = ((globalThis as { mockVault?: Map<string, string> }).mockVault ??= new Map())
  return {
    WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'WHEN_UNLOCKED_THIS_DEVICE_ONLY',
    getItemAsync: jest.fn(async (k: string) => (vault.has(k) ? vault.get(k) : null)),
    setItemAsync: jest.fn(async (k: string, v: string) => void vault.set(k, v)),
    deleteItemAsync: jest.fn(async (k: string) => void vault.delete(k)),
  }
})
jest.mock('expo-crypto', () => {
  const { randomBytes, randomUUID } = jest.requireActual('node:crypto')
  return { getRandomBytes: (n: number) => new Uint8Array(randomBytes(n)), randomUUID: () => randomUUID() }
})
jest.mock('expo-local-authentication', () => ({
  hasHardwareAsync: jest.fn(async () => true),
  isEnrolledAsync: jest.fn(async () => true),
  authenticateAsync: jest.fn(async () => ({ success: true })),
}))
jest.mock('expo-device', () => ({ modelName: 'Pixel 7' }))
const mockReplace = jest.fn()
const mockPush = jest.fn()
jest.mock('expo-router', () => ({
  router: { replace: (href: string) => mockReplace(href), push: (href: string) => mockPush(href), back: jest.fn() },
  useSegments: () => ['(tabs)'],
  usePathname: () => '/unlock',
  useFocusEffect: jest.fn(),
}))
jest.mock('@/offline/runtime', () => ({ startOfflineRuntime: jest.fn(async () => undefined), watchNetwork: jest.fn() }))
// Which handset this is (M46 §1): Android's per-app id on a phone, none on iOS or the web.
let mockHandset: string | null = null
jest.mock('@/features/enrolment/handset', () => ({ handsetId: () => mockHandset }))

type Refusal = { status: number | string; code: string; message: string; fieldErrors: Record<string, string[]>; retryAfter: null; details?: Record<string, unknown> }
type Answer = { data?: unknown; error?: Refusal }
type Sent = { endpoint: 'challenge' | 'resume' | 'enrol'; arg: Record<string, unknown> }

const mockSent: Sent[] = []
const mockServer: { challenge: (arg: Record<string, unknown>) => Answer; resume: (arg: Record<string, unknown>) => Answer; enrol: (arg: Record<string, unknown>) => Answer } = {
  challenge: () => ({ data: { challenge: 'Q2hhbGxlbmdlLW9uZS10aW1l', expires_at: '2026-10-05T03:02:00Z' } }),
  resume: () => ({ error: { status: 403, code: 'device_not_enrolled', message: 'x', fieldErrors: {}, retryAfter: null } }),
  enrol: () => ({ error: { status: 422, code: 'enrolment_code_invalid', message: 'x', fieldErrors: {}, retryAfter: null } }),
}
const mockEnrol = jest.fn(async (arg: Record<string, unknown>) => {
  mockSent.push({ endpoint: 'enrol', arg })
  return mockServer.enrol(arg)
})
const mockDispatch = jest.fn(async (action: unknown) => {
  const marker = action as { mockEndpoint?: Sent['endpoint']; arg?: Record<string, unknown> }
  if (!marker?.mockEndpoint) return action
  mockSent.push({ endpoint: marker.mockEndpoint, arg: marker.arg ?? {} })
  return marker.mockEndpoint === 'challenge' ? mockServer.challenge(marker.arg ?? {}) : mockServer.resume(marker.arg ?? {})
})
jest.mock('@gs/core/api/guard', () => ({
  guardApi: {
    endpoints: {
      devicesChallenge: { initiate: (arg: Record<string, unknown>) => ({ mockEndpoint: 'challenge', arg }) },
      devicesResume: { initiate: (arg: Record<string, unknown>) => ({ mockEndpoint: 'resume', arg }) },
    },
    useDevicesEnrolMutation: () => [mockEnrol],
    useDevicesEnrolStatusMutation: () => [jest.fn()],
  },
}))
type MockSession = { status: string; endedReason: string | null; principal: { id: number; name: string; siteName: string | null; reference: string | null } | null }
const mockState: { network: { online: boolean }; session: MockSession } = { network: { online: true }, session: { status: 'signedOut', endedReason: null, principal: null } }
jest.mock('@/store', () => ({ useAppDispatch: () => mockDispatch, useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)

const vault = (): Map<string, string> => (globalThis as { mockVault?: Map<string, string> }).mockVault!
const settle = async () => {
  await act(async () => {
    for (let i = 0; i < 12; i++) await Promise.resolve()
  })
}

const GUARD = { id: 9001, name: 'Desmond Clarke', employee_number: 'GS-9001' }
const SITE = { id: 'phoenixparkvillage1', name: 'Phoenix Park Village 1' }
const SETUP_DONE = { finishedAt: '2026-10-01T08:00:00Z', notificationsAskedAt: '2026-10-01T07:58:00Z', locationDisclosedAt: '2026-10-01T07:59:00Z', detailsConfirmedAt: 'a', identity: 'confirmed' as const, kitAcknowledgedAt: 'a' }
const REMOVED = 'Your supervisor removed this phone on 2 October. Ask the office for a new code.'
const KEEP = [...KEEP_ON_SESSION_END, ...KEEP_FOR_SIGNING_BACK_IN]

/** The phone as enrolment leaves it: its own key and install id, the token, and the binding (openSession.ts). */
async function enrolledPhone(): Promise<{ uid: string; publicKey: string }> {
  const publicKey = await devicePublicKey()
  const uid = await deviceUid()
  await openSession(mockDispatch, 'T-1', { guard: GUARD, site: SITE })
  onboarding.update(SETUP_DONE)
  return { uid, publicKey }
}

/** The server's own check of a resume: the signature is the bound key's, over this install and the challenge it gave. */
function signedByThisPhone(arg: Record<string, unknown>, publicKey: string): boolean {
  const message = Uint8Array.from(resumeMessage(String(arg.device_uid), String(arg.challenge)), (ch) => ch.charCodeAt(0))
  return nacl.sign.detached.verify(message, base64UrlDecode(String(arg.signature))!, base64UrlDecode(publicKey)!)
}

const bound = { state: 'bound', reviewer: false, abilities: [], guard: GUARD, site: SITE }
const resumed = (token: string) => ({ data: { ...bound, state: 'resumed', token } })

beforeEach(async () => {
  vault().clear()
  await getStorage().clearAll()
  await tokenStorage.clear('guard')
  await binding.hydrate()
  mockSent.length = 0
  mockReplace.mockClear()
  mockPush.mockClear()
  mockDispatch.mockClear()
  jest.mocked(LocalAuthentication.authenticateAsync).mockClear().mockResolvedValue({ success: true } as never)
  jest.mocked(LocalAuthentication.hasHardwareAsync).mockResolvedValue(true)
  jest.mocked(LocalAuthentication.isEnrolledAsync).mockResolvedValue(true)
  mockState.network.online = true
  mockState.session = { status: 'signedOut', endedReason: null, principal: null }
})

describe('a simulated update preserves the binding', () => {
  it('keeps the token, the key, the install id and the binding when the app starts again from nothing', async () => {
    const before = await enrolledPhone()

    // THE UPDATE: every module starts again with nothing in memory; the secure store, which an update keeps, is kept.
    jest.resetModules()
    const after = {
      binding: (require('@/features/enrolment/binding') as typeof import('@/features/enrolment/binding')).binding,
      auth: require('@gs/core/auth') as typeof import('@gs/core/auth'),
      crypto: require('@gs/core/crypto') as typeof import('@gs/core/crypto'),
      route: require('@/features/boot/nextRoute') as typeof import('@/features/boot/nextRoute'),
    }
    expect(after.binding.peek()).toBeNull()
    expect(after.auth.tokenStorage.peek()).toBeNull()

    await after.binding.hydrate()
    expect(await after.auth.bootSession('guard', jest.fn())).toBe('active')
    expect(after.auth.tokenStorage.peek()).toBe('T-1')
    expect(after.binding.peek()).toMatchObject({ guardId: 9001, name: 'Desmond Clarke', reference: 'GS-9001', siteName: 'Phoenix Park Village 1' })
    expect(await after.crypto.deviceUid()).toBe(before.uid)
    expect(await after.crypto.devicePublicKey()).toBe(before.publicKey)
  })
})

describe('clearing the app data loses the binding, and the screen says so', () => {
  it('has no session and no binding, goes to enrolment, and says the phone has not been set up: asking nobody', async () => {
    await enrolledPhone()

    // "Clear storage" (or an uninstall): the app's data goes, the secure store's values with it.
    vault().clear()
    await getStorage().clearAll()
    await binding.hydrate()

    expect(await bootSession('guard', jest.fn())).toBe('signedOut')
    expect(binding.peek()).toBeNull()
    expect(nextRoute('signedOut')).toBe('/enrol')

    const view = await renderHook(() => useEnrolCode(jest.fn()))
    await settle()
    expect(view.result.current.caseLine).toBe(NOT_SET_UP)
    // Not "ask the office", which is no route back at 2am (M46 §1.3): a phone set up before takes its own code again.
    expect(NOT_SET_UP).toBe('This phone is not set up for GeminiSecure yet. If it was set up before, type the code you used then: it works again on the same phone. A new phone needs a new code from your supervisor.')
    expect(view.result.current.removed).toBe(false)
    // No key on the phone, so nothing to prove and nothing asked of the server.
    expect(mockSent).toEqual([])
  })
})

describe('a removed phone loses its binding, and the screen names the removal', () => {
  it('asks the server on the unlock screen, signed, no fingerprint, and lands on enrolment with the server\'s own sentence', async () => {
    const phone = await enrolledPhone()
    // Another phone was approved in this one's place: its next request is refused (401), and the session ends.
    await clearEverything('guard', mockDispatch, 'revoked', KEEP)
    mockState.session = { status: 'signedOut', endedReason: 'revoked', principal: null }
    expect(nextRoute('signedOut')).toBe('/unlock')

    mockServer.resume = (arg) => {
      expect(signedByThisPhone(arg, phone.publicKey)).toBe(true)
      return { error: { status: 403, code: 'device_removed', message: REMOVED, fieldErrors: {}, retryAfter: null, details: { removed_at: '2026-10-02T14:05:00Z', removed_by: 'supervisor' } } }
    }
    await renderHook(() => useUnlock())
    await settle()

    expect(mockSent.map((sent) => sent.endpoint)).toEqual(['challenge', 'resume'])
    expect(mockSent[1]!.arg).toMatchObject({ device_uid: phone.uid, method: 'check' })
    expect(LocalAuthentication.authenticateAsync).not.toHaveBeenCalled()
    expect(binding.peek()).toBeNull()
    expect(binding.removal()).toEqual({ message: REMOVED, at: '2026-10-02T14:05:00Z' })
    expect(mockReplace).toHaveBeenCalledWith('/enrol')
    expect(nextRoute('signedOut')).toBe('/enrol')

    mockSent.length = 0
    const enrol = await renderHook(() => useEnrolCode(jest.fn()))
    await settle()
    expect(enrol.result.current.caseLine).toBe(REMOVED)
    expect(enrol.result.current.removed).toBe(true)
    // Told once; the enrolment screen does not ask again.
    expect(mockSent).toEqual([])
  })

  it('says so on the screen, with the emergency numbers under it', () => {
    const screen = readFileSync(path.join(__dirname, '..', '..', 'enrolment', 'screens', 'EnrolCodeScreen.tsx'), 'utf8')
    expect(screen).toContain('{m.caseLine}')
    expect(screen).toMatch(/\{m\.removed \? \(/)
    expect(screen).toContain('call 119 for the police, or 110 for fire and ambulance')
  })
})

describe('a 30-minute sign-out keeps the binding and asks for biometrics only', () => {
  it('keeps the key, the binding and the setup, asks for the fingerprint, and signs back in with no code', async () => {
    const phone = await enrolledPhone()
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    mockState.session = { status: 'signedOut', endedReason: 'expired', principal: null }

    expect(tokenStorage.peek()).toBeNull()
    expect(binding.peek()).toMatchObject({ guardId: 9001 })
    expect(onboarding.isFinished()).toBe(true)
    expect(nextRoute('signedOut')).toBe('/unlock')

    mockServer.resume = (arg) => {
      expect(signedByThisPhone(arg, phone.publicKey)).toBe(true)
      return arg.method === 'check' ? { data: bound } : resumed('T-2')
    }
    const view = await renderHook(() => useUnlock())
    await settle()
    expect(view.result.current.why).toBe('You were signed out after 30 minutes with nothing sent from this phone. Nothing you saved on it has been lost.')
    expect(view.result.current.pinOpen).toBe(false)
    expect(view.result.current.notActive).toBeNull()

    await act(async () => view.result.current.unlock())
    await settle()

    expect(LocalAuthentication.authenticateAsync).toHaveBeenCalledWith({ promptMessage: 'Confirm it is you to sign back in', cancelLabel: 'Cancel', disableDeviceFallback: true })
    expect(mockSent.filter((sent) => sent.endpoint === 'resume').map((sent) => sent.arg.method)).toEqual(['check', 'biometric'])
    expect(mockSent.some((sent) => 'enrolment_code' in sent.arg)).toBe(false)
    expect(tokenStorage.peek()).toBe('T-2')
    expect(binding.peek()).toMatchObject({ guardId: 9001 })
    // Setup is not asked again: the same officer, on the same phone.
    expect(onboarding.isFinished()).toBe(true)
    expect(mockReplace).toHaveBeenLastCalledWith(nextRoute('active'))
    expect(nextRoute('active')).not.toMatch(/^\/onboarding/)
  })

  it('is the store\'s own rule: a session that ends by itself keeps what signing back in needs', () => {
    const store = readFileSync(path.join(__dirname, '..', '..', '..', 'store', 'index.ts'), 'utf8')
    expect(store).toContain('await clearEverything(\'guard\', store.dispatch, reason, [...KEEP_ON_SESSION_END, ...KEEP_FOR_SIGNING_BACK_IN])')
    expect(KEEP_FOR_SIGNING_BACK_IN).toEqual(['v1:principal', 'v1:onboarding', 'v1:tour'])
  })

  it('a failed match is counted, and the third opens the supervisor\'s PIN, which goes once, and is dropped', async () => {
    await enrolledPhone()
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    mockState.session = { status: 'signedOut', endedReason: 'expired', principal: null }
    mockServer.resume = (arg) => (arg.method === 'check' ? { data: bound } : resumed('T-3'))
    jest.mocked(LocalAuthentication.authenticateAsync).mockResolvedValue({ success: false, error: 'user_cancel' } as never)

    const view = await renderHook(() => useUnlock())
    await settle()
    for (let i = 0; i < 3; i++) {
      await act(async () => view.result.current.unlock())
      await settle()
    }
    expect(view.result.current.attemptsLeft).toBe(0)
    expect(view.result.current.pinOpen).toBe(true)
    expect(mockSent.filter((sent) => sent.arg.method === 'biometric')).toEqual([])

    await act(async () => {
      view.result.current.setSupervisor(' D.Brown@GeminiSL.com ')
      view.result.current.setPin('48-2913')
    })
    await act(async () => view.result.current.vouch())
    await settle()

    const vouched = mockSent.filter((sent) => sent.arg.method === 'supervisor_pin')
    expect(vouched).toHaveLength(1)
    expect(vouched[0]!.arg).toMatchObject({ supervisor: 'd.brown@geminisl.com', pin: '482913' })
    expect(tokenStorage.peek()).toBe('T-3')
  })

  it('a phone with no fingerprint set up goes straight to the supervisor\'s PIN, and a refusal keeps nothing of the PIN', async () => {
    await enrolledPhone()
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    jest.mocked(LocalAuthentication.isEnrolledAsync).mockResolvedValue(false)
    mockServer.resume = (arg) =>
      arg.method === 'check' ? { data: bound } : { error: { status: 403, code: 'override_refused', message: 'That supervisor address and PIN were not accepted.', fieldErrors: {}, retryAfter: null } }

    const view = await renderHook(() => useUnlock())
    await settle()
    expect(view.result.current.biometrics).toBe('none')
    expect(view.result.current.pinOpen).toBe(true)

    await act(async () => {
      view.result.current.setSupervisor('d.brown@geminisl.com')
      view.result.current.setPin('000000')
    })
    await act(async () => view.result.current.vouch())
    await settle()

    expect(view.result.current.problem).toBe('That supervisor address and PIN were not accepted.')
    expect(view.result.current.fields).toEqual({ supervisor: 'd.brown@geminisl.com', pin: '' })
    expect(tokenStorage.peek()).toBeNull()
    expect(binding.peek()).toMatchObject({ guardId: 9001 })
  })

  it('with no signal, says signing back in needs one and asks nothing', async () => {
    await enrolledPhone()
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    mockState.network.online = false

    const view = await renderHook(() => useUnlock())
    await settle()
    await act(async () => view.result.current.unlock())
    await settle()

    expect(mockSent).toEqual([])
    expect(LocalAuthentication.authenticateAsync).not.toHaveBeenCalled()
    expect(view.result.current.problem).toMatch(/^Signing back in needs a connection/)
  })
})

describe('phones enrolled before this version', () => {
  it('a phone still signed in is given its binding from the session it holds', async () => {
    await deviceUid()
    await tokenStorage.set('guard', 'T-old')
    expect(binding.peek()).toBeNull()

    // As on a phone: booting, then the session the earlier version left behind.
    const view = await render(<SessionGate />)
    await settle()
    mockState.session = { status: 'active', endedReason: null, principal: { id: 9001, name: 'Desmond Clarke', siteName: 'Phoenix Park Village 1', reference: 'GS-9001' } }
    await view.rerender(<SessionGate />)
    await settle()

    expect(binding.peek()).toMatchObject({ guardId: 9001, name: 'Desmond Clarke', reference: 'GS-9001' })
  })

  it('a phone whose session ended before the update asks the server, and is sent to the fingerprint rather than a code', async () => {
    // Enrolled by the earlier version: a key and an install id, no binding record — and the session already over.
    await devicePublicKey()
    await deviceUid()
    mockServer.resume = (arg) => (arg.method === 'check' ? { data: bound } : resumed('T-4'))

    await renderHook(() => useEnrolCode(jest.fn()))
    await settle()

    expect(mockSent.map((sent) => [sent.endpoint, sent.arg.method])).toEqual([['challenge', undefined], ['resume', 'check']])
    expect(binding.peek()).toMatchObject({ guardId: 9001, name: 'Desmond Clarke' })
    expect(mockReplace).toHaveBeenCalledWith('/unlock')
  })

  it("the reviewer's demonstration account, enrolled before this version, takes the server's flag from the check: the passcode may stand in", async () => {
    await devicePublicKey()
    await deviceUid()
    mockServer.resume = (arg) => (arg.method === 'check' ? { data: { ...bound, reviewer: true } } : resumed('T-5'))

    await renderHook(() => useEnrolCode(jest.fn()))
    await settle()
    expect(binding.peek()).toMatchObject({ guardId: 9001, reviewer: true })

    // A phone with no fingerprint: on this account alone the phone's passcode stands in, and no supervisor is asked.
    jest.mocked(LocalAuthentication.isEnrolledAsync).mockResolvedValue(false)
    const view = await renderHook(() => useUnlock())
    await settle()
    expect(view.result.current.passcode).toBe(true)
    expect(view.result.current.pinOpen).toBe(false)

    await act(async () => view.result.current.unlock())
    await settle()
    expect(LocalAuthentication.authenticateAsync).toHaveBeenCalledWith({ promptMessage: 'Confirm it is you to sign back in', cancelLabel: 'Cancel', disableDeviceFallback: false })
    expect(tokenStorage.peek()).toBe('T-5')
  })

  it("a binding made from an earlier version's session learns the reviewer flag from the unlock screen's own check", async () => {
    await enrolledPhone()
    expect(binding.peek()?.reviewer).toBe(false)
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    mockServer.resume = (arg) => (arg.method === 'check' ? { data: { ...bound, reviewer: true } } : resumed('T-6'))

    const view = await renderHook(() => useUnlock())
    await settle()
    expect(view.result.current.passcode).toBe(true)
    expect(binding.peek()).toMatchObject({ guardId: 9001, reviewer: true })
  })

  it('a real guard is told no such thing: the check keeps the fingerprint, and the passcode never stands in', async () => {
    await enrolledPhone()
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    mockServer.resume = (arg) => (arg.method === 'check' ? { data: bound } : resumed('T-7'))

    const view = await renderHook(() => useUnlock())
    await settle()
    expect(view.result.current.passcode).toBe(false)
    expect(binding.peek()).toMatchObject({ guardId: 9001, reviewer: false })
  })
})

describe('somebody else on this phone', () => {
  it('"Not your phone?" goes to enrolment, which says whose it is', async () => {
    await enrolledPhone()
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    mockServer.resume = () => ({ data: bound })

    const unlock = await renderHook(() => useUnlock())
    await settle()
    await act(async () => unlock.result.current.notMine())
    expect(mockPush).toHaveBeenCalledWith('/enrol')

    mockSent.length = 0
    const enrol = await renderHook(() => useEnrolCode(jest.fn()))
    await settle()
    expect(enrol.result.current.caseLine).toBe('This phone is set up for Desmond Clarke. A one-time code from the office sets it up for you instead.')
    // The phone is somebody's: it does not ask the server on their behalf.
    expect(mockSent).toEqual([])
  })

  it('a code for another officer starts setup over for them, and the phone becomes theirs', async () => {
    await enrolledPhone()
    expect(onboarding.isFinished()).toBe(true)

    await openSession(mockDispatch, 'T-9', { guard: { id: 9002, name: 'Marcia Brown', employee_number: 'GS-9002' }, site: SITE })

    expect(onboarding.isFinished()).toBe(false)
    expect(binding.peek()).toMatchObject({ guardId: 9002, name: 'Marcia Brown' })
  })

  it('"Remove this app from this phone" is remembered, and coming back takes a code: no fingerprint, no asking', async () => {
    await devicePublicKey()
    await binding.markRemoved({ message: REMOVED_BY_YOU, at: '2026-10-05T03:00:00Z' })

    const enrol = await renderHook(() => useEnrolCode(jest.fn()))
    await settle()

    expect(nextRoute('signedOut')).toBe('/enrol')
    expect(enrol.result.current.caseLine).toBe(REMOVED_BY_YOU)
    expect(mockSent).toEqual([])
  })
})

describe('a phone that lost its sign-in gets back in without the office (M46 §1)', () => {
  // The instance this file's own modules hold — not a fresh one from the registry the update test reset.
  const store = () => ({ getItemAsync: jest.mocked(SecureStore.getItemAsync) })

  it('a key that could not be read just now is tried again: nothing is forgotten, and the guard stays on the sign-in', async () => {
    await enrolledPhone()
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    mockState.session = { status: 'signedOut', endedReason: 'expired', principal: null }
    const reads = store().getItemAsync.getMockImplementation()!
    store().getItemAsync.mockImplementation(async (k: string, options?: SecureStore.SecureStoreOptions) => {
      if (k === 'gs.guard.device_secret') throw new Error('KeyStore is not available just now')
      return reads(k, options)
    })

    try {
      const view = await renderHook(() => useUnlock())
      await settle()
      expect(mockSent).toEqual([])
      expect(mockReplace).not.toHaveBeenCalled()
      expect(binding.peek()).toMatchObject({ guardId: 9001 })

      await act(async () => view.result.current.unlock())
      await settle()
      expect(view.result.current.problem).toBe(UNREADABLE)
      expect(binding.peek()).toMatchObject({ guardId: 9001 })
      expect(vault().has('gs.guard.binding')).toBe(true)
    } finally {
      store().getItemAsync.mockImplementation(reads)
    }
  })

  it('a phone whose own key is gone keeps its binding, and the enrolment screen says its own code sets it up again', async () => {
    await enrolledPhone()
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    mockState.session = { status: 'signedOut', endedReason: 'expired', principal: null }
    vault().delete('gs.guard.device_secret')

    await renderHook(() => useUnlock())
    await settle()

    expect(mockSent).toEqual([])
    expect(mockReplace).toHaveBeenCalledWith('/enrol')
    expect(binding.peek()).toMatchObject({ guardId: 9001 })
    expect(vault().has('gs.guard.binding')).toBe(true)

    const enrol = await renderHook(() => useEnrolCode(jest.fn()))
    await settle()
    expect(enrol.result.current.caseLine).toBe(KEY_LOST)
    expect(enrol.result.current.removed).toBe(false)
    expect(mockSent).toEqual([])
  })

  it('names the handset with every sign-back-in, and sends nothing of the kind where there is none', async () => {
    await enrolledPhone()
    await clearEverything('guard', mockDispatch, 'expired', KEEP)
    mockState.session = { status: 'signedOut', endedReason: 'expired', principal: null }
    mockServer.resume = (arg) => (arg.method === 'check' ? { data: bound } : resumed('T-8'))

    mockHandset = 'a1b2c3d4e5f60718'
    try {
      const view = await renderHook(() => useUnlock())
      await settle()
      await act(async () => view.result.current.unlock())
      await settle()
      expect(mockSent.filter((sent) => sent.endpoint === 'resume').map((sent) => [sent.arg.method, sent.arg.hardware_id])).toEqual([
        ['check', 'a1b2c3d4e5f60718'],
        ['biometric', 'a1b2c3d4e5f60718'],
      ])
    } finally {
      mockHandset = null
    }

    mockSent.length = 0
    await renderHook(() => useUnlock())
    await settle()
    expect(mockSent.find((sent) => sent.endpoint === 'resume')!.arg).not.toHaveProperty('hardware_id')
  })

  it('a spent code typed again on the same phone goes with the handset, and the recovery opens the session', async () => {
    await enrolledPhone()
    // The app's data is wiped: a new install, a new key — the same handset.
    vault().clear()
    await getStorage().clearAll()
    await binding.hydrate()
    mockServer.enrol = () => ({ data: { status: 'enrolled', recovered: true, reviewer: false, token: 'T-R', abilities: [], guard: GUARD, site: SITE } })

    mockHandset = 'a1b2c3d4e5f60718'
    try {
      const enrolled = jest.fn()
      const view = await renderHook(() => useEnrolCode(enrolled))
      await settle()
      await act(async () => view.result.current.setCode('uz88nauz'))
      await act(async () => view.result.current.submit())
      await settle()

      const sent = mockSent.filter((s) => s.endpoint === 'enrol')
      expect(sent).toHaveLength(1)
      expect(sent[0]!.arg).toMatchObject({ enrolment_code: 'UZ88-NAUZ', platform: expect.any(String), hardware_id: 'a1b2c3d4e5f60718' })
      expect(enrolled).toHaveBeenCalledTimes(1)
      expect(tokenStorage.peek()).toBe('T-R')
      expect(binding.peek()).toMatchObject({ guardId: 9001, name: 'Desmond Clarke' })
      expect(binding.keyLost()).toBe(false)
    } finally {
      mockHandset = null
    }
  })
})
