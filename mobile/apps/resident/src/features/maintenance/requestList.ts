/**
 * "All requests" — the list's pure logic (no React, no UI kit), so it is unit-tested without a phone.
 *
 * NO BOARD DRAWS THIS SCREEN. The specification names it, and its absence is the first thing a resident notices, so it is assembled from the
 * boards' own parts: board 26's card, icon tile and status pill (the same ones "Your requests" uses under the
 * form) and board 12's filter chips.
 *
 * EVERYTHING THE HOUSEHOLD HAS SENT THE ESTATE OFFICE, newest first — and the server files two kinds of thing as
 * tickets: a MAINTENANCE REQUEST and a SECURITY REPORT. They are told apart exactly as everywhere else in the app:
 *   - a ticket in the server's list is a security report when its description carries the Report-incident marker
 *     (tickets.ts, INCIDENT_MARKER) — never by its category;
 *   - an act still on this phone is a security report when the Report-incident screens queued it (the outbox's
 *     own `kind`), whatever it reads like.
 * Each row opens the screen that ALREADY shows that kind: /maintenance/[id] (Maintenance Status) or
 * /incident/[id] (Incident Status). A row the server has not taken has no id, and opens nothing.
 *
 * A status is said in WORDS, in each kind's own vocabulary (a request is "Assigned", a report is "Being looked
 * into") — the same words its status screen uses, so the list and the screen it opens never disagree.
 */

import { dateShort, siteParts } from '@gs/core/lib'
import { STATUS_LABEL, categoryLabel, isClosed, isIncidentReport, isTicket, stamp, type QueuedOp, type Status, type Ticket } from './tickets'

/** The outbox's `kind` for an act the Report-incident screens queued (features/incidents, INCIDENT_KIND — copied, not imported: features do not reach into each other). */
export const REPORT_KIND = 'incident'
/** …and for one the maintenance form queued. */
export const REQUEST_KIND = 'ticket'

export type Kind = 'request' | 'report'

/** What this file needs of an outbox record: tickets.ts's, plus whose act it is. */
export type ListOp = QueuedOp & { kind: string }

/** A security report's statuses in its own screen's words (features/incidents/incidents.ts, STATUS_LABEL — copied; both sides are tested against the other's). */
export const REPORT_STATUS_LABEL: Record<Status, string> = {
  submitted: 'Submitted',
  acknowledged: 'Received',
  assigned: 'Being looked into',
  in_progress: 'Being looked into',
  completed: 'Resolved',
  verified: 'Resolved',
  cancelled: 'Closed',
}

export const statusWords = (kind: Kind, status: Status): string => (kind === 'report' ? REPORT_STATUS_LABEL[status] : STATUS_LABEL[status]) ?? String(status)

/** `red` is drawn as an OUTLINE, never a fill (M98): a request the estate did not accept is Rejected, not a panic. */
export type ChipTone = 'navy' | 'amber' | 'green' | 'slate' | 'red'

const STATUS_TONE: Record<Status, ChipTone> = { submitted: 'navy', acknowledged: 'navy', assigned: 'navy', in_progress: 'amber', completed: 'green', verified: 'green', cancelled: 'slate' }

const QUEUE_LABEL = { pending: 'Pending sync', syncing: 'Syncing', refused: 'Not accepted' } as const
const QUEUE_TONE: Record<keyof typeof QUEUE_LABEL, ChipTone> = { pending: 'amber', syncing: 'amber', refused: 'red' }

export type RequestRow = {
  key: string
  kind: Kind
  /** "Maintenance" · "Security report" — said on the row, because the list holds both. */
  kindLabel: string
  title: string
  detail: string
  chip: { label: string; tone: ChipTone }
  /** Still on this phone (its outbox state) — null once it is the server's. */
  queue: 'pending' | 'syncing' | 'refused' | null
  /** The server's status, once it has one. */
  status: Status | null
  /** The screen that shows it — null for a row the server has not taken: it has no id, and opens nothing. */
  path: string | null
  /** The newest moment the office recorded on it (UTC instant), for "Updated 2 h ago"; null for a row still on this phone. */
  updated?: string | null
  /** A completed job waiting for the household's answer: drawn to stand out (`NEEDS_CONFIRMATION`). */
  needsConfirmation?: boolean
  /** For a screen reader: everything on the row, then what pressing does (or that it cannot be opened yet). */
  label: string
}

const KIND_LABEL: Record<Kind, string> = { request: 'Maintenance', report: 'Security report' }

const textOf = (value: unknown): string => (typeof value === 'string' ? value : '')
const timeOf = (iso: string): number => new Date(iso).getTime()
const numberOf = (ticket: Pick<Ticket, 'number'>): string => String(ticket.number).replace(/^#/, '')
/** Ids are numbers on this server; one that is not is ordered as text rather than dropped. */
const idOrder = (p: number | string, q: number | string): number => {
  const [a, b] = [Number(p), Number(q)]
  return Number.isFinite(a) && Number.isFinite(b) ? a - b : String(p) < String(q) ? -1 : String(p) > String(q) ? 1 : 0
}

/** "2 Sep 2026", the one form with its year every time (M98). Kingston's calendar. `_at` is kept for its callers. */
export const dayOf = (iso: string, _at?: Date): string => dateShort(iso)

export const kindOfTicket = (ticket: { description?: string | null }): Kind => (isIncidentReport(ticket) ? 'report' : 'request')

export const pathOf = (kind: Kind, id: number | string): string => `${kind === 'report' ? '/incident' : '/maintenance'}/${String(id)}`

/**
 * The last moment the office recorded on a ticket: its newest timeline entry, else when it was reported. What "Updated
 * 2 h ago" is read from (M98, Hassam: "how the user is able to view his current or past maintenance and incident").
 */
export function updatedAt(ticket: Pick<Ticket, 'reported_at' | 'timeline'>): string {
  let latest = ticket.reported_at
  for (const entry of ticket.timeline ?? []) if (typeof entry.occurred_at === 'string' && timeOf(entry.occurred_at) > timeOf(latest)) latest = entry.occurred_at
  return latest
}

/** A fixed job the office has marked completed and this household has not yet answered: the one thing in the list that is waiting on THEM. */
export const NEEDS_CONFIRMATION = 'Needs your confirmation'

function filedRow(ticket: Ticket, kind: Kind, at: Date): RequestRow {
  const confirm = kind === 'request' && ticket.status === 'completed'
  // The server's own plain-word status (status_label, from Security's change to tickets.index) when it sends one; this app's words otherwise.
  const sent = (ticket as { status_label?: unknown }).status_label
  const words = confirm ? NEEDS_CONFIRMATION : typeof sent === 'string' && sent.trim() !== '' ? sent.trim() : statusWords(kind, ticket.status)
  const detail = [`#${numberOf(ticket)}`, dayOf(ticket.reported_at, at), kind === 'request' ? categoryLabel(ticket.category) : null].filter(Boolean).join(' · ')
  return {
    key: `t:${ticket.id}`,
    kind,
    kindLabel: KIND_LABEL[kind],
    title: ticket.title,
    detail,
    chip: { label: words, tone: confirm ? 'amber' : (STATUS_TONE[ticket.status] ?? 'navy') },
    queue: null,
    status: ticket.status,
    path: pathOf(kind, ticket.id),
    updated: updatedAt(ticket),
    needsConfirmation: confirm,
    label: `${KIND_LABEL[kind]}: ${ticket.title}. ${words}. ${detail}. Open it`,
  }
}

/**
 *   waiting / being sent  → on top, said to be on this phone — never "submitted"
 *   refused               → with the server's sentence; it was never filed, so it opens nothing
 *   confirmed             → the server's now. Until the list carries it, it is drawn from the server's own
 *                           answer, so nothing vanishes between "sent" and "listed".
 * Then everything the server holds, newest first. Never drawn twice.
 */
export function allRows(input: { items: readonly Ticket[]; ops: readonly ListOp[]; at: Date }): RequestRow[] {
  const sends = input.ops.filter((op) => op.endpoint === 'tickets.store' && (op.kind === REQUEST_KIND || op.kind === REPORT_KIND))
  const kindOfOp = (op: ListOp): Kind => (op.kind === REPORT_KIND ? 'report' : 'request')

  const unsent: RequestRow[] = sends
    .filter((op): op is ListOp & { state: 'pending' | 'syncing' | 'refused' } => op.state !== 'confirmed')
    .slice()
    .sort((x, y) => timeOf(y.capturedAt) - timeOf(x.capturedAt))
    .map((op) => {
      const kind = kindOfOp(op)
      const title = textOf(op.body?.title) || (kind === 'report' ? 'Security report' : 'Maintenance request')
      const detail = op.state === 'refused' ? (op.error?.message ?? `The estate office did not accept this ${kind === 'report' ? 'report' : 'request'}.`) : `Saved on this phone ${stamp(op.capturedAt)}, not sent yet`
      return {
        key: `q:${op.id}`,
        kind,
        kindLabel: KIND_LABEL[kind],
        title,
        detail,
        chip: { label: QUEUE_LABEL[op.state], tone: QUEUE_TONE[op.state] },
        queue: op.state,
        status: null,
        path: null,
        label: `${KIND_LABEL[kind]}: ${title}. ${QUEUE_LABEL[op.state]}. ${detail}`,
      }
    })

  const listed = new Set(input.items.map((item) => String(item.id)))
  // An answer the outbox kept is of the kind of the act it answered — the screens that queued it know what they sent.
  const answered = sends.flatMap((op) => (op.state === 'confirmed' && isTicket(op.result) && !listed.has(String(op.result.id)) ? [{ ticket: op.result, kind: kindOfOp(op) }] : []))

  const filed = [...answered, ...input.items.map((ticket) => ({ ticket, kind: kindOfTicket(ticket) }))]
    .slice()
    // Two received in the same second: the later-filed (higher id) first — the server's own tie-break, so pages join up.
    .sort((x, y) => timeOf(y.ticket.reported_at) - timeOf(x.ticket.reported_at) || idOrder(y.ticket.id, x.ticket.id))
    .map(({ ticket, kind }) => filedRow(ticket, kind, input.at))

  return [...unsent, ...filed]
}

/* ---- the chips ------------------------------------------------------------------------------------ */

export type Filter = { key: 'all' | Kind | 'open'; label: string }

export const ALL: Filter = { key: 'all', label: 'All' }

/** Still with the office (or still on this phone, waiting to be sent): not completed, verified or cancelled — and not a refusal, which was never filed. */
export const isOpenRow = (row: RequestRow): boolean => (row.queue ? row.queue !== 'refused' : row.needsConfirmation === true || (row.status !== null && !isClosed(row.status)))

/** Only chips with something behind them; the two kinds are offered only when the list really holds both. */
export function filtersFor(rows: readonly RequestRow[]): Filter[] {
  const chips: Filter[] = [ALL]
  if (rows.some(isOpenRow) && rows.some((row) => !isOpenRow(row))) chips.push({ key: 'open', label: 'Still open' })
  if (rows.some((row) => row.kind === 'request') && rows.some((row) => row.kind === 'report')) chips.push({ key: 'request', label: 'Maintenance' }, { key: 'report', label: 'Security reports' })
  return chips
}

/**
 * THE HUB'S TWO TABS, "Maintenance" and "Incidents" (M98): one kind each. A tab's rows are split OPEN first, then PAST,
 * each newest first as the list already is; a row still on this phone counts as open (a refusal, which was never filed,
 * is listed with the past, so it does not sit at the head of "Open").
 */
export type Tab = 'request' | 'report'
export const TAB_LABEL: Record<Tab, string> = { request: 'Maintenance', report: 'Incidents' }
export type Section = { key: 'open' | 'past'; title: string; rows: RequestRow[] }

/** Where a tab is reached from Home, the form its plus opens, and what the plus says (M98: the list first, then the form). */
export const tabEntry = (tab: Tab): { list: string; form: string; plus: string } =>
  tab === 'request' ? { list: '/requests?tab=request', form: '/maintenance/new', plus: 'New maintenance request' } : { list: '/requests?tab=report', form: '/incident/new', plus: 'Report an incident' }

export function tabRows(rows: readonly RequestRow[], tab: Tab): RequestRow[] {
  return rows.filter((row) => row.kind === tab)
}

export function sectionsOf(rows: readonly RequestRow[]): Section[] {
  const open = rows.filter(isOpenRow)
  const past = rows.filter((row) => !isOpenRow(row))
  return [
    ...(open.length > 0 ? [{ key: 'open' as const, title: `Open · ${open.length}`, rows: open }] : []),
    ...(past.length > 0 ? [{ key: 'past' as const, title: 'Past', rows: past }] : []),
  ]
}

/**
 * HOME'S "My requests" CARD (M98): "2 open · #1055 In progress". Null when this household has never filed anything,
 * and then the card is not drawn at all. With nothing open it says how many it has filed, never "0 open".
 */
export function homeSummary(rows: readonly RequestRow[]): { line: string; open: number; waitingOnYou: boolean } | null {
  if (rows.length === 0) return null
  const open = rows.filter(isOpenRow)
  const waitingOnYou = rows.some((row) => row.needsConfirmation === true)
  if (open.length === 0) return { line: `${rows.length} ${rows.length === 1 ? 'request' : 'requests'} filed, none open`, open: 0, waitingOnYou }
  const lead = open[0]!
  const number = lead.detail.split(' · ')[0] ?? ''
  const said = [number.startsWith('#') ? number : null, lead.chip.label].filter(Boolean).join(' ')
  return { line: `${open.length} open${said ? ` · ${said}` : ''}`, open: open.length, waitingOnYou }
}

export function applyFilter(rows: readonly RequestRow[], key: Filter['key']): RequestRow[] {
  if (key === 'open') return rows.filter(isOpenRow)
  if (key === 'request' || key === 'report') return rows.filter((row) => row.kind === key)
  return [...rows]
}

/* ---- older pages, said plainly ----------------------------------------------------------------------- */

/** Why "Show older requests" cannot be pressed — or null when it can. Older pages are asked of the server: there is no saved copy to fall back on. */
export const olderBlocked = (online: boolean): string | null => (online ? null : 'Older requests are fetched from your estate, so they need a connection.')

/** Said under the list once the server has nothing older — so the end reads as the end, not as a fault. */
export const END_OF_LIST = 'That is everything your household has sent the estate office.'
