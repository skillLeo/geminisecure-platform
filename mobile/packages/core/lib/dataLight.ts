/**
 * Data-light mode — for a phone on a small prepaid data plan, which in Jamaica is most phones.
 *
 * ONE switch, kept on the phone, read by everything that spends data for convenience rather than need:
 *
 *   pollEvery(ms)     lists that refresh by themselves do it four times less often
 *   photoLimits()     a photo attached to a report is sent smaller (still readable)
 *   refetchOnFocus()  a screen does not re-ask for what it already has just because it was reopened
 *
 * WHAT IT NEVER TOUCHES — safety does not get a cheaper tier: a panic or duress alert, the alertness
 * check-in poll, gate verification, a visitor-approval request and the offline queue's uploads all run
 * exactly as they do with the switch off. Those callers simply do not ask this file.
 */

import { useSyncExternalStore } from 'react'
import { getStorage, jsonStore } from './storage'

const box = () => jsonStore<{ on: boolean }>(getStorage(), 'prefs.dataLight', 'v1')
const listeners = new Set<() => void>()
let cached: boolean | null = null

const read = (): boolean => {
  if (cached === null) cached = box().get()?.on === true
  return cached
}

export const dataLight = {
  isOn: read,
  set(on: boolean): void {
    cached = on
    box().set({ on })
    listeners.forEach((fn) => fn())
  },
  subscribe(fn: () => void): () => void { listeners.add(fn); return () => listeners.delete(fn) },
  /** After a logout wipes storage, or in a test. */
  reset(): void { cached = null; listeners.forEach((fn) => fn()) },
}

export const useDataLight = (): boolean => useSyncExternalStore(dataLight.subscribe, read, read)

const SLOWER = 4

/** A convenience refresh interval, stretched in data-light mode. Never below the interval asked for. */
export const pollEvery = (ms: number, on: boolean = read()): number => (on ? ms * SLOWER : ms)

/** How large an attached photo may be. 1024 px at 0.45 is still enough to read a number plate across a road. */
export const photoLimits = (on: boolean = read()): { maxSide: number; quality: number } => (on ? { maxSide: 1024, quality: 0.45 } : { maxSide: 1600, quality: 0.6 })

/** RTK Query's refetchOnFocus / refetchOnMountOrArgChange for a convenience list. */
export const refetchOnFocus = (on: boolean = read()): boolean => !on
