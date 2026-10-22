/**
 * Visitor at the gate (board 25): the phone never claims a photo of the visitor was taken, because none exists (the user,
 * 9 Oct 2026: "Remove the false 'Photo captured by guard at Main Gate' caption"). It says where the visitor is waiting.
 */
import { render, screen } from '@testing-library/react-native'
import type { ApprovalModel } from '../useApproval'
import ApprovalScreen from '../screens/ApprovalScreen'

const mockModel: ApprovalModel = {
  status: 'waiting',
  unit: 'Lot 47',
  visitor: 'Marcia James',
  purpose: 'Family visit',
  vehicle: null,
  guard: 'Devon Clarke',
  post: 'Main Gate',
  secondsLeft: 150,
  fraction: 150 / 180,
  clock: '2:30',
  estate: 'Phoenix Park Village 1',
  online: true,
  error: null,
  held: false,
  answer: jest.fn(),
}

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { back: jest.fn(), replace: jest.fn(), canGoBack: () => true }, useLocalSearchParams: () => ({ id: '7' }) }))
jest.mock('../useApproval', () => ({ HELD_LINE: 'held', useApproval: () => mockModel }))

it('says where the visitor is waiting, and never that a photo was captured', async () => {
  await render(<ApprovalScreen />)
  expect(screen.getByText('Waiting at Main Gate')).toBeTruthy()
  expect(screen.queryByText(/Photo captured/)).toBeNull()
  expect(screen.getByText('Says their name is Marcia James')).toBeTruthy()
})
