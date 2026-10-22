/**
 * Alertness checks — the pure logic (board guard-app-15).
 *
 * A check is issued by the server and must be answered within two minutes, ONLINE ("a check answered
 * after signal returns is late by definition"). Three rules live here so they can be tested:
 *
 *   CONSENT FIRST       no check is ever presented to a guard who has not been told what it is and agreed.
 *   NEVER IN THE WAY    a check never interrupts a duress alert or an incident report being written. It
 *                       waits; if its two minutes run out meanwhile, that is said honestly afterwards.
 *   THE SERVER'S CLOCK  the deadline is the server's. The phone's clock may be wrong, so time left is
 *                       measured against the server's time as of the last pull.
 */

export type PendingCheck = { check_id: number | string; issued_at: string; respond_by: string }
export type Consent = { agreedAt: string | null; declinedAt: string | null }
export const NO_CONSENT: Consent = { agreedAt: null, declinedAt: null }
export const WINDOW_MS = 120_000
export const POLL_MS = 20_000

/** server − device, in ms, from one pull: what to add to the phone's clock to get the server's. */
export const clockOffset = (serverTime: string | null | undefined, deviceNow: Date): number => {
  const server = serverTime ? new Date(serverTime).getTime() : NaN
  return Number.isFinite(server) ? server - deviceNow.getTime() : 0
}

/** The check to answer now: still open on the SERVER's clock, the one closing soonest first. */
export function pickCheck(checks: PendingCheck[], deviceNow: Date, offsetMs: number): PendingCheck | null {
  const serverNow = deviceNow.getTime() + offsetMs
  return [...checks].filter((c) => new Date(c.respond_by).getTime() > serverNow).sort((p, q) => new Date(p.respond_by).getTime() - new Date(q.respond_by).getTime())[0] ?? null
}

export function remaining(check: PendingCheck, deviceNow: Date, offsetMs: number): { ms: number; label: string; fraction: number } {
  const ms = Math.max(0, new Date(check.respond_by).getTime() - (deviceNow.getTime() + offsetMs))
  const total = Math.max(1, new Date(check.respond_by).getTime() - new Date(check.issued_at).getTime())
  const seconds = Math.ceil(ms / 1000)
  return { ms, label: `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`, fraction: Math.min(1, ms / total) }
}

export type Situation = { consent: Consent; duressActive: boolean; pathname: string; alreadyOnScreen: boolean }

/** Routes a check must never cover: an emergency, and a report being written (its text is not saved until it is sent). */
const PROTECTED = [/^\/duress/, /^\/incident\/new/]

export function mayPresent(s: Situation): { present: boolean; why: 'ok' | 'no_consent' | 'declined' | 'duress' | 'busy' | 'showing' } {
  if (s.alreadyOnScreen) return { present: false, why: 'showing' }
  // A guard who said no is not nagged: checks go unanswered (and they were told what that means) until they agree in More.
  if (!s.consent.agreedAt && s.consent.declinedAt) return { present: false, why: 'declined' }
  if (s.duressActive) return { present: false, why: 'duress' }
  if (PROTECTED.some((re) => re.test(s.pathname))) return { present: false, why: 'busy' }
  // Not agreed yet: the screen is still opened — it shows the CONSENT, never the check, until the guard agrees.
  if (!s.consent.agreedAt) return { present: true, why: 'no_consent' }
  return { present: true, why: 'ok' }
}

/** What the server's answer means, in a guard's words. */
export function outcomeText(outcome: string | undefined): { tone: 'green-400' | 'amber-500' | 'red-500'; title: string; body: string } {
  if (outcome === 'passed') return { tone: 'green-400', title: 'Thanks, recorded', body: 'Your check-in is on the record for this shift.' }
  if (outcome === 'missed') return { tone: 'amber-500', title: 'This check closed', body: 'The two minutes ran out before your answer arrived, so it is recorded as missed. If you had no signal or were dealing with something, tell the control room: they can see why.' }
  return { tone: 'amber-500', title: 'Not recorded', body: 'The server did not record this check-in. Tell the control room if you were on post.' }
}
