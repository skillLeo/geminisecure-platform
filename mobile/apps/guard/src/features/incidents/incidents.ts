/**
 * The incident feature's pure logic — no React, no UI kit, tested under Jest.
 *
 *   CATEGORIES        the board's chips. The server's `kind` is FREE TEXT (≤ 160), so a chip's words are
 *                     exactly what is filed — nothing is mapped onto a code list, because there is none.
 *   incidentSchema    the server's own limits: a kind · severity low|med|high · detail 20–5000 after trimming
 *                     · location ≤ 160. Validation here can only ever be as strict as the server, never stricter.
 *   reportBody()      the fields incidents.store takes, and only those. `occurred_at` is the moment of CAPTURE —
 *                     a report queued with no signal must not be dated by the hour it finally synced.
 *   mediaBody()       incidents.media is multipart with ONE field, `file` = { uri, name, type }.
 *   mergeReports()    the server's list + this phone's unsent reports → the rows My Reports draws.
 *
 * Records are append-only: nothing here edits or withdraws a report.
 */

// From the lib entry, not the package root: this file is pure, and is tested without the UI kit loaded.
import { dateShort, siteParts, time12 } from '@gs/core/lib'
import type { Api } from '@gs/core/api'
import { z } from 'zod'

// ───────────────────────────────────────────────────────────── categories

export type ReportIcon = 'qa-tile-3' | 'rp-icon'

export type Category = { id: string; label: string; icon: ReportIcon }

/** The first four are board guard-app-08's chips, in its order; the next two are kinds board 23 lists. */
export const CATEGORIES: readonly Category[] = [
  { id: 'suspicious', label: 'Suspicious activity', icon: 'qa-tile-3' },
  { id: 'break_in', label: 'Break-in', icon: 'qa-tile-3' },
  { id: 'fire', label: 'Fire', icon: 'qa-tile-3' },
  { id: 'equipment', label: 'Equipment fault', icon: 'rp-icon' },
  { id: 'trespass', label: 'Trespass', icon: 'qa-tile-3' },
  { id: 'noise', label: 'Noise complaint', icon: 'qa-tile-3' },
  { id: 'medical', label: 'Medical emergency', icon: 'qa-tile-3' },
  { id: 'other', label: 'Other', icon: 'qa-tile-3' },
]

export const categoryById = (id: string | null | undefined): Category | null => CATEGORIES.find((cat) => cat.id === id) ?? null

/** What is filed as `kind`: the chip's own words. */
export const kindOf = (categoryId: string): string => (categoryById(categoryId) ?? categoryById('other')!).label

const norm = (text: string) => text.trim().replace(/\s+/g, ' ').toLowerCase()

/** A report's glyph in the list. A kind this app did not file (the console logs its own) gets the warning sign. */
export function iconForKind(kind: string): ReportIcon {
  return CATEGORIES.find((cat) => norm(cat.label) === norm(kind))?.icon ?? 'qa-tile-3'
}

// ───────────────────────────────────────────────────────────── severity

export type Severity = Api.IncidentsStoreRequest['severity']

/** The server's values with the console's words for them (IncidentLog::SEVERITIES). */
export const SEVERITIES: readonly { value: Severity; label: string }[] = [
  { value: 'low', label: 'Low' },
  { value: 'med', label: 'Medium' },
  { value: 'high', label: 'High' },
]

export const isSeverity = (value: unknown): value is Severity => value === 'low' || value === 'med' || value === 'high'

// ───────────────────────────────────────────────────────────── the form

export const DETAIL_MIN = 20
export const DETAIL_MAX = 5000
export const LOCATION_MAX = 160

export const incidentSchema = z.object({
  category: z.string().refine((id) => categoryById(id) !== null, 'Choose what kind of incident this is.'),
  severity: z.string().refine(isSeverity, 'Choose how serious this is: low, medium or high.'),
  detail: z
    .string()
    .trim()
    .min(DETAIL_MIN, `Describe what happened in at least ${DETAIL_MIN} characters.`)
    .max(DETAIL_MAX, `Keep the report to ${DETAIL_MAX} characters or fewer.`),
  // Nothing is optional (M18C): where it happened, in the officer's own words.
  location: z.string().trim().min(2, 'Say where it happened: for example "back wall by Lot 63".').max(LOCATION_MAX, `Keep the location to ${LOCATION_MAX} characters or fewer.`),
})

export type IncidentForm = z.input<typeof incidentSchema>
export type IncidentField = keyof IncidentForm

export const INCIDENT_FIELDS: readonly IncidentField[] = ['category', 'severity', 'detail', 'location']

/** The live count under the report: the server counts the text after trimming, so this does too. */
export function detailCount(detail: string): { count: number; ok: boolean; label: string } {
  const count = detail.trim().length
  if (count > DETAIL_MAX) return { count, ok: false, label: `${count} of ${DETAIL_MAX} characters: too long` }
  if (count >= DETAIL_MIN) return { count, ok: true, label: `${count} characters` }
  const short = DETAIL_MIN - count
  return { count, ok: false, label: `${count} of ${DETAIL_MIN} characters minimum: ${short} more` }
}

/** Dictated words are added to what is already written — dictation never replaces the guard's typing. */
export function appendTranscript(detail: string, heard: string): string {
  const words = heard.trim().replace(/\s+/g, ' ')
  if (!words) return detail
  const base = detail.replace(/\s+$/, '')
  return (base ? `${base} ${words}` : words).slice(0, DETAIL_MAX)
}

export type Fix = { latitude: number; longitude: number; accuracyM: number | null }

const inRange = (n: number, lo: number, hi: number) => Number.isFinite(n) && n >= lo && n <= hi

export type ReportBody = Omit<Api.IncidentsStoreRequest, '__stamp'>

/** Exactly the fields incidents.store takes. The position is left out when there is none, never sent as "". */
export function reportBody(input: { values: IncidentForm; fix: Fix | null; occurredAt: string }): ReportBody {
  const severity = input.values.severity
  if (!isSeverity(severity)) throw new Error('severity not chosen')
  const location = input.values.location.trim().slice(0, LOCATION_MAX)
  const fix = input.fix && inRange(input.fix.latitude, -90, 90) && inRange(input.fix.longitude, -180, 180) ? input.fix : null
  return {
    kind: kindOf(input.values.category),
    severity,
    detail: input.values.detail.trim(),
    location,
    ...(fix ? { latitude: fix.latitude, longitude: fix.longitude } : null),
    occurred_at: input.occurredAt,
  }
}

// ───────────────────────────────────────────────────────────── media

/** The server sets no count; this is the phone's own ceiling — five fill the board's photo row. */
export const PHOTO_MAX = 5
/** On-device compression asked of the picker. */
export const PHOTO_QUALITY = 0.6

/** The board's own tile (guard-app-08): five of them and their four gaps fill its 397-point row. */
export const PHOTO_TILE = 58

/** What the strip cannot use: the body's two side paddings and the four gaps between five tiles. */
const PHOTO_STRIP_GUTTERS = 40 + (PHOTO_MAX - 1) * 9

/**
 * ONE ROW OF FIVE, ON EVERY PHONE.
 *
 * Five 58-point tiles and their gaps need 366 points, and the board leaves 357 of a 397-point
 * screen — so the fifth tile (the "+", or the fifth photo) wrapped onto a line of its own, reading
 * as though there was no more room to add. A 360-point phone leaves 320. The tile is measured
 * instead: the board's 58 where there is room, smaller where there is not, never below 44, where a
 * thumbnail stops being a picture of anything.
 */
export function photoTileWidth(screenWidth: number): number {
  if (!Number.isFinite(screenWidth) || screenWidth <= 0) return PHOTO_TILE
  return Math.max(44, Math.min(PHOTO_TILE, Math.floor((screenWidth - PHOTO_STRIP_GUTTERS) / PHOTO_MAX)))
}

export type Photo = { uri: string; mime: string | null; fileName: string | null }

const EXTENSION: Record<string, string> = { 'image/jpeg': 'jpg', 'image/jpg': 'jpg', 'image/png': 'png', 'image/heic': 'heic', 'image/heif': 'heif' }

/** incidents.media's body: multipart, one field, `file` — the generated endpoint picks that key and no other. */
export function mediaBody(photo: Photo, index: number): { file: Api.UploadFile } {
  const fromUri = /\.(jpe?g|png|hei[cf])(?:$|[?#])/i.exec(photo.uri)?.[1]?.toLowerCase().replace('jpeg', 'jpg')
  const type = photo.mime && EXTENSION[photo.mime.toLowerCase()] ? photo.mime.toLowerCase() : fromUri ? (fromUri === 'jpg' ? 'image/jpeg' : `image/${fromUri}`) : 'image/jpeg'
  const extension = EXTENSION[type] ?? 'jpg'
  return { file: { uri: photo.uri, name: `incident-photo-${index + 1}.${extension}`, type } }
}

// ───────────────────────────────────────────────────────────── My Reports

export type ReportItem = Api.IncidentsMeResponse['items'][number]

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

export type ChipTone = 'amber-500' | 'green-400' | 'red-500'

export type ReportRow = {
  key: string
  /** Set on the first row of a section: the board's small caps heading above the card. */
  heading: string | null
  first: boolean
  last: boolean
  icon: ReportIcon
  title: string
  chip: { label: string; tone: ChipTone }
  detail: string
  meta: string
  /** A refusal's sentence, or photos still waiting to upload. */
  note: { text: string; tone: ChipTone } | null
  /** The office's outcome, once resolved. */
  outcome: string | null
  /** This phone's unsent report: the row opens the sync queue. A server row opens nothing (append-only). */
  queued: boolean
}

/** The console's own words for the server's two statuses (IncidentLog: 'Open' / 'Resolved'), in the board's chips. */
export const STATUS_CHIP: Record<ReportItem['status'], { label: string; tone: ChipTone }> = {
  open: { label: 'Open', tone: 'amber-500' },
  resolved: { label: 'Resolved', tone: 'green-400' },
}

const QUEUE_CHIP = {
  pending: { label: 'Pending sync', tone: 'amber-500' },
  syncing: { label: 'Syncing', tone: 'amber-500' },
  refused: { label: 'Not accepted', tone: 'red-500' },
} as const

const DAY_MS = 86_400_000
const dayNumber = (input: Date | string): number => {
  const p = siteParts(input)
  return Math.floor(Date.UTC(p.year, p.month, p.day) / DAY_MS)
}

/** "Today, 9:12 AM" · "Yesterday, 6:40 PM" · "Sep 2, 6:40 PM" — on the site’s clock. */
export function whenLabel(iso: string, nowDate: Date): string {
  const days = dayNumber(nowDate) - dayNumber(iso)
  return `${days === 0 ? 'Today' : days === 1 ? 'Yesterday' : dateShort(iso)}, ${time12(iso)}`
}

/** The board writes a report's number as #IR-0337: the server's id, four digits wide. */
export const reference = (id: number | string): string => `#IR-${String(id).padStart(4, '0')}`

/** "This week" is today and the six days before it, in Jamaica. */
export const isThisWeek = (iso: string, nowDate: Date): boolean => dayNumber(nowDate) - dayNumber(iso) < 7

const text = (value: unknown): string => (typeof value === 'string' ? value : '')
const time = (iso: string): number => new Date(iso).getTime()
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`

function isItem(value: unknown): value is ReportItem {
  if (typeof value !== 'object' || value === null) return false
  const v = value as Record<string, unknown>
  return (typeof v.id === 'number' || typeof v.id === 'string') && typeof v.kind === 'string' && typeof v.detail === 'string' && typeof v.occurred_at === 'string' && (v.status === 'open' || v.status === 'resolved')
}

function section(heading: string, rows: Omit<ReportRow, 'heading' | 'first' | 'last'>[]): ReportRow[] {
  return rows.map((row, i) => ({ ...row, heading: i === 0 ? heading : null, first: i === 0, last: i === rows.length - 1 }))
}

/**
 * The server's list plus this phone's own reports the server has not taken:
 *   waiting / being sent  → "Pending sync" / "Syncing", amber, on top
 *   refused               → "Not accepted", with the server's sentence
 *   confirmed             → it is the server's now. Until the refreshed list carries it, it is drawn from the
 *                           server's own answer, so a report never vanishes between "sent" and "listed".
 * Newest first inside each section. Never drawn twice.
 */
export function mergeReports(input: { items: readonly ReportItem[]; ops: readonly QueuedOp[]; nowDate: Date }): ReportRow[] {
  const reports = input.ops.filter((op) => op.endpoint === 'incidents.store')
  const media = input.ops.filter((op) => op.endpoint === 'incidents.media' && (op.state === 'pending' || op.state === 'syncing'))
  const photosWaitingOn = (opId: string) => media.filter((m) => m.after === opId).length

  const unsent = reports
    .filter((op): op is QueuedOp & { state: 'pending' | 'syncing' | 'refused' } => op.state !== 'confirmed')
    .slice()
    .sort((x, y) => time(y.capturedAt) - time(x.capturedAt))
    .map((op) => {
      const kind = text(op.body?.kind) || 'Incident report'
      const photos = photosWaitingOn(op.id)
      const refused = op.state === 'refused'
      return {
        key: `q:${op.id}`,
        icon: iconForKind(kind),
        title: kind,
        chip: QUEUE_CHIP[op.state],
        detail: text(op.body?.detail),
        meta: `${whenLabel(text(op.body?.occurred_at) || op.capturedAt, input.nowDate)} · ${refused ? 'not filed' : 'saved on this phone'}`,
        note: refused
          ? { text: op.error?.message ?? 'The server did not accept this report.', tone: 'red-500' as const }
          : photos > 0 ? { text: `${plural(photos, 'photo')} will upload after the report.`, tone: 'amber-500' as const } : null,
        outcome: null,
        queued: true,
      }
    })

  // Confirmed on this phone but not yet in the list the phone holds: the server's own answer stands in.
  const listed = new Set(input.items.map((item) => String(item.id)))
  const answered = reports.flatMap((op) => (op.state === 'confirmed' && isItem(op.result) && !listed.has(String(op.result.id)) ? [op.result] : []))
  const waitingById = new Map<string, number>()
  for (const op of reports) if (op.state === 'confirmed' && isItem(op.result)) waitingById.set(String(op.result.id), photosWaitingOn(op.id))

  const filed = [...answered, ...input.items]
    .slice()
    .sort((x, y) => time(y.occurred_at) - time(x.occurred_at))
    .map((item) => {
      const photos = waitingById.get(String(item.id)) ?? 0
      const outcome = item.status === 'resolved' ? text(item.resolution).trim() : ''
      return {
        recent: isThisWeek(item.occurred_at, input.nowDate),
        row: {
          key: `i:${item.id}`,
          icon: iconForKind(item.kind),
          title: item.kind,
          chip: STATUS_CHIP[item.status] ?? STATUS_CHIP.open,
          detail: item.detail,
          meta: `${whenLabel(item.occurred_at, input.nowDate)} · ${reference(item.id)}`,
          note: photos > 0 ? { text: `${plural(photos, 'photo')} still waiting to upload.`, tone: 'amber-500' as const } : null,
          outcome: outcome || null,
          queued: false,
        },
      }
    })

  return [
    ...section('On this phone', unsent),
    ...section('This week', filed.filter((f) => f.recent).map((f) => f.row)),
    ...section('Earlier', filed.filter((f) => !f.recent).map((f) => f.row)),
  ]
}
