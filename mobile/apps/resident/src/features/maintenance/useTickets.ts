/**
 * The household's maintenance requests — board resident-app-26 (one request's status), and the short
 * "Your requests" list under the new-request form.
 *
 *   READ     `tickets.index`, cached (`useOfflineCache`): the contract says "Serve the cache", so a
 *            request's status opens with no connection.
 *   ONE      there is no `tickets.show`: a request is found BY ID in that list — or, between "sent" and
 *            "listed", in the server's own answer to the request this phone queued.
 *   IS IT FIXED? Once the office marks a job completed the household answers (26 Sep, R8): "Yes, it's fixed"
 *            (`tickets.confirm_fixed`) or "Still a problem" with what is still wrong (`tickets.still_a_problem`,
 *            back in the office's queue) — `fixedAnswer()`, queued like every act.
 *   ADD A COMMENT (M47 §11b) `tickets.comment`: a note on the request's history, where the office works the job —
 *            queued like every act, so a message written with no signal goes when there is one; the screen says
 *            which of sent, waiting or refused it is.
 *   MESSAGES (M54 §2.2) the thread both ends write into: the household's comments and what the office sends from the
 *            console's "Message resident", read off the request's own history (tickets.ts, threadOf).
 *   THE VENDOR (M47 §11a) the company the estate gave the job to, and the number its call button rings.
 */

import { useCallback, useMemo, useState } from 'react'
import { fidelityScreenId, isFidelity, now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { mergeById, olderFor, time12, type OlderPages } from '@gs/core/lib'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { statusFixture } from '@/fidelity/maintenance'
import { DemoWriteRefused, drainNow, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { boardSteps, findTicket, inQuotes, isIncidentReport, isTicket, newerHistory, readNotes, requestsOnly, recentRows, refusalToClear, requestTitle, submittedLine, threadOf, vendorOf, type BoardStep, type MessageRow, type QueuedOp, type RecentRow, type Ticket } from './tickets'

const DENIED = 'Your account cannot see maintenance requests. Ask your estate office to check your access.'

/* ---- the list the phone holds ------------------------------------------------------------------- */

export type ListState = {
  status: 'loading' | 'ready' | 'error' | 'denied'
  errorMessage: string | null
  items: readonly Ticket[]
  reportIds: readonly string[]
  /**
   * For "All requests": EVERY ticket the phone holds, of both kinds — the server's newest page and whatever
   * that screen has fetched beyond it — then the newest page alone, where the page before it starts (null: nothing
   * older, or a server that does not page), and the chain fetched so far, while it still belongs to TODAY's first
   * page (core: olderPages.ts). A request opened from that list is found in `items` like any other.
   */
  all: readonly Ticket[]
  first: readonly Ticket[]
  firstNext: string | null
  older: OlderPages<Ticket> | null
  ops: readonly QueuedOp[]
  online: boolean
  stale: boolean
  refreshing: boolean
  refresh: () => void
}

/** `fetchedOlder`: the chain "All requests" has just extended — saved beside the first page, so it opens offline too. */
export function useTicketList(skip: boolean, fetchedOlder?: OlderPages<Ticket>): ListState {
  const online = useAppSelector((s) => s.network.online)
  const view = useAppSelector((s) => s.outbox.ops)
  const query = residentApi.useTicketsIndexQuery(undefined, { skip })
  const cached = useOfflineCache('tickets.all', query.data)
  const savedOlder = useOfflineCache<OlderPages<Ticket>>('tickets.older', fetchedOlder)
  const [refreshing, setRefreshing] = useState(false)

  // The store mirrors the queue without bodies or answers; the queue itself has both.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const ops = useMemo<readonly QueuedOp[]>(() => (skip ? [] : outbox.all().filter((op) => op.kind === 'ticket')), [view, skip])

  const refetch = query.refetch
  const refresh = useCallback(() => {
    if (skip) return
    setRefreshing(true)
    void drainNow() // a person pulled the list down: go at once
    void refetch().finally(() => setRefreshing(false))
  }, [refetch, skip])

  // A 403 is the SERVER saying this account may not see these — it outranks whatever the phone saved earlier, and it
  // is not "no connection". (Found by the notices review, 2026-09-20: the saved list used to be shown under a 403
  // with a banner blaming the signal.) Any other failure keeps the saved list, marked as saved.
  const denied = isApiError(query.error) && query.error.status === 403
  // `?? null`: a first page saved by an older build has no `next_before` at all.
  const firstNext = denied ? null : (cached?.next_before ?? null)
  const first = useMemo<readonly Ticket[]>(() => (denied ? [] : (cached?.items ?? [])), [denied, cached])
  const older = useMemo(() => (denied ? null : olderFor(savedOlder, firstNext)), [denied, savedOlder, firstNext])
  const all = useMemo<readonly Ticket[]>(() => (older ? mergeById(first, older.items) : first), [first, older])
  return {
    status: denied ? 'denied' : cached ? 'ready' : query.error ? 'error' : 'loading',
    errorMessage: denied ? DENIED : isApiError(query.error) ? query.error.message : null,
    // Security reports are tickets too, and live under "Your reports" — never in both lists (tickets.ts: INCIDENT_MARKER).
    items: requestsOnly(all),
    /** The id of a ticket that IS this household's but is a security report — so its screen can say where it lives. */
    reportIds: all.filter(isIncidentReport).map((item) => String(item.id)),
    all,
    first,
    firstNext,
    older,
    ops,
    online,
    stale: !denied && !!cached && (!online || !!query.error),
    refreshing,
    refresh,
  }
}

/* ---- "Your requests", under the form ------------------------------------------------------------- */

/**
 * A refusal the resident has read leaves the queue — its photos first, then the request (tickets.ts says
 * why the order matters, and why nothing goes while a photo is still waiting). Nothing else ever removes
 * a refused request: left alone it heads "Your requests" for good.
 */
export async function clearRefusal(opId: string): Promise<void> {
  const ids = refusalToClear(opId, outbox.all())
  if (!ids) return
  for (const id of ids) await outbox.dismiss(id)
}

export type RecentModel = {
  status: ListState['status']
  errorMessage: string | null
  rows: RecentRow[]
  more: number
  stale: boolean
  refreshing: boolean
  refresh: () => void
  /** True for a refused row that can leave the list NOW — the control is drawn only then, so it never does nothing. */
  canDismiss: (row: RecentRow) => boolean
  dismiss: (row: RecentRow) => void
}

export function useRecentRequests(): RecentModel {
  const list = useTicketList(isFidelity)
  const rows = useMemo(() => recentRows({ items: list.items, ops: list.ops }), [list.items, list.ops])
  const shown = rows.filter((row) => row.ticketId !== null).length
  const ops = list.ops
  const canDismiss = useCallback((row: RecentRow) => row.queue === 'refused' && row.opId !== null && refusalToClear(row.opId, ops) !== null, [ops])
  const dismiss = useCallback((row: RecentRow) => {
    if (row.opId) void clearRefusal(row.opId)
  }, [])
  return { status: list.status, errorMessage: list.errorMessage, rows, more: Math.max(0, list.items.length - shown), stale: list.stale, refreshing: list.refreshing, refresh: list.refresh, canDismiss, dismiss }
}

/* ---- one request --------------------------------------------------------------------------------- */

/**
 * "Is it fixed?" (the client's report R8, 26 Sep) — once the office has marked the job completed, the household says
 * yes (verified: "Closed by you", the board's "Verified by you") or that it is still a problem (back in the office's queue, with what is
 * still wrong). Saved on the phone first and sent like any request.
 */
export type FixedModel = {
  canConfirm: boolean
  canReopen: boolean
  confirmed: boolean
  sending: 'confirm' | 'reopen' | null
  refusal: string | null
  confirm: () => void
  reopen: (reason: string) => void
}

/** Board resident-app-26, as this request fills it. */
export type TicketModel = {
  status: 'loading' | 'ready' | 'missing' | 'error' | 'denied'
  /** Set when the id is one of the household's SECURITY REPORTS: the route that shows it. */
  elsewhere: string | null
  errorMessage: string | null
  /** The app bar: "Request #1042". */
  heading: string
  ticket: Ticket | null
  /** The summary card: the title, the resident's words between quotation marks, "Submitted Sep 2 · Electrical". */
  title: string
  words: string | null
  meta: string
  /** "Status": the six stages, each line in the board's format. */
  steps: BoardStep[]
  /**
   * "Assigned vendor" (M47 §11a): the company, its initials, "Tech: Owen Grant · today, 2:00–4:00 PM" and the number its
   * call button rings — null until somebody has the job; no call button without a number.
   */
  vendor: { initials: string | null; name: string | null; line: string | null; phone: string | null } | null
  /**
   * "Messages" (M54 §2.2): the household's comments and the office's replies on this request, oldest first. Empty on
   * the board, which draws no thread, and on a request nobody has written on yet.
   */
  messages: MessageRow[]
  /** "Add a comment" (M47 §11b): it sends. */
  comment: CommentModel
  /** Null while the job is open, cancelled, or on the board (which draws it in progress). */
  fixed: FixedModel | null
  refreshing: boolean
  refresh: () => void
}

/** "Add a comment": what is typed, and what became of the last message — on its way, sent, waiting for signal, refused. */
export type CommentModel = {
  text: string
  setText: (text: string) => void
  send: () => void
  /** Said under the box, never in a system dialog (M47 U7); null before anything is sent. */
  state: { kind: 'sending' | 'sent' | 'waiting' | 'refused'; line: string } | null
}

/** Long enough for the office to read, short enough for one message: the server's own ceiling. */
export const COMMENT_MAX = 500

/**
 * When the office received it: the server's own time on the note, from its answer — a message written with no signal
 * arrives later than it was written. The moment it was written when the answer names none.
 */
const receivedAt = (op: QueuedOp): string => {
  const said = typeof op.body?.body === 'string' ? op.body.body.trim() : null
  const notes = isTicket(op.result) ? op.result.timeline.filter((entry) => entry.event === 'note' && entry.note === said) : []
  return notes[notes.length - 1]?.occurred_at ?? op.capturedAt
}

/** What became of this request's last comment, from the outbox that sends it. */
export function commentState(ticketId: string, ops: readonly QueuedOp[], online: boolean): CommentModel['state'] {
  const mine = ops.filter((op) => op.endpoint === 'tickets.comment' && String(op.params?.ticket) === ticketId)
  const last = mine[mine.length - 1]
  if (!last) return null
  if (last.state === 'refused') return { kind: 'refused', line: last.error?.message ?? 'The estate office did not accept that message.' }
  if (last.state === 'confirmed') return { kind: 'sent', line: `Sent to the estate office · ${time12(receivedAt(last))}` }
  return online ? { kind: 'sending', line: 'Sending…' } : { kind: 'waiting', line: 'Saved on this phone: it goes to the estate office when you are back online.' }
}

export function useTicket(id: string | undefined): TicketModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-26' ? statusFixture : null
  const list = useTicketList(isFidelity)

  const listed: Ticket | null = fixture ? fixture.ticket : findTicket(id, list.items, list.ops.map((op) => op.result))
  // The fuller of the list's copy and the server's answer to a message sent from here: that message is in the thread
  // the moment the office has it, not only once the list is fetched again.
  const ticket: Ticket | null = listed && !fixture ? newerHistory(listed, list.ops.map((op) => op.result)) : listed

  const steps = useMemo(() => (ticket ? boardSteps(ticket) : []), [ticket])
  const messages = useMemo(() => (ticket && !fixture ? threadOf(ticket) : []), [ticket, fixture])

  // The estate's clock, frozen on the board.
  const vendor = ticket ? vendorOf(ticket, now()) : null

  // "Add a comment" — sent through the outbox, so it is never lost for want of a signal.
  const refusal = useDemoRefusal()
  const [text, setText] = useState('')
  const [said, setSaid] = useState<string | null>(null)
  const ticketId = ticket ? String(ticket.id) : null
  const send = useCallback(() => {
    const body = text.trim()
    if (fixture || !ticketId || !ticket || body === '') return
    if (refusal) return setSaid(refusal)
    setSaid(null)
    void submit({ endpoint: 'tickets.comment', params: { ticket: ticket.id }, body: { body: body.slice(0, COMMENT_MAX) }, label: `Comment · ${requestTitle(ticket)}`, kind: 'ticket' })
      .then(() => setText(''))
      .catch((e: unknown) => setSaid(e instanceof DemoWriteRefused ? e.message : 'The message could not be kept on this phone. Try again.'))
  }, [fixture, ticketId, ticket, text, refusal])
  const commentLine = said ? { kind: 'refused' as const, line: said } : ticketId ? commentState(ticketId, list.ops, list.online) : null

  return {
    status: ticket ? 'ready' : list.status === 'ready' ? 'missing' : list.status,
    // It IS this household's — but it is a security report, and those live on their own screen.
    elsewhere: !ticket && !!id && list.reportIds.includes(id) ? `/incident/${id}` : null,
    errorMessage: list.errorMessage,
    heading: ticket ? requestTitle(ticket) : 'Maintenance request',
    ticket,
    title: ticket?.title ?? '',
    // The old "Notes to the office" block an older request carries is taken out (tickets.ts: readNotes).
    words: ticket ? inQuotes(readNotes(ticket).text) : null,
    meta: fixture ? fixture.board.meta : ticket ? submittedLine(ticket) : '',
    steps,
    vendor,
    messages,
    comment: { text, setText: (value) => { setSaid(null); setText(value.slice(0, COMMENT_MAX)) }, send, state: commentLine },
    fixed: fixedAnswer(ticket, fixture !== null, list.ops, 'Maintenance request'),
    refreshing: list.refreshing,
    refresh: list.refresh,
  }
}

/**
 * The household's answer to "is it fixed?", for a maintenance request or a security report alike (both are tickets).
 * The act is queued like every other (`tickets.confirm_fixed`, `tickets.still_a_problem`); while it waits the screen
 * says so, and a refusal is said in the server's words.
 */
export function fixedAnswer(ticket: Pick<Ticket, 'id' | 'number' | 'status'> | null, onBoard: boolean, ops: readonly QueuedOp[], kindLabel: string, kind = 'ticket'): FixedModel | null {
  if (!ticket || onBoard || (ticket.status !== 'completed' && ticket.status !== 'verified')) return null
  const mine = ops.filter((op) => (op.endpoint === 'tickets.confirm_fixed' || op.endpoint === 'tickets.still_a_problem') && String((op as { params?: Record<string, unknown> }).params?.ticket) === String(ticket.id))
  const waiting = mine.find((op) => op.state === 'pending' || op.state === 'syncing')
  const refused = [...mine].reverse().find((op) => op.state === 'refused')
  const label = `${kindLabel} #${ticket.number ?? ticket.id}`

  return {
    canConfirm: ticket.status === 'completed' && !waiting,
    canReopen: !waiting,
    confirmed: ticket.status === 'verified',
    sending: waiting ? (waiting.endpoint === 'tickets.confirm_fixed' ? 'confirm' : 'reopen') : null,
    refusal: refused && !waiting ? ((refused as { error?: { message?: string } | null }).error?.message ?? 'The estate office did not accept that.') : null,
    confirm: () => void submit({ endpoint: 'tickets.confirm_fixed', params: { ticket: ticket.id }, body: {}, label: `It's fixed · ${label}`, kind }).catch(() => undefined),
    reopen: (reason: string) => {
      const why = reason.trim()
      if (why.length < 3) return
      void submit({ endpoint: 'tickets.still_a_problem', params: { ticket: ticket.id }, body: { reason: why.slice(0, 500) }, label: `Still a problem · ${label}`, kind }).catch(() => undefined)
    },
  }
}
