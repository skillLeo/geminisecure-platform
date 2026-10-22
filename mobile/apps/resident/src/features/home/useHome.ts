/**
 * Home — board resident-app-05. Four independent cards over one greeting: a failure in one
 * never blanks the others, and the panic button is there in EVERY state (safety never depends
 * on a request having worked).
 *
 *   SOMEONE AT THE GATE   `me.household.pending_approvals` — live only, never from cache; polled
 *                         every 10 s because a resident has three minutes to answer a walk-up.
 *   BALANCE               the ledger's own figure, formatted from its string, never computed; a
 *                         figure that is not from this minute says so ("As at …").
 *   EXPECTED TODAY        active passes whose window touches today (Jamaica).
 *   NOTICE                an unread urgent notice first.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import { dateLong, isFidelity, now, useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { useAccess } from '@/features/access/useAccess'
import { homeFixture } from '@/fidelity/home'
import { useAppSelector } from '@/store'
import { ago, balanceView, expectedToday, greetingFor, headlineNotice, secondsLeft, type BalanceView, type ExpectedVisitor } from './homeLogic'

export type Approval = { id: string; visitor: string; detail: string; secondsLeft: number }
export type NoticeView = { id: string; title: string; body: string; posted: string; urgent: boolean }
type CardState<T> = { status: 'loading' | 'ready' | 'error' | 'hidden'; data: T | null; message: string | null }

export type HomeModel = {
  greeting: string
  place: string
  today: string
  online: boolean
  approvals: Approval[]
  balance: BalanceView | null
  balanceState: CardState<BalanceView>['status']
  expected: ExpectedVisitor[]
  expectedState: CardState<ExpectedVisitor[]>['status']
  notice: NoticeView | null
  noticeState: CardState<NoticeView>['status']
  unreadNotices: number
  waitingToSync: number
  refreshing: boolean
  refresh: () => void
}

const APPROVAL_POLL_MS = 10_000

export function useHome(): HomeModel {
  const principal = useAppSelector((s) => s.session.principal)
  const online = useAppSelector((s) => s.network.online)
  const waitingToSync = useAppSelector((s) => s.outbox.waiting)
  const skip = isFidelity

  /*
   * WHAT THIS LEVEL READS (`access.may`): a Helper cannot read the household at all, and only Members answer the
   * gate, pay dues or issue passes. What the server would refuse is neither asked for nor drawn — the card is
   * simply not there. Nothing is known before the first answer, and then everything is asked, as before levels.
   */
  const { may } = useAccess()
  const readsHousehold = may('household')

  const household = residentApi.useMeHouseholdQuery(undefined, { skip: skip || !readsHousehold, pollingInterval: APPROVAL_POLL_MS })
  const cachedHousehold = useOfflineCache('me.household', household.data)
  const householdId = cachedHousehold?.household?.id

  const readsDues = may('dues')
  const balanceQuery = residentApi.useHouseholdsBalanceQuery({ household: householdId ?? '' }, { skip: skip || !readsDues || householdId === undefined })
  const cachedBalance = useOfflineCache('dues.balance', balanceQuery.data)

  const readsPasses = may('visitor_passes')
  const passes = residentApi.useVisitorPassesIndexQuery({ status: 'active' }, { skip: skip || !readsPasses })
  const cachedPasses = useOfflineCache('passes.active', passes.data)

  const readsNotices = may('notices')
  const notices = residentApi.useNoticesIndexQuery(undefined, { skip: skip || !readsNotices })
  const cachedNotices = useOfflineCache('notices.index', notices.data)

  /*
   * WHO THE PANIC SCREEN TEXTS AND CALLS, kept on the phone for every access level (household invites, G2): a
   * Helper cannot read `me.household`, and panic is never taken from anybody. Read when Home opens and when the
   * app comes back to it — never polled — and saved, because it is needed most when there is no signal. An older
   * server has no such endpoint; the panic screen then uses the household's own list.
   */
  const panicContacts = residentApi.usePanicContactsQuery(undefined, { skip, refetchOnFocus: true })
  useOfflineCache('panic.contacts', panicContacts.data)

  // The clock the countdowns and the greeting read; frozen by the fidelity harness.
  const [tick, setTick] = useState(0)
  useEffect(() => {
    if (isFidelity) return
    const timer = setInterval(() => setTick((n) => n + 1), 1000)
    return () => clearInterval(timer)
  }, [])
  const at = useMemo(() => now(), [tick]) // eslint-disable-line react-hooks/exhaustive-deps

  const [refreshing, setRefreshing] = useState(false)
  const refetchAll = [household.refetch, balanceQuery.refetch, passes.refetch, notices.refetch, panicContacts.refetch]
  const refresh = useCallback(() => {
    if (isFidelity) return
    setRefreshing(true)
    // Only what this level reads: a query that was never started cannot be asked again.
    const again = [
      readsHousehold ? household.refetch() : null,
      readsDues && readsHousehold && householdId !== undefined ? balanceQuery.refetch() : null,
      readsPasses ? passes.refetch() : null,
      readsNotices ? notices.refetch() : null,
      panicContacts.refetch(),
    ]
    void Promise.allSettled(again.filter((p) => p !== null)).finally(() => setRefreshing(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [householdId, readsHousehold, readsDues, readsPasses, readsNotices, ...refetchAll])

  const name = principal?.name ?? ''
  const base = {
    online,
    waitingToSync,
    refreshing,
    refresh,
    unreadNotices: Number(cachedNotices?.unread ?? 0),
  }

  if (isFidelity) {
    return { ...base, ...homeFixture, balanceState: 'ready', expectedState: 'ready', noticeState: 'ready' }
  }

  // Live only: an approval read from a cache could be one the resident already answered, or one that has expired.
  // Only a Member answers the gate (`approvals.respond`): nobody else is shown a card they could not answer.
  const approvals: Approval[] = (may('approvals') ? (household.data?.pending_approvals ?? []) : [])
    .map((p) => ({
      id: String(p.id),
      visitor: p.visitor_name,
      detail: [p.purpose, p.vehicle_plate, p.post_name ? `at ${p.post_name}` : null].filter(Boolean).join(' · '),
      secondsLeft: secondsLeft(p.respond_by, at),
    }))
    .filter((p) => p.secondsLeft > 0)

  const denied = (e: unknown) => isApiError(e) && e.status === 403
  const stateOf = (q: { isLoading: boolean; error?: unknown }, has: boolean): CardState<unknown>['status'] => (has ? 'ready' : denied(q.error) ? 'hidden' : q.error ? 'error' : 'loading')

  const headline = cachedNotices ? headlineNotice(cachedNotices.items) : null

  return {
    ...base,
    greeting: greetingFor(at, name.split(/\s+/)[0] ?? ''),
    place: [principal?.siteName, principal?.reference].filter(Boolean).join(' · '),
    today: dateLong(at),
    approvals,
    balance: readsDues && cachedBalance ? balanceView(cachedBalance, balanceQuery.data !== undefined && !balanceQuery.error && online) : null,
    balanceState: !readsDues || !readsHousehold ? 'hidden' : householdId === undefined ? stateOf(household, false) : stateOf(balanceQuery, !!cachedBalance),
    expected: readsPasses && cachedPasses ? expectedToday(cachedPasses.items, at) : [],
    expectedState: readsPasses ? stateOf(passes, !!cachedPasses) : 'hidden',
    notice: readsNotices && headline ? { id: String(headline.id), title: headline.title, body: headline.body, posted: ago(headline.published_at, at), urgent: headline.kind === 'urgent' } : null,
    noticeState: readsNotices ? stateOf(notices, !!cachedNotices) : 'hidden',
  }
}
