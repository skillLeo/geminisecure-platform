/**
 * Money, as the Resident App shows it — and ONLY shows it.
 *
 * The server sends every amount as a decimal STRING ("12400.00") precisely so that no
 * handset ever does arithmetic on it: a float would turn J$0.10 + J$0.20 into
 * 0.30000000000000004, and an app that computes a balance is an app that can disagree
 * with the ledger. So this file never parses an amount into a number. It moves characters:
 * a sign, thousands separators, and the cents as they were sent.
 *
 *   money('12400.00')            → "$12,400.00"   the boards' full form (totals, receipts)
 *   money('12400.00', 'compact') → "$12,400"      the boards' short form (cards, rows) — cents shown only when not zero
 *   money('-350.5')              → "-$350.50"     a credit
 *
 * The boards write "$", not "J$" (every amount in the app is Jamaican dollars; the currency
 * code is shown once, where a receipt needs it). The Guard App never imports this file:
 * a guard never sees money.
 */

export type MoneyStyle = 'full' | 'compact'

const SHAPE = /^\s*(-?)(\d+)(?:\.(\d+))?\s*$/

/** True when the amount is zero, negative or positive — by reading the string, not by arithmetic. */
export function moneySign(amount: string | null | undefined): -1 | 0 | 1 {
  const m = SHAPE.exec(amount ?? '')
  if (!m) return 0
  const isZero = /^0*$/.test(m[2]!) && /^0*$/.test(m[3] ?? '')
  return isZero ? 0 : m[1] === '-' ? -1 : 1
}

export function money(amount: string | null | undefined, style: MoneyStyle = 'full'): string {
  const m = SHAPE.exec(amount ?? '')
  // Not a decimal string: show what was sent rather than invent a figure.
  if (!m) return amount === null || amount === undefined || amount === '' ? '—' : String(amount)

  const whole = m[2]!.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  // Two places, as sent; a third place and beyond is kept rather than rounded — rounding is arithmetic.
  const cents = (m[3] ?? '').padEnd(2, '0')
  const negative = m[1] === '-' && moneySign(amount) !== 0
  const showCents = style === 'full' || !/^0+$/.test(cents)

  return `${negative ? '-' : ''}$${whole}${showCents ? `.${cents}` : ''}`
}
