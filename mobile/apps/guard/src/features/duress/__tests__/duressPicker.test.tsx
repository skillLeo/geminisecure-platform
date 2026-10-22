/**
 * "RAISED BY MISTAKE? CANCEL" — THE REASON PICKER IS BROUGHT INTO VIEW (DESIGN §12.2 row 19).
 *
 * It stays in place (choosing a reason IS the second step, on a ten-second countdown), but it opens under its
 * button, and on a small handset that is below the fold: a guard could press "Cancel" and see nothing happen while
 * the seconds ran out. It is scrolled into view the moment it is laid out.
 *
 * The real DuressScreen, rendered, with its model scripted (useDuress is the whole duress runtime).
 */

import { ScrollView } from 'react-native'
import { fireEvent, render, screen } from '@testing-library/react-native'
import DuressScreen from '../screens/DuressScreen'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Medium: 'medium' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { back: jest.fn(), replace: jest.fn(), canGoBack: () => true }, usePathname: () => '/duress', useFocusEffect: jest.fn() }))
const mockModel = {
  phase: 'active', fire: jest.fn(), steps: [], chip: 'AUDIBLE', sentLine: 'Sent 2:41 PM', headline: null, drill: false, heldLine: '', failure: null, finish: jest.fn(),
  withPosition: true, canCancel: true, cancelSecondsLeft: 8, cancel: jest.fn(), cancelBusy: false, cancelError: null, closedAs: null,
}
jest.mock('../useDuress', () => ({ CANCEL_REASONS: ['Pressed by mistake', 'Testing', 'Something else'], HOLD_MS: 3000, useDuress: () => mockModel }))

jest.setTimeout(30_000)

it('opens the reason picker and scrolls it into view as soon as it is laid out', async () => {
  const scrollToEnd = (ScrollView.prototype as unknown as { scrollToEnd: jest.Mock }).scrollToEnd
  scrollToEnd.mockClear()
  await render(<DuressScreen />)
  expect(screen.queryByLabelText('Why are you cancelling?')).not.toBeOnTheScreen()
  await fireEvent.press(screen.getByRole('button', { name: 'Raised by mistake? Cancel within 8 seconds' }))
  const picker = screen.getByLabelText('Why are you cancelling?')
  expect(scrollToEnd).not.toHaveBeenCalled()
  await fireEvent(picker, 'layout', { nativeEvent: { layout: { x: 0, y: 820, width: 360, height: 300 } } })
  expect(scrollToEnd).toHaveBeenCalledTimes(1)
})
