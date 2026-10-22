/**
 * resident-app-08 · what the Visitors tab says, worked out from what the server tells a resident (M42 §5 #7, M47 §5).
 *
 * TODAY'S VISITORS ARE UNDER "ON SITE NOW" (M47 §5.3, Hassam's rule) — whatever Home lists under "Expected today" is
 * here too, worked out by the same function (homeLogic.expectedToday). A visitor invited for today was in neither list
 * before: On site now held only the passes the gate had admitted, History only the past, and "Expected" was gone.
 *
 * THE GATE TELLS A RESIDENT WHEN A VISITOR WAS LET IN, AND WHERE AND BY WHOM: a pass carries `last_admitted_at` (the
 * latest admission, recurring passes too), `used_at` (a single-use pass's one entry) and, from M47, the gate and the
 * guard of that admission. Nothing says when a visitor LEFT: no guard records a departure. So:
 *
 *   On site now      first everyone admitted today, newest first: "Arrived 2:14 PM · Guest of …" and a badge counting
 *                    from that real arrival ("45 min", "3h 12m") — an APPROXIMATION, and only that: a visitor who has
 *                    left still shows until the estate's day ends. Then everyone still EXPECTED today, soonest first,
 *                    as Home draws them: what the visit is for under the name, the window in the badge.
 *   Recent activity  one row for each of today's admissions, at its real time: "Entered · Main Gate · by Guard Whyte"
 *                    — the gate and the guard when the server recorded them. Never an "Exited" row.
 *   History          the past passes (used, cancelled, ran out), newest first, drawn as the cards are.
 *
 * "GUEST OF …" names the member who issued the pass (`host`, M47 §5.5) — never whoever is signed in, which named the
 * wrong person on every pass a household's other members sent. A server too old to say leaves it out. A contractor's
 * or a courier's pass names its kind instead ("Contractor pass", "Delivery pass").
 *
 * The resident's own e-Pass is never here (M47 §5.3): it is the resident's credential, not a visitor.
 */

import { dateShort, isSameSiteDay, time12 } from '@gs/core/lib'
import { expectedToday } from '@/features/home/homeLogic'
import { initialsOf, type Groups, type Pass } from './passes'

/** A visitor's card — on site now, or a past pass under History. `passId` opens the pass; null on the design's own sample. */
export type SiteCard = { key: string; passId: string | null; initials: string; name: string; line: string; badge: string }

/** A row of Recent activity: `entered` draws the → tile, else the ← one. */
export type ActivityRow = { key: string; passId: string | null; entered: boolean; name: string; line: string; time: string }

export type Tracking = { onSite: SiteCard[]; activity: ActivityRow[]; history: SiteCard[] }

/** When the gate last let this pass's visitor in — null when it never has. */
export const admittedAt = (p: Pick<Pass, 'last_admitted_at' | 'used_at'>): string | null => p.last_admitted_at ?? p.used_at ?? null

/** How long since they arrived, as the board's badge says it: "45 min" under the hour, "3h 12m" from it. */
export function stayWords(arrived: string, at: Date): string {
  const minutes = Math.max(0, Math.floor((at.getTime() - new Date(arrived).getTime()) / 60_000))
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`
}

const tidy = (text: string | null | undefined): string => (text ?? '').trim().replace(/\s+/g, ' ')

/** Who issued the pass, as the server says it (`host`) — '' from a server too old to say. */
export const hostOf = (p: Partial<Pick<Pass, 'host'>>): string => tidy(p.host)

/** Whose visitor it is: "Contractor pass" · "Delivery pass" · "Guest of Andrea Fletcher" — or nothing, with no name to give. */
export function whoOf(p: Pick<Pass, 'category'>, host: string): string | null {
  if (p.category === 'contractor') return 'Contractor pass'
  if (p.category === 'delivery') return 'Delivery pass'
  const name = tidy(host)
  return name ? `Guest of ${name}` : null
}

/** "Entered · Main Gate · by Guard Whyte", as the board writes it — the guard by surname; "Entered" when the gate said neither. */
export function enteredWords(p: Partial<Pick<Pass, 'last_admitted_post' | 'last_admitted_by'>>): string {
  const surname = tidy(p.last_admitted_by).split(' ').pop() ?? ''
  return joined('Entered', tidy(p.last_admitted_post) || null, surname ? `by Guard ${surname}` : null)
}

const joined = (...parts: (string | null)[]): string => parts.filter((part): part is string => !!part).join(' · ')
const byNewest = (a: { at: string }, b: { at: string }): number => new Date(b.at).getTime() - new Date(a.at).getTime()

/** A past pass: what became of it, whose it was, and the day it happened ("Today", else "Fri, Sep 4"). */
function historyCard(p: Pass, at: Date): SiteCard {
  const arrived = admittedAt(p)
  const [what, when] = p.status === 'cancelled' ? ['Cancelled', p.cancelled_at ?? p.valid_to] : arrived ? [`Arrived ${time12(arrived)}`, arrived] : ['Not used', p.valid_to]
  return { key: `h:${p.id}`, passId: String(p.id), initials: initialsOf(p.visitor_name), name: p.visitor_name, line: joined(what, whoOf(p, hostOf(p))), badge: isSameSiteDay(when, at) ? 'Today' : dateShort(when) }
}

/** The household's passes (useVisitorList's groups — the e-pass is not a visitor) → the three lists the tab draws. */
export function trackingOf(groups: Groups, at: Date): Tracking {
  const passes = [...groups.now, ...groups.upcoming, ...groups.history]
  const today = passes
    .flatMap((p) => {
      const arrived = admittedAt(p)
      return arrived && isSameSiteDay(arrived, at) ? [{ p, at: arrived }] : []
    })
    .sort(byNewest)
  const arrivedToday = new Set(today.map(({ p }) => String(p.id)))
  // Still to come today — Home's own list, less anyone the gate has let in today: they are above, as arrived.
  const expected = expectedToday(passes.filter((p) => !arrivedToday.has(String(p.id))), at)
  return {
    onSite: [
      ...today.map(({ p, at: arrived }) => ({ key: `s:${p.id}`, passId: String(p.id), initials: initialsOf(p.visitor_name), name: p.visitor_name, line: joined(`Arrived ${time12(arrived)}`, whoOf(p, hostOf(p))), badge: stayWords(arrived, at) })),
      ...expected.map((v) => ({ key: `e:${v.id}`, passId: v.id, initials: v.initials, name: v.name, line: v.detail, badge: v.when })),
    ],
    activity: today.map(({ p, at: arrived }) => ({ key: `a:${p.id}`, passId: String(p.id), entered: true, name: p.visitor_name, line: enteredWords(p), time: time12(arrived) })),
    history: groups.history.map((p) => historyCard(p, at)),
  }
}
