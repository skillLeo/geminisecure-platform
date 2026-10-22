/**
 * Amenity bookings — money is shown and never computed; a request is never called confirmed; hours
 * are the ESTATE's whatever the phone's zone; the app cancels only inside the server's own deadline.
 */
import { forgetSiteZone, setSiteZone } from '@gs/core/lib'
import {
  CLOSED_MONEY,
  DEPOSIT_NOTE,
  FEE_WITH_OLDER,
  LAPSED_LINE,
  OWED_BEFORE,
  PAID_AHEAD,
  PENDING_LINE,
  TAKEN_LINE,
  atSite,
  cancelStateOf,
  cancellationTerms,
  clockLabel,
  dayLong,
  dayOptions,
  dayShort,
  depositLine,
  depositLineWithDemo,
  depositNoteOf,
  describeLine,
  detailLine,
  feeLine,
  heroLine,
  hoursOf,
  payParts,
  priceOf,
  quoteRows,
  rulesOf,
  rulesTailOf,
  termsRows,
  fieldErrorsOf,
  hoursLabel,
  iconFor,
  isClosed,
  isNil,
  isUpcoming,
  lengthLabel,
  minutesOf,
  pickFree,
  pickSlot,
  priceLine,
  priceRows,
  slotLabel,
  slotPassed,
  slotTaken,
  slotsFor,
  splitBookings,
  standingOf,
  timeRange,
  toRequest,
  validateRequest,
  whenLine,
  wholeOf,
  type Amenity,
  type Booking,
} from '../amenities'

const AT = new Date('2026-09-04T09:41:00-05:00') // a Friday in Kingston

const gazebo: Amenity = {
  id: 3, name: 'Gazebo', icon_key: 'gazebo', capacity: 25, opens_at: '08:00:00', closes_at: '22:00:00', booking_fee: '3000.00', deposit: '5000.00', currency: 'JMD', cancellation_hours: 48, booking_window_days: 30,
  requires_approval: false,
  description: null, place: null, hourly_rate: null, slot_minutes: null, rules_summary: null, rules: null,
}
const centre: Amenity = { ...gazebo, id: 1, name: 'Community Centre', icon_key: 'pavilion', capacity: 120, opens_at: '09:00:00', closes_at: '24:00:00' }
/** The Gazebo as the design draws it (M47 §8–§9) — the terms `amenities:design-terms` sets. */
const designGazebo: Amenity = {
  ...gazebo, capacity: 30, opens_at: '09:00:00', closes_at: '21:00:00', description: 'garden setting', place: 'Phase 2', hourly_rate: '2000.00', slot_minutes: 120,
  rules_summary: 'noise cut-off at 9:00 PM and no glassware on the lawn', rules: 'Noise cut-off at 9:00 PM.\nNo glassware on the lawn.',
}

const booking = (patch: Partial<Booking> = {}): Booking => ({
  id: 7,
  reference: 'BKG-2026-09-0004',
  amenity: { id: 3, name: 'Gazebo' },
  starts_at: '2026-09-19T11:00:00-05:00',
  ends_at: '2026-09-19T13:00:00-05:00',
  guests: 12,
  status: 'pending',
  booking_fee: '3000.00',
  deposit: '5000.00',
  deposit_state: 'awaiting',
  deposit_kept: null,
  deposit_demonstration: false,
  fee_charged: false,
  fee_outstanding: '0.00',
  fee_to_clear: '0.00',
  fee_paid: false,
  currency: 'JMD',
  cancellable_until: '2026-09-17T11:00:00-05:00',
  declined_reason: null,
  cancelled_at: null,
  confirmation_code: null,
  gate_token: null,
  paid_with: null,
  ...patch,
})

describe('what the server sends as numbers', () => {
  it('reads a whole number from a number or a string, and nothing else', () => {
    expect(wholeOf(25)).toBe(25)
    expect(wholeOf('30')).toBe(30)
    expect(wholeOf(null)).toBeNull()
    expect(wholeOf('')).toBeNull()
    expect(wholeOf('lots')).toBeNull()
    expect(wholeOf(-1)).toBeNull()
  })
})

describe('pictures', () => {
  it("maps the estate's picture keys to the boards' pictures, and an unknown key to the pavilion", () => {
    expect(iconFor('gazebo')).toBe('amenity-icon-2')
    expect(iconFor('clubhouse')).toBe('amenity-icon')
    expect(iconFor('pool')).toBe('amenity-icon-3')
    expect(iconFor('tennis')).toBe('pm-icon-2')
    expect(iconFor(null)).toBe('pm-icon-2')
  })
})

describe('opening hours', () => {
  it('reads HH:MM:SS, the twenty-fourth hour included', () => {
    expect(minutesOf('09:00:00')).toBe(540)
    expect(minutesOf('22:00')).toBe(1320)
    expect(minutesOf('24:00:00')).toBe(1440)
    expect(minutesOf('25:00:00')).toBeNull()
    expect(minutesOf('soon')).toBeNull()
  })

  it("says Midnight the way the estate's own console does", () => {
    expect(clockLabel(480)).toBe('8:00 AM')
    expect(clockLabel(1440)).toBe('Midnight')
    // The estate's opening hours, untagged (M98: no tag after a time, ever).
    expect(hoursLabel(gazebo)).toBe('8:00 AM – 10:00 PM')
    expect(hoursLabel(centre)).toBe('9:00 AM – Midnight')
    expect(hoursLabel({ opens_at: '', closes_at: '' })).toBeNull()
  })

  it('describes a place with what the server knows, and nothing else', () => {
    expect(detailLine(gazebo)).toBe('Up to 25 guests · Open 8:00 AM – 10:00 PM')
    expect(detailLine({ capacity: null, opens_at: '08:00:00', closes_at: '22:00:00' })).toBe('Open 8:00 AM – 10:00 PM')
  })
})

describe('money is shown, never computed', () => {
  it('puts the fee and the deposit side by side, exactly as sent', () => {
    expect(priceLine('3000.00', '5000.00')).toBe('$3,000 fee · $5,000 deposit')
    expect(priceLine('3000.50', '5000.00')).toBe('$3,000.50 fee · $5,000 deposit')
    expect(priceLine('0.00', '0.00')).toBe('No fee · no deposit')
  })

  it('has no total row: two amounts, and that is all', () => {
    const rows = priceRows('3000.00', '5000.00')
    expect(rows).toEqual([{ label: 'Booking fee', value: '$3,000.00' }, { label: 'Refundable deposit', value: '$5,000.00' }])
    expect(rows.some((row) => /total/i.test(row.label) || row.value === '$8,000.00')).toBe(false)
    expect(priceRows('0.00', '0.00').map((row) => row.value)).toEqual(['No fee', 'None'])
  })

  it("names a deposit's state in plain words, with no date the server did not send", () => {
    expect(depositLine(booking({ deposit_state: 'awaiting' }))).toBe('$5,000 deposit not received yet')
    expect(depositLine(booking({ deposit_state: 'refunded' }))).toBe('$5,000 deposit refunded')
    expect(depositLine(booking({ deposit_state: 'none' }))).toBeNull()
    expect(depositLine(booking({ deposit: '0.00', deposit_state: 'awaiting' }))).toBeNull()
  })

  it('says how much of a deposit the estate kept when it kept only part, never "the deposit was kept" over money that went back', () => {
    expect(depositLine(booking({ status: 'completed', deposit_state: 'forfeited', deposit_kept: '2000.00' }))).toBe('$2,000 of your $5,000 deposit kept by the estate')
    // The whole of it, an older server that sends no figure, and a figure that cannot be read: the plain sentence.
    expect(depositLine(booking({ status: 'completed', deposit_state: 'forfeited', deposit_kept: '5000.00' }))).toBe('$5,000 deposit kept by the estate')
    expect(depositLine(booking({ status: 'completed', deposit_state: 'forfeited', deposit_kept: null }))).toBe('$5,000 deposit kept by the estate')
    expect(depositLine({ status: 'completed', deposit: '5000.00', deposit_state: 'forfeited' })).toBe('$5,000 deposit kept by the estate')
    expect(depositLine(booking({ status: 'completed', deposit_state: 'forfeited', deposit_kept: 'n/a' }))).toBe('$5,000 deposit kept by the estate')
    // A figure is only ever said about a deposit that WAS kept.
    expect(depositLine(booking({ status: 'completed', deposit_state: 'refunded', deposit_kept: '2000.00' }))).toBe('$5,000 deposit refunded')
  })

  it('says "No fee" only when the server SENT a zero: an amount it cannot read is shown as sent, never called nothing', () => {
    expect(isNil('0.00')).toBe(true)
    expect(isNil('0')).toBe(true)
    expect(isNil('3000.00')).toBe(false)
    expect(isNil('n/a')).toBe(false)
    expect(isNil(null)).toBe(false)
    expect(priceRows('n/a', '5000.00')[0]!.value).toBe('n/a')
    expect(priceLine('n/a', '0.00')).toBe('n/a fee · no deposit')
  })

  it('does not tell a household that a deposit is still awaited on a booking that was declined or cancelled', () => {
    expect(depositLine(booking({ status: 'cancelled', deposit_state: 'awaiting' }))).toBeNull()
    expect(depositLine(booking({ status: 'declined', deposit_state: 'awaiting' }))).toBeNull()
    // Money the estate really holds is always said. (The server now refunds a cancelled booking's deposit at once, so
    // `held` under `cancelled` is only ever seen from an older server; it is still said as it is.)
    expect(depositLine(booking({ status: 'cancelled', deposit_state: 'held' }))).toBe('$5,000 deposit held by the estate')
    expect(depositLine(booking({ status: 'cancelled', deposit_state: 'refunded' }))).toBe('$5,000 deposit refunded')
    expect(isClosed(booking({ status: 'declined' }))).toBe(true)
    expect(isClosed(booking({ status: 'completed' }))).toBe(false)
    expect(CLOSED_MONEY).not.toMatch(/same way as your dues/)
  })

  it('states the cancellation terms', () => {
    expect(cancellationTerms(48)).toContain('up to 48 hours before')
    expect(cancellationTerms(1)).toContain('up to 1 hour before')
    expect(cancellationTerms(null)).toContain('any time before it starts')
  })
})

describe('days and times are Kingston’s', () => {
  it('writes a day in the one form (M98: the consoles\' "9 Oct 2026")', () => {
    expect(dayLong('2026-09-19T11:00:00-05:00')).toBe('19 Sep 2026')
    expect(dayShort('2026-09-19T11:00:00-05:00')).toBe('19 Sep 2026')
    // 03:30 UTC on the 20th is still the evening of the 19th in Kingston.
    expect(dayShort('2026-09-20T03:30:00Z')).toBe('19 Sep 2026')
  })

  it("offers today … the last day of the estate's booking window", () => {
    const days = dayOptions(AT, 30)
    expect(days).toHaveLength(31)
    expect(days[0]).toMatchObject({ iso: '2026-09-04', label: 'Today' })
    expect(days[1]).toMatchObject({ iso: '2026-09-05', label: 'Tomorrow' })
    expect(days[30]!.iso).toBe('2026-10-04')
    expect(dayOptions(AT, null)).toHaveLength(61)
  })

  it('builds the moment in the SITE’s zone, and rolls midnight into the next day', () => {
    expect(atSite('2026-09-19', 660)).toBe('2026-09-19T11:00:00-05:00')
    expect(atSite('2026-09-30', 1440)).toBe('2026-10-01T00:00:00-05:00')
  })

  /**
   * THE ONE CLOCK — "Saturday, 11 to 1" is the clock on the wall at the estate. The suite is also run with
   * TZ=Asia/Tokyo; a request built from the handset's own zone would ask for the middle of the night.
   */
  it('asks for 11 AM at the estate, not 11 AM on the handset', () => {
    expect(new Date(atSite('2026-09-19', 660)).toISOString()).toBe('2026-09-19T16:00:00.000Z')
    try {
      setSiteZone('America/New_York') // September: daylight saving, −04:00
      expect(atSite('2026-09-19', 660)).toBe('2026-09-19T11:00:00-04:00')
      expect(new Date(atSite('2026-09-19', 660)).toISOString()).toBe('2026-09-19T15:00:00.000Z')
    } finally {
      forgetSiteZone()
    }
  })

  it('writes a range, ending at Midnight when it does', () => {
    expect(timeRange('2026-09-19T11:00:00-05:00', '2026-09-19T13:00:00-05:00')).toBe('11:00 AM – 1:00 PM')
    expect(timeRange('2026-09-19T22:00:00-05:00', '2026-09-20T00:00:00-05:00')).toBe('10:00 PM – Midnight')
    expect(whenLine(booking())).toBe('19 Sep 2026, 11:00 AM – 1:00 PM')
    expect(lengthLabel(660, 780)).toBe('2 hours')
    expect(lengthLabel(660, 750)).toBe('1 hour 30 minutes')
  })
})

describe('the hour blocks', () => {
  it('runs from opening to closing in one-hour blocks', () => {
    const slots = slotsFor(gazebo)
    expect(slots).toHaveLength(14)
    expect(slots[0]).toMatchObject({ from: 480, to: 540, label: '8–9 AM' })
    expect(slots[3]!.label).toBe('11–12 PM')
    expect(slots[13]).toMatchObject({ from: 1260, to: 1320, label: '9–10 PM' })
    expect(slotsFor(centre).at(-1)!.label).toBe('11 PM–Midnight')
    expect(slotsFor({ opens_at: '', closes_at: '' })).toEqual([])
    expect(slotLabel(540, 660)).toBe('9–11 AM')
  })

  it('builds one unbroken stretch from taps', () => {
    expect(pickSlot(null, 3)).toEqual({ start: 3, end: 3 })
    expect(pickSlot({ start: 3, end: 3 }, 5)).toEqual({ start: 3, end: 5 })
    expect(pickSlot({ start: 3, end: 5 }, 1)).toEqual({ start: 1, end: 5 })
    expect(pickSlot({ start: 1, end: 5 }, 4)).toEqual({ start: 4, end: 4 })
    expect(pickSlot({ start: 4, end: 4 }, 4)).toBeNull()
  })

  it('will not offer a block that has already begun', () => {
    const slots = slotsFor(gazebo)
    expect(slotPassed('2026-09-04', slots[1]!, AT)).toBe(true) // 9–10 AM, and it is 9:41
    expect(slotPassed('2026-09-04', slots[2]!, AT)).toBe(false) // 10–11 AM
    expect(slotPassed('2026-09-05', slots[0]!, AT)).toBe(false)
  })

  /*
   * M21 §1.5, on the emulator: the picker knew nothing of the diary, so the hours already booked were found one
   * refusal at a time — and the refusal named the other household's booking reference.
   */
  it('marks the hours already booked or closed that day, on the estate\'s clock', () => {
    const slots = slotsFor(gazebo)
    // Another household's 2 PM – 6 PM, sent as instants (19:00–23:00 UTC is 2–6 PM in Kingston).
    const taken = [{ starts_at: '2026-09-19T19:00:00+00:00', ends_at: '2026-09-19T23:00:00+00:00' }]
    const booked = slots.filter((slot) => slotTaken('2026-09-19', slot, taken)).map((slot) => slot.label)
    expect(booked).toEqual(['2–3 PM', '3–4 PM', '4–5 PM', '5–6 PM'])
    // Only that day; nothing marked with no diary sent (a list saved before the server sent one).
    expect(slots.some((slot) => slotTaken('2026-09-20', slot, taken))).toBe(false)
    expect(slots.some((slot) => slotTaken('2026-09-19', slot, undefined))).toBe(false)
  })

  it('never stretches a request across an hour that cannot be had', () => {
    const blocked = (i: number) => i === 6 // 2–3 PM
    expect(pickFree(null, 6, blocked)).toBeNull()
    expect(pickFree({ start: 3, end: 3 }, 6, blocked)).toEqual({ start: 3, end: 3 })
    // 11 AM, then 4 PM: the stretch would run across 2 PM, so it starts again at 4 PM.
    expect(pickFree({ start: 3, end: 3 }, 8, blocked)).toEqual({ start: 8, end: 8 })
    expect(pickFree({ start: 3, end: 3 }, 5, blocked)).toEqual({ start: 3, end: 5 })
    expect(pickFree({ start: 3, end: 5 }, 3, blocked)).toEqual({ start: 3, end: 3 })
  })
})

describe('the request: a day and its blocks, and nothing more (M47 §9d–f: no guests box, no note)', () => {
  const input = { day: '2026-09-19', range: { start: 3, end: 4 } }

  it('passes a request inside the hours and the window', () => {
    expect(validateRequest(input, gazebo, AT)).toEqual({})
  })

  it('asks for a time, refuses a start in the past and a day outside the window', () => {
    expect(validateRequest({ ...input, range: null }, gazebo, AT).time).toBe('Choose a time slot: tap the first one you need, then the last.')
    expect(validateRequest({ ...input, day: '2026-09-04', range: { start: 0, end: 1 } }, gazebo, AT).time).toContain('already started')
    expect(validateRequest({ ...input, day: '2026-12-25' }, gazebo, AT).day).toContain('30 days ahead')
    // The last day of the window, later in the day than now: the server measures to the minute.
    expect(validateRequest({ ...input, day: '2026-10-04', range: { start: 5, end: 6 } }, gazebo, AT).day).toContain('30 days ahead')
    // Hours the diary shows taken (a saved stretch, a day changed under it) are refused before anything is sent.
    const taken = [{ starts_at: '2026-09-19T16:30:00+00:00', ends_at: '2026-09-19T17:30:00+00:00' }]
    expect(validateRequest(input, { ...gazebo, taken }, AT).time).toBe(TAKEN_LINE)
  })

  it('sends Kingston clock times with their offset, and no guests or note, which the board does not ask', () => {
    expect(toRequest(3, input, gazebo)).toEqual({ amenity_id: 3, starts_at: '2026-09-19T11:00:00-05:00', ends_at: '2026-09-19T13:00:00-05:00' })
    expect(toRequest(1, { ...input, range: { start: 14, end: 14 } }, centre)!.ends_at).toBe('2026-09-20T00:00:00-05:00')
    expect(toRequest(3, { ...input, range: null }, gazebo)).toBeNull()
    // Two of the design's two-hour blocks: 11 AM to 3 PM.
    expect(hoursOf({ day: '2026-09-19', range: { start: 1, end: 2 } }, designGazebo)).toEqual({ starts_at: '2026-09-19T11:00:00-05:00', ends_at: '2026-09-19T15:00:00-05:00' })
  })

  it("puts a 422's time error on the blocks", () => {
    expect(fieldErrorsOf({ starts_at: ['The starts at must be a date.'] })).toEqual({ time: 'The starts at must be a date.' })
    expect(fieldErrorsOf({})).toEqual({})
  })
})

describe('the design’s amenity (M47 §8–§9)', () => {
  it('describes a place as board 21 does, and its hero as board 22 does', () => {
    expect(describeLine(designGazebo)).toBe('Up to 30 guests · garden setting')
    expect(heroLine(designGazebo)).toBe('Up to 30 guests · garden setting · Phase 2')
    // A place the estate has not described: its capacity, and nothing made up.
    expect(describeLine(gazebo)).toBe('Up to 25 guests')
    expect(heroLine(gazebo)).toBe('Up to 25 guests')
  })

  it('prices it by the hour, as the estate charges: a place with one flat fee keeps saying so', () => {
    expect(priceOf(designGazebo)).toBe('$2,000/hr · $5,000 deposit')
    expect(priceOf({ ...designGazebo, hourly_rate: '4500.00', deposit: '10000.00' })).toBe('$4,500/hr · $10,000 deposit')
    expect(priceOf(gazebo)).toBe('$3,000 fee · $5,000 deposit')
    // A list saved before rates existed has no field at all.
    const { hourly_rate: _, ...older } = gazebo
    expect(priceOf(older)).toBe('$3,000 fee · $5,000 deposit')
  })

  it('books its day in the board’s six two-hour blocks: 9–11 AM … 7–9 PM', () => {
    expect(slotsFor(designGazebo).map((s) => s.label)).toEqual(['9–11 AM', '11–1 PM', '1–3 PM', '3–5 PM', '5–7 PM', '7–9 PM'])
  })

  it('draws the summary from the server’s quote: the board’s rows, word for word, and no sum of its own', () => {
    const quote = { minutes: 120, hourly_rate: '2000.00', fee: '4000.00', owed_before: '0.00', credit: '0.00', deposit: '5000.00', total: '9000.00' }
    expect(quoteRows('Gazebo', quote)).toEqual({
      rows: [
        { label: 'Gazebo rate: 2 hrs × $2,000', value: '$4,000' },
        { label: 'Refundable damage deposit', value: '$5,000' },
      ],
      total: { label: 'Total due today', value: '$9,000' },
    })
    // Owed from before, or paid ahead: said only when there is some — the server's figures, still not added here.
    expect(quoteRows('Gazebo', { ...quote, owed_before: '1500.00', total: '10500.00' }).rows[1]).toEqual({ label: OWED_BEFORE, value: '$1,500' })
    expect(quoteRows('Gazebo', { ...quote, credit: '500.00', total: '8500.00' }).rows[1]).toEqual({ label: PAID_AHEAD, value: '−$500' })
    // Before any hours are chosen: the rate and the deposit, and a total that waits for them.
    expect(termsRows(designGazebo)).toEqual({ rows: [{ label: 'Gazebo rate', value: '$2,000/hr' }, { label: 'Refundable damage deposit', value: '$5,000' }], total: { label: 'Total due today', value: '—' } })
    expect(DEPOSIT_NOTE).toBe('Deposit is refunded after a condition check within 3 business days of your event.')
  })

  it('says the house rules as board 22 does, and opens them as the estate wrote them', () => {
    expect(rulesTailOf(designGazebo)).toBe(', including noise cut-off at 9:00 PM and no glassware on the lawn.')
    expect(rulesOf(designGazebo)).toEqual(['Noise cut-off at 9:00 PM.', 'No glassware on the lawn.'])
    expect(rulesTailOf(gazebo)).toBe('.')
    expect(rulesOf(gazebo)).toEqual([])
  })

  it('says where a deposit paid by card goes back to, as board 31 does', () => {
    expect(depositNoteOf(booking({ status: 'confirmed', deposit_state: 'held', deposit_demonstration: true, paid_with: 'Visa •••• 4417' }))).toBe('Your $5,000 deposit is refunded within 3 business days after a condition check, straight back to Visa •••• 4417.')
    // Paid to the estate, not by card: the estate pays it back, and no card is named.
    expect(depositNoteOf(booking({ status: 'confirmed', deposit_state: 'held' }))).toBe('Your $5,000 deposit is refunded within 3 business days after a condition check.')
  })
})

describe("a booking's standing", () => {
  it('never calls a request confirmed', () => {
    const pending = standingOf(booking({ status: 'pending' }))
    expect(pending.line).toBe(PENDING_LINE)
    expect(pending.line).toBe('Requested: waiting for the estate to confirm')
    expect(`${pending.word} ${pending.title}`).not.toMatch(/confirmed/i)
    expect(standingOf(booking({ status: 'confirmed' })).title).toBe('Booking confirmed')
  })

  it('stops saying "waiting" once the time of an undecided request has passed, and still never says confirmed', () => {
    const after = new Date('2026-09-19T13:00:00-05:00')
    expect(standingOf(booking({ status: 'pending' }), AT).line).toBe(PENDING_LINE)
    expect(standingOf(booking({ status: 'pending' }), after).line).toBe(LAPSED_LINE)
    expect(LAPSED_LINE).not.toMatch(/confirmed|waiting/i)
    expect(standingOf(booking({ status: 'confirmed' }), after).word).toBe('Confirmed')
  })

  it('says an expired request expired, never declined, and asks nothing more for it (M53 §2)', () => {
    const expired = booking({ status: 'expired', deposit_state: 'awaiting', fee_paid: false, fee_outstanding: '0.00' })
    const standing = standingOf(expired)
    expect(standing).toMatchObject({ word: 'Expired', tone: 'slate', title: 'Request expired', line: LAPSED_LINE })
    expect(`${standing.word} ${standing.title} ${standing.line}`).not.toMatch(/declined|confirmed/i)
    expect(isClosed(expired)).toBe(true)
    expect(feeLine(expired)).toBeNull()
    expect(depositLine(expired)).toBeNull()
    expect(payParts(expired, true)).toEqual([])
  })

  it("shows the estate's reason for declining", () => {
    expect(standingOf(booking({ status: 'declined', declined_reason: 'The lawn is being re-turfed.' })).line).toContain('The lawn is being re-turfed.')
    expect(standingOf(booking({ status: 'declined' })).line).toContain('estate office')
  })

  it('splits what is still to come from the past', () => {
    const items = [
      booking({ id: 1 }),
      booking({ id: 2, status: 'confirmed', starts_at: '2026-09-10T11:00:00-05:00', ends_at: '2026-09-10T13:00:00-05:00' }),
      booking({ id: 3, status: 'completed', starts_at: '2026-08-15T11:00:00-05:00', ends_at: '2026-08-15T13:00:00-05:00' }),
      booking({ id: 4, status: 'cancelled' }),
      booking({ id: 5, status: 'confirmed', starts_at: '2026-09-03T11:00:00-05:00', ends_at: '2026-09-03T13:00:00-05:00' }),
    ]
    const split = splitBookings(items, AT)
    expect(split.upcoming.map((b) => b.id)).toEqual([2, 1])
    expect(split.past.map((b) => b.id)).toEqual([4, 5, 3])
    expect(isUpcoming(booking({ status: 'declined' }), AT)).toBe(false)
  })

  it("offers cancelling only inside the server's own deadline", () => {
    expect(cancelStateOf(booking(), AT)).toMatchObject({ can: true })
    expect(cancelStateOf(booking(), new Date('2026-09-17T11:00:00-05:00'))).toMatchObject({ can: false })
    expect(cancelStateOf(booking(), new Date('2026-09-18T09:00:00-05:00'))).toMatchObject({ can: false, reason: expect.stringContaining('too late') })
    expect(cancelStateOf(booking(), new Date('2026-09-19T11:30:00-05:00'))).toMatchObject({ can: false, reason: expect.stringContaining('already started') })
    expect(cancelStateOf(booking({ status: 'cancelled' }), AT)).toEqual({ can: false, reason: null })
    expect(cancelStateOf(booking({ status: 'completed' }), AT)).toEqual({ can: false, reason: null })
  })
})

describe('paying a booking by test card (M12 §2.14; D-179)', () => {
  const approved = booking({ status: 'confirmed', fee_charged: true, fee_outstanding: '3000.00', fee_to_clear: '3000.00', fee_paid: false })

  it("says whether the fee is paid from the server's own figures, and nothing from an older server", () => {
    expect(feeLine(approved)).toBe('$3,000 fee to pay')
    expect(feeLine({ ...approved, fee_paid: true, fee_outstanding: '0.00' })).toBe('$3,000 fee paid')
    expect(feeLine(booking())).toBe('$3,000 fee: paid once the estate approves')
    expect(feeLine({ ...booking(), fee_paid: undefined })).toBeNull()
    expect(feeLine(booking({ status: 'declined' }))).toBeNull()
  })

  it('offers the fee once approved, and the deposit while it is awaited, never on a closed booking or with the checkout off', () => {
    expect(payParts(approved, true).map((p) => [p.part, p.label, p.amount, p.note])).toEqual([
      ['fee', 'Pay the $3,000 fee by card', '3000.00', null],
      ['deposit', 'Pay the $5,000 deposit by card', '5000.00', null],
    ])
    expect(payParts(booking(), true).map((p) => p.part)).toEqual(['deposit']) // pending: the deposit only
    expect(payParts(approved, false)).toEqual([])
    expect(payParts({ ...approved, status: 'cancelled' }, true)).toEqual([])
    expect(payParts({ ...approved, deposit_state: 'held', fee_paid: true }, true)).toEqual([])
  })

  it('a fee over older dues takes them too, and the button says so before the card is taken', () => {
    const [fee] = payParts({ ...approved, deposit_state: 'held', fee_to_clear: '15400.00' }, true)
    expect(fee).toEqual({
      part: 'fee',
      label: 'Pay $15,400 by card: the $3,000 fee and what is owed before it',
      amount: '15400.00',
      what: 'Gazebo booking fee, with what is owed before it',
      note: FEE_WITH_OLDER,
    })
    // An older server sent no `fee_to_clear`: the fee's own figure, as before.
    expect(payParts({ ...approved, deposit_state: 'held', fee_to_clear: undefined as never }, true)[0]).toMatchObject({ amount: '3000.00', note: null })
  })

  it('a test card\'s deposit is never read as money held', () => {
    expect(depositLineWithDemo(booking({ deposit_state: 'held', deposit_demonstration: true }))).toBe('$5,000 deposit held by the estate: paid with a test card, so no money moved')
    expect(depositLineWithDemo(booking({ deposit_state: 'held' }))).toBe(depositLine(booking({ deposit_state: 'held' })))
  })
})
