import { DEMO_CANNOT_WRITE } from '@/features/auth/demoWrites'
import { dayMonth } from '@/features/auth/restore'

/**
 * Deleting your account — the words, and the one rule (M5 §4, M18H §2.1).
 *
 * Both app stores require an in-app way to delete an account, and both allow records to be KEPT
 * "for legitimate reasons such as security, fraud prevention or regulatory compliance" — **when
 * clearly disclosed**. So the disclosure is the feature: what stops, what stays, why, and for how
 * long, ITEMISED, in front of the person BEFORE they confirm. Nothing here is vague and nothing
 * here is a promise the server does not keep.
 *
 * DELETING IS A STATE IT CAN COME BACK FROM (M18H). Everything stops at once; nothing of the person
 * is erased until the estate's window closes — they restore it themselves by signing in for the
 * first days, the estate office after that. The screen says so, with this estate's own numbers, and
 * says what the home owes, because a debt does not go with the account. The word is still typed,
 * not tapped.
 */

/** The word the person types. Sent to the server, which refuses the deletion without it. */
export const CONFIRMATION = 'DELETE'

export const confirmationTyped = (typed: string): boolean => typed.trim().toUpperCase() === CONFIRMATION

export type Disclosure = { title: string; items: string[] }

/** Stops the moment they confirm (M18H §1.3). */
export const DELETED_NOW: Disclosure = {
  title: 'Stops straight away',
  items: [
    'Your way in: you are signed out of this app on every phone, at once.',
    'Your e-pass stops working at the gate, and so does every visitor pass you sent that has not been used: a visitor who shows one is told it no longer works.',
    'Your future amenity bookings are cancelled, and any deposit you paid is refunded.',
    'Notifications and emails stop: except one email telling you the account was deleted, and until when you can restore it.',
    "Your name comes off the estate's list of current residents.",
    'Anything saved on this phone: your settings, your drafts, and the copies the app kept so it works without a signal.',
  ],
}

/** What stays with the home — and why (M18H §1.4). Every line is something the app can point at on the server. */
export const KEPT: Disclosure = {
  title: 'Stays with your home, and why',
  items: [
    "What your home owes, and every charge, payment and receipt. They belong to the HOME, not to the account, so deleting the account does not clear a debt: it stays on the home's account with the estate.",
    "The gate and security record: visitors admitted, passes used, alerts, reports. It is the community's security record, kept for up to 7 years.",
    'Your household and its other members: deleting your app account does not move anybody else out. Your own register entry is kept by the estate office, off the list of current residents, for as long as the account can be restored.',
    'Votes already cast: kept because a vote is stored with nothing that links it to your household, so there is nothing of yours in it to delete.',
  ],
}

/** This estate's windows, from `me.show`; the platform's defaults until it has answered. */
export type Windows = { self_days: number; office_days: number }
export const DEFAULT_WINDOWS: Windows = { self_days: 7, office_days: 30 }

const days = (n: number): string => (n === 1 ? '1 day' : `${n} days`)

/** "Changed your mind?" — how it comes back, and when it cannot (M18H §2.1). */
export function restoreLine(w: Windows = DEFAULT_WINDOWS): string {
  return `Changed your mind? Sign in again with the same email within ${days(w.self_days)} and the app offers to restore everything. After that, the estate office can restore it until day ${w.office_days}. After ${days(w.office_days)} it cannot be undone: nothing of you is left on the account.`
}

/** "If they owe money, it says so and says the debt does not go away." Null when the home owes nothing. */
export function debtLine(owed: string | null): string | null {
  return owed === null ? null : `Your home owes ${owed}. That does not go away when the account does: it stays on the home's account with the estate.`
}

/** What the screen says when it cannot be done right now. */
export const NEEDS_CONNECTION = 'Deleting your account needs a connection. It is never saved to happen later: it happens while you watch, or not at all.'

/**
 * "EXPLORE A DEMO COMMUNITY" HAS NO ACCOUNT TO DELETE.
 *
 * THE SAME SENTENCE AS EVERY OTHER REFUSED ACT, and deliberately not one of its own. A reviewer
 * meets this idea a dozen times in one session — invite a visitor, cast the ballot, report a
 * problem, delete the account — and ten different wordings of it read as ten different faults
 * rather than one consistent rule. `features/auth/demoWrites.ts` holds it and the reasoning.
 */
export { DEMO_CANNOT_WRITE as DEMONSTRATION } from '@/features/auth/demoWrites'

/**
 * The server's refusal, as the sentence the person reads — the one place that decision is made.
 *
 * Everything but the demonstration case is shown AS THE SERVER WROTE IT: a deletion is the last
 * thing this app should be paraphrasing, and `demo_account_kept` (the reviewer's own account)
 * already arrives as a full explanation of what would have happened on a real one.
 */
export function deleteProblem(input: { demo: boolean; offline: boolean; code: string | null; message: string }): string {
  if (input.offline) return NEEDS_CONNECTION
  if (input.demo && input.code === 'missing_ability') return DEMO_CANNOT_WRITE
  return input.message.trim() === '' ? 'That did not go through. Nothing was deleted.' : input.message
}

/**
 * The screen after (M18H §2.2): the reference, and the two dates in words — until when signing in restores it, and after
 * when it cannot be undone. `restoreUntil` and `permanentOn` are the estate-calendar dates the server sent.
 */
export function AFTER(reference: string, restoreUntil?: string | null, permanentOn?: string | null): string {
  const keep = `Your reference is ${reference}: keep it if you ever need to ask us about this.`
  if (!restoreUntil || !permanentOn) return `Your account is deleted. ${keep}`
  return `Your account is deleted. You can restore it by signing in with the same email until ${dayMonth(restoreUntil)}; after that the estate office can, until ${dayMonth(permanentOn)}. After ${dayMonth(permanentOn)} it cannot be undone. ${keep}`
}

/** The same thing, without the app, for somebody who has uninstalled it. Derived from the one server address. */
export const ALSO_ON_THE_WEB = 'You can also ask for this without the app, on our website.'
