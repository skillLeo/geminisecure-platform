/**
 * "CALL-STYLE ALERTS" — whether this phone can be rung like a call, in words a guard can act on (GUARD_RING_DESIGN §9.1),
 * and the report the SERVER decides from (§6.6). Pure: the native readiness is handed in, so a test holds every
 * sentence to what the phone actually said.
 *
 * NOTHING HERE IS ASSUMED. Each row is what Android answered at the moment of asking: ON, OFF, LOW (the alarm volume)
 * or UNKNOWN — "unknown" is never drawn as on. Each row that is off names the phone's own settings screen that fixes it,
 * and the app never changes any of them itself.
 *
 * THE SUMMARY IS THE SERVER'S RULE (App\Services\Ring\RingReadiness::state), applied to the same report the server is
 * sent, so the screen in the guard's hand and the console in the control room can never disagree about a phone:
 *
 *   not_ready  not set up, notifications off, the ring channel off, full-screen alerts off, alarm volume at zero, no
 *              alarm tone — or any of those unknown
 *   partial    it rings, but: no Do Not Disturb bypass, battery optimised, background restricted, alarm volume low,
 *              no screen lock, or anything else unknown
 *   ready      everything above in order
 */

import type { RingReport } from '@gs/core/notify'
import type { Availability, NativeReadiness, SettingName } from './native'

export type RowKey = 'notifications' | 'ring_channel' | 'full_screen' | 'dnd' | 'battery' | 'background' | 'alarm_volume' | 'alarm_tone' | 'screen_lock'
export type RowState = 'on' | 'off' | 'low' | 'unknown'
export type ReadinessRow = { key: RowKey; title: string; state: RowState; detail: string; fix: SettingName | null; fixLabel: string | null }
export type RingState = 'ready' | 'partial' | 'not_ready'
export type Summary = { state: RingState; headline: string; detail: string }

export const BATTERY_SENTENCE = 'Battery saving can hold a panic alert back for minutes while this phone is idle. Allow GeminiSecure Guard to run unrestricted: it is a safety app.'

/** What the screen says instead of rows, on a phone that has no call-style ring at all. */
export const NOTES: Record<Exclude<Availability, 'android'>, string> = {
  expo_go: 'Call-style alerts need the Guard App build from the office. In this preview, alerts arrive only while the app is open.',
  no_module: 'Update the Guard App to ring like a call.',
  ios:
    "On an iPhone a panic arrives as a time-sensitive alert with a sound, which comes through Focus modes. Apple allows full-screen ringing only for phone calls, and an alert that sounds through silent mode needs Apple's approval, so an iPhone rings once for each alert rather than like a call.",
  web: 'Call-style alerts ring on an Android phone with the Guard App installed.',
}

/** `off` at 0, `low` under 30 % of the maximum, `ok` otherwise; `unknown` when the phone would not say. The same rule as RingSound.kt. */
export function volumeBucket(volume: number, max: number): 'ok' | 'low' | 'off' | 'unknown' {
  if (!Number.isFinite(volume) || volume < 0) return 'unknown'
  if (volume === 0) return 'off'
  if (max > 0 && volume * 100 < max * 30) return 'low'
  return 'ok'
}

const on = (state: boolean): RowState => (state ? 'on' : 'off')

/** The rows, in the order the console lists them. `askForDnd` is `flags.askForDndAccess`, passed in. */
export function rowsOf(n: NativeReadiness, { askForDnd }: { askForDnd: boolean }): ReadinessRow[] {
  const volume = volumeBucket(n.alarmVolume, n.alarmVolumeMax)
  const channelOn = n.ringChannel === 'on'
  const dndRow = (): ReadinessRow => {
    const base = { key: 'dnd' as const, title: 'Rings through Do Not Disturb' }
    if (n.channelBypass) return { ...base, state: 'on', detail: 'On: Do Not Disturb, a Focus mode or bed time does not silence a panic.', fix: null, fixLabel: null }
    if (n.dndAccess) return { ...base, state: 'off', detail: 'Allowed for the app, but the Call-style alerts channel has not got it: turn on "Override Do Not Disturb" for that channel.', fix: 'ring_channel', fixLabel: 'Settings' }
    return { ...base, state: 'off', detail: 'Off: Do Not Disturb, a Focus mode or bed time can silence a panic.', fix: askForDnd ? 'dnd' : null, fixLabel: askForDnd ? 'Allow' : null }
  }
  return [
    {
      key: 'notifications', title: 'Notifications', state: on(n.notifications === 'on'),
      detail: n.notifications === 'on' ? 'On.' : 'Off: nothing from GeminiSecure Guard can show or ring on this phone.',
      fix: n.notifications === 'on' ? null : 'notifications', fixLabel: n.notifications === 'on' ? null : 'Settings',
    },
    {
      key: 'ring_channel', title: 'Call-style alerts channel', state: on(channelOn),
      detail: channelOn ? "On, with the phone's own alarm tone." : 'Off or silent: a panic will not ring like a call.',
      fix: channelOn ? null : 'ring_channel', fixLabel: channelOn ? null : 'Settings',
    },
    {
      key: 'full_screen', title: 'Full-screen alerts', state: on(n.fullScreen !== 'off'),
      detail: n.fullScreen === 'on' ? 'On: a panic takes over the lock screen.' : n.fullScreen === 'not_needed' ? 'Nothing to allow: phones older than Android 14 show it without asking.' : 'Off: a panic shows as a banner instead of taking over the lock screen.',
      fix: n.fullScreen === 'off' ? 'full_screen' : null, fixLabel: n.fullScreen === 'off' ? 'Allow' : null,
    },
    dndRow(),
    {
      key: 'battery', title: 'Battery optimisation', state: n.battery === 'exempt' ? 'on' : n.battery === 'optimised' ? 'off' : 'unknown',
      detail: n.battery === 'exempt' ? 'Off for this app: alerts are not held back.' : n.battery === 'optimised' ? BATTERY_SENTENCE : 'This phone would not say.',
      fix: n.battery === 'optimised' ? 'battery' : null, fixLabel: n.battery === 'optimised' ? 'Allow' : null,
    },
    {
      key: 'background', title: 'Background activity', state: n.background === 'allowed' ? 'on' : n.background === 'restricted' ? 'off' : 'unknown',
      detail: n.background === 'allowed' ? 'Allowed.' : n.background === 'restricted' ? 'Restricted: this phone may hold alerts back while the app is closed.' : 'This version of Android does not say.',
      fix: n.background === 'restricted' ? 'background' : null, fixLabel: n.background === 'restricted' ? 'Settings' : null,
    },
    {
      key: 'alarm_volume', title: 'Alarm volume', state: volume === 'ok' ? 'on' : volume === 'low' ? 'low' : volume === 'off' ? 'off' : 'unknown',
      detail:
        volume === 'ok' ? `At ${n.alarmVolume} of ${n.alarmVolumeMax}.`
        : volume === 'low' ? `Low (${n.alarmVolume} of ${n.alarmVolumeMax}): the ring may be hard to hear.`
        : volume === 'off' ? 'Off: the ring will make no sound.'
        : 'This phone would not say.',
      fix: volume === 'low' || volume === 'off' ? 'alarm_volume' : null, fixLabel: volume === 'low' || volume === 'off' ? 'Adjust' : null,
    },
    {
      key: 'alarm_tone', title: 'Alarm tone', state: on(n.alarmTone === 'set'),
      detail: n.alarmTone === 'set' ? "Set: the ring uses the phone's own alarm tone." : 'None is set: the ring will be silent.',
      fix: n.alarmTone === 'set' ? null : 'alarm_tone', fixLabel: n.alarmTone === 'set' ? null : 'Settings',
    },
    {
      key: 'screen_lock', title: 'Screen lock', state: on(n.screenLock === 'on'),
      detail: n.screenLock === 'on' ? '"I\'m responding" is confirmed by unlocking the phone.' : '"I\'m responding" cannot be used from a ring on a phone with no screen lock. Set a PIN, pattern or password.',
      fix: n.screenLock === 'on' ? null : 'screen_lock', fixLabel: n.screenLock === 'on' ? null : 'Settings',
    },
  ]
}

/** The report `push.register` carries (§6.6): the server's keys, in the server's words. Undefined without the module. */
export function serverReport(n: NativeReadiness | null | undefined): RingReport | undefined {
  if (!n) return undefined
  const maker = String(n.maker ?? '').toLowerCase().replace(/[^a-z0-9 _-]/g, '').trim().slice(0, 20)
  return {
    v: 1,
    configured: n.configured === true,
    notifications: n.notifications === 'on' ? 'on' : 'off',
    ring_channel: n.ringChannel === 'on' ? 'on' : 'off',
    full_screen: n.fullScreen,
    dnd_bypass: n.channelBypass ? 'on' : 'off',
    battery: n.battery,
    background: n.background,
    alarm_volume: volumeBucket(n.alarmVolume, n.alarmVolumeMax),
    alarm_tone: n.alarmTone,
    screen_lock: n.screenLock,
    sdk: n.sdk,
    maker,
  }
}

const DECIDES_NOT_READY = ['configured', 'notifications', 'ring_channel', 'full_screen', 'alarm_volume', 'alarm_tone'] as const

/** App\Services\Ring\RingReadiness::state(), on the phone. */
export function stateOf(r: RingReport): RingState {
  for (const key of DECIDES_NOT_READY) {
    const value = r[key]
    if (value === undefined || value === 'unknown' || value === false || value === 'off' || value === 'none') return 'not_ready'
  }
  const partial = r.dnd_bypass !== 'on' || r.battery !== 'exempt' || r.background !== 'allowed' || r.alarm_volume === 'low' || r.screen_lock !== 'on'
  return partial ? 'partial' : 'ready'
}

const REASONS: [(r: RingReport) => boolean, string][] = [
  [(r) => r.configured !== true, 'the ring is not set up yet: open the app once with signal'],
  [(r) => r.notifications !== 'on', 'notifications are off'],
  [(r) => r.ring_channel !== 'on', 'the Call-style alerts channel is off or silent'],
  [(r) => r.full_screen === 'off' || r.full_screen === 'unknown', 'full-screen alerts are off'],
  [(r) => r.alarm_volume === 'off', 'the alarm volume is at zero'],
  [(r) => r.alarm_volume === 'unknown', 'the phone would not say its alarm volume'],
  [(r) => r.alarm_tone !== 'set', 'no alarm tone is set'],
]
const PARTLY: [(r: RingReport) => boolean, string][] = [
  [(r) => r.dnd_bypass !== 'on', 'Do Not Disturb can silence it'],
  [(r) => r.battery !== 'exempt', 'battery saving can delay it'],
  [(r) => r.background !== 'allowed', 'background activity is restricted or unknown'],
  [(r) => r.alarm_volume === 'low', 'the alarm volume is low'],
  [(r) => r.screen_lock !== 'on', 'there is no screen lock, so it cannot be answered from the ring'],
]

export function summaryOf(n: NativeReadiness): Summary {
  const r = serverReport(n)!
  const state = stateOf(r)
  if (state === 'ready') return { state, headline: 'Ready to ring like a call', detail: 'A panic at your estate will ring this phone over the lock screen until somebody answers.' }
  if (state === 'partial') return { state, headline: 'Partly ready', detail: `It rings, but ${PARTLY.filter(([when]) => when(r)).map(([, why]) => why).join('; ')}.` }
  return { state, headline: 'Not ready to ring like a call', detail: `A panic will not ring like a call: ${REASONS.filter(([when]) => when(r)).map(([, why]) => why).join('; ')}.` }
}

/**
 * The manufacturer's own battery manager cannot be read by any app, so this is advice, never a row that claims ON or
 * OFF. Only a real test ring proves a handset.
 */
export function makerAdvice(maker: string): string | null {
  const m = String(maker ?? '').toLowerCase()
  if (/samsung/.test(m)) return 'On a Samsung phone, also add GeminiSecure Guard to "Never sleeping apps" (Settings → Battery → Background usage limits).'
  if (/xiaomi|redmi|poco/.test(m)) return 'On a Xiaomi phone, also turn on Autostart for GeminiSecure Guard and set its battery saver to "No restrictions".'
  if (/tecno|infinix|itel|transsion/.test(m)) return 'On a Tecno, Infinix or itel phone, also allow GeminiSecure Guard to start by itself and to run in the background (look in Phone Master or the app\'s battery settings).'
  if (/huawei|honor/.test(m)) return 'On a Huawei or Honor phone, also set GeminiSecure Guard to "Manage manually" under App launch, with every switch on.'
  if (/oppo|realme|oneplus/.test(m)) return 'On this phone, also allow GeminiSecure Guard to auto-launch and to run in the background (in the app\'s battery settings).'
  if (/vivo/.test(m)) return 'On a vivo phone, also allow GeminiSecure Guard high background power use and auto-start.'
  return null
}
