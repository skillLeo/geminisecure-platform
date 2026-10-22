/**
 * LIVE UPDATES ON THE RESIDENT APP (M11 Part C, DESIGN §8) — mounted once, in the root layout.
 *
 * While a resident is signed in (or waiting for their household claim to be decided), the phone holds one
 * socket and the channels `live.config` lists for this account, catches up after every drop, and hands each
 * event to the handlers in ./handlers. It starts with the session and stops with it.
 *
 * WHAT IT NEVER DOES: raise a banner, write a record or touch the outbox (C9, §8.7). A permission change that
 * ends the session is taken the way every other ended session is — the next read answers 401 and the session
 * slice signs the resident out — so on `ended` this makes one cheap read at once.
 *
 * Nothing in fidelity mode: the board harness draws the screens from fixtures, with no server behind them.
 */

import { useEffect } from 'react'
import { isFidelity } from '@gs/core'
import { residentApi } from '@gs/core/api/resident'
import { configureLive, startLive, stopLive, type AccessChange } from '@gs/core/live'
import { store, useAppSelector } from '@/store'
import { liveHandlers } from './handlers'

let configured = false

function onAccessChanged(change: AccessChange): void {
  if (change.effect !== 'ended') return
  void store.dispatch(residentApi.endpoints.meShow.initiate(undefined as never, { forceRefetch: true, subscribe: false }))
}

export function configureResidentLive(): void {
  if (configured) return
  configured = true
  configureLive({ store, app: 'resident', handlers: liveHandlers(), onAccessChanged })
}

export function LiveRuntime(): null {
  const signedIn = useAppSelector((s) => s.session.status === 'active' || s.session.status === 'pending')

  useEffect(() => {
    if (isFidelity || !signedIn) return
    configureResidentLive()
    startLive()
    return () => stopLive()
  }, [signedIn])

  return null
}
