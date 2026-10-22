/**
 * Messages — boards guard-app-18 (the inbox) and guard-app-14 (a thread).
 *
 *   THE SERVER'S LIST   messages.index, kept on the phone (useOfflineCache) so it is there with no signal,
 *                       and asked for again every 30 s while a message screen is open.
 *   THIS PHONE'S OWN    what the guard wrote and has not reached dispatch yet is read from the outbox and
 *                       shown in the thread as "Waiting to send" — never as sent. A refused one says why.
 *   SENDING             submit(): captured on the phone first, with its Idempotency-Key and device time,
 *                       then sent. The screen never claims delivery it does not have.
 *   READ                the SERVER's, per guard and per phone: `read_at` on each message is this
 *                       guard's own read on this handset. Showing a conversation tells the server so
 *                       (`messages.read`, through submit() — offline it waits in the sync queue and follows
 *                       when signal returns). The phone keeps no read marker of its own: with no signal it
 *                       shows the last list the server gave it, plus what it has asked and not been answered.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { fidelityScreenId, getStorage, isFidelity, jsonStore, now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { pollEvery, useDataLight } from '@gs/core/lib'
import { drainNow, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { bubbles, idsOfMark, settledMarks, summarise, toMarkRead, validateBody, type QueuedMessage, type ReadMark, type ThreadKind } from './messages'

const POLL_MS = 30_000
const THREAD_NAME: Record<ThreadKind, string> = { broadcast: 'Estate broadcasts', dispatch: 'Control room' }

function useFeed(skip: boolean) {
  const online = useAppSelector((s) => s.network.online)
  const mirrored = useAppSelector((s) => s.outbox.ops)
  // A convenience refresh: slower in data-light mode. (A NEW message still arrives with the app's 20 s "what changed?" pull.)
  const light = useDataLight()
  const query = guardApi.useMessagesIndexQuery(undefined, { skip, pollingInterval: pollEvery(POLL_MS, light) })
  const cached = useOfflineCache('messages.index', query.data)

  // `mirrored` is the signal that the outbox changed; the bodies themselves are read from the outbox.
  const queued = useMemo<QueuedMessage[]>(
    () => (skip ? [] : outbox.all().filter((op) => op.endpoint === 'messages.store').map((op) => ({ id: op.id, body: String(op.body?.body ?? ''), capturedAt: op.capturedAt, state: op.state, error: op.error?.message ?? null, serverId: (op.result as { id?: number | string } | null)?.id ?? null }))),
    [skip, mirrored],
  )

  // The mark-reads this phone has asked for — waiting, being sent, answered or refused.
  const marks = useMemo<ReadMark[]>(
    () => (skip ? [] : outbox.all().filter((op) => op.endpoint === 'messages.read').map((op) => ({ id: op.id, ids: idsOfMark(op.body), state: op.state }))),
    [skip, mirrored],
  )

  /*
   * The read marker this phone used to keep, before the server held read state per guard and per phone.
   * Nothing reads it any more, so it is cleared wherever messages are opened — the inbox as
   * well as a thread — rather than left behind as a record of what this handset once decided.
   */
  useEffect(() => {
    if (!skip) jsonStore<unknown>(getStorage(), 'messages.seen', 'v1').remove()
  }, [skip])

  const [refreshing, setRefreshing] = useState(false)
  const refetch = query.refetch
  const refresh = useCallback(async () => {
    if (skip) return
    setRefreshing(true)
    try {
      await drainNow()
      await refetch()
    } finally {
      setRefreshing(false)
    }
  }, [skip, refetch])

  const error = query.error
  const forbidden = isApiError(error) && error.status === 403
  const items = cached?.items
  const fresh = !!query.data && !query.isError

  // An answered mark-read whose messages the list now shows read has nothing left to say: it leaves the sync queue.
  // Only against a FRESH list — a cached one cannot confirm anything.
  useEffect(() => {
    if (skip || !fresh || !items) return
    for (const id of settledMarks(items, marks)) void outbox.dismiss(id)
  }, [skip, fresh, items, marks])

  const status: 'loading' | 'error' | 'forbidden' | 'ready' = skip || items || queued.length > 0 ? 'ready' : query.isLoading ? 'loading' : forbidden ? 'forbidden' : error ? 'error' : 'ready'
  const staleNote = skip || fresh || query.isLoading ? null
    : forbidden ? 'This handset is not allowed to read messages. Contact your supervisor.'
    : items ? `${online ? 'Messages could not be refreshed' : 'No signal'}: showing what this phone last received. New messages will appear when the connection is back.`
    : `${online ? 'Messages could not be loaded' : 'No signal: messages have not reached this phone yet'}.`

  return { online, items: items ?? [], queued, marks, status, staleNote, errorMessage: isApiError(error) ? error.message : null, refreshing, refresh: () => void refresh() }
}

export function useInbox() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-18'
  const feed = useFeed(fixture)
  const siteName = useAppSelector((s) => s.session.principal?.siteName) ?? 'Your estate'
  const threads = useMemo(() => summarise(feed.items, feed.queued, now(), feed.marks), [feed.items, feed.queued, feed.marks])
  return { ...feed, fixture, siteName, threads }
}

/** `opening` — words another screen hands over to start the message with (a payslip question names its period). */
export function useThread(kind: ThreadKind, opening = '') {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-14'
  const feed = useFeed(fixture)
  const groups = useMemo(() => bubbles(feed.items, feed.queued, kind), [feed.items, feed.queued, kind])

  // Showing a conversation is reading it — and the SERVER is told, for this guard on this phone. Captured first
  // (so it survives no signal), sent when it can be; each message is asked about once, whatever the answer.
  // `asking` only covers the moment between capturing the act and the queue showing it — it is not a read marker.
  const asking = useRef(new Set<number>())
  useEffect(() => {
    if (fixture) return
    const ids = toMarkRead(feed.items, kind, feed.marks).filter((id) => !asking.current.has(id))
    if (ids.length === 0) return
    ids.forEach((id) => asking.current.add(id))
    void submit({ endpoint: 'messages.read', body: { ids }, label: `Messages read · ${THREAD_NAME[kind]}`, kind: 'message' }).catch(() => ids.forEach((id) => asking.current.delete(id)))
  }, [fixture, feed.items, feed.marks, kind])

  const [draft, setDraft] = useState(kind === 'dispatch' ? opening.slice(0, 500) : '')
  const [problem, setProblem] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  const send = useCallback(async () => {
    if (fixture || kind !== 'dispatch' || sending) return
    const invalid = validateBody(draft)
    setProblem(invalid)
    if (invalid) return
    setSending(true)
    try {
      const body = draft.trim()
      await submit({ endpoint: 'messages.store', body: { body }, label: `Message to dispatch: ${body.length > 40 ? `${body.slice(0, 40)}…` : body}`, kind: 'message' })
      setDraft('')
    } catch {
      setProblem('This message could not be saved on the phone. Check the phone has free storage and try again.')
    } finally {
      setSending(false)
    }
  }, [fixture, kind, sending, draft])

  return { ...feed, fixture, groups, canWrite: kind === 'dispatch', draft, setDraft: (text: string) => { setDraft(text); if (problem) setProblem(null) }, problem, sending, send: () => void send() }
}
