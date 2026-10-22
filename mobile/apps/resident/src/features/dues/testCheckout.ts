/**
 * THE CARD CHECKOUT, IN DEMONSTRATION MODE (M12 §2.6, the client's instruction overriding D-077; the server's
 * D-179). Pure logic, no React, so every rule is unit-tested without a phone.
 *
 * A payment with a TEST card goes through to the estate: a real receipt from its sequence and a lower balance,
 * marked a demonstration everywhere, and no money moves (the server posts it to 1090, never to the bank). So:
 *
 *   - every screen of it says DEMONSTRATION, and the result says no money moved;
 *   - it accepts ONLY the card numbers Stripe publishes for testing (https://docs.stripe.com/testing). A real
 *     card number is refused and wiped from the field the moment it is recognised as one;
 *   - THE CARD NEVER LEAVES THE PHONE. The form is checked here; what is sent is only which test card it was —
 *     its brand and last four digits (useCardCheckout.ts). The fields live in the screen's memory and go when it
 *     closes; nothing is stored.
 *
 * The outcomes are Stripe's own for its test numbers, in its own words, decided again by the server from the same
 * list: declined, insufficient funds, expired card, incorrect security code.
 */

export type Brand = 'visa' | 'mastercard' | 'amex' | 'discover' | 'unknown'

export type TestOutcome = 'succeeded' | 'declined' | 'insufficient_funds' | 'expired_card' | 'incorrect_cvc'

export type TestCard = { number: string; brand: Brand; outcome: TestOutcome; label: string }

/** Stripe's published test numbers, and what each one does. Any future expiry, any CVC, any name. */
export const TEST_CARDS: readonly TestCard[] = [
  { number: '4242424242424242', brand: 'visa', outcome: 'succeeded', label: 'Visa · succeeds' },
  { number: '4000056655665556', brand: 'visa', outcome: 'succeeded', label: 'Visa (debit) · succeeds' },
  { number: '5555555555554444', brand: 'mastercard', outcome: 'succeeded', label: 'Mastercard · succeeds' },
  { number: '378282246310005', brand: 'amex', outcome: 'succeeded', label: 'American Express · succeeds' },
  { number: '6011111111111117', brand: 'discover', outcome: 'succeeded', label: 'Discover · succeeds' },
  { number: '4000000000000002', brand: 'visa', outcome: 'declined', label: 'Declined' },
  { number: '4000000000009995', brand: 'visa', outcome: 'insufficient_funds', label: 'Insufficient funds' },
  { number: '4000000000000069', brand: 'visa', outcome: 'expired_card', label: 'Expired card' },
  { number: '4000000000000127', brand: 'visa', outcome: 'incorrect_cvc', label: 'Incorrect security code' },
]

/** What the checkout says when a test card is refused — Stripe's own sentences. */
export const DECLINE_SENTENCE: Record<Exclude<TestOutcome, 'succeeded'>, string> = {
  declined: 'Your card was declined.',
  insufficient_funds: 'Your card has insufficient funds.',
  expired_card: 'Your card has expired.',
  incorrect_cvc: "Your card's security code is incorrect.",
}

export const NOT_A_TEST_CARD =
  "That is not one of Stripe's test cards, so it was cleared. This page is a test and only takes test numbers, like 4242 4242 4242 4242. Never enter a real card here."

export const TEST_MODE_NOTE =
  'Demonstration payment: test cards only. Use a test card such as 4242 4242 4242 4242, any future date and any security code. You get a real receipt and your balance goes down; no real money moves. Your card number never leaves this phone.'

/** The result, said plainly: a demonstration, real in the books and not in the bank. */
export const DEMONSTRATION_PAID =
  'This was a demonstration payment. No money moved. The estate issued a real receipt and your balance went down, and it stays that way until the estate reverses it. The receipt is under Payments.'

/** The last four digits the server is told — the whole number never leaves the phone. */
export const last4Of = (number: string): string => digitsOf(number).slice(-4)

export const digitsOf = (text: string): string => text.replace(/\D+/g, '')

export function brandOf(number: string): Brand {
  const d = digitsOf(number)
  if (/^4/.test(d)) return 'visa'
  if (/^(5[1-5]|2(2[2-9]|[3-6]\d|7[01]|720))/.test(d)) return 'mastercard'
  if (/^3[47]/.test(d)) return 'amex'
  if (/^(6011|65|64[4-9])/.test(d)) return 'discover'
  return 'unknown'
}

export const BRAND_NAME: Record<Brand, string> = { visa: 'Visa', mastercard: 'Mastercard', amex: 'American Express', discover: 'Discover', unknown: 'Card' }

/** How many digits a complete number has: 15 for American Express, 16 for the rest. */
export const lengthFor = (brand: Brand): number => (brand === 'amex' ? 15 : 16)
export const cvcLengthFor = (brand: Brand): number => (brand === 'amex' ? 4 : 3)

/** "4242424242424242" → "4242 4242 4242 4242" · American Express "3782 822463 10005". Never more than a whole number. */
export function formatCardNumber(text: string): string {
  const brand = brandOf(text)
  const d = digitsOf(text).slice(0, lengthFor(brand))
  if (brand === 'amex') return [d.slice(0, 4), d.slice(4, 10), d.slice(10, 15)].filter(Boolean).join(' ')
  return (d.match(/.{1,4}/g) ?? []).join(' ')
}

/** "1", "12", "123" → "1", "12 / ", "12 / 3" — and a lone first digit above 1 is a month: "4" → "04 / ". */
export function formatExpiry(text: string, previous = ''): string {
  let d = digitsOf(text).slice(0, 4)
  // Deleting the " / " takes the month's last digit with it, rather than the separator coming straight back.
  if (previous.endsWith(' / ') && text.length < previous.length) d = d.slice(0, 1)
  if (d.length === 1 && Number(d) > 1) d = `0${d}`
  if (d.length < 2) return d
  return `${d.slice(0, 2)} / ${d.slice(2)}`
}

/** The Luhn check every real card number passes. */
export function luhn(number: string): boolean {
  const d = digitsOf(number)
  if (d.length < 12) return false
  let sum = 0
  for (let i = 0; i < d.length; i++) {
    let n = Number(d[d.length - 1 - i])
    if (i % 2 === 1) {
      n *= 2
      if (n > 9) n -= 9
    }
    sum += n
  }
  return sum % 10 === 0
}

export const testCardFor = (number: string): TestCard | null => TEST_CARDS.find((card) => card.number === digitsOf(number)) ?? null

/**
 * A complete, valid card number that is NOT a test number: most likely a real card. The field is wiped.
 * (A complete number that fails Luhn is only a typo, and is left for the resident to correct.)
 */
export function isRealCard(number: string): boolean {
  const d = digitsOf(number)
  return d.length === lengthFor(brandOf(d)) && luhn(d) && testCardFor(d) === null
}

export type CardForm = { number: string; expiry: string; cvc: string; name: string }
export type CardField = keyof CardForm
export const EMPTY_CARD: CardForm = { number: '', expiry: '', cvc: '', name: '' }

/**
 * The first problem with each field, in Stripe's words. `today` is this month AT THE SITE (siteParts — never the
 * phone's own calendar), `month` 1–12: a card is good to the end of the month printed on it.
 */
export function cardErrors(form: CardForm, today: { year: number; month: number }): Partial<Record<CardField, string>> {
  const errors: Partial<Record<CardField, string>> = {}
  const number = digitsOf(form.number)
  const brand = brandOf(number)
  if (number.length === 0) errors.number = 'Enter your card number.'
  else if (number.length < lengthFor(brand)) errors.number = 'Your card number is incomplete.'
  else if (!luhn(number)) errors.number = 'Your card number is invalid.'
  else if (!testCardFor(number)) errors.number = NOT_A_TEST_CARD

  const expiry = digitsOf(form.expiry)
  if (expiry.length === 0) errors.expiry = 'Enter the expiry date.'
  else if (expiry.length < 4) errors.expiry = "Your card's expiration date is incomplete."
  else {
    const month = Number(expiry.slice(0, 2))
    const year = 2000 + Number(expiry.slice(2, 4))
    const thisYear = today.year
    const thisMonth = today.month
    if (month < 1 || month > 12) errors.expiry = "Your card's expiration month is invalid."
    else if (year < thisYear) errors.expiry = "Your card's expiration year is in the past."
    else if (year === thisYear && month < thisMonth) errors.expiry = "Your card's expiration date is in the past."
    else if (year > thisYear + 50) errors.expiry = "Your card's expiration year is invalid."
  }

  const cvc = digitsOf(form.cvc)
  if (cvc.length === 0) errors.cvc = 'Enter the security code.'
  else if (cvc.length < cvcLengthFor(brand)) errors.cvc = "Your card's security code is incomplete."

  if (form.name.trim().length < 2) errors.name = 'Enter the name on the card.'
  return errors
}

/** What the test gateway answers for a form with no errors. */
export const outcomeOf = (number: string): TestOutcome => testCardFor(number)?.outcome ?? 'declined'

/** "•••• 4242" */
export const lastFour = (number: string): string => `•••• ${digitsOf(number).slice(-4)}`

/** A reference that says what it is: "TEST-7F3K2Q". Never mistaken for an estate receipt number. */
export function testReference(seed: number): string {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  let n = Math.abs(Math.floor(seed)) || 1
  let out = ''
  for (let i = 0; i < 6; i++) {
    out += alphabet[n % alphabet.length]
    n = Math.floor(n / alphabet.length) + (i + 1) * 7919
  }
  return `TEST-${out}`
}
