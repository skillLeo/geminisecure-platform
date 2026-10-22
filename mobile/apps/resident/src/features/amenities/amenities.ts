/**
 * Amenity bookings — the pure logic (no React, no UI kit), unit-tested without a phone.
 *
 * WHAT THE SERVER SENDS (`amenities.index`): whether this household MAY BOOK, and for each amenity a
 * name, a picture key, a capacity, opening hours (`HH:MM:SS`, where `24:00:00` is midnight at the END
 * of the day), ONE booking fee and ONE deposit as decimal strings, how far ahead it takes bookings and
 * how late a booking may be cancelled. A booking (`bookings.*`) carries its own snapshot of the fee,
 * the deposit and the deposit's state, a status, and the moment until which the app may cancel it.
 *
 * WHEN THE DIARY IS TAKEN (M21 §1.5): each amenity's `taken` periods — every pending or confirmed booking and every
 * period the estate closed, the hours only, never whose. The hour picker marks those hours booked and will not
 * stretch a request across one; the server still has the last word when the request is sent (a slot is first come,
 * and a saved copy of the list can be old).
 *
 * SINCE M47 §8–§10 IT ALSO SENDS what the boards draw: a description and a place ("garden setting · Phase 2"), an
 * HOURLY rate ("$2,000/hr"), the blocks a day is booked in (two hours: "9–11 AM"), the house rules, and — for a booking
 * paid for in the app (`bookings.book`), confirmed at once — a confirmation code, the guests' gate QR and the card. Still
 * nothing here multiplies, adds or totals an amount: "2 hrs × $2,000 … $4,000" and "Total due today … $9,000" are the
 * server's quote (`bookings.quote`), shown as sent. Whether the fee is paid (`fee_paid`, `fee_outstanding`) is the
 * server's, from the ledger; paying the fee or the deposit is the card checkout's demonstration payment (M12 §2.14,
 * `payParts`).
 *
 * TIMES ARE THE ESTATE'S. The resident picks "Saturday, 11 to 1" meaning the clock on the wall where they
 * live; the request is built in the SITE's zone (`atSiteZone`) whatever the phone's own zone says
 * — and it is SENT with that zone's offset, because the server checks opening hours against the clock time
 * it was given.
 */

import { atSiteZone, dateShort, siteDayIso, siteNoon, siteParts, money, moneySign, time12, timeTag, withClockTag, yourTimeLine } from '@gs/core/lib'
import type { Api } from '@gs/core/api'
import type { IconName } from '@gs/core'

type ApiAmenity = Api.AmenitiesIndexResponse['items'][number]
/** A period the amenity's diary is taken: two instants (`amenities.index`, M21 §1.5). */
export type Taken = ApiAmenity['taken'][number]
/**
 * The generated types infer `capacity` and `booking_window_days` as strings; the server sends integers. `taken` may be
 * missing from a list saved on the phone before the server sent it — then nothing is marked, as before.
 */
export type Amenity = Omit<ApiAmenity, 'capacity' | 'booking_window_days' | 'taken'> & { capacity: number | string | null; booking_window_days: number | string | null; taken?: readonly Taken[] }
type ApiBooking = Api.BookingsIndexResponse['items'][number]
export type Booking = Omit<ApiBooking, 'guests'> & { guests: number | string | null }
export type BookingStatus = Booking['status']

export const PAUSED = 'Bookings are paused for this household: contact the estate office'
export const NOT_AVAILABLE = 'Bookings are not available at this estate'
export const HOW_PAID = 'The fee is paid to the estate the same way as your dues'

/** With the card checkout on (M12 §2.6, §2.14): where the fee and deposit can be paid, said before anybody books. */
export const HOW_PAID_CARD =
  'The fee and the deposit can be paid in the app by card, a demonstration payment with a test card, so no money moves, or to the estate the same way as your dues'
export const PENDING_LINE = 'Requested: waiting for the estate to confirm'
/** A request whose time came and went with no decision: the status is still `pending`, but nobody is waiting any more. */
export const LAPSED_LINE = 'The estate did not decide on this request before its time passed'
/** Under a declined or cancelled booking: no fee, no "how it is paid" — and no claim either way about what the office has charged. */
export const CLOSED_MONEY = 'Nothing is paid in this app. Questions about a fee or a deposit on this booking are for the estate office.'

const DAY_MINUTES = 24 * 60
const pad = (n: number) => String(n).padStart(2, '0')

/* ---- numbers the server sends as numbers ----------------------------------------------------------- */

/** A whole number from the server, or null — never NaN. */
export function wholeOf(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') return null
  const n = typeof value === 'number' ? value : Number.parseInt(value, 10)
  return Number.isInteger(n) && n >= 0 ? n : null
}

/* ---- pictures --------------------------------------------------------------------------------------- */

const ICONS: Record<string, IconName> = { gazebo: 'amenity-icon-2', clubhouse: 'amenity-icon', pavilion: 'pm-icon-2', pool: 'amenity-icon-3' }
/** The estate's picture key → the board's picture. A key this app does not know gets the pavilion, the server's own default. */
export const iconFor = (key: string | null | undefined): IconName => ICONS[String(key ?? '').toLowerCase()] ?? 'pm-icon-2'

/* ---- opening hours ---------------------------------------------------------------------------------- */

/** "09:00:00" → 540 · "24:00:00" → 1440 · "22:00" → 1320. Anything unreadable is null. */
export function minutesOf(time: string | null | undefined): number | null {
  const m = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec((time ?? '').trim())
  if (!m) return null
  const total = Number(m[1]) * 60 + Number(m[2])
  return total >= 0 && total <= DAY_MINUTES ? total : null
}

/** "8:00 AM" · "Midnight" (the server's own word for the twenty-fourth hour) · "Noon". */
export function clockLabel(minutes: number): string {
  if (minutes === 0 || minutes >= DAY_MINUTES) return 'Midnight'
  if (minutes === 12 * 60) return 'Noon'
  // A clock face, not a moment: any day does, read on the site's clock.
  return time12(atSiteZone('2026-01-01', minutes))
}

/** "8:00 AM – 10:00 PM", or null when the server's hours cannot be read. */
export function hoursLabel(a: Pick<Amenity, 'opens_at' | 'closes_at'>): string | null {
  const open = minutesOf(a.opens_at)
  const close = minutesOf(a.closes_at)
  return open === null || close === null || close <= open ? null : withClockTag(`${clockLabel(open)} – ${clockLabel(close)}`)
}

/** "Up to 25 guests · 8:00 AM – 10:00 PM" — what the server knows about a place, and nothing it does not. */
export function detailLine(a: Pick<Amenity, 'capacity' | 'opens_at' | 'closes_at'>): string {
  const capacity = wholeOf(a.capacity)
  const hours = hoursLabel(a)
  return [capacity ? `Up to ${capacity} guests` : null, hours ? `Open ${hours}` : null].filter(Boolean).join(' · ')
}

/** "Up to 30 guests" — the start of the line under a name on boards 21 and 22. */
export function capacityLine(a: Pick<Amenity, 'capacity'>): string {
  const capacity = wholeOf(a.capacity)
  return capacity ? `Up to ${capacity} guests` : ''
}

const tidy = (text: string | null | undefined): string => (text ?? '').trim().replace(/\s+/g, ' ')

/** "Up to 120 guests · kitchen & AV included" — board 21's line under a name: how many it holds, then what it is. */
export function describeLine(a: Pick<Amenity, 'capacity'> & Partial<Pick<Amenity, 'description'>>): string {
  return [capacityLine(a), tidy(a.description)].filter(Boolean).join(' · ')
}

/** "Up to 30 guests · garden setting · Phase 2" — board 22's hero: and where it is on the estate. */
export function heroLine(a: Pick<Amenity, 'capacity'> & Partial<Pick<Amenity, 'description' | 'place'>>): string {
  return [describeLine(a), tidy(a.place)].filter(Boolean).join(' · ')
}

/* ---- money: shown, never computed ------------------------------------------------------------------- */

const DECIMAL = /^\s*-?\d+(?:\.\d+)?\s*$/

/**
 * True only when the server SENT a zero ("0.00"). `moneySign` also answers 0 for a string it cannot
 * read — and "No fee" is a statement about money the app may make only on the server's word, so an
 * unreadable amount is shown as it was sent (that is what `money()` does) and never called nothing.
 */
export const isNil = (amount: string | null | undefined): boolean => DECIMAL.test(amount ?? '') && moneySign(amount) === 0

/** "$3,000 fee · $5,000 deposit" · "No fee · no deposit". Two amounts side by side; never a sum. */
export function priceLine(fee: string, deposit: string): string {
  const f = isNil(fee) ? 'No fee' : `${money(fee, 'compact')} fee`
  const d = isNil(deposit) ? 'no deposit' : `${money(deposit, 'compact')} deposit`
  return `${f} · ${d}`
}

/** The hourly rate the server sent, or null for a place with one flat fee (or a list saved before rates existed). */
const rateOf = (a: Partial<Pick<Amenity, 'hourly_rate'>>): string | null => (a.hourly_rate && DECIMAL.test(a.hourly_rate) && !isNil(a.hourly_rate) ? a.hourly_rate : null)

/** "$2,000/hr · $5,000 deposit" — board 21's price, by the hour as the estate charges; a flat fee says itself (`priceLine`). */
export function priceOf(a: Pick<Amenity, 'booking_fee' | 'deposit'> & Partial<Pick<Amenity, 'hourly_rate'>>): string {
  const rate = rateOf(a)
  if (rate === null) return priceLine(a.booking_fee, a.deposit)
  return `${money(rate, 'compact')}/hr · ${isNil(a.deposit) ? 'no deposit' : `${money(a.deposit, 'compact')} deposit`}`
}

export type PriceRow = { label: string; value: string }

/** The rows of the terms card. There is deliberately NO total row: the app does no arithmetic on money. */
export function priceRows(fee: string, deposit: string): PriceRow[] {
  return [
    { label: 'Booking fee', value: isNil(fee) ? 'No fee' : money(fee) },
    { label: 'Refundable deposit', value: isNil(deposit) ? 'None' : money(deposit) },
  ]
}

const DEPOSIT_LINE: Record<Booking['deposit_state'], string | null> = {
  none: null,
  awaiting: 'not received yet',
  held: 'held by the estate',
  refunded: 'refunded',
  forfeited: 'kept by the estate',
}

/**
 * Declined, cancelled or expired (a request the estate never decided before its time, M53 §2): the booking will not
 * take place, so nothing more is asked of the household for it.
 */
export const isClosed = (b: Pick<Booking, 'status'>): boolean => b.status === 'declined' || b.status === 'cancelled' || b.status === 'expired'

/**
 * "$5,000 deposit refunded" — the server's state for the deposit, in a homeowner's words. No date: the server sends none.
 *
 * The server now moves every deposit on: a booking declined or cancelled before its deposit
 * arrived answers `none`, and one cancelled in the app answers `refunded` at once. An older server left the
 * first of those `awaiting` for ever — "Not received yet" under "Cancelled" would read as money the household
 * still owes, so it is still not said. A deposit the estate really holds (or returned, or kept) is always said.
 *
 * KEPT IN PART says how much (`deposit_kept`): "the deposit was kept" over a household that got most of it back
 * would be the app overstating what the estate took. The two figures are the server's own; nothing is subtracted.
 */
export function depositLine(b: Pick<Booking, 'deposit' | 'deposit_state' | 'status'> & Partial<Pick<Booking, 'deposit_kept'>>): string | null {
  const state = DEPOSIT_LINE[b.deposit_state] ?? null
  if (state === null || isNil(b.deposit)) return null
  if (b.deposit_state === 'awaiting' && isClosed(b)) return null
  const kept = b.deposit_kept ?? null
  if (b.deposit_state === 'forfeited' && kept !== null && DECIMAL.test(kept) && !isNil(kept) && kept !== b.deposit) {
    return `${money(kept, 'compact')} of your ${money(b.deposit, 'compact')} deposit ${state}`
  }
  return `${money(b.deposit, 'compact')} deposit ${state}`
}

/** A test card paid the deposit (M12 §2.6): said beside its state, so a demonstration is never read as money held. */
export function depositLineWithDemo(b: Parameters<typeof depositLine>[0] & Partial<Pick<Booking, 'deposit_demonstration'>>): string | null {
  const line = depositLine(b)
  return line && b.deposit_demonstration === true ? `${line}: paid with a test card, so no money moved` : line
}

/**
 * Board 31's deposit note, in its words, while the deposit is still to come or is held: "Your $5,000 deposit is refunded
 * within 3 business days after a condition check, straight back to Visa •••• 4417" — the server's rule (a held deposit
 * goes back three days after the event unless the estate makes a claim), and the card when it was paid by one in the
 * app (`paid_with`, M47 §10). A deposit paid to the estate is paid back by the estate office, so no card is named for it.
 * Once the deposit has moved on — refunded, kept — its state; with no deposit, nothing.
 */
export function depositNoteOf(b: Parameters<typeof depositLineWithDemo>[0] & Partial<Pick<Booking, 'paid_with'>>): string | null {
  if (isNil(b.deposit) || b.deposit_state === 'none') return null
  if ((b.deposit_state === 'awaiting' || b.deposit_state === 'held') && !isClosed(b)) {
    const card = b.deposit_state === 'held' && b.paid_with ? `, straight back to ${tidy(b.paid_with)}` : ''
    return `Your ${money(b.deposit, 'compact')} deposit is refunded within 3 business days after a condition check${card}.`
  }
  const state = depositLineWithDemo(b)
  return state ? `${state}.` : null
}

/**
 * "$4,000 fee paid" · "$4,000 fee to pay" · "$4,000 fee — paid once the estate approves" (M12 §2.14). From the server's
 * `fee_paid` and `fee_outstanding`; an older server sent neither, and then nothing is said rather than guessed.
 */
export function feeLine(b: Pick<Booking, 'booking_fee' | 'status'> & Partial<Pick<Booking, 'fee_paid' | 'fee_outstanding'>>): string | null {
  if (isNil(b.booking_fee) || b.fee_paid === undefined) return null
  if (b.fee_paid) return `${money(b.booking_fee, 'compact')} fee paid`
  if (isClosed(b)) return null
  if (b.status === 'pending') return `${money(b.booking_fee, 'compact')} fee: paid once the estate approves`
  const owed = b.fee_outstanding && DECIMAL.test(b.fee_outstanding) && !isNil(b.fee_outstanding) ? b.fee_outstanding : b.booking_fee
  return `${money(owed, 'compact')} fee to pay`
}

/** `amount` is what the card is charged — for the fee, the server's `fee_to_clear` — and `note` says why, when it is more than the fee. */
export type PayPart = { part: 'fee' | 'deposit'; label: string; amount: string; what: string; note: string | null }

/** Why the fee's card payment is more than the fee (D-179): said before the card is taken, never after. */
export const FEE_WITH_OLDER =
  'Payments clear the oldest charge first, so the fee is paid together with what your household owes from before it. Paying the fee alone would clear the older charges and leave the fee owing.'

const decimalOrNull = (v: string | null | undefined): string | null => (v && DECIMAL.test(v) && !isNil(v) ? v : null)

/**
 * What of a booking can be paid in the app now, with a test card (M12 §2.14): the fee once the estate has approved
 * the booking and while something is owed on it; the deposit while the estate is still waiting for it. Nothing on a
 * booking that was declined or cancelled, and no deposit on one that is over.
 *
 * THE FEE TAKES WHAT IS OWED BEFORE IT. Payments clear the oldest charge first, so the server's `fee_to_clear` — the
 * fee and every older charge still owing — is what the card is charged, and the button says so in its own words.
 * Both figures are the server's; nothing is added or subtracted here.
 */
export function payParts(
  b: Pick<Booking, 'booking_fee' | 'deposit' | 'deposit_state' | 'status' | 'amenity'> & Partial<Pick<Booking, 'fee_paid' | 'fee_outstanding' | 'fee_to_clear'>>,
  cardOn: boolean,
): PayPart[] {
  if (!cardOn || isClosed(b)) return []
  const parts: PayPart[] = []
  const owed = decimalOrNull(b.fee_outstanding)
  const toClear = decimalOrNull(b.fee_to_clear) ?? owed
  if ((b.status === 'confirmed' || b.status === 'completed') && b.fee_paid === false && owed !== null && toClear !== null) {
    const more = toClear !== owed
    parts.push({
      part: 'fee',
      label: more ? `Pay ${money(toClear, 'compact')} by card: the ${money(owed, 'compact')} fee and what is owed before it` : `Pay the ${money(owed, 'compact')} fee by card`,
      amount: toClear,
      what: more ? `${b.amenity.name} booking fee, with what is owed before it` : `${b.amenity.name} booking fee`,
      note: more ? FEE_WITH_OLDER : null,
    })
  }
  if (b.status !== 'completed' && b.deposit_state === 'awaiting' && !isNil(b.deposit)) {
    parts.push({ part: 'deposit', label: `Pay the ${money(b.deposit, 'compact')} deposit by card`, amount: b.deposit, what: `${b.amenity.name} deposit`, note: null })
  }
  return parts
}

/** "Cancel in the app up to 48 hours before it starts." */
export function cancellationTerms(hours: number | string | null | undefined): string {
  const h = wholeOf(hours)
  if (h === null || h === 0) return 'You can cancel in the app any time before it starts.'
  return `You can cancel in the app up to ${h} hour${h === 1 ? '' : 's'} before it starts. After that, speak to the estate office.`
}

/* ---- days ------------------------------------------------------------------------------------------- */

export type DayOption = { iso: string; label: string; long: string }

/** How many days ahead the picker offers when the estate sets no limit of its own. */
export const DEFAULT_WINDOW_DAYS = 60
const MAX_DAY_CHIPS = 90

/** "20 Sep 2026" — the one way a date is written (M98, 12 Oct 2026; it read "Saturday, 20 September"), on the estate's calendar. */
export function dayLong(input: string | Date): string {
  return dateShort(input)
}

/** "20 Sep 2026" (M98; it read "Sat, 20 Sep"). */
export function dayShort(input: string | Date): string {
  return dateShort(input)
}

/** The estate's calendar day `offset` days after the one `at` falls on there. */
export const siteDay = (at: Date, offset = 0): string => siteDayIso(at, offset)

/** Today … the last day the estate takes bookings for (`booking_window_days`), as chips. */
export function dayOptions(at: Date, windowDays: number | string | null | undefined): DayOption[] {
  const limit = Math.min(wholeOf(windowDays) ?? DEFAULT_WINDOW_DAYS, MAX_DAY_CHIPS)
  return Array.from({ length: limit + 1 }, (_, offset) => {
    const iso = siteDay(at, offset)
    return { iso, label: offset === 0 ? 'Today' : offset === 1 ? 'Tomorrow' : dayShort(siteNoon(iso)), long: dayLong(siteNoon(iso)) }
  })
}

/**
 * "2026-09-20" + 660 → the instant 11 AM is AT THE SITE, with the site's own offset; 1440 rolls to
 * midnight of the NEXT day, which is how the server reads a booking that runs to closing.
 */
export const atSite = (dayIso: string, minutes: number): string => atSiteZone(dayIso, minutes)

/* ---- the hour blocks -------------------------------------------------------------------------------- */

export type Slot = { index: number; from: number; to: number; label: string; spoken: string }

const hourWord = (minutes: number) => {
  const h = Math.floor(minutes / 60) % 24
  const mm = minutes % 60
  return `${h % 12 === 0 ? 12 : h % 12}${mm ? `:${pad(mm)}` : ''}`
}
const meridiem = (minutes: number) => (Math.floor(minutes / 60) % 24 < 12 ? 'AM' : 'PM')

/** "9–11 AM" · "11–1 PM" — the board's way of writing a block. The last hour of a day that runs to midnight says so. */
export const slotLabel = (from: number, to: number): string => (to >= DAY_MINUTES ? `${hourWord(from)} ${meridiem(from)}–Midnight` : `${hourWord(from)}–${hourWord(to)} ${meridiem(to)}`)

/**
 * The day's blocks from opening to closing, as long as the estate books this place in (`slot_minutes`: two hours gives
 * board 22's "9–11 AM · 11–1 PM · …"; one hour when it does not say). The last is shorter when the hours do not divide.
 */
export function slotsFor(a: Pick<Amenity, 'opens_at' | 'closes_at'> & Partial<Pick<Amenity, 'slot_minutes'>>): Slot[] {
  const open = minutesOf(a.opens_at)
  const close = minutesOf(a.closes_at)
  if (open === null || close === null || close <= open) return []
  const step = Math.max(15, wholeOf(a.slot_minutes) ?? 60)
  const slots: Slot[] = []
  for (let from = open, index = 0; from < close; from += step, index++) {
    const to = Math.min(from + step, close)
    slots.push({ index, from, to, label: slotLabel(from, to), spoken: `${clockLabel(from)} to ${clockLabel(to)}` })
  }
  return slots
}

export type Range = { start: number; end: number }

/**
 * Tapping blocks builds ONE unbroken stretch: the first tap picks a block, a tap outside the stretch
 * extends it to that block, a tap inside starts again from that block, and tapping a lone block clears it.
 */
export function pickSlot(range: Range | null, index: number): Range | null {
  if (range === null) return { start: index, end: index }
  if (range.start === range.end && range.start === index) return null
  if (index < range.start) return { start: index, end: range.end }
  if (index > range.end) return { start: range.start, end: index }
  return { start: index, end: index }
}

/** A block on `dayIso` that has already begun cannot be asked for: the server takes only bookings that start in the future. */
export const slotPassed = (dayIso: string, slot: Slot, at: Date): boolean => new Date(atSite(dayIso, slot.from)).getTime() <= at.getTime()

/** Whether a block on `dayIso` overlaps a period already booked or closed (M21 §1.5) — the server would refuse it. */
export function slotTaken(dayIso: string, slot: Pick<Slot, 'from' | 'to'>, taken: readonly Taken[] | undefined): boolean {
  if (!taken || taken.length === 0) return false
  const from = new Date(atSite(dayIso, slot.from)).getTime()
  const to = new Date(atSite(dayIso, slot.to)).getTime()
  return taken.some((p) => new Date(p.starts_at).getTime() < to && new Date(p.ends_at).getTime() > from)
}

/**
 * Whether `dayIso` still has an hour that can be asked for — the board's "Available today" (resident-app-21): one that
 * has not begun, is not booked or closed (M21 §1.5), and starts inside the amenity's booking window. Read from the
 * diary the server sent; the server still has the last word when a request is sent.
 */
export function freeOn(a: Pick<Amenity, 'opens_at' | 'closes_at' | 'booking_window_days' | 'taken'>, dayIso: string, at: Date): boolean {
  const days = wholeOf(a.booking_window_days)
  const latest = days === null ? Number.POSITIVE_INFINITY : at.getTime() + days * 86_400_000
  return slotsFor(a).some((slot) => !slotPassed(dayIso, slot, at) && !slotTaken(dayIso, slot, a.taken) && new Date(atSite(dayIso, slot.from)).getTime() <= latest)
}

/**
 * `pickSlot`, for a day with hours that cannot be had (passed, booked, closed): a tap on one of them changes nothing,
 * and a stretch that would run across one starts again from the block tapped — one request is one unbroken, free stretch.
 */
export function pickFree(range: Range | null, index: number, blocked: (index: number) => boolean): Range | null {
  if (blocked(index)) return range
  const next = pickSlot(range, index)
  if (next === null) return null
  for (let i = next.start; i <= next.end; i++) if (blocked(i)) return { start: index, end: index }
  return next
}

/** "11:00 AM – 1:00 PM JA" — the estate's clock, tagged once at the end (M19A §4.2). */
export function timeRange(startsAt: string | Date, endsAt: string | Date): string {
  const end = siteParts(endsAt)
  const endsAtMidnight = end.hour === 0 && end.minute === 0
  return withClockTag(`${time12(startsAt)} – ${endsAtMidnight ? 'Midnight' : time12(endsAt)}`)
}

/** "11:00 AM – 1:00 PM" — board 31's way of writing a booking's hours: the estate's clock, in the board's own format. */
export function timePlain(startsAt: string | Date, endsAt: string | Date): string {
  const end = siteParts(endsAt)
  return `${time12(startsAt)} – ${end.hour === 0 && end.minute === 0 ? 'Midnight' : time12(endsAt)}`
}

/** "2 hours" · "1 hour 30 minutes" — a length of time, not an amount of money. */
export function lengthLabel(fromMinutes: number, toMinutes: number): string {
  const total = Math.max(0, toMinutes - fromMinutes)
  const h = Math.floor(total / 60)
  const m = total % 60
  return [h ? `${h} hour${h === 1 ? '' : 's'}` : null, m ? `${m} minutes` : null].filter(Boolean).join(' ') || '0 minutes'
}

/** "2 hrs" · "1 hr" · "1.5 hrs" — board 22's way of saying how long a booking is ("Gazebo rate — 2 hrs × $2,000"). */
export function hoursShort(fromMinutes: number, toMinutes: number): string {
  const hours = Math.max(0, toMinutes - fromMinutes) / 60
  const shown = Number.isInteger(hours) ? String(hours) : hours.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')
  return `${shown} ${hours === 1 ? 'hr' : 'hrs'}`
}

/**
 * Board 22's summary card for a REQUEST (`bookings.store` — card payments off): the fee as the server states it — by the
 * hour ("Gazebo rate — $2,000/hr"), or one flat fee with the length chosen ("Gazebo rate — 2 hrs") — and the deposit.
 * Each amount is shown as sent; nothing is multiplied, added or totalled here.
 */
export function checkoutRows(name: string, fee: string, deposit: string, length: string | null, hourly: string | null = null): PriceRow[] {
  return [
    hourly ? { label: `${name} rate`, value: `${money(hourly, 'compact')}/hr` } : { label: length ? `${name} rate: ${length}` : `${name} rate`, value: isNil(fee) ? 'No fee' : money(fee, 'compact') },
    { label: 'Refundable damage deposit', value: isNil(deposit) ? 'None' : money(deposit, 'compact') },
  ]
}

/**
 * Board 22's "Total due today" for a REQUEST. It is sent with no payment — the fee is paid once the estate approves, the
 * deposit from My bookings or the way dues are (M12 §2.14) — so what is due today is nothing: said, not worked out.
 */
export const DUE_TODAY: PriceRow = { label: 'Total due today', value: money('0.00', 'compact') }

export type Quote = Api.BookingsQuoteResponse

/** Said only when the household owes from before: a payment clears the oldest charge first (D-179), so the card pays it too. */
export const OWED_BEFORE = 'Owed from before: paid first'
/** Said only when the household has paid ahead: that pays for the fee first. */
export const PAID_AHEAD = 'Paid ahead'
/** Board 22's line under the total, word for word. */
export const DEPOSIT_NOTE = 'Deposit is refunded after a condition check within 3 business days of your event.'

/**
 * Board 22's summary card for "Confirm & pay" (M47 §9.5), from the server's quote (`bookings.quote`): every amount as
 * the server worked it out and none added here — "Gazebo rate — 2 hrs × $2,000 · $4,000", what is owed from before and
 * paid first (only when there is some), money paid ahead (likewise), "Refundable damage deposit · $5,000", and the
 * total, "Total due today · $9,000".
 */
export function quoteRows(name: string, q: Pick<Quote, 'minutes' | 'hourly_rate' | 'fee' | 'owed_before' | 'credit' | 'deposit' | 'total'>): { rows: PriceRow[]; total: PriceRow } {
  const rate = q.hourly_rate && DECIMAL.test(q.hourly_rate) && !isNil(q.hourly_rate) ? ` × ${money(q.hourly_rate, 'compact')}` : ''
  const rows: PriceRow[] = [{ label: `${name} rate: ${hoursShort(0, q.minutes)}${rate}`, value: isNil(q.fee) ? 'No fee' : money(q.fee, 'compact') }]
  if (DECIMAL.test(q.owed_before) && !isNil(q.owed_before)) rows.push({ label: OWED_BEFORE, value: money(q.owed_before, 'compact') })
  if (DECIMAL.test(q.credit) && !isNil(q.credit)) rows.push({ label: PAID_AHEAD, value: `−${money(q.credit, 'compact')}` })
  rows.push({ label: 'Refundable damage deposit', value: isNil(q.deposit) ? 'None' : money(q.deposit, 'compact') })
  return { rows, total: { label: 'Total due today', value: money(q.total, 'compact') } }
}

/** Before any hours are chosen: the rate and the deposit, and a total that waits for the hours. */
export function termsRows(a: Pick<Amenity, 'name' | 'booking_fee' | 'deposit'> & Partial<Pick<Amenity, 'hourly_rate'>>): { rows: PriceRow[]; total: PriceRow } {
  return { rows: checkoutRows(a.name, a.booking_fee, a.deposit, null, rateOf(a)), total: { label: 'Total due today', value: '—' } }
}

/* ---- the house rules (M47 §9.6) --------------------------------------------------------------------- */

/** ", including noise cut-off at 9:00 PM and no glassware on the lawn." — board 22's sentence after the rules' name; "." with none. */
export const rulesTailOf = (a: Partial<Pick<Amenity, 'rules_summary'>>): string => (tidy(a.rules_summary) ? `, including ${tidy(a.rules_summary)}.` : '.')

/** The house rules, one per line, as the estate wrote them — what the gold link opens. None when the estate has none. */
export const rulesOf = (a: Partial<Pick<Amenity, 'rules'>>): string[] => (a.rules ?? '').split('\n').map(tidy).filter(Boolean)

/* ---- the request ------------------------------------------------------------------------------------ */

/** What the board asks for: a day and an unbroken stretch of its blocks. "Number of guests" and the note are gone (M47 §9d–f). */
export type RequestInput = { day: string; range: Range | null }
export type RequestErrors = Partial<Record<'day' | 'time', string>>

export const TAKEN_LINE = 'Part of that time is already booked. Choose hours that are free: the ones not struck out.'

export function validateRequest(input: RequestInput, amenity: Pick<Amenity, 'name' | 'opens_at' | 'closes_at' | 'booking_window_days' | 'taken'> & Partial<Pick<Amenity, 'slot_minutes'>>, at: Date): RequestErrors {
  const errors: RequestErrors = {}
  const slots = slotsFor(amenity)
  const first = input.range ? slots[input.range.start] : undefined
  const last = input.range ? slots[input.range.end] : undefined

  const days = wholeOf(amenity.booking_window_days)
  const tooFar = days === null ? 'Choose a day from the list.' : `The ${amenity.name} takes bookings up to ${days} days ahead. Choose an earlier day or time.`
  if (!dayOptions(at, amenity.booking_window_days).some((d) => d.iso === input.day)) errors.day = tooFar

  if (!first || !last) errors.time = 'Choose a time slot: tap the first one you need, then the last.'
  else {
    const starts = new Date(atSite(input.day, first.from)).getTime()
    if (starts <= at.getTime()) errors.time = 'That time has already started. Choose a later one.'
    // The server measures its window to the minute, not to the day.
    else if (days !== null && starts > at.getTime() + days * 86_400_000) errors.day = tooFar
    else if (input.range && slots.slice(input.range.start, input.range.end + 1).some((slot) => slotTaken(input.day, slot, amenity.taken))) errors.time = TAKEN_LINE
  }
  return errors
}

/** The stretch chosen, as the two instants the server takes — or null when nothing is chosen. */
export function hoursOf(input: RequestInput, amenity: Pick<Amenity, 'opens_at' | 'closes_at'> & Partial<Pick<Amenity, 'slot_minutes'>>): { starts_at: string; ends_at: string } | null {
  const slots = slotsFor(amenity)
  const first = input.range ? slots[input.range.start] : undefined
  const last = input.range ? slots[input.range.end] : undefined
  return first && last ? { starts_at: atSite(input.day, first.from), ends_at: atSite(input.day, last.to) } : null
}

/** A REQUEST (card payments off), only once `validateRequest` has found nothing. */
export function toRequest(amenityId: number | string, input: RequestInput, amenity: Pick<Amenity, 'opens_at' | 'closes_at'> & Partial<Pick<Amenity, 'slot_minutes'>>): Api.BookingsStoreRequest | null {
  const hours = hoursOf(input, amenity)
  const id = wholeOf(amenityId)
  return hours && id !== null ? { amenity_id: id, ...hours } : null
}

/** A 422's field errors, put on the control that can fix them. */
export function fieldErrorsOf(fieldErrors: Record<string, string[]>): RequestErrors {
  const errors: RequestErrors = {}
  const time = fieldErrors.starts_at?.[0] ?? fieldErrors.ends_at?.[0]
  if (time) errors.time = time
  return errors
}

/* ---- a booking's standing --------------------------------------------------------------------------- */

/** No red: a declined booking needs the resident's attention, not an emergency's (M12 §4.4, D-185). */
export type Tone = 'navy' | 'amber' | 'slate'
export type Standing = { word: string; tone: Tone; title: string; line: string }

/**
 * What a booking IS, in the server's terms. `pending` is a request and is never called confirmed —
 * only the estate's decision (`confirmed`) earns that word.
 */
export function standingOf(b: Pick<Booking, 'status' | 'declined_reason' | 'amenity'> & Partial<Pick<Booking, 'ends_at'>>, at?: Date): Standing {
  const place = `the ${b.amenity.name}`
  const lapsed = at !== undefined && b.ends_at !== undefined && new Date(b.ends_at).getTime() <= at.getTime()
  switch (b.status) {
    case 'confirmed':
      return { word: 'Confirmed', tone: 'navy', title: 'Booking confirmed', line: `The estate has confirmed your booking of ${place}` }
    case 'declined':
      return { word: 'Declined', tone: 'amber', title: 'Booking declined', line: b.declined_reason ? `The estate declined this request: ${b.declined_reason}` : 'The estate declined this request. The estate office can tell you why.' }
    case 'cancelled':
      return { word: 'Cancelled', tone: 'slate', title: 'Booking cancelled', line: `Your booking of ${place} was cancelled` }
    case 'completed':
      return { word: 'Completed', tone: 'amber', title: 'Booking completed', line: `Your booking of ${place} has taken place` }
    // M53 §2: nobody decided the request before its time. Never called declined: nobody declined it.
    case 'expired':
      return { word: 'Expired', tone: 'slate', title: 'Request expired', line: LAPSED_LINE }
    case 'pending':
    default:
      return { word: 'Requested', tone: 'amber', title: 'Booking requested', line: lapsed ? LAPSED_LINE : PENDING_LINE }
  }
}

const isOpen = (b: Pick<Booking, 'status'>) => b.status === 'pending' || b.status === 'confirmed'

/** Still to come: asked for or confirmed, and not over yet. Everything else is the past. */
export const isUpcoming = (b: Pick<Booking, 'status' | 'ends_at'>, at: Date): boolean => isOpen(b) && new Date(b.ends_at).getTime() > at.getTime()

/**
 * Whether a booking needs the household (M12 §2.14): it is still to come and something on it is to pay — the fee
 * once the estate has approved it, or the deposit the estate is waiting for — however it is then paid. What the dot
 * on Home's "Book amenity" tile and the count on "My bookings" count.
 */
export const needsAttention = (b: Booking, at: Date): boolean => isUpcoming(b, at) && payParts(b, true).length > 0

export function splitBookings<T extends Pick<Booking, 'status' | 'starts_at' | 'ends_at'>>(items: readonly T[], at: Date): { upcoming: T[]; past: T[] } {
  const start = (b: T) => new Date(b.starts_at).getTime()
  return {
    upcoming: items.filter((b) => isUpcoming(b, at)).sort((p, q) => start(p) - start(q)),
    past: items.filter((b) => !isUpcoming(b, at)).sort((p, q) => start(q) - start(p)),
  }
}

export type CancelState = { can: true; until: string } | { can: false; reason: string | null }

/** The app may cancel only while the booking is open AND the server's own deadline has not passed. */
export function cancelStateOf(b: Pick<Booking, 'status' | 'starts_at' | 'cancellable_until'>, at: Date): CancelState {
  if (!isOpen(b)) return { can: false, reason: null }
  const until = new Date(b.cancellable_until).getTime()
  if (new Date(b.starts_at).getTime() <= at.getTime()) return { can: false, reason: 'This booking has already started: speak to the estate office.' }
  if (!Number.isFinite(until) || at.getTime() >= until) return { can: false, reason: `It is too late to cancel this in the app: that closed ${whenShort(b.cancellable_until)}. The estate office can still cancel it.` }
  return { can: true, until: `You can cancel in the app until ${whenShort(b.cancellable_until)}.` }
}

/** Said before a booking is cancelled — in the ConfirmSheet, under the question. */
export const CANCEL_BOOKING_LINE = 'This cannot be undone in the app. The slot goes back to the estate. A deposit the estate already holds is refunded: the estate office pays it back to you.'

/**
 * The question before a booking is cancelled, naming WHICH booking in full ("the Gazebo on Thu, 1 Oct · 11:00 AM –
 * 2:00 PM" — `BookingRow.which`): a household can hold two of the same amenity. The act is itself "cancel", so the
 * way out is "Keep the booking".
 */
export const cancelBookingSheet = (which: string): { title: string; lines: string[]; confirmLabel: string; cancelLabel: string } => ({
  title: `Cancel your booking of ${which}?`,
  lines: [CANCEL_BOOKING_LINE],
  confirmLabel: 'Cancel the booking',
  cancelLabel: 'Keep the booking',
})

/** "20 Sep 2026, 11:00 AM" (M98's one form) */
export const whenShort = (iso: string | Date): string => `${dayShort(iso)}, ${timeTag(iso)}`

/** Under a booking's time, the viewer's own clock when it differs (M19A §4.3); null at the estate. */
export const bookingYourTime = (b: Pick<Booking, 'starts_at' | 'ends_at'>): string | null => yourTimeLine(b.starts_at, b.ends_at)

/** "20 Sep 2026, 11:00 AM – 1:00 PM" (M98's one form) */
export const whenLine = (b: Pick<Booking, 'starts_at' | 'ends_at'>): string => `${dayShort(b.starts_at)}, ${timeRange(b.starts_at, b.ends_at)}`

export const guestsLine = (guests: number | string | null | undefined): string | null => {
  const n = wholeOf(guests)
  return n === null || n === 0 ? null : `${n} guest${n === 1 ? '' : 's'}`
}

export const findById = <T extends { id: number | string }>(id: string | undefined, items: readonly T[]): T | null => (id ? (items.find((b) => String(b.id) === id) ?? null) : null)
