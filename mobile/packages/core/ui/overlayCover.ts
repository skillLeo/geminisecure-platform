/**
 * WHAT IS DRAWN OVER THE WHOLE APP, SO A QUESTION IS NEVER DRAWN OVER IT (INV-12, DESIGN §12.1 rule b).
 *
 * A ConfirmSheet is a React Native Modal: a window of its own, above everything the navigator draws — including
 * the Resident App's lock, which is an ordinary view mounted after the navigator. Left alone, a question asked
 * before the phone went into a pocket would come back ABOVE the lock, answerable by whoever picked the phone up.
 *
 * So whatever covers the app says so here (`setCovered('app-lock', true)`), and every sheet reads `useCovered()`
 * and hides while anything covers. A sheet whose write is already on its way hides too, and shows its outcome when
 * the cover lifts. One small store, keyed, so two covers can never switch each other off.
 */

import { useSyncExternalStore } from 'react'

const covers = new Set<string>()
const listeners = new Set<() => void>()

/** `key` names the cover ("app-lock"); `on` is whether it is down right now. */
export function setCovered(key: string, on: boolean): void {
  if (covers.has(key) === on) return
  if (on) covers.add(key)
  else covers.delete(key)
  listeners.forEach((fn) => fn())
}

export const isCovered = (): boolean => covers.size > 0

const subscribe = (fn: () => void) => {
  listeners.add(fn)
  return () => void listeners.delete(fn)
}

export const useCovered = (): boolean => useSyncExternalStore(subscribe, isCovered, isCovered)
