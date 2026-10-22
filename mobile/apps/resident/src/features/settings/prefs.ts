/**
 * The settings that live ON THIS PHONE — because the server has nowhere to keep them.
 *
 * The Resident API has no notification preferences, no sessions list, no passwords, no language and no
 * accessibility settings. What a resident chooses here is therefore a fact about one phone:
 *
 *   textSize       how much larger the app's type is drawn (a multiplier the type layer reads) — up to half as large
 *                  again, with nothing capping the phone's own setting on top (M50 §6.5): screens scroll instead
 *   reduceMotion   screens change without an animation
 *   highContrast   every piece of type in the darkest ink (M50 §6.5, the board's "High contrast")
 *   appLock        ask the PHONE to confirm it is its owner before the app opens (the OS prompt only —
 *                  nothing biometric is read, kept or sent; this is a yes or a no from the phone)
 *
 * Two more settings are shown on these screens but are NOT kept here, because they already have ONE home
 * that the rest of the app reads — a second copy would be a switch that changes nothing:
 *
 *   dataLight      `dataLight` in @gs/core/lib (the notifier, the lists and the photo pickers ask it)
 *   notifications  `notificationPrefs` in features/notify/prefs (the notifier asks it before every banner)
 *
 * `prefs()` gives all of it in one object, for whoever needs to read it.
 * Signing out clears the phone's storage, and these with it; `reset()` drops the copy held in memory.
 */

import { useEffect, useState, useSyncExternalStore } from 'react'
import { AccessibilityInfo } from 'react-native'
import { dataLight, getStorage, jsonStore } from '@gs/core/lib'
import { notificationPrefs, type Prefs as NotificationPrefs } from '@/features/notify/prefs'

/**
 * NO CAP TO PROTECT A LAYOUT (M50 §6.5). The four steps used to stop at 1.3× — app and phone together — "so every
 * screen still fits": the wrong trade for a resident with poor eyesight. Now they run to 1.5×, the phone's own setting
 * adds on top with nothing stopping it (`setTextCap(null)`, the root layout), and above the default a screen scrolls.
 */
export const TEXT_SIZES = [
  { key: 'standard', label: 'Standard', scale: 1 },
  { key: 'large', label: 'Large', scale: 1.15 },
  { key: 'larger', label: 'Larger', scale: 1.3 },
  { key: 'largest', label: 'Largest', scale: 1.5 },
] as const

export type TextSize = (typeof TEXT_SIZES)[number]['key']
/**
 * paymentsLock — "Require biometrics for payments" (board 19, drawn ON): the phone confirms its owner before any card
 * payment above $5,000 (paymentsLock.ts). On unless the resident turns it off.
 */
export type LocalPrefs = { textSize: TextSize; reduceMotion: boolean; highContrast: boolean; appLock: boolean; paymentsLock: boolean }
export type AllPrefs = LocalPrefs & { dataLight: boolean; notifications: NotificationPrefs }

export const DEFAULTS: LocalPrefs = { textSize: 'standard', reduceMotion: false, highContrast: false, appLock: false, paymentsLock: true }

const SIZE_KEYS: readonly string[] = TEXT_SIZES.map((size) => size.key)

/** Whatever was stored — by an older version, or by hand — comes out as a shape this version understands. */
export function normalise(stored: Partial<Record<keyof LocalPrefs, unknown>> | null | undefined): LocalPrefs {
  const size = stored?.textSize
  return {
    textSize: typeof size === 'string' && SIZE_KEYS.includes(size) ? (size as TextSize) : DEFAULTS.textSize,
    reduceMotion: stored?.reduceMotion === true,
    highContrast: stored?.highContrast === true,
    appLock: stored?.appLock === true,
    paymentsLock: stored?.paymentsLock !== false,
  }
}

export const textScaleOf = (size: TextSize): number => TEXT_SIZES.find((s) => s.key === size)?.scale ?? 1

/** One step smaller (-1) or larger (+1); stays put at either end. */
export function stepTextSize(size: TextSize, by: -1 | 1): TextSize {
  const at = TEXT_SIZES.findIndex((s) => s.key === size)
  return TEXT_SIZES[Math.min(TEXT_SIZES.length - 1, Math.max(0, at + by))]?.key ?? size
}

/**
 * The size a finger on the track lands on: the NEAREST of the four steps, read from where the thumb's centre
 * would be. `x` is from the track's left edge; outside the track it is the end it is past. A track not yet
 * measured (width 0) answers the first step rather than dividing by nothing.
 */
export function textSizeAt(x: number, width: number, thumb: number): TextSize {
  const span = width - thumb
  const fraction = span <= 0 ? 0 : Math.min(1, Math.max(0, (x - thumb / 2) / span))
  return TEXT_SIZES[Math.round(fraction * (TEXT_SIZES.length - 1))]?.key ?? DEFAULTS.textSize
}

const KEY = 'settings.prefs'
const VERSION = 'v1'
const box = () => jsonStore<Partial<LocalPrefs>>(getStorage(), KEY, VERSION)
const listeners = new Set<() => void>()

// The copy in memory is only ever as old as the stored text it was read from: a sign-out wipes the
// storage from somewhere else, and the very next read here sees that and goes back to the defaults.
let seen: string | null | undefined
let cached: LocalPrefs = DEFAULTS

function read(): LocalPrefs {
  const raw = getStorage().getString(`${VERSION}:${KEY}`)
  if (raw !== seen) {
    seen = raw
    cached = normalise(box().get())
  }
  return cached
}

export const settingsPrefs = {
  get: read,
  set(patch: Partial<LocalPrefs>): LocalPrefs {
    box().set(normalise({ ...read(), ...patch }))
    const next = read()
    listeners.forEach((fn) => fn())
    return next
  },
  subscribe(fn: () => void): () => void {
    listeners.add(fn)
    return () => void listeners.delete(fn)
  },
  /** After a sign-out or "clear data" — tells whoever is drawing these to look again. */
  reset(): void {
    listeners.forEach((fn) => fn())
  },
}

/**
 * WHO IS LISTENING. These choices only mean something because a part of the app OUTSIDE this folder reads
 * them, and the root layout (app/_layout.tsx) now does all three:
 *
 *   textSize       `bindTextScale(setTextScale)` — core's <Text> multiplies every size by it
 *   reduceMotion   `useReduceMotion()` — the navigator drops its transition and the kit's pulses stand still
 *   appLock        `<AppLock />`, mounted after the navigator
 *
 * So the screens no longer say "saved, but not applied by this build". The one thing still CHECKED at run
 * time is the lock: a lock that is promised and not there is worse than no lock, so the Security screen
 * draws its switch as unavailable unless the cover is really mounted (AppLock sets this while it is).
 */
export const wired = { appLock: false }

/** Everything this phone has been told, in one object. */
export const prefs = (): AllPrefs => ({ ...read(), dataLight: dataLight.isOn(), notifications: notificationPrefs.get() })

export const useSettingsPrefs = (): LocalPrefs => useSyncExternalStore(settingsPrefs.subscribe, read, read)

export const textScale = (): number => textScaleOf(read().textSize)
export const reduceMotion = (): boolean => read().reduceMotion
export const highContrast = (): boolean => read().highContrast
export const appLockOn = (): boolean => read().appLock

/**
 * FOR THE TYPE LAYER — call once (root layout): `apply` is given the multiplier now and on every change.
 *   useEffect(() => bindTextScale(setTextScale), [])      // setTextScale: whatever core's Text reads
 */
export function bindTextScale(apply: (scale: number) => void): () => void {
  apply(textScale())
  return settingsPrefs.subscribe(() => apply(textScale()))
}

/** The same for high contrast: `useEffect(() => bindHighContrast(setHighContrast), [])` in the root layout. */
export function bindHighContrast(apply: (on: boolean) => void): () => void {
  apply(highContrast())
  return settingsPrefs.subscribe(() => apply(highContrast()))
}

/**
 * FOR THE NAVIGATOR — in the root layout:
 *   const still = useReduceMotion()
 *   <Stack screenOptions={{ …, animation: still ? 'none' : 'fade' }} />
 */
export function useReduceMotion(): boolean {
  const chosen = useSettingsPrefs().reduceMotion
  // The phone's own accessibility setting counts too: someone who told their PHONE that motion makes them
  // unwell must not have to find a second switch inside each app. Either one is enough.
  const system = useSystemReduceMotion()
  return chosen || system
}

/** The PHONE's own "reduce motion" setting — read once, then followed as it changes. False where there is none to ask. */
export function useSystemReduceMotion(): boolean {
  const [on, setOn] = useState(false)
  useEffect(() => {
    let alive = true
    void AccessibilityInfo.isReduceMotionEnabled().then((value) => { if (alive) setOn(value) }).catch(() => undefined)
    const sub = AccessibilityInfo.addEventListener('reduceMotionChanged', setOn)
    return () => { alive = false; sub.remove() }
  }, [])
  return on
}
