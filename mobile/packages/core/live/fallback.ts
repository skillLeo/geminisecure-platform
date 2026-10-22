/**
 * WHILE THE SOCKET IS DOWN, CATCH-UP IS THE POLL (DESIGN §5.6), and how long a reconnect may wait (§8.3).
 *
 *   safety screen open, either app   5 s     the resident's Panic, the guard's Duress and Site alert
 *   guard app                        15 s
 *   resident app                     30 s, ×4 in data-light mode
 *
 * A SAFETY SCREEN IS NEVER STRETCHED. `useLiveFallback({safety: true})` holds the cadence at 5 s while that
 * screen is open, in data-light mode too — today's safety polls are 5 s, and wherever the socket cannot
 * connect (iOS's Origin, the laptop tunnel, a network that blocks WebSockets) those screens must be no
 * staler than they are today. Data-light is a convenience tier; safety does not get one (lib/dataLight.ts).
 *
 * THE RECONNECT BACKOFF: 1, 2, 4, 8, 15, then 30 s, ±30 % jitter. Only the resident app in data-light
 * mode with no safety screen open may wait longer, up to 120 s (60 s, then 120 s). The guard app never does.
 */

import { useEffect } from 'react'
import { dataLight } from '../lib/dataLight'
import type { LiveApp } from './configure'

export const SAFETY_CADENCE_MS = 5_000
export const GUARD_CADENCE_MS = 15_000
export const RESIDENT_CADENCE_MS = 30_000
/** The resident's cadence in data-light mode: ×4, the same factor lib/dataLight.ts uses. */
export const DATA_LIGHT_FACTOR = 4

export const BACKOFF_STEPS_S = [1, 2, 4, 8, 15, 30] as const
/** The resident app in data-light mode, with no safety screen open, keeps doubling to this. */
export const DATA_LIGHT_CAP_S = 120
export const JITTER = 0.3

let safetyScreens = 0
const listeners = new Set<() => void>()

/** Whether a safety screen is open right now, on this app. */
export const safetyScreenOpen = (): boolean => safetyScreens > 0

export function onSafetyScreenChange(fn: () => void): () => void {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

/** A safety screen opened. Returns the "it closed" function; each call counts once. */
export function holdSafetyScreen(): () => void {
  safetyScreens += 1
  listeners.forEach((fn) => fn())
  let released = false
  return () => {
    if (released) return
    released = true
    safetyScreens = Math.max(0, safetyScreens - 1)
    listeners.forEach((fn) => fn())
  }
}

/**
 * A screen opts in while it is open: `useLiveFallback({ safety: true })` on the Panic, Duress and Site alert
 * screens keeps the down-cadence at 5 s and the reconnect wait at the guard's, whatever data-light says.
 */
export function useLiveFallback({ safety }: { safety: boolean }): void {
  useEffect(() => (safety ? holdSafetyScreen() : undefined), [safety])
}

/** How often catch-up runs while the socket is down. */
export function downCadenceMs(app: LiveApp, light: boolean = dataLight.isOn(), safety: boolean = safetyScreenOpen()): number {
  if (safety) return SAFETY_CADENCE_MS
  if (app === 'guard') return GUARD_CADENCE_MS
  return light ? RESIDENT_CADENCE_MS * DATA_LIGHT_FACTOR : RESIDENT_CADENCE_MS
}

/** The un-jittered wait before reconnect attempt `attempt` (1-based), in seconds. */
export function backoffStepS(attempt: number, app: LiveApp, light: boolean = dataLight.isOn(), safety: boolean = safetyScreenOpen()): number {
  const n = Math.max(1, Math.floor(attempt))
  if (n <= BACKOFF_STEPS_S.length) return BACKOFF_STEPS_S[n - 1]!
  const stretched = app === 'resident' && light && !safety
  if (!stretched) return BACKOFF_STEPS_S[BACKOFF_STEPS_S.length - 1]!
  // 30 → 60 → 120, and 120 from there on.
  return Math.min(DATA_LIGHT_CAP_S, BACKOFF_STEPS_S[BACKOFF_STEPS_S.length - 1]! * 2 ** (n - BACKOFF_STEPS_S.length))
}

/** The jittered wait in ms: the step ±30 %. `random` is 0 ≤ r < 1. */
export function backoffMs(attempt: number, app: LiveApp, random: number = Math.random(), light?: boolean, safety?: boolean): number {
  const step = backoffStepS(attempt, app, light, safety) * 1000
  return Math.round(step * (1 - JITTER + 2 * JITTER * random))
}

/** After the server closed a working socket (a deploy, an eviction): 0.5–3 s, to spread the reconnect herd. */
export const serverCloseDelayMs = (random: number = Math.random()): number => Math.round(500 + 2500 * random)

/** For tests. */
export function forgetSafetyScreens(): void {
  safetyScreens = 0
  listeners.forEach((fn) => fn())
}
