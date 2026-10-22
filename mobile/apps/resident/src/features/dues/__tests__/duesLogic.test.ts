/**
 * Dues, held to the money hard-stop:
 *   - every amount here is SHOWN from the server's string and never computed (the one sum the phone does — the
 *     statement's ageing boxes — is statement.ts, held to the cent in statement.test.ts);
 *   - a figure that is not from the server just now carries its age and says it may have changed;
 *   - a resident reads the consoles' money words (M98): Unpaid · Overdue · Partly paid · Paid · Credited · Void, and
 *     Payment processing · In arrears: access restricted, the last ONLY when the server says it; never "Due";
 *   - arrears are warned about BEFORE any restriction, in plain words;
 *   - dates are Jamaica's, whatever zone the phone thinks it is in, written the one way: "28 Sep 2026".
 */
import { flags, money } from '@gs/core/lib'
import type { Api } from '@gs/core/api'
import {
  asOf,
  chargeStatus,
  daysUntil,
  householdStanding,
  isCurrent,
  methodWord,
  longDate,
  paymentRows,
  receivedOn,
  serverSaysRestricted,
  shortDate,
  successShareText,
  successView,
  DEMONSTRATION_NOTE,
  REMINDERS_OFF,
  type Charge,
} from '../duesLogic'
import { receiptRuns } from '../receiptPdf'

// 9:41 on Friday 4 September 2026 in Jamaica (UTC−5) — the boards' moment.
const AT = new Date('2026-09-04T14:41:00Z')

const balance = (over: Partial<Api.HouseholdsBalanceResponse> = {}): Api.HouseholdsBalanceResponse => ({
  household_id: 7, unit: 'Lot 47', balance: '12400.00', currency: 'JMD', as_at: '2026-09-04', oldest_unpaid_due_on: '2026-08-01', days_overdue: 34, on_payment_plan: false, plan: null, pay_now: { amount: '12400.00', due_on: '2026-08-01', reason: 'overdue' }, access_restricted: false, ...over,
})

const charge = (over: Partial<Charge> = {}): Charge => ({
  id: 1, reference: 'DUES-2026-09-L47', type: 'dues', period: '2026-09', description: 'Maintenance fee: September', amount: '6200.00', outstanding: '6200.00', due_on: '2026-09-28', status: 'due', ...over,
})

describe('dates are read as written, and "today" is Jamaica\'s', () => {
  it('formats a calendar date without a time zone, in the one form (M98)', () => {
    expect(shortDate('2026-09-28')).toBe('28 Sep 2026')
    expect(shortDate('2026-08-01')).toBe('1 Aug 2026')
    expect(shortDate(null)).toBeNull()
    expect(shortDate('soon')).toBeNull()
    // A date where there is room is written the same one way.
    expect(longDate('2026-10-02')).toBe('2 Oct 2026')
  })

  it('counts days from today in Jamaica, not in UTC', () => {
    expect(daysUntil('2026-09-28', AT)).toBe(24)
    expect(daysUntil('2026-09-04', AT)).toBe(0)
    expect(daysUntil('2026-08-01', AT)).toBe(-34)
    // 03:00 UTC on the 5th is still 22:00 on the 4th in Kingston.
    expect(daysUntil('2026-09-05', new Date('2026-09-05T03:00:00Z'))).toBe(1)
  })

  it('a charge due today is due today all evening in Kingston, not a day overdue from 7 PM (bug hunt B121)', () => {
    // 8 PM on 24 September in Kingston is 01:00 UTC on the 25th. The server now answers on the estate's day
    // (DuesController::balance, B121); the app's own count was already Kingston's, and stays so.
    const evening = new Date('2026-09-25T01:00:00Z')
    expect(daysUntil('2026-09-24', evening)).toBe(0)
    expect(chargeStatus(charge({ status: 'due', due_on: '2026-09-24' }), evening).word).toBe('Unpaid')
  })
})

describe('the words a resident reads for a charge', () => {
  it('maps every server status to the consoles\' words (M98, 12 Oct 2026: "Unpaid, never Due")', () => {
    expect(chargeStatus(charge({ status: 'paid', outstanding: '0.00' }), AT).word).toBe('Paid')
    expect(chargeStatus(charge({ status: 'part_paid', outstanding: '1200.00' }), AT).word).toBe('Partly paid')
    // Amber, never red: red is an open panic's colour and nothing else (M98).
    expect(chargeStatus(charge({ status: 'overdue', due_on: '2026-08-01' }), AT)).toEqual({ word: 'Overdue', tone: 'amber-700' })
    expect(chargeStatus(charge({ status: 'due', due_on: '2026-09-28' }), AT).word).toBe('Unpaid')
    // Falling due this week is the date's to say ("Due 10 Sep 2026"), not the status's: still Unpaid.
    expect(chargeStatus(charge({ status: 'due', due_on: '2026-09-10' }), AT).word).toBe('Unpaid')
    expect(chargeStatus(charge({ status: 'due', due_on: '2026-09-04' }), AT).word).toBe('Unpaid')
    // The two the phone API does not send yet have their words ready.
    expect(chargeStatus({ status: 'credited', due_on: '2026-09-28', outstanding: '0.00' }, AT)).toEqual({ word: 'Credited', tone: 'slate-500' })
    expect(chargeStatus({ status: 'void', due_on: '2026-09-28', outstanding: '0.00' }, AT)).toEqual({ word: 'Void', tone: 'slate-500' })
  })

  it('never says "Due" as a status, nor "Due soon" or "Partially paid", whatever the server sends', () => {
    for (const status of ['paid', 'part_paid', 'overdue', 'due', 'credited', 'void', 'processing', 'pending', 'written_off']) {
      for (const due_on of ['2026-08-01', '2026-09-04', '2026-09-10', '2026-12-01']) {
        expect(['Due', 'Due soon', 'Partially paid']).not.toContain(chargeStatus({ status, due_on, outstanding: '10.00' }, AT).word)
      }
    }
  })

  it('keeps amber for overdue only (the user, 9 Oct 2026): unpaid and a part payment not yet past its date are navy', () => {
    expect(chargeStatus(charge({ status: 'due', due_on: '2026-09-10' }), AT)).toEqual({ word: 'Unpaid', tone: 'navy-700' })
    expect(chargeStatus(charge({ status: 'part_paid', due_on: '2026-09-28', outstanding: '1200.00' }), AT)).toEqual({ word: 'Partly paid', tone: 'navy-700' })
    expect(chargeStatus(charge({ status: 'part_paid', due_on: '2026-08-01', outstanding: '1200.00' }), AT)).toEqual({ word: 'Partly paid', tone: 'amber-700' })
  })

  it('a status this version has never seen says only what the amount says', () => {
    expect(chargeStatus({ status: 'processing', due_on: '2026-09-28', outstanding: '6200.00' }, AT).word).toBe('Payment processing')
    expect(chargeStatus({ status: 'written_off', due_on: '2026-09-28', outstanding: '0.00' }, AT).word).toBe('Paid')
    expect(chargeStatus({ status: 'written_off', due_on: '2026-09-28', outstanding: '10.00' }, AT).word).toBe('Unpaid')
  })

  it('never says "access restricted" about a charge', () => {
    for (const status of ['paid', 'part_paid', 'overdue', 'due', 'processing', 'other']) {
      expect(chargeStatus({ status, due_on: '2020-01-01', outstanding: '999999.00' }, AT).word).not.toBe('In arrears: access restricted')
    }
  })
})

describe('the household\'s standing: warned before restricted, and restricted only on the server\'s word', () => {
  it('overdue: a plain-words warning that comes BEFORE any restriction', () => {
    const standing = householdStanding(balance(), AT)
    expect(standing.status).toEqual({ word: 'Overdue', tone: 'amber-700' })
    expect(standing.restricted).toBe(false)
    expect(standing.warning).toContain('34 days overdue')
    expect(standing.warning).toContain('it must write to you first')
    expect(standing.warning).toContain('Your own entry is never affected')
    expect(householdStanding(balance({ days_overdue: 1 }), AT).warning).toContain('1 day overdue')
  })

  it('NEVER works restriction out from a number of days', () => {
    const veryLate = householdStanding(balance({ days_overdue: 400 }), AT)
    expect(veryLate.restricted).toBe(false)
    expect(veryLate.status?.word).toBe('Overdue')
  })

  it('says "In arrears: access restricted" only when the server says so', () => {
    expect(serverSaysRestricted(balance())).toBe(false)
    const flagged = { ...balance(), access_restricted: true } as Api.HouseholdsBalanceResponse
    const standing = householdStanding(flagged, AT)
    expect(standing.status?.word).toBe('In arrears: access restricted')
    expect(standing.restricted).toBe(true)
    expect(standing.warning).toContain('You can always come and go yourself')
    // Anything but a true boolean is not the server saying it.
    expect(serverSaysRestricted({ ...balance(), access_restricted: 'yes' } as unknown as Api.HouseholdsBalanceResponse)).toBe(false)
  })

  it('a payment plan changes the words, not the facts', () => {
    const standing = householdStanding(balance({ on_payment_plan: true }), AT)
    expect(standing.warningTone).toBe('navy')
    expect(standing.warning).toContain('payment plan')
  })

  /*
   * Bookings are off for the pilot. An arrears warning that names amenity bookings sends a
   * resident looking for a bookings screen this build does not have — the same journey the
   * notification row and the help answer used to send them on. Read from the flag, not hard-coded,
   * so the clause comes back whole the day bookings are switched on and these still pass.
   */
  it('no arrears warning names a feature this build has switched off', () => {
    const warnings = [
      householdStanding({ ...balance(), access_restricted: true } as Api.HouseholdsBalanceResponse, AT).warning,
      householdStanding(balance({ days_overdue: 30 }), AT).warning,
      householdStanding(balance({ days_overdue: 30, on_payment_plan: true }), AT).warning,
    ]

    for (const warning of warnings) {
      expect(warning).not.toBeNull()
      expect(/amenity|booking/i.test(warning ?? '')).toBe(flags.amenityBookings)
      // Whatever the flag says, the warning still names what IS held and never softens the rest.
      expect(warning).toContain("your guests' visitor passes")
      expect(warning).toContain('on hold')
    }

    expect(warnings[0]).toContain('You can always come and go yourself')
    expect(warnings[1]).toContain('it must write to you first')
    expect(warnings[2]).toContain('payment plan')
  })

  it('owing but not late: Unpaid, inside a week or not, and no warning (M98)', () => {
    expect(householdStanding(balance({ days_overdue: 0, oldest_unpaid_due_on: '2026-09-28' }), AT)).toMatchObject({ status: { word: 'Unpaid', tone: 'navy-700' }, warning: null })
    expect(householdStanding(balance({ days_overdue: 0, oldest_unpaid_due_on: '2026-09-08' }), AT)).toMatchObject({ status: { word: 'Unpaid', tone: 'navy-700' }, warning: null })
  })

  it('nothing owed, or a credit, is Paid', () => {
    expect(householdStanding(balance({ balance: '0.00', days_overdue: 0 }), AT).status?.word).toBe('Paid')
    expect(householdStanding(balance({ balance: '-350.50', days_overdue: 0 }), AT).status?.word).toBe('Paid')
  })
})

describe('payments the office recorded', () => {
  const payment: Api.PaymentsIndexResponse['items'][number] = { id: 9, receipt_no: 'PPV-R-04472', amount: '12400.00', currency: 'JMD', method: 'bank', reference: 'Lot 47', received_at: '2026-09-04T14:41:00Z', status: 'recorded', is_demonstration: false }

  it('lists them with the method in plain words', () => {
    expect(paymentRows([payment])[0]).toEqual({ id: '9', title: '$12,400.00 · Bank transfer', sub: '4 Sep 2026 · receipt PPV-R-04472', reversed: false, demonstration: false })
    expect(methodWord('cheque')).toBe('Cheque')
    expect(methodWord('something-new')).toBe('Recorded by the office')
  })

  it('a receipt the OFFICE recorded says RECEIVED, in board 30’s rows, never "successful": nothing in the app succeeded', () => {
    const view = successView({ ...payment, estate: 'Phoenix Park Village 1', unit: 'Lot 47', household: 'Fletcher', received_by_name: 'D. Brown', entered_at: '2026-09-04T15:00:00Z' } as Api.PaymentsReceiptResponse, '0.00')
    expect(view.title).toBe('Payment received')
    expect(view.line).toBe('$12,400.00 received by Phoenix Park Village 1 · receipt #PPV-R-04472')
    expect(view.details).toEqual([
      { label: 'Paid with', value: 'Bank transfer' },
      // A receipt is a record: it says its year, in the one form, no tag on the screen (M98).
      { label: 'Date & time', value: '4 Sep 2026, 9:41 AM', moment: true },
    ])
    expect(view.total).toEqual({ label: 'Your balance', value: money('0.00') })
    expect(JSON.stringify(view)).not.toMatch(/successful/i)
  })

  it('the demonstration Pay just recorded is "Payment successful", names its test card, and says no money moved', () => {
    const demo = { ...payment, method: 'card' as const, reference: 'Demonstration card payment · Visa •••• 4242', received_at: '2026-10-07T20:41:00+00:00', is_demonstration: true, estate: 'Phoenix Park Village 1' }
    const view = successView(demo, '0.00')
    // The balance is clear, so the board's own note is true and is said; part-paid, the demonstration's own note.
    expect(view).toMatchObject({ reversed: false, title: 'Payment successful', line: '$12,400.00 paid to Phoenix Park Village 1 · receipt #PPV-R-04472', note: REMINDERS_OFF })
    expect(successView(demo, '6200.00').note).toBe(DEMONSTRATION_NOTE)
    expect(view.details).toEqual([
      { label: 'Paid with', value: 'Visa •••• 4242 (demonstration)' },
      { label: 'Date & time', value: '7 Oct 2026, 3:41 PM', moment: true },
    ])
    expect(view.total).toEqual({ label: 'Your balance', value: '$0.00' })
    expect(successView(demo, '6200.00').note).toMatch(/No money moved/)
  })

  it('"Your balance" is the ledger’s figure, a credit is $0.00, as on Dues, and is left out until it has answered', () => {
    expect(successView(payment, '6200.00').total).toEqual({ label: 'Your balance', value: '$6,200.00' })
    expect(successView(payment, '-350.50').total).toEqual({ label: 'Your balance', value: '$0.00' })
    expect(successView(payment, null).total).toBeNull()
  })

  it('nothing the receipt does not hold is shown: the board’s charge lines are left out, not worked out', () => {
    expect(successView({ ...payment, reference: null }, null).details.map((l) => l.label)).toEqual(['Paid with', 'Date & time'])
    expect(JSON.stringify(successView(payment, '0.00'))).not.toMatch(/Maintenance fee/)
    // The board's note is about the BALANCE, not the receipt: with it clear, it is true of an office payment too (229b8e7).
    expect(successView(payment, '0.00').note).toBe(REMINDERS_OFF)
  })

  // The office records the DAY money arrived; the server keeps it as midnight UTC (what the live API sends).
  // Through a clock that is 7:00 PM the evening before in Jamaica — the wrong day, and a time nobody recorded.
  describe('a payment recorded as a DAY is shown as that day, with no invented time', () => {
    const dayOnly = { ...payment, received_at: '2026-07-03T00:00:00+00:00' }

    it('the history says the day the office wrote: the same day the statement says', () => {
      expect(paymentRows([dayOnly])[0]?.sub).toBe('3 Jul 2026 · receipt PPV-R-04472')
      expect(receivedOn('2026-07-03T00:00:00Z')).toEqual({ date: '3 Jul 2026', time: null })
      expect(receivedOn('2026-07-03T00:00:00.000000Z')).toEqual({ date: '3 Jul 2026', time: null })
    })

    it('the receipt says "Date received", never "7:00 PM" the evening before', () => {
      const view = successView(dayOnly, null)
      expect(view.details).toContainEqual({ label: 'Date received', value: '3 Jul 2026' })
      expect(JSON.stringify(view)).not.toMatch(/\b2 Jul\b|7:00 PM|Date & time/)
    })

    it('a payment that does carry a time is a moment, shown in the site’s zone', () => {
      expect(receivedOn('2026-09-05T03:00:00Z')).toEqual({ date: '4 Sep 2026', time: '10:00 PM' })
      // Midnight somewhere else is still a moment.
      expect(receivedOn('2026-07-03T00:00:00-05:00')).toEqual({ date: '3 Jul 2026', time: '12:00 AM' })
    })

    it('something that is not a date is shown as sent, not as "NaN"', () => {
      expect(receivedOn('soon')).toEqual({ date: 'soon', time: null })
    })
  })

  it('every date says its year (M98): a history runs back further than twelve months', () => {
    expect(shortDate('2025-07-03')).toBe('3 Jul 2025')
    expect(shortDate('2026-07-03')).toBe('3 Jul 2026')
    expect(paymentRows([{ ...payment, received_at: '2025-07-03T00:00:00+00:00' }])[0]?.sub).toBe('3 Jul 2025 · receipt PPV-R-04472')
    // A calendar date is read as written: New Year's Eve stays the 31st whatever "this year" is anywhere.
    expect(shortDate('2026-12-31')).toBe('31 Dec 2026')
  })

  /*
   * Hassam's rule (M98, 12 Oct 2026): the clock's name is dropped where the reader is certainly at the estate, and kept
   * where the document leaves the estate or the reader might not be. The screen is read at the estate; the PDF and the
   * shared text are saved, mailed and opened anywhere, years later.
   */
  it('the copy that LEAVES the app names the estate’s clock; the screen does not, and a day alone needs none', () => {
    const view = successView(payment, '0.00')
    expect(view.details.find((d) => d.label === 'Date & time')?.value).toBe('4 Sep 2026, 9:41 AM')
    const printed = receiptRuns(view).map((r) => r.text)
    expect(printed).toContain('4 Sep 2026, 9:41 AM Jamaica time')
    expect(printed).not.toContain('4 Sep 2026, 9:41 AM')
    expect(successShareText(view)).toContain('Date & time: 4 Sep 2026, 9:41 AM Jamaica time')
    const dayOnly = successView({ ...payment, received_at: '2026-07-03T00:00:00+00:00' }, null)
    expect(receiptRuns(dayOnly).map((r) => r.text)).toContain('3 Jul 2026')
    expect(receiptRuns(dayOnly).some((r) => r.text.includes('Jamaica time'))).toBe(false)
  })

  it('a reversed payment says so everywhere: the demonstration’s too, once the estate reverses it', () => {
    const view = successView({ ...payment, status: 'reversed' }, '12400.00')
    expect(view).toMatchObject({ reversed: true, title: 'Payment reversed', line: '$12,400.00 was recorded and then reversed by your estate · receipt #PPV-R-04472' })
    expect(successShareText(view)).toContain('Payment reversed')
    expect(successShareText(view)).toContain('Your balance: $12,400.00')
    const demo = successView({ ...payment, status: 'reversed', is_demonstration: true, method: 'card' }, '12400.00')
    expect(demo.title).toBe('Payment reversed')
    expect(demo.note).toMatch(/demonstration payment was reversed/)
  })
})

describe('ages', () => {
  it('reads a recent age in words that belong to no clock, and "yesterday" in Jamaica (M19A §4.1)', () => {
    expect(asOf('2026-09-04T14:00:00Z', AT)).toBe('as of 41 minutes ago')
    // 03:00 UTC on the 4th is 10 PM on the 3rd in Kingston.
    expect(asOf('2026-09-04T03:00:00Z', AT)).toBe('as of yesterday at 10:00 PM')
    expect(asOf('2026-09-01T15:00:00Z', AT)).toBe('as of 1 Sep 2026, 10:00 AM')
  })

  it('a figure is current only while it is from the server, just now', () => {
    expect(isCurrent('2026-09-04T14:40:00Z', AT, true)).toBe(true)
    expect(isCurrent('2026-09-04T14:40:00Z', AT, false)).toBe(false)
    expect(isCurrent('2026-09-04T14:00:00Z', AT, true)).toBe(false)
  })
})
