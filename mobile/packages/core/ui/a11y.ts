/**
 * Three accessibility choices an app can make for ITSELF, on top of what the phone's own settings say:
 *
 *   text scale      every <Text> multiplies its size by it. An app may cap app × system together (`setTextCap`) — the
 *                   Guard App keeps the boards' proven 1.3×; the Resident App lifts the cap (M50 §6.5: "it limits a
 *                   resident with poor eyesight to protect a layout") and its screens scroll above the default instead.
 *   reduce motion   the kit's looping animations stand still, and a root layout can drop its transitions.
 *   high contrast   every piece of type is drawn in the darkest ink (M50 §6.5 — the board's "High contrast" switch):
 *                   body and secondary text go to black, so nothing reads pale. Status colours and links keep their
 *                   meaning.
 *
 * The kit only READS these. An app decides where the choice is kept and calls the setters (the Resident
 * App: Settings → Language & accessibility). The fidelity harness never sets them, so boards are measured
 * at exactly 1×, in the boards' own colours.
 */

import { useSyncExternalStore } from 'react'

/** The default cap on app × system text size: the boards' layouts are proven to here. */
export const MAX_TEXT_SCALE = 1.3

let scale = 1
let cap: number | null = MAX_TEXT_SCALE
let still = false
let contrast = false
const listeners = new Set<() => void>()
const tell = () => listeners.forEach((fn) => fn())
const subscribe = (fn: () => void) => { listeners.add(fn); return () => void listeners.delete(fn) }

/** The most app × system may reach — or null for no cap at all (the screens scroll instead). */
export function setTextCap(next: number | null): void {
  const value = next === null ? null : Number.isFinite(next) && next >= 1 ? next : MAX_TEXT_SCALE
  if (value === cap) return
  cap = value
  if (cap !== null && scale > cap) scale = cap
  tell()
}

export function setTextScale(next: number): void {
  const wanted = Number.isFinite(next) ? Math.max(1, next) : 1
  const clamped = cap === null ? wanted : Math.min(cap, wanted)
  if (clamped === scale) return
  scale = clamped
  tell()
}

export function setReduceMotion(next: boolean): void {
  if (next === still) return
  still = next
  tell()
}

export function setHighContrast(next: boolean): void {
  if (next === contrast) return
  contrast = next
  tell()
}

const readScale = () => scale
const readCap = () => cap
const readStill = () => still
const readContrast = () => contrast

export const useTextScale = (): number => useSyncExternalStore(subscribe, readScale, readScale)
export const useTextCap = (): number | null => useSyncExternalStore(subscribe, readCap, readCap)
export const useReduceMotionPref = (): boolean => useSyncExternalStore(subscribe, readStill, readStill)
export const useHighContrast = (): boolean => useSyncExternalStore(subscribe, readContrast, readContrast)

/**
 * What the phone's own font setting may still add on top of the app's choice: what keeps app × system at the cap — or
 * 0, React Native's "no limit", when the app has lifted it.
 */
export const systemHeadroom = (appScale: number, limit: number | null = cap): number =>
  limit === null ? 0 : Math.round((limit / appScale) * 1000) / 1000
