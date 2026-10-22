/**
 * The estate's notices — boards resident-app-12 (the list, a tab) and -36 (one notice).
 *
 *   READ     `notices.index`, cached (`useOfflineCache`, the same key Home reads): the contract says
 *            "Serve the cache", so the list and every notice in it open with no connection. The note
 *            above a saved list gives the TRUE reason it is not fresh ("No connection" only when that is
 *            it), and a 403 — the server's latest word about this account — outranks the saved list.
 *   ONE      there is no `notices.show`: a notice is found BY ID in that list.
 *   READING  opening a notice IS reading it — the contract has no acknowledgement flag, so there is no
 *            "I have read this" button. `notices.read` is a queueable write ("Offline: Queue"), so it goes
 *            through `submit()` — captured on the phone first, sent when it can be, once per notice.
 *            Until the server answers, the phone says "Pending sync" and never "Marked as read": the dot
 *            leaves the card in THIS phone's mirror only.
 *   SEEN BY  the server's own count of HOUSEHOLDS ("seen by 212 of 450 households") follows "Marked as
 *            read" — from its answer to this phone's read when there is one (it already counts this household),
 *            else from the list. A list saved before the server sent a count simply has no figure to print.
 *   FILES    the files the office put with a notice are rows under its text (useAttachments.ts): fetched with
 *            the phone's token, opened with the phone's share sheet, and honest about every way that can fail.
 *   NOTHING ELSE. The server sends no topic and no acknowledgement flag. Neither is drawn live.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { AppState } from 'react-native'
import { useFocusEffect } from 'expo-router'
import { fidelityScreenId, isFidelity, now, useOfflineCache, type IconName } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { addOlderPage, mergeById, olderCursor, olderFor, type OlderPages, type PageAnswer } from '@gs/core/lib'
import { noticeFixture, noticesFixture } from '@/fidelity/notices'
import { useDemoRefusal } from '@/features/auth/useDemo'
import { drain, outbox, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { ALL, DESIGN_FILTERS, attachmentsOf, bylineOf, dayKey, deniedSentence, designFilter, findNotice, initialsOf, isUnread, listStatusOf, mootRefusals, newestFirst, paragraphs, postedCard, postedDetail, postedFull, previewOf, readStateOf, seenLine, shouldQueueRead, stalenessOf, type Filter, type ListFailure, type ListStatus, type Notice, type ReadOp, type ReadState, type Staleness, type Tag } from './notices'
import { useAttachments } from './useAttachments'

const readOps = (): ReadOp[] => outbox.all().filter((op) => op.endpoint === 'notices.read')

/* ---- the list the phone holds ------------------------------------------------------------------- */

type ListState = {
  status: ListStatus
  errorMessage: string | null
  items: readonly Notice[]
  ops: readonly ReadOp[]
  /** Why a saved list is on screen instead of a fresh one — `null` when it is fresh. */
  staleness: Staleness
  /** A request for the list is in flight: "not in the list" is not yet an answer. */
  fetching: boolean
  refreshing: boolean
  refresh: () => void
  /**
   * The newest page alone, where the page before it starts (null: nothing older, or a list saved by a
   * build that did not page), and the chain fetched beyond it while it still belongs to TODAY's first
   * page (core: olderPages.ts). `items` is both together — so a notice opened from an older page is
   * found by `useNotice` exactly like any other, and so is its file.
   */
  first: readonly Notice[]
  firstNext: string | null
  older: OlderPages<Notice> | null
  online: boolean
}

function useNoticeList(skip: boolean, fetchedOlder?: OlderPages<Notice>): ListState {
  const online = useAppSelector((s) => s.network.online)
  const view = useAppSelector((s) => s.outbox.ops)
  const query = residentApi.useNoticesIndexQuery(undefined, { skip })
  const cached = useOfflineCache('notices.index', query.data)
  const savedOlder = useOfflineCache<OlderPages<Notice>>('notices.older', fetchedOlder)
  const [refreshing, setRefreshing] = useState(false)

  const failure: ListFailure = isApiError(query.error) ? query.error : null
  const failed = !!query.error
  const status = listStatusOf({ hasList: !!cached, failed, failure })

  // The store mirrors the queue without params or answers; the queue itself has both.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const ops = useMemo<readonly ReadOp[]>(() => (skip ? [] : readOps()), [view, skip])
  // A 403 outranks the saved list: nothing saved is handed on once the server has said this account may not see it.
  const ready = status === 'ready'
  const firstNext = ready ? (cached?.next_before ?? null) : null
  const first = useMemo<readonly Notice[]>(() => (ready ? (cached?.items ?? []) : []), [ready, cached])
  const older = useMemo(() => (ready ? olderFor(savedOlder, firstNext) : null), [ready, savedOlder, firstNext])
  const items = useMemo(() => newestFirst(older ? mergeById(first, older.items) : first), [first, older])

  const refetch = query.refetch
  const refresh = useCallback(() => {
    if (skip) return
    setRefreshing(true)
    void drain()
    void refetch().finally(() => setRefreshing(false))
  }, [refetch, skip])

  // A refused read the server's FRESH list has made moot (the notice was withdrawn, or is read) leaves the queue.
  const fresh = !skip && query.isSuccess && !query.isFetching && !failed ? query.data : undefined
  useEffect(() => {
    if (!fresh) return
    for (const id of mootRefusals(fresh.items, ops)) void outbox.dismiss(id)
  }, [fresh, ops])

  return {
    status,
    errorMessage: status === 'denied' ? deniedSentence(failure?.code ?? '') : failure ? failure.message : null,
    items,
    ops,
    staleness: status === 'ready' ? stalenessOf({ hasList: !!cached, online, failed, failure }) : null,
    fetching: !skip && query.isFetching,
    refreshing,
    refresh,
    first,
    firstNext,
    older,
    online,
  }
}

/** The site’s calendar day, re-read whenever the screen is looked at again: a tab stays mounted, and "Today" must not outlive the day. */
function useToday(): string {
  const [day, setDay] = useState(() => dayKey(now()))
  useFocusEffect(useCallback(() => setDay(dayKey(now())), []))
  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      if (state === 'active') setDay(dayKey(now()))
    })
    return () => sub.remove()
  }, [])
  return day
}

/* ---- the tab ------------------------------------------------------------------------------------- */

export type NoticeCard = { id: string; urgent: boolean; icon: IconName; tags: Tag[]; title: string; preview: string; posted: string; unread: boolean; label: string }

/** Said under the list once the server has nothing older — so the end reads as the end, not as a fault. */
export const END_OF_NOTICES = 'That is every notice your estate has posted to your household.'

export type OlderModel = {
  /** There is an older page to ask for. */
  available: boolean
  /** Why it cannot be asked for right now (no connection) — null when it can. */
  blocked: string | null
  loading: boolean
  /** The last ask failed: the server's sentence, or a plain one. */
  problem: string | null
  load: () => void
}

export type NoticeListModel = {
  status: ListState['status']
  errorMessage: string | null
  /** The design's four, always: All · Urgent · Security · Events (M42 §5 #11). */
  chips: readonly Filter[]
  filter: string
  setFilter: (key: string) => void
  /** The funnel's one choice: unread notices only. */
  unreadOnly: boolean
  setUnreadOnly: (on: boolean) => void
  cards: NoticeCard[]
  /**
   * "Show older notices". The list answers with the newest hundred; on an estate
   * that posts often the hundred-and-first used to be unreachable from the app altogether — its file
   * and its read still worked BY ID, which nothing on a screen could ever reach.
   */
  older: OlderModel
  refreshing: boolean
  refresh: () => void
}

export function useNotices(): NoticeListModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-12' ? noticesFixture : null
  const [fetched, setFetched] = useState<OlderPages<Notice> | undefined>(undefined)
  const list = useNoticeList(isFidelity, fetched)
  const today = useToday()
  const [picked, setPicked] = useState<string>(ALL.key)
  const [ask] = residentApi.useLazyNoticesIndexQuery()
  const [loading, setLoading] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const asking = useRef(false)

  const { first, firstNext, older, online } = list
  const cursor = olderCursor(older, firstNext)

  const load = useCallback(() => {
    if (asking.current || cursor === null || firstNext === null || !online) return
    asking.current = true
    setLoading(true)
    setProblem(null)
    ask({ before: cursor }, false)
      .unwrap()
      .then((page: PageAnswer<Notice>) => setFetched(addOlderPage<Notice>({ firstNext, first, older, asked: cursor, page })))
      .catch((error: unknown) => setProblem(isApiError(error) && typeof error.status === 'number' ? error.message : 'Older notices could not be fetched just now. Check your connection and try again.'))
      .finally(() => {
        asking.current = false
        setLoading(false)
      })
  }, [ask, cursor, first, firstNext, older, online])

  const items: readonly Notice[] = fixture ? fixture.items : list.items
  const ops = list.ops
  const [unreadOnly, setUnreadOnly] = useState(false)

  const filter = DESIGN_FILTERS.some((chip) => chip.key === picked) ? picked : ALL.key

  /*
   * A notice's TOPIC ("Security", "Events", "Maintenance", "Governance") is not sent by the server yet: a notice is
   * general or urgent. So live a card carries only "Urgent" when it is, and its picture is the warning sign or the
   * megaphone; the design's own topics and pictures come from its fixture, in fidelity mode only.
   */
  const cards = useMemo<NoticeCard[]>(() => {
    const at = now()
    const topicsOf = (n: Notice): string[] => fixture?.board.cards[String(n.id)]?.tags.filter((t) => !t.urgent).map((t) => t.label) ?? []
    return designFilter(items, filter, topicsOf, unreadOnly, ops).map((n) => {
      const id = String(n.id)
      const drawn = fixture?.board.cards[id]
      const urgent = n.kind === 'urgent'
      const unread = isUnread(n, ops)
      const posted = postedCard(n.published_at, at)
      return {
        id,
        urgent,
        icon: drawn?.icon ?? (urgent ? 'qa-tile-3' : 'tab-notices'),
        tags: drawn ? [...drawn.tags] : urgent ? [{ label: 'Urgent', urgent: true }] : [],
        title: n.title,
        preview: previewOf(n.body),
        posted,
        unread,
        // Board 12 draws no attachment mark on a card, so none is drawn — but a screen reader is told before opening it.
        label: `${unread ? 'Unread. ' : ''}${urgent ? 'Urgent. ' : ''}${n.title}. ${posted}${attachmentsOf(n).length > 0 ? `. Has ${attachmentsOf(n).length === 1 ? 'a file' : `${attachmentsOf(n).length} files`} attached` : ''}`,
      }
    })
    // `today` is a dependency on purpose: "Today · 8:15 AM" is rewritten once the site's day has turned.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fixture, items, filter, unreadOnly, ops, today])

  return {
    status: fixture ? 'ready' : list.status,
    errorMessage: list.errorMessage,
    chips: DESIGN_FILTERS,
    filter,
    setFilter: setPicked,
    unreadOnly,
    setUnreadOnly,
    cards,
    older: {
      available: !fixture && list.status === 'ready' && cursor !== null,
      // Older pages are fetched from the estate: there is no saved copy to fall back on, and the control says so.
      blocked: online ? null : 'Older notices are fetched from your estate, so they need a connection.',
      loading,
      problem,
      load,
    },
    refreshing: list.refreshing,
    refresh: list.refresh,
  }
}

/* ---- one notice ---------------------------------------------------------------------------------- */

/**
 * The line under the rule (board 36's `.seen-row`). `attention`: it is not the server's settled word — the read is still
 * on this phone, or was refused — and is drawn in the design's attention ink rather than its quiet grey.
 */
export type ReadLine = { text: string; icon: IconName; attention: boolean }

/**
 * The line under the notice. Only the server's answer is ever called "Marked as read"; a read still on
 * this phone says so whatever the connection LOOKS like — a phone can believe it is online and not be.
 */
function readLine(state: ReadState, saveFailed: boolean, at: Date): ReadLine | null {
  switch (state.kind) {
    case 'server':
      // The board's line: "Marked as read · seen by 212 of 450 households". Without a count (a list saved by an older
      // build), the line says WHEN instead — never a figure the server did not send.
      return { text: state.seen ? `Marked as read · ${seenLine(state.seen)}` : state.at ? `Marked as read · ${postedFull(state.at, at)}` : 'Marked as read', icon: 'why-note', attention: false }
    case 'pending':
      return { text: "Pending sync · read on this phone. The estate has not been told yet: it is sent when you're connected.", icon: 'offline-note', attention: true }
    case 'syncing':
      return { text: 'Syncing · telling the estate you have read this', icon: 'offline-note', attention: true }
    case 'refused':
      return { text: `Not accepted · ${state.message}`, icon: 'qa-tile-3', attention: true }
    case 'unread':
      return saveFailed ? { text: 'This phone could not record that you read this. Open the notice again later.', icon: 'qa-tile-3', attention: true } : null
  }
}

/** A file the office put with the notice, as its row draws it. Opening it is useAttachments.ts, unchanged. */
export type NoticeFile = {
  key: string
  name: string
  /** For a screen reader: what it is, how big, and what pressing does. */
  label: string
  busy: boolean
  /** What happened the last time it was pressed — `attention` unless it is simply being fetched. */
  note: { text: string; attention: boolean } | null
  open: () => void
}

/** openSentence's one quiet sentence ("Fetching the file from your estate…"); every other one needs attention. */
const QUIET_NOTE = 'slate-600'

export type NoticeModel = {
  status: 'loading' | 'ready' | 'missing' | 'error' | 'denied'
  errorMessage: string | null
  notice: Notice | null
  /**
   * The gold pill above the title. The server sends no TOPIC ("Security", "Events") — a notice is general or urgent —
   * so live the pill is "Urgent" when it is, and a general notice has none; the board's "Urgent · Security" in fidelity.
   */
  tag: string | null
  title: string
  initials: string
  /** "Posted by Property Manager · Today, 8:15 AM" */
  meta: string
  paragraphs: string[]
  /** The files the office put with the notice — a row each, under its text. */
  attachments: NoticeFile[]
  read: ReadLine | null
  /** The list on this phone is a saved one — so "not found" means "not saved here", not "withdrawn". */
  stale: boolean
  refreshing: boolean
  refresh: () => void
}

export function useNotice(id: string | undefined): NoticeModel {
  const fixture = isFidelity && fidelityScreenId() === 'resident-app-36' ? noticeFixture : null
  const list = useNoticeList(isFidelity)
  const refusal = useDemoRefusal()
  const [saveFailed, setSaveFailed] = useState(false)

  const notice: Notice | null = fixture ? fixture.notice : findNotice(id, list.items)

  // Opening is reading. Captured once per notice; the queue is asked directly, so a second mount cannot double it.
  const queuedFor = useRef<string | null>(null)
  const noticeId = notice ? String(notice.id) : null
  const unreadOnServer = !!notice && !notice.read_at
  useEffect(() => {
    if (isFidelity || !notice || noticeId === null || !unreadOnServer || queuedFor.current === noticeId) return
    /*
     * A DEMONSTRATION SESSION SAYS NOTHING HERE, and says nothing ABOUT saying nothing.
     * Marking a notice read is the only write in this app nobody asked for — it happens because
     * the notice was opened — so a refusal sentence would be the app volunteering an error the
     * reader did not cause. It is simply not attempted, and no "could not be saved" is drawn.
     */
    if (refusal) return
    if (!shouldQueueRead(notice, readOps())) return
    queuedFor.current = noticeId
    setSaveFailed(false)
    submit({ endpoint: 'notices.read', params: { notice: notice.id }, label: `Notice read · ${notice.title}`, kind: 'notice' }).catch(() => {
      queuedFor.current = null
      setSaveFailed(true)
    })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [noticeId, unreadOnServer, refusal])

  // Opening a file is exactly as it was (useAttachments.ts); only the colour of its sentence is said here as a meaning.
  const opened = useAttachments(notice)
  const attachments = useMemo<NoticeFile[]>(
    () => opened.map((file) => ({ key: file.key, name: file.name, label: file.label, busy: file.busy, open: file.open, note: file.note ? { text: file.note.text, attention: file.note.tone !== QUIET_NOTE } : null })),
    [opened],
  )

  useToday() // re-renders once the site’s day has turned, so "Today, 8:15 AM" does not outlive the day
  const at = now()
  const byline = notice ? bylineOf(notice) : ''

  return {
    // While the list is still being fetched, "not in the saved list" is not yet "not found": a notice posted since the last save is on its way.
    status: notice ? 'ready' : list.status === 'ready' ? (list.fetching ? 'loading' : 'missing') : list.status,
    errorMessage: list.errorMessage,
    notice,
    tag: fixture ? fixture.board.tag : notice?.kind === 'urgent' ? 'Urgent' : null,
    title: notice?.title ?? '',
    initials: initialsOf(byline),
    meta: notice ? `Posted by ${byline} · ${postedDetail(notice.published_at, at)}` : '',
    paragraphs: notice ? paragraphs(notice.body) : [],
    attachments,
    // The board's notice carries the board's own count (212 of 450), so its line is built by the code a real one is.
    read: notice ? readLine(readStateOf(notice, list.ops), saveFailed, at) : null,
    stale: !fixture && list.staleness !== null,
    refreshing: list.refreshing,
    refresh: list.refresh,
  }
}
