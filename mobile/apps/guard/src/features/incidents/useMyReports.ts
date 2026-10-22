/**
 * My Reports — board guard-app-23. The guard's own incident reports from the last 90 days.
 *
 *   THE SERVER'S LIST   incidents.me, kept on the phone (useOfflineCache) so it is there with no signal.
 *   THIS PHONE'S OWN    reports still in the outbox are merged on top: Pending sync / Syncing in amber,
 *                       and a refused one as Not accepted with the server's sentence. Tapping one opens
 *                       the sync queue. (The store mirrors the outbox without bodies; the bodies — what
 *                       kind, what was written — are read from the outbox itself whenever it changes.)
 *   STATUS              the server's two values, in the board's chips: open → amber, resolved → green.
 *
 * Append-only: no row can be edited or deleted, here or anywhere.
 */

import { useCallback, useMemo, useState } from 'react'
import { fidelityScreenId, isFidelity, now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { myReportsFixture } from '@/fidelity/incidents'
import { drain, drainNow, outbox } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { mergeReports, type ReportRow } from './incidents'

export type MyReportsModel = {
  status: 'loading' | 'error' | 'forbidden' | 'ready'
  errorMessage: string | null
  rows: ReportRow[]
  /** An honest line when what is shown is not fresh from the server. */
  staleNote: string | null
  refreshing: boolean
  refresh: () => void
}

export function useMyReports(): MyReportsModel {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-23' ? myReportsFixture : null
  const online = useAppSelector((s) => s.network.online)
  const mirrored = useAppSelector((s) => s.outbox.ops)

  const query = guardApi.useIncidentsMeQuery(undefined, { skip: !!fixture })
  const cached = useOfflineCache('incidents.me', query.data)
  const items = fixture ? fixture.items : cached?.items
  const fresh = !!query.data && !query.isError

  // `mirrored` is the signal that the outbox changed; the records themselves (with their bodies) come from the outbox.
  const queued = useMemo(() => (fixture ? [] : outbox.all().filter((op) => op.endpoint === 'incidents.store' || op.endpoint === 'incidents.media')), [fixture, mirrored])
  const rows = useMemo(() => mergeReports({ items: items ?? [], ops: queued, nowDate: now() }), [items, queued])

  const [refreshing, setRefreshing] = useState(false)
  const refetch = query.refetch
  const refresh = useCallback(async () => {
    if (fixture) return
    setRefreshing(true)
    try {
      // Pulling the list down is also a reason to send what is waiting.
      await drainNow()
      await refetch()
    } finally {
      setRefreshing(false)
    }
  }, [fixture, refetch])

  const error = query.error
  const forbidden = isApiError(error) && error.status === 403
  const status: MyReportsModel['status'] = fixture ? 'ready'
    : items || rows.length > 0 ? 'ready'
    : query.isLoading ? 'loading'
    : forbidden ? 'forbidden'
    : error ? 'error'
    : 'ready'

  const staleNote = fixture || fresh || query.isLoading ? null
    : forbidden ? 'This handset is not allowed to read your reports, so only what is saved on this phone is shown.'
    : items ? `${online ? 'Your reports could not be refreshed' : 'No signal'}: showing the last list this phone received. Reports you have not sent yet are marked Pending sync.`
    : `${online ? 'Your reports could not be loaded' : 'No signal: your reports have not reached this phone'}. Reports you file are kept here and marked Pending sync.`

  return {
    status,
    errorMessage: isApiError(error) ? error.message : null,
    rows,
    staleNote,
    refreshing,
    refresh: () => void refresh(),
  }
}
