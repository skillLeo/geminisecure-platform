/**
 * What the alertness feature keeps: the guard's consent (on this phone, in the key-value store — it goes
 * with the session on a rebind or sign-out, so the next guard is asked afresh), and, in memory only,
 * the check that is open right now and the server-clock offset it was read with.
 */

import { getStorage, jsonStore } from '@gs/core'
import { NO_CONSENT, type Consent, type PendingCheck } from './alertness'

const box = () => jsonStore<Consent>(getStorage(), 'alertness.consent', 'v1')

export const consent = {
  get: (): Consent => box().get() ?? NO_CONSENT,
  agree(at: Date): Consent { const next = { agreedAt: at.toISOString(), declinedAt: null }; box().set(next); return next },
  decline(at: Date): Consent { const next = { agreedAt: null, declinedAt: at.toISOString() }; box().set(next); return next },
}

type Open = { checks: PendingCheck[]; offsetMs: number; pulledAt: number }
let open: Open = { checks: [], offsetMs: 0, pulledAt: 0 }
const listeners = new Set<() => void>()

export const openChecks = {
  get: (): Open => open,
  set(next: Open): void { open = next; listeners.forEach((fn) => fn()) },
  /** Once answered (or closed), a check is never presented again from a stale pull. */
  settle(id: number | string): void { open = { ...open, checks: open.checks.filter((c) => String(c.check_id) !== String(id)) }; listeners.forEach((fn) => fn()) },
  subscribe(fn: () => void): () => void { listeners.add(fn); return () => listeners.delete(fn) },
}
