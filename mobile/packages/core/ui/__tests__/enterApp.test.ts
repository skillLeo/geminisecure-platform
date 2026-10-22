/**
 * Into the app with nothing behind it — found on the emulator, 26 Sep: Back from the resident's Home reached
 * "Welcome back · Log in" while signed in, and a guard's Home button on Clock In went back into setup step 3.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'

const mockRouter = { canDismiss: jest.fn(() => true), dismissAll: jest.fn(), replace: jest.fn() }
// A getter: the import below is hoisted above `mockRouter`, so the factory must not read it until a call does.
jest.mock('expo-router', () => ({ get router() { return mockRouter } }))

// eslint-disable-next-line import/first
import { enterApp } from '../enterApp'

beforeEach(() => jest.clearAllMocks())

it('clears the history, then replaces what is left with the first screen', () => {
  enterApp('/(tabs)')
  expect(mockRouter.dismissAll).toHaveBeenCalledTimes(1)
  expect(mockRouter.replace).toHaveBeenCalledWith('/(tabs)')
  expect(mockRouter.dismissAll.mock.invocationCallOrder[0]).toBeLessThan(mockRouter.replace.mock.invocationCallOrder[0]!)
})

it('just replaces when there is nothing to clear, or no navigator to ask', () => {
  mockRouter.canDismiss.mockReturnValueOnce(false)
  enterApp('/clock-in')
  mockRouter.canDismiss.mockImplementationOnce(() => { throw new Error('no navigator') })
  enterApp('/clock-in')
  expect(mockRouter.dismissAll).not.toHaveBeenCalled()
  expect(mockRouter.replace).toHaveBeenCalledTimes(2)
})

it('is how both apps leave sign-in and setup', () => {
  const read = (...p: string[]) => readFileSync(path.join(__dirname, '..', '..', '..', '..', 'apps', ...p), 'utf8')
  // Every proven sign-in — the emailed code, a restore, the fingerprint (M50 §3) — leaves through one helper.
  expect(read('resident', 'src', 'features', 'auth', 'afterSignIn.ts')).toMatch(/enterApp\(\(permissionsAsked\.get\(\) \? NEXT\.home : '\/permissions'\)/)
  expect(read('resident', 'src', 'features', 'auth', 'afterSignIn.ts')).toMatch(/enterApp\('\/panic'/)
  expect(read('resident', 'src', 'features', 'auth', 'screens', 'CodeScreen.tsx')).toMatch(/goAfterSignIn\(outcome\.next\)/)
  expect(read('resident', 'src', 'features', 'auth', 'screens', 'PermissionsScreen.tsx')).toMatch(/usePermissions\(\(\) => enterApp\('\/\(tabs\)'\)\)/)
  // (The demonstration door, the third way in, is gone from Log in and Create account: M47 §1.2 1g, §2.2 2g.)
  expect(read('guard', 'src', 'features', 'enrolment', 'screens', 'OnboardingScreen.tsx')).toMatch(/to === 'done' \? enterApp\('\/clock-in'\)/)
})

it('is how both apps leave a session too, so nothing signed out stays mounted and polling (M22)', () => {
  const read = (...p: string[]) => readFileSync(path.join(__dirname, '..', '..', '..', '..', 'apps', ...p), 'utf8')
  const resident = read('resident', 'src', 'features', 'boot', 'SessionGate.tsx')
  const guard = read('guard', 'src', 'features', 'boot', 'SessionGate.tsx')
  expect(resident).toMatch(/status === 'signedOut' && !OPEN_TO_ALL\.has\(first\)\) enterApp\('\/\(auth\)\/log-in'/)
  expect(resident).toMatch(/status === 'pending' && !OPEN_TO_ALL\.has\(first\)\) enterApp\('\/\(auth\)\/pending'/)
  // The Guard App's way out goes to signing back in while the phone is still the guard's, else enrolment (M32 F3).
  expect(guard).toMatch(/status === 'signedOut' && !OPEN_TO_ALL\.has\(first\)\) enterApp\(signedOutRoute\(\) as never\)/)
  expect(resident + guard).not.toMatch(/router\.replace/)
})
