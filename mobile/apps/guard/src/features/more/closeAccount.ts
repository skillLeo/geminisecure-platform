/**
 * "Closing your account" in the Guard App — the stores' requirement, answered honestly for THIS app.
 *
 * WHY THIS IS NOT A DELETE BUTTON. Apple requires in-app deletion of accounts the app lets people
 * CREATE. This app creates none: an officer's account is issued by their employer, a supervisor
 * hands out the enrolment code, and the record behind it is an EMPLOYMENT record that employment
 * and tax law says must be kept. An officer cannot delete their own employment record, and a button
 * that claimed to would be a lie. Apple's own guidance for exactly this case is to point the person
 * at where it can be done — so this screen does that, in plain words, and offers the one thing that
 * IS the officer's to decide and that the app really can do:
 *
 *   REMOVE THIS APP'S ACCESS FROM THIS PHONE — the token is destroyed, everything the app kept on the
 *   phone goes, and the phone keeps only that the officer removed it, so it is not signed back into with
 *   a fingerprint as an ordinary sign-out is (M32 F3): setting it up again takes a code. It is the
 *   sign-out path, said plainly and reachable from the same place a person looks for "delete".
 *
 * The Resident App, where a person DOES create their own account, has a real in-app deletion
 * (apps/resident/src/features/settings/deleteAccount.ts).
 */

export const TITLE = 'Closing your account'

export const WHOSE_ACCOUNT = [
  'Your GeminiSecure account is issued by your employer, not created by you: a supervisor issues the code that sets it up, and the record behind it is your employment record.',
  'Employment and tax law say how long that record is kept, so it is not something this app can delete, and not something an officer can delete for themselves.',
]

/** What the person is told to do, and who can do it. */
export const HOW = [
  'To close your account, speak to your supervisor or the office. They can end it, and it stops working on every phone at once.',
  'If a phone is lost or stolen, tell your supervisor now: they revoke it immediately, which is faster and safer than anything on the phone itself.',
]

/** The one thing here the officer can really do. */
export const REMOVE_FROM_PHONE = {
  title: 'Remove this app from this phone',
  body: 'This signs you out and deletes everything GeminiSecure keeps on this phone. Your account is untouched: coming back needs a new enrolment code from a supervisor.',
}

/** Anybody may read these before, during and after; they need no sign-in. */
export const LEGAL_LEAD = 'What is kept about you, and why, is set out in the Privacy Policy.'
