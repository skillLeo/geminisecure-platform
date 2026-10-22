/**
 * "All meetings" — every meeting the estate has published to this household. No board draws it.
 *
 *   READ     `meetings.index`: the SAME first page, under the SAME cache key, that Home and the lobby read — so the
 *            list opens with no connection and says so, and a 403 (the estate's latest word about this account)
 *            outranks the saved copy.
 *   OLDER    the server hands its history over a page at a time (`before` / `next_before`). "Show older meetings"
 *            asks for the page before the last one held; what comes back is kept beside the first page
 *            (`meetings.older`), so it too is there offline — and the lobby finds an old meeting in it by id.
 *            Asking needs a connection and says so; a page that brings nothing new ends the list rather than
 *            leaving a button that does nothing (core: olderPages.ts).
 *   OPEN     every row opens the existing lobby, `/meetings/[id]`.
 *   THE CLOCK moves "Starting soon" / "Happening now" without a refresh, as the lobby's does.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { isFidelity, now } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { addOlderPage, olderCursor, type OlderPages, type PageAnswer } from '@gs/core/lib'
import { END_OF_LIST, olderBlocked, sectionsOf, type Section } from './meetingList'
import { staleLine, type Meeting } from './meetings'
import { useMeetingList, type ListState } from './useMeetings'

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

export type AllMeetingsModel = {
  status: ListState['status']
  errorMessage: string | null
  sections: Section[]
  /** Why this is the saved copy — null when it is fresh. */
  staleNote: string | null
  older: OlderModel
  /** Said once the server has nothing older. */
  endNote: string | null
  refreshing: boolean
  refresh: () => void
}

function useClock(): Date {
  const [at, setAt] = useState(now)
  useEffect(() => {
    if (isFidelity) return
    const timer = setInterval(() => setAt(now()), 30_000)
    return () => clearInterval(timer)
  }, [])
  return at
}

export function useAllMeetings(): AllMeetingsModel {
  const [fetched, setFetched] = useState<OlderPages<Meeting> | undefined>(undefined)
  const list = useMeetingList(isFidelity, fetched)
  const at = useClock()
  const [ask] = residentApi.useLazyMeetingsIndexQuery()
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
      .then((page: PageAnswer<Meeting>) => setFetched(addOlderPage<Meeting>({ firstNext, first, older, asked: cursor, page })))
      .catch((error: unknown) => setProblem(isApiError(error) && typeof error.status === 'number' ? error.message : 'Older meetings could not be fetched just now. Check your connection and try again.'))
      .finally(() => {
        asking.current = false
        setLoading(false)
      })
  }, [ask, cursor, first, firstNext, older, online])

  const sections = useMemo(() => (list.status === 'ready' ? sectionsOf(list.items, list.ops, at) : []), [list.status, list.items, list.ops, at])
  const ended = list.status === 'ready' && cursor === null && older !== null && older.items.length > 0

  return {
    status: list.status,
    errorMessage: list.errorMessage,
    sections,
    staleNote: list.stale ? staleLine(list.online, 'Dates, places and replies') : null,
    older: { available: list.status === 'ready' && cursor !== null, blocked: olderBlocked(online), loading, problem, load },
    endNote: ended ? END_OF_LIST : null,
    refreshing: list.refreshing,
    refresh: list.refresh,
  }
}
