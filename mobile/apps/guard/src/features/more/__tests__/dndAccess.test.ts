/**
 * The Do Not Disturb row on More → Notifications. The GUARD APP only.
 *
 * The three things worth failing a build over: the row is drawn from what ANDROID said and never from
 * having asked; the flag really does stop the asking; and the words are the honest ones — a work phone,
 * a duress alarm, and no claim that the app touches anybody's Do Not Disturb itself.
 */

import { readFileSync } from 'node:fs'
import path from 'node:path'
import { DND_NOT_GRANTED, DND_NO_SETTINGS_SCREEN, DND_SETTINGS_ACTION, dndRow } from '../dndAccess'

const screen = readFileSync(path.resolve(__dirname, '..', 'screens', 'NotificationsScreen.tsx'), 'utf8')
const flags = readFileSync(path.resolve(__dirname, '..', '..', '..', '..', '..', '..', 'packages', 'core', 'lib', 'flags.ts'), 'utf8')

describe('the row is drawn from what the phone answered', () => {
  it('off, and the app is still asking: the plain explanation and a way to change it', () => {
    const row = dndRow('off', true)
    expect(row?.control).toBe('allow')
    expect(row?.title).toMatch(/Do Not Disturb/)
    expect(row?.detail).toMatch(/work phone/i)
    expect(row?.detail).toMatch(/duress alarm/)
    expect(row?.detail).toMatch(/panic at your site/)
    expect(row?.detail).toMatch(/alertness check/)
    // It says what the app will NOT do — the answer to the only question a reviewer will ask.
    expect(row?.detail).toMatch(/never changes your Do Not Disturb itself/)
  })

  it('on: it says so, offers no control, and does not overstate what is covered', () => {
    const row = dndRow('on', true)
    expect(row?.control).toBe('on')
    expect(row?.detail).toMatch(/^On\./)
    expect(row?.detail).toMatch(/Everything else from this app stays silenced/)
  })

  it('a phone that would not say draws NO ROW, never a control that leads nowhere', () => {
    expect(dndRow('unknown', true)).toBeNull()
    expect(dndRow('unknown', false)).toBeNull()
  })
})

describe('the flag is the reversible half, and only that half', () => {
  it('off: nothing is offered and nothing sends a guard to the settings screen', () => {
    expect(dndRow('off', false)).toBeNull()
  })

  it('off: a phone that ALREADY granted it is still told the truth, because that is not a claim', () => {
    expect(dndRow('on', false)?.control).toBe('on')
  })

  it('defaults ON, and one environment value turns it off without a new binary', () => {
    expect(flags).toMatch(/askForDndAccess:\s*process\.env\.EXPO_PUBLIC_ASK_DND_ACCESS !== 'off'/)
  })
})

describe('the screen uses these words, and this state, rather than its own', () => {
  it('reads the row from this file and the state from the channel Android has', () => {
    expect(screen).toMatch(/dndRow\(/)
    expect(screen).toMatch(/safetyChannelDnd\('guard'\)/)
  })

  it('opens the phone\'s own screen: it never sets Do Not Disturb', () => {
    expect(DND_SETTINGS_ACTION).toBe('android.settings.NOTIFICATION_POLICY_ACCESS_SETTINGS')
    expect(screen).toMatch(/Linking\.sendIntent\(DND_SETTINGS_ACTION\)/)
    expect(screen).not.toMatch(/setInterruptionFilter|setNotificationPolicy/)
  })

  it('reads both answers again when the guard comes back from the phone\'s settings', () => {
    expect(screen).toMatch(/AppState\.addEventListener\('change'/)
  })

  it('"not changed yet" is said only after the guard was really sent out, and says where to look', () => {
    expect(screen).toMatch(/sentToSettings\.current && bypass === 'off' \? DND_NOT_GRANTED : null/)
    expect(DND_NOT_GRANTED).toMatch(/Do Not Disturb access/)
    expect(DND_NO_SETTINGS_SCREEN).toMatch(/Special app access/)
  })
})
