/**
 * The short code printed under a visitor's QR pass — "PPV2-4471": the estate's prefix
 * (2–4 letters), the unit's phase number, a hyphen, four digits. The server matches it
 * exactly (upper-cased, WITH the hyphen), so the app puts the hyphen in: it always sits
 * before the last four digits, which is only knowable once a fifth digit has been typed.
 */

const MAX_LETTERS = 4
const MAX_DIGITS = 7 // a phase of up to three digits + the four

export function formatPassCode(input: string): string {
  const clean = input.toUpperCase().replace(/[^A-Z0-9]/g, '')
  const letters = (clean.match(/^[A-Z]*/)?.[0] ?? '').slice(0, MAX_LETTERS)
  const digits = clean.slice(letters.length).replace(/\D/g, '').slice(0, MAX_DIGITS)
  return digits.length >= 5 ? `${letters}${digits.slice(0, -4)}-${digits.slice(-4)}` : `${letters}${digits}`
}

export const isCompletePassCode = (code: string): boolean => /^[A-Z]{2,4}\d{1,3}-\d{4}$/.test(code)

/** What the keypad's ⌫ removes: the last CHARACTER the guard typed, never just the hyphen the app added. */
export const backspace = (code: string): string => formatPassCode(code.replace(/-/g, '').slice(0, -1))
