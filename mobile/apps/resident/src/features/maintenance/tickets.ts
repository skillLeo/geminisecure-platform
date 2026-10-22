/**
 * The maintenance feature's pure logic — no React, no UI kit, tested under Jest.
 *
 *   CATEGORIES          the SERVER's nine values, in words a homeowner uses — how a filed request's category is
 *                       read back (categoryLabel).
 *   REQUEST_CHIPS       the new-request form's four chips (board resident-app-11, M42 §5 #10): Plumbing ·
 *                       Electrical · Lighting · Gate, each filed under one of the server's categories.
 *   requestSchema       what the form needs before anything is queued: a chip, and what is wrong (M18C). A
 *                       queued request's refusal arrives later with a sentence and no per-field errors, so
 *                       nothing is sent that the server's own limits would refuse.
 *   titleFrom()         the TITLE the server requires (4–140) and the design has no field for: the resident's own first
 *                       words and the place (M47 §11.5) — "The gate light dem nuh work since Tuesday — Common area".
 *   readNotes()         "Allow entry when I'm not home" and "Best time to come" are now COLUMNS on the
 *                       ticket — named fields on a named screen in the specification, not free
 *                       text. Until this build the phone wrote them INTO the description as lines under
 *                       "Notes to the office:", so every request the household filed before it still
 *                       carries them there — readNotes() prefers the columns and falls back to the text,
 *                       and hands back the description with the old block taken out so it is never shown
 *                       twice. Nothing in the household's history is rewritten to get there.
 *   ticketBody()        the fields tickets.store takes, and only those. Inside the unit, `location` is
 *                       left out: the server fills in the unit itself. No `preferred_time`: "Best time to
 *                       come" left the form (M42 §5 #10).
 *   stageRows()         the server's timeline laid on the board's six-stage spine.
 *   boardSteps() …      the same spine, the vendor's line and the summary in board resident-app-26's own words and
 *                       format ("Sep 3, 8:15 AM · Property Manager", "Tech: Owen Grant · today, 2:00–4:00 PM").
 *
 * A comment is a note on the request's history (`tickets.comment`, M47 §11b); there is no rate or close endpoint for a
 * resident. A job the office marked completed is answered "fixed" or "still a problem" (useTickets.ts, fixedAnswer).
 */

// From the lib entry, not the package root: this file is pure, and is tested without the UI kit loaded.
import { dateShort, dayTimeTag, siteParts, time12, timeCompact, timeTag } from '@gs/core/lib'
import type { Api } from '@gs/core/api'
import { z } from 'zod'

type ApiTicket = Api.TicketsIndexResponse['items'][number]
type ApiEntry = ApiTicket['timeline'][number]
/**
 * The generated `event` is inferred from the contract's examples ("reported, acknowledged, …"); the server has more
 * (reassigned, reopened, verified…), so it is widened here. `by` and `vendor` are optional: a server from before M47
 * sends neither, and a ticket this phone saved from one still opens.
 */
export type TimelineEntry = Omit<ApiEntry, 'event' | 'by'> & { event: string; by?: ApiEntry['by'] }
export type Ticket = Omit<ApiTicket, 'timeline' | 'vendor'> & { timeline: readonly TimelineEntry[]; vendor?: ApiTicket['vendor'] }
export type Status = Ticket['status']
export type Priority = NonNullable<Api.TicketsStoreRequest['priority']>
export type CategoryId = NonNullable<Api.TicketsStoreRequest['category']>

// ───────────────────────────────────────────────────────────── categories · priority

export const CATEGORIES: readonly { value: CategoryId; label: string }[] = [
  { value: 'plumbing', label: 'Plumbing' },
  { value: 'electrical', label: 'Electrical' },
  { value: 'water', label: 'Water supply' },
  { value: 'security', label: 'Security' },
  { value: 'landscaping', label: 'Landscaping' },
  { value: 'roads', label: 'Roads' },
  { value: 'waste', label: 'Garbage' },
  { value: 'amenity', label: 'Amenities' },
  { value: 'other', label: 'Other' },
]

export const isCategory = (value: unknown): value is CategoryId => CATEGORIES.some((cat) => cat.value === value)

const sentenceCase = (text: string): string => {
  const words = text.replace(/[_-]+/g, ' ').trim()
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}

/** The server's category in words. One this app does not know (the console may add its own) is shown as written. */
export const categoryLabel = (value: string | null | undefined): string | null => (value ? (CATEGORIES.find((cat) => cat.value === value)?.label ?? sentenceCase(value)) : null)

export const PRIORITIES: readonly { value: Priority; label: string }[] = [
  { value: 'low', label: 'Low' },
  { value: 'medium', label: 'Medium' },
  { value: 'high', label: 'High' },
]

export const isPriority = (value: unknown): value is Priority => value === 'low' || value === 'medium' || value === 'high'

// ───────────────────────────────────────────────────────────── the form

/**
 * Board resident-app-11's four chips (M42 §5 #10: "four chips, all visible, nothing cut off") and the server category
 * each is filed under. The server has no lighting or gate category: a light is electrical work, and an estate's gate is
 * its security equipment. The chip's word is the title only when the resident wrote nothing to make one of (titleFrom).
 */
export const REQUEST_CHIPS = [
  { id: 'plumbing', label: 'Plumbing', category: 'plumbing' },
  { id: 'electrical', label: 'Electrical', category: 'electrical' },
  { id: 'lighting', label: 'Lighting', category: 'electrical' },
  { id: 'gate', label: 'Gate', category: 'security' },
] as const satisfies readonly { id: string; label: string; category: CategoryId }[]

export type ChipId = (typeof REQUEST_CHIPS)[number]['id']

export const isChipId = (value: unknown): value is ChipId => REQUEST_CHIPS.some((chip) => chip.id === value)

/** The server's title limits (tickets.store: required, 4–140 characters). */
export const TITLE_MIN = 4
export const TITLE_MAX = 140
/** The server's own ceiling. Nothing is appended to what the resident wrote, and the form stops short of it. */
export const SERVER_DESCRIPTION_MAX = 2000
export const DESCRIPTION_MAX = 1800

export type Area = 'unit' | 'common'

/** The segment's own words — and the `location` a common-area request is filed under. */
export const COMMON_AREA = 'Common area'

/** Trimmed, and runs of spaces made one. */
export const tidyTitle = (typed: string): string => typed.trim().replace(/\s+/g, ' ')

/** About how much of the resident's words a title takes. */
export const TITLE_WORDS = 40
/** A clause shorter than this ("Mr", "Hi") is not a title: the words run on to the next stop. */
const CLAUSE_MIN = 8

/**
 * THE TITLE THE SERVER REQUIRES, written by the app from WHAT IS WRONG, THEN WHERE (M47 §11.5; the user, 7 October:
 * "Resident's words + place" — "Plumbing — Lot 47" tells the office nothing when it has fifty of those). The first
 * sentence or clause of what the resident wrote, kept to about forty characters and never cut inside a word ("…" where
 * it was cut), a dash, and the place: the unit for work inside it, "Common area" for the estate's own ground —
 * "The gate light dem nuh work since Tuesday — Common area". `fallback` (the chip's word) when there are no words.
 * At least TITLE_MIN, cut at TITLE_MAX.
 */
export function titleFrom(words: string, place: string | null, fallback: string): string {
  const raw = words.trim()
  const stops = /[.!?;,](?=\s|$)|\n/g
  let end = raw.length
  for (let found = stops.exec(raw); found; found = stops.exec(raw)) {
    if (tidyTitle(raw.slice(0, found.index)).length >= CLAUSE_MIN) {
      end = found.index
      break
    }
  }
  let lead = tidyTitle(raw.slice(0, end)).replace(/[.!?;,:]+$/, '')
  if (lead.length > TITLE_WORDS + 8) {
    // Up to the last whole word within about forty characters: the slice runs one past, so a word ending AT the
    // fortieth is kept whole.
    const cut = lead.slice(0, TITLE_WORDS + 2)
    const space = cut.lastIndexOf(' ')
    lead = `${(space > 0 ? cut.slice(0, space) : cut.slice(0, TITLE_WORDS)).replace(/[\s,;:–—-]+$/, '')}…`
  }
  lead = lead ? lead.charAt(0).toUpperCase() + lead.slice(1) : ''
  const where = tidyTitle(place ?? '')
  const title = lead ? (where ? `${lead}: ${where}` : lead) : where ? `${fallback}: ${where}` : fallback
  return (title.length >= TITLE_MIN ? title : fallback).slice(0, TITLE_MAX).trimEnd()
}

export const requestSchema = z.object({
  // Nothing is optional (M18C): what kind of problem, and what is wrong in the resident's own words.
  kind: z.string().refine(isChipId, 'Choose what kind of problem it is.'),
  area: z.enum(['unit', 'common']),
  description: z.string().trim().min(3, 'Say what is wrong: a sentence is enough, or tap the microphone and say it.').max(DESCRIPTION_MAX, `Keep the details to ${DESCRIPTION_MAX} characters or fewer.`),
  priority: z.string().refine(isPriority, 'Choose how urgent this is.'),
  allowEntry: z.boolean(),
})

export type RequestForm = z.input<typeof requestSchema>
export type RequestField = keyof RequestForm

/** In the order the screen draws them — the first message is the one shown above Submit. */
export const REQUEST_FIELDS: readonly RequestField[] = ['kind', 'area', 'description', 'priority', 'allowEntry']

export const EMPTY_REQUEST: RequestForm = { kind: '', area: 'unit', description: '', priority: 'medium', allowEntry: false }

/**
 * "Report it again" (a cancelled request's status screen) hands over the old request's title. A title this form wrote
 * names its chip and its place, so they are chosen again; any other — typed into an older build — starts the transcript.
 */
export function prefillFrom(title: string): Partial<RequestForm> {
  const words = tidyTitle(title)
  if (!words) return {}
  // An older build joined the chip and the place with a dash, this one with a colon (Law 3): both are read.
  const chip = REQUEST_CHIPS.find((c) => words === c.label || words.startsWith(`${c.label}: `) || words.startsWith(`${c.label} — `))
  if (!chip) return { description: words.slice(0, DESCRIPTION_MAX) }
  return words === `${chip.label}: ${COMMON_AREA}` || words === `${chip.label} — ${COMMON_AREA}` ? { kind: chip.id, area: 'common' } : { kind: chip.id }
}

/**
 * The heading the phone used to write entry and a preferred time under, before the server had columns
 * for them. NOTHING WRITES IT ANY MORE — it is kept because the household's older requests carry it,
 * and readNotes() is how those are still read.
 */
export const NOTES_HEADING = 'Notes to the office:'

const ENTRY_KEY = "Entry when I'm not home"
const BEST_TIME_KEY = 'Best time to come'

export type TicketNotes = {
  /** The description with the old "Notes to the office:" block taken out — what the screen shows. */
  text: string
  /** True / false as the household answered; null where the request never said (which is not "no"). */
  allowEntry: boolean | null
  bestTime: string | null
}

/**
 * What the household said about letting somebody in, and when.
 *
 * THE COLUMNS WIN, AND THE OLD TEXT IS THE FALLBACK. A request sent by this build carries
 * `access_permitted` / `preferred_time` and a clean description; one captured before it carries the
 * two answers as lines in the description and nulls in the columns. Both read the same here — and the
 * block is taken out of `text` so the screen never prints the same answer twice.
 */
export function readNotes(ticket: Pick<Ticket, 'description'> & Partial<Pick<Ticket, 'access_permitted' | 'preferred_time'>>): TicketNotes {
  const raw = (ticket.description ?? '').replace(/\r\n/g, '\n')
  const column = { allowEntry: ticket.access_permitted ?? null, bestTime: ticket.preferred_time?.trim() || null }

  const at = raw.lastIndexOf(NOTES_HEADING)
  const written = at === -1 || (at > 0 && raw[at - 1] !== '\n') ? null : raw.slice(at + NOTES_HEADING.length).split('\n').map((line) => line.trim()).filter(Boolean)
  // A block this app did not write (a heading the office typed, words after it) is left in the text, whole.
  if (!written || written.length === 0 || written.some((line) => !line.startsWith('- '))) return { text: raw.trim(), ...column }

  const valueOf = (key: string): string | null => {
    const line = written.find((l) => l.startsWith(`- ${key}: `))
    return line ? line.slice(key.length + 4).trim() || null : null
  }

  return {
    text: raw.slice(0, at).trim(),
    allowEntry: column.allowEntry ?? (valueOf(ENTRY_KEY) === null ? null : true),
    bestTime: column.bestTime ?? valueOf(BEST_TIME_KEY),
  }
}

/** "Entry with nobody home: yes · Best time: Weekday mornings" — or null where the request said neither. */
export function accessLine(notes: TicketNotes): string | null {
  const parts = [notes.allowEntry === null ? null : `Entry with nobody home: ${notes.allowEntry ? 'yes' : 'no'}`, notes.bestTime ? `Best time: ${notes.bestTime}` : null].filter((part): part is string => !!part)
  return parts.length ? parts.join(' · ') : null
}

export type TicketBody = Omit<Api.TicketsStoreRequest, '__stamp'>

/**
 * Exactly the fields tickets.store takes. Empty optionals are left out, never sent as "".
 *
 * The chip is filed under its server category, and the title is written from the resident's words and the place
 * (titleFrom).
 * INSIDE THE UNIT no location is sent — the server files it against the unit — and entry is answered BY NAME,
 * `access_permitted`, "off" sent as the answer it is. ON THE ESTATE'S OWN GROUND the location is "Common area" (left
 * out, the server would file a street light against the unit), and entry is never asked, so nothing is answered for
 * the household: the server reads the missing field as "the request did not say". No `preferred_time`: the form no
 * longer asks for one (M42 §5 #10).
 */
export function ticketBody(values: RequestForm, unit: string | null): TicketBody {
  if (!isPriority(values.priority)) throw new Error('priority not chosen')
  const chip = REQUEST_CHIPS.find((c) => c.id === values.kind)
  if (!chip) throw new Error('kind not chosen')
  const inTheUnit = values.area === 'unit'
  return {
    title: titleFrom(values.description, inTheUnit ? unit : COMMON_AREA, chip.label),
    category: chip.category,
    description: values.description.trim().slice(0, SERVER_DESCRIPTION_MAX),
    ...(inTheUnit ? null : { location: COMMON_AREA }),
    priority: values.priority,
    ...(inTheUnit ? { access_permitted: values.allowEntry } : null),
  }
}

/** Dictated words are added to what is already written — dictation never replaces the resident's typing. */
export function appendTranscript(description: string, heard: string): string {
  const words = heard.trim().replace(/\s+/g, ' ')
  if (!words) return description
  const base = description.replace(/\s+$/, '')
  return (base ? `${base} ${words}` : words).slice(0, DESCRIPTION_MAX)
}

// ───────────────────────────────────────────────────────────── photos

/** The server sets no count; this is the phone's own ceiling — four thumbs and the add tile fill the board's row (5 × 60 + 4 × 9 = 336 of 350). */
export const PHOTO_MAX = 4
/** On-device compression asked of the picker and of the shrinker. */
export const PHOTO_QUALITY = 0.6

export type Photo = { uri: string; mime: string | null; fileName: string | null }

const EXTENSION: Record<string, string> = { 'image/jpeg': 'jpg', 'image/jpg': 'jpg', 'image/png': 'png', 'image/heic': 'heic', 'image/heif': 'heif' }

/** tickets.media's body: multipart, one field, `file` — the generated endpoint picks that key and no other. */
export function mediaBody(photo: Photo, index: number): { file: Api.UploadFile } {
  const fromUri = /\.(jpe?g|png|hei[cf])(?:$|[?#])/i.exec(photo.uri)?.[1]?.toLowerCase().replace('jpeg', 'jpg')
  const type = photo.mime && EXTENSION[photo.mime.toLowerCase()] ? photo.mime.toLowerCase() : fromUri ? (fromUri === 'jpg' ? 'image/jpeg' : `image/${fromUri}`) : 'image/jpeg'
  const extension = EXTENSION[type] ?? 'jpg'
  return { file: { uri: photo.uri, name: `request-photo-${index + 1}.${extension}`, type } }
}

// ───────────────────────────────────────────────────────────── status: the server's timeline on the board's spine

/**
 * A request's state in the consoles' words (M98, 12 Oct 2026; MaintenanceTicket::STATE_LABELS): "Under review" where it
 * said Acknowledged and "Closed" where it said Verified, so the household and the office name a job's state alike.
 */
export const STATUS_LABEL: Record<Status, string> = {
  submitted: 'Submitted',
  acknowledged: 'Under review',
  assigned: 'Assigned',
  in_progress: 'In progress',
  completed: 'Completed',
  verified: 'Closed',
  cancelled: 'Cancelled',
}

/** A closed request takes no more work: completed, verified by the household, or cancelled. */
export const isClosed = (status: Status): boolean => status === 'completed' || status === 'verified' || status === 'cancelled'

type Stage = { status: Exclude<Status, 'cancelled'>; label: string; events: readonly string[] }

/** Board resident-app-26's six stages, in lifecycle order, with the server events that fill each (the consoles' words, M98). */
export const STAGES: readonly Stage[] = [
  { status: 'submitted', label: 'Submitted', events: ['reported'] },
  { status: 'acknowledged', label: 'Under review', events: ['acknowledged'] },
  { status: 'assigned', label: 'Assigned to vendor', events: ['assigned'] },
  { status: 'in_progress', label: 'In progress', events: ['started', 'reopened'] },
  { status: 'completed', label: 'Completed', events: ['resolved'] },
  { status: 'verified', label: 'Closed', events: ['verified'] },
]

const EVENT_LABEL: Record<string, string> = {
  reported: 'Submitted',
  acknowledged: 'Under review',
  assigned: 'Assigned to vendor',
  reassigned: 'Vendor changed',
  priority_changed: 'Urgency changed',
  started: 'Work started',
  resolved: 'Completed',
  reopened: 'Reopened',
  verified: 'Closed',
  cancelled: 'Cancelled',
}

/** "2 Sep 2026, 6:40 PM" — on the site’s clock, in the one form (M98). */
export const stamp = (iso: string): string => dayTimeTag(iso)

/** When a stage or an entry happened, the office's note on it, and who in the office did it — before any is written out. */
type Moment = { at: string | null; note: string | null; by: string | null }

/**
 * "Property Manager" — the member of staff behind an entry, by their role as board 26 names them, else by name (M47
 * §11.4). The household's own entries are theirs and need no name; an older server names nobody.
 */
export function staffOf(entry: Partial<Pick<TimelineEntry, 'by'>> | undefined): string | null {
  const by = entry?.by
  if (!by || by.kind !== 'staff') return null
  return by.role?.trim() || by.name?.trim() || null
}

const momentOf = (entry: TimelineEntry | undefined, fallbackIso: string | null): Moment => ({
  at: entry?.occurred_at ?? fallbackIso ?? null,
  note: entry?.note?.trim() || null,
  by: staffOf(entry),
})

const detailOf = ({ at, note }: Pick<Moment, 'at' | 'note'>): string | null => {
  if (!at) return note
  return note ? `${stamp(at)} · ${note}` : stamp(at)
}

export type StageRow = {
  key: string
  label: string
  /** done: a tick · current: where the request is now · todo: not yet · stopped: cancelled here */
  state: 'done' | 'current' | 'todo' | 'stopped'
  detail: string | null
}

export type UpdateRow = { key: string; label: string; detail: string }

/** A stage as the server recorded it (stageMoments): its moment, not yet written out. */
export type StageMoment = Omit<StageRow, 'detail'> & Moment
type UpdateMoment = { key: string; event: string } & Moment

/**
 * The spine, and whatever else the server recorded. A stage's line is the LATEST timeline entry that put
 * the request in that status (a reopened job's "In progress" is when it was reopened, with the reason).
 * Entries that fill no stage — a change of vendor or urgency, an earlier completion before a reopen — are
 * returned as `updates`, oldest first, so the resident can follow every change without asking.
 */
export function stageRows(ticket: Ticket): { rows: StageRow[]; updates: UpdateRow[] } {
  const { rows, updates } = stageMoments(ticket)
  return {
    rows: rows.map(({ key, label, state, at, note }) => ({ key, label, state, detail: detailOf({ at, note }) })),
    updates: updates.map(({ key, event, at, note }) => ({ key, label: EVENT_LABEL[event] ?? sentenceCase(event), detail: detailOf({ at, note }) ?? '' })),
  }
}

/** stageRows' reading of the timeline, each stage's moment kept apart so a board can write it in its own format. */
export function stageMoments(ticket: Ticket): { rows: StageMoment[]; updates: UpdateMoment[] } {
  const timeline = ticket.timeline ?? []
  const used = new Set<number>()

  const lastIndex = (test: (entry: TimelineEntry) => boolean): number => {
    let found = -1
    timeline.forEach((entry, i) => {
      if (test(entry)) found = i
    })
    return found
  }

  const entryFor = (stage: Stage): TimelineEntry | undefined => {
    // The stage's own event; else that event under another status (a first vendor named while work was
    // already under way); else an event this app has no name for that put the request in this status.
    const tests: ((entry: TimelineEntry) => boolean)[] = [
      (entry) => entry.status === stage.status && stage.events.includes(entry.event),
      (entry) => stage.events.includes(entry.event),
      (entry) => entry.status === stage.status && !(entry.event in EVENT_LABEL),
    ]
    for (const test of tests) {
      const index = lastIndex(test)
      if (index === -1) continue
      used.add(index)
      return timeline[index]
    }
    return undefined
  }

  const cancelled = ticket.status === 'cancelled'
  // A cancelled request is drawn up to the furthest stage it reached.
  const reached = cancelled
    ? STAGES.reduce((furthest, stage, i) => (timeline.some((entry) => entry.status === stage.status || stage.events.includes(entry.event)) ? i : furthest), 0)
    : STAGES.findIndex((stage) => stage.status === ticket.status)
  const all = ticket.status === 'verified'

  const rows: StageMoment[] = []
  STAGES.forEach((stage, i) => {
    if (i > reached) {
      if (!cancelled) rows.push({ key: stage.status, label: stage.label, state: 'todo', at: null, note: null, by: null })
      return
    }
    const entry = entryFor(stage)
    const state = all || cancelled || i < reached ? 'done' : 'current'
    rows.push({ key: stage.status, label: stage.label, state, ...momentOf(entry, i === 0 ? ticket.reported_at : null) })
  })

  if (cancelled) {
    const index = lastIndex((entry) => entry.status === 'cancelled' || entry.event === 'cancelled')
    if (index !== -1) used.add(index)
    rows.push({ key: 'cancelled', label: 'Cancelled', state: 'stopped', ...momentOf(index === -1 ? undefined : timeline[index], ticket.closed_at) })
  }

  const updates: UpdateMoment[] = []
  timeline.forEach((entry, i) => {
    if (used.has(i)) return
    updates.push({ key: `${i}:${entry.event}`, event: entry.event, ...momentOf(entry, null) })
  })

  return { rows, updates }
}

// ───────────────────────────────────────────────────────────── board resident-app-26's own words and format

/** "2 Sep 2026" — a stage's date in the one form (M98; the board drew "Sep 2"), on the estate's clock. '' for none. */
export const monthDay = (iso: string): string => dateShort(iso)

/** "2 Sep 2026, 6:40 PM" — the stamp on a stage, on the estate's clock. */
export const boardStamp = (iso: string): string => {
  const day = monthDay(iso)
  return day ? `${day}, ${time12(iso)}` : ''
}

/**
 * The last stage, named for who reached it: only the household can close a job by saying it is fixed
 * (tickets.confirm_fixed). The board's "Verified by you" is "Closed by you" since the consoles say Closed (M98).
 */
export const VERIFIED_BY_YOU = 'Closed by you'

/** A stage as the board's timeline draws it: its title and, once reached, its line. */
export type BoardStep = { key: string; title: string; state: StageRow['state']; line: string | null }

/** The stage that names who in the office took the request up — the board names nobody on any other (M47 §11.4). */
const NAMED_STAGE = 'acknowledged'

/**
 * The board's six stages: "Submitted · 2 Sep 2026, 6:40 PM", "Under review · 3 Sep 2026, 8:15 AM · Property Manager",
 * "Assigned to vendor · 3 Sep 2026, 11:20 AM" — the stamp, and the office's note or, on "Under review" with none, who in the
 * office took it up (the vendor card says who has the job). The stage the request is at now says what is happening
 * ("In progress · Technician on site"), or its stamp where the office left no note. A stage not reached yet is its
 * title alone.
 */
export function boardSteps(ticket: Ticket): BoardStep[] {
  return stageMoments(ticket).rows.map(({ key, label, state, at, note, by: staff }) => {
    const by = key === NAMED_STAGE ? staff : null
    const when = at ? boardStamp(at) || null : null
    const line = state === 'todo' ? null : state === 'current' ? (note ?? ([when, by].filter((part): part is string => !!part).join(' · ') || null)) : [when, note ?? by].filter((part): part is string => !!part).join(' · ') || null
    return { key, title: key === 'verified' ? VERIFIED_BY_YOU : label, state, line }
  })
}

/** "Submitted Sep 2 · Electrical" — the line under the request's title. It names no place: the title does. */
export function submittedLine(ticket: Pick<Ticket, 'reported_at' | 'category'>): string {
  return [`Submitted ${monthDay(ticket.reported_at)}`, categoryLabel(ticket.category)].filter((part): part is string => !!part).join(' · ')
}

/** The resident's own words, between the board's quotation marks. Null when they wrote none. */
export function inQuotes(text: string): string | null {
  const words = text.trim()
  if (!words) return null
  return words.length > 1 && words.startsWith('"') && words.endsWith('"') ? words : `"${words}"`
}

/** One message on a request: who wrote it, whether that was the office, the words, and "Sep 3, 8:15 AM". */
export type MessageRow = { key: string; from: string; office: boolean; text: string; when: string }

/**
 * "Messages" (M54 §2.2: "staff and resident post into one thread"): the household's comments (`tickets.comment`) and
 * what the office wrote back (the console's "Message resident", and "Visit time changed"), oldest first. A note the
 * server names nobody on is the office's. Not on board resident-app-26, so the board never draws it.
 */
export function threadOf(ticket: Pick<Ticket, 'timeline'>): MessageRow[] {
  return (ticket.timeline ?? []).flatMap((entry, i) => {
    const text = entry.note?.trim()
    if (entry.event !== 'note' || !text) return []
    const office = entry.by?.kind !== 'resident'
    const from = office ? (staffOf(entry) ?? 'Estate office') : entry.by?.name?.trim() || 'Your household'
    return [{ key: `${i}:${entry.occurred_at}`, from, office, text, when: entry.occurred_at ? boardStamp(entry.occurred_at) : '' }]
  })
}

/**
 * The fuller of two copies of one request's history: the list's, or the server's answer to a message this phone sent.
 * A history only grows, so the longer one is the newer, and a message just sent is in the thread before the list is
 * fetched again.
 */
export function newerHistory(listed: Ticket, answers: readonly unknown[]): Ticket {
  return answers.filter(isTicket).filter((answer) => String(answer.id) === String(listed.id)).reduce<Ticket>((best, answer) => ((answer.timeline?.length ?? 0) > (best.timeline?.length ?? 0) ? answer : best), listed)
}

// ───────────────────────────────────────────────────────────── who is coming, and when

const DAY_MS = 86_400_000
const dayNumber = (input: Date | string): number => {
  const p = siteParts(input)
  return Math.floor(Date.UTC(p.year, p.month, p.day) / DAY_MS)
}

/** How a window is written: the date of a day that is not today, tomorrow or yesterday, and the time that ends it. */
type WindowFormat = { date: (iso: string) => string; end: (iso: string) => string }

const dayWord = (iso: string, nowDate: Date, date: WindowFormat['date'] = dateShort): string => {
  const days = dayNumber(iso) - dayNumber(nowDate)
  return days === 0 ? 'today' : days === 1 ? 'tomorrow' : days === -1 ? 'yesterday' : date(iso)
}

function windowWith(startsAt: string | null, endsAt: string | null, nowDate: Date, f: WindowFormat): string | null {
  const first = startsAt ?? endsAt
  if (!first) return null
  const day = dayWord(first, nowDate, f.date)
  if (!startsAt) return `${day}, by ${f.end(first)}`
  if (!endsAt) return `${day}, from ${f.end(startsAt)}`
  const sameDay = dayNumber(startsAt) === dayNumber(endsAt)
  if (!sameDay) return `${day}, ${time12(startsAt)} – ${dayWord(endsAt, nowDate, f.date)}, ${f.end(endsAt)}`
  const sameHalf = siteParts(startsAt).hour < 12 === siteParts(endsAt).hour < 12
  return sameHalf ? `${day}, ${timeCompact(startsAt)}–${f.end(endsAt)}` : `${day}, ${time12(startsAt)} – ${f.end(endsAt)}`
}

/**
 * "today, 2:00–4:00 PM" · "tomorrow, 11:30 AM – 1:00 PM" · "9 Sep 2026, from 9:00 AM" — or null when the office gave no
 * window. The estate's clock, no tag (M98).
 */
export function etaWindow(startsAt: string | null, endsAt: string | null, nowDate: Date): string | null {
  return windowWith(startsAt, endsAt, nowDate, { date: dateShort, end: timeTag })
}

/** "today, 2:00–4:00 PM" · "9 Sep 2026, from 9:00 AM" — the window on board resident-app-26, on the estate's clock. */
export function visitWindow(startsAt: string | null, endsAt: string | null, nowDate: Date): string | null {
  return windowWith(startsAt, endsAt, nowDate, { date: monthDay, end: time12 })
}

/**
 * The board's vendor card line, "Tech: Owen Grant · today, 2:00–4:00 PM" — or what of it the office has given: the
 * technician's name, the window, or both. Null when it gave neither. A closed job has no window to wait for.
 */
export function vendorLine(ticket: Ticket, nowDate: Date): string | null {
  const name = ticket.technician_name?.trim() ?? ''
  const window = isClosed(ticket.status) ? null : visitWindow(ticket.eta_starts_at, ticket.eta_ends_at, nowDate)
  const parts = [name ? `Tech: ${name}` : null, window].filter((part): part is string => !!part)
  return parts.length ? parts.join(' · ') : null
}

export const initialsOf = (name: string): string => {
  const words = name.trim().split(/\s+/).filter(Boolean)
  const letters = words.length > 1 ? `${words[0]![0]}${words[words.length - 1]![0]}` : (words[0] ?? '').slice(0, 2)
  return letters.toUpperCase()
}

/** "IE" for Island Electric Services — a company's first two words, as board 26's avatar draws it. */
export const companyInitials = (name: string): string => {
  const words = name.trim().split(/\s+/).filter((w) => /[A-Za-z0-9]/.test(w))
  return (words.length > 1 ? `${words[0]![0]}${words[1]![0]}` : (words[0] ?? '').slice(0, 2)).toUpperCase()
}

/**
 * Board 26's "Assigned vendor" card (M47 §11a): the company the estate gave the job to and its initials, "Tech: Owen
 * Grant · today, 2:00–4:00 PM", and the number its call button rings — the technician's, else the company's. Null when
 * nobody has the job yet. An older server sent no company: the technician's initials, and no company line.
 */
export function vendorOf(ticket: Ticket, nowDate: Date): { initials: string | null; name: string | null; line: string | null; phone: string | null } | null {
  const company = ticket.vendor ?? null
  const name = company?.name?.trim() || null
  const tech = ticket.technician_name?.trim() ?? ''
  const line = vendorLine(ticket, nowDate)
  if (!name && !line) return null
  return { initials: name ? companyInitials(name) : tech ? initialsOf(tech) : null, name, line, phone: company?.phone?.trim() || null }
}

/**
 * The technician card: what the server gives a resident is a name and a window — no company, no phone number.
 *
 * The window is on the ESTATE's clock and carries its tag on every phone ("Expected tomorrow, 12:05–12:06 AM JA", M19A
 * §4.2): read as the phone's own time from abroad it was ten hours off (the client, 26 Sep).
 */
export function visitOf(ticket: Ticket, nowDate: Date): { initials: string; name: string; detail: string | null } | null {
  const name = ticket.technician_name?.trim() ?? ''
  const eta = isClosed(ticket.status) ? null : etaWindow(ticket.eta_starts_at, ticket.eta_ends_at, nowDate)
  if (!name && !eta) return null
  return { initials: name ? initialsOf(name) : '—', name: name || 'A technician', detail: eta ? `Expected ${eta}` : null }
}

/** "Submitted Sep 2 · Electrical · Lot 47" */
export function metaLine(ticket: Ticket): string {
  return [`Submitted ${dateShort(ticket.reported_at)}`, categoryLabel(ticket.category), ticket.location?.trim() || null].filter((part): part is string => !!part).join(' · ')
}

export const requestTitle = (ticket: Pick<Ticket, 'number'>): string => `Request #${String(ticket.number).replace(/^#/, '')}`

// ───────────────────────────────────────────────────────────── "Your requests": the server's list + what this phone has not sent

/** What this file needs of an outbox record (structurally the outbox's own `OutboxOp`). */
export type QueuedOp = {
  id: string
  endpoint: string
  state: 'pending' | 'syncing' | 'confirmed' | 'refused'
  capturedAt: string
  after: string | null
  /** The path's values — `{ ticket }` for an act on one request (a comment, "is it fixed?"). */
  params?: Record<string, unknown>
  body: Record<string, unknown> | null
  error: { message: string } | null
  result: unknown
}

export type RecentRow = {
  key: string
  title: string
  detail: string
  /** A request still on this phone: its outbox state. Null once it is the server's. */
  queue: 'pending' | 'syncing' | 'refused' | null
  /** The server's status, once it has one. */
  status: Status | null
  /** Opens /maintenance/[id]. A request the server has not taken has no id and opens nothing. */
  ticketId: string | null
  /** The outbox act behind a row that is still on this phone — what a read refusal is cleared by. Null once it is the server's. */
  opId: string | null
}

export const RECENT_MAX = 5

const textOf = (value: unknown): string => (typeof value === 'string' ? value : '')
const timeOf = (iso: string): number => new Date(iso).getTime()
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`

/** Photos of this ticket that are still on the phone, waiting their turn behind the request. */
export function photosWaiting(ticketId: string, ops: readonly QueuedOp[]): number {
  return photosOf(ticketId, ops).filter((op) => op.state === 'pending' || op.state === 'syncing').length
}

/**
 * Photos of this ticket the server ANSWERED no to (too large, a type it does not take). The request itself
 * stands; the resident is told on the request's own page, so nobody believes a picture went along that did not.
 */
export function photosRefused(ticketId: string, ops: readonly QueuedOp[]): number {
  return photosOf(ticketId, ops).filter((op) => op.state === 'refused').length
}

function photosOf(ticketId: string, ops: readonly QueuedOp[]): QueuedOp[] {
  const parents = new Set(ops.filter((op) => op.endpoint === 'tickets.store' && isTicket(op.result) && String(op.result.id) === ticketId).map((op) => op.id))
  return ops.filter((op) => op.endpoint === 'tickets.media' && op.after !== null && parents.has(op.after))
}

/**
 *   waiting / being sent  → on top, said to be on this phone — never "submitted"
 *   refused               → with the server's sentence
 *   confirmed             → the server's now. Until the refreshed list carries it, it is drawn from the
 *                           server's own answer, so a request never vanishes between "sent" and "listed".
 * Newest first. Never drawn twice. The server's part stops at RECENT_MAX.
 */
export function recentRows(input: { items: readonly Ticket[]; ops: readonly QueuedOp[] }): RecentRow[] {
  const requests = input.ops.filter((op) => op.endpoint === 'tickets.store')
  const media = input.ops.filter((op) => op.endpoint === 'tickets.media' && (op.state === 'pending' || op.state === 'syncing'))

  const unsent: RecentRow[] = requests
    .filter((op): op is QueuedOp & { state: 'pending' | 'syncing' | 'refused' } => op.state !== 'confirmed')
    .slice()
    .sort((x, y) => timeOf(y.capturedAt) - timeOf(x.capturedAt))
    .map((op) => {
      const photos = media.filter((m) => m.after === op.id).length
      return {
        key: `q:${op.id}`,
        title: textOf(op.body?.title) || 'Maintenance request',
        detail:
          op.state === 'refused'
            ? (op.error?.message ?? 'The estate office did not accept this request.')
            : `Saved on this phone ${stamp(op.capturedAt)}${photos > 0 ? ` · ${plural(photos, 'photo')} to follow` : ''}`,
        queue: op.state,
        status: null,
        ticketId: null,
        opId: op.id,
      }
    })

  const listed = new Set(input.items.map((item) => String(item.id)))
  const answered = requests.flatMap((op) => (op.state === 'confirmed' && isTicket(op.result) && !listed.has(String(op.result.id)) ? [op.result] : []))

  const filed: RecentRow[] = [...answered, ...input.items]
    .slice()
    .sort((x, y) => timeOf(y.reported_at) - timeOf(x.reported_at))
    .slice(0, RECENT_MAX)
    .map((item) => ({
      key: `t:${item.id}`,
      title: item.title,
      detail: `#${String(item.number).replace(/^#/, '')} · ${dateShort(item.reported_at)}`,
      queue: null,
      status: item.status,
      ticketId: String(item.id),
      opId: null,
    }))

  return [...unsent, ...filed]
}

/**
 * A refusal the resident has READ may leave the queue (nothing else ever takes it out: it would sit on
 * top of "Your requests" for good). What to dismiss, in order — the photos held behind the request
 * first, then the request — or null when it is not safe yet: while a photo of it is still waiting its
 * turn, NOTHING may go. A photo whose request has left the queue can never find the ticket it belongs
 * to, and the queue stops at an act it cannot address — every later act on this phone would wait behind it.
 * Only a request the server REFUSED is ever cleared: one that is waiting, or was accepted, never is.
 */
export function refusalToClear(opId: string, ops: readonly QueuedOp[]): string[] | null {
  const request = ops.find((op) => op.id === opId && op.endpoint === 'tickets.store')
  if (!request || request.state !== 'refused') return null
  const photos = ops.filter((op) => op.after === opId)
  if (photos.some((op) => op.state === 'pending' || op.state === 'syncing')) return null
  return [...photos.map((op) => op.id), opId]
}

// ───────────────────────────────────────────────────────────── finding a ticket

/** Structurally a ticket — for an answer the outbox kept, which the type system has never seen. */
export function isTicket(value: unknown): value is Ticket {
  if (typeof value !== 'object' || value === null) return false
  const v = value as Record<string, unknown>
  return (typeof v.id === 'number' || typeof v.id === 'string') && typeof v.title === 'string' && typeof v.status === 'string' && typeof v.reported_at === 'string' && Array.isArray(v.timeline)
}

/**
 * The ticket with this id: from the list the phone holds, or — between "sent" and "listed" — from the
 * server's own answer to the request this phone queued. Never drawn from anything the server did not say.
 */
/**
 * A SECURITY REPORT IS NOT A MAINTENANCE REQUEST — though the server files both as tickets.
 *
 * The Report-incident screens (features/incidents) close every description they write with a block headed
 * "Report details:" that always carries "- Written on this phone: <stamp>". That block is the marker: a ticket
 * that has it belongs to "Your reports" and is left out of "Your requests" — never both, never neither. The
 * expression is the same one features/incidents/incidents.ts uses; it is COPIED, not imported (features do not
 * reach into each other), and both sides test it against the other's descriptions.
 */
export const INCIDENT_MARKER = /(?:^|\n)Report details:\n(?:- [^\n]*\n)*- Written on this phone: \S/

export const isIncidentReport = (ticket: { description?: string | null }): boolean => INCIDENT_MARKER.test((ticket.description ?? '').replace(/\r\n/g, '\n'))

/** The household's tickets that are maintenance requests. */
export const requestsOnly = <T extends { description?: string | null }>(items: readonly T[]): T[] => items.filter((item) => !isIncidentReport(item))

export function findTicket(id: string | undefined, items: readonly Ticket[], answers: readonly unknown[]): Ticket | null {
  if (!id) return null
  return items.find((item) => String(item.id) === id) ?? answers.filter(isTicket).find((item) => String(item.id) === id) ?? null
}
