/**
 * LIVE UPDATES ON THE GUARD APP (M11 Part C, DESIGN §8) — mounted once, in the root layout.
 *
 * While a guard is signed in, the phone holds one socket to the server and the channels `live.config` lists
 * for this handset, catches up on anything it missed after every drop, and hands each event to the handlers
 * in ./handlers. It starts with the session and stops with it; the client itself goes quiet in the
 * background and comes back on the foreground and on regained signal (packages/core/live/lifecycle.ts).
 *
 * WHAT IT NEVER DOES: raise a banner, write a record or touch the outbox (C9, §8.7). A permission change that
 * ends the session is taken the way every other ended session is: the next read answers 401 and the session
 * slice signs the guard out; so on `ended` this makes one cheap read at once rather than waiting for one.
 *
 * Nothing in fidelity mode: the board harness draws the screens from fixtures, with no server behind them.
 */

import { useEffect } from 'react'
import { isFidelity } from '@gs/core'
import { guardApi } from '@gs/core/api/guard'
import { configureLive, startLive, stopLive, type AccessChange } from '@gs/core/live'
import { store, useAppSelector } from '@/store'
import { liveHandlers } from './handlers'

let configured = false

function onAccessChanged(change: AccessChange): void {
  if (change.effect !== 'ended') return
  void store.dispatch(guardApi.endpoints.guardsMe.initiate(undefined as never, { forceRefetch: true, subscribe: false }))
}

export function configureGuardLive(): void {
  if (configured) return
  configured = true
  configureLive({ store, app: 'guard', handlers: liveHandlers(), onAccessChanged })
}

export function LiveRuntime(): null {
  const active = useAppSelector((s) => s.session.status === 'active')

  useEffect(() => {
    if (isFidelity || !active) return
    configureGuardLive()
    startLive()
    return () => stopLive()
  }, [active])

  return null
}
