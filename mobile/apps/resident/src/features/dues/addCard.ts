/**
 * Add a card — board resident-app-35. The form's pure logic: no React, no UI kit.
 *
 * NOTHING HERE IS KEPT OR SENT. There is no card capture on the server, and no card is ever stored (M12 §2.6). What is
 * typed into the board's form is kept to the design's own format, shown on the card at the top as the board shows it,
 * and dropped when the screen closes (useDues.ts `useAddCard`). "Save card" says that saving a card is not available
 * yet.
 */

/** The longest each field grows: 19 digits in fours (23), "MM / YY" (7), a security code (4), an embossed name (26). */
export const CARD_FIELD_MAX = { number: 23, expiry: 7, cvv: 4, holder: 26 } as const

export type CardField = keyof typeof CARD_FIELD_MAX

/** The card at the top of the board: the brand, the last four in the board's masked form, the holder's name. */
export type CardPreview = { brand: string | null; number: string | null; holder: string | null }

const digits = (text: string): string => text.replace(/\D/g, '')

/** "•••• •••• •••• " — the board's mask, before the last four. */
const MASK = '•••• •••• •••• '

/** What a field keeps of what was typed, in the board's format: "1234 5678 9012 0148", "MM / YY", "CVV". */
export function fieldText(field: CardField, text: string): string {
  switch (field) {
    case 'number':
      return digits(text).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 ')
    case 'expiry': {
      const d = digits(text).slice(0, 4)
      return d.length > 2 ? `${d.slice(0, 2)} / ${d.slice(2)}` : d
    }
    case 'cvv':
      return digits(text).slice(0, CARD_FIELD_MAX.cvv)
    case 'holder':
      return text.slice(0, CARD_FIELD_MAX.holder)
  }
}

/** The brand a card number's first digits name, written as the board writes "VISA" — none until they name one. */
export function cardBrand(number: string): string | null {
  const d = digits(number)
  if (/^4/.test(d)) return 'VISA'
  if (/^(5[1-5]|222[1-9]|22[3-9]\d|2[3-6]\d\d|27[01]\d|2720)/.test(d)) return 'MASTERCARD'
  if (/^3[47]/.test(d)) return 'AMEX'
  if (/^(6011|65|64[4-9])/.test(d)) return 'DISCOVER'
  return null
}

/** The card at the top, from what is typed: each part only once there is something to show. */
export function cardPreview(number: string, holder: string): CardPreview {
  const d = digits(number)
  const name = holder.trim()
  return {
    brand: cardBrand(d),
    number: d.length >= 4 ? `${MASK}${d.slice(-4)}` : null,
    holder: name ? name.toUpperCase() : null,
  }
}
