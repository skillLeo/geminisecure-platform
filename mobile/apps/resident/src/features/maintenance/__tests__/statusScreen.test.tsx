/**
 * resident-app-26 · REQUEST #1042, ITS WORKING HALF (M47 §11a–b) — proved on the real screen, its model mocked:
 *
 *   - "Assigned vendor": the company, its initials and the technician's line; the round button rings the number — and
 *     with no number there is no button;
 *   - "Add a comment": the box takes what is typed and the send button sends it; with nothing written the button does
 *     not send; what became of the message is said under the box, never in a system dialog (U7);
 *   - the apology is gone with the feature it apologised for (§11f): no "Not available yet", no Alert.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { act, fireEvent, render as renderTree, screen } from '@testing-library/react-native'
import { Alert, Linking } from 'react-native'
import { SafeAreaProvider } from 'react-native-safe-area-context'
import type { TicketModel } from '../useTickets'
import MaintenanceStatusScreen from '../screens/MaintenanceStatusScreen'

const METRICS = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 0, left: 0, right: 0, bottom: 0 } }
const render = (ui: React.ReactElement) => renderTree(<SafeAreaProvider initialMetrics={METRICS}>{ui}</SafeAreaProvider>)

jest.mock('react-native-worklets', () => require('react-native-worklets/lib/module/mock'))
jest.mock('react-native-reanimated', () => ({ ...require('react-native-reanimated/mock'), useReducedMotion: () => false }))
jest.mock('expo-router', () => ({
  router: { push: jest.fn(), back: jest.fn(), replace: jest.fn(), canGoBack: () => false },
  usePathname: () => '/maintenance/1042',
  useLocalSearchParams: () => ({ id: '1042' }),
  useFocusEffect: jest.fn(),
}))
let mockModel: TicketModel
jest.mock('../useTickets', () => ({ COMMENT_MAX: 500, useTicket: () => mockModel }))

jest.setTimeout(30_000)

const model = (over: Partial<TicketModel> = {}): TicketModel => ({
  status: 'ready',
  elsewhere: null,
  errorMessage: null,
  heading: 'Request #1042',
  ticket: { id: 1042 } as unknown as TicketModel['ticket'],
  title: 'Gate lighting: Phase 2 visitor parking',
  words: '"The light by the gate is out."',
  // What the hook draws since M98: the consoles' "Under review", dates in the one form.
  meta: 'Submitted 2 Sep 2026 · Electrical',
  steps: [
    { key: 'submitted', title: 'Submitted', state: 'done', line: '2 Sep 2026, 6:40 PM' },
    { key: 'acknowledged', title: 'Under review', state: 'done', line: '3 Sep 2026, 8:15 AM · Property Manager' },
    { key: 'assigned', title: 'Assigned to vendor', state: 'current', line: '3 Sep 2026, 9:00 AM · Property Manager' },
  ],
  vendor: { initials: 'IE', name: 'Island Electric Services', line: 'Tech: Owen Grant · today, 2:00–4:00 PM', phone: '876-555-0191' },
  messages: [],
  comment: { text: '', setText: jest.fn(), send: jest.fn(), state: null },
  fixed: null,
  refreshing: false,
  refresh: jest.fn(),
  ...over,
})

let alert: jest.SpyInstance
let dial: jest.SpyInstance
beforeEach(() => {
  alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined)
  dial = jest.spyOn(Linking, 'openURL').mockResolvedValue(true)
})
afterEach(() => {
  alert.mockRestore()
  dial.mockRestore()
})

describe('"Assigned vendor"', () => {
  it('the company, its initials and the technician’s line, and the round button rings the number', async () => {
    mockModel = model()
    await render(<MaintenanceStatusScreen />)
    expect(screen.getByText('Island Electric Services')).toBeTruthy()
    expect(screen.getByText('IE')).toBeTruthy()
    expect(screen.getByText('Tech: Owen Grant · today, 2:00–4:00 PM')).toBeTruthy()
    // Said, and rung, in the app's one format (M50 §6.11).
    await act(async () => fireEvent.press(screen.getByLabelText('Call Island Electric Services, +1 876 555 0191')))
    expect(dial).toHaveBeenCalledWith('tel:+18765550191')
    expect(alert).not.toHaveBeenCalled()
  })

  it('no number, no button: a control that could do nothing is not drawn', async () => {
    mockModel = model({ vendor: { initials: 'IE', name: 'Island Electric Services', line: 'Tech: Owen Grant · today, 2:00–4:00 PM', phone: null } })
    await render(<MaintenanceStatusScreen />)
    expect(screen.queryByLabelText(/^Call /)).toBeNull()
  })

  it('who in the office did each step, by role', async () => {
    mockModel = model()
    await render(<MaintenanceStatusScreen />)
    expect(screen.getByText('3 Sep 2026, 8:15 AM · Property Manager')).toBeTruthy()
    expect(screen.getByText('Under review')).toBeTruthy()
  })
})

describe('"Add a comment"', () => {
  it('the box takes what is typed, and the send button sends it: no dialog', async () => {
    mockModel = model()
    const first = await render(<MaintenanceStatusScreen />)
    expect(screen.getByText('Message the Property Manager…')).toBeTruthy()
    await act(async () => fireEvent.changeText(screen.getByLabelText('Message the Property Manager…'), 'It is worse at night.'))
    expect(mockModel.comment.setText).toHaveBeenCalledWith('It is worse at night.')
    await act(async () => first.unmount())

    mockModel = model({ comment: { ...mockModel.comment, text: 'It is worse at night.' } })
    await render(<MaintenanceStatusScreen />)
    expect(screen.queryByText('Message the Property Manager…')).toBeNull()
    await act(async () => fireEvent.press(screen.getByLabelText('Send the message')))
    expect(mockModel.comment.send).toHaveBeenCalledTimes(1)
    expect(alert).not.toHaveBeenCalled()
  })

  it('with nothing written the button sends nothing', async () => {
    mockModel = model()
    await render(<MaintenanceStatusScreen />)
    await act(async () => fireEvent.press(screen.getByLabelText('Send the message')))
    expect(mockModel.comment.send).not.toHaveBeenCalled()
    expect(alert).not.toHaveBeenCalled()
  })

  it('what became of it is said under the box: waiting for signal', async () => {
    mockModel = model({ comment: { text: '', setText: jest.fn(), send: jest.fn(), state: { kind: 'waiting', line: 'Saved on this phone: it goes to the estate office when you are back online.' } } })
    await render(<MaintenanceStatusScreen />)
    expect(screen.getByText('Saved on this phone: it goes to the estate office when you are back online.')).toBeTruthy()
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('— or refused, said as an alert', async () => {
    mockModel = model({ comment: { text: '', setText: jest.fn(), send: jest.fn(), state: { kind: 'refused', line: 'This is a demonstration account: nothing you do here is sent.' } } })
    await render(<MaintenanceStatusScreen />)
    expect(screen.getByRole('alert')).toBeTruthy()
    expect(screen.getByText('This is a demonstration account: nothing you do here is sent.')).toBeTruthy()
  })
})

describe('"Messages" (M54 §2.2)', () => {
  it('the thread, both ends: who wrote each message, when, and the words, above the box', async () => {
    mockModel = model({
      messages: [
        { key: '3:a', from: 'Hassam Ahmed', office: false, text: 'It is worse at night.', when: '4 Sep 2026, 11:20 AM' },
        { key: '4:b', from: 'Property Manager', office: true, text: 'The technician comes tomorrow at 9.', when: '4 Sep 2026, 12:05 PM' },
      ],
    })
    await render(<MaintenanceStatusScreen />)
    expect(screen.getByText('Messages')).toBeTruthy()
    expect(screen.getByText('The technician comes tomorrow at 9.')).toBeTruthy()
    expect(screen.getByLabelText('Property Manager, 4 Sep 2026, 12:05 PM: The technician comes tomorrow at 9.')).toBeTruthy()
    expect(screen.getByLabelText('Hassam Ahmed, 4 Sep 2026, 11:20 AM: It is worse at night.')).toBeTruthy()
  })

  it('nobody has written yet: no heading over nothing', async () => {
    mockModel = model()
    await render(<MaintenanceStatusScreen />)
    expect(screen.queryByText('Messages')).toBeNull()
  })
})

describe('the apology is gone with the feature it apologised for (§11f)', () => {
  it('no "Not available yet", and no system dialog, anywhere in the screen or its model', () => {
    for (const file of ['../screens/MaintenanceStatusScreen.tsx', '../useTickets.ts']) {
      const source = readFileSync(path.join(__dirname, file), 'utf8')
      expect(source).not.toContain('Not available yet')
      expect(source).not.toMatch(/Alert\.alert/)
    }
  })
})
