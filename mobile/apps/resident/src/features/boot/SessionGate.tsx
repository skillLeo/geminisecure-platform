/**
 * The one place the resident's session is booted and guarded — mounted once, in the root layout.
 *
 *   signedOut   may see the splash and the sign-in screens, nothing else.
 *   pending     signed in, but the estate has not yet approved the unit claim: the token reaches
 *               only `/me` and the claim, so the app shows only the claim and its holding screen —
 *               a real screen with a real status, never a dead end.
 *   active      everything; the offline runtime adopts this account's queue and drains on reconnect.
 *
 * A token revoked mid-use (signed in on another phone, access withdrawn by the estate) lands on
 * Log In with the reason; what the resident saved with no signal is kept for when they return.
 * Fidelity mode boots nothing: the harness puts each screen in its board's state itself.
 */

import { useEffect } from 'react'
import { useSegments } from 'expo-router'
import { enterApp, isFidelity } from '@gs/core'
import { bootSession, noSession } from '@gs/core/auth'
import { residentApi } from '@gs/core/api/resident'
import { refreshFromMe } from '@/features/auth/session'
import { startOfflineRuntime, watchNetwork } from '@/offline/runtime'
import { store, useAppDispatch, useAppSelector } from '@/store'

// The dev-only gallery (M45 §3) is a redirect to the start screen in any build but a gallery or fidelity one.
const OPEN_TO_ALL = new Set<string | undefined>([undefined, '(auth)', 'lifted', 'gallery'])

export function SessionGate() {
  const dispatch = useAppDispatch()
  const status = useAppSelector((s) => s.session.status)
  // A READ-ONLY look round the demonstration community. It is an ordinary `active`
  // session in every other way, so everything below reads the same — except the outbox.
  const demo = useAppSelector((s) => s.session.demo)
  const first = useSegments()[0] as string | undefined

  useEffect(() => {
    if (isFidelity) return
    // A boot failure must never strand anyone on a splash: no readable session is no session.
    void bootSession('resident', dispatch).catch(() => dispatch(noSession()))
    watchNetwork()
  }, [dispatch])

  // Who this is, from the server, once per activation: a sign-in answer names the account but not the unit
  // (only `/me` does), and the estate may have changed the unit or the name since this phone last asked.
  // A failure changes nothing — what the phone already knows stays.
  useEffect(() => {
    if (isFidelity || status !== 'active') return
    let alive = true
    void (async () => {
      const me = await store.dispatch(residentApi.endpoints.meShow.initiate(undefined, { forceRefetch: true, subscribe: false }))
      if (alive && me.data) await refreshFromMe(me.data, store.getState().session.principal, false, dispatch)
    })().catch(() => undefined)
    return () => { alive = false }
  }, [status, dispatch])

  useEffect(() => {
    if (isFidelity) return
    // NO OUTBOX FOR A DEMONSTRATION. Every write in that session is refused by the server at the
    // route's own ability check, so a queue could only ever fill up with things that will never
    // be accepted — and an app that says "saved, it will send later" about one of them would be
    // faking an offline success, which this app never does.
    if (status === 'active' && !demo) void startOfflineRuntime()
    // Nothing of the session is left mounted underneath (enterApp): a screen left there went on polling with no token.
    if (status === 'signedOut' && !OPEN_TO_ALL.has(first)) enterApp('/(auth)/log-in' as never)
    if (status === 'pending' && !OPEN_TO_ALL.has(first)) enterApp('/(auth)/pending' as never)
  }, [status, demo, first])

  return null
}
