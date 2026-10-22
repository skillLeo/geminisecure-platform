/**
 * A QUESTION IS NEVER ASKED OVER THE APP LOCK (INV-12, DESIGN §12.1 rule b).
 *
 * A ConfirmSheet is a Modal — a window above everything the navigator draws, the app lock included (the lock is an
 * ordinary view mounted after the navigator). Without this, "Remove Soft Dev from your household?" asked before the
 * phone went into a pocket would come back ABOVE the lock, answerable by whoever picked the phone up. So the lock
 * says when it is down, and every sheet hides while it is.
 */

import { act, render } from '@testing-library/react-native'
import { isCovered } from '@gs/core'
import { settingsPrefs } from '../prefs'
import { AppLock } from '../components/AppLock'

// The kit's skeletons animate on the UI thread; under Jest both halves of that run as their own mocks.
jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light' } }))
let mockPath = '/(tabs)/profile'
jest.mock('expo-router', () => ({ router: { push: jest.fn() }, usePathname: () => mockPath }))
jest.mock('expo-local-authentication', () => ({
  SecurityLevel: { NONE: 0, SECRET: 1, BIOMETRIC_WEAK: 2, BIOMETRIC_STRONG: 3 },
  getEnrolledLevelAsync: jest.fn(async () => 3),
  // The owner has not answered yet: the lock stays down.
  authenticateAsync: jest.fn(async () => ({ success: false, error: 'user_cancel' })),
}))
const mockState = { session: { status: 'active' } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)
const settle = async () => { await act(async () => { for (let i = 0; i < 5; i++) await Promise.resolve() }) }

beforeEach(() => {
  mockPath = '/(tabs)/profile'
  mockState.session.status = 'active'
})

describe('the app lock tells every confirmation sheet when it covers the app', () => {
  it('while the lock is down the app is covered, and not once it is gone', async () => {
    settingsPrefs.set({ appLock: true })
    const view = await render(<AppLock />)
    await settle()
    expect(isCovered()).toBe(true)
    await view.unmount()
    expect(isCovered()).toBe(false)
  })

  it('the lock never covers the emergency alert, so nothing is hidden there by it', async () => {
    settingsPrefs.set({ appLock: true })
    mockPath = '/panic'
    const view = await render(<AppLock />)
    await settle()
    expect(isCovered()).toBe(false)
    await view.unmount()
  })

  it('with the lock switched off nothing is covered', async () => {
    settingsPrefs.set({ appLock: false })
    const view = await render(<AppLock />)
    await settle()
    expect(isCovered()).toBe(false)
    await view.unmount()
  })
})
