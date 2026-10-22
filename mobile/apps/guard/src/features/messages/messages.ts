/**
 * Messages — the pure logic. The server has exactly TWO conversations for a guard:
 *   broadcast   what dispatch sends to everyone at the estate (read-only)
 *   dispatch    this guard's own line to the control room (`outbound` from them, `inbound` from the guard)
 * There are no guard-to-guard messages on the server, so the app draws none.
 */

import { dateShort, isSameSiteDay, siteParts, time12 } from '@gs/core/lib'
import type { Api } from '@gs/core/api'

export type Message = Api.MessagesIndexResponse['items'][number]
export type ThreadKind = 'broadcast' | 'dispatch'
export const MAX_BODY = 500

export type Bubble = { id: string; mine: boolean; body: string; time: string; state: 'sent' | 'waiting' | 'refused'; note: string | null }
export type QueuedMessage = { id: string; body: string; capturedAt: string; state: 'pending' | 'syncing' | 'refused' | 'confirmed'; error: string | null; /** the server's id for it, once confirmed */ serverId: number | string | null }

export const threadOf = (m: Message): ThreadKind => (m.direction === 'broadcast' ? 'broadcast' : 'dispatch')

/** The site's calendar day, from the ONE clock — never this phone's. */
const sameDay = (p: string | Date, q: string | Date) => isSameSiteDay(p, q)

/** "9:24 AM" today · "Yesterday" · "Mon" inside a week · "Sep 1" before that — the inbox's own shorthand. */
export function whenLabel(iso: string, at: Date): string {
  if (sameDay(iso, at)) return time12(iso)
  const days = Math.floor((at.getTime() - new Date(iso).getTime()) / 86_400_000)
  if (sameDay(iso, new Date(at.getTime() - 86_400_000))) return 'Yesterday'
  if (days < 7) return ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][siteParts(iso).weekday] ?? dateShort(iso)
  return dateShort(iso)
}

export const dayHeading = (iso: string, at: Date): string => (sameDay(iso, at) ? 'Today' : sameDay(iso, new Date(at.getTime() - 86_400_000)) ? 'Yesterday' : dateShort(iso))

export type ThreadSummary = { kind: ThreadKind; preview: string | null; when: string | null; unread: number }

/**
 * READ STATE IS THE SERVER'S. `read_at` on a message is when THIS guard read it on THIS phone — the
 * server keeps it per guard, per handset, so there is no marker of this phone's own any more. What the phone
 * adds is only what it has ASKED for and not been answered yet: a `messages.read` waiting in the offline queue.
 *
 * A mark-read this phone queued, as the outbox holds it.
 */
export type ReadMark = { id: string; ids: string[]; state: QueuedMessage['state'] }

/** The ids inside a queued `messages.read` body, as strings. Anything else in there is ignored. */
export const idsOfMark = (body: unknown): string[] => {
  const ids = body && typeof body === 'object' ? (body as { ids?: unknown }).ids : null
  return Array.isArray(ids) ? ids.filter((id): id is number | string => typeof id === 'number' || typeof id === 'string').map(String) : []
}

/** Messages a mark-read is on its way for (or was just answered for). A REFUSED one is not: the server said no, and the server wins. */
export const markedIds = (marks: readonly ReadMark[]): Set<string> => new Set(marks.filter((k) => k.state !== 'refused').flatMap((k) => k.ids))

/**
 * Unread: dispatch wrote it, the server has no read of it from this guard on this phone, and no mark-read of
 * this phone's is waiting to tell it so. Offline that last part is what clears the badge — honestly: the act
 * is in the sync queue, where the guard can see it waiting.
 */
export function isUnread(m: Message, marked: ReadonlySet<string> = new Set()): boolean {
  return m.from !== 'you' && !m.read_at && !marked.has(String(m.id))
}

/**
 * What to tell the server once a conversation is on screen: every message in it the server still holds unread
 * that this phone has not ALREADY asked about — in any state, a refused one included, so a refusal is never
 * asked again every thirty seconds. At most a hundred, the endpoint's limit.
 */
export function toMarkRead(items: Message[], kind: ThreadKind, marks: readonly ReadMark[]): number[] {
  const asked = new Set(marks.flatMap((k) => k.ids))
  return items
    .filter((m) => threadOf(m) === kind && m.from !== 'you' && !m.read_at && !asked.has(String(m.id)))
    .map((m) => Number(m.id))
    .filter((id) => Number.isInteger(id) && id > 0)
    .slice(0, 100)
}

/**
 * Answered mark-reads that have nothing left to say: the server confirmed them AND the list now shows every
 * one of their messages read (or no longer lists it). They leave the sync queue — housekeeping is not a
 * record of the post being covered.
 */
export function settledMarks(items: Message[], marks: readonly ReadMark[]): string[] {
  const stillUnread = new Set(items.filter((m) => m.from !== 'you' && !m.read_at).map((m) => String(m.id)))
  return marks.filter((k) => k.state === 'confirmed' && k.ids.every((id) => !stillUnread.has(id))).map((k) => k.id)
}

export function summarise(items: Message[], queued: QueuedMessage[], at: Date, marks: readonly ReadMark[] = []): Record<ThreadKind, ThreadSummary> {
  const marked = markedIds(marks)
  const newest = (kind: ThreadKind) => items.filter((m) => threadOf(m) === kind).sort((p, q) => new Date(q.sent_at).getTime() - new Date(p.sent_at).getTime())[0]
  const unread = (kind: ThreadKind) => items.filter((m) => threadOf(m) === kind && isUnread(m, marked)).length
  const b = newest('broadcast')
  const d = newest('dispatch')
  const waiting = queued.filter((q) => q.state === 'pending' || q.state === 'syncing').sort((p, q) => new Date(q.capturedAt).getTime() - new Date(p.capturedAt).getTime())[0]
  const lastMine = waiting && (!d || new Date(waiting.capturedAt).getTime() > new Date(d.sent_at).getTime())
  return {
    broadcast: { kind: 'broadcast', preview: b ? `Dispatch: ${b.body}` : null, when: b ? whenLabel(b.sent_at, at) : null, unread: unread('broadcast') },
    dispatch: { kind: 'dispatch', preview: lastMine ? `You: ${waiting.body}` : d ? `${d.from === 'you' ? 'You' : 'Dispatch'}: ${d.body}` : null, when: lastMine ? 'Waiting to send' : d ? whenLabel(d.sent_at, at) : null, unread: unread('dispatch') },
  }
}

/** One conversation, oldest first, with what is still on the phone at the end — honestly marked. */
export function bubbles(items: Message[], queued: QueuedMessage[], kind: ThreadKind): { day: string; iso: string; rows: Bubble[] }[] {
  const sent: (Bubble & { iso: string })[] = items
    .filter((m) => threadOf(m) === kind)
    .map((m) => ({ id: `m${m.id}`, iso: m.sent_at, mine: m.from === 'you', body: m.body, time: time12(m.sent_at), state: 'sent' as const, note: null }))
  // A confirmed op is the server's by now: it stays (as sent) only until the list that carries it has arrived.
  const listed = new Set(items.map((m) => String(m.id)))
  const held: (Bubble & { iso: string })[] =
    kind === 'dispatch'
      ? queued
          .filter((q) => q.state !== 'confirmed' || (q.serverId !== null && !listed.has(String(q.serverId))))
          .map((q) => ({ id: `q${q.id}`, iso: q.capturedAt, mine: true, body: q.body, time: time12(q.capturedAt), state: q.state === 'confirmed' ? ('sent' as const) : q.state === 'refused' ? ('refused' as const) : ('waiting' as const), note: q.state === 'confirmed' ? null : q.state === 'refused' ? (q.error ?? 'Not accepted') : 'Waiting to send' }))
      : []
  const all = [...sent, ...held].sort((p, q) => new Date(p.iso).getTime() - new Date(q.iso).getTime())
  const groups: { day: string; iso: string; rows: Bubble[] }[] = []
  for (const row of all) {
    const last = groups[groups.length - 1]
    if (last && sameDay(last.iso, row.iso)) last.rows.push(row)
    else groups.push({ day: row.iso, iso: row.iso, rows: [row] })
  }
  return groups
}

export const validateBody = (text: string): string | null => (text.trim().length === 0 ? 'Write a message first.' : text.trim().length > MAX_BODY ? `Keep it under ${MAX_BODY} characters.` : null)
