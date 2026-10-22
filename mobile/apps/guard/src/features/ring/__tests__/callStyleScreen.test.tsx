/**
 * More → Notifications → Call-style alerts (GUARD_RING_DESIGN §9.1): the rows, their fixes, the re-read on the way back
 * from Settings, and the test ring. The UI kit is replaced by plain React Native parts; the logic is the real one.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { AppState } from 'react-native'
import { act, fireEvent, render, waitFor } from '@testing-library/react-native'

jest.setTimeout(30_000)

jest.mock('@gs/core', () => require('./kitMock'))

jest.mock('../native', () => {
  type MockRing = { readinessValue: Record<string, unknown>; [mockKey: string]: unknown }
  const ring: MockRing = {
    readinessValue: {} as Record<string, unknown>,
    readiness: jest.fn(async () => ({ ...ring.readinessValue })),
    openSetting: jest.fn(async () => true),
  }
  return { GuardRing: ring, availability: () => 'android' }
})
jest.mock('@gs/core/notify', () => ({ nudgePushRegistration: jest.fn() }))
jest.mock('@gs/core/lib', () => ({ flags: { askForDndAccess: true } }))
jest.mock('@gs/core/api', () => ({ isApiError: (e: { code?: unknown } | null) => !!e && typeof e.code === 'string' }))
jest.mock('@gs/core/api/guard', () => ({ guardApi: { endpoints: { ringTest: { initiate: () => ({ type: 'ring/test' }) } } } }))
jest.mock('@/store', () => ({ store: { dispatch: jest.fn() } }))

import { store } from '@/store'
import CallStyleScreen from '../screens/CallStyleScreen'
import { NO_SETTINGS_SCREEN, TEST_SENT, testSentence } from '../useCallStyle'

const ring = (jest.requireMock('../native') as { GuardRing: { readinessValue: Record<string, unknown>; readiness: jest.Mock; openSetting: jest.Mock } }).GuardRing

const OFF = {
  v: 1, configured: true, sdk: 35, maker: 'samsung', notifications: 'off', ringChannel: 'silent', fullScreen: 'off', dndAccess: false, channelBypass: false,
  battery: 'optimised', background: 'restricted', alarmVolume: 0, alarmVolumeMax: 7, alarmTone: 'none', screenLock: 'off', biometric: false, interruptionFilter: 1, soundDetail: '',
}

let appListeners: ((state: string) => void)[] = []
const settle = () => act(async () => { await new Promise((r) => setTimeout(r, 0)) })

beforeEach(() => {
  jest.clearAllMocks()
  ring.readinessValue = { ...OFF }
  appListeners = []
  jest.spyOn(AppState, 'addEventListener').mockImplementation(((_: string, l: (state: string) => void) => { appListeners.push(l); return { remove: () => undefined } }) as never)
})

async function screen() {
  const view = await render(<CallStyleScreen />)
  // The rows come from the phone's answer, which arrives a tick later.
  await waitFor(() => expect(view.getByText('Screen lock')).toBeTruthy(), { timeout: 10_000 })
  return view
}

describe('the Call-style alerts screen', () => {
  it('draws every row with the phone\'s own answer, and the summary', async () => {
    const view = await screen()
    for (const title of ['Notifications', 'Call-style alerts channel', 'Full-screen alerts', 'Rings through Do Not Disturb', 'Battery optimisation', 'Background activity', 'Alarm volume', 'Alarm tone', 'Screen lock']) {
      expect(view.getByText(title)).toBeTruthy()
    }
    expect(view.getByText('Not ready to ring like a call')).toBeTruthy()
    // The manufacturer's own battery manager cannot be read: advice, not a row.
    expect(view.getByText(/Never sleeping apps/)).toBeTruthy()
  })

  it('each fix opens its own settings screen', async () => {
    const view = await screen()
    const expected = ['notifications', 'ring_channel', 'full_screen', 'dnd', 'battery', 'background', 'alarm_volume', 'alarm_tone', 'screen_lock']
    const buttons = view.getAllByLabelText(/^(Settings|Allow|Adjust): /)
    expect(buttons).toHaveLength(expected.length)
    for (const b of buttons) await act(async () => { fireEvent.press(b) })
    expect(ring.openSetting.mock.calls.map((c) => c[0])).toEqual(expected)
  })

  it('says so when the phone would not open a settings screen', async () => {
    ring.openSetting.mockResolvedValueOnce(false)
    const view = await screen()
    await act(async () => { fireEvent.press(view.getAllByLabelText(/^(Settings|Allow|Adjust): /)[0]!) })
    expect(view.getByText(NO_SETTINGS_SCREEN)).toBeTruthy()
  })

  it('reads the phone again on every return from Settings', async () => {
    const view = await screen()
    const reads = ring.readiness.mock.calls.length
    ring.readinessValue = { ...OFF, notifications: 'on' }
    await act(async () => { for (const l of appListeners) l('active') })
    await settle()
    expect(ring.readiness.mock.calls.length).toBeGreaterThan(reads)
    expect(view.queryByLabelText('Settings: Notifications')).toBeNull()
  })

  it('"Send a test ring" asks the server for a real one, and says what came back', async () => {
    ;(store.dispatch as jest.Mock).mockResolvedValueOnce({ data: { sent: true, ring_state: 'partial' } })
    const view = await screen()
    await act(async () => { fireEvent.press(view.getByText('Send a test ring')) })
    await settle()
    expect(store.dispatch).toHaveBeenCalledWith({ type: 'ring/test' })
    expect(view.getByText(`${TEST_SENT} The office's console has this phone as partly ready.`)).toBeTruthy()
  })

  it('shows the server\'s own sentence when a test ring cannot be sent', async () => {
    ;(store.dispatch as jest.Mock).mockResolvedValueOnce({ error: { status: 409, code: 'no_push_address', message: 'This phone has no push address yet: allow notifications, then try again.' } })
    const view = await screen()
    await act(async () => { fireEvent.press(view.getByText('Send a test ring')) })
    await settle()
    expect(view.getByText('This phone has no push address yet: allow notifications, then try again.')).toBeTruthy()
    expect(testSentence({ ok: false, message: 'This phone has not reported call-style alerts: update the Guard App.' })).toBe('This phone has not reported call-style alerts: update the Guard App.')
  })
})

describe('the way in', () => {
  it('More → Notifications has one row that opens it', () => {
    const source = readFileSync(path.resolve(__dirname, '..', '..', 'more', 'screens', 'NotificationsScreen.tsx'), 'utf8')
    expect(source).toMatch(/title="Call-style alerts"/)
    expect(source).toMatch(/router\.push\('\/call-style'/)
    expect(readFileSync(path.resolve(__dirname, '..', '..', '..', '..', 'app', 'call-style.tsx'), 'utf8')).toMatch(/CallStyleScreen/)
  })
})
