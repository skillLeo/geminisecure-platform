/**
 * Dues & payments (board resident-app-09, M42 §5 #8): the balance card, its four ageing boxes, and the statement's rows.
 *
 *   - the ageing is the ONE sum the phone does: whole cents added as integers, never floats — and it is shown only when
 *     it comes to the ledger's balance to the cent; when it cannot, no figure is shown at all;
 *   - the rule — CURRENT (not yet past due) · 30 DAYS (1–30 past due) · 60 DAYS (31–60) · 90+ DAYS (over 60) — at every edge;
 *   - the gold line says months once a whole month has passed, days before that, and when the next charge falls due;
 *   - the formats: "$12,400.00" on the card, "$6,200" in a box or a row, "28 Sep 2026" (M98's one form), never "JMD".
 *
 * The seeded ledger the client sees (EstateFinanceSeeder): monthly dues of $6,200 due on the 1st, Lot 47 owing two months.
 */
import type { Api } from '@gs/core/api'
import type { Charge, StatementLine } from '../duesLogic'
import { AGEING_LABELS, ageBucket, ageing, balanceView, dueLine, fromCents, monthDay, monthsSince, statementRows, toCents } from '../statement'

// 9:41 AM on Thursday 8 October 2026 in Jamaica (UTC−5): the morning of the client's call.
const AT = new Date('2026-10-08T14:41:00Z')
// 9:41 AM on Friday 4 September 2026 in Jamaica: the boards' own moment.
const BOARD_AT = new Date('2026-09-04T14:41:00Z')

const charge = (over: Partial<Charge> = {}): Charge => ({
  id: 1, reference: 'CHG-2026-10-0047', type: 'dues', period: '2026-10', description: 'Maintenance fee: October', amount: '6200.00', outstanding: '6200.00', due_on: '2026-10-01', status: 'overdue', ...over,
})

const balance = (over: Partial<Api.HouseholdsBalanceResponse> = {}): Api.HouseholdsBalanceResponse => ({
  household_id: 7, unit: 'Lot 47', balance: '12400.00', currency: 'JMD', as_at: '2026-10-08', oldest_unpaid_due_on: '2026-09-01', days_overdue: 37, on_payment_plan: false, plan: null, pay_now: { amount: '12400.00', due_on: '2026-09-01', reason: 'overdue' }, access_restricted: false, ...over,
})

/** Lot 47 as seeded: October and September open, August and before paid. Newest first, as `invoices.index` sends them. */
const SEEDED: Charge[] = [
  charge({ id: 6 }),
  charge({ id: 5, reference: 'CHG-2026-09-0047', period: '2026-09', description: 'Maintenance fee: September', due_on: '2026-09-01' }),
  charge({ id: 4, reference: 'CHG-2026-08-0047', period: '2026-08', description: 'Maintenance fee: August', due_on: '2026-08-01', outstanding: '0.00', status: 'paid' }),
  charge({ id: 3, reference: 'CHG-2026-07-0047', period: '2026-07', description: 'Maintenance fee: July', due_on: '2026-07-01', outstanding: '0.00', status: 'paid' }),
]

const boxes = (...values: string[]) => AGEING_LABELS.map((label, i) => ({ label, value: values[i] }))

describe('dates in the one form (M98, 12 Oct 2026: the consoles\' "9 Oct 2026")', () => {
  it('"28 Sep 2026": no weekday, read as written', () => {
    expect(monthDay('2026-09-28', AT)).toBe('28 Sep 2026')
    expect(monthDay('2026-09-01', AT)).toBe('1 Sep 2026')
    expect(monthDay(null, AT)).toBeNull()
    expect(monthDay('soon', AT)).toBeNull()
    expect(monthDay('2026-13-01', AT)).toBeNull()
  })

  it('every date says its year, whatever "this year" is anywhere', () => {
    expect(monthDay('2025-12-01', AT)).toBe('1 Dec 2025')
    // A calendar date is read as written: New Year's Eve is the 31st, in the year it names.
    expect(monthDay('2026-12-31', new Date('2027-01-01T03:00:00Z'))).toBe('31 Dec 2026')
  })
})

describe('whole cents: read from the characters, added as integers', () => {
  it('reads a decimal string exactly', () => {
    expect(toCents('6200.00')).toBe(620000)
    expect(toCents('6200.5')).toBe(620050)
    expect(toCents('0.10')).toBe(10)
    expect(toCents('12400')).toBe(1240000)
    expect(toCents('-350.50')).toBe(-35050)
    expect(Object.is(toCents('-0.00'), 0)).toBe(true)
  })

  it('refuses what it cannot hold exactly: three places, words, nothing, a figure too large', () => {
    expect(toCents('12.345')).toBeNull()
    expect(toCents('J$12')).toBeNull()
    expect(toCents(null)).toBeNull()
    expect(toCents('')).toBeNull()
    expect(toCents('99999999999999999999.00')).toBeNull()
  })

  it('writes cents back as the decimal string the money formatter shows', () => {
    expect(fromCents(620050)).toBe('6200.50')
    expect(fromCents(0)).toBe('0.00')
    expect(fromCents(5)).toBe('0.05')
    expect(fromCents(-35050)).toBe('-350.50')
  })

  it('J$0.10 and J$0.20 make J$0.30, never 0.30000000000000004', () => {
    const tiny = [charge({ id: 2, amount: '0.10', outstanding: '0.10', due_on: '2026-10-08' }), charge({ id: 1, amount: '0.20', outstanding: '0.20', due_on: '2026-10-08' })]
    expect(ageing('0.30', tiny, AT)).toEqual(boxes('$0.30', '$0', '$0', '$0'))
  })
})

describe('the ageing rule: days PAST the due date (to be confirmed by the client)', () => {
  it('CURRENT is not yet past due; 30 DAYS is 1–30; 60 DAYS is 31–60; 90+ DAYS is over 60', () => {
    expect([-20, -1, 0].map(ageBucket)).toEqual([0, 0, 0])
    expect([1, 30].map(ageBucket)).toEqual([1, 1])
    expect([31, 60].map(ageBucket)).toEqual([2, 2])
    expect([61, 90, 400].map(ageBucket)).toEqual([3, 3, 3])
  })

  it('each charge lands in its own box, counted on the estate\'s calendar', () => {
    const edges = [
      charge({ id: 9, due_on: '2026-10-08', outstanding: '100.00' }), // due today: not yet past due
      charge({ id: 8, due_on: '2026-10-28', outstanding: '200.00' }), // still to come
      charge({ id: 7, due_on: '2026-10-07', outstanding: '300.00' }), // 1 day past due
      charge({ id: 6, due_on: '2026-09-08', outstanding: '400.00' }), // 30 days
      charge({ id: 5, due_on: '2026-09-07', outstanding: '500.00' }), // 31 days
      charge({ id: 4, due_on: '2026-08-09', outstanding: '600.00' }), // 60 days
      charge({ id: 3, due_on: '2026-08-08', outstanding: '700.00' }), // 61 days
    ]
    expect(ageing('2800.00', edges, AT)).toEqual(boxes('$300', '$700', '$1,100', '$700'))
  })

  it('a charge due today is current all evening in Kingston, not a day past due from 7 PM', () => {
    // 8 PM on 8 October in Kingston is 01:00 UTC on the 9th.
    expect(ageing('6200.00', [charge({ due_on: '2026-10-08' })], new Date('2026-10-09T01:00:00Z'))).toEqual(boxes('$6,200', '$0', '$0', '$0'))
  })
})

describe('the four boxes', () => {
  it("Lot 47 on the morning of the call: the board's own figures: $0 · $6,200 · $6,200 · $0", () => {
    expect(ageing('12400.00', SEEDED, AT)).toEqual(boxes('$0', '$6,200', '$6,200', '$0'))
  })

  it('only what is still owed is aged: a paid charge is in no box, a part-paid one only for what is left', () => {
    const partPaid = [charge({ id: 2, outstanding: '1200.50', status: 'part_paid' }), charge({ id: 1, due_on: '2026-09-01', outstanding: '0.00', status: 'paid' })]
    expect(ageing('1200.50', partPaid, AT)).toEqual(boxes('$0', '$1,200.50', '$0', '$0'))
  })

  it('figures that do not come to the balance to the cent are never shown', () => {
    // The balance holds something that is not one of these charges — an opening balance, an adjustment.
    expect(ageing('13000.00', SEEDED, AT)).toBeNull()
    // A charge the list did not return (or an answer fetched a moment apart from the other).
    expect(ageing('12400.00', SEEDED.slice(1), AT)).toBeNull()
    expect(ageing('12399.99', SEEDED, AT)).toBeNull()
  })

  it('an amount or a date that cannot be read fills no box', () => {
    expect(ageing('12400.00', [charge({ outstanding: 'n/a' }), ...SEEDED.slice(1)], AT)).toBeNull()
    expect(ageing('12400.00', [charge({ due_on: 'soon' }), ...SEEDED.slice(1)], AT)).toBeNull()
    expect(ageing('a lot', SEEDED, AT)).toBeNull()
  })

  it('nothing owed, or a credit: nothing is outstanding in any box', () => {
    const paid = SEEDED.map((c) => ({ ...c, outstanding: '0.00', status: 'paid' as const }))
    expect(ageing('0.00', paid, AT)).toEqual(boxes('$0', '$0', '$0', '$0'))
    expect(ageing('-350.50', paid, AT)).toEqual(boxes('$0', '$0', '$0', '$0'))
    expect(ageing('-350.50', SEEDED, AT)).toBeNull()
  })

  it('the board\'s short form, and never a currency code', () => {
    const shown = JSON.stringify(ageing('12400.00', SEEDED, AT))
    expect(shown).not.toMatch(/JMD|J\$/)
    expect(ageing('6200.50', [charge({ outstanding: '6200.50' })], AT)).toEqual(boxes('$0', '$6,200.50', '$0', '$0'))
  })
})

describe('the gold line: "2 months overdue · next due 28 Sep 2026"', () => {
  it('Lot 47 on the morning of the call: its oldest unpaid charge fell due on 1 Sep 2026, and nothing is still to come', () => {
    expect(dueLine(balance(), SEEDED, AT)).toBe('1 month overdue')
  })

  it('whole months once a month has passed, from the server\'s oldest unpaid due date', () => {
    expect(dueLine(balance({ oldest_unpaid_due_on: '2026-08-01', days_overdue: 68 }), SEEDED, AT)).toBe('2 months overdue')
    expect(dueLine(balance({ oldest_unpaid_due_on: '2026-09-08', days_overdue: 30 }), SEEDED, AT)).toBe('1 month overdue')
  })

  it('days before that, and the date the next charge falls due', () => {
    const board = [charge({ id: 2, description: 'Maintenance fee: September', due_on: '2026-09-28', status: 'due' }), charge({ id: 1, description: 'Maintenance fee: August', due_on: '2026-08-28' })]
    expect(dueLine(balance({ oldest_unpaid_due_on: '2026-08-28', days_overdue: 7 }), board, BOARD_AT)).toBe('7 days overdue · next due 28 Sep 2026')
    expect(dueLine(balance({ oldest_unpaid_due_on: '2026-09-03', days_overdue: 1 }), board, BOARD_AT)).toBe('1 day overdue · next due 28 Sep 2026')
  })

  it('nothing overdue: only when the next charge falls due, capitalised', () => {
    const coming = [charge({ id: 2, due_on: '2026-10-28', status: 'due' }), charge({ id: 1, due_on: '2026-10-12', status: 'due' })]
    expect(dueLine(balance({ oldest_unpaid_due_on: null, days_overdue: 0 }), coming, AT)).toBe('Next due 12 Oct 2026')
  })

  it('a paid charge still to come is not "next due"; with nothing overdue and nothing to come there is no line', () => {
    const paidAhead = [charge({ due_on: '2026-10-28', outstanding: '0.00', status: 'paid' })]
    expect(dueLine(balance({ oldest_unpaid_due_on: null, days_overdue: 0 }), paidAhead, AT)).toBeNull()
  })

  it('before the charges have answered, it says only what the balance says', () => {
    expect(dueLine(balance(), null, AT)).toBe('1 month overdue')
  })

  it('counts calendar months at the estate', () => {
    expect(monthsSince('2026-08-31', new Date('2026-09-30T15:00:00Z'))).toBe(0)
    expect(monthsSince('2026-01-31', new Date('2026-03-01T15:00:00Z'))).toBe(1)
    expect(monthsSince('2025-12-15', new Date('2026-01-15T15:00:00Z'))).toBe(1)
    expect(monthsSince('soon', AT)).toBeNull()
  })
})

describe('the dark-blue card', () => {
  it('Lot 47 on the morning of the call', () => {
    expect(balanceView(balance(), SEEDED, AT)).toEqual({ amount: '$12,400.00', due: '1 month overdue', ageing: boxes('$0', '$6,200', '$6,200', '$0'), canPay: true })
  })

  it('nothing owed, or a credit, is "$0.00" with nothing to pay', () => {
    const paid = SEEDED.map((c) => ({ ...c, outstanding: '0.00', status: 'paid' as const }))
    expect(balanceView(balance({ balance: '0.00', oldest_unpaid_due_on: null, days_overdue: 0, pay_now: null }), paid, AT)).toEqual({ amount: '$0.00', due: null, ageing: boxes('$0', '$0', '$0', '$0'), canPay: false })
    expect(balanceView(balance({ balance: '-350.50', oldest_unpaid_due_on: null, days_overdue: 0, pay_now: null }), paid, AT)).toMatchObject({ amount: '$0.00', due: null, canPay: false })
  })

  it('until the charges answer, no box is filled', () => {
    expect(balanceView(balance(), null, AT).ageing).toBeNull()
  })
})

describe("the statement's rows", () => {
  /** Lot 47's journal, newest first, as `households.statement` sends it: a charge is posted on its due date. */
  const LINES: StatementLine[] = [
    { date: '2026-10-01', description: 'Maintenance fee: October', reference: 'CHG-2026-10-0001', charge: '6200.00', payment: null, balance: '12400.00' },
    { date: '2026-09-01', description: 'Maintenance fee: September', reference: 'CHG-2026-09-0001', charge: '6200.00', payment: null, balance: '6200.00' },
    { date: '2026-08-30', description: 'Payment received: receipt PPV-R-04471', reference: 'RCT-2026-08-0012', charge: null, payment: '6200.00', balance: '0.00' },
    { date: '2026-08-01', description: 'Maintenance fee: August', reference: 'CHG-2026-08-0001', charge: '6200.00', payment: null, balance: '6200.00' },
  ]

  it("the board's words: \"Charged <date> · <state>\", the amount with its sign, the running balance beneath", () => {
    expect(statementRows(LINES, SEEDED, AT)).toEqual([
      { key: 'CHG-2026-10-0001:0', kind: 'charge', title: 'Maintenance fee: October', sub: 'Charged 1 Oct 2026 · overdue', amount: '−$6,200', balance: 'Bal. $12,400' },
      { key: 'CHG-2026-09-0001:1', kind: 'charge', title: 'Maintenance fee: September', sub: 'Charged 1 Sep 2026 · overdue', amount: '−$6,200', balance: 'Bal. $6,200' },
      { key: 'RCT-2026-08-0012:2', kind: 'payment', title: 'Payment received: receipt PPV-R-04471', sub: '30 Aug 2026', amount: '+$6,200', balance: 'Bal. $0' },
      { key: 'CHG-2026-08-0001:3', kind: 'charge', title: 'Maintenance fee: August', sub: 'Charged 1 Aug 2026', amount: '−$6,200', balance: 'Bal. $6,200' },
    ])
  })

  it('a charge not yet past its date says when it is due', () => {
    const ahead = { date: '2026-10-28', description: 'Special assessment: roads', reference: 'CHG-2026-10-0090', charge: '4500.00', payment: null, balance: '16900.00' }
    const rows = statementRows([ahead], [charge({ id: 7, description: 'Special assessment: roads', amount: '4500.00', outstanding: '4500.00', due_on: '2026-10-28', status: 'due' })], AT)
    expect(rows[0]?.sub).toBe('Charged 28 Oct 2026 · due 28 Oct 2026')
  })

  it('a line no charge matches, or before the charges answer, says only when it was charged', () => {
    expect(statementRows(LINES, null, AT).map((r) => r.sub)).toEqual(['Charged 1 Oct 2026', 'Charged 1 Sep 2026', '30 Aug 2026', 'Charged 1 Aug 2026'])
    // Same day and amount, another memo: not the same charge.
    expect(statementRows(LINES.slice(0, 1), [charge({ description: 'Late fee' })], AT)[0]?.sub).toBe('Charged 1 Oct 2026')
  })

  it('each charge answers for one line at most', () => {
    const twice = [LINES[0]!, { ...LINES[0]!, reference: 'CHG-2026-10-0002', balance: '6200.00' }]
    const one = [charge({ id: 2 }), charge({ id: 1, outstanding: '0.00', status: 'paid' })]
    expect(statementRows(twice, one, AT).map((r) => r.sub)).toEqual(['Charged 1 Oct 2026 · overdue', 'Charged 1 Oct 2026'])
    expect(statementRows(twice, one.slice(0, 1), AT).map((r) => r.sub)).toEqual(['Charged 1 Oct 2026 · overdue', 'Charged 1 Oct 2026'])
  })

  it('a credit is called a credit; a line with neither amount shows a dash; last year says its year; keys are unique', () => {
    const rows = statementRows(
      [
        { date: '2026-07-02', description: '', reference: 'RCT-2026-07-0001', charge: null, payment: '6550.50', balance: '-350.50' },
        { date: '2025-12-01', description: 'Opening balance', reference: 'JRN-0001', charge: null, payment: null, balance: '0.00' },
      ],
      [],
      AT,
    )
    expect(rows[0]).toMatchObject({ kind: 'payment', title: 'Payment received', sub: '2 Jul 2026', amount: '+$6,550.50', balance: 'Credit $350.50' })
    expect(rows[1]).toMatchObject({ kind: 'charge', sub: 'Charged 1 Dec 2025', amount: '—' })
    expect(new Set(statementRows(LINES, SEEDED, AT).map((r) => r.key)).size).toBe(LINES.length)
  })
})
