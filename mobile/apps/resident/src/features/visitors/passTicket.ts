/**
 * THE PASS AS A TICKET (M12 §2.8) — the words on the picture the visitor receives.
 *
 * Estate, lot, visitor, the day, the time window, the QR and the code beneath it: what a visitor needs at the
 * gate on one picture, so it can be forwarded, saved and shown without the words that came with it. Pure, so it
 * is tested without a phone; `PassTicket.tsx` draws it.
 *
 * THE ESTATE'S CLOCK, NOT NAMED. The times are the estate's, whatever the phone that shares it is set to, and the
 * ticket names no clock (Hassam, M98, 12 Oct 2026: "Everything on a pass is estate time by definition: the estate is
 * in Jamaica, the gate is in Jamaica, the visitor arrives in Jamaica"). It said "Jamaica time" until then.
 */

import { isSameSiteDay, siteParts, siteZone, time12 } from '@gs/core/lib'
import { CATEGORY_LABEL, type Pass } from './passes'

export type Ticket = {
  estate: string
  /** "Phase 2 · Lot 47" — the home the visitor is going to — or null when the phone does not know it yet. */
  lot: string | null
  kind: string
  visitor: string
  /** "27 Sep 2026" — or "27 Sep 2026 – 3 Oct 2026" for a pass over several days. */
  day: string
  /** "8:58 AM – 11:00 AM" — or the two ends with their days, for a pass over several days. */
  window: string
  /** "27 Sep 2026 · 8:58 AM – 11:00 AM" — the one line the ticket prints (M18F §2.1; its date in M98's form, no clock named). */
  when: string
  code: string
  token: string
  /** One short line for the foot of the ticket. */
  footer: string
  /** For a screen reader, and the picture's description. */
  label: string
}

/** M18F §2.1, word for word — and short enough to sit on the ticket without being cut (it used to run off both ends). */
export const TICKET_FOOTER = 'Show this at the gate. The guard scans the code, or types it in.'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

export function ticketOf(pass: Pick<Pass, 'category' | 'visitor_name' | 'valid_from' | 'valid_to' | 'code' | 'token'>, estate: string, lot: string | null, zone: string = siteZone()): Ticket {
  const oneDay = isSameSiteDay(pass.valid_from, pass.valid_to, zone)
  const a = siteParts(pass.valid_from, zone)
  const b = siteParts(pass.valid_to, zone)
  // "27 Sep 2026": the one way a date is written (M98, 12 Oct 2026; M18F §2.1's "Sunday, 27 September"), on the
  // estate's clock, which the ticket does not name (above).
  const dated = (p: typeof a) => `${p.day} ${MONTHS[p.month]} ${p.year}`
  const day = oneDay ? dated(a) : `${dated(a)} – ${dated(b)}`
  const window = oneDay
    ? `${time12(pass.valid_from, zone)} – ${time12(pass.valid_to, zone)}`
    : `${dated(a)}, ${time12(pass.valid_from, zone)} to ${dated(b)}, ${time12(pass.valid_to, zone)}`
  const visitor = pass.visitor_name.trim().replace(/\s+/g, ' ') || 'Visitor'
  const where = lot?.trim() ? lot.trim() : null
  const when = oneDay ? `${day} · ${window}` : window
  return {
    estate: estate.trim() || 'Your estate',
    lot: where,
    kind: CATEGORY_LABEL[pass.category] ?? 'Visitor pass',
    visitor,
    day,
    window,
    when,
    code: pass.code,
    token: pass.token,
    footer: TICKET_FOOTER,
    label: `Visitor pass for ${visitor} at ${estate}${where ? `, ${where}` : ''}. ${when}. Gate code ${pass.code}.`,
  }
}

/** The ticket's grid: 720 across, the QR 480 of it (see components/PassTicket.tsx). */
export const TICKET_W = 720
export const TICKET_QR = 480

/** A run of lines, each at its baseline. */
export type TicketBlock = { lines: string[]; first: number; step: number }
const lastOf = (b: TicketBlock): number => b.first + (b.lines.length - 1) * b.step

/**
 * WHERE EVERYTHING GOES ON THE TICKET, worked out from the words — so a long name moves everything below it down
 * instead of running off the edge (M18F §5). Characters per line are set for each face and size with room to spare
 * (the text column is 600 wide). Pure, so the layout is tested without drawing it.
 */
export function ticketLayout(ticket: Ticket) {
  const estate: TicketBlock = { lines: wrap(ticket.estate, 20), first: 148, step: 52 }
  const lot: TicketBlock | null = ticket.lot ? { lines: wrap(`Visiting ${ticket.lot}`, 32), first: lastOf(estate) + 52, step: 40 } : null
  const tagline = (lot ? lastOf(lot) : lastOf(estate)) + 46
  const headEnd = tagline + 30
  const perf = headEnd + 30
  const visitorLabel = perf + 62
  const visitor: TicketBlock = { lines: wrap(ticket.visitor, 19), first: visitorLabel + 54, step: 54 }
  const when: TicketBlock = { lines: wrap(ticket.when, 32), first: lastOf(visitor) + 56, step: 40 }
  const qrY = lastOf(when) + 40
  const code = qrY + TICKET_QR + 72
  const footer: TicketBlock = { lines: wrap(ticket.footer, 44), first: code + 50, step: 30 }
  const height = lastOf(footer) + 56
  return { estate, lot, tagline, headEnd, perf, visitorLabel, visitor, when, qrY, code, footer, height }
}

/**
 * A line broken on its words to fit the ticket's width — NEVER cut, never an ellipsis (M18F §5): an estate called
 * "Phoenix Park Village 1 Homeowners' Association" takes two lines, and the ticket grows to hold them. A word longer
 * than the width breaks after its own hyphen — "Fitzgerald-" / "Montgomery" (M20: the longest realistic visitor, a
 * double-barrelled name, came out as "Fitzgerald-Montgome" / "ry"). Only a word with no hyphen to break at (a name
 * typed with no spaces) is broken where it must be. Measured in characters, which the ticket's fixed-size type allows.
 */
export function wrap(text: string, max: number): string[] {
  const lines: string[] = []
  let line = ''
  // `joined`: this piece carries on the word before it, after its hyphen, so no space goes between them.
  const place = (piece: string, joined: boolean) => {
    const together = line === '' ? piece : joined ? `${line}${piece}` : `${line} ${piece}`
    if (together.length <= max) {
      line = together
      return
    }
    if (line) lines.push(line)
    line = piece
  }
  for (const word of text.trim().split(/\s+/).filter(Boolean)) {
    // A word that fits on a line of its own is never broken; a longer one is broken after its hyphens first.
    const pieces = word.length <= max ? [word] : (word.match(/[^-]*-|[^-]+/g) ?? [word])
    pieces.forEach((piece, i) => {
      let rest = piece
      while (rest.length > max) {
        if (line) {
          lines.push(line)
          line = ''
        }
        lines.push(rest.slice(0, max))
        rest = rest.slice(max)
      }
      if (rest) place(rest, i > 0)
    })
  }
  if (line) lines.push(line)
  return lines.length > 0 ? lines : ['']
}
