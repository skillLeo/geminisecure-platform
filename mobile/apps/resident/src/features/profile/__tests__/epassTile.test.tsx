/**
 * M47 §6d — A MEMBER REACHES THEIR OWN E-PASS FROM PROFILE.
 *
 * Home offers it to Dependents and Helpers alone, and it is not a visitor, so it left the Visitors tab (§5.3). The
 * tile on Profile's hero opens it — Home's own quick-action tile, inside the hero's height — for every account with a
 * home on the register, and for none without one.
 */

import { act, fireEvent, render, screen } from '@testing-library/react-native'
import { router } from 'expo-router'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { push: jest.fn(), back: jest.fn(), canGoBack: () => false }, usePathname: () => '/profile', useFocusEffect: jest.fn() }))
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: null }, outbox: { ops: [] as unknown[] } }
jest.mock('@/store', () => ({ useAppDispatch: () => jest.fn(), useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))
let mockModel: Record<string, unknown>
jest.mock('../useProfile', () => ({ useProfile: () => mockModel }))

import ProfileScreen from '../screens/ProfileScreen'

/** What the hero reads, and nothing more: a Helper's household details are kept by someone else, so none is drawn. */
const model = (over: Record<string, unknown> = {}) => ({
  fixture: null,
  hero: { name: 'Hassam Ahmed', initials: 'HA', place: 'Phase 2 · Lot 47', role: 'Member' },
  photo: { canChange: false, uri: null, hasPhoto: false, blocked: null, sheet: { open: false, ask: jest.fn(), take: jest.fn(), choose: jest.fn(), remove: jest.fn(), cancel: jest.fn() } },
  details: { status: 'loading' },
  denied: false,
  deniedMessage: 'Your account is not linked to a home on the estate’s register yet.',
  keptBy: 'Andrea Fletcher',
  refreshing: false,
  refresh: jest.fn(),
  ...over,
})

it('the hero carries "My e-Pass", and it opens the resident’s own pass', async () => {
  mockModel = model()
  await render(<ProfileScreen />)
  expect(screen.getByText('My e-Pass')).toBeTruthy()
  await act(async () => {
    fireEvent.press(screen.getByRole('button', { name: 'Show my own e-Pass' }))
  })
  expect(router.push).toHaveBeenLastCalledWith('/visitors/pass/e-pass')
})

it('is not offered to an account with no home on the register', async () => {
  mockModel = model({ denied: true })
  await render(<ProfileScreen />)
  expect(screen.queryByRole('button', { name: 'Show my own e-Pass' })).toBeNull()
})
