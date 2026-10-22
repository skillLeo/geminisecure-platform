/**
 * THE ONLY DOOR TO THE CALL-STYLE RING'S NATIVE MODULE (apps/guard/modules/guard-ring, GUARD_RING_DESIGN §8.1).
 *
 * The ring itself needs no JavaScript: a killed phone wakes, rings, and is answered from the lock screen entirely
 * natively. This is for the app while it is open — where the server is, whether the guard is under their own
 * duress, the Call-style readiness screen, and tidying up at sign-out.
 *
 * `GuardRing` is NULL in Expo Go, on iOS, on the web and in an older APK without the module: every caller checks,
 * and every one of them then does exactly what the app did before the ring existed. It is loaded with
 * `requireOptionalNativeModule`, which answers null instead of throwing, so nothing here can stop the app at import.
 */

import { Platform } from 'react-native'
import { requireOptionalNativeModule } from 'expo'
import { isExpoGo } from '@gs/core/lib'

/** What the phone says, read from Android at the moment of asking (RingReadiness.kt `read`). */
export type NativeReadiness = {
  v: number
  /** The module knows where the server is and its ring channel exists. */
  configured: boolean
  sdk: number
  maker: string
  notifications: 'on' | 'off'
  ringChannel: 'on' | 'off' | 'missing' | 'quiet' | 'silent'
  fullScreen: 'on' | 'off' | 'not_needed'
  /** Notification Policy Access is granted to the app. */
  dndAccess: boolean
  /** The ring channel's own Do Not Disturb bypass, read back from Android. */
  channelBypass: boolean
  battery: 'exempt' | 'optimised' | 'unknown'
  background: 'allowed' | 'restricted' | 'unknown'
  /** -1 when the phone would not say. */
  alarmVolume: number
  alarmVolumeMax: number
  alarmTone: 'set' | 'none'
  screenLock: 'on' | 'off'
  biometric: boolean
  interruptionFilter: number
  /** The live sound check a `shown` receipt carries: `imp=4;snd=1;vol=5/7;dnd=pass;fsi=1;lk=1`. */
  soundDetail: string
}

/** The phone settings screens the module can open (RingReadiness.kt `open`). */
export type SettingName = 'notifications' | 'ring_channel' | 'full_screen' | 'dnd' | 'battery' | 'background' | 'alarm_volume' | 'alarm_tone' | 'screen_lock' | 'app'

export type RingEvent = { type: 'ringing' | 'opened' | 'answered' | 'declined' | 'stopped' | 'held'; id: string }

export type GuardRingNative = {
  /** Where answers and receipts go: the app's one API address. */
  configure(apiBase: string): Promise<boolean>
  readiness(): Promise<NativeReadiness>
  openSetting(which: SettingName): Promise<boolean>
  /** The guard's own live duress (R14): no ring may sound before `untilMs` (the phone's clock); null when there is none. */
  setQuiet(untilMs: number | null): Promise<void>
  /** Sends the answers and receipts still waiting on the phone. Resolves with how many the server took. */
  flushPending(): Promise<number>
  /** The last alert whose ring screen was opened, once. */
  consumeLaunch(): Promise<string | null>
  /** After the tray was cleared (an expired session): puts every live ring back. Resolves with how many. */
  restore(): Promise<number>
  stopAll(): Promise<void>
  forget(): Promise<void>
  addListener(event: 'onRingEvent', listener: (event: RingEvent) => void): { remove(): void }
}

function load(): GuardRingNative | null {
  if (Platform.OS !== 'android' || isExpoGo) return null
  try {
    return requireOptionalNativeModule<GuardRingNative>('GuardRing')
  } catch {
    return null
  }
}

export const GuardRing: GuardRingNative | null = load()

/** Why this phone cannot ring like a call, or 'android' when it can (whether it is set up is the readiness screen's business). */
export type Availability = 'android' | 'expo_go' | 'ios' | 'no_module' | 'web'

export function availability(module: GuardRingNative | null = GuardRing): Availability {
  if (Platform.OS === 'web') return 'web'
  if (Platform.OS === 'ios') return 'ios'
  if (isExpoGo) return 'expo_go'
  return module ? 'android' : 'no_module'
}
