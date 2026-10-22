/**
 * AN OLDER RECORD IS NEVER LOCKED OUT — IT IS ASKED, ONCE A SIGN-IN, POLITELY (M18C §5).
 *
 * Phone and email became required for everybody on the register (M18C). A resident added before that may have neither,
 * and nothing is refused them for it — a panic least of all. Instead `me.show`'s `missing_details` says what the
 * resident's own entry lacks, and Home asks for it with one card: dismissed, it stays away until the app is next opened.
 * Pure, so the words are tested without a phone.
 */

export type MissingDetailsPrompt = { title: string; body: string; action: string; missing: ('phone' | 'email')[] }

/** What the server's `missing_details` says, read defensively: only `phone` and `email` mean anything. */
export function missingOf(value: unknown): ('phone' | 'email')[] {
  if (!Array.isArray(value)) return []
  const found = value.filter((v): v is 'phone' | 'email' => v === 'phone' || v === 'email')
  return (['phone', 'email'] as const).filter((key) => found.includes(key))
}

export function missingDetailsPrompt(value: unknown): MissingDetailsPrompt | null {
  const missing = missingOf(value)
  if (missing.length === 0) return null
  const both = missing.length === 2
  const what = both ? 'a phone number and an email' : missing[0] === 'phone' ? 'a phone number' : 'an email'
  return {
    title: both ? 'Add your phone number and email' : missing[0] === 'phone' ? 'Add your phone number' : 'Add your email',
    body: `We need ${what} so we can reach you in an emergency. Nothing is held back until you do.`,
    action: both ? 'Add them now' : 'Add it now',
    missing,
  }
}

/*
 * Dismissed for this launch only — "ask again next time" (M18C §5) — and only for the person who dismissed it: module
 * memory, never the key-value store, and keyed by the account, so somebody else signing in on this phone is asked.
 */
let dismissedBy: string | null = null
export const missingDetailsDismissed = (who: string | number | null | undefined): boolean => who !== null && who !== undefined && dismissedBy === String(who)
export const dismissMissingDetails = (who: string | number | null | undefined): void => {
  dismissedBy = who === null || who === undefined ? null : String(who)
}
/** For the tests. */
export const resetMissingDetails = (): void => {
  dismissedBy = null
}
