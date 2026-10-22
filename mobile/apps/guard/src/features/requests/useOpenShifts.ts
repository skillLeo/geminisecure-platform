/**
 * Open shifts — boards guard-app-21 (the list) and guard-app-22 (claimed).
 *
 * ONLINE ONLY, both ways — the server's own words: "an open shift is first come, and a cached list is
 * stale". So the list is NEVER served from a cache, and a claim is sent directly and NEVER queued: a
 * claim that arrives an hour late is a claim on a shift somebody else already has. A refusal is final
 * and is never retried; the list is refreshed instead.
 */

import { useCallback, useMemo, useState } from 'react'
import { fidelityScreenId, isFidelity, now } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { useAppSelector } from '@/store'
import { claimRefusal, openShiftCards, type OpenShiftCard } from './requests'

export type ClaimedShift = { title: string; when: string; site: string }

/** What the "claimed" screen shows: handed over in memory by the list that made the claim. */
let lastClaim: ClaimedShift | null = null
export const takeLastClaim = (): ClaimedShift | null => lastClaim

export function useOpenShifts(active: boolean, onClaimed: () => void) {
  const fixture = isFidelity && fidelityScreenId() === 'guard-app-21'
  const online = useAppSelector((s) => s.network.online)
  const query = guardApi.useShiftsOpenQuery(undefined, { skip: fixture || !active || !online, refetchOnMountOrArgChange: true })
  const [claim] = guardApi.useShiftsClaimMutation()

  const [claiming, setClaiming] = useState<number | string | null>(null)
  const [problem, setProblem] = useState<{ id: number | string; message: string } | null>(null)

  // Offline there is no list at all — not yesterday's.
  const cards = useMemo<OpenShiftCard[]>(() => (online && query.data && !query.isError ? openShiftCards(query.data.items, now()) : []), [online, query.data, query.isError])

  const refetch = query.refetch
  const claimShift = useCallback(async (card: OpenShiftCard) => {
    if (fixture || claiming !== null) return
    if (!online) { setProblem({ id: card.id, message: 'No signal. A shift can only be claimed while connected: it is first come.' }); return }
    setClaiming(card.id)
    setProblem(null)
    try {
      await claim({ shift: card.id }).unwrap()
      lastClaim = { title: card.title, when: `${card.day} · ${card.window}`, site: card.site }
      onClaimed()
    } catch (error) {
      setProblem({ id: card.id, message: claimRefusal(isApiError(error) ? error.code : undefined, isApiError(error) ? error.message : 'The claim could not be sent. Check your connection and try again.') })
      void refetch()
    } finally {
      setClaiming(null)
    }
  }, [fixture, claiming, online, claim, onClaimed, refetch])

  const error = query.error
  const status: 'offline' | 'loading' | 'forbidden' | 'error' | 'ready' = fixture ? 'ready'
    : !online ? 'offline'
    : query.isLoading || query.isUninitialized ? 'loading'
    : isApiError(error) && error.status === 403 ? 'forbidden'
    : error ? 'error'
    : 'ready'

  return { fixture, status, errorMessage: isApiError(error) ? error.message : null, cards, claiming, problem, claimShift: (card: OpenShiftCard) => void claimShift(card), refreshing: query.isFetching && !query.isLoading, refresh: () => { if (!fixture && active && online) void refetch() } }
}
