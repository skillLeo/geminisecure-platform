/**
 * My Profile's live half — the guard's OWN details as the office holds them (guards.me).
 *
 *   THE SERVER'S ANSWER   name, service number, site, standing post, and the licence with its expiry.
 *   OFFLINE               the last answer, kept on the phone, shown with its age. The expiry WARNING is worked
 *                         out again from the date every time (licence.ts) — a saved "valid" goes stale.
 *   REFUSED (403)         outranks a saved copy: the phone says it may not read them, and shows none.
 *   NOTHING YET           a skeleton while loading; an error with Try again when the first load failed.
 */

import { useCallback, useState } from 'react'
import { fidelityScreenId, isFidelity, now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { dateShort, time12 } from '@gs/core/lib'
import { useAppSelector } from '@/store'
import { licenceView, todayAt, type LicenceView } from './licence'

export type ProfileModel = {
  status: 'loading' | 'ready' | 'error' | 'forbidden'
  name: string | null
  serviceNumber: string | null
  siteName: string | null
  postName: string | null
  licence: LicenceView | null
  /** Said under the rows when what is shown is a saved copy. */
  staleNote: string | null
  errorMessage: string | null
  refreshing: boolean
  refresh: () => void
}

export function useProfile(): ProfileModel {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-26'
  const online = useAppSelector((s) => s.network.online)
  const query = guardApi.useGuardsMeQuery(undefined, { skip: fixture })
  const cached = useOfflineCache('guards.me', query.data)
  const cachedAt = useOfflineCache('guards.me.at', query.data === undefined ? undefined : query.fulfilledTimeStamp)

  const [refreshing, setRefreshing] = useState(false)
  const refetch = query.refetch
  const refresh = useCallback(() => {
    if (fixture) return
    setRefreshing(true)
    void Promise.resolve(refetch()).finally(() => setRefreshing(false))
  }, [fixture, refetch])

  const error = query.error
  const forbidden = isApiError(error) && error.status === 403
  // A refusal outranks a saved copy: what the phone may no longer read, it no longer shows.
  const me = forbidden ? undefined : cached
  const fresh = !!query.data && !query.isError

  const status: ProfileModel['status'] = fixture ? 'ready' : forbidden ? 'forbidden' : me ? 'ready' : query.isLoading || query.isUninitialized ? 'loading' : error ? 'error' : 'loading'

  const staleNote = fixture || fresh || !me ? null
    : `${online ? 'These details could not be refreshed' : 'No signal'}: showing what this phone last received${typeof cachedAt === 'number' ? ` (${dateShort(new Date(cachedAt))}, ${time12(new Date(cachedAt))})` : ''}. The expiry warning is worked out from today's date.`

  return {
    status,
    name: me?.name ?? null,
    serviceNumber: me?.employee_number ?? null,
    siteName: me?.site.name ?? null,
    postName: me?.post?.name ?? null,
    licence: licenceView(me?.licence, todayAt(me?.site.timezone, now())),
    staleNote,
    errorMessage: forbidden ? 'This handset is not allowed to read your details. Contact your supervisor.' : isApiError(error) ? error.message : null,
    refreshing,
    refresh,
  }
}
