/**
 * Election results — board resident-app-28. ONLY when the server gives them: `elections.results`
 * answers `409 results_not_published` until the result is certified and published, and until then
 * the screen says "Results are published after voting closes" and shows nothing else — no live
 * tally, no turnout, no hint.
 *
 * A published result is public and may be served from the cache once fetched (the contract says
 * so); this is the ONE file in the feature that keeps anything, and what it keeps is the
 * certified count — never a paper. It does not import the marks.
 *
 * THE BOARD draws per-phase turnout bars, a crown on each winner, a line under each name and
 * "Verify your vote was counted". The server's result has counts per option, turnout and quorum —
 * no phases, no named winner, no receipt codes. Live, the screen shows what the server gives, in
 * order of marks, with "Certified Sep 28, 8:02 PM" in the board's words; the phase bars, the crowns
 * and the lines under the names are the board's sample, in fidelity mode only.
 */

import { useCallback, useState } from 'react'
import { isFidelity, useOfflineCache } from '@gs/core'
import type { ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { resultsFixture } from '@/fidelity/elections'
import { RESULTS_NOT_YET, certifiedShort, resultsView, type ResultsView } from './paper'

export type Phase = { label: string; percent: number }

export type ResultsModel = {
  status: 'loading' | 'not-yet' | 'missing' | 'denied' | 'error' | 'ready'
  message: string | null
  view: ResultsView | null
  /** Board only: the server's result has no per-phase turnout. */
  phases: readonly Phase[]
  refreshing: boolean
  refresh: () => void
}

export function useResults(id: string | undefined): ResultsModel {
  const skip = isFidelity || !id
  const q = residentApi.useElectionsResultsQuery({ ballot: id ?? '' }, { skip })
  // `currentData`, not `data`: `data` still holds the LAST election's result while another is being asked for,
  // and would be saved under this election's key.
  const cached = useOfflineCache(`elections.results.${id ?? 'none'}`, skip ? undefined : q.currentData)
  const [refreshing, setRefreshing] = useState(false)

  const refetch = q.refetch
  const refresh = useCallback(() => {
    if (skip) return
    setRefreshing(true)
    void refetch().finally(() => setRefreshing(false))
  }, [skip, refetch])

  const base = { message: null, view: null, phases: [], refreshing, refresh }

  if (isFidelity) {
    const f = resultsFixture
    return {
      ...base,
      status: 'ready',
      phases: f.phases,
      view: { badge: f.badge, title: f.title, certified: f.certified, turnoutCast: f.turnoutCast, turnoutRest: f.turnoutRest, turnoutPercent: f.turnoutPercent, quorum: null, statement: null, groups: f.groups.map((g) => ({ key: g.key, heading: g.heading, rows: g.rows.map((r) => ({ ...r })) })) },
    }
  }
  if (!id) return { ...base, status: 'missing' }

  const error = q.isError ? (q.error as ApiError) : null
  // The server's "not yet" outranks anything this phone remembers.
  if (error?.code === 'results_not_published') return { ...base, status: 'not-yet', message: RESULTS_NOT_YET }
  if (error?.status === 404) return { ...base, status: 'missing' }
  if (error?.status === 403) return { ...base, status: 'denied' }

  // A result opened on this phone before is shown from its saved copy until the server answers again.
  if (cached && String(cached.id) === id) return { ...base, status: 'ready', view: { ...resultsView(cached), certified: certifiedShort(cached) } }
  if (error) return { ...base, status: 'error', message: error.status === 'network' || error.status === 'timeout' ? 'The results could not be loaded: there is no connection. Once they have been opened on this phone they can be read without one.' : error.message }
  return { ...base, status: 'loading' }
}
