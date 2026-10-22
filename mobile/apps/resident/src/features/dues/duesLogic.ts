/**
 * Dues — the pure logic. No React, no UI kit, so it is unit-tested without a phone.
 *
 * MONEY IS SHOWN, NEVER WORKED OUT. Every amount arrives from the ledger as a decimal STRING and
 * goes through `money()` / `moneySign()` (packages/core/lib/money.ts), which move characters and
 * never parse a number. Nothing here adds, subtracts, totals or rounds an amount. The one sum the
 * phone does — the statement's four ageing boxes (CURRENT / 30 / 60 / 90+ DAYS, M42 §5 #8) — is in
 * statement.ts: whole cents added as integers, and shown only when they add up to the ledger's
 * balance to the cent.
 *
 * WORDS A RESIDENT READS — the consoles' money words (M98, 12 Oct 2026: "Unpaid, never Due"): Unpaid · Overdue ·
 * Partly paid · Paid · Credited · Void, and two that are not a charge's status: Payment processing (a payment on its
 * way) and In arrears: access restricted. That last is said ONLY when the server says it: whether a household is
 * restricted is the estate's rule (threshold, written notice, payment plan) and the phone never works it out from a
 * number of days. "Due 14 Oct" as a DATE stays: only the status word changed.
 */

import { clockNameOf, dateShort, flags, siteParts, money, moneySign, sinceWords, timeTag } from '@gs/core/lib'
import type { Api } from '@gs/core/api'
import { successFixture } from '@/fidelity/dues'

export type Balance = Api.HouseholdsBalanceResponse
export type Charge = Api.InvoicesIndexResponse['items'][number]
export type StatementLine = Api.HouseholdsStatementResponse['items'][number]
export type Payment = Api.PaymentsIndexResponse['items'][number]
export type Receipt = Api.PaymentsReceiptResponse
export type PayIntent = Api.InvoicesPayIntentResponse

/** What is kept on the phone is kept WITH the moment it was fetched — a cached figure is never shown as current. */
export type Stamped<T> = { value: T; savedAt: string }

export type StatusWord = 'Unpaid' | 'Overdue' | 'Partly paid' | 'Paid' | 'Credited' | 'Void' | 'Payment processing' | 'In arrears: access restricted'
/**
 * No red. "Red stays reserved for urgent and life-safety, nothing else" (M12 §4.4, D-185): a bill that is overdue,
 * even a household in arrears, needs attention — amber, the word beside it saying which — and is never drawn in the
 * colour of a panic.
 */
export type StatusTone = 'navy-700' | 'amber-700' | 'slate-500'
export type Status = { word: StatusWord; tone: StatusTone }

/** A figure fetched longer ago than this carries its age, even with a connection. */
export const CURRENT_FOR_MS = 5 * 60_000

const DAY_MS = 86_400_000
const MONTHS: Record<string, string> = { '01': 'Jan', '02': 'Feb', '03': 'Mar', '04': 'Apr', '05': 'May', '06': 'Jun', '07': 'Jul', '08': 'Aug', '09': 'Sep', '10': 'Oct', '11': 'Nov', '12': 'Dec' }

/**
 * "2026-09-28" → "28 Sep 2026": the one way a date is written (M98, 12 Oct 2026, the consoles' form), with its year every
 * time, so "3 Jul" twice in a statement that runs back years is never two different days. A DATE has no time zone: it
 * is read as written, never through `new Date()` on a time.
 */
export function shortDate(date: string | null | undefined): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date ?? '')
  const month = m ? MONTHS[m[2]!] : undefined
  if (!m || !month) return null
  return `${m[3]!.replace(/^0/, '')} ${month} ${m[1]}`
}

/** "2026-10-02" → "2 Oct 2026": a date where there is room is written the same one way (M98). */
export const longDate = (date: string | null | undefined): string | null => shortDate(date)

/**
 * WHEN THE OFFICE RECEIVED A PAYMENT. The office records the DAY the money arrived (`received_on`),
 * and the server keeps that day as midnight UTC. Read through a clock, midnight UTC is 7:00 PM the
 * evening BEFORE at the site — a receipt dated Jul 3 would say "Jul 2, 7:00 PM", disagreeing with the
 * statement and with the estate's own printed receipt, and inventing a time nobody recorded. So an
 * instant with no time in it is read as the date it was written as, and shows no time. An instant
 * that does carry a time is a moment, and is shown in the site's zone like every other moment.
 */
const DAY_ONLY = /^(\d{4}-\d{2}-\d{2})T00:00:00(?:\.0+)?(?:Z|[+-]00:00)$/

export function receivedOn(instant: string): { date: string; time: string | null } {
  const day = DAY_ONLY.exec(instant)
  if (day) return { date: shortDate(day[1]) ?? instant, time: null }
  if (Number.isNaN(siteParts(instant).year)) return { date: instant, time: null }
  return { date: dateShort(instant), time: timeTag(instant) }
}

/** Whole days from today IN JAMAICA to a calendar date; negative once it has passed. */
export function daysUntil(date: string | null | undefined, at: Date): number | null {
  const m = /^(\d{4}-\d{2}-\d{2})/.exec(date ?? '')
  if (!m) return null
  const today = siteParts(at)
  return Math.round((new Date(`${m[1]}T00:00:00Z`).getTime() - Date.UTC(today.year, today.month, today.day)) / DAY_MS)
}

/** "as of 5 minutes ago" · "as of 12 Sep 2026, 3:40 PM" — the age every saved figure carries (M19A §4.1, M98). */
export function asOf(savedAt: string, at: Date): string {
  return `as of ${sinceWords(savedAt, at)}`
}

/** True only while the figure is from the server, just now. */
export function isCurrent(savedAt: string, at: Date, live: boolean): boolean {
  return live && at.getTime() - new Date(savedAt).getTime() <= CURRENT_FOR_MS
}

const days = (n: number) => `${n} day${n === 1 ? '' : 's'}`
const owed = (amount: string | null | undefined) => moneySign(amount) > 0

/* ---- one charge ------------------------------------------------------------------------------ */

/**
 * The server's status for a charge, in the consoles' words (M98): `due` is "Unpaid", never "Due", and there is no "Due
 * soon" any more: when it falls due is its date's to say ("Due 14 Oct 2026"), not its status's. `credited` and `void`
 * have their words ready, though the phone API sends neither yet (paid, part_paid, overdue, due).
 * AMBER IS FOR OVERDUE ONLY (the user, 9 Oct 2026, Home and Dues alike): unpaid and a part payment not yet past its date
 * read in navy; overdue, a part payment past its date, and arrears are amber.
 */
export function chargeStatus(charge: { status: string; due_on: string; outstanding: string }, at: Date): Status {
  switch (charge.status) {
    case 'paid':
      return { word: 'Paid', tone: 'slate-500' }
    case 'part_paid': {
      const left = daysUntil(charge.due_on, at)
      return { word: 'Partly paid', tone: left !== null && left < 0 ? 'amber-700' : 'navy-700' }
    }
    case 'overdue':
      return { word: 'Overdue', tone: 'amber-700' }
    case 'credited':
      return { word: 'Credited', tone: 'slate-500' }
    case 'void':
    case 'voided':
      return { word: 'Void', tone: 'slate-500' }
    case 'processing':
    case 'pending':
      return { word: 'Payment processing', tone: 'navy-700' }
    case 'due':
      return { word: 'Unpaid', tone: 'navy-700' }
    default:
      // A status this version does not know: say only what the amount itself says.
      return owed(charge.outstanding) ? { word: 'Unpaid', tone: 'navy-700' } : { word: 'Paid', tone: 'slate-500' }
  }
}

/* ---- the household's standing ------------------------------------------------------------------ */

export type Standing = { status: Status | null; restricted: boolean; warning: string | null; warningTone: 'amber' | 'navy' }

/**
 * `access_restricted` is NOT in today's balance contract. It is read here so that the day the server
 * says it, the app says it — and until then the app never claims a restriction it cannot know about.
 */
export function serverSaysRestricted(balance: Balance): boolean {
  return (balance as { access_restricted?: unknown }).access_restricted === true
}

/**
 * WHAT ARREARS PUT ON HOLD — named only as far as this build lets the resident do it.
 *
 * At the gate, arrears hold a household's visitor passes AND its amenity bookings, and all three
 * sentences below said so. With bookings switched off for the pilot (`flags.amenityBookings`),
 * naming them sends a resident looking for a bookings screen that is not there — the same
 * journey the notification row and the help answer used to send them on. This is not softening what
 * the estate does: it is not listing a consequence the app cannot show them the other side of. The
 * clause comes back whole, in each sentence's own words, the day the flag is turned on.
 */
const HELD = flags.amenityBookings ? "your guests' visitor passes and amenity bookings" : "your guests' visitor passes"
const HELD_PLAIN = flags.amenityBookings ? "your guests' visitor passes and bookings" : "your guests' visitor passes"

export function householdStanding(balance: Balance, _at: Date): Standing {
  if (serverSaysRestricted(balance)) {
    return {
      status: { word: 'In arrears: access restricted', tone: 'amber-700' },
      restricted: true,
      warningTone: 'amber',
      warning:
        `Your household is in arrears, and the estate has put ${HELD} on hold. You can always come and go yourself, and emergency and medical vehicles are never stopped. To have it lifted, pay what is owed or ask the estate office about a payment plan.`,
    }
  }
  if (!owed(balance.balance)) return { status: { word: 'Paid', tone: 'slate-500' }, restricted: false, warning: null, warningTone: 'navy' }

  if (balance.days_overdue > 0) {
    return {
      status: { word: 'Overdue', tone: 'amber-700' },
      restricted: false,
      warningTone: balance.on_payment_plan ? 'navy' : 'amber',
      // The warning comes BEFORE any restriction: nobody should first hear of arrears from a guard turning a guest away.
      warning: balance.on_payment_plan
        ? `You are on a payment plan with the estate office. While its payments are kept up, ${HELD_PLAIN} are not put on hold.`
        : `Your dues are ${days(balance.days_overdue)} overdue. If they stay unpaid, the estate can put ${HELD} on hold: it must write to you first. Your own entry is never affected. Pay what is owed, or ask the estate office about a payment plan.`,
    }
  }

  // Owing, nothing past its date: "Unpaid", in navy (M98; amber is for overdue only, the user, 9 Oct 2026).
  return { status: { word: 'Unpaid', tone: 'navy-700' }, restricted: false, warning: null, warningTone: 'navy' }
}

/* ---- the statement ----------------------------------------------------------------------------- */

/*
 * The statement screen's own logic — its balance card, the four ageing boxes and its rows (board 09, M42 §5 #8) — is
 * statement.ts.
 */

/**
 * The server sends the newest lines only — this many unless asked for more (`limit`, up to 200) — and does
 * not say whether older ones exist.
 */
export const STATEMENT_PAGE = 50
/** The same for the payments the office recorded. */
export const PAYMENTS_PAGE = 100

/*
 * The Pay screen's own logic — the full balance, the line under it and the part-payment (board 10, M42 §5 #9) — is
 * pay.ts. It no longer asks `invoices.pay_intent` for one charge's instructions: the client deleted them from the screen.
 */

/* ---- payments the office recorded ------------------------------------------------------------ */

const METHOD: Record<string, string> = { bank: 'Bank transfer', cash: 'Cash', cheque: 'Cheque', card: 'Card' }
export const methodWord = (method: string): string => METHOD[method] ?? 'Recorded by the office'

export type PaymentRow = { id: string; title: string; sub: string; reversed: boolean; demonstration: boolean }

/** A test card paid it (M12 §2.6): a real receipt, and no money moved. Said beside it everywhere. */
const isDemonstration = (p: Pick<Payment, 'is_demonstration'>): boolean => p.is_demonstration === true

export function paymentRows(items: Payment[]): PaymentRow[] {
  return items.map((p) => ({
    id: String(p.id),
    title: `${money(p.amount)} · ${isDemonstration(p) ? 'Card (demonstration)' : methodWord(p.method)}`,
    sub: `${receivedOn(p.received_at).date} · receipt ${p.receipt_no}`,
    reversed: p.status === 'reversed',
    demonstration: isDemonstration(p),
  }))
}

/**
 * Payment successful — board resident-app-30, as the rebuilt screen draws it: the title, the line under it, the
 * detail lines, "Your balance" under its rule, the note.
 */
export type SuccessView = {
  reversed: boolean
  title: string
  line: string
  /** `moment`: the value is a date AND a time, which a copy that leaves the app names the clock of (`onTheEstateClock`). */
  details: { label: string; value: string; moment?: true }[]
  /** "Your balance" — the ledger's figure now; null until it has answered. */
  total: { label: string; value: string } | null
  note: string
}

export const DEMONSTRATION_NOTE =
  'A demonstration payment: paid in the app with a test card. No money moved. It counts towards your balance and statement like a real payment until the estate reverses it.'

/**
 * Board 30's own note, word for word (the user, 7 October: everything the design has is on screen). True only when
 * the balance is clear — nothing is left to remind about — so it is said then, and the payment's own note otherwise.
 */
export const REMINDERS_OFF = successFixture.note

/** The card a demonstration receipt names — "Visa •••• 4242", read from the server's own reference for it. */
const DEMO_CARD_ON_RECEIPT = /(Visa|Mastercard|American Express|Discover|Card) •••• \d{4}/

/**
 * A payment as the ESTATE recorded it, in board 30's shape.
 *
 *   TITLE      "Payment successful" (the board's) only for a payment MADE IN THIS APP — today, only the demonstration
 *              checkout's (pay.ts). One the estate office recorded "received" it: nothing in the app succeeded. A
 *              reversed one says so, in amber on the screen.
 *   THE LINE   "$12,400.00 paid to Phoenix Park Village 1 · receipt #PPV-R-04472" — the estate's own name and number.
 *   DETAILS    the board's two that the server can answer: what paid it ("Visa •••• 4242 (demonstration)", or the
 *              office's method) and when. The board's first two lines — the charges it cleared — are not in the
 *              receipt, and are left out rather than worked out. A day the office recorded has no time to show.
 *   BALANCE    "Your balance" is the ledger's figure now (`households.balance`), a credit shown as $0.00 as on Dues.
 *   THE NOTE   the board's own sentence ("Your payment reminders for this balance have been turned off automatically") when
 *              the balance is clear — true then, nothing is left to remind about; otherwise what this payment is.
 */
export function successView(r: Payment & Partial<Pick<Receipt, 'estate'>>, balance: string | null): SuccessView {
  const reversed = r.status === 'reversed'
  const demonstration = isDemonstration(r)
  // A receipt is a record: it says its year (every date does now, M98), and a time only when one was recorded.
  const received = receivedOn(r.received_at)
  const card = demonstration ? (DEMO_CARD_ON_RECEIPT.exec(r.reference ?? '')?.[0] ?? 'Card') : null
  const estate = r.estate ?? 'your estate'
  const receipt = `receipt #${r.receipt_no}`
  return {
    reversed,
    title: reversed ? 'Payment reversed' : demonstration ? 'Payment successful' : 'Payment received',
    line: reversed
      ? `${money(r.amount)} was recorded and then reversed by ${estate} · ${receipt}`
      : `${money(r.amount)} ${demonstration ? 'paid to' : 'received by'} ${estate} · ${receipt}`,
    details: [
      { label: 'Paid with', value: card ? `${card} (demonstration)` : methodWord(r.method) },
      received.time ? { label: 'Date & time', value: `${received.date}, ${received.time}`, moment: true } : { label: 'Date received', value: received.date },
    ],
    total: balance === null ? null : { label: 'Your balance', value: moneySign(balance) < 0 ? money('0.00') : money(balance) },
    note: reversed
      ? demonstration
        ? 'This demonstration payment was reversed, so it no longer counts towards your balance. No money moved either way.'
        : 'The estate office reversed this payment, so it no longer counts towards your balance. Contact the office if you did not expect this.'
      : balance !== null && moneySign(balance) <= 0
        ? REMINDERS_OFF
        : demonstration
          ? DEMONSTRATION_NOTE
          : 'The estate office recorded this payment and issued this receipt. Your statement and balance already include it.',
  }
}

/**
 * "4 Sep 2026, 9:41 AM Jamaica time": a moment on a copy of the receipt that LEAVES the app (the PDF, the shared text).
 * Hassam's rule (M98, 12 Oct 2026): the clock's name is dropped where the reader is certainly at the estate, and kept
 * where the document leaves the estate or the reader might not be. A receipt is saved, mailed and opened anywhere, years
 * later; the screen itself is read at the estate, so it says the time alone.
 */
export const onTheEstateClock = (moment: string): string => `${moment} ${clockNameOf()}`

export function successShareText(view: SuccessView): string {
  return [view.title, view.line, ...view.details.map((l) => `${l.label}: ${l.moment ? onTheEstateClock(l.value) : l.value}`), ...(view.total ? [`${view.total.label}: ${view.total.value}`] : [])].join('\n')
}
