/**
 * The incidents feature's pure logic — no React, no UI kit, tested under Jest.
 *
 * THE RESIDENT API HAS NO INCIDENT ENDPOINT. A resident's non-emergency security report is a TICKET
 * with category `security` (tickets.store): it lands in the estate office's queue — not with the
 * police, and not on a dispatcher's panel. Every sentence here is written to that truth.
 *
 *   reportSchema        the form board resident-app-14 draws (M42 §5 #15): a type, what was seen or heard,
 *                       and — only if the resident changes it — the place, in words (≤ 120, the server's own
 *                       limit). A queued report's refusal arrives later with a sentence and no per-field
 *                       errors, so the form mirrors the server exactly.
 *   reportTitle()       THE TITLE THE SERVER REQUIRES (4–140). The design asks for none, so the app writes it: the
 *                       chip is the category and the title is THE INCIDENT (M47 §12.4) — what the resident said,
 *                       then where ("Resident's words + place", the user, 7 October), as a request's title is.
 *   incidentTitle()     the title a screen shows: a report filed before M47 was titled with its type, which only
 *                       repeats the chip — that one is read from the resident's own words instead.
 *   composeDescription  the board draws a TYPE of incident; the server has no field for it, so it is written
 *                       INTO the description as a plain line under "Report details:" — with the moment the
 *                       report was written on the phone, because a report queued with no signal gets the
 *                       server's clock on arrival.
 *   readDescription     the same lines read back, so the status screen can show the type as the board's tag
 *                       (it still reads the "When:" line a report filed by an earlier build carries).
 *   isIncidentReport    THE MARKER: that closing block is also how a report this feature filed is told from
 *                       a maintenance request — the household's tickets come back as one list, and the
 *                       maintenance form offers the server's `security` category too. Category is NOT the test.
 *   reportBody()        the fields tickets.store takes, and only those. `category` is always `security`.
 *   stageRows()         the server's timeline laid on board resident-app-27's four-stage spine.
 *   boardSteps() …      the same spine and its updates in the board's own words and format ("Received by security ·
 *                       9:13 AM · Dispatcher Renae Cross", "Reported Sep 4, 9:12 AM").
 *
 * WHERE, as real fields: the spot the phone was on when Report incident opened (location.ts), and the place in
 * the resident's words if they changed it. Left unsaid, the server files the report at their unit.
 *
 * A REPORT IS NOT AN ALERT (the user, 7 October). It goes to the estate office's queue and nowhere else:
 * `happening_now` — which would tell the guards on duty — is never sent. "Happening now" is the Panic button.
 *
 * What the server does NOT have, and so is never sent or modelled: an anonymous flag, an incident type,
 * an occurred-at time, a way to edit, delete, comment on or close a report. The design's "Keep my name
 * private from other residents" switch is the screen's own: no resident-facing answer carries a reporter's
 * name (useIncidentReport.ts says what was checked), so nothing about it is sent.
 */

// From the lib entry, not the package root: this file is pure, and is tested without the UI kit loaded.
import { dateShort, dayTimeTag, sinceWords, siteParts, time12, timeCompact, timeTag, timeWords } from '@gs/core/lib'
import type { Api } from '@gs/core/api'
import { z } from 'zod'
// Pure too, and imports nothing of this feature: one way of writing a title for both kinds of ticket.
import { titleFrom } from '@/features/maintenance/tickets'

type ApiTicket = Api.TicketsIndexResponse['items'][number]
type ApiEntry = ApiTicket['timeline'][number]
/**
 * The generated `event` is inferred from the contract's examples; the server has more (reassigned, reopened, verified…),
 * so it is widened here. `by` and `vendor` are optional: a server from before M47 sends neither.
 */
export type TimelineEntry = Omit<ApiEntry, 'event' | 'by'> & { event: string; by?: ApiEntry['by'] }
export type Incident = Omit<ApiTicket, 'timeline' | 'vendor'> & { timeline: readonly TimelineEntry[]; vendor?: ApiTicket['vendor'] }
export type Status = Incident['status']
export type Priority = NonNullable<Api.TicketsStoreRequest['priority']>

/** The one category a resident's security report is filed under. */
export const SECURITY = 'security' as const

/** Police, Jamaica. A phone call: it needs no data connection and no session. */
export const EMERGENCY_NUMBER = '119'

/** One outbox `kind` for this feature's acts, so "Your reports" never shows a maintenance request's queue row (and the reverse). */
export const INCIDENT_KIND = 'incident'

// ───────────────────────────────────────────────────────────── what the form offers

/** Board resident-app-14's four chips, exactly. Not a server field: written into the description. */
export const INCIDENT_TYPES = [
  { value: 'suspicious', label: 'Suspicious activity' },
  { value: 'theft', label: 'Theft' },
  { value: 'noise', label: 'Noise' },
  { value: 'trespass', label: 'Trespass' },
] as const

export type IncidentTypeId = (typeof INCIDENT_TYPES)[number]['value']
export const isIncidentType = (value: unknown): value is IncidentTypeId => INCIDENT_TYPES.some((t) => t.value === value)
export const typeLabel = (value: string): string | null => INCIDENT_TYPES.find((t) => t.value === value)?.label ?? null

// ───────────────────────────────────────────────────────────── the form

export const TITLE_MIN = 4
export const TITLE_MAX = 140
export const LOCATION_MAX = 120
/** The server takes 2000 characters; the report details are appended inside that, so the typed part stops short. */
export const SERVER_DESCRIPTION_MAX = 2000
export const DESCRIPTION_MAX = 1700

const oneLine = (text: string, max: number) => text.trim().replace(/\s+/g, ' ').slice(0, max)

/**
 * What the board asks, and only that. A type is always chosen — the board draws its first chip on, and so does
 * the form — so the one thing the resident can leave out is what they saw or heard, which the office cannot act
 * without (M18C). The place is optional: left unsaid, the server files the report at the resident's unit.
 */
export const reportSchema = z.object({
  type: z.string().refine((value) => isIncidentType(value), 'Choose what kind of incident it is.'),
  description: z.string().trim().min(3, 'Say what you saw or heard: tap here to type it, or tap the microphone.').max(DESCRIPTION_MAX, `Keep the details to ${DESCRIPTION_MAX} characters or fewer.`),
  place: z.string().trim().max(LOCATION_MAX, `Keep the place to ${LOCATION_MAX} characters or fewer.`),
})

export type ReportForm = z.input<typeof reportSchema>
export type ReportField = keyof ReportForm

/** In the order the screen draws them — the first message is the one the screen shows. */
export const REPORT_FIELDS: readonly ReportField[] = ['type', 'description', 'place']

/** The board's first chip is on when the screen opens. */
export const EMPTY_REPORT: ReportForm = { type: INCIDENT_TYPES[0].value, description: '', place: '' }

/**
 * THE TITLE tickets.store REQUIRES (4–140 characters), written by the app because the design asks for none. The chip
 * is the category and the title is the incident (M47 §12.4: "Suspicious activity" over "Grey pickup at Phase 2
 * playground"): the first sentence or clause of what the resident said, kept to about forty characters and cut between
 * words, then " — " and the place when they named one (titleFrom — a maintenance request's title is written the same
 * way). The type's label only when nothing was said, which the form does not allow.
 *
 *   'Grey pickup by the playground. Two men inside.' · 'Phase 2 playground'  →  "Grey pickup by the playground — Phase 2 playground"
 *   'Somebody took the bike from the porch' · ''                            →  "Somebody took the bike from the porch"
 */
export function reportTitle(values: Pick<ReportForm, 'description' | 'place' | 'type'>): string {
  return titleFrom(values.description, oneLine(values.place, LOCATION_MAX) || null, typeLabel(values.type) ?? 'Security report')
}

/**
 * The title a screen shows. A report filed before M47 was titled with its type — "Suspicious activity — Phase 2
 * playground" — which only repeats the chip above it (§12.4): that one is read from the resident's own words instead,
 * with the place it named. Any other title is the record's, shown as it is.
 */
export function incidentTitle(incident: Pick<Incident, 'title' | 'description'>): string {
  const title = incident.title.trim()
  const said = readDescription(incident.description)
  const tag = said.type
  // The server's old titles use a dash, this build's a colon (Law 3): either is the type and then the place.
  const joint = !tag ? null : [' — ', ': '].find((sep) => title.startsWith(`${tag}${sep}`)) ?? null
  if (!tag || !(title === tag || joint)) return title
  const words = said.text.replace(/^["“]+|["”]+$/g, '').trim()
  const place = title === tag || !joint ? null : title.slice(tag.length + joint.length).trim() || null
  return words ? titleFrom(words, place, title) : title
}

export const DETAILS_HEADING = 'Report details:'

/**
 * THE MARKER. Every report THIS feature files ends with the "Report details:" block, and that block
 * always carries this line (composeDescription writes it unconditionally, and never lets it be cut).
 * It is how a security report is told from a maintenance request — see isIncidentReport().
 */
export const WRITTEN_KEY = 'Written on this phone'

/**
 * "4 Sep 2026, 9:12 AM" — the estate's clock in the one form (M98: it read "Fri, Sep 4, 2026, 9:12 AM Jamaica time"),
 * with the year a description needs, since it is read long after it was written.
 */
export const writtenStamp = (at: Date | string): string => `${dateShort(at)}, ${timeWords(at)}`

/**
 * What the resident wrote, then the type they chose — as plain lines the office reads. `writtenAt` is the
 * moment Submit was pressed on THIS phone: with no signal the report reaches the server later, and the
 * server's `reported_at` is its arrival, not when it was seen.
 */
export function composeDescription(values: Pick<ReportForm, 'description' | 'type'>, writtenAt: Date): string {
  const lines: string[] = []
  const type = typeLabel(values.type)
  if (type) lines.push(`Type: ${type}`)
  // The stamp is read on the ESTATE's clock, never the phone's, and names no clock (M98: the office and the estate
  // keep one clock). Older builds wrote "(estate time)" or "Jamaica time" after it; readDescription still reads those.
  lines.push(`${WRITTEN_KEY}: ${writtenStamp(writtenAt)}`)
  const block = `${DETAILS_HEADING}\n${lines.map((line) => `- ${line}`).join('\n')}`
  // The details are never the part that is cut: the typed text gives way first.
  const text = values.description.trim().slice(0, Math.max(0, SERVER_DESCRIPTION_MAX - block.length - 2))
  return text ? `${text}\n\n${block}` : block
}

export type ReadDescription = { text: string; type: string | null; when: string | null; written: string | null }

/** The lines composeDescription() wrote, read back. A description this app did not write is returned whole, as text. */
export function readDescription(description: string | null | undefined): ReadDescription {
  const raw = (description ?? '').replace(/\r\n/g, '\n')
  const plain: ReadDescription = { text: raw.trim(), type: null, when: null, written: null }
  const at = raw.lastIndexOf(DETAILS_HEADING)
  if (at === -1 || (at > 0 && raw[at - 1] !== '\n')) return plain
  const lines = raw.slice(at + DETAILS_HEADING.length).split('\n').map((line) => line.trim()).filter(Boolean)
  if (lines.length === 0 || lines.some((line) => !line.startsWith('- '))) return plain
  const valueOf = (key: string): string | null => {
    const line = lines.find((l) => l.startsWith(`- ${key}: `))
    return line ? line.slice(key.length + 4).trim() || null : null
  }
  return { text: raw.slice(0, at).trim(), type: valueOf('Type'), when: valueOf('When'), written: valueOf(WRITTEN_KEY) }
}

/**
 * Is this ticket a security report THIS FEATURE filed? Decided by the marker, not by the category:
 *
 *   - tickets.index carries every ticket of the household. The maintenance form offers the server's own
 *     `security` category ("the gate arm is stuck"), so a category filter would list a maintenance request
 *     here as a security report — under "Received by the estate office / Being looked into", with the
 *     police buttons beside it. That is not what the resident filed.
 *   - the office may re-file a report under another category; it is still the report the resident sent
 *     from this screen, and it must not vanish from "Your reports" because of it.
 *
 * The marker is the block composeDescription() closes every report with: a line that is exactly
 * `Report details:`, then `- ` lines, one of which starts `- Written on this phone: `. It is ONE test on
 * the description (INCIDENT_MARKER, with \r\n read as \n) so that the maintenance side can apply the exact
 * mirror image — a ticket WITH the marker is not a maintenance request — and every ticket of the household
 * is listed in exactly one of the two places: never both, never neither. Words the office adds after the
 * block do not unmark it; if the office rewrites the block away, the ticket moves to "Your requests".
 */
export const INCIDENT_MARKER = /(?:^|\n)Report details:\n(?:- [^\n]*\n)*- Written on this phone: \S/

export const isIncidentReport = (ticket: Pick<Incident, 'description'>): boolean => INCIDENT_MARKER.test((ticket.description ?? '').replace(/\r\n/g, '\n'))

export type ReportBody = Omit<Api.TicketsStoreRequest, '__stamp'>

/** Where the resident was, if they attached it — two degrees and how wide the circle is (location.ts). */
export type ReportFix = { latitude: number; longitude: number; accuracyM: number | null }

/** Seven places is about a centimetre, and is what the server's column holds. Anything finer is noise. */
const degrees = (value: number): number => Math.round(value * 1e7) / 1e7

/**
 * Exactly the fields tickets.store takes. `category` is always `security`; an empty place is left out,
 * never sent as "" (the server then files the report at the resident's unit).
 *
 * WHERE — the ticket record carries lat and lng, so the pin is captured into it: the spot the phone was on when
 * the screen opened (location.ts). `fix` is null when there is none, and the report goes without it.
 *
 * NOT SENT, ON PURPOSE:
 *   happening_now  it would tell the guards on duty. A report goes to the estate office's queue only; for
 *                  "happening now" there is the Panic button (the user, 7 October).
 *   priority       the design asks nothing about seriousness, so none is claimed — the server's default (medium).
 */
export function reportBody(values: ReportForm, writtenAt: Date, fix: ReportFix | null = null): ReportBody {
  const location = oneLine(values.place, LOCATION_MAX)
  return {
    title: reportTitle(values),
    category: SECURITY,
    description: composeDescription(values, writtenAt),
    ...(location ? { location } : null),
    ...(fix ? { latitude: degrees(fix.latitude), longitude: degrees(fix.longitude), ...(fix.accuracyM === null ? null : { accuracy_m: fix.accuracyM }) } : null),
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

/** The server sets no count; this is the phone's own ceiling — four thumbs and the add tile fill the board's row. */
export const PHOTO_MAX = 4
/** On-device compression asked of the picker and of the shrinker. */
export const PHOTO_QUALITY = 0.6

export type Photo = { uri: string; mime: string | null; fileName: string | null }

const EXTENSION: Record<string, string> = { 'image/jpeg': 'jpg', 'image/jpg': 'jpg', 'image/png': 'png', 'image/heic': 'heic', 'image/heif': 'heif' }

/** tickets.media's body: multipart, one field, `file` — the generated endpoint picks that key and no other. The name says nothing about the resident. */
export function mediaBody(photo: Photo, index: number): { file: Api.UploadFile } {
  const fromUri = /\.(jpe?g|png|hei[cf])(?:$|[?#])/i.exec(photo.uri)?.[1]?.toLowerCase().replace('jpeg', 'jpg')
  const type = photo.mime && EXTENSION[photo.mime.toLowerCase()] ? photo.mime.toLowerCase() : fromUri ? (fromUri === 'jpg' ? 'image/jpeg' : `image/${fromUri}`) : 'image/jpeg'
  const extension = EXTENSION[type] ?? 'jpg'
  return { file: { uri: photo.uri, name: `report-photo-${index + 1}.${extension}`, type } }
}

// ───────────────────────────────────────────────────────────── status: the server's timeline on the board's spine

export const STATUS_LABEL: Record<Status, string> = {
  submitted: 'Submitted',
  acknowledged: 'Received',
  assigned: 'Being looked into',
  in_progress: 'Being looked into',
  completed: 'Resolved',
  verified: 'Resolved',
  cancelled: 'Closed',
}

/** A closed report takes no more work: resolved, or closed without action. */
export const isClosed = (status: Status): boolean => status === 'completed' || status === 'verified' || status === 'cancelled'

type Stage = { key: string; label: string; statuses: readonly Status[]; events: readonly string[] }

/**
 * Board resident-app-27's four stages. The board says "Received by security" and "Under investigation";
 * a ticket is received by the ESTATE OFFICE and then worked on — said as that.
 */
export const STAGES: readonly Stage[] = [
  { key: 'submitted', label: 'Submitted', statuses: ['submitted'], events: ['reported'] },
  { key: 'received', label: 'Received by the estate office', statuses: ['acknowledged'], events: ['acknowledged'] },
  { key: 'looking', label: 'Being looked into', statuses: ['assigned', 'in_progress'], events: ['assigned', 'reassigned', 'started', 'reopened'] },
  { key: 'resolved', label: 'Resolved', statuses: ['completed', 'verified'], events: ['resolved', 'verified'] },
]

const EVENT_LABEL: Record<string, string> = {
  reported: 'Submitted',
  acknowledged: 'Received by the estate office',
  assigned: 'Given to someone to handle',
  reassigned: 'Handed to someone else',
  priority_changed: 'Seriousness changed by the office',
  started: 'Being looked into',
  resolved: 'Resolved',
  reopened: 'Opened again',
  verified: 'Confirmed resolved',
  cancelled: 'Closed without action',
}

const sentenceCase = (text: string): string => {
  const words = text.replace(/[_-]+/g, ' ').trim()
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : ''
}

const DAY_MS = 86_400_000
const dayNumber = (input: Date | string): number => {
  const p = siteParts(input)
  return Math.floor(Date.UTC(p.year, p.month, p.day) / DAY_MS)
}

/** "4 Sep 2026, 9:12 AM" — on the site’s clock, in the one form (M98). */
export const stamp = (iso: string): string => dayTimeTag(iso)

/** A recent entry in words that belong to no clock — "20 minutes ago", "yesterday at 9:12 PM" — and an older one with its day (M19A §4.1). */
export const whenShort = (iso: string, nowDate: Date): string => sinceWords(iso, nowDate)

/** When a stage or an entry happened, the office's note on it, and who in the office did it — before any is written out. */
type Moment = { at: string | null; note: string | null; by: string | null }

/**
 * "Dispatcher Renae Cross" — the member of staff behind an entry, by role and name as board 27 names them (M47 §12.5);
 * the name alone where they hold no role. The household's own entries are theirs; an older server names nobody.
 */
export function staffOf(entry: Pick<TimelineEntry, 'by'> | undefined): string | null {
  const by = entry?.by
  if (!by || by.kind !== 'staff') return null
  return [by.role?.trim(), by.name?.trim()].filter((part): part is string => !!part).join(' ') || null
}

/**
 * Who wrote an update, as the box under "Updates" names them beside its time (M57 §8): the office's staff by role and
 * name, the household's own entry, a contractor by name. Nobody where the server named nobody (an older server).
 */
export function writerOf(entry: Pick<TimelineEntry, 'by'> | undefined): string | null {
  const by = entry?.by
  if (!by) return null
  if (by.kind === 'staff') return staffOf(entry) ?? 'The estate office'
  if (by.kind === 'resident') return 'Your household'
  return by.name?.trim() || 'The contractor'
}

const momentOf = (entry: TimelineEntry | undefined, fallbackIso: string | null): Moment => ({
  at: entry?.occurred_at ?? fallbackIso ?? null,
  note: entry?.note?.trim() || null,
  by: staffOf(entry),
})

const detailOf = ({ at, note }: Pick<Moment, 'at' | 'note'>, nowDate: Date): string | null => {
  if (!at) return note
  return note ? `${whenShort(at, nowDate)} · ${note}` : whenShort(at, nowDate)
}

export type StageRow = {
  key: string
  label: string
  /** done: a tick · current: where the report is now · todo: not yet · stopped: closed without action here */
  state: 'done' | 'current' | 'todo' | 'stopped'
  detail: string | null
}

export type UpdateRow = { key: string; text: string; when: string }

/** A stage as the server recorded it (stageMoments): its moment, not yet written out. */
export type StageMoment = Omit<StageRow, 'detail'> & Moment
/** An entry that fills no stage: what it says, when it happened, and who wrote it. */
export type UpdateMoment = { key: string; text: string; at: string; by: string | null }

/**
 * The spine, and whatever else the server recorded. A stage's line is the LATEST timeline entry that put
 * the report in that stage. Every other entry — a note the office added, a change of hands, an earlier
 * "resolved" before it was opened again — is returned as `updates`, oldest first, in the office's own words.
 */
export function stageRows(incident: Incident, nowDate: Date): { rows: StageRow[]; updates: UpdateRow[] } {
  const { rows, updates } = stageMoments(incident)
  return {
    rows: rows.map(({ key, label, state, at, note }) => ({ key, label, state, detail: detailOf({ at, note }, nowDate) })),
    updates: updates.map(({ key, text, at }) => ({ key, text, when: whenShort(at, nowDate) })),
  }
}

/** stageRows' reading of the timeline, each moment kept apart so a board can write it in its own format. */
export function stageMoments(incident: Incident): { rows: StageMoment[]; updates: UpdateMoment[] } {
  const timeline = incident.timeline ?? []
  const used = new Set<number>()

  const lastIndex = (test: (entry: TimelineEntry) => boolean): number => {
    let found = -1
    timeline.forEach((entry, i) => {
      if (test(entry)) found = i
    })
    return found
  }

  const inStage = (stage: Stage, entry: TimelineEntry): boolean => stage.statuses.some((status) => status === entry.status)

  const entryFor = (stage: Stage): TimelineEntry | undefined => {
    // The stage's own event; else that event under another status; else an event this app has no name
    // for that put the report in this stage.
    const tests: ((entry: TimelineEntry) => boolean)[] = [
      (entry) => inStage(stage, entry) && stage.events.includes(entry.event),
      (entry) => stage.events.includes(entry.event),
      (entry) => inStage(stage, entry) && !(entry.event in EVENT_LABEL),
    ]
    for (const test of tests) {
      const index = lastIndex(test)
      if (index === -1) continue
      used.add(index)
      return timeline[index]
    }
    return undefined
  }

  const cancelled = incident.status === 'cancelled'
  // A report closed without action is drawn up to the furthest stage it reached.
  const reached = cancelled
    ? STAGES.reduce((furthest, stage, i) => (timeline.some((entry) => inStage(stage, entry) || stage.events.includes(entry.event)) ? i : furthest), 0)
    : Math.max(0, STAGES.findIndex((stage) => stage.statuses.includes(incident.status)))
  const all = !cancelled && reached === STAGES.length - 1

  const rows: StageMoment[] = []
  STAGES.forEach((stage, i) => {
    if (i > reached) {
      if (!cancelled) rows.push({ key: stage.key, label: stage.label, state: 'todo', at: null, note: null, by: null })
      return
    }
    const entry = entryFor(stage)
    const state = all || cancelled || i < reached ? 'done' : 'current'
    rows.push({ key: stage.key, label: stage.label, state, ...momentOf(entry, i === 0 ? incident.reported_at : null) })
  })

  if (cancelled) {
    const index = lastIndex((entry) => entry.status === 'cancelled' || entry.event === 'cancelled')
    if (index !== -1) used.add(index)
    rows.push({ key: 'cancelled', label: EVENT_LABEL.cancelled!, state: 'stopped', ...momentOf(index === -1 ? undefined : timeline[index], incident.closed_at) })
  }

  const updates: UpdateMoment[] = []
  timeline.forEach((entry, i) => {
    if (used.has(i)) return
    const label = EVENT_LABEL[entry.event]
    const note = entry.note?.trim() ?? ''
    // An entry this app has no name for is the office's own note: shown as written.
    const text = label ? (note ? `${label}: ${note}` : label) : note || sentenceCase(entry.event)
    updates.push({ key: `${i}:${entry.event}`, text, at: entry.occurred_at, by: writerOf(entry) })
  })

  return { rows, updates }
}

// ───────────────────────────────────────────────────────────── board resident-app-27's own words and format

/**
 * The board's stage titles, by stage. The office's queue is what receives and works on a report; the board says
 * "security" and "investigation" — drawn as the board draws them (the user, 7 October: the design wins), and listed as
 * a contradiction for the lead. stageRows keeps the plain words.
 */
export const BOARD_STAGE_TITLES: Readonly<Record<string, string>> = {
  submitted: 'Submitted',
  received: 'Received by security',
  looking: 'Under investigation',
  resolved: 'Resolved',
}

/**
 * "Incident #1042" — the board's word, and the number the estate office knows the report by (the office numbers every
 * ticket in one series; the board's "IR-0337" is a series the server does not have).
 */
export const incidentHeading = (incident: Pick<Incident, 'number'>): string => `Incident #${numberOf(incident)}`

/** "4 Sep 2026" — the date in the one form (M98; the board drew "Sep 4"), on the estate's clock. '' for none. */
export const monthDay = (iso: string): string => dateShort(iso)

/** "4 Sep 2026, 9:12 AM" — the stamp under the title, on the estate's clock. */
export const boardStamp = (iso: string): string => {
  const day = monthDay(iso)
  return day ? `${day}, ${time12(iso)}` : ''
}

/**
 * "9:13 AM" — a moment on the day the report was made, as the board writes its stages and updates (the line under the
 * title carries the date) — or "5 Sep 2026, 10:00 AM" on any later day.
 */
export const dayTime = (iso: string, reportedAt: string): string => (dayNumber(iso) === dayNumber(reportedAt) ? time12(iso) : boardStamp(iso))

/** A stage as the board's timeline draws it: its title and, once reached, its line. */
export type BoardStep = { key: string; title: string; state: StageRow['state']; line: string | null }

/** The stage that names who took the report in — the board names nobody on any other (M47 §12.5). */
const NAMED_STAGE = 'received'

/**
 * The board's four stages: "Submitted · 9:12 AM", "Received by security · 9:13 AM · Dispatcher Renae Cross" — the time,
 * and the office's note or, on "Received" with none, who took it in, by role and name. The stage the report is at now
 * says what is happening ("Under investigation · Patrol dispatched to the area"), or its time (and who) where the office
 * left no note. A stage not reached yet is its title alone.
 */
export function boardSteps(incident: Incident): BoardStep[] {
  return stageMoments(incident).rows.map(({ key, label, state, at, note, by: staff }) => {
    const by = key === NAMED_STAGE ? staff : null
    const when = at ? dayTime(at, incident.reported_at) || null : null
    const said = (...parts: (string | null)[]): string | null => parts.filter((part): part is string => !!part).join(' · ') || null
    const line = state === 'todo' ? null : state === 'current' ? (note ?? said(when, by)) : said(when, note ?? by)
    return { key, title: BOARD_STAGE_TITLES[key] ?? label, state, line }
  })
}

/** The board's "Updates": every entry that fills no stage, in the office's words, with its time ("9:16 AM") and who wrote it (M57 §8). */
export function boardUpdates(incident: Incident): { key: string; text: string; when: string }[] {
  return stageMoments(incident).updates.map(({ key, text, at, by }) => ({ key, text, when: by ? `${dayTime(at, incident.reported_at)} · ${by}` : dayTime(at, incident.reported_at) }))
}

/** Said inside the Updates box while it holds none (M57 §8): the report arrived, and where its news will appear. */
export const NO_UPDATES = ['Your report was sent.', 'The estate office will post updates here.'] as const

// ───────────────────────────────────────────────────────────── who is handling it, and when

const dayWord = (iso: string, nowDate: Date): string => {
  const days = dayNumber(iso) - dayNumber(nowDate)
  return days === 0 ? 'today' : days === 1 ? 'tomorrow' : days === -1 ? 'yesterday' : dateShort(iso)
}

/**
 * "today, 2:00–4:00 PM" · "tomorrow, 11:30 AM – 1:00 PM" · "9 Sep 2026, from 9:00 AM" — or null when the office gave no
 * window. The estate's clock, no tag (M98).
 */
export function etaWindow(startsAt: string | null, endsAt: string | null, nowDate: Date): string | null {
  const first = startsAt ?? endsAt
  if (!first) return null
  const day = dayWord(first, nowDate)
  if (!startsAt) return `${day}, by ${timeTag(first)}`
  if (!endsAt) return `${day}, from ${timeTag(startsAt)}`
  if (dayNumber(startsAt) !== dayNumber(endsAt)) return `${day}, ${time12(startsAt)} – ${dayWord(endsAt, nowDate)}, ${timeTag(endsAt)}`
  const sameHalf = siteParts(startsAt).hour < 12 === siteParts(endsAt).hour < 12
  return sameHalf ? `${day}, ${timeCompact(startsAt)}–${timeTag(endsAt)}` : `${day}, ${time12(startsAt)} – ${timeTag(endsAt)}`
}

/**
 * What the server gives a resident about who has the report: a name and a window — no phone number, no rank. The window
 * is the estate's clock; since M98 it carries no tag (a phone on another zone gets its own clock beside it, M19A §4.3).
 */
export function handlerOf(incident: Incident, nowDate: Date): { name: string; detail: string | null } | null {
  const name = incident.technician_name?.trim() ?? ''
  const eta = isClosed(incident.status) ? null : etaWindow(incident.eta_starts_at, incident.eta_ends_at, nowDate)
  if (!name && !eta) return null
  return { name: name || 'Someone from the estate', detail: eta ? `Expected ${eta}` : null }
}

export const numberOf = (incident: Pick<Incident, 'number'>): string => String(incident.number).replace(/^#/, '')
export const reportHeading = (incident: Pick<Incident, 'number'>): string => `Report #${numberOf(incident)}`

// ───────────────────────────────────────────────────────────── "Your reports": the server's list + what this phone has not sent

/** What this file needs of an outbox record (structurally the outbox's own `OutboxOp`). */
export type QueuedOp = {
  id: string
  endpoint: string
  state: 'pending' | 'syncing' | 'confirmed' | 'refused'
  capturedAt: string
  after: string | null
  body: Record<string, unknown> | null
  error: { message: string } | null
  result: unknown
}

export type RecentRow = {
  key: string
  title: string
  detail: string
  /** A report still on this phone: its outbox state. Null once it is the server's. */
  queue: 'pending' | 'syncing' | 'refused' | null
  /** The server's status, once it has one. */
  status: Status | null
  /** Opens /incident/[id]. A report the server has not taken has no id and opens nothing. */
  incidentId: string | null
  /** The outbox act behind a row that is still on this phone — what a read refusal is cleared by. Null once it is the server's. */
  opId: string | null
}

export const RECENT_MAX = 5
export const NOT_ACCEPTED = 'The estate office did not accept this report.'

const textOf = (value: unknown): string => (typeof value === 'string' ? value : '')
const timeOf = (iso: string): number => new Date(iso).getTime()
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`

/** Structurally a ticket — for an answer the outbox kept, which the type system has never seen. */
export function isTicket(value: unknown): value is Incident {
  if (typeof value !== 'object' || value === null) return false
  const v = value as Record<string, unknown>
  return (typeof v.id === 'number' || typeof v.id === 'string') && typeof v.title === 'string' && typeof v.status === 'string' && typeof v.reported_at === 'string' && Array.isArray(v.timeline)
}

/** tickets.index carries every kind of ticket the household has; only the ones this feature filed (the marker) are reports here. */
export const reportsOnly = (items: readonly Incident[]): Incident[] => items.filter(isIncidentReport)

function photosOf(incidentId: string, ops: readonly QueuedOp[]): QueuedOp[] {
  const parents = new Set(ops.filter((op) => op.endpoint === 'tickets.store' && isTicket(op.result) && String(op.result.id) === incidentId).map((op) => op.id))
  return ops.filter((op) => op.endpoint === 'tickets.media' && op.after !== null && parents.has(op.after))
}

/** Photos of this report that are still on the phone, waiting their turn behind it. */
export function photosWaiting(incidentId: string, ops: readonly QueuedOp[]): number {
  return photosOf(incidentId, ops).filter((op) => op.state === 'pending' || op.state === 'syncing').length
}

/**
 * Photos of this report the server ANSWERED no to (too large, a type it does not take) — or that were gone
 * from the phone when their turn came. The report itself stands; it is said on the report's own page, so
 * nobody believes the office has a picture it does not have.
 */
export function photosRefused(incidentId: string, ops: readonly QueuedOp[]): number {
  return photosOf(incidentId, ops).filter((op) => op.state === 'refused').length
}

/**
 *   waiting / being sent  → on top, said to be on this phone — never "submitted"
 *   refused               → with the server's sentence
 *   confirmed             → the server's now. Until the refreshed list carries it, it is drawn from the
 *                           server's own answer, so a report never vanishes between "sent" and "listed".
 * Newest first. Never drawn twice. The server's part stops at RECENT_MAX. `ops` are this feature's own
 * (kind `incident`) — an answer to one of them IS a report, whatever it reads like; `items` may be the
 * whole household list — only the ones carrying this feature's marker are used.
 */
export function recentRows(input: { items: readonly Incident[]; ops: readonly QueuedOp[] }): RecentRow[] {
  const reports = input.ops.filter((op) => op.endpoint === 'tickets.store')
  const media = input.ops.filter((op) => op.endpoint === 'tickets.media' && (op.state === 'pending' || op.state === 'syncing'))

  const unsent: RecentRow[] = reports
    .filter((op): op is QueuedOp & { state: 'pending' | 'syncing' | 'refused' } => op.state !== 'confirmed')
    .slice()
    .sort((x, y) => timeOf(y.capturedAt) - timeOf(x.capturedAt))
    .map((op) => {
      const photos = media.filter((m) => m.after === op.id).length
      return {
        key: `q:${op.id}`,
        title: textOf(op.body?.title) || 'Security report',
        detail: op.state === 'refused' ? (op.error?.message ?? NOT_ACCEPTED) : `Saved on this phone ${stamp(op.capturedAt)}${photos > 0 ? ` · ${plural(photos, 'photo')} to follow` : ''}`,
        queue: op.state,
        status: null,
        incidentId: null,
        opId: op.id,
      }
    })

  const listed = reportsOnly(input.items)
  // Once the list carries a ticket, the list's copy is the truth — the kept answer is only for the gap before it.
  const known = new Set(input.items.map((item) => String(item.id)))
  const answered = reports.flatMap((op) => (op.state === 'confirmed' && isTicket(op.result) && !known.has(String(op.result.id)) ? [op.result] : []))

  const filed: RecentRow[] = [...answered, ...listed]
    .slice()
    .sort((x, y) => timeOf(y.reported_at) - timeOf(x.reported_at))
    .slice(0, RECENT_MAX)
    .map((item) => ({
      key: `t:${item.id}`,
      title: incidentTitle(item),
      detail: `#${numberOf(item)} · ${dateShort(item.reported_at)}`,
      queue: null,
      status: item.status,
      incidentId: String(item.id),
      opId: null,
    }))

  return [...unsent, ...filed]
}

/**
 * A refusal the resident has READ may leave the queue (nothing else ever takes it out: it would head
 * "Your reports" for good, above the corrected report that replaced it). This is not deleting a record:
 * the server said no, so nothing was ever filed. What to dismiss, in order — the photos held behind the
 * report first, then the report — or null when it is not safe yet: while a photo of it is still waiting
 * its turn, NOTHING may go (a photo whose report has left the queue can never find its ticket, and the
 * queue stops at an act it cannot address). Only a report the server REFUSED is ever cleared: one that is
 * waiting, or was accepted, never is.
 */
export function refusalToClear(opId: string, ops: readonly QueuedOp[]): string[] | null {
  const report = ops.find((op) => op.id === opId && op.endpoint === 'tickets.store')
  if (!report || report.state !== 'refused') return null
  const photos = ops.filter((op) => op.after === opId)
  if (photos.some((op) => op.state === 'pending' || op.state === 'syncing')) return null
  return [...photos.map((op) => op.id), opId]
}

/**
 * The security report with this id: from the list the phone holds, or — between "sent" and "listed" —
 * from the server's own answer to a report THIS FEATURE queued (`answers`). A ticket this feature did not
 * file (no marker) is NOT an incident and is not opened here, whatever its category. Never drawn from
 * anything the server did not say.
 */
export function findIncident(id: string | undefined, items: readonly Incident[], answers: readonly unknown[]): Incident | null {
  if (!id) return null
  const listed = items.find((item) => String(item.id) === id)
  if (listed) return isIncidentReport(listed) ? listed : null
  return answers.filter(isTicket).find((item) => String(item.id) === id) ?? null
}
