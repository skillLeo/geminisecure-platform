/**
 * SIGNING IN TO A DELETED ACCOUNT (M18H §2.3, §2.4) — the words, and what the server said.
 *
 * Deleting an account is a state it can come back from. When somebody signs in to one, the server proves the code and
 * then refuses the token with `409 account_deleted`, carrying the dates on the estate's calendar and — while the
 * person's own window is open — a ticket that "Restore my account" sends to `auth.restore`. Nothing is restored until
 * they press it.
 *
 *   days 1–7    This account was deleted on 27 Sep 2026.
 *               You can restore it until 4 October and nothing will be lost.     [ Restore my account ]   Not now
 *   days 8–30   This account was deleted on 27 Sep 2026.
 *               Only the estate office can restore it now. Contact them and they can bring it back until 27 Oct 2026.
 *                                                                                 [ Contact the estate office ]
 *
 * After day 30 there is nothing to say: the server signs the address in as anybody new.
 */

import type { ApiError } from '@gs/core/api'

export type DeletedAccount = {
  /** Estate-calendar dates, `YYYY-MM-DD`. */
  deletedOn: string
  restoreUntil: string
  officeUntil: string
  /** The person's own window is open: the ticket below restores it. */
  canRestore: boolean
  ticket: string | null
  officeEmail: string | null
  officePhone: string | null
}

const text = (v: unknown): string | null => (typeof v === 'string' && v.trim() !== '' ? v : null)
const DAY = /^\d{4}-\d{2}-\d{2}$/

/** What `409 account_deleted` (or `restore_office_only`) carried — or null when it is some other refusal. */
export function deletedAccountOf(e: Pick<ApiError, 'code' | 'details'>): DeletedAccount | null {
  if (e.code !== 'account_deleted' && e.code !== 'restore_office_only') return null
  const d = e.details ?? {}
  const deletedOn = text(d.deleted_on)
  const restoreUntil = text(d.restore_until)
  const officeUntil = text(d.office_until)
  if (!deletedOn || !DAY.test(deletedOn) || !restoreUntil || !officeUntil) return null
  const ticket = text(d.ticket)
  return {
    deletedOn,
    restoreUntil,
    officeUntil,
    canRestore: d.can_restore === true && ticket !== null,
    ticket: d.can_restore === true ? ticket : null,
    officeEmail: text(d.office_email),
    officePhone: text(d.office_phone),
  }
}

/* The one deleted account this sign-in found, held in memory between the code screen and the restore screen — never
 * in a URL, never on disk: the ticket is a proof of the code. */
let held: DeletedAccount | null = null
export const deletedAccount = {
  get: (): DeletedAccount | null => held,
  set: (next: DeletedAccount | null) => (held = next),
  clear: () => (held = null),
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/**
 * "2026-09-27" → "27 Sep 2026" — a calendar date in the one form (M98, 12 Oct 2026; it read "27 September"), with no
 * clock and no zone to shift it.
 */
export function dayMonth(date: string): string {
  const [y, m, d] = date.split('-').map((n) => Number.parseInt(n, 10))
  return MONTHS[(m ?? 1) - 1] && d && y ? `${d} ${MONTHS[(m as number) - 1]} ${y}` : date
}

export type RestoreCopy = { title: string; body: string; action: 'restore' | 'contact'; actionLabel: string; notNow: string | null }

/** §2.3 and §2.4, word for word. */
export function restoreCopy(d: DeletedAccount): RestoreCopy {
  const title = `This account was deleted on ${dayMonth(d.deletedOn)}.`
  return d.canRestore
    ? { title, body: `You can restore it until ${dayMonth(d.restoreUntil)} and nothing will be lost.`, action: 'restore', actionLabel: 'Restore my account', notNow: 'Not now' }
    : { title, body: `Only the estate office can restore it now. Contact them and they can bring it back until ${dayMonth(d.officeUntil)}.`, action: 'contact', actionLabel: 'Contact the estate office', notNow: null }
}

/** Where "Contact the estate office" goes: the office's email first, its phone next — or nowhere the app can open. */
export function officeLink(d: DeletedAccount): { url: string; label: string } | null {
  if (d.officeEmail) return { url: `mailto:${d.officeEmail}?subject=${encodeURIComponent('Please restore my Resident App account')}`, label: d.officeEmail }
  if (d.officePhone) return { url: `tel:${d.officePhone.replace(/[^\d+]/g, '')}`, label: d.officePhone }
  return null
}

/** What the restore screen says when `auth.restore` refuses. */
export function restoreProblem(e: Pick<ApiError, 'code' | 'message' | 'status'>): string {
  if (e.status === 'network' || e.status === 'timeout') return "You're offline, or the server can't be reached. Restoring needs a connection: check it and try again."
  return e.message
}

/** Said once, on the first screen after a restore. */
export const RESTORED = 'Your account is back: your home, your household and your history are where they were.'

/** "Say so once" (auth.restore): set by the restore, taken — and so cleared — by the first Home that shows it. */
let restoredPending = false
export const restoredNotice = {
  set: () => (restoredPending = true),
  take: (): boolean => {
    const was = restoredPending
    restoredPending = false
    return was
  },
}
