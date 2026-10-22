/**
 * "Call-style alerts" — what the phone says it can do, row by row, and the report the server decides from
 * (GUARD_RING_DESIGN §9.1, §6.6). Pure: the native readiness is handed in.
 */

import type { NativeReadiness } from '../native'
import { BATTERY_SENTENCE, NOTES, makerAdvice, rowsOf, serverReport, stateOf, summaryOf, volumeBucket } from '../readiness'

const READY: NativeReadiness = {
  v: 1, configured: true, sdk: 35, maker: 'google', notifications: 'on', ringChannel: 'on', fullScreen: 'on', dndAccess: true,
  channelBypass: true, battery: 'exempt', background: 'allowed', alarmVolume: 5, alarmVolumeMax: 7, alarmTone: 'set',
  screenLock: 'on', biometric: true, interruptionFilter: 1, soundDetail: 'imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=0',
}

const row = (n: NativeReadiness, key: string) => rowsOf(n, { askForDnd: true }).find((r) => r.key === key)!

describe('every row is ON, OFF or unknown, with its fix', () => {
  it('a phone with everything on has every row on and no fix to offer', () => {
    const rows = rowsOf(READY, { askForDnd: true })
    expect(rows.map((r) => r.key)).toEqual(['notifications', 'ring_channel', 'full_screen', 'dnd', 'battery', 'background', 'alarm_volume', 'alarm_tone', 'screen_lock'])
    for (const r of rows) {
      expect(`${r.key}: ${r.state}`).toBe(`${r.key}: on`)
      expect(r.fix).toBeNull()
    }
  })

  it('each row that is off names the phone setting that fixes it', () => {
    const off: NativeReadiness = { ...READY, notifications: 'off', ringChannel: 'silent', fullScreen: 'off', dndAccess: false, channelBypass: false, battery: 'optimised', background: 'restricted', alarmVolume: 0, alarmTone: 'none', screenLock: 'off' }
    const fixes = Object.fromEntries(rowsOf(off, { askForDnd: true }).map((r) => [r.key, [r.state, r.fix]]))
    expect(fixes).toEqual({
      notifications: ['off', 'notifications'],
      ring_channel: ['off', 'ring_channel'],
      full_screen: ['off', 'full_screen'],
      dnd: ['off', 'dnd'],
      battery: ['off', 'battery'],
      background: ['off', 'background'],
      alarm_volume: ['off', 'alarm_volume'],
      alarm_tone: ['off', 'alarm_tone'],
      screen_lock: ['off', 'screen_lock'],
    })
  })

  it('what the phone cannot say is unknown, never on, and offers nothing it cannot do', () => {
    const doubtful: NativeReadiness = { ...READY, battery: 'unknown', background: 'unknown', alarmVolume: -1, alarmVolumeMax: -1 }
    expect(row(doubtful, 'battery').state).toBe('unknown')
    expect(row(doubtful, 'background').state).toBe('unknown')
    expect(row(doubtful, 'background').fix).toBeNull()
    expect(row(doubtful, 'alarm_volume').state).toBe('unknown')
  })

  it('full-screen alerts are not needed before Android 14, and say so', () => {
    const old: NativeReadiness = { ...READY, sdk: 30, fullScreen: 'not_needed' }
    expect(row(old, 'full_screen').state).toBe('on')
    expect(row(old, 'full_screen').detail).toMatch(/Android 14/)
  })

  it('Do Not Disturb: the fix is the access screen, or the channel when access is granted but the channel has not got it', () => {
    expect(row({ ...READY, dndAccess: false, channelBypass: false }, 'dnd').fix).toBe('dnd')
    expect(row({ ...READY, dndAccess: true, channelBypass: false }, 'dnd').fix).toBe('ring_channel')
    // The flag that governs ASKING (flags.askForDndAccess) turned off: the row says off, and offers no way there.
    expect(rowsOf({ ...READY, dndAccess: false, channelBypass: false }, { askForDnd: false }).find((r) => r.key === 'dnd')!.fix).toBeNull()
  })

  it('a low alarm volume is low, not off', () => {
    expect(row({ ...READY, alarmVolume: 1, alarmVolumeMax: 7 }, 'alarm_volume').state).toBe('low')
    expect(volumeBucket(0, 7)).toBe('off')
    expect(volumeBucket(2, 7)).toBe('low')
    expect(volumeBucket(3, 7)).toBe('ok')
    expect(volumeBucket(-1, -1)).toBe('unknown')
  })

  it('the battery sentence is exactly the plain one', () => {
    expect(BATTERY_SENTENCE).toBe('Battery saving can hold a panic alert back for minutes while this phone is idle. Allow GeminiSecure Guard to run unrestricted: it is a safety app.')
    expect(row({ ...READY, battery: 'optimised' }, 'battery').detail).toBe(BATTERY_SENTENCE)
  })
})

describe('the summary is the server\'s own rule, so the phone and the console never disagree', () => {
  it('ready only when everything is on', () => {
    expect(stateOf(serverReport(READY)!)).toBe('ready')
    expect(summaryOf(READY).headline).toBe('Ready to ring like a call')
  })

  it('never ready with full-screen alerts or notifications off: those are not ready at all', () => {
    for (const n of [{ ...READY, fullScreen: 'off' as const }, { ...READY, notifications: 'off' as const }, { ...READY, ringChannel: 'silent' as const }, { ...READY, alarmVolume: 0 }, { ...READY, alarmTone: 'none' as const }, { ...READY, configured: false }]) {
      expect(stateOf(serverReport(n)!)).toBe('not_ready')
      expect(summaryOf(n).state).toBe('not_ready')
    }
  })

  it('partly ready when it rings, but something holds it back, and the summary says what', () => {
    const partial: NativeReadiness = { ...READY, channelBypass: false, battery: 'optimised' }
    expect(stateOf(serverReport(partial)!)).toBe('partial')
    const s = summaryOf(partial)
    expect(s.state).toBe('partial')
    expect(s.detail).toMatch(/Do Not Disturb/)
    expect(s.detail).toMatch(/battery/i)
    for (const n of [{ ...READY, screenLock: 'off' as const }, { ...READY, background: 'restricted' as const }, { ...READY, alarmVolume: 1 }, { ...READY, background: 'unknown' as const }]) {
      expect(stateOf(serverReport(n)!)).toBe('partial')
    }
  })

  it('an alarm volume the phone would not report is doubt, and doubt about a ring is not ready', () => {
    expect(stateOf(serverReport({ ...READY, alarmVolume: -1, alarmVolumeMax: -1 })!)).toBe('not_ready')
  })
})

describe('the report sent with push.register (§6.6)', () => {
  it('carries exactly the keys the server reads, in its words', () => {
    expect(serverReport(READY)).toEqual({
      v: 1, configured: true, notifications: 'on', ring_channel: 'on', full_screen: 'on', dnd_bypass: 'on', battery: 'exempt',
      background: 'allowed', alarm_volume: 'ok', alarm_tone: 'set', screen_lock: 'on', sdk: 35, maker: 'google',
    })
  })

  it('buckets the alarm volume and reads the bypass back from the channel, not from the access', () => {
    const r = serverReport({ ...READY, alarmVolume: 1, dndAccess: true, channelBypass: false })!
    expect(r.alarm_volume).toBe('low')
    expect(r.dnd_bypass).toBe('off')
  })

  it('a channel that is silent, quiet or missing is off', () => {
    for (const ringChannel of ['silent', 'quiet', 'missing', 'off'] as const) expect(serverReport({ ...READY, ringChannel })!.ring_channel).toBe('off')
  })

  it('no module, no report', () => {
    expect(serverReport(null)).toBeUndefined()
    expect(serverReport(undefined)).toBeUndefined()
  })

  it('the maker is plain words the server can keep', () => {
    expect(serverReport({ ...READY, maker: 'Samsung Electronics!' })!.maker).toBe('samsung electronics')
  })
})

describe('the sentences for phones that cannot ring like a call', () => {
  it('Expo Go, an iPhone and an older build each say what is true', () => {
    expect(NOTES.expo_go).toBe('Call-style alerts need the Guard App build from the office. In this preview, alerts arrive only while the app is open.')
    expect(NOTES.no_module).toBe('Update the Guard App to ring like a call.')
    expect(NOTES.ios).toMatch(/time-sensitive/)
    expect(NOTES.ios).toMatch(/Apple/)
    expect(NOTES.ios).not.toMatch(/ring like a call\./)
  })

  it('gives the manufacturer\'s own battery advice where there is one, and none where there is not', () => {
    expect(makerAdvice('samsung')).toMatch(/Never sleeping apps/)
    expect(makerAdvice('xiaomi')).toMatch(/Autostart/)
    expect(makerAdvice('google')).toBeNull()
    expect(makerAdvice('')).toBeNull()
  })
})
