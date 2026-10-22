/**
 * What happens behind the splash, in order:
 *
 *   1. the key-value store is read into memory (so every later read is synchronous)
 *   2. the token is read from the secure store
 *   3. the last known principal is shown from disk at once — the app opens with a
 *      name on it before the network has answered, and works with no network at all
 *
 * The principal on disk is display facts only (a name, a site, a reference). It is
 * not a credential: without the token in the secure store it opens nothing.
 */

import type { ThunkDispatch, UnknownAction } from '@reduxjs/toolkit'
import { getStorage, jsonStore } from '../lib/storage'
import { hydrateSiteZone } from '../lib/siteTime'
import { noSession, sessionRestored, type Principal } from './sessionSlice'
import { tokenStorage, type AppKind } from './tokenStorage'

export const PRINCIPAL_KEY = 'principal'

/**
 * `demo` is the READ-ONLY look round the demonstration community. It is kept beside
 * the principal because the TOKEN survives a restart: a phone reopened in a demonstration
 * session must say so on the first frame, not quietly look like a real one.
 */
type Cached = { principal: Principal | null; pending: boolean; demo?: boolean; reviewer?: boolean }

export async function bootSession(app: AppKind, dispatch: ThunkDispatch<unknown, unknown, UnknownAction>): Promise<'active' | 'pending' | 'signedOut'> {
  const storage = getStorage(`gs-${app}`)
  await storage.hydrate()
  // Beside the principal, and read before the first screen draws: the site's zone the server last sent, so
  // an app opened with no signal still shows Kingston times and not the handset's.
  hydrateSiteZone()

  const token = await tokenStorage.hydrate(app)
  if (!token) {
    dispatch(noSession())
    return 'signedOut'
  }

  const cached = jsonStore<Cached>(storage, PRINCIPAL_KEY, 'v1').get()
  const pending = cached?.pending ?? false
  dispatch(sessionRestored({ principal: cached?.principal ?? null, pending, demo: cached?.demo === true, reviewer: cached?.reviewer === true }))
  return pending ? 'pending' : 'active'
}
