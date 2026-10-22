/**
 * SHOWN ONCE (M47 §3) — the one place that says whether something meant to appear once has been shown.
 *
 * The tour and the "This estate is on Jamaica time" card came back at every sign-in: "seen" was written to the phone's
 * own key-value store, and every sign-out the person chose, every reinstall and every new phone wipes that store (the
 * thirty-minute sign-out kept only a short list). Each "once" is now kept where it survives:
 *
 *   for the ACCOUNT   the first-run tour. The record is the account's, on the server: `me.show` says what the account
 *                     has been shown (`account.shown`), `me.shown` records it, and the first time stands. Shown on the
 *                     first ever sign-in and never again — not after a sign-out, a reinstall, or on another phone.
 *                     Finishing it and skipping it both count.
 *   for the PHONE     "Before we get started" (board 29) asks THIS phone for its permissions, so it is done once the
 *                     phone has answered each of them — an answer the phone keeps through every sign-out and drops,
 *                     rightly, with a reinstall (`permissionsAsked`, features/auth/usePermissions.ts).
 *
 * The Jamaica-time card is gone (M47 §4.2). "Your account is back" is said once after a restore, from memory, and the
 * missing-details card asks once a launch on purpose: neither is a record that can be lost.
 *
 * UNTIL THE SERVER THAT KEEPS THE ACCOUNT'S RECORD IS DEPLOYED, `me.show` carries no `account.shown`, and the phone's own
 * record decides, exactly as before — nothing is worse in between.
 */

import type { Api } from '@gs/core/api'

/** What is shown once per account, by the name `me.shown` takes. */
export type AccountOnce = 'tour'

export type AccountShown = Partial<Record<AccountOnce, string | null>>

/** The account's record, from `me.show`; null when this server keeps none yet. */
export function accountShown(me: Api.MeShowResponse | null | undefined): AccountShown | null {
  const shown = (me?.account as { shown?: unknown } | undefined)?.shown
  return shown !== null && typeof shown === 'object' ? (shown as AccountShown) : null
}

/** Show it, it is done, or wait for the account's answer — so nothing flashes up and vanishes when it arrives. */
export type OnceVerdict = 'show' | 'done' | 'wait'

/**
 * From the account's record and this phone's own:
 *   the account has it              done
 *   the account does not, but this phone saw it finished (the account was not told yet: no signal, an older server)
 *                                   done — and the account is told (`tellAccount`)
 *   the account does not            show
 *   no record (an older server, or `me.show` could not be asked)    this phone's record, as before
 *   no answer yet                   wait
 */
export function onceVerdict(input: { key: AccountOnce; record: AccountShown | null; answered: boolean; seenHere: boolean }): OnceVerdict {
  if (input.record !== null) return input.record[input.key] || input.seenHere ? 'done' : 'show'
  if (!input.answered) return 'wait'
  return input.seenHere ? 'done' : 'show'
}

/** This phone saw it done and the account's record does not say so yet: the account is told. */
export const tellAccount = (input: { key: AccountOnce; record: AccountShown | null; seenHere: boolean }): boolean =>
  input.record !== null && !input.record[input.key] && input.seenHere
