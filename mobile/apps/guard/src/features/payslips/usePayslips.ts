/**
 * Payslip — board guard-app-12. The guard's OWN payslips on approved runs.
 *
 *   THE SERVER'S FIGURES   payslips.me, and nothing derived from them (see payslips.ts).
 *   OFFLINE                the server's contract, to the letter: "Serve the cache from the secure store;
 *                          never write a payslip to shared storage." So this list is NOT put through
 *                          useOfflineCache (the shared key-value store). Each payslip is its own small
 *                          item in the Keychain / Keystore, bound to this guard, and wiped on any logout.
 *                          RTK Query's copy is memory only and is reset on logout.
 *   NEVER                  a PDF, a share sheet, a screenshot helper, a log line with a figure in it.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { fidelityScreenId, isFidelity, now } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { secureCache } from '@gs/core/auth'
import { dateShort } from '@gs/core/lib'
import { useAppSelector } from '@/store'
import { newestFirst, type Payslip } from './payslips'

const KEEP = 12
const INDEX = 'payslips.index'

async function readCache(owner: string): Promise<{ items: Payslip[]; savedAt: string } | null> {
  const index = await secureCache.get<(number | string)[]>(INDEX, owner)
  if (!index) return null
  const entries = await Promise.all(index.value.map((id) => secureCache.get<Payslip>(`payslip.${id}`, owner)))
  return { items: entries.flatMap((e) => (e ? [e.value] : [])), savedAt: index.savedAt }
}

async function writeCache(owner: string, items: Payslip[], savedAt: string): Promise<void> {
  const keep = newestFirst(items).slice(0, KEEP)
  const before = await secureCache.get<(number | string)[]>(INDEX, owner)
  for (const slip of keep) await secureCache.set(`payslip.${slip.id}`, owner, slip, savedAt)
  await secureCache.set(INDEX, owner, keep.map((s) => s.id), savedAt)
  for (const id of before?.value ?? []) if (!keep.some((s) => String(s.id) === String(id))) await secureCache.remove(`payslip.${id}`)
}

export function usePayslips() {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-12'
  const online = useAppSelector((s) => s.network.online)
  const owner = useAppSelector((s) => s.session.principal?.reference) ?? null
  const query = guardApi.usePayslipsMeQuery(undefined, { skip: fixture })

  const [cached, setCached] = useState<{ items: Payslip[]; savedAt: string } | null>(null)
  const [cacheRead, setCacheRead] = useState(fixture)

  useEffect(() => {
    if (fixture || !owner) return
    let alive = true
    void readCache(owner).then((hit) => { if (alive) { setCached(hit); setCacheRead(true) } }).catch(() => { if (alive) setCacheRead(true) })
    return () => { alive = false }
  }, [fixture, owner])

  const data = query.data
  useEffect(() => {
    if (fixture || !owner || !data) return
    // A failure to cache is not a failure to show: the figures on screen are the server's either way.
    void writeCache(owner, data.items, now().toISOString()).catch(() => undefined)
  }, [fixture, owner, data])

  const fresh = !!data && !query.isError
  const items = useMemo(() => newestFirst(data?.items ?? cached?.items ?? []), [data, cached])

  const [index, setIndex] = useState(0)
  const at = Math.min(index, Math.max(0, items.length - 1))
  const slip = items[at] ?? null

  const [refreshing, setRefreshing] = useState(false)
  const refetch = query.refetch
  const refresh = useCallback(async () => {
    if (fixture) return
    setRefreshing(true)
    try { await refetch() } finally { setRefreshing(false) }
  }, [fixture, refetch])

  const error = query.error
  const forbidden = isApiError(error) && error.status === 403
  const status: 'loading' | 'error' | 'forbidden' | 'empty' | 'ready' = fixture ? 'ready'
    : slip ? 'ready'
    : query.isLoading || !cacheRead ? 'loading'
    : forbidden ? 'forbidden'
    : error ? 'error'
    : 'empty'

  const staleNote = fixture || fresh || !slip ? null
    : `${online ? 'Your payslips could not be refreshed' : 'No signal'}: showing the copy kept securely on this phone${cached ? `, last updated ${dateShort(cached.savedAt)}` : ''}.`

  return {
    fixture,
    status,
    errorMessage: isApiError(error) ? error.message : null,
    slip,
    /** Older ← → newer. The list is newest first, so "older" is the next index. */
    older: at < items.length - 1 ? () => setIndex(at + 1) : null,
    newer: at > 0 ? () => setIndex(at - 1) : null,
    count: items.length,
    staleNote,
    refreshing,
    refresh: () => void refresh(),
  }
}
