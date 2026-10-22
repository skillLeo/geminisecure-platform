/**
 * PAY MAINTENANCE FEE — the logic behind board 10 (M42 §5 #9), held to the board's words and to the truth:
 *   - the full-balance option and the button are the ledger's balance, in the board's two forms, never worked out;
 *   - the line under the full balance says only what paying it does: "Clears both overdue months" when two monthly
 *     dues are overdue, and otherwise the true sentence in the same words;
 *   - the part-payment field keeps an amount and nothing else, and the button says that amount;
 *   - Pay's one sentence is about the gateway, and claims no payment.
 */

import type { Charge } from '../duesLogic'
import { CARDS_OFF, CLEARS_BALANCE, amountOf, autopayLine, boardDate, cleanAmount, clearsLine, fullBalanceTitle, ordinal, payLabel, savedCardMethod, savedCardOf } from '../pay'

// 9:41 on Friday 4 September 2026 in Jamaica (UTC−5) — the boards' moment.
const AT = new Date('2026-09-04T14:41:00Z')

const charge = (over: Partial<Charge> = {}): Charge => ({
  id: 3, reference: 'DUES-2026-09-L47', type: 'dues', period: '2026-09', description: 'Maintenance fee: September', amount: '6200.00', outstanding: '6200.00', due_on: '2026-09-28', status: 'due', ...over,
})
const september = charge()
const august = charge({ id: 2, reference: 'DUES-2026-08-L47', period: '2026-08', description: 'Maintenance fee: August', due_on: '2026-08-01', status: 'overdue' })
const july = charge({ id: 1, reference: 'DUES-2026-07-L47', period: '2026-07', description: 'Maintenance fee: July', due_on: '2026-07-01', status: 'overdue' })
const fine = charge({ id: 7, reference: 'FINE-2026-08-L47', type: 'fine', period: null, description: 'Noise complaint fine', due_on: '2026-08-15', status: 'overdue' })

describe("the amounts are the ledger's, in the board's two forms", () => {
  it('"Pay full balance: $12,400" and "Pay $12,400.00"', () => {
    expect(fullBalanceTitle('12400.00')).toBe('Pay full balance: $12,400')
    expect(payLabel('12400.00')).toBe('Pay $12,400.00')
  })

  it('cents are shown when there are some, never rounded away', () => {
    expect(fullBalanceTitle('12400.50')).toBe('Pay full balance: $12,400.50')
    expect(payLabel('12400.5')).toBe('Pay $12,400.50')
  })
})

describe('the line under the full balance says only what is true', () => {
  it("two overdue months: the board's own words", () => {
    expect(clearsLine([september, august, july], AT)).toBe('Clears both overdue months')
  })

  it('one overdue month, or three', () => {
    expect(clearsLine([september, august], AT)).toBe('Clears 1 overdue month')
    expect(clearsLine([september, august, july, charge({ id: 0, period: '2026-06', due_on: '2026-06-01', status: 'overdue' })], AT)).toBe('Clears all 3 overdue months')
  })

  it('nothing overdue, not yet due, or due today, and the full balance clears the balance', () => {
    expect(clearsLine([september], AT)).toBe(CLEARS_BALANCE)
    expect(clearsLine([charge({ due_on: '2026-09-04' })], AT)).toBe(CLEARS_BALANCE)
    expect(clearsLine([], AT)).toBe(CLEARS_BALANCE)
    expect(CLEARS_BALANCE).toBe('Clears your balance')
  })

  it('a paid charge is not overdue, whatever its date; a part-paid one past its date is', () => {
    expect(clearsLine([september, { ...august, outstanding: '0.00', status: 'paid' }], AT)).toBe(CLEARS_BALANCE)
    expect(clearsLine([{ ...august, outstanding: '1200.00', status: 'part_paid' }], AT)).toBe('Clears 1 overdue month')
  })

  it('a month is counted once, however many charges carry it', () => {
    expect(clearsLine([august, { ...august, id: 9, reference: 'DUES-2026-08-L47-B' }], AT)).toBe('Clears 1 overdue month')
  })

  it('anything that is not a monthly due is a charge, never called a month', () => {
    expect(clearsLine([fine], AT)).toBe('Clears 1 overdue charge')
    expect(clearsLine([august, fine], AT)).toBe('Clears both overdue charges')
    expect(clearsLine([july, august, fine], AT)).toBe('Clears all 3 overdue charges')
    // A dues charge that names no month cannot be called one.
    expect(clearsLine([{ ...august, period: null }], AT)).toBe('Clears 1 overdue charge')
  })

  it('until the charges have answered, only what the full balance always does', () => {
    expect(clearsLine(null, AT)).toBe(CLEARS_BALANCE)
  })

  it("overdue is counted on Jamaica's calendar, late in the evening too", () => {
    // 8 PM on 4 September in Kingston is 01:00 UTC on the 5th: a charge due on the 4th is still due today.
    const evening = new Date('2026-09-05T01:00:00Z')
    expect(clearsLine([charge({ due_on: '2026-09-04' })], evening)).toBe(CLEARS_BALANCE)
    expect(clearsLine([charge({ due_on: '2026-09-03' })], evening)).toBe('Clears 1 overdue month')
  })
})

describe('the part-payment field keeps an amount, and the button says it', () => {
  it('keeps digits and one decimal point, two places at most', () => {
    expect(cleanAmount('5,000')).toBe('5000')
    expect(cleanAmount('$5000.555')).toBe('5000.55')
    expect(cleanAmount('12.3.4')).toBe('12.34')
    expect(cleanAmount('abc')).toBe('')
    expect(cleanAmount('1234567890123')).toBe('123456789')
  })

  it('reads an amount above zero, or nothing', () => {
    expect(amountOf('5000')).toBe('5000')
    expect(amountOf('5000.')).toBe('5000')
    expect(amountOf('.5')).toBe('0.5')
    for (const nothing of ['', '0', '0.00', '.']) expect(amountOf(nothing)).toBeNull()
  })

  it('"Pay $5,000.00", and "Pay" alone while nothing is entered', () => {
    expect(payLabel(amountOf('5000'))).toBe('Pay $5,000.00')
    expect(payLabel(amountOf(''))).toBe('Pay')
  })
})

describe('what Pay says (M50 §4.2 #3, §6.6)', () => {
  it('with card payments off, says how to pay instead, and nothing about a payment having been made', () => {
    expect(CARDS_OFF).toMatch(/Bank transfer or Cash agent/)
    expect(CARDS_OFF).not.toMatch(/success|recorded|received|paid|demonstration|not integrated|not available/i)
  })

  it('names a saved card as the board does, and finds the card a row names', () => {
    const card = { id: 7, brand: 'visa', last4: '4242', label: 'Visa •••• 4242', expires: '08/30', default: true, expired: false }
    expect(savedCardMethod(card)).toEqual({ key: 'card:7', title: 'Visa •••• 4242', sub: 'Saved card · expires 08/30' })
    expect(savedCardOf('card:7', [card])).toBe(card)
    expect(savedCardOf('card:8', [card])).toBeNull()
    expect(savedCardOf('bank', [card])).toBeNull()
  })

  it('writes AutoPay\'s terms and dates as the board does', () => {
    expect(autopayLine('15000.00', 28)).toBe('Automatically pay up to $15,000 on the 28th each month.')
    expect([1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 28].map(ordinal)).toEqual(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '23rd', '28th'])
    // The one form (M98, the consoles' "9 Oct 2026").
    expect(boardDate('2026-09-28')).toBe('28 Sep 2026')
    expect(boardDate('2026-10-05')).toBe('5 Oct 2026')
  })
})
