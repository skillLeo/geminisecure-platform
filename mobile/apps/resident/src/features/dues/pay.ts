/**
 * Pay maintenance fee — board resident-app-10 (M42 §5 #9). The Pay screen's pure logic: no React, no UI kit, so it is
 * unit-tested without a phone (__tests__/pay.test.ts).
 *
 * WHERE PAYMENT HAPPENS (M42 E6; the user's Q6, 7 October). Real money: not in the app — the client will give a link,
 * and Pay will open the client's own payment page in the browser. No card form is on the way (the client: "we have no
 * need to add any 4242 input like that").
 *
 * THE DEMONSTRATION PAYMENT (the user, 7 October, for the client's call: "tap Pay → demonstration payment recorded →
 * Payment successful" — a dead end looks unfinished). While the SERVER says its demonstration checkout is on — ONE
 * answer, `cards.index`'s `available` (M50 §6.6: "fix it in one place"), which is `DemoCardPayments::enabled()` on a
 * demonstration server and never at launch — Pay offers the resident's SAVED test cards first (M50 §4.2 #3: "Save
 * card" works), or, with none saved, DEMO_CARD, which says on its own row that it is a demonstration and that no money
 * moves. Pay with a card sends `payments.card` with that test card and opens Payment successful on the estate's
 * receipt. What that records is the server's demonstration (DemoCardPayments, D-179): a real receipt from the estate's
 * sequence and a lower balance, posted to 1090 and never to the bank, marked "Demonstration card payment" — and undone,
 * after the demonstration, by `payments:reverse-demonstrations`. With Bank transfer or Cash agent chosen, Pay shows
 * the estate's own instructions and the reference to quote (`invoices.pay_intent`) — never a dead end, and never
 * "Payment successful" when nothing was recorded. The hosted gateway (`payments.intents.store`) is switched off until a
 * card provider is connected.
 *
 * AMOUNTS ARE THE LEDGER'S. "Pay full balance — $12,400" and "Pay $12,400.00" are the household's balance
 * (`households.balance` — what the Dues screen shows), put through money() and never worked out.
 *
 * THE LINE UNDER THE FULL BALANCE says only what paying it does. Paying the whole balance clears every charge, so it
 * clears every OVERDUE one: a charge with something still owed on it whose due date has passed on the estate's
 * calendar. Counted —
 *   none overdue                                → "Clears your balance"
 *   all of them monthly dues (`dues` + a month) → months, each month once: "Clears 1 overdue month",
 *                                                 "Clears both overdue months", "Clears all 3 overdue months"
 *   any other charge among them (a fine, an assessment, an amenity fee) → charges, in the same words:
 *                                                 "Clears 1 overdue charge", "Clears both overdue charges", …
 * Until the charges have answered, "Clears your balance" — true of the full balance whatever they say.
 */

import { money, moneySign } from '@gs/core/lib'
import { daysUntil, type Charge } from './duesLogic'

/** What Pay says when card payments are off for the estate and a card row is somehow chosen. */
export const CARDS_OFF = 'Card payments are not switched on for this estate. Choose Bank transfer or Cash agent to see how to pay.'

export const CLEARS_BALANCE = 'Clears your balance'

export type PayChoice = 'full' | 'part'

/** `card` is the demonstration card (or the design's own, in fidelity mode); `card:<id>` one of the resident's saved cards. */
export type MethodKey = 'card' | 'bank' | 'cash' | `card:${number}`
export type PayMethod = { key: MethodKey; title: string; sub: string }

/** A saved card, as `cards.index` draws one. */
export type SavedCard = { id: number; brand: string; last4: string; label: string; expires: string; default: boolean; expired: boolean }

/** A saved card's row on Pay: the board's "Visa •••• 4417 · Saved card · expires 08/28". */
export const savedCardMethod = (card: SavedCard): PayMethod => ({ key: `card:${card.id}`, title: card.label, sub: `Saved card · expires ${card.expires}` })

/** The saved card a method names, or null. */
export const savedCardOf = (key: MethodKey, cards: readonly SavedCard[]): SavedCard | null =>
  key.startsWith('card:') ? (cards.find((card) => `card:${card.id}` === key) ?? null) : null

/** 1 → "1st", 2 → "2nd", 3 → "3rd", 11 → "11th", 22 → "22nd", 28 → "28th". */
export function ordinal(day: number): string {
  const teen = day % 100 >= 11 && day % 100 <= 13
  const suffix = teen ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' } as Record<number, string>)[day % 10] ?? 'th'
  return `${day}${suffix}`
}

/** The board's AutoPay line: "Automatically pay up to $15,000 on the 28th each month." */
export const autopayLine = (limit: string, day: number): string => `Automatically pay up to ${money(limit, 'compact')} on the ${ordinal(day)} each month.`

/** A calendar date (YYYY-MM-DD) in the one form (M98, the consoles'; the board wrote "Sep 28, 2026"): "28 Sep 2026". No clock to shift it. */
export function boardDate(day: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day)
  if (!m) return day
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
  return `${Number(m[3])} ${months[Number(m[2]) - 1] ?? ''} ${m[1]}`
}

export const BANK_TRANSFER: PayMethod = { key: 'bank', title: 'Bank transfer', sub: 'NCB, Scotiabank, JMMB & more' }
export const CASH_AGENT: PayMethod = { key: 'cash', title: 'Cash agent', sub: 'Pay in person, reference at checkout' }

/** The demonstration card's row: the board's card row, saying on it what it is — never a saved card that is not there. */
export const DEMO_CARD: PayMethod = { key: 'card', title: 'Visa •••• 4242', sub: 'Demonstration card · no money moves' }

/** Which of Stripe's test cards the demonstration pays with — the server's `visa:4242`, which succeeds. Never a real card. */
export const DEMO_TEST_CARD = { brand: 'visa', last4: '4242' } as const

/** The server's own sentence for a payment with no amount (DemoCardPayments::payDues). */
export const CHOOSE_AN_AMOUNT = 'Choose an amount to pay.'

/** True only when the server says its demonstration checkout is on — a demonstration server, never at launch. */
export const demoCheckoutOn = (intent: { online_payment_available?: boolean; card_mode?: string | null } | null | undefined): boolean =>
  intent?.online_payment_available === true && intent.card_mode === 'demonstration'

/** "Pay full balance — $12,400" — the board's short form: cents only when there are some. */
export const fullBalanceTitle = (balance: string): string => `Pay full balance: ${money(balance, 'compact')}`

const MONTH = /^\d{4}-\d{2}$/

export function clearsLine(charges: readonly Charge[] | null, at: Date): string {
  const overdue = (charges ?? []).filter((c) => moneySign(c.outstanding) > 0 && (daysUntil(c.due_on, at) ?? 0) < 0)
  if (overdue.length === 0) return CLEARS_BALANCE
  const monthly = overdue.every((c) => c.type === 'dues' && MONTH.test(c.period ?? ''))
  const n = monthly ? new Set(overdue.map((c) => c.period)).size : overdue.length
  const what = monthly ? 'month' : 'charge'
  if (n === 1) return `Clears 1 overdue ${what}`
  if (n === 2) return `Clears both overdue ${what}s`
  return `Clears all ${n} overdue ${what}s`
}

/**
 * What the part-payment field keeps of what was typed: digits and ONE decimal point, two places after it at most, nine
 * digits before it at most (the most the server reads). "5,000" → "5000"; "12.345" → "12.34".
 */
export function cleanAmount(text: string): string {
  const [whole = '', ...rest] = text.replace(/[^\d.]/g, '').split('.')
  const dollars = whole.slice(0, 9)
  return rest.length === 0 ? dollars : `${dollars}.${rest.join('').slice(0, 2)}`
}

/** The typed amount as a decimal string — "5000", "0.5" — or null when it is not an amount above zero. */
export function amountOf(text: string): string | null {
  const m = /^(\d*)(?:\.(\d{0,2}))?$/.exec(text.trim())
  if (!m || (m[1] === '' && !m[2])) return null
  const amount = `${m[1] || '0'}${m[2] ? `.${m[2]}` : ''}`
  return moneySign(amount) > 0 ? amount : null
}

/** "Pay $12,400.00" — the board's full form; "Pay" alone while no amount has been entered. */
export const payLabel = (amount: string | null): string => (amount ? `Pay ${money(amount)}` : 'Pay')
