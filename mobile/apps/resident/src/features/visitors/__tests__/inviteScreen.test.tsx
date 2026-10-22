/**
 * resident-app-07 · Invite visitor, rebuilt (M42 §5 #6) — proved on the real screen and the real hook, the server mocked.
 *
 *   - the board's words are there, and none of the old screen's: no blurb under the switch, no hint under a field, no
 *     tick box, no "Jamaica time", no summary block, no long WhatsApp paragraph;
 *   - the two added controls and nothing more: How they arrive (Walk in · Drive in · Taxi), and the plate for Drive in only;
 *   - the purpose is chosen from the list (visitPurposes.ts), in its order;
 *   - the footer names the visitor typed, as "they";
 *   - Generate QR pass sends what the server takes (a plate, or no_vehicle) and opens the pass;
 *   - every tab is the same form.
 */

import { act, fireEvent, render as renderTree, screen, within } from '@testing-library/react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import { VISIT_PURPOSES } from '../visitPurposes'
import InviteScreen from '../screens/InviteScreen'

// The client's phone, 390 × 844, with an iPhone's insets.
const METRICS = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 47, left: 0, right: 0, bottom: 34 } }
const render = () => renderTree(<SafeAreaProvider initialMetrics={METRICS}><InviteScreen /></SafeAreaProvider>)

// The kit's skeletons animate; in Jest the animation library runs on its own published mocks.
jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
const mockReplace = jest.fn()
jest.mock('expo-router', () => ({ router: { replace: (to: string) => mockReplace(to), back: jest.fn(), canGoBack: () => true }, usePathname: () => '/visitors/invite', useFocusEffect: jest.fn() }))
jest.mock('expo-haptics', () => ({ notificationAsync: jest.fn(async () => undefined), NotificationFeedbackType: { Success: 'success' } }))
const mockIssue = jest.fn(async (_body: Record<string, unknown>) => ({ data: { id: 77 } }) as Record<string, unknown>)
jest.mock('@gs/core/api/resident', () => ({ residentApi: { useVisitorPassesStoreMutation: () => [mockIssue] } }))
jest.mock('@/offline/runtime', () => ({ submit: jest.fn(async () => undefined) }))
const mockState = { network: { online: true }, session: { status: 'active', demo: false, principal: { id: 1, name: 'Resident A', siteName: 'Phoenix Park Village', reference: null } } }
jest.mock('@/store', () => ({ useAppSelector: (select: (s: unknown) => unknown) => select(mockState) }))

// 8:00 AM on Thursday 24 September in Kingston: the form's default window, now for two hours, is still ahead.
beforeEach(() => {
  jest.useFakeTimers({ now: new Date('2026-09-24T13:00:00Z') })
  mockIssue.mockClear()
  mockReplace.mockClear()
})
afterEach(() => jest.useRealTimers())

const press = async (element: Parameters<typeof fireEvent.press>[0]) => {
  await act(async () => {
    fireEvent.press(element)
    // The issue is asked and answered (mocked) before the screen is read again.
    await Promise.resolve()
    await Promise.resolve()
  })
}
const nameOf = (node: { props: Record<string, unknown> }): unknown => node.props['aria-label'] ?? node.props.accessibilityLabel
/** A window as the time box draws it: a no-break space before each meridiem, so a line breaks only after the dash. */
/** The window as its box draws it: one line, as written (M47 §7.3 — no hard space needed to keep "AM" whole). */
const drawn = (window: string): string => window
const type = async (label: string, text: string) => {
  await act(async () => {
    fireEvent.changeText(screen.getByLabelText(label), text)
  })
}
/** Opens a dropdown by its field and picks one of its choices. */
const choose = async (field: RegExp, choice: string) => {
  await press(screen.getByLabelText(field))
  await press(screen.getByRole('radio', { name: choice }))
}

it('draws the board’s words, and none of the old screen’s', async () => {
  await render()
  for (const words of ['Invite visitor', 'Single visit', 'Recurring', 'Contractor', 'Delivery', 'Visitor name', 'Phone number', 'Purpose of visit', 'Arrival window', 'Generate QR pass']) {
    expect(screen.getByText(words)).toBeTruthy()
  }
  // The one control M42 adds before a choice is made; the plate waits for Drive in.
  expect(screen.getByText('How they arrive')).toBeTruthy()
  expect(screen.queryByText('Vehicle plate')).toBeNull()
  expect(screen.getByText('Your visitor gets this pass when you send it on the next screen: they just show it at the gate, no phone call needed.')).toBeTruthy()

  for (const gone of [
    'One entry, inside the window you choose.',
    'The guard can call them at the gate.',
    'The guard checks it against the car at the gate.',
    /Arriving on foot or by taxi/,
    /Jamaica time/,
    /there now/,
    /Next you can send the pass/,
    /Valid (from|until)/,
  ]) {
    expect(screen.queryByText(gone)).toBeNull()
  }
  expect(screen.queryByRole('checkbox')).toBeNull()
  // The Arrival window: the day in the one form (M98), and the window opening now.
  expect(screen.getByText('24 Sep 2026')).toBeTruthy()
  expect(screen.getByText(drawn('8:00 AM – 10:00 AM'))).toBeTruthy()
})

/** The open dropdown's own rows — not the switch's four, which are radios too. */
const choices = () => within(screen.getByTestId('chooser-list')).getAllByRole('radio').map(nameOf)

it('offers Walk in · Drive in · Ride in, and asks for the plate for Drive in only', async () => {
  await render()
  await press(screen.getByLabelText(/^How they arrive:/))
  // The field's own name, and the sheet's title.
  expect(screen.getAllByText('How they arrive')).toHaveLength(2)
  expect(choices()).toEqual(['Walk in', 'Drive in', 'Ride in'])
  await press(screen.getByRole('radio', { name: 'Drive in' }))
  expect(screen.getByText('Vehicle plate')).toBeTruthy()

  await choose(/^How they arrive:/, 'Ride in')
  expect(screen.queryByText('Vehicle plate')).toBeNull()
  await choose(/^How they arrive:/, 'Walk in')
  expect(screen.queryByText('Vehicle plate')).toBeNull()
})

it('chooses the purpose from the list, in its order', async () => {
  await render()
  await press(screen.getByLabelText(/^Purpose of visit:/))
  expect(choices()).toEqual([...VISIT_PURPOSES])
  await press(screen.getByRole('radio', { name: 'Domestic helper' }))
  expect(screen.getByLabelText('Purpose of visit: Domestic helper')).toBeTruthy()
  expect(screen.queryByTestId('chooser-list')).toBeNull()
})

it('names the visitor typed in the footer, as "they"', async () => {
  await render()
  await type('Visitor name', 'Kemar Brown')
  expect(screen.getByText('Kemar gets this pass when you send it on the next screen: they just show it at the gate, no phone call needed.')).toBeTruthy()
})

it('says what is missing, and sends nothing', async () => {
  await render()
  await press(screen.getByRole('button', { name: 'Generate QR pass' }))
  expect(screen.getByText("Enter the visitor's phone number. Example: 876 555 0123.")).toBeTruthy()
  expect(screen.getByText('Choose what the visit is for.')).toBeTruthy()
  expect(screen.getByText('Choose how they arrive.')).toBeTruthy()
  // The struck hint does not come back as an error either.
  expect(screen.queryByText(/call them at the gate/)).toBeNull()
  expect(mockIssue).not.toHaveBeenCalled()
})

describe('Generate QR pass', () => {
  const fillIn = async () => {
    await type('Visitor name', 'Kemar Brown')
    await type('Phone number', '876 555 0123')
    await choose(/^Purpose of visit:/, 'Family visit')
  }

  it('sends a walk-in as "no vehicle", and opens the pass', async () => {
    await render()
    await fillIn()
    await choose(/^How they arrive:/, 'Walk in')
    await press(screen.getByRole('button', { name: 'Generate QR pass' }))
    expect(mockIssue).toHaveBeenCalledTimes(1)
    const body = mockIssue.mock.calls[0]![0]
    expect(body).toMatchObject({ category: 'single', visitor_name: 'Kemar Brown', purpose: 'Family visit', no_vehicle: true })
    expect(body).not.toHaveProperty('vehicle_plate')
    expect(mockReplace).toHaveBeenCalledWith('/visitors/pass/77')
  })

  it('sends a car’s plate for Drive in, and refuses Drive in with none', async () => {
    await render()
    await fillIn()
    await choose(/^How they arrive:/, 'Drive in')
    await press(screen.getByRole('button', { name: 'Generate QR pass' }))
    expect(screen.getByText('Enter the plate, or choose Walk in or Ride in.')).toBeTruthy()
    expect(mockIssue).not.toHaveBeenCalled()

    await type('Vehicle plate', 'pc 8821')
    await press(screen.getByRole('button', { name: 'Generate QR pass' }))
    const body = mockIssue.mock.calls[0]![0]
    expect(body).toMatchObject({ vehicle_plate: 'PC 8821' })
    expect(body).not.toHaveProperty('no_vehicle')
  })
})

it('draws every tab as the same lean form: a recurring pass reads its days and hours in the same two boxes', async () => {
  await render()
  for (const tab of ['Recurring', 'Contractor', 'Delivery', 'Single visit']) {
    await press(screen.getByRole('radio', { name: tab }))
    for (const words of ['Visitor name', 'Phone number', 'Purpose of visit', 'How they arrive', 'Arrival window', 'Generate QR pass']) expect(screen.getByText(words)).toBeTruthy()
    expect(screen.queryByText(/Valid (from|until)/)).toBeNull()
    // A recurring or contractor pass runs a week, 6:00 AM to 6:00 PM — and 6:00 AM today has passed at 8:00, so "Now".
    expect(screen.getByText(tab === 'Recurring' || tab === 'Contractor' ? '24 Sep – 1 Oct 2026' : '24 Sep 2026')).toBeTruthy()
    expect(screen.getByText(drawn('8:00 AM – 6:00 PM'))).toBeTruthy()
  }
})

it('chooses a time in two steps, From then Until, and reads it as the board does', async () => {
  await render()
  await press(screen.getByText(drawn('8:00 AM – 10:00 AM')))
  await press(screen.getByRole('radio', { name: '2:00 PM' }))
  // The end moved with the start (two hours on); Until is offered next.
  expect(screen.getByRole('radio', { name: '4:00 PM', checked: true })).toBeTruthy()
  await press(screen.getByRole('radio', { name: '4:00 PM' }))
  expect(screen.getByText(drawn('2:00 PM – 4:00 PM'))).toBeTruthy()
  expect(screen.queryByTestId('chooser-list')).toBeNull()

  // Across noon both ends keep their meridiem — and may only break after the dash.
  await press(screen.getByText(drawn('2:00 PM – 4:00 PM')))
  await press(screen.getByRole('radio', { name: '11:30 AM' }))
  await press(screen.getByRole('radio', { name: '1:30 PM' }))
  expect(screen.getByText(drawn('11:30 AM – 1:30 PM'))).toBeTruthy()
})
