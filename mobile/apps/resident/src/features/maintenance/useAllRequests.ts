/**
 * "All requests" — everything this household has sent the estate office, newest first. No board draws it.
 *
 *   READ     `tickets.index`: the SAME first page, under the SAME cache key, that "Your requests", "Your reports" and
 *            both status screens read — so the list opens with no connection and says so, and a 403 (the estate's
 *            latest word about this account) outranks the saved copy.
 *   BOTH     the server files maintenance requests AND security reports as tickets. Both are listed, each marked as
 *            what it is, each opening the screen that already shows it (requestList.ts says how they are told apart).
 *   UNSENT   what is still on this phone heads the list — Pending sync · Syncing · Not accepted, in those words —
 *            and opens nothing, because the server has given it no id.
 *   OLDER    the server hands its history over a page at a time (`before` / `next_before`). "Show older requests"
 *            asks for the page before the last one held; what comes back is kept beside the first page
 *            (`tickets.older`), so it too is there offline — and both status screens find an old ticket in it.
 *            Asking needs a connection and says so; a page that brings nothing new ends the list rather than
 *            leaving a button that does nothing (core: olderPages.ts).
 */

import { useCallback, useMemo, useRef, useState } from 'react'
import { useLocalSearchParams } from 'expo-router'
import { isFidelity, now } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { addOlderPage, olderCursor, type OlderPages, type PageAnswer } from '@gs/core/lib'
import { useAccess } from '@/features/access/useAccess'
import { outbox } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { END_OF_LIST, REPORT_KIND, REQUEST_KIND, TAB_LABEL, allRows, homeSummary, isOpenRow, olderBlocked, sectionsOf, tabRows, type ListOp, type RequestRow, type Section, type Tab } from './requestList'
import type { Ticket } from './tickets'
import { useTicketList, type ListState } from './useTickets'

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

export type AllRequestsModel = {
  status: ListState['status']
  errorMessage: string | null
  /** The two tabs (M98): Maintenance and Incidents, each with how many are open. */
  tab: Tab
  tabs: { key: Tab; label: string; open: number }[]
  setTab: (tab: Tab) => void
  /** The chosen tab's rows, open first and then past, newest first. */
  sections: Section[]
  rows: RequestRow[]
  /** Nothing at all has ever been sent (as against: nothing in this tab). */
  nothingYet: boolean
  /** Why this is the saved copy — null when it is fresh. */
  staleNote: string | null
  older: OlderModel
  endNote: string | null
  refreshing: boolean
  refresh: () => void
}

export function useAllRequests(): AllRequestsModel {
  const [fetched, setFetched] = useState<OlderPages<Ticket> | undefined>(undefined)
  const list = useTicketList(isFidelity, fetched)
  const view = useAppSelector((s) => s.outbox.ops)
  const [ask] = residentApi.useLazyTicketsIndexQuery()
  const [loading, setLoading] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const [picked, setPicked] = useState<Tab | null>(null)
  const asking = useRef(false)

  // Both kinds of act: what the maintenance form queued, and what the Report-incident screens queued.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const ops = useMemo<readonly ListOp[]>(() => (isFidelity ? [] : outbox.all().filter((op) => op.kind === REQUEST_KIND || op.kind === REPORT_KIND)), [view])

  const { first, firstNext, older, online } = list
  const cursor = olderCursor(older, firstNext)

  const load = useCallback(() => {
    if (asking.current || cursor === null || firstNext === null || !online) return
    asking.current = true
    setLoading(true)
    setProblem(null)
    ask({ before: cursor }, false)
      .unwrap()
      .then((page: PageAnswer<Ticket>) => setFetched(addOlderPage<Ticket>({ firstNext, first, older, asked: cursor, page })))
      .catch((error: unknown) => setProblem(isApiError(error) && typeof error.status === 'number' ? error.message : 'Older requests could not be fetched just now. Check your connection and try again.'))
      .finally(() => {
        asking.current = false
        setLoading(false)
      })
  }, [ask, cursor, first, firstNext, older, online])

  // What is still on this phone is shown whatever the list's state — it is this phone's own, and a 403 does not unsay it.
  const all = useMemo(() => allRows({ items: list.status === 'ready' ? list.all : [], ops, at: now() }), [list.status, list.all, ops])
  // The tab the resident chose; until they choose, Maintenance, or Incidents when that is all this household has filed.
  const asked = useLocalSearchParams<{ tab?: string }>().tab
  const tab: Tab = picked ?? (asked === 'report' ? 'report' : asked === 'request' ? 'request' : all.some((row) => row.kind === 'request') || !all.some((row) => row.kind === 'report') ? 'request' : 'report')
  const rows = useMemo(() => tabRows(all, tab), [all, tab])
  const sections = useMemo(() => sectionsOf(rows), [rows])
  const tabs = useMemo(() => (['request', 'report'] as const).map((key) => ({ key, label: TAB_LABEL[key], open: tabRows(all, key).filter(isOpenRow).length })), [all])

  const ended = list.status === 'ready' && cursor === null && older !== null && older.items.length > 0

  return {
    status: list.status,
    errorMessage: list.errorMessage,
    tab,
    tabs,
    setTab: setPicked,
    sections,
    rows,
    nothingYet: all.length === 0,
    staleNote: list.stale ? `${online ? 'Your requests could not be checked with the estate just now' : 'No connection'}: this is the list saved on this phone. A status may have moved on since.` : null,
    older: { available: list.status === 'ready' && cursor !== null, blocked: olderBlocked(online), loading, problem, load },
    endNote: ended ? END_OF_LIST : null,
    refreshing: list.refreshing,
    refresh: list.refresh,
  }
}

/**
 * HOME'S "My requests" CARD (M98): the same list, summarised — "2 open · #1055 In progress" — or nothing at all for a
 * household that has never filed anything (and while the first answer is still on its way). Reads the SAME cache as the
 * hub, so it is there offline, and a 403 hides it.
 */
export function useRequestsSummary(skipAsked: boolean): { line: string; open: number; waitingOnYou: boolean } | null {
  // The design-comparison build draws no such card, and asks the estate nothing.
  const skip = skipAsked || isFidelity
  const list = useTicketList(skip)
  const view = useAppSelector((s) => s.outbox.ops)
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const ops = useMemo<readonly ListOp[]>(() => (skip ? [] : outbox.all().filter((op) => op.kind === REQUEST_KIND || op.kind === REPORT_KIND)), [view, skip])
  return useMemo(() => (list.status === 'denied' ? null : homeSummary(allRows({ items: list.status === 'ready' ? list.all : [], ops, at: now() }))), [list.status, list.all, ops])
}
/** Whether this account is shown a way into "My requests" (Settings): not the design-comparison build, and not an account that may not use requests. */
export function useRequestsEntry(): boolean {
  const { may } = useAccess()
  return !isFidelity && may('tickets')
}