/**
 * Connectivity, as one boolean the whole app reads — and the trigger that drains the
 * outbox the moment signal returns.
 *
 * "Online" means the OS reports a connection AND, when it can tell, that the internet
 * is reachable. It is advisory: a request can still fail, and the outbox treats any
 * failure as "try again later with the same key". Safety never depends on this flag.
 */

import NetInfo, { type NetInfoState } from '@react-native-community/netinfo'
import { createSlice, type PayloadAction } from '@reduxjs/toolkit'

export type NetworkState = { online: boolean; since: string | null }

const slice = createSlice({
  name: 'network',
  initialState: { online: true, since: null } as NetworkState,
  reducers: {
    connectivityChanged(state, action: PayloadAction<{ online: boolean; at: string }>) {
      if (state.online !== action.payload.online) {
        state.online = action.payload.online
        state.since = action.payload.at
      }
    },
  },
})

export const { connectivityChanged } = slice.actions
export const networkReducer = slice.reducer

export const isOnline = (s: NetInfoState): boolean => s.isConnected === true && s.isInternetReachable !== false

/** Start listening. `onReconnect` fires on every offline → online edge. Returns the unsubscribe. */
export function watchConnectivity(dispatch: (a: ReturnType<typeof connectivityChanged>) => void, onReconnect: () => void): () => void {
  let last = true
  return NetInfo.addEventListener((state) => {
    const online = isOnline(state)
    dispatch(connectivityChanged({ online, at: new Date().toISOString() }))
    if (online && !last) onReconnect()
    last = online
  })
}
