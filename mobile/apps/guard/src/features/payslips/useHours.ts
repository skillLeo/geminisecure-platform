/**
 * My Hours — board guard-app-19. The guard's shifts in one Monday-to-Sunday week (Jamaica), with the
 * server's hours worked and night hours. Hours, never pay: nothing on this screen is money, so it is
 * kept on the phone like any other list (useOfflineCache) and is there with no signal.
 *
 * The server's range is in UTC, so a day either side is asked for and the week is cut on the phone.
 */

import { useCallback, useMemo, useState } from 'react'
import { fidelityScreenId, isFidelity, now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { useAppSelector } from '@/store'
import { dayRows, queryRange, weekLabel, weekOf, weekTotals } from './payslips'

/** How far back the week can be stepped: the server keeps a guard's shifts, the phone asks for a quarter. */
const WEEKS_BACK = 13

export function useHours() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-19'
  const online = useAppSelector((s) => s.network.online)
  const [offset, setOffset] = useState(0)
  const week = useMemo(() => weekOf(now(), offset), [offset])
  const range = useMemo(() => queryRange(week), [week])

  const query = guardApi.useShiftsMeQuery(range, { skip: fixture })
  // `query.data` can briefly be the PREVIOUS week's while the next one loads: only keep what matches this range.
  const matching = query.data && query.data.from === range.from && query.data.to === range.to ? query.data : undefined
  const cached = useOfflineCache(`shifts.me:${week.from}`, matching)
  const items = cached?.items

  const rows = useMemo(() => dayRows(items ?? [], week, now()), [items, week])
  const totals = useMemo(() => weekTotals(items ?? [], week), [items, week])

  const [refreshing, setRefreshing] = useState(false)
  const refetch = query.refetch
  const refresh = useCallback(async () => {
    if (fixture) return
    setRefreshing(true)
    try { await refetch() } finally { setRefreshing(false) }
  }, [fixture, refetch])

  const error = query.error
  const forbidden = isApiError(error) && error.status === 403
  const fresh = !!matching && !query.isError
  const status: 'loading' | 'error' | 'forbidden' | 'ready' = fixture || items ? 'ready' : query.isFetching ? 'loading' : forbidden ? 'forbidden' : error ? 'error' : 'loading'
  const staleNote = fixture || fresh || query.isFetching || !items ? null : `${online ? 'Your hours could not be refreshed' : 'No signal'}: showing what this phone last received for this week.`

  return {
    fixture,
    status,
    errorMessage: isApiError(error) ? error.message : null,
    label: weekLabel(week),
    thisWeek: offset === 0,
    rows,
    totals,
    earlier: offset > -WEEKS_BACK ? () => setOffset(offset - 1) : null,
    later: offset < 0 ? () => setOffset(offset + 1) : null,
    staleNote,
    refreshing,
    refresh: () => void refresh(),
  }
}
