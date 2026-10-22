/**
 * SIGN OUT (PROFILE) IS ASKED IN A SHEET OVER THE SCREEN — never a panel at the very bottom of a long tab that had
 * to be scrolled to by a hack (`scrollToEnd` after the tap), and never something that could open below the fold.
 *
 * What it says is unchanged and stays in ProfileScreen.tsx (auth/__tests__/signOut.test.ts reads it there): what is
 * removed, what has not reached the estate yet and is deleted with it, that an emergency alert still on the phone
 * blocks it, and — with no connection — that the estate cannot be told now. The button names what it does.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, renderHook } from '@testing-library/react-native'
import { signOutWords } from '../screens/ProfileScreen'
import { useSignOut, type SignOutModel } from '../useProfile'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), notificationAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light' }, NotificationFeedbackType: { Success: 'success' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
const mockReplace = jest.fn()
jest.mock('expo-router', () => ({ router: { replace: (...a: unknown[]) => mockReplace(...a), push: jest.fn() }, usePathname: () => '/profile', useFocusEffect: jest.fn() }))
jest.mock('@gs/core/api/resident', () => ({ residentApi: { useMembersDestroyMutation: () => [jest.fn()], useMembersInviteMutation: () => [jest.fn()], useMembersInviteCancelMutation: () => [jest.fn()] } }))
jest.mock('@/features/auth/useDemo', () => ({ useDemoRefusal: () => null }))
const mockClose = jest.fn(async () => undefined)
jest.mock('@/features/auth/session', () => ({ closeSession: () => mockClose(), refreshFromMe: jest.fn(async () => undefined) }))
const mockDrain = jest.fn(async () => undefined)
jest.mock('@/offline/runtime', () => ({ drainNow: () => mockDrain(), outbox: { all: () => [], dismiss: jest.fn(async () => undefined), reset: jest.fn() } }))
type Op = { id: string; endpoint: string; state: string; label: string }
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: null }, outbox: { ops: [] as Op[] } }
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

jest.setTimeout(30_000)
const settle = async () => { await act(async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); await new Promise((r) => setTimeout(r, 5)) }) }
const model = (over: Partial<SignOutModel>): SignOutModel => ({ asking: true, working: false, blocked: null, unsent: 0, unsentShown: [], unsentMore: 0, online: true, problem: null, ask: jest.fn(), dismiss: jest.fn(), confirm: jest.fn(), ...over })

beforeEach(() => {
  mockClose.mockReset()
  mockClose.mockImplementation(async () => undefined)
  mockDrain.mockClear()
  mockReplace.mockClear()
  mockState.network.online = true
  mockState.outbox.ops = []
})

describe('what the sign-out sheet says', () => {
  it('nothing waiting: "Sign out", and "Stay signed in" to keep the session', () => {
    const words = signOutWords(model({}))
    expect(words.title).toBe('Sign out of this phone?')
    expect(words.confirmLabel).toBe('Sign out')
    expect(words.cancelLabel).toBe('Stay signed in')
    expect(words.lines[0]).toMatch(/^Everything the app keeps on this phone is removed/)
    expect(words.warning).toBeNull()
    expect(words.blocked).toBeNull()
  })

  it('changes still on the phone are counted and NAMED, and the button says they go with it', () => {
    const words = signOutWords(model({ unsent: 2, unsentShown: ['Vehicle · PC 4471', 'Emergency contact · Marcia Ahmed'] }))
    expect(words.confirmLabel).toBe('Sign out and delete 2 changes')
    expect(words.warning?.lead).toMatch(/^2 changes saved on this phone have not reached the estate yet\. Signing out now deletes them for good:$/)
    expect(words.warning?.items).toEqual(['Vehicle · PC 4471', 'Emergency contact · Marcia Ahmed'])
  })

  it('with no connection it says beforehand that the estate cannot be told, never afterwards that it was', () => {
    const words = signOutWords(model({ online: false, unsent: 1, unsentShown: ['Vehicle · PC 4471'] }))
    expect(words.warning?.lead).toContain(': there is no connection')
    expect(words.lines.join(' ')).toContain('the estate cannot be told to end this session now')
    expect(words.lines.join(' ')).toContain('ends by itself within 30 minutes')
  })

  it('an emergency alert not yet sent BLOCKS it: the button is disabled with that reason', () => {
    const blocked = 'An emergency alert from this phone has not reached the estate yet. Signing out would delete it: stay signed in until it has been sent.'
    const words = signOutWords(model({ blocked, unsent: 1 }))
    expect(words.blocked).toBe(blocked)
    expect(words.confirmLabel).toBe('Sign out')
    expect(words.warning).toBeNull()
  })
})

describe('signing out from the Profile tab', () => {
  it('asks first (and gives what is waiting one last chance to go); Stay signed in keeps the session', async () => {
    const { result } = await renderHook(() => useSignOut())
    await act(async () => result.current.ask())
    expect(result.current.asking).toBe(true)
    expect(mockDrain).toHaveBeenCalled()
    await act(async () => result.current.dismiss())
    expect(result.current.asking).toBe(false)
    expect(mockClose).not.toHaveBeenCalled()
  })

  it('confirm signs out ONCE and goes to Log In', async () => {
    const { result } = await renderHook(() => useSignOut())
    await act(async () => result.current.ask())
    await act(async () => {
      result.current.confirm()
      result.current.confirm()
    })
    await settle()
    expect(mockClose).toHaveBeenCalledTimes(1)
    expect(mockReplace).toHaveBeenCalledWith('/(auth)/log-in')
  })

  it('a sign-out that did not finish says so IN the sheet, which stays open', async () => {
    mockClose.mockImplementation(async () => { throw new Error('storage') })
    const { result } = await renderHook(() => useSignOut())
    await act(async () => result.current.ask())
    await act(async () => result.current.confirm())
    await settle()
    expect(result.current.asking).toBe(true)
    expect(result.current.working).toBe(false)
    expect(result.current.problem).toBe('Signing out did not finish, so you are still signed in on this phone. Try again.')
    expect(mockReplace).not.toHaveBeenCalled()
  })

  it('an emergency alert still on the phone blocks it: confirm does nothing', async () => {
    mockState.outbox.ops = [{ id: 'op-1', endpoint: 'duress.store', state: 'pending', label: 'Emergency alert' }]
    const { result } = await renderHook(() => useSignOut())
    await act(async () => result.current.ask())
    expect(result.current.blocked).toMatch(/emergency alert/)
    await act(async () => result.current.confirm())
    await settle()
    expect(mockClose).not.toHaveBeenCalled()
  })
})

describe('the screens', () => {
  const profile = readFileSync(path.join(__dirname, '..', 'screens', 'ProfileScreen.tsx'), 'utf8')
  const settings = readFileSync(path.join(__dirname, '..', '..', 'settings', 'screens', 'SettingsScreen.tsx'), 'utf8')

  it('Profile draws no Sign out (board 17 has none: M42 §5 #28); Settings asks it in a ConfirmSheet (board 18)', () => {
    expect(profile).not.toMatch(/aria-label="Sign out"/)
    expect(profile).not.toContain('scrollToEnd')
    expect(settings).toMatch(/<Confirm[\s\S]*?title="Sign out of this phone\?"/)
  })
})
