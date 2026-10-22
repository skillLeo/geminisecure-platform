/**
 * What changed for a resident since this phone last looked — pure, so it can be tested.
 *
 * The Resident API has no "what changed?" call, so the app compares what its lists say NOW with a small
 * snapshot it kept from last time (ids and statuses only — never a name, an amount or a body). Rules:
 *
 *   - The FIRST look at a list on a phone announces nothing old: a resident who has just signed in is
 *     not told about every notice of the last six months. Lists arrive one at a time, so "first" is
 *     kept PER LIST. The one exception is someone AT THE GATE right now.
 *   - A lock screen is read by whoever is nearby: a payment is "recorded", never an amount; a notice
 *     is its title, never its body; a ticket is its number and new status.
 *   - Each note has a stable key, so nothing is shown twice.
 */

import type { ResidentType } from './links'

export type Feeds = {
  approvals?: { id: number | string; visitor_name: string; post_name: string; respond_by: string }[]
  notices?: { id: number | string; title: string; read_at: string | null }[]
  tickets?: { id: number | string; number: number | string; status: string }[]
  payments?: { id: number | string; status: string }[]
  passes?: { id: number | string; visitor_name: string; status: string; category: string }[]
  bookings?: { id: number | string; status: string; amenity: { name: string } }[]
}

/** ids and statuses only. A feed that was not supplied this time keeps its old entry untouched. */
export type Snapshot = {
  /** the lists this phone has looked at at least once */
  seen: string[]
  approvals: string[]
  notices: string[]
  tickets: Record<string, string>
  payments: string[]
  passes: Record<string, string>
  bookings: Record<string, string>
}
export const EMPTY_SNAPSHOT: Snapshot = { seen: [], approvals: [], notices: [], tickets: {}, payments: [], passes: {}, bookings: {} }

export type ResidentNote = { key: string; type: ResidentType; title: string; body: string; data: { type: ResidentType; id?: string }; urgent: boolean }

// A request's state in the consoles' words (M98): "Under review" where it said Acknowledged, "Closed" for verified.
const TICKET: Record<string, string> = { acknowledged: 'Under review', assigned: 'Assigned to a technician', in_progress: 'In progress', completed: 'Completed', verified: 'Closed', cancelled: 'Cancelled' }
const BOOKING: Record<string, string> = { confirmed: 'confirmed', declined: 'not approved', cancelled: 'cancelled' }
const clip = (text: string, max = 90) => { const one = text.replace(/\s+/g, ' ').trim(); return one.length > max ? `${one.slice(0, max - 1)}…` : one }

export function residentDigest(prev: Snapshot | null, feeds: Feeds, at: Date): { notes: ResidentNote[]; next: Snapshot } {
  const before: Snapshot = { ...EMPTY_SNAPSHOT, ...(prev ?? {}) }
  const seen = new Set(before.seen)
  const firstLook = (feed: string): boolean => !before.seen.includes(feed)
  const notes: ResidentNote[] = []
  const next: Snapshot = { ...before }

  if (feeds.approvals) {
    seen.add('approvals')
    const open = feeds.approvals.filter((a) => new Date(a.respond_by).getTime() > at.getTime())
    for (const a of open) {
      // Someone waiting at the gate is announced even on the first look: it is happening NOW.
      if (!before.approvals.includes(String(a.id))) notes.push({ key: `approval:${a.id}`, type: 'approval_request', title: 'Someone is at the gate for you', body: clip(`${a.visitor_name} is at ${a.post_name}. Open to let them in or turn them away.`), data: { type: 'approval_request', id: String(a.id) }, urgent: true })
    }
    next.approvals = open.map((a) => String(a.id))
  }

  if (feeds.notices) {
    const first = firstLook('notices')
    seen.add('notices')
    if (!first) for (const n of feeds.notices) if (!before.notices.includes(String(n.id)) && !n.read_at) notes.push({ key: `notice:${n.id}`, type: 'notice', title: 'New notice from your estate', body: clip(n.title), data: { type: 'notice', id: String(n.id) }, urgent: false })
    next.notices = feeds.notices.map((n) => String(n.id))
  }

  if (feeds.tickets) {
    const first = firstLook('tickets')
    seen.add('tickets')
    const map: Record<string, string> = {}
    for (const t of feeds.tickets) {
      map[String(t.id)] = t.status
      const was = before.tickets[String(t.id)]
      if (!first && was !== undefined && was !== t.status && TICKET[t.status]) notes.push({ key: `ticket:${t.id}:${t.status}`, type: 'ticket_update', title: `Request #${t.number}`, body: `${TICKET[t.status]}.`, data: { type: 'ticket_update', id: String(t.id) }, urgent: false })
    }
    next.tickets = map
  }

  if (feeds.payments) {
    const first = firstLook('payments')
    seen.add('payments')
    if (!first) for (const p of feeds.payments) if (!before.payments.includes(String(p.id)) && p.status === 'recorded') notes.push({ key: `payment:${p.id}`, type: 'payment_recorded', title: 'Payment recorded', body: 'The estate office recorded a payment on your account. Open the app to see your statement.', data: { type: 'payment_recorded', id: String(p.id) }, urgent: false })
    next.payments = feeds.payments.map((p) => String(p.id))
  }

  if (feeds.passes) {
    const first = firstLook('passes')
    seen.add('passes')
    const map: Record<string, string> = {}
    for (const p of feeds.passes) {
      map[String(p.id)] = p.status
      const was = before.passes[String(p.id)]
      // The resident's own e-pass being used at the gate is them coming home — not news.
      if (!first && was !== undefined && was !== 'used' && p.status === 'used' && p.category !== 'resident') notes.push({ key: `pass:${p.id}:used`, type: 'visitor_admitted', title: 'Your visitor has arrived', body: clip(`${p.visitor_name} was let in at the gate.`), data: { type: 'visitor_admitted', id: String(p.id) }, urgent: false })
    }
    next.passes = map
  }

  if (feeds.bookings) {
    const first = firstLook('bookings')
    seen.add('bookings')
    const map: Record<string, string> = {}
    for (const b of feeds.bookings) {
      map[String(b.id)] = b.status
      const was = before.bookings[String(b.id)]
      if (!first && was !== undefined && was !== b.status && BOOKING[b.status]) notes.push({ key: `booking:${b.id}:${b.status}`, type: 'booking_decided', title: 'Your booking', body: clip(`${b.amenity.name}: ${BOOKING[b.status]}.`), data: { type: 'booking_decided', id: String(b.id) }, urgent: false })
    }
    next.bookings = map
  }

  next.seen = [...seen].sort()
  return { notes, next }
}
