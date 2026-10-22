/**
 * ELIGIBILITY FIRST. Every marking screen asks `elections.eligibility` — online, fresh, on this
 * visit — before anything about the paper is drawn, or even fetched.
 *
 *   ineligible   NO ballot at all (not a disabled one): a plain holding screen, and no reason
 *                beyond the server's own `reason`. The paper is never mounted, so no choice exists
 *                anywhere in the tree.
 *   voted        straight to the receipt, never the choices.
 *   closed       a closed state.
 *   open         only now is the paper fetched and drawn.
 *
 * The contract says "Online only": an answer from an earlier visit (RTK Query keeps one for a
 * minute) is NOT trusted — only an answer fulfilled since this screen mounted counts. With no
 * connection there is no ballot, and the screen says why.
 */

import { useCallback, useRef } from 'react'
import { isFidelity } from '@gs/core'
import type { ApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { useAppSelector } from '@/store'
import { gateOf, gateOfError, lostConnection, recheckedGate, type GateModel } from './paper'

export type { GateModel }

/** How long the server is given to finish writing a paper whose answer was lost, before "no paper" is believed. */
const SETTLE_MS = 4000

/**
 * `recheck({ settle: true })` — straight after a LOST answer the server may still be writing the paper it did
 * receive, so "no paper yet" is not yet "no paper": if the first answer is `open`, it is asked ONCE more after a
 * pause. Asking sends nothing. (The pause lives HERE, in the file that only ever reads — the file that sends the
 * vote holds no timer at all, and a test keeps it that way.)
 */
export function useBallotGate(id: string | undefined): { gate: GateModel; online: boolean; recheck: (options?: { settle?: boolean }) => Promise<GateModel> } {
  const online = useAppSelector((s) => s.network.online)
  const skip = isFidelity || !id

  const since = useRef(0)
  if (since.current === 0) since.current = Date.now()

  const q = residentApi.useElectionsEligibilityQuery({ ballot: id ?? '' }, { skip, refetchOnMountOrArgChange: true, refetchOnReconnect: true })

  const refetch = q.refetch
  const recheck = useCallback(async (options: { settle?: boolean } = {}): Promise<GateModel> => {
    if (skip || !id) return { state: 'checking' }
    // A refetch that fails still hands back the LAST GOOD answer as `data` — after a lost send, the one
    // from before the paper went. Only an answer fulfilled after this moment counts (recheckedGate).
    const ask = async (): Promise<GateModel> => {
      const askedAt = Date.now()
      return recheckedGate(await refetch(), askedAt, id)
    }
    const first = await ask()
    if (!options.settle || first.state !== 'open') return first
    await new Promise<void>((resolve) => setTimeout(resolve, SETTLE_MS))
    return ask()
  }, [skip, id, refetch])

  // The board's state: an eligible household with the poll open.
  if (isFidelity) return { gate: { state: 'open', checkedOn: null, why: null }, online, recheck }
  if (!id) return { gate: { state: 'missing' }, online, recheck }

  const error = q.isError ? (q.error as ApiError) : null
  const fresh = q.data !== undefined && (q.fulfilledTimeStamp ?? 0) >= since.current && String(q.data.ballot_id) === String(id)

  // An answer from THIS visit stands while the connection comes and goes (the send is blocked
  // offline anyway, and the server is the real guard). Any other failure replaces it.
  if (fresh && q.data && (error === null || lostConnection(error))) return { gate: gateOf(q.data), online, recheck }
  if (error) return { gate: gateOfError(error), online, recheck }
  // The phone knows it has no connection: say so now, rather than a skeleton until the request
  // (and its backed-off retries) gives up. The question is still asked, and asked again on reconnect.
  if (!online) return { gate: { state: 'offline' }, online, recheck }
  return { gate: { state: 'checking' }, online, recheck }
}
