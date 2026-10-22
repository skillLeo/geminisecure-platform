/**
 * The profile's "Upcoming shifts" (M12 §3.3): shifts.me for the next fourteen days, kept on the phone like My Hours
 * so the list is there with no signal, with its age said when it is a saved copy. A refusal outranks the copy.
 */

import { useCallback, useMemo } from 'react'
import { now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { useAppSelector } from '@/store'
import { upcomingRange, upcomingShifts, type UpcomingRow } from './upcoming'

export type UpcomingModel = {
  status: 'loading' | 'ready' | 'error' | 'forbidden'
  rows: UpcomingRow[]
  staleNote: string | null
  refresh: () => void
}

export function useUpcomingShifts(skip: boolean): UpcomingModel {
  const online = useAppSelector((s) => s.network.online)
  // The day turns over while the screen is open far less often than it is opened: the range is fixed per mount.
  const range = useMemo(() => upcomingRange(now()), [])
  const query = guardApi.useShiftsMeQuery(range, { skip })
  const matching = query.data && query.data.from === range.from && query.data.to === range.to ? query.data : undefined
  const cached = useOfflineCache('shifts.me.upcoming', matching)

  const forbidden = isApiError(query.error) && query.error.status === 403
  const items = forbidden ? undefined : cached?.items
  const rows = useMemo(() => upcomingShifts(items ?? [], now()), [items])

  const refetch = query.refetch
  const refresh = useCallback(() => { if (!skip) void refetch() }, [skip, refetch])

  const status: UpcomingModel['status'] = skip || items ? 'ready' : forbidden ? 'forbidden' : query.isError ? 'error' : 'loading'
  const fresh = !!matching && !query.isError
  const staleNote = skip || fresh || query.isFetching || !items ? null : `${online ? 'Your shifts could not be refreshed' : 'No signal'}: showing what this phone last received.`

  return { status, rows, staleNote, refresh }
}
