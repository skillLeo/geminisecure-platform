/**
 * The holding screen's behaviour — signed in, but the estate has not approved the unit claim yet.
 *
 * `GET /me` is the truth and it is POLLED (every 15 s while this screen is open): the moment the
 * estate approves, the account is active "on its next request" — no new sign-in. The answer is
 * also kept on the phone, so with no signal the resident still sees what they sent and when,
 * with an honest note that it may be out of date. A saved answer is only shown if it is THIS
 * account's.
 *
 *   account active   → the session is activated (the offline runtime starts) and the app opens
 *   claim null       → nothing claimed yet → the claim form
 *   pending          → "your estate is reviewing your claim" + what was sent
 *   pending + doc    → the estate asked for a document; there is no upload endpoint, so it goes
 *                      to the estate office — said plainly
 *   rejected         → the estate's reason, and a way to claim a different unit
 *   403              → the estate withdrew access: an explanation and Log out, no retry loop
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { useOfflineCache } from '@gs/core'
import { isApiError } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { useAppDispatch, useAppSelector } from '@/store'
import { claimView, submittedWhen, type ClaimView } from './claim'
import { closeSession, refreshFromMe } from './session'

export const POLL_MS = 15_000

export type PendingModel = {
  status: 'loading' | 'ready' | 'error' | 'denied'
  errorMessage: string | null
  view: ClaimView | null
  /** "Friday, September 4 at 9:41 AM", on the site’s clock. */
  submittedAt: string
  estateName: string | null
  /** Where sign-in codes go, masked by the server ("a•••@example.com"). */
  accountHint: string | null
  /** What is shown is the copy saved on this phone: the server has not answered this time. */
  fromCache: boolean
  online: boolean
  refreshing: boolean
  refresh: () => void
  loggingOut: boolean
  logOut: () => void
}

export function usePendingClaim(go: { home: () => void; claim: () => void; logIn: () => void }): PendingModel {
  const dispatch = useAppDispatch()
  const principal = useAppSelector((s) => s.session.principal)
  const sessionStatus = useAppSelector((s) => s.session.status)
  const online = useAppSelector((s) => s.network.online)
  const signedIn = sessionStatus === 'pending' || sessionStatus === 'active'

  const query = residentApi.useMeShowQuery(undefined, { pollingInterval: POLL_MS, skip: !signedIn, refetchOnMountOrArgChange: true, refetchOnReconnect: true })
  const saved = useOfflineCache('me.show', query.data)
  const mine = saved && (!principal || String(saved.account.id) === String(principal.id)) ? saved : undefined
  const me = query.data ?? mine

  const [refreshing, setRefreshing] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)
  const leaving = useRef(false)

  // Every FRESH answer updates who this is (the name, the estate, the unit as claimed); an active
  // account opens the app, and an account with no claim goes to the form. Never from a saved copy.
  const fresh = query.data
  useEffect(() => {
    if (!fresh || leaving.current) return
    let alive = true
    void (async () => {
      const now = await refreshFromMe(fresh, principal, sessionStatus === 'pending', dispatch)
      if (!alive || leaving.current) return
      if (now === 'active') {
        leaving.current = true
        go.home()
      } else if (fresh.claim === null) {
        leaving.current = true
        go.claim()
      }
    })()
    return () => {
      alive = false
    }
    // `principal` and `go` change identity as a result of this very effect; the answer is what it follows.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fresh, dispatch])

  const refetch = query.refetch
  const refresh = useCallback(async () => {
    if (!signedIn) return
    setRefreshing(true)
    try {
      await refetch()
    } finally {
      setRefreshing(false)
    }
  }, [refetch, signedIn])

  const logOut = useCallback(async () => {
    if (leaving.current) return
    leaving.current = true
    setLoggingOut(true)
    await closeSession(dispatch)
    go.logIn()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dispatch])

  const error = query.error
  const denied = isApiError(error) && error.status === 403
  const view = me ? claimView(me) : null
  const claim = view && 'claim' in view ? view.claim : null

  return {
    status: denied ? 'denied' : me ? 'ready' : query.isLoading || query.isUninitialized ? 'loading' : 'error',
    errorMessage: isApiError(error) ? (error.status === 'network' || error.status === 'timeout' ? "You're offline, or the server can't be reached, and nothing about your claim is saved on this phone yet. Check your connection and try again." : error.message) : error ? 'Something unexpected happened. Please try again.' : null,
    view,
    submittedAt: claim ? submittedWhen(claim.submitted_at) : '',
    estateName: me?.estate.name ?? principal?.siteName ?? null,
    accountHint: me?.account.destination_hint ?? null,
    fromCache: !query.data && !!mine && !query.isLoading,
    online,
    refreshing,
    refresh: () => void refresh(),
    loggingOut,
    logOut: () => void logOut(),
  }
}
