/**
 * What every store in this project contains. Each app calls `configureStore`
 * itself — with concrete types, so RootState is inferred exactly — and spreads
 * these in:
 *
 *   export const store = configureStore({
 *     reducer: { ...coreReducers, shift: shiftReducer },
 *     middleware: (getDefault) => getDefault().concat(coreMiddleware),
 *   })
 *   startCoreListeners(store.dispatch)
 *
 * NOT PERSISTED. Nothing in a store here is written to disk by a persistence
 * layer: the token is in SecureStore, and the offline outbox and the disk cache
 * are written deliberately through lib/storage. redux-persist is how a token ends
 * up in AsyncStorage.
 */

import { configureStore, type ThunkDispatch, type UnknownAction } from '@reduxjs/toolkit'
import { setupListeners } from '@reduxjs/toolkit/query'
import { AppState, NativeModules, Platform, TurboModuleRegistry } from 'react-native'
import { sessionReducer } from '../auth/sessionSlice'
import { baseApi } from './baseApi'

export const coreReducers = {
  session: sessionReducer,
  [baseApi.reducerPath]: baseApi.reducer,
} as const

export const coreMiddleware = baseApi.middleware

type Dispatch = ThunkDispatch<unknown, unknown, UnknownAction>
type Unsubscribe = () => void
type NetInfoLike = { addEventListener(fn: (state: { isConnected: boolean | null; isInternetReachable?: boolean | null }) => void): Unsubscribe }

/**
 * NetInfo, only where its native half exists — looked for the way NetInfo itself looks for it. Without it (Jest)
 * NetInfo's first state read fails inside a promise nothing can catch, so asking and catching is not enough.
 */
function netInfo(): NetInfoLike | null {
  let native: unknown = NativeModules.RNCNetInfo
  if (!native) {
    try {
      native = TurboModuleRegistry.get('RNCNetInfo')
    } catch {
      native = null
    }
  }
  if (!native) return null
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const mod = require('@react-native-community/netinfo') as { default?: NetInfoLike } & NetInfoLike
    return mod.default ?? mod
  } catch {
    return null
  }
}

/**
 * Enables refetchOnReconnect / refetchOnFocus, ON THE PHONE (M11 Part C). RTK's own listeners are the browser's
 * `online` and `visibilitychange` events, which React Native has neither of — so until this, baseApi's
 * `refetchOnReconnect: true` and a hook's `refetchOnFocus: true` never fired on either app. Here:
 *
 *   NetInfo    offline → online, the same test the offline line uses (offline/network.ts): onOnline.
 *              Only on the EDGE: NetInfo's first answer at start-up is not a reconnect, and refetching every
 *              screen then would be a burst of requests for nothing
 *   AppState   background → active: onFocus. iOS's `inactive` (the pull-down shade) is not a departure, as
 *              live/lifecycle.ts treats it too
 *
 * On the web build RTK's own listeners are the right ones, and stay. Call once per store.
 */
export const startCoreListeners = (dispatch: Dispatch): Unsubscribe => (Platform.OS === 'web' ? setupListeners(dispatch) : setupListeners(dispatch, nativeListeners))

/** The handler `setupListeners` is given on a phone (see startCoreListeners). */
export const nativeListeners: NonNullable<Parameters<typeof setupListeners>[1]> = (d, { onFocus, onFocusLost, onOnline, onOffline }) => {
  const offs: Unsubscribe[] = []

  let online = true
  const net = netInfo()
  try {
    if (net) {
      offs.push(
        net.addEventListener((state) => {
          const now = state.isConnected === true && state.isInternetReachable !== false
          if (now === online) return
          online = now
          d(now ? onOnline() : onOffline())
        }),
      )
    }
  } catch {
    // No connectivity events here: nothing refetches on reconnect, as before.
  }

  let away = AppState.currentState === 'background'
  const sub = AppState.addEventListener('change', (state) => {
    if (state === 'background') {
      if (!away) d(onFocusLost())
      away = true
    } else if (state === 'active' && away) {
      away = false
      d(onFocus())
    }
  })
  offs.push(() => sub.remove())

  return () => offs.forEach((off) => off())
}

/**
 * A store with the core state only — for tests, and for anything that needs no feature slice. It keeps RTK's own
 * listeners rather than the phone's: under Jest, jest-expo's stand-in for NetInfo's native half answers
 * `getCurrentState` with nothing, and NetInfo fails on that inside a promise nothing can catch.
 */
export function createCoreStore() {
  const store = configureStore({
    reducer: coreReducers,
    middleware: (getDefault) => getDefault().concat(coreMiddleware),
  })
  setupListeners(store.dispatch)
  return store
}

export type CoreStore = ReturnType<typeof createCoreStore>
export type CoreState = ReturnType<CoreStore['getState']>
