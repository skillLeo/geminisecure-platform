/**
 * THE REBUILT PANIC SCREEN, RENDERED (resident-app-06, M42 §5 #5) — the real PanicScreen with its model scripted
 * (usePanic pulls in the whole app; its words are panicBoard.test.ts's and its raising is untouched).
 *
 *   - the board, as the client drew it, and none of what the client struck from it;
 *   - before the press the circle is the button, and "Can't hold the button? Tap here instead" opens the screen's
 *     own question (a native Alert does nothing on react-native-web) — never blocked, offline included;
 *   - the two controls with no backend are honest: the message stays in the box and the switch stays off;
 *   - the chevron only leaves (nothing is cancelled), and the call button dials in every state.
 */

import { Alert } from 'react-native'
import { fireEvent, render, screen } from '@testing-library/react-native'
import { router } from 'expo-router'
import PanicScreen from '../screens/PanicScreen'

jest.mock('react-native-worklets', () => jest.requireActual('react-native-worklets/src/mock'))
jest.mock('react-native-reanimated', () => jest.requireActual('react-native-reanimated/mock'))
jest.mock('expo-haptics', () => ({ impactAsync: jest.fn(async () => undefined), notificationAsync: jest.fn(async () => undefined), ImpactFeedbackStyle: { Light: 'light', Medium: 'medium' }, NotificationFeedbackType: { Warning: 'warning' } }))
jest.mock('react-native-safe-area-context', () => ({ useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }) }))
jest.mock('expo-router', () => ({ router: { back: jest.fn(), replace: jest.fn(), push: jest.fn(), canGoBack: () => true }, usePathname: () => '/panic', useFocusEffect: jest.fn() }))

const BOARD = {
  title: 'Alert sent',
  meta: 'General emergency · Sent 9:41 AM',
  steps: [
    { title: 'Alert sent to gatehouse & dispatch', detail: '9:41:02 AM', done: true },
    { title: 'Guard Marcus Whyte acknowledged', detail: '14 seconds later', done: true },
    { title: 'On the way: ETA 2 min', detail: 'Live location shared', done: false },
  ],
  bubble: { from: 'Dispatch:', text: 'Stay on the line, Guard Whyte is 2 minutes out.' },
  audioOn: false,
}
const ARM = { title: 'Hold to send an alert', meta: 'Keep holding for 1.5 seconds · release to stop', steps: [], bubble: null, audioOn: false }

const mockFire = jest.fn()
const mockSend = jest.fn(async () => true)
const mockRetry = jest.fn()
const channel = (over: Record<string, unknown> = {}) => ({ items: [], canWrite: true, placeholder: 'Message dispatch…', send: mockSend, retry: mockRetry, problem: null, ...over })
const mockToggleAudio = jest.fn()
const mockClearAudio = jest.fn()
const audio = (over: Record<string, unknown> = {}) => ({ on: false, state: 'off', toggle: mockToggleAudio, problem: null, clearProblem: mockClearAudio, ...over })
const mockModel: Record<string, unknown> = {}
const reset = (over: Record<string, unknown>) => {
  for (const key of Object.keys(mockModel)) delete mockModel[key]
  Object.assign(mockModel, {
    phase: 'active', board: BOARD, fire: mockFire, online: true, contacts: [],
    callEmergency: jest.fn(), textContacts: jest.fn(), callContact: jest.fn(),
    channel: channel(),
    audio: audio(),
    ...over,
  })
}
jest.mock('../usePanic', () => ({ EMERGENCY_NUMBER: '119', HOLD_MS: 1500, usePanic: () => mockModel }))

jest.setTimeout(30_000)

const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined)

beforeEach(() => {
  mockFire.mockClear()
  alert.mockClear()
  ;(router.back as jest.Mock).mockClear()
  reset({})
})

describe('the board, as the client drew it', () => {
  it('draws every slot of resident-app-06', async () => {
    await render(<PanicScreen />)
    for (const words of ['Emergency alert', 'Alert sent', 'General emergency · Sent 9:41 AM', 'Alert sent to gatehouse & dispatch', '9:41:02 AM', 'Guard Marcus Whyte acknowledged', '14 seconds later', 'On the way: ETA 2 min', 'Live location shared', 'Live channel with dispatch', 'Dispatch: Stay on the line, Guard Whyte is 2 minutes out.', 'Message dispatch…', 'Share live audio', 'Call emergency services', 'Only a responder can close this alert']) {
      expect(screen.getByText(words)).toBeOnTheScreen()
    }
    expect(screen.getByRole('button', { name: 'Back: the alert stays active' })).toBeOnTheScreen()
  })

  it('and nothing the client struck from it', async () => {
    await render(<PanicScreen />)
    for (const struck of [/Help requested/, /Call 119 as well/, /Call 119 now/, /closed by the control room/, /Hold to send a new alert/, /Can't hold it/, /^Done$/, /Lot 47/, /alerted/, /What kind of help/, /Sent by mistake/, /How the control room reaches you/, /Text my emergency contacts/]) {
      expect(screen.queryByText(struck)).toBeNull()
    }
    expect(screen.queryByRole('button', { name: /Done|Hold to send|Cancel/ })).toBeNull()
  })

  it('offers no way to send a second alert while one is live', async () => {
    await render(<PanicScreen />)
    expect(screen.queryByRole('button', { name: /Send an alert|Send a new alert|can't hold/i })).toBeNull()
  })
})

describe('before the press: the circle is the button', () => {
  beforeEach(() => reset({ phase: 'arm', board: ARM }))

  it('held, never tapped, and nothing about an alert that does not exist yet', async () => {
    await render(<PanicScreen />)
    const hold = screen.getByRole('button', { name: "Send an alert to your community's security officers" })
    expect(hold.props.accessibilityHint).toBe('Hold for 1.5 seconds to send. It is never sent by a single tap.')
    expect(screen.getByText('Hold to send an alert')).toBeOnTheScreen()
    for (const absent of ['Live channel with dispatch', 'Share live audio', 'Only a responder can close this alert']) expect(screen.queryByText(absent)).toBeNull()
    expect(screen.getByText('Call emergency services')).toBeOnTheScreen()
  })
})

describe("Can't hold the button?", () => {
  const tapInstead = () => screen.getByRole('button', { name: "I can't hold the button. Send with a confirmation instead" })
  beforeEach(() => reset({ phase: 'arm', board: ARM }))

  it('opens the panic screen’s own sheet: it is not a native alert a browser drops', async () => {
    await render(<PanicScreen />)
    expect(screen.queryByText('Send an alert?')).not.toBeOnTheScreen()
    await fireEvent.press(tapInstead())
    expect(screen.getByText('Send an alert?')).toBeOnTheScreen()
    expect(screen.getByText('Your alert goes to your gatehouse and the control room at once.')).toBeOnTheScreen()
    expect(screen.getByRole('button', { name: 'Send the alert' })).toBeEnabled() // never blocked: offline, it is held and sent with the first signal
  })

  it('offline, it does not say "at once": the alert is held on the phone, and it says to call 119 as well: still never blocked', async () => {
    reset({ phase: 'arm', board: ARM, online: false })
    await render(<PanicScreen />)
    await fireEvent.press(tapInstead())
    expect(screen.queryByText('Your alert goes to your gatehouse and the control room at once.')).not.toBeOnTheScreen()
    expect(screen.getByText('There is no connection: the alert is saved on this phone and sent the moment it finds any signal. Call 119 as well.')).toBeOnTheScreen()
    expect(screen.getByRole('button', { name: 'Send the alert' })).toBeEnabled()
  })

  it('"No, go back" sends nothing', async () => {
    await render(<PanicScreen />)
    await fireEvent.press(tapInstead())
    await fireEvent.press(screen.getByRole('button', { name: 'No, go back' }))
    expect(screen.queryByText('Send an alert?')).not.toBeOnTheScreen()
    expect(mockFire).not.toHaveBeenCalled()
  })

  it('"Send the alert" sends it ONCE, and the question goes', async () => {
    await render(<PanicScreen />)
    await fireEvent.press(tapInstead())
    await fireEvent.press(screen.getByRole('button', { name: 'Send the alert' }))
    expect(mockFire).toHaveBeenCalledTimes(1)
    expect(screen.queryByText('Send an alert?')).not.toBeOnTheScreen()
  })

  it('an alert that will never be sent says so, over the way to send a new one', async () => {
    reset({ phase: 'failed', board: { ...ARM, meta: 'The server did not accept this alert. Send a new one, or call 119 now.' } })
    await render(<PanicScreen />)
    expect(screen.getByText('The server did not accept this alert. Send a new one, or call 119 now.')).toBeOnTheScreen()
    expect(screen.getByRole('button', { name: 'Send a new alert' })).toBeOnTheScreen()
    await fireEvent.press(screen.getByRole('button', { name: "I can't hold the button. Send a new alert with a confirmation instead" }))
    await fireEvent.press(screen.getByRole('button', { name: 'Send the alert' }))
    expect(mockFire).toHaveBeenCalledTimes(1)
  })
})

describe('"Message dispatch…": the live channel with the control room (M50 §4.2 #2)', () => {
  beforeEach(() => {
    mockSend.mockClear()
    mockRetry.mockClear()
  })

  it('sends what was written, once, and the box empties when it is taken, never a dialog', async () => {
    await render(<PanicScreen />)
    await fireEvent.changeText(screen.getByLabelText('Message dispatch'), 'There is someone in the garden')
    await fireEvent.press(screen.getByRole('button', { name: 'Send the message' }))
    expect(mockSend).toHaveBeenCalledWith('There is someone in the garden')
    expect(alert).not.toHaveBeenCalled()
    expect(screen.queryByDisplayValue('There is someone in the garden')).toBeNull()
  })

  it('draws the replies and the resident\'s own, each saying where it truly stands', async () => {
    reset({
      channel: channel({
        items: [
          { key: 'm1', from: 'you', name: null, body: 'At the back door', state: 'delivered', note: null, opId: null },
          { key: 'm2', from: 'dispatch', name: 'Kemar', body: 'Stay inside. An officer is coming.', state: null, note: null, opId: null },
          { key: 'q3', from: 'you', name: null, body: 'Hurry', state: 'waiting', note: null, opId: null },
          { key: 'q4', from: 'you', name: null, body: 'Hello?', state: 'failed', note: 'Write a message first.', opId: 'op-4' },
        ],
      }),
    })
    await render(<PanicScreen />)
    expect(screen.getByText('At the back door')).toBeOnTheScreen()
    expect(screen.getByText('Delivered')).toBeOnTheScreen()
    expect(screen.getByText('Kemar (dispatch): Stay inside. An officer is coming.')).toBeOnTheScreen()
    expect(screen.getByText('Waiting for signal: it sends by itself')).toBeOnTheScreen()
    expect(screen.getByText('Not sent: Write a message first.')).toBeOnTheScreen()
    await fireEvent.press(screen.getByRole('button', { name: 'Try sending the message again' }))
    expect(mockRetry).toHaveBeenCalledWith('op-4')
  })

  it('on a closed alert, cannot be written to and says why', async () => {
    reset({ phase: 'closed', channel: channel({ canWrite: false, placeholder: 'This alert is closed' }) })
    await render(<PanicScreen />)
    expect(screen.getByText('This alert is closed')).toBeOnTheScreen()
    await fireEvent.press(screen.getByRole('button', { name: 'Send the message' }))
    expect(mockSend).not.toHaveBeenCalled()
  })
})

describe('"Share live audio": what the phone hears, to the control room (M50 §4.2 #1)', () => {
  beforeEach(() => {
    mockToggleAudio.mockClear()
    mockClearAudio.mockClear()
  })

  it('is drawn as it truly stands, and the switch asks the hook, never a dialog', async () => {
    reset({ audio: audio({ on: true, state: 'on' }) })
    await render(<PanicScreen />)
    const toggle = screen.getByRole('switch', { name: 'Share live audio' })
    expect(toggle).toBeChecked()
    await fireEvent.press(toggle)
    expect(mockToggleAudio).toHaveBeenCalledWith(false)
    expect(alert).not.toHaveBeenCalled()
  })

  it('says in words that the microphone is live while it records, and nothing while it does not (M57 §5.4)', async () => {
    reset({ audio: audio({ on: true, state: 'on' }) })
    await render(<PanicScreen />)
    expect(screen.getByText('Your microphone is on and the control room can listen. Turn the switch off to stop.')).toBeOnTheScreen()

    reset({ audio: audio({ on: true, state: 'starting' }) })
    await render(<PanicScreen />)
    expect(screen.queryByText(/Your microphone is on/)).toBeNull()
  })

  it('with the microphone off, says so in the app\'s own sheet, with the way to the phone\'s Settings', async () => {
    reset({ audio: audio({ problem: { title: 'The microphone is off for GeminiSecure', line: 'Allow it in Settings.', settings: true } }) })
    await render(<PanicScreen />)
    expect(screen.getByText('The microphone is off for GeminiSecure')).toBeOnTheScreen()
    expect(screen.getByRole('button', { name: 'Open Settings' })).toBeOnTheScreen()
    await fireEvent.press(screen.getByRole('button', { name: 'Close' }))
    expect(mockClearAudio).toHaveBeenCalled()
  })
})

describe('always a way out, and the emergency number', () => {
  it('the chevron only leaves: nothing is sent and nothing is cancelled', async () => {
    await render(<PanicScreen />)
    await fireEvent.press(screen.getByRole('button', { name: 'Back: the alert stays active' }))
    expect(router.back).toHaveBeenCalledTimes(1)
    expect(mockFire).not.toHaveBeenCalled()
  })

  it.each(['arm', 'sending', 'queued', 'active', 'failed', 'closed'])('%s: "Call emergency services" dials', async (phase) => {
    const callEmergency = jest.fn()
    reset({ phase, board: phase === 'arm' || phase === 'failed' || phase === 'sending' ? ARM : BOARD, callEmergency })
    await render(<PanicScreen />)
    await fireEvent.press(screen.getByRole('button', { name: 'Call emergency services on 119' }))
    expect(callEmergency).toHaveBeenCalledTimes(1)
  })
})

describe('held on the phone with no signal', () => {
  const held = { title: 'Not sent yet', meta: 'General emergency · Pressed 9:41 AM', steps: BOARD.steps.map((s) => ({ ...s, done: false })), bubble: { from: null, text: 'Your phone has no signal, so your alert is saved on this phone and will be sent the moment any signal returns. The control room does not have it yet. Call 119 now.' }, audioOn: false }

  it('says nobody has it yet, in the phone’s own words, never "Dispatch:"', async () => {
    reset({ phase: 'queued', board: held })
    await render(<PanicScreen />)
    expect(screen.getByText('Not sent yet')).toBeOnTheScreen()
    expect(screen.getByText(held.bubble.text)).toBeOnTheScreen()
    expect(screen.queryByText(/^Dispatch:/)).toBeNull()
  })

  it('offers a text to the household’s contacts from the phone’s own Messages app, only with someone to text', async () => {
    reset({ phase: 'queued', board: held, contacts: [{ name: 'Mark Fletcher', phone: '8765550142' }, { name: 'Ann Lee', phone: '8765550199' }] })
    await render(<PanicScreen />)
    await fireEvent.press(screen.getByRole('button', { name: 'Text my emergency contacts: choose who' }))
    expect(screen.getByText('Text your emergency contacts')).toBeOnTheScreen()
  })

  it('and not without one', async () => {
    reset({ phase: 'queued', board: held, contacts: [] })
    await render(<PanicScreen />)
    expect(screen.queryByRole('button', { name: 'Text my emergency contacts: choose who' })).toBeNull()
  })
})
