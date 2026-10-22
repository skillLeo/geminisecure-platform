/**
 * "All meetings" — the list's pure logic (no React, no UI kit), so it is unit-tested without a phone.
 *
 * NO BOARD DRAWS THIS SCREEN. The specification names it, and their absence is the first
 * thing a resident notices, so it is assembled from the boards' own parts: board 12's card and chip, board
 * 26's section headings, and the Meeting Lobby's own words for when and where (meetings.ts).
 *
 *   UPCOMING FIRST, soonest at the top — everything still ahead or under way, and a meeting cancelled before its
 *   day (a resident must see that Saturday's AGM is OFF, not find it gone). A meeting postponed with no new date is
 *   still to come, whatever day it was first called for: it goes after every meeting that has a date, and its row
 *   says "New date to be set" where the date goes (M98, 12 Oct 2026).
 *   THEN THE PAST, newest first — held, cancelled and gone by, or never marked held by the office.
 *   A STATUS IN WORDS on every row; the household's own reply where there is one, with where it stands
 *   (Pending sync · Syncing · Not accepted) when it is this phone's and not yet the server's.
 *   Every row opens the meeting's existing lobby.
 */

import { dayTimeTag } from '@gs/core/lib'
import { DATE_TO_BE_SET, SYNC_LABEL, dateToBeSet, phaseOf, rsvpSaid, rsvpStateOf, whereOf, type Meeting, type Phase, type RsvpOp } from './meetings'

const startOf = (m: Pick<Meeting, 'starts_at'>): number => new Date(m.starts_at).getTime()

const AHEAD: readonly Phase[] = ['upcoming', 'soon', 'underway']

/** Still to come (or happening now) — and a cancelled meeting until its day has passed. */
export function isAhead(m: Pick<Meeting, 'status' | 'starts_at' | 'date_to_be_set'>, at: Date): boolean {
  const phase = phaseOf(m, at)
  if (phase === 'cancelled') return startOf(m) > at.getTime()
  // A postponed meeting is still to come, whatever date it was first given.
  if (phase === 'postponed') return true
  return AHEAD.includes(phase)
}

export function splitMeetings<T extends Pick<Meeting, 'id' | 'status' | 'starts_at' | 'date_to_be_set'>>(items: readonly T[], at: Date): { upcoming: T[]; past: T[] } {
  // Two meetings at the same moment keep ONE order whichever way they arrived: by id (numbers on this server; text otherwise).
  const byId = (p: T, q: T) => {
    const [a, b] = [Number(p.id), Number(q.id)]
    return Number.isFinite(a) && Number.isFinite(b) ? a - b : String(p.id) < String(q.id) ? -1 : String(p.id) > String(q.id) ? 1 : 0
  }
  // A meeting with no new date yet goes after every one that has a date: the day it was first called for is not its turn.
  const upcoming = items.filter((m) => isAhead(m, at)).sort((p, q) => Number(dateToBeSet(p)) - Number(dateToBeSet(q)) || startOf(p) - startOf(q) || byId(p, q))
  // The past runs the server's way — newest first, and the later-made of two at one moment first — so pages join up.
  const past = items.filter((m) => !isAhead(m, at)).sort((p, q) => startOf(q) - startOf(p) || byId(q, p))
  return { upcoming, past }
}

/* ---- the row ---------------------------------------------------------------------------------------- */

const KIND: Record<string, string> = { agm: 'Annual general meeting', egm: 'Extraordinary general meeting', phase: 'Phase meeting' }

/** The meeting's kind in a homeowner's words (the lobby's hero shouts it in capitals; a list does not). */
export const kindLabel = (type: string): string => {
  if (KIND[type]) return KIND[type]!
  const words = type.replace(/[_-]+/g, ' ').trim()
  return words ? `${words.charAt(0).toUpperCase()}${words.slice(1)} meeting` : 'Meeting'
}

export type ChipTone = 'navy' | 'amber' | 'green' | 'slate'

const CHIP: Record<Phase, { label: string; tone: ChipTone }> = {
  upcoming: { label: 'Upcoming', tone: 'navy' },
  soon: { label: 'Starting soon', tone: 'amber' },
  underway: { label: 'Happening now', tone: 'amber' },
  overdue: { label: 'Date has passed', tone: 'slate' },
  held: { label: 'Held', tone: 'green' },
  cancelled: { label: 'Cancelled', tone: 'slate' },
  // Told, then moved with no new date yet (M56 §2.3): still the household's to come back to.
  postponed: { label: 'Postponed', tone: 'amber' },
}

/** Where the meeting stands, in words. */
export const chipOf = (m: Pick<Meeting, 'status' | 'starts_at' | 'date_to_be_set'>, at: Date): { label: string; tone: ChipTone } => CHIP[phaseOf(m, at)]

/**
 * "27 Sep 2026, 10:00 AM" — the one form, its year every time (M98; a history goes back years). "New date to be set"
 * while a postponed meeting has none: the day it was first called for is never shown as its date.
 */
export const whenOf = (m: Pick<Meeting, 'starts_at' | 'status' | 'date_to_be_set'>, _at?: Date): string =>
  dateToBeSet(m) ? DATE_TO_BE_SET : dayTimeTag(m.starts_at)

/**
 * The household's reply, as the list may say it. A reply still on this phone says so; a refused one is NOT the
 * household's reply and is not printed as one. A past meeting the household never replied to says nothing, and nor does
 * one with no date yet: replies open again with the new date.
 */
export function replyLine(m: Pick<Meeting, 'id' | 'my_rsvp' | 'status' | 'starts_at' | 'date_to_be_set'>, ops: readonly RsvpOp[], at: Date): string | null {
  const state = rsvpStateOf(m, ops, null)
  if (state.sync === 'refused') return state.answer ? rsvpSaid(state.answer) : null
  if (!state.answer) return isAhead(m, at) && m.status === 'scheduled' && !dateToBeSet(m) ? 'No reply from your household yet' : null
  const waiting = state.sync === 'pending' || state.sync === 'syncing' ? ` · ${SYNC_LABEL[state.sync]}` : ''
  return `${rsvpSaid(state.answer)}${waiting}`
}

export type MeetingRow = {
  id: string
  kind: string
  title: string
  when: string
  where: string
  chip: { label: string; tone: ChipTone }
  reply: string | null
  /** Held, and the office has recorded minutes. */
  minutes: boolean
  /** For a screen reader: everything on the row, then what pressing does. */
  label: string
  /** The existing lobby. */
  path: string
}

export function rowOf(m: Meeting, ops: readonly RsvpOp[], at: Date): MeetingRow {
  const chip = chipOf(m, at)
  const when = whenOf(m, at)
  const where = whereOf(m)
  const reply = replyLine(m, ops, at)
  const minutes = m.status === 'held' && m.minutes_available === true
  return {
    id: String(m.id),
    kind: kindLabel(m.type),
    title: m.title,
    when,
    where,
    chip,
    reply,
    minutes,
    label: [m.title, chip.label, when, where, reply, minutes ? 'Minutes are ready' : null, 'Open this meeting'].filter(Boolean).join('. '),
    path: `/meetings/${String(m.id)}`,
  }
}

export type Section = { key: 'upcoming' | 'past'; title: string; rows: MeetingRow[] }

/** The screen's two sections — one that is empty is not drawn. */
export function sectionsOf(items: readonly Meeting[], ops: readonly RsvpOp[], at: Date): Section[] {
  const { upcoming, past } = splitMeetings(items, at)
  return [
    { key: 'upcoming' as const, title: 'Upcoming', rows: upcoming.map((m) => rowOf(m, ops, at)) },
    { key: 'past' as const, title: 'Past meetings', rows: past.map((m) => rowOf(m, ops, at)) },
  ].filter((section) => section.rows.length > 0)
}

/* ---- older pages, said plainly ----------------------------------------------------------------------- */

/** Why "Show older meetings" cannot be pressed — or null when it can. Older pages are asked of the server: there is no saved copy to fall back on. */
export const olderBlocked = (online: boolean): string | null => (online ? null : 'Older meetings are fetched from your estate, so they need a connection.')

/** Said under the list once the server has nothing older — so the end of the list reads as the end, not as a fault. */
export const END_OF_LIST = 'That is every meeting your estate has published to your household.'
