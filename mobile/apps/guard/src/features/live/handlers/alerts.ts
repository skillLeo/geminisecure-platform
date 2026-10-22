/**
 * ALERTS ON THE GUARD'S PHONE, FROM THE SOCKET (M11 Part C, DESIGN §2.4 A, §8.4).
 *
 * The server tells this guard's own channel (`guard.{t}.{g}`) about every step of an alert the guard was
 * buzzed for, or is responding to: raised, acknowledged, dispatched, en route, on scene, resolved, false
 * alarm, cancelled. Each event is a REFERENCE — an alert id and a step, nothing about who or where — so the
 * phone asks the server for the alert again through the same read its screen already makes, which applies
 * the guard's own scope.
 *
 *   alert.raised      the site's newest alert: `pullDeltas`, the one "what changed?" read, which keeps the
 *                     alert on the phone and raises the banner exactly as the push and the poll always have.
 *                     Coalesced: five events in 300 ms are one pull
 *   every other step  the one alert, refetched wherever it is on screen (the site-alert screen, the duress
 *                     follower); a closed alert then reads closed there, and the Response buttons go
 *
 * A SILENT DURESS SENDS NOTHING TO THE GUARD WHO RAISED IT (the server never names their channel), so
 * nothing here can make a phone in a watched guard's pocket light up. Live never raises a banner itself.
 */

import { invalidateRecord, type LiveHandler } from '@gs/core/live'
import { pullDeltas } from '@/features/notify/poller'

const COALESCE_MS = 300
let pullTimer: ReturnType<typeof setTimeout> | null = null
let pulling: Promise<unknown> | null = null
let pullAgain = false

/** One `pullDeltas` for a burst of raises, and never two at once. */
export function pullSoon(): void {
  if (pullTimer !== null) return
  pullTimer = setTimeout(() => {
    pullTimer = null
    if (pulling) {
      pullAgain = true
      return
    }
    pulling = pullDeltas()
      .catch(() => null)
      .finally(() => {
        pulling = null
        if (pullAgain) {
          pullAgain = false
          pullSoon()
        }
      })
  }, COALESCE_MS)
}

const raised: LiveHandler = () => {
  pullSoon()
}

const stepped: LiveHandler = (event) => {
  if (event.t === 'alert.raised') return
  if (event.i) invalidateRecord('alerts', event.i)
}

export const alertHandlers: Record<string, LiveHandler[]> = {
  'alert.raised': [raised],
  'alert.*': [stepped],
}

/** For tests. */
export function resetAlertHandlers(): void {
  if (pullTimer !== null) clearTimeout(pullTimer)
  pullTimer = null
  pulling = null
  pullAgain = false
}
