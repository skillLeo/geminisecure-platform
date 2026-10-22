/**
 * THE ONE PANIC ON THIS PHONE — where it is kept, whose it is, and what it means to a screen that is not the
 * panic screen.
 *
 * It was the panic screen's own business, read and written only while that screen was mounted. Three things
 * followed from that (B117, B118):
 *
 *   a panic the queue delivered while the screen was closed stayed "saved — not yet received", and a day later
 *   the queue's confirmed copy left and nothing could ever turn it into the alert it had become;
 *
 *   a sign-out nobody chose — the thirty-minute idle window closing, or the office changing access — wiped it
 *   with everything else, so the resident was moved off the panic screen to Log In with no 119 and no word
 *   that the alert had not gone, and after signing in again it was sent with no screen showing it;
 *
 *   and whoever signed in next on the phone would have been shown somebody else's.
 *
 * So the record carries whose it is, `settleHeldPanic` keeps it true from the queue whatever screen is open,
 * `clearEverything` keeps it through a sign-out nobody chose, and `panicNotice` is what Log In says about it.
 *
 * Pure but for the one store it reads and writes.
 */

import { getStorage, jsonStore } from '@gs/core/lib'
import type { AlertAnswer, AlertType } from '@gs/core/safety'

/** Jamaica: police 119 · ambulance and fire 110. */
export const EMERGENCY_NUMBER = '119'

/** Why a held alert is held: `raiseAlert`'s own reasons. `refused` is not held at all — the server said no. */
export type HeldWhy = 'no_signal' | 'slow' | 'server' | 'refused'

/**
 * `type`, `why`, `message` and `owner` are optional on disk: an alert saved by an earlier build has none of them,
 * and is a general emergency held for want of a signal, raised by whoever is signed in.
 */
export type Saved = {
  alert: AlertAnswer | null
  opId: string | null
  pressedAt: string
  withPosition: boolean
  type?: AlertType | null
  why?: HeldWhy | null
  message?: string | null
  /** `account:<id>` — the Resident App account that pressed it. */
  owner?: string | null
  /**
   * The position the press SENT, when it sent one (`withPosition`), kept on the phone so the screen can show the
   * resident where the control room sees them (M11 §6). Never read again from the handset for this: it is what went.
   */
  position?: { latitude: number; longitude: number } | null
}

const KEY = 'panic.active'
const VERSION = 'v1'

/** The key on disk, which `clearEverything` keeps through a sign-out nobody chose (B118). */
export const PANIC_RECORD_STORAGE_KEY = `${VERSION}:${KEY}`

export const panicBox = () => jsonStore<Saved>(getStorage(), KEY, VERSION)

export const ownerOf = (principal: { id: number | string } | null | undefined): string | null => (principal ? `account:${principal.id}` : null)

/** Somebody else signed in on this phone when it was pressed. A record with no owner is from an older build, and is taken as theirs. */
export const belongsToSomeoneElse = (saved: Saved | null, principal: { id: number | string } | null | undefined): boolean =>
  !!saved?.owner && !!principal && saved.owner !== ownerOf(principal)

const CLOSED = ['resolved', 'false_alarm']

/**
 * THE QUEUE'S ANSWER, ON THE RECORD — from the offline runtime, on every change to the queue, whatever screen is
 * open (B117). Delivered: it becomes the alert, before the queue's confirmed copy can leave a day later.
 * Refused: it says so, with the server's sentence. Anything else is left exactly as it is.
 */
export function settleHeldPanic(ops: readonly { id: string; state: string; result?: unknown; error?: { message?: string } | null }[]): void {
  const box = panicBox()
  const saved = box.get()
  if (!saved || saved.alert || !saved.opId) return
  const op = ops.find((o) => o.id === saved.opId)
  if (op?.state === 'confirmed' && op.result && typeof op.result === 'object') {
    void box.setDurable({ ...saved, alert: op.result as AlertAnswer, why: null, message: null })
  } else if (op?.state === 'refused' && saved.why !== 'refused') {
    void box.setDurable({ ...saved, why: 'refused', message: op.error?.message || null })
  }
}

/**
 * WHAT LOG IN SAYS ABOUT IT (B118) — the one screen a resident is sent to when a session ends by itself, and
 * the one that used to say nothing about an alert that had not gone. Null when there is nothing to say.
 */
export function panicNotice(saved: Saved | null): { title: string; body: string } | null {
  if (!saved) return null
  if (!saved.alert) {
    if (saved.why === 'refused') {
      return { title: 'YOUR EMERGENCY ALERT WAS NOT ACCEPTED', body: `${saved.message ? `${saved.message} ` : ''}Sign in to send a new one, or call ${EMERGENCY_NUMBER} now. A call needs no data.` }
    }
    return { title: 'YOUR EMERGENCY ALERT HAS NOT BEEN SENT', body: `You were signed out before it could go. Sign in again and it is sent at once, or call ${EMERGENCY_NUMBER} now. A call needs no data.` }
  }
  if (!CLOSED.includes(saved.alert.status)) {
    return { title: 'YOUR EMERGENCY ALERT IS OPEN', body: `The control room has it. Sign in to follow it, or call ${EMERGENCY_NUMBER} now. A call needs no data.` }
  }
  return null
}
