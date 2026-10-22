/**
 * Today's gate activity — board guard-app-24. THE GUARD'S OWN LOG.
 *
 *   THE SERVER'S LOG   gate.activity is the ESTATE's log for today, each row naming who recorded it.
 *                      By default only the rows recorded by the signed-in guard are shown ("Your
 *                      entries today"); the rest of the estate's log is one tap away, labelled as such.
 *   THE TILES          the server's counts — admitted · exited · overridden · denied — are the ESTATE's
 *                      totals and are labelled so. (The board's WALK-UPS count does not exist.)
 *   THIS PHONE'S ACTS  gate acts still in the outbox are merged on top: Pending sync / Syncing in
 *                      amber, and a refused one as Not accepted with the server's sentence. Tapping
 *                      one opens the sync queue.
 *   OFFLINE            the last log this phone received (only if it is TODAY's) plus the queued acts.
 *
 * Append-only: no row can be edited or deleted, here or anywhere.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { fidelityScreenId, isFidelity, now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { gateActivityFixture } from '@/fidelity/walkup'
import { drain, drainNow, submit } from '@/offline/runtime'
import { useAppSelector } from '@/store'
import { activityRow, countTiles, exitLabel, isLogOfToday, mineOnly, onSiteNow, queuedExitSubject, queuedRows, type ActivityRow, type CountTile, type OnSiteRow } from './walkup'

export type GateActivityModel = {
  status: 'loading' | 'error' | 'forbidden' | 'ready'
  errorMessage: string | null
  countsLabel: string
  listLabel: string
  tiles: CountTile[]
  rows: ActivityRow[]
  scope: 'mine' | 'estate'
  /** Rows recorded by other guards today. */
  othersCount: number
  /** The switch is drawn only when pressing it changes what is shown (no dead controls). */
  canSwitchScope: boolean
  toggleScope: () => void
  /** An honest line when what is shown is not fresh from the server. */
  staleNote: string | null
  refreshing: boolean
  refresh: () => void
  /** "On site now" (M54 §2.4): visitors admitted today and not recorded leaving, estate-wide. Empty on the board. */
  onSite: OnSiteRow[]
  /** Record that one has left: queued like every gate act, sent now or when there is signal. */
  markExited: (row: OnSiteRow) => void
  /** The exit could not be saved on this phone. */
  exitError: string | null
}

export function useGateActivity(): GateActivityModel {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-24' ? gateActivityFixture : null
  const online = useAppSelector((s) => s.network.online)
  const guardName = useAppSelector((s) => s.session.principal?.name ?? null)
  const siteName = useAppSelector((s) => s.session.principal?.siteName ?? null)
  const ops = useAppSelector((s) => s.outbox.ops)

  // The most the server gives: on a busy estate the guard's own rows must not fall off the end.
  const query = guardApi.useGateActivityQuery({ limit: 100 }, { skip: !!fixture })
  const cached = useOfflineCache('gate.activity', query.data)
  // Yesterday's log saved on the phone is not today's.
  const log = cached && isLogOfToday(cached.date, now()) ? cached : null
  const fresh = !!query.data && !query.isError

  const [wantsEstate, setWantsEstate] = useState(false)
  const [refreshing, setRefreshing] = useState(false)

  // Without a name to match on, "yours" cannot be told apart: the estate's log is shown and labelled as that —
  // and no switch is offered, because it could not switch to anything.
  const scope: GateActivityModel['scope'] = fixture ? 'mine' : wantsEstate || !guardName ? 'estate' : 'mine'

  const queued = useMemo(() => queuedRows(ops), [ops])
  const mine = useMemo(() => (log ? mineOnly(log.items, guardName) : []), [log, guardName])
  const rows = useMemo(() => [...queued, ...(scope === 'mine' ? mine : (log?.items ?? [])).map(activityRow)], [queued, scope, mine, log])

  // An act that has just been confirmed is in the server's log now: fetch it, so it never vanishes from the list.
  const confirmedGateActs = ops.filter((o) => o.kind === 'gate' && o.state === 'confirmed').length
  const seenConfirmed = useRef(confirmedGateActs)
  const refetch = query.refetch
  useEffect(() => {
    const more = confirmedGateActs > seenConfirmed.current
    seenConfirmed.current = confirmedGateActs
    if (!fixture && more) void refetch()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [confirmedGateActs])

  const refresh = useCallback(async () => {
    if (fixture) return
    setRefreshing(true)
    try {
      // Pulling the log down is also a reason to send what is waiting.
      await Promise.all([refetch(), drainNow()])
    } finally {
      setRefreshing(false)
    }
  }, [fixture, refetch])

  const othersCount = fixture || !guardName || !log ? 0 : log.items.length - mine.length

  // Who is still inside: the estate's log, less the exits this phone has queued and not yet sent (M54 §2.4).
  const queuedExits = useMemo(
    () => ops.filter((o) => o.kind === 'gate' && o.state !== 'confirmed' && o.state !== 'refused').map((o) => queuedExitSubject(o.label)).filter((s): s is string => s !== null),
    [ops],
  )
  const onSite = useMemo(() => (fixture || !log ? [] : onSiteNow(log.items, queuedExits)), [fixture, log, queuedExits])
  const [exitError, setExitError] = useState<string | null>(null)
  const markExited = useCallback((row: OnSiteRow) => {
    setExitError(null)
    void submit({ endpoint: 'gate.exit', body: { category: row.category, subject: row.subject }, label: exitLabel(row.subject), kind: 'gate' }).catch(() =>
      setExitError('That exit could not be saved on this phone, so nothing has been recorded. Try again.'),
    )
  }, [])

  const error = query.error
  const forbidden = isApiError(error) && error.status === 403
  const status: GateActivityModel['status'] = fixture ? 'ready'
    : log || queued.length > 0 ? 'ready'
    : query.isLoading ? 'loading'
    : forbidden ? 'forbidden'
    : error && !cached ? 'error'
    : 'ready'

  const staleNote = fixture || fresh || query.isLoading ? null
    : forbidden ? 'This handset is not allowed to read the gate log, so only what is saved on this phone is shown.'
    : log ? `${online ? "The log could not be refreshed" : 'No signal'}: showing the last log this phone received. Entries you have not sent yet are marked Pending sync.`
    : `${online ? "Today's log could not be loaded" : "No signal: today's log has not reached this phone"}. Entries you record are kept here and marked Pending sync.`

  return {
    status,
    errorMessage: isApiError(error) ? error.message : null,
    countsLabel: fixture ? fixture.countsLabel : `${siteName ?? 'Whole estate'} · estate totals today`,
    listLabel: fixture ? fixture.listLabel : scope === 'mine' ? 'Your entries today' : "Everyone's entries today",
    tiles: fixture ? fixture.tiles : countTiles(log?.counts),
    rows: fixture ? fixture.rows : rows,
    scope,
    othersCount,
    canSwitchScope: !fixture && !!guardName && (wantsEstate || othersCount > 0),
    toggleScope: () => setWantsEstate((v) => !v),
    staleNote,
    refreshing,
    refresh: () => void refresh(),
    onSite,
    markExited,
    exitError,
  }
}
