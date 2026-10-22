/**
 * J-GUARD-1 — SIGNING OUT OF A GUARD HANDSET, AND REMOVING THE APP FROM IT, ARE ASKED IN A SHEET.
 *
 * More drew its "Sign out of this phone?" panel in place of the bottom row with no scroll into view, so on a small
 * handset it opened below the fold; Close account swapped its button for two more in the card. Both now open a
 * ConfirmSheet in the Guard App's tone. Cancel keeps the session; the confirm signs out ONCE; a sign-out that did
 * not finish says so inside the sheet. The words stay in MoreScreen.tsx (signOut.test.ts reads them there).
 *
 * The real screens and the real useSignOut, with the network, the queue and the store scripted.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, fireEvent, render, screen } from '@testing-library/react-native'
import MoreScreen from '../screens/MoreScreen'
import CloseAccountScreen from '../screens/CloseAccountScreen'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), notificationAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' }, NotificationFeedbackType: { Warning: 'warning' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-application', () => ({ nativeApplicationVersion: '1.4.0' }))
const mockReplace = jest.fn()
jest.mock('expo-router', () => ({ router: { replace: (...a: unknown[]) => mockReplace(...a), push: jest.fn(), back: jest.fn(), canGoBack: () => true }, usePathname: () => '/more', useFocusEffect: jest.fn() }))
jest.mock('@gs/core/api/guard', () => ({ guardApi: { endpoints: { authSignOut: { initiate: jest.fn() } } } }))
const mockEndSession = jest.fn(async (_call: unknown) => undefined)
const mockClearEverything = jest.fn(async (..._a: unknown[]) => undefined)
jest.mock('@gs/core/auth', () => ({ clearEverything: (...a: unknown[]) => mockClearEverything(...a), endServerSession: (call: unknown) => mockEndSession(call), signedOut: () => ({ type: 'signedOut' }) }))
jest.mock('@gs/core/notify', () => ({ clearNotifications: jest.fn(async () => undefined) }))
jest.mock('@/features/notify/pushRegistration', () => ({ pushRegistration: { signOut: jest.fn(async () => undefined) } }))
jest.mock('@/features/duress/useDuress', () => ({ isLive: () => false, useActiveDuress: () => null }))
const mockDrain = jest.fn(async () => undefined)
jest.mock('@/offline/runtime', () => ({ drainNow: () => mockDrain(), forgetQueue: jest.fn() }))
type Op = { id: string; state: string }
const mockState = { network: { online: true }, session: { status: 'active', principal: { name: 'Marcus Reid', reference: 'GS-9001', siteName: 'Phoenix Park Village' } }, outbox: { ops: [] as Op[] } }
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)
const settle = async () => { await act(async () => { for (let i = 0; i < 10; i++) await Promise.resolve() }) }

beforeEach(() => {
  mockReplace.mockClear()
  mockEndSession.mockClear()
  mockClearEverything.mockReset()
  mockClearEverything.mockImplementation(async () => undefined)
  mockDrain.mockClear()
  mockState.network.online = true
  mockState.outbox.ops = []
})

describe('More → Sign out', () => {
  const openSheet = async () => {
    await render(<MoreScreen />)
    await fireEvent.press(screen.getByRole('button', { name: 'Sign out' }))
    expect(screen.getByText('Sign out of this phone?')).toBeOnTheScreen()
  }

  it('asks in a sheet over the screen; "Stay signed in" keeps the session', async () => {
    await openSheet()
    expect(screen.getByLabelText('Sign out of this phone?')).toHaveProp('role', 'alertdialog')
    await fireEvent.press(screen.getByRole('button', { name: 'Stay signed in' }))
    await settle()
    expect(screen.queryByText('Sign out of this phone?')).not.toBeOnTheScreen()
    expect(mockEndSession).not.toHaveBeenCalled()
    expect(mockClearEverything).not.toHaveBeenCalled()
  })

  it('confirm signs out ONCE and goes to enrolment', async () => {
    let finish: () => void = () => undefined
    mockClearEverything.mockImplementation(() => new Promise<undefined>((resolve) => { finish = () => resolve(undefined) }))
    await openSheet()
    // The sheet's own confirm — the row behind it is also called "Sign out".
    const confirm = () => screen.getAllByRole('button', { name: 'Sign out' }).at(-1)!
    await fireEvent.press(confirm())
    await fireEvent.press(confirm())
    await act(async () => finish())
    await settle()
    expect(mockEndSession).toHaveBeenCalledTimes(1)
    expect(mockClearEverything).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/enrol')
  })

  it('a sign-out that did not finish says so IN the sheet, which stays open', async () => {
    mockClearEverything.mockImplementation(async () => { throw new Error('storage') })
    await openSheet()
    await fireEvent.press(screen.getAllByRole('button', { name: 'Sign out' }).at(-1)!)
    await settle()
    expect(screen.getByText('Sign out of this phone?')).toBeOnTheScreen()
    expect(screen.getByRole('alert')).toHaveTextContent('Signing out did not finish, so you are still signed in on this phone. Try again.')
    expect(mockReplace).not.toHaveBeenCalled()
  })

  it('records still on the phone are counted in the sheet, and the button says "Sign out anyway"', async () => {
    mockState.outbox.ops = [{ id: 'a', state: 'pending' }, { id: 'b', state: 'syncing' }]
    await openSheet()
    expect(screen.getByText(/^2 records have not reached the server\./)).toBeOnTheScreen()
    expect(screen.getByRole('button', { name: 'Sign out anyway' })).toBeEnabled()
  })

  it('with no signal it says beforehand that the server cannot be told', async () => {
    mockState.network.online = false
    await openSheet()
    expect(screen.getByText(/the server cannot be told to end this session now/)).toBeOnTheScreen()
  })
})

describe('Delete my account → Remove this app from this phone', () => {
  const openSheet = async () => {
    await render(<CloseAccountScreen />)
    await fireEvent.press(screen.getByRole('button', { name: 'Remove this app from this phone' }))
    expect(screen.getByText('Remove this app from this phone?')).toBeOnTheScreen()
  }

  it('asks in a sheet; "Keep it on this phone" keeps it', async () => {
    await openSheet()
    await fireEvent.press(screen.getByRole('button', { name: 'Keep it on this phone' }))
    await settle()
    expect(screen.queryByText('Remove this app from this phone?')).not.toBeOnTheScreen()
    expect(mockEndSession).not.toHaveBeenCalled()
  })

  it('confirm removes it ONCE', async () => {
    await openSheet()
    await fireEvent.press(screen.getByRole('button', { name: 'Remove it from this phone' }))
    await settle()
    expect(mockEndSession).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/enrol')
  })

  it('with records waiting, the button names what goes with it', async () => {
    mockState.outbox.ops = [{ id: 'a', state: 'pending' }]
    await openSheet()
    expect(screen.getByRole('button', { name: 'Remove, and delete 1 unsent' })).toBeEnabled()
  })
})

describe('the words stay where signOut.test.ts reads them', () => {
  it('MoreScreen.tsx still says them, and no longer draws the inline panel', () => {
    const more = readFileSync(path.join(__dirname, '..', 'screens', 'MoreScreen.tsx'), 'utf8')
    expect(more).toContain('<ConfirmSheet')
    expect(more).toContain('tone="guard"')
    expect(more).not.toContain('s.confirmActions')
  })
})
