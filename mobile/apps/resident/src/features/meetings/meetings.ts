/**
 * The meetings feature's pure logic — no React, no UI kit — so it is unit-tested without a phone.
 *
 * WHAT THE SERVER SENDS about a meeting (`meetings.index`): its type (agm | egm | phase), a title, when
 * it starts, a venue and/or a `virtual_link`, a status (scheduled | held | cancelled), whether it will be
 * RECORDED, an agenda (a wall-clock time and a line of text per item), whether minutes exist, and the
 * household's RSVP. One write: `meetings.rsvp` (which the contract lets a phone QUEUE).
 *
 * WHAT IT DOES NOT HAVE: video, sound, participants, chat, a raised hand, a live motion vote, a quorum
 * figure, or a URL for the minutes. An RSVP is NOT attendance — quorum is counted from the register
 * taken in the room — so nothing here turns RSVPs into a quorum bar. "Joining" is opening the estate's
 * own link (`virtual_link`) in the browser or meeting app, and only when it is a plain https address.
 */

import { dateShort, dayTimeTag, timeTag, yourTimeLine } from '@gs/core/lib'
import type { Api } from '@gs/core/api'

type ApiMeeting = Api.MeetingsIndexResponse['items'][number]
export type AgendaItem = ApiMeeting['agenda'][number]
/**
 * The server's meeting; the agenda is only ever read.
 *
 * `held_as` and `place` arrived in the catalogue with the server's M10 §8 change. Optional, because a meeting
 * this phone cached before the server sent them comes back off disk without them: the lobby's hero shows
 * `place` (placeOf), and says the app's own `whereOf` words when the key is missing. `held_as` is not read
 * yet; `platform` (26 Sep: an online meeting's platform by name) likewise. `reason` and `description` (M56: why it was
 * postponed or cancelled, and what it is about) the same way: absent from a meeting cached before the server sent them.
 * `date_to_be_set` (M98, 12 Oct 2026: postponed with no new date) too, and the generated contract does not name it yet.
 */
export type Meeting = Omit<ApiMeeting, 'agenda' | 'held_as' | 'place' | 'platform' | 'reason' | 'description'> & {
  agenda: readonly AgendaItem[]
  held_as?: ApiMeeting['held_as']
  place?: ApiMeeting['place']
  platform?: ApiMeeting['platform']
  reason?: string | null
  description?: string | null
  date_to_be_set?: boolean | null
}
export type Rsvp = NonNullable<Meeting['my_rsvp']>

/** A queued `meetings.rsvp`, as the outbox holds it. Declared here so this file stays free of the UI kit. */
export type RsvpOp = {
  id: string
  endpoint: string
  params: Record<string, unknown>
  body: Record<string, unknown> | null
  state: 'pending' | 'syncing' | 'confirmed' | 'refused'
  lastTriedAt: string | null
  error: { message: string } | null
  result: unknown
}

const MINUTE = 60_000
/** The link opens this long before the start… */
export const JOIN_OPENS_BEFORE_MIN = 30
/** …and the app stops offering it this long after: the server has no end time, only `held` once the office says so. */
export const JOIN_CLOSES_AFTER_MIN = 6 * 60

/* ---- what kind, when, where ------------------------------------------------------------------------ */

const TYPE_LABEL: Record<string, string> = { agm: 'ANNUAL GENERAL MEETING', egm: 'EXTRAORDINARY GENERAL MEETING', phase: 'PHASE MEETING' }

/** The hero's amber line. A type this app does not know is shown as the server wrote it. */
export const typeLabel = (type: string): string => TYPE_LABEL[type] ?? `${type.replace(/[_-]+/g, ' ').trim().toUpperCase()} MEETING`

/**
 * "27 Sep 2026" — the one way a date is written (M98, 12 Oct 2026; the board wrote "Saturday 27 September"), on the
 * Kingston calendar, its year every time. `_at` is kept for the callers that still pass today.
 */
export function dayLine(iso: string | Date, _at?: Date): string {
  return dateShort(iso)
}

/** Where it is held, in a homeowner's words. A link is never printed — it is opened. */
export function whereOf(m: Pick<Meeting, 'venue' | 'virtual_link'>): string {
  const venue = m.venue?.trim() || null
  const online = !!m.virtual_link?.trim()
  if (venue && online) return `${venue} · also online`
  if (venue) return venue
  return online ? 'Online' : 'Venue to be confirmed'
}

/** What is shown where a meeting's date goes while the estate has not set a new one: the console's own words. */
export const DATE_TO_BE_SET = 'New date to be set'

/**
 * NO NEW DATE YET (M98, 12 Oct 2026). While a meeting is postponed with no new date the server sends `date_to_be_set`
 * true, and `starts_at` is still the day it was first called for: that day is never shown as the meeting's date. A
 * meeting saved on this phone before the server sent the flag answers by its status, because `postponed` has only ever
 * meant "moved, with no new date yet" (M56 §2.3). One postponed TO a new date arrives `scheduled` at that date.
 */
export const dateToBeSet = (m: { status: string; date_to_be_set?: boolean | null }): boolean =>
  typeof m.date_to_be_set === 'boolean' ? m.date_to_be_set : m.status === 'postponed'

/**
 * Under a meeting's time, the viewer's own clock when it differs (M19A §4.3) — "7:00 PM your time (Sat 27)" — and null
 * at the estate. A meeting is something a resident acts on: they join it, or they go. Null while there is no date.
 */
export const yourStart = (m: Pick<Meeting, 'starts_at' | 'status' | 'date_to_be_set'>): string | null => (dateToBeSet(m) ? null : yourTimeLine(m.starts_at))

/** "27 Sep 2026, 10:00 AM · Community Centre", or "New date to be set · Community Centre". */
export const whenLine = (m: Pick<Meeting, 'starts_at' | 'status' | 'date_to_be_set' | 'venue' | 'virtual_link'>, _at?: Date): string =>
  dateToBeSet(m) ? `${DATE_TO_BE_SET} · ${whereOf(m)}` : `${dayTimeTag(m.starts_at)} · ${whereOf(m)}`

/**
 * Where it is held, as the SERVER words it (`place`: the venue, the online platform by name, or both) — composed there so
 * the console, the notice and this app say it one way. A meeting saved on this phone before the server sent `place`
 * falls back to the app's own words for its venue and link.
 */
export const placeOf = (m: Pick<Meeting, 'place' | 'venue' | 'virtual_link'>): string => m.place?.trim() || whereOf(m)

/**
 * The lobby hero's line (resident-app-13), its date in the one form (M98): "27 Sep 2026, 10:00 AM · Community Centre",
 * or "New date to be set · Community Centre" while the meeting is postponed with no new date.
 */
export const heroWhen = (m: Pick<Meeting, 'starts_at' | 'status' | 'date_to_be_set' | 'place' | 'venue' | 'virtual_link'>, _at?: Date): string =>
  dateToBeSet(m) ? `${DATE_TO_BE_SET} · ${placeOf(m)}` : `${dayTimeTag(m.starts_at)} · ${placeOf(m)}`

/**
 * The board's lock line under "Join meeting", with the estate's own name where the board prints its own ("Phoenix Park").
 * NOT TRUE TODAY, and drawn because the board draws it: the app hosts no room — joining opens the estate's own link.
 */
export const lockLine = (estate: string | null | undefined): string =>
  `This room is locked to verified ${estate?.trim() ? `${estate.trim()} ` : ''}residents only: there's no link to share, and no outsider can join.`

/**
 * An agenda item's time is a WALL-CLOCK time on the estate ("10:00:00" from a TIME column) — not a
 * moment, so it is never shifted by a time zone. Shown as the board does: "10:00".
 */
export function agendaTime(startTime: string | null): string | null {
  const found = startTime ? /^(\d{1,2}):(\d{2})/.exec(startTime.trim()) : null
  return found ? `${found[1]!.padStart(2, '0')}:${found[2]}` : null
}

/* ---- which meeting --------------------------------------------------------------------------------- */

const startOf = (m: Pick<Meeting, 'starts_at'>): number => new Date(m.starts_at).getTime()

/**
 * The next meeting still to come — or one under way: a meeting that began an hour ago is still "the next meeting" on Home.
 * Never one with no date yet: the day it was first called for is not when it will be held.
 */
export function nextMeeting(items: readonly Meeting[], at: Date): Meeting | null {
  const horizon = at.getTime() - JOIN_CLOSES_AFTER_MIN * MINUTE
  return [...items].filter((m) => m.status === 'scheduled' && !dateToBeSet(m) && startOf(m) >= horizon).sort((p, q) => startOf(p) - startOf(q))[0] ?? null
}

/** `next` is Home's link: /meetings/next. Anything else is the server's id. */
export const findMeeting = (id: string | undefined, items: readonly Meeting[], at: Date): Meeting | null =>
  id === undefined ? null : id === 'next' ? nextMeeting(items, at) : (items.find((m) => String(m.id) === id) ?? null)

/* ---- where a meeting stands ------------------------------------------------------------------------ */

/**
 *   upcoming   scheduled, more than half an hour away
 *   soon       scheduled, the link is open, not started
 *   underway   scheduled and past its start (the office has not marked it held)
 *   overdue    scheduled, but due to start more than six hours ago
 *   held       the office marked it held
 *   cancelled  the office cancelled it
 *   postponed  the office told households, then moved it with no new date yet (M56 §2.3)
 */
export type Phase = 'upcoming' | 'soon' | 'underway' | 'overdue' | 'held' | 'cancelled' | 'postponed'

/**
 * POSTPONED, and why (M56 §2.3, §1.2). The server sends `postponed` beside the three statuses the generated contract
 * names, and `reason` with a postponed or cancelled meeting: read here, so an older server that sends neither still works.
 */
export const isPostponed = (m: { status: string }): boolean => m.status === 'postponed'
export const reasonOf = (m: object): string | null => {
  const reason = (m as { reason?: unknown }).reason
  return typeof reason === 'string' && reason.trim() ? reason.trim() : null
}

export function phaseOf(m: Pick<Meeting, 'status' | 'starts_at' | 'date_to_be_set'>, at: Date): Phase {
  if (m.status === 'cancelled') return 'cancelled'
  if (isPostponed(m) || dateToBeSet(m)) return 'postponed'
  if (m.status === 'held') return 'held'
  const minutes = (startOf(m) - at.getTime()) / MINUTE
  if (minutes > JOIN_OPENS_BEFORE_MIN) return 'upcoming'
  if (minutes > 0) return 'soon'
  return minutes >= -JOIN_CLOSES_AFTER_MIN ? 'underway' : 'overdue'
}

const span = (minutes: number): string => {
  const total = Math.max(1, Math.round(minutes))
  if (total < 60) return `${total} minute${total === 1 ? '' : 's'}`
  const h = Math.floor(total / 60)
  const rest = total % 60
  return `${h} hour${h === 1 ? '' : 's'}${rest ? ` ${rest} min` : ''}`
}

/**
 * A short label and a sentence for where the meeting stands — the Live screen's card. "You can join now"
 * is said only when `joinOpen` — the clock alone does not know whether there is a link this phone will open.
 */
export function standing(m: Pick<Meeting, 'status' | 'starts_at' | 'date_to_be_set'>, at: Date, joinOpen = false): { phase: Phase; label: string; line: string } {
  const phase = phaseOf(m, at)
  const minutes = (startOf(m) - at.getTime()) / MINUTE
  // "27 Sep 2026, 10:00 AM", the one form (M98).
  const day = dayTimeTag(m.starts_at)
  const why = reasonOf(m)
  switch (phase) {
    case 'cancelled':
      return { phase, label: 'CANCELLED', line: why ? `The estate office cancelled this meeting: ${why}` : 'The estate office cancelled this meeting.' }
    case 'postponed':
      return { phase, label: 'POSTPONED', line: `The estate office postponed this meeting. A new date will follow.${why ? ` Reason: ${why}` : ''}` }
    case 'held':
      return { phase, label: 'MEETING ENDED', line: `This meeting was held on ${day}.` }
    case 'upcoming':
      return { phase, label: 'NOT OPEN YET', line: `Starts ${day}.` }
    case 'soon':
      return { phase, label: `STARTS IN ${span(minutes).toUpperCase()}`, line: `Starts at ${timeTag(m.starts_at)}.${joinOpen ? ' You can join now and wait for the chair.' : ''}` }
    case 'underway':
      return { phase, label: 'HAPPENING NOW', line: `Started ${span(-minutes)} ago, at ${timeTag(m.starts_at)}.` }
    case 'overdue':
      return { phase, label: 'START TIME HAS PASSED', line: `This meeting was due to start on ${day}.` }
  }
}

/** The Live screen's amber line under the title: "Today, 10:00 AM" or "27 Sep 2026, 10:00 AM". */
export function statusShort(m: Pick<Meeting, 'status' | 'starts_at' | 'date_to_be_set'>, at: Date): string {
  const phase = phaseOf(m, at)
  if (phase === 'cancelled') return 'Cancelled'
  if (phase === 'postponed') return 'Postponed'
  if (phase === 'held') return 'Ended'
  if (phase === 'underway') return `Started ${timeTag(m.starts_at)}`
  return phase === 'soon' ? `Today, ${timeTag(m.starts_at)}` : dayTimeTag(m.starts_at)
}

/* ---- the link -------------------------------------------------------------------------------------- */

/**
 * Only a plain https address is ever opened: no other scheme (http, tel, intent, javascript, a custom
 * app scheme), no `user@host` trick in the authority, no spaces. Anything else is not opened at all.
 */
const HTTPS = /^https:\/\/([a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+)(?::\d{1,5})?(?:[/?#][^\s]*)?$/i

export const safeLink = (link: string | null | undefined): string | null => (link && HTTPS.test(link.trim()) ? link.trim() : null)

/** The site the link leads to — shown BEFORE it is opened, so the resident knows where they are going: "zoom.us". */
export const linkHost = (link: string | null | undefined): string | null => {
  const found = link ? HTTPS.exec(link.trim()) : null
  return found ? found[1]!.toLowerCase().replace(/^www\./, '') : null
}

export type JoinState = { canJoin: true; url: string; host: string; reason: null } | { canJoin: false; url: null; host: null; reason: string }

const no = (reason: string): JoinState => ({ canJoin: false, url: null, host: null, reason })

/** May the resident open the meeting's link now — and if not, the plain reason why. */
export function joinStateOf(m: Pick<Meeting, 'status' | 'starts_at' | 'date_to_be_set' | 'venue' | 'virtual_link'>, at: Date): JoinState {
  const phase = phaseOf(m, at)
  if (phase === 'cancelled') return no('This meeting was cancelled, so there is nothing to join.')
  if (phase === 'postponed') return no('This meeting is postponed. A new date will follow.')
  if (phase === 'held') return no('This meeting has ended.')
  const venue = m.venue?.trim() || null
  if (!m.virtual_link?.trim()) return no(venue ? `This meeting is held in person at ${venue}. The estate office has not added an online link for it.` : 'The estate office has not added an online link for this meeting yet.')
  const url = safeLink(m.virtual_link)
  const host = linkHost(m.virtual_link)
  if (!url || !host) return no('The link the estate office added is not a secure (https) web address, so the app will not open it. Ask the estate office to check it.')
  if (phase === 'upcoming') {
    const opens = new Date(startOf(m) - JOIN_OPENS_BEFORE_MIN * MINUTE)
    return no(`The link opens ${JOIN_OPENS_BEFORE_MIN} minutes before the meeting: ${dayTimeTag(opens)}.`)
  }
  if (phase === 'overdue') return no('This meeting was due to start more than 6 hours ago. Ask your estate office whether it is still running.')
  return { canJoin: true, url, host, reason: null }
}

/* ---- recording · minutes --------------------------------------------------------------------------- */

/** Said BEFORE the resident joins, in plain words. Past meetings say it in the past. */
export function recordingLine(m: Pick<Meeting, 'recording_enabled' | 'status' | 'virtual_link'>): string | null {
  if (!m.recording_enabled || m.status === 'cancelled') return null
  if (m.status === 'held') return 'This meeting was recorded.'
  return m.virtual_link?.trim()
    ? 'This meeting will be recorded. If you join, what you say, and your camera, if it is on, may be in the recording.'
    : 'This meeting will be recorded. If you attend, what you say may be in the recording.'
}

/**
 * The Live screen's panel: what "joining" really is here. The app carries no video or sound — the
 * meeting is wherever the estate holds it, and the panel says so instead of drawing an empty room.
 */
export function roomNote(m: Pick<Meeting, 'status' | 'date_to_be_set' | 'venue' | 'virtual_link'>): { title: string; body: string } {
  if (m.status === 'cancelled') return { title: 'This meeting was cancelled', body: 'There is nothing to join. If the estate office calls it again, the new meeting will appear in the app.' }
  if (isPostponed(m) || dateToBeSet(m)) return { title: 'This meeting is postponed', body: 'There is nothing to join yet. You will be told the new date, and your household can reply again then.' }
  if (m.status === 'held') return { title: 'This meeting has ended', body: 'There is nothing to join any more.' }
  const venue = m.venue?.trim() || null
  if (m.virtual_link?.trim()) {
    // A link this phone will never open is not promised as one that "opens your estate's link".
    if (!safeLink(m.virtual_link)) {
      return {
        title: 'The online link cannot be opened',
        body: `The link the estate office added is not a secure (https) web address, so the app will not open it. Ask the estate office to check it.${venue ? ` You can still attend in person at ${venue}.` : ''}`,
      }
    }
    return {
      title: 'The meeting opens outside GeminiSecure',
      body: `The app does not carry video or sound. “Join the meeting” opens your estate’s link in your browser or meeting app: you watch, listen and speak there. Come back here any time.${venue ? ` You can also attend in person at ${venue}.` : ''}`,
    }
  }
  return venue
    ? { title: 'This meeting is held in person', body: `It takes place at ${venue}. There is no online link for it. If the estate office adds one, you will be able to join from here.` }
    : { title: 'No online link yet', body: 'The estate office has not said where this meeting will be held, or added an online link. When it does, you will be able to join from here.' }
}

/** An empty agenda: "not yet" only while the meeting is still to come — a held or cancelled one will never get one. */
export const noAgendaLine = (m: Pick<Meeting, 'status'>): string => (m.status === 'scheduled' || isPostponed(m) ? 'The agenda has not been published yet.' : 'No agenda was published for this meeting.')

/**
 * Why the meeting on screen is the phone's saved copy — said as it is. "No connection" only when there is
 * none: a server that answered with an error is not the resident's signal, and the note does not blame it.
 */
export const staleLine = (online: boolean, mayHaveChanged: string): string =>
  `${online ? 'This could not be checked with the estate just now' : 'No connection'}: this is what was saved on this phone. ${mayHaveChanged} may have changed since.`

/** The contract gives a boolean and no address, so the app points at the office — never at a file it cannot fetch. */
/**
 * THE MINUTES (M21 §1.4). The server says `minutes_available` only once the office has PUBLISHED them, and they are read
 * here from `meetings.minutes`. `minutesLine` is what is said when that answer cannot be had.
 */
export const minutesLine = (m: Pick<Meeting, 'minutes_available'>): string | null => (m.minutes_available ? 'The minutes of this meeting are published. Pull down to load them: they need a connection the first time.' : null)

export type MinutesView = { heading: string; meta: string | null; body: string | null; note: string | null }

/** What the minutes card draws: the text as the office published it, when and by whom — or why it cannot be shown. */
export function minutesView(r: Pick<Api.MeetingsMinutesResponse, 'body' | 'recorded_by' | 'published_at'> | undefined, failed: boolean): MinutesView {
  const body = r?.body?.trim() ? r.body.trim() : null
  if (!body) return { heading: 'Minutes', meta: null, body: null, note: failed ? 'The minutes could not be loaded. Pull down to try again.' : 'Loading the minutes…' }
  const published = r?.published_at ? `Published ${dayTimeTag(r.published_at)}` : null
  const by = r?.recorded_by?.trim() ? `recorded by ${r.recorded_by.trim()}` : null
  return { heading: 'Minutes', meta: [published, by].filter(Boolean).join(' · ') || null, body, note: null }
}

/* ---- the household's RSVP -------------------------------------------------------------------------- */

export const RSVP_OPTIONS: readonly { value: Rsvp; label: string; said: string }[] = [
  { value: 'attending', label: 'Attending', said: 'Your household will attend' },
  { value: 'apologies', label: 'Apologies', said: 'Your household sent apologies' },
  { value: 'not_attending', label: 'Not attending', said: 'Your household will not attend' },
]

export const isRsvp = (value: unknown): value is Rsvp => RSVP_OPTIONS.some((o) => o.value === value)

export const rsvpSaid = (value: Rsvp): string => RSVP_OPTIONS.find((o) => o.value === value)!.said

/**
 * The server refuses an RSVP once the meeting has started, been held or been cancelled (409 meeting_closed) — so the app
 * does not offer one. Nor while there is no date to reply for: replies open again with the new date.
 */
export const rsvpOpen = (m: Pick<Meeting, 'status' | 'starts_at' | 'date_to_be_set'>, at: Date): boolean => m.status === 'scheduled' && !dateToBeSet(m) && startOf(m) > at.getTime()

export function rsvpClosedLine(m: Pick<Meeting, 'status' | 'starts_at' | 'date_to_be_set'>): string {
  if (m.status === 'cancelled') return 'This meeting was cancelled.'
  if (isPostponed(m) || dateToBeSet(m)) return 'This meeting is postponed. Replies open again when it has a new date.'
  return m.status === 'held' ? 'This meeting has been held. Replies closed when it started.' : 'Replies closed when the meeting started.'
}

const meetingOf = (op: RsvpOp): string | null => {
  const id = op.params.meeting
  return typeof id === 'string' || typeof id === 'number' ? String(id) : null
}

/** This meeting's queued replies, oldest first — the last one is the household's latest word. */
export const rsvpOpsFor = (id: string, ops: readonly RsvpOp[]): RsvpOp[] => ops.filter((op) => op.endpoint === 'meetings.rsvp' && meetingOf(op) === id)

const field = (value: unknown, key: string): unknown => (value && typeof value === 'object' ? (value as Record<string, unknown>)[key] : undefined)

/**
 * Where the household's reply stands — and who knows it.
 *   sync null        the server's own word (`my_rsvp`), nothing of this phone's in flight
 *   pending          chosen on this phone; the estate has NOT been told yet
 *   syncing          being sent now
 *   confirmed        the server recorded it (its count of households attending comes with the answer)
 *   refused          the server answered no — its sentence is shown, the reply is NOT the household's, and it is never re-sent
 */
export type RsvpState = {
  answer: Rsvp | null
  sync: 'pending' | 'syncing' | 'confirmed' | 'refused' | null
  /** What was tried, when refused. */
  tried: Rsvp | null
  message: string | null
  /** "households that said they will attend" — from the server's answer to this phone's reply, never computed. */
  attending: number | null
}

/**
 * `listRequestedAt` is when the request that produced the list on screen was SENT (this phone's clock), not
 * when it arrived: a list asked for before the reply was tried cannot know of it, however late it lands —
 * so a pull-to-refresh racing the queue never puts the OLD reply back on screen as if it still stood.
 */
export function rsvpStateOf(m: Pick<Meeting, 'id' | 'my_rsvp'>, ops: readonly RsvpOp[], listRequestedAt: number | null): RsvpState {
  const server = isRsvp(m.my_rsvp) ? m.my_rsvp : null
  const mine = rsvpOpsFor(String(m.id), ops)
  const op = mine[mine.length - 1]
  const asked = op && isRsvp(op.body?.response) ? op.body.response : null
  if (!op || !asked) return { answer: server, sync: null, tried: null, message: null, attending: null }
  if (op.state === 'refused') return { answer: server, sync: 'refused', tried: asked, message: op.error?.message ?? 'The estate did not accept this reply.', attending: null }
  if (op.state !== 'confirmed') return { answer: asked, sync: op.state, tried: null, message: null, attending: null }

  // Confirmed. A list asked for AFTER the reply was sent is the newer word (someone else in the household may have changed it since).
  const triedAt = op.lastTriedAt ? new Date(op.lastTriedAt).getTime() : Number.NaN
  const listIsNewer = listRequestedAt !== null && Number.isFinite(triedAt) && listRequestedAt > triedAt
  const said = field(op.result, 'response')
  const recorded = isRsvp(said) ? said : asked
  const raw = field(op.result, 'households_attending')
  const count = typeof raw === 'number' || (typeof raw === 'string' && raw.trim() !== '') ? Number(raw) : Number.NaN
  const attending = Number.isInteger(count) && count >= 0 ? count : null
  if (listIsNewer && server !== recorded) return { answer: server, sync: null, tried: null, message: null, attending: null }
  return { answer: recorded, sync: 'confirmed', tried: null, message: null, attending }
}

/** Queue a reply? Not the one already standing (or on its way) — a second tap is not a second act. A refused reply may be tried again. */
export const shouldQueueRsvp = (choice: Rsvp, state: RsvpState): boolean => state.sync === 'refused' || state.answer !== choice

/** "38 households have said they will attend so far." — RSVPs, in the server's count. Never called a quorum. */
export const attendingLine = (count: number | null): string | null =>
  count === null ? null : `${count === 0 ? 'No households have' : count === 1 ? '1 household has' : `${count} households have`} said they will attend so far. This is not the quorum: that is counted at the meeting.`

export const SYNC_LABEL: Record<NonNullable<RsvpState['sync']>, string> = { pending: 'Pending sync', syncing: 'Syncing', confirmed: 'Confirmed', refused: 'Not accepted' }

/** The sentence under the reply. */
export function rsvpNote(state: RsvpState): string | null {
  if (state.sync === 'pending') return 'Saved on this phone. The estate office will get it when you are back online.'
  if (state.sync === 'syncing') return 'Sending your reply to the estate office…'
  if (state.sync === 'refused') return `${state.message ?? 'The estate did not accept this reply.'} ${state.answer ? `Your earlier reply still stands: ${rsvpSaid(state.answer).toLowerCase()}.` : 'No reply is recorded for your household.'}`
  return null
}
