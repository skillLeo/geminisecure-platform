/**
 * Home's pure logic — no React, no UI kit — so it is unit-tested without a phone.
 * Money is formatted from the server's string and never computed (packages/core/lib/money.ts).
 */

import { isSameSiteDay, siteDayIso, siteParts, money, moneySign, rangeTag, timeTag } from '@gs/core/lib'
import type { Api } from '@gs/core/api'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/**
 * "2026-09-28" → "28 Sep 2026" — the one way a date is written (M98, 12 Oct 2026; it read "Monday, September 28"). A DATE
 * has no time zone: it is read as written, never through `new Date()` on a time.
 */
export function dayOfYear(date: string | null | undefined): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(date ?? '')
  if (!m) return null
  const month = MONTHS[Number(m[2]) - 1]
  return month ? `${Number(m[3])} ${month} ${m[1]}` : null
}

export function greetingFor(at: Date, firstName: string): string {
  const hour = siteParts(at).hour
  const part = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
  return firstName ? `${part}, ${firstName}` : part
}

export type BalanceView = {
  state: 'owing' | 'overdue' | 'clear' | 'credit'
  title: string
  amount: string
  detail: string
  /**
   * ONE COLOUR PER JOB, EVERY ONE READABLE (M57 §1.2, WCAG 2.1 AA). Owing on time — "Due 28 Sep 2026", or a payment plan
   * being met — is the card's own body ink (slate-500, drawn as text-body, the "Maintenance fee" label's: 14.5:1 on
   * white); overdue or restricted the darker amber-700 (5.6:1) — never red, which is urgent and life-safety only (M12
   * §4.4). The board's amber-600 measured 2.2:1 and only the design-comparison build draws it. The amber clock badge stays
   * on every owing card.
   */
  tone: 'amber-700' | 'slate-500' | 'amber-600'
  action: string
  /** Where the card goes: how to pay one charge, or the dues statement (a restricted household needs to read WHY first). */
  opens: 'pay' | 'dues'
  /** Set when the figure did not come from the server just now — a cached balance is never shown as current. */
  stale: string | null
}

export function balanceView(b: Api.HouseholdsBalanceResponse, fresh: boolean): BalanceView {
  const sign = moneySign(b.balance)
  const stale = fresh ? null : `As at ${dayOfYear(b.as_at) ?? b.as_at}: connect to see today's figure`
  const base = { title: 'Maintenance fee', stale }

  if (sign === 0) return { ...base, state: 'clear', amount: "You're all paid up", detail: `As at ${dayOfYear(b.as_at) ?? b.as_at}`, tone: 'slate-500', action: 'View', opens: 'dues' }
  if (sign < 0) return { ...base, state: 'credit', amount: `${money(b.balance.replace('-', ''), 'compact')} in credit`, detail: 'It comes off your next charge', tone: 'slate-500', action: 'View', opens: 'dues' }

  const overdue = Number(b.days_overdue) > 0
  const due = dayOfYear(b.oldest_unpaid_due_on)
  // M18D §5: WHAT TO PAY NOW is the server's (`pay_now`) — the plan's next instalment while a plan holds — and the card
  // says it in the dues page's own words, with the same button. An older server sends none: the whole balance.
  const now = (b as { pay_now?: Api.HouseholdsBalanceResponse['pay_now'] }).pay_now ?? null
  const payAmount = now && moneySign(now.amount) > 0 ? money(now.amount, 'compact') : money(b.balance, 'compact')
  const nextOn = now ? dayOfYear(now.due_on) : null
  const planLine = b.on_payment_plan ? (now?.reason === 'instalment' && nextOn ? `On a payment plan · next payment ${payAmount}, due ${nextOn}` : 'On a payment plan') : null
  return {
    ...base,
    state: overdue ? 'overdue' : 'owing',
    amount: `${money(b.balance, 'compact')} outstanding`,
    // The SERVER's word (the gate's own decision), never worked out from the days: a resident is told HERE, first,
    // and never learns of a restriction from a guest being turned away.
    detail: b.access_restricted === true ? "Guests' passes are on hold: open to see why" : planLine ?? (overdue ? `Overdue by ${b.days_overdue} day${Number(b.days_overdue) === 1 ? '' : 's'}` : due ? `Due ${due}` : ''),
    tone: b.access_restricted === true || (overdue && !b.on_payment_plan) ? 'amber-700' : 'slate-500',
    // M18D §1: the button names the act and the sum — "Pay $3,400" — never "How to pay", and it is the dues page's own
    // button: the same words, the same screen. That screen says how this estate takes the money.
    action: b.access_restricted === true ? 'See my dues' : `Pay ${payAmount}`,
    opens: b.access_restricted === true ? 'dues' : 'pay',
  }
}

export type ExpectedVisitor = { id: string; initials: string; name: string; detail: string; when: string }

const CATEGORY: Record<string, string> = { single: 'Visitor', recurring: 'Regular visitor', contractor: 'Contractor', delivery: 'Delivery' }
const initialsOf = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('')
/** The estate's calendar day, from the ONE clock — never this phone's. */
const sameDay = (p: Date | string, q: Date | string) => isSameSiteDay(p, q)

/** Active passes whose window touches TODAY in Jamaica, soonest first. The resident's own e-pass is not a visitor. */
export function expectedToday(items: Api.VisitorPassesIndexResponse['items'], at: Date): ExpectedVisitor[] {
  const startsByToday = (from: string) => new Date(from).getTime() <= at.getTime() || sameDay(from, at)
  return items
    .filter((p) => p.status === 'active' && p.category !== 'resident' && new Date(p.valid_to).getTime() >= at.getTime() && startsByToday(p.valid_from))
    .sort((p, q) => new Date(p.valid_from).getTime() - new Date(q.valid_from).getTime())
    .map((p) => {
      const short = sameDay(p.valid_from, p.valid_to) && new Date(p.valid_to).getTime() - new Date(p.valid_from).getTime() <= 6 * 3_600_000
      // "2:00 – 4:00 PM" when both ends share a meridiem, as the board draws it, no tag (M98); a long pass shows when it
      // starts.
      const when = !short ? (sameDay(p.valid_from, at) ? timeTag(p.valid_from) : 'All day') : rangeTag(p.valid_from, p.valid_to)
      return { id: String(p.id), initials: initialsOf(p.visitor_name), name: p.visitor_name, detail: p.purpose || CATEGORY[p.category] || 'Visitor', when }
    })
}

/**
 * ELAPSED TIME, not a time of day: two instants subtracted. It has no zone, so it is not put through
 * lib/siteTime.ts — "20 minutes ago" is 20 minutes ago wherever the phone is. Once it is old
 * enough to be named by its DAY it goes back through the one clock, in the site's zone.
 *
 * THAT LAST STEP took the day off `toISOString()`, which is UTC: a notice the estate posted at 9 PM in
 * Kingston reads "Posted September 1" when it went up on the evening of August 31. `siteDayIso` names
 * the calendar day AT THE SITE, from the IANA name the server sent, so the day agrees with the notice.
 */
export function ago(iso: string, at: Date): string {
  const minutes = Math.max(0, Math.floor((at.getTime() - new Date(iso).getTime()) / 60_000))
  if (minutes < 1) return 'Posted just now'
  if (minutes < 60) return `Posted ${minutes} minute${minutes === 1 ? '' : 's'} ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `Posted ${hours} hour${hours === 1 ? '' : 's'} ago`
  const days = Math.floor(hours / 24)
  return days < 7 ? `Posted ${days} day${days === 1 ? '' : 's'} ago` : `Posted ${dayOfYear(siteDayIso(iso)) ?? ''}`.trim()
}

/** The notice Home shows: an urgent one nobody has read beats everything; then the newest unread; then the newest. */
export function headlineNotice(items: Api.NoticesIndexResponse['items']): Api.NoticesIndexResponse['items'][number] | null {
  const newest = [...items].sort((p, q) => new Date(q.published_at).getTime() - new Date(p.published_at).getTime())
  return newest.find((n) => n.kind === 'urgent' && !n.read_at) ?? newest.find((n) => !n.read_at) ?? newest[0] ?? null
}

/** Seconds left to answer a guard at the gate. */
export const secondsLeft = (respondBy: string, at: Date): number => Math.max(0, Math.ceil((new Date(respondBy).getTime() - at.getTime()) / 1000))
