/**
 * The notices' pure logic — no React, no UI kit — so it is unit-tested without a phone.
 *
 * WHAT THE SERVER SENDS about a notice: its `kind` (`general` | `urgent`), who it is for (`audience`:
 * "Whole estate" or the household's phase), a title, a body, a byline, when it was published, when
 * THIS resident read it — and how many HOUSEHOLDS have seen it out of how many it is
 * for, and the files the office put with it (a name, a type, a size and an id; the file itself is
 * fetched from `notices.attachment` with the phone's token — there is no address in the list).
 * It sends no topic ("Security", "Events") and no acknowledgement flag — so neither is built from
 * anything here. The chips filter by what is really there.
 */

import { dateShort, siteParts, sinceWords, time12, timeTag } from '@gs/core/lib'
import type { Api } from '@gs/core/api'

/** A file the office put with a notice. */
export type Attachment = { id: number | string; name: string; content_type: string; bytes: number }

/**
 * The server's notice. The three newest fields are OPTIONAL here whatever the generated type says: the list
 * on screen may be one an older build saved on this phone, which has none of them — so every reader below
 * treats them as possibly absent, and the compiler holds it to that.
 */
export type Notice = Omit<Api.NoticesIndexResponse['items'][number], 'seen_households' | 'audience_households' | 'attachments'> & {
  seen_households?: number
  audience_households?: number
  attachments?: readonly Attachment[]
}

/** A queued `notices.read`, as the outbox holds it. Declared here so this file stays free of the UI kit. */
export type ReadOp = {
  id: string
  endpoint: string
  params: Record<string, unknown>
  state: 'pending' | 'syncing' | 'confirmed' | 'refused'
  error: { message: string } | null
  result: unknown
}

/* ---- order ---------------------------------------------------------------------------------------- */

export function newestFirst(items: readonly Notice[]): Notice[] {
  // Two notices posted in the same second are separated by their id, newest first — the SERVER's own
  // tie-break (`published_at DESC, id DESC`). The list pages now, so without it a pair stamped together
  // could swap places depending on which page each arrived in, and the join would look unstable.
  return [...items].sort((p, q) => new Date(q.published_at).getTime() - new Date(p.published_at).getTime() || idOrder(q.id, p.id))
}

/** Ids may be numbers or strings; compare as numbers where both are, and as text otherwise. */
function idOrder(a: Notice['id'], b: Notice['id']): number {
  const x = Number(a)
  const y = Number(b)
  return Number.isFinite(x) && Number.isFinite(y) ? x - y : String(a).localeCompare(String(b))
}

/* ---- when ----------------------------------------------------------------------------------------- */

/** Whole JAMAICA calendar days between the two — "yesterday" is yesterday in Kingston, whatever the phone's zone. */
export function daysAgo(iso: string, at: Date): number {
  const p = siteParts(iso)
  const q = siteParts(at)
  return Math.round((Date.UTC(q.year, q.month, q.day) - Date.UTC(p.year, p.month, p.day)) / 86_400_000)
}

/** "21 Aug 2026": the one form, its year every time (M98). */
const dated = (iso: string) => dateShort(iso)

const capital = (text: string): string => (text ? `${text[0]!.toUpperCase()}${text.slice(1)}` : text)

/**
 * The list's line — a recent notice in words that belong to no clock (M19A §4.1): "2 hours ago" · "Yesterday at 3:40 PM"
 * · "3 days ago" · "21 Aug 2026" (M98's one form, no tag).
 */
export function postedShort(iso: string, at: Date): string {
  const days = daysAgo(iso, at)
  if (days <= 1) return capital(sinceWords(iso, at))
  return days < 7 ? `${days} days ago` : dated(iso)
}

/**
 * The list card's line (M42 §5 #11): "Today, 8:15 AM" · "Yesterday, 3:40 PM" · "2 days ago" · "3 days ago", and past a
 * week the date, "21 Aug 2026". A day and a time with a comma, as every date and time is written (M98; the design's
 * middot read as a second way). Days are the estate's calendar days.
 */
export function postedCard(iso: string, at: Date): string {
  const days = daysAgo(iso, at)
  if (days <= 0) return `Today, ${time12(iso)}`
  if (days === 1) return `Yesterday, ${time12(iso)}`
  return days < 7 ? `${days} days ago` : dated(iso)
}

/**
 * The notice's own byline time (board 36): "Today, 8:15 AM" · "Yesterday, 3:40 PM" — and before that the date and the
 * time in the one form, "21 Aug 2026, 8:15 AM" (M98). Days are the estate's calendar days.
 */
export function postedDetail(iso: string, at: Date): string {
  const days = daysAgo(iso, at)
  if (days <= 0) return `Today, ${time12(iso)}`
  if (days === 1) return `Yesterday, ${time12(iso)}`
  return `${dated(iso)}, ${time12(iso)}`
}

/** "2 hours ago" · "Yesterday at 3:40 PM" · "21 Aug 2026, 8:15 AM" (M19A §4.1, M98) — when a read was recorded. */
export function postedFull(iso: string, at: Date): string {
  const days = daysAgo(iso, at)
  return days <= 1 ? capital(sinceWords(iso, at)) : `${dated(iso)}, ${timeTag(iso)}`
}

/* ---- who ------------------------------------------------------------------------------------------ */

/** The server's own rule (Notice::byline): the role where one was recorded, the person otherwise — never both. */
export const bylineOf = (n: Pick<Notice, 'author_name' | 'posted_as_role'>): string => (n.posted_as_role?.trim() || n.author_name.trim() || 'The estate office')

export const initialsOf = (name: string): string => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('')

/* ---- what is drawn -------------------------------------------------------------------------------- */

export type Tag = { label: string; urgent: boolean }

/** The card's pills: "Urgent" when it is, then who it is for. */
export function tagsOf(n: Pick<Notice, 'kind' | 'audience'>): Tag[] {
  const audience = n.audience.trim()
  return [...(n.kind === 'urgent' ? [{ label: 'Urgent', urgent: true }] : []), ...(audience ? [{ label: audience, urgent: false }] : [])]
}

/** The detail's one pill: "Urgent · Whole estate". */
export const tagLine = (n: Pick<Notice, 'kind' | 'audience'>): string => tagsOf(n).map((t) => t.label).join(' · ')

/** A body is plain text: a blank line starts a paragraph. */
export function paragraphs(body: string): string[] {
  return body.replace(/\r\n?/g, '\n').split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean)
}

/** One run of text for the card's two lines. */
export const previewOf = (body: string): string => body.replace(/\s+/g, ' ').trim()

/* ---- seen by how many households ------------------------------------------------------------------- */

/** The server's count: households that have opened the notice, out of the households it is for. */
export type Seen = { seen: number; audience: number }

const wholeNumber = (value: unknown): number | null => {
  const n = typeof value === 'number' ? value : typeof value === 'string' && value.trim() !== '' ? Number(value) : Number.NaN
  return Number.isInteger(n) && n >= 0 ? n : null
}

/**
 * Read from whatever the server sent — a notice in the list, or its answer to a read. Null when it sent no
 * count (a list saved by an older build), when the notice is for nobody, or when the two figures cannot
 * both be true (more seen than there are): a figure this phone cannot stand behind is not printed.
 */
export function seenOf(from: { seen_households?: unknown; audience_households?: unknown }): Seen | null {
  const seen = wholeNumber(from.seen_households)
  const audience = wholeNumber(from.audience_households)
  return seen === null || audience === null || audience === 0 || seen > audience ? null : { seen, audience }
}

const grouped = (n: number): string => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',')

/** The board's words: "seen by 212 of 450 households". One household is "1 household". */
export const seenLine = (s: Seen): string => `seen by ${grouped(s.seen)} of ${grouped(s.audience)} household${s.audience === 1 ? '' : 's'}`

/* ---- attachments ---------------------------------------------------------------------------------- */

const isAttachment = (value: unknown): value is Attachment => {
  if (typeof value !== 'object' || value === null) return false
  const v = value as Record<string, unknown>
  return (typeof v.id === 'number' || typeof v.id === 'string') && typeof v.name === 'string' && v.name.trim() !== ''
}

/** The files with a notice — none when the server sent none (or a list saved before it sent any). */
export const attachmentsOf = (n: Pick<Notice, 'attachments'>): Attachment[] => (Array.isArray(n.attachments) ? n.attachments.filter(isAttachment) : [])

const KINDS: readonly { test: RegExp; label: string }[] = [
  { test: /^application\/pdf$/i, label: 'PDF' },
  { test: /^image\//i, label: 'Picture' },
  { test: /^application\/(msword|vnd\.openxmlformats-officedocument\.wordprocessingml\.document)$/i, label: 'Word document' },
]

const BY_EXTENSION: Record<string, string> = { pdf: 'PDF', jpg: 'Picture', jpeg: 'Picture', png: 'Picture', doc: 'Word document', docx: 'Word document' }

/** What kind of file it is, in a homeowner's words — from its type, else from its name, else plainly "File". */
export function kindOf(a: Pick<Attachment, 'content_type' | 'name'>): string {
  const byType = KINDS.find((k) => k.test.test((a.content_type ?? '').trim()))?.label
  if (byType) return byType
  const extension = /\.([A-Za-z0-9]{2,5})$/.exec(a.name.trim())?.[1]?.toLowerCase() ?? ''
  return BY_EXTENSION[extension] ?? 'File'
}

/** "240 KB" · "1.2 MB" — or null when the server gave no size worth printing. */
export function sizeLabel(bytes: unknown): string | null {
  const n = wholeNumber(bytes)
  if (n === null || n === 0) return null
  if (n < 1024) return `${n} bytes`
  if (n < 1024 * 1024) return `${Math.max(1, Math.round(n / 1024))} KB`
  const mb = n / (1024 * 1024)
  return `${mb >= 10 ? Math.round(mb) : Math.round(mb * 10) / 10} MB`
}

/** "PDF · 240 KB" */
export const attachmentDetail = (a: Attachment): string => [kindOf(a), sizeLabel(a.bytes)].filter(Boolean).join(' · ')

/** The API path the file is fetched from — with the phone's token, never a public address. */
export const attachmentPath = (noticeId: number | string, a: Pick<Attachment, 'id'>): string => `/notices/${encodeURIComponent(String(noticeId))}/attachments/${encodeURIComponent(String(a.id))}`

/** Where it is kept on the phone: one folder per attachment, so two files with one name never collide. */
export const attachmentFolder = (noticeId: number | string, a: Pick<Attachment, 'id'>): string => `notice-${String(noticeId)}-${String(a.id)}`

/**
 * Where opening a file stands, and the sentence for it.
 *   idle        not asked for
 *   fetching    being fetched from the estate
 *   opened      handed to the phone's viewer / share sheet
 *   unsupported this build cannot fetch files at all (the web preview)
 *   noViewer    the file is on the phone, but the phone has nothing to open or share it with
 *   offline     no connection, and the file is not on the phone yet
 *   refused     the server said no — its sentence
 *   failed      anything else
 */
export type OpenState =
  | { kind: 'idle' }
  | { kind: 'fetching' }
  | { kind: 'opened' }
  | { kind: 'unsupported' }
  | { kind: 'noViewer' }
  | { kind: 'offline' }
  | { kind: 'refused'; message: string }
  | { kind: 'failed' }

/** How a fetch that did not succeed is said. A 4xx is the server's answer; everything else is the connection. */
export function openStateOfFailure(error: { status: number | 'network' | 'timeout'; message: string }): OpenState {
  if (error.status === 'network' || error.status === 'timeout') return { kind: 'offline' }
  if (typeof error.status === 'number' && error.status >= 400 && error.status < 500) return { kind: 'refused', message: error.message }
  return { kind: 'failed' }
}

/** Every problem opening a file is an ordinary one, so amber (M98: red is an open panic's colour and nothing else). */
export function openSentence(state: OpenState): { text: string; tone: 'slate-600' | 'amber-700' } | null {
  switch (state.kind) {
    case 'idle':
    case 'opened':
      return null
    case 'fetching':
      return { text: 'Fetching the file from your estate…', tone: 'slate-600' }
    case 'unsupported':
      return { text: 'Files cannot be opened in this preview. Open the notice in the GeminiSecure app on your phone.', tone: 'amber-700' }
    case 'noViewer':
      return { text: 'The file is on this phone, but the phone has no app that can open or share it. Install a PDF or document viewer and try again.', tone: 'amber-700' }
    case 'offline':
      return { text: 'No connection: this file has not been fetched to this phone yet. Try again when you are back online.', tone: 'amber-700' }
    case 'refused':
      return { text: `Not available · ${state.message}`, tone: 'amber-700' }
    case 'failed':
      return { text: 'The file could not be fetched just now. Please try again in a moment.', tone: 'amber-700' }
  }
}

/* ---- read / unread -------------------------------------------------------------------------------- */

const noticeOf = (op: ReadOp): string | null => {
  const id = op.params.notice
  return typeof id === 'string' || typeof id === 'number' ? String(id) : null
}

export const readOpFor = (id: string, ops: readonly ReadOp[]): ReadOp | null => ops.find((op) => op.endpoint === 'notices.read' && noticeOf(op) === id) ?? null

/**
 * Where a notice's "read" stands — and who knows it.
 *   server     the server said so (`read_at`), or answered this phone's queued read
 *   pending    opened on this phone; the estate has NOT been told yet
 *   syncing    being sent now
 *   refused    the server answered no — its sentence is shown, and it is never sent again
 *   unread     not opened
 */
export type ReadState =
  | { kind: 'server'; at: string | null; seen: Seen | null }
  | { kind: 'pending' }
  | { kind: 'syncing' }
  | { kind: 'refused'; message: string }
  | { kind: 'unread' }

export function readStateOf(n: Pick<Notice, 'id' | 'read_at' | 'seen_households' | 'audience_households'>, ops: readonly ReadOp[]): ReadState {
  if (n.read_at) return { kind: 'server', at: n.read_at, seen: seenOf(n) }
  const op = readOpFor(String(n.id), ops)
  if (!op) return { kind: 'unread' }
  if (op.state === 'confirmed') {
    const answer = op.result && typeof op.result === 'object' ? (op.result as Record<string, unknown>) : {}
    // The server's answer to THIS read carries the count with this household in it — newer than the list that was opened.
    return { kind: 'server', at: typeof answer.read_at === 'string' ? answer.read_at : null, seen: seenOf(answer) ?? seenOf(n) }
  }
  if (op.state === 'refused') return { kind: 'refused', message: op.error?.message ?? 'The estate did not accept this.' }
  return { kind: op.state }
}

/** The dot on a card. A notice opened on this phone loses its dot here — that is this phone's mirror, not the server's word. */
export const isUnread = (n: Pick<Notice, 'id' | 'read_at'>, ops: readonly ReadOp[]): boolean => {
  const state = readStateOf(n, ops).kind
  return state === 'unread' || state === 'refused'
}

/** Should opening this notice queue a read? Once per notice: an act already in the queue — even a refused one — is never queued again. */
export const shouldQueueRead = (n: Pick<Notice, 'id' | 'read_at'>, ops: readonly ReadOp[]): boolean => !n.read_at && readOpFor(String(n.id), ops) === null

/* ---- the chips ------------------------------------------------------------------------------------ */

export type Filter = { key: string; label: string }

export const ALL: Filter = { key: 'all', label: 'All' }

/**
 * Only what the server's list can really be cut by: unread, urgent, and — when the household sees
 * more than one — the audience. A chip with nothing behind it is not drawn.
 */
export function filtersFor(items: readonly Notice[], ops: readonly ReadOp[]): Filter[] {
  const chips: Filter[] = [ALL]
  if (items.some((n) => isUnread(n, ops))) chips.push({ key: 'unread', label: 'Unread' })
  if (items.some((n) => n.kind === 'urgent')) chips.push({ key: 'urgent', label: 'Urgent' })
  const audiences = [...new Set(items.map((n) => n.audience.trim()).filter(Boolean))]
  if (audiences.length > 1) for (const audience of audiences) chips.push({ key: `audience:${audience}`, label: audience })
  return chips
}

export function applyFilter(items: readonly Notice[], key: string, ops: readonly ReadOp[]): Notice[] {
  if (key === 'unread') return items.filter((n) => isUnread(n, ops))
  if (key === 'urgent') return items.filter((n) => n.kind === 'urgent')
  if (key.startsWith('audience:')) return items.filter((n) => n.audience.trim() === key.slice('audience:'.length))
  return [...items]
}

/**
 * THE DESIGN'S FOUR FILTERS (M42 §5 #11: "Filter chips become: All · Urgent · Security · Events. Delete 'Unread'.").
 * Always these four. Urgent cuts by the server's `kind`; Security and Events cut by a notice's TOPIC — which the server
 * does not send yet, so live they hold only what has a topic (nothing, today). Unread lives behind the funnel.
 */
export const DESIGN_FILTERS: readonly Filter[] = [ALL, { key: 'urgent', label: 'Urgent' }, { key: 'topic:Security', label: 'Security' }, { key: 'topic:Events', label: 'Events' }]

export function designFilter(items: readonly Notice[], key: string, topicsOf: (n: Notice) => readonly string[], unreadOnly: boolean, ops: readonly ReadOp[]): Notice[] {
  const kept = unreadOnly ? items.filter((n) => isUnread(n, ops)) : [...items]
  if (key === 'urgent') return kept.filter((n) => n.kind === 'urgent')
  if (key.startsWith('topic:')) return kept.filter((n) => topicsOf(n).includes(key.slice('topic:'.length)))
  return kept
}

export const findNotice = (id: string | undefined, items: readonly Notice[]): Notice | null => (id === undefined ? null : items.find((n) => String(n.id) === id) ?? null)

/* ---- what the phone is showing, and why ------------------------------------------------------------ */

/** How the last fetch of the list failed, as the API layer reported it (`null`: it did not). */
export type ListFailure = { status: number | 'network' | 'timeout'; code: string; message: string } | null

export type ListStatus = 'loading' | 'ready' | 'error' | 'denied'

/**
 * A 403 is the server's LATEST word about this account, so it outranks a list saved on the phone: a
 * resident whose access the estate has removed is told so — not shown the old notices under a note
 * that blames the connection. Every other failure leaves a saved list on screen.
 */
export function listStatusOf(input: { hasList: boolean; failed: boolean; failure: ListFailure }): ListStatus {
  if (input.failure?.status === 403) return 'denied'
  if (input.hasList) return 'ready'
  return input.failed ? 'error' : 'loading'
}

const DENIED_PENDING = "Your account is not linked to a home on the estate's register yet. Once the estate approves your claim, its notices appear here."
const DENIED_OTHER = "Your account cannot see the estate's notices. Ask your estate office to check your access."

/** `account_pending` is the one 403 whose cause the app knows; any other is not explained by guessing. */
export const deniedSentence = (code: string): string => (code === 'account_pending' ? DENIED_PENDING : DENIED_OTHER)

/**
 * Why a saved list is on screen instead of a fresh one:
 *   offline      no connection (the phone says so, or the request never reached the server)
 *   unrefreshed  the server was reached and failed — "No connection" would be untrue
 */
export type Staleness = 'offline' | 'unrefreshed' | null

export function stalenessOf(input: { hasList: boolean; online: boolean; failed: boolean; failure: ListFailure }): Staleness {
  if (!input.hasList) return null
  if (!input.online) return 'offline'
  if (!input.failed) return null
  return !input.failure || input.failure.status === 'network' || input.failure.status === 'timeout' ? 'offline' : 'unrefreshed'
}

export function staleSentence(staleness: Staleness, what: 'list' | 'notice'): string | null {
  if (staleness === null) return null
  if (what === 'list') {
    return staleness === 'offline'
      ? 'No connection: these are the notices saved on this phone. There may be newer ones.'
      : "The estate's notices could not be refreshed just now: these are the ones saved on this phone. Pull down to try again."
  }
  return staleness === 'offline'
    ? 'No connection: this is the notice as saved on this phone.'
    : 'This notice could not be refreshed just now: this is how it was saved on this phone.'
}

/** The site’s calendar day, as a key: "Today" on a card is recomputed when this changes (a tab stays mounted for days). */
export function dayKey(at: Date): string {
  const p = siteParts(at)
  return `${p.year}-${p.month}-${p.day}`
}

/**
 * Refused reads with nothing left to say. Judged ONLY against a list the server has just sent: the
 * notice is no longer in it (withdrawn — the 404 that refused the read), or the server already has it
 * as read. Nothing else removes a refused read from the queue, so without this each would stay for good.
 */
export function mootRefusals(fresh: readonly Notice[], ops: readonly ReadOp[]): string[] {
  return ops
    .filter((op) => op.endpoint === 'notices.read' && op.state === 'refused')
    .filter((op) => {
      const id = noticeOf(op)
      const notice = id === null ? null : findNotice(id, fresh)
      return notice === null || !!notice.read_at
    })
    .map((op) => op.id)
}
