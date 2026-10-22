/**
 * The handset's API token.
 *
 * SecureStore ONLY — Keychain on iOS, Keystore on Android, this device only, never
 * backed up. Never AsyncStorage, never MMKV, never redux-persist, never the Redux
 * store: a token in the store is a token in every state dump and crash report.
 *
 * A copy is held in module memory so attaching the header does not cost a
 * Keychain read per request. `hydrate()` fills it once behind the splash.
 *
 * On web (development and the fidelity harness only) SecureStore does not exist,
 * and a token is deliberately kept in memory alone — never in localStorage.
 */

import * as SecureStore from 'expo-secure-store'
import { isWeb } from '../lib/env'

export type AppKind = 'guard' | 'resident'

const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY }

const keyFor = (app: AppKind): string => `gs.${app}.token`

let cached: string | null = null
let hydrated = false

export const tokenStorage = {
  /** Read the token from the secure store into memory. Call once at boot. */
  async hydrate(app: AppKind): Promise<string | null> {
    cached = isWeb ? cached : await SecureStore.getItemAsync(keyFor(app), OPTIONS)
    hydrated = true
    return cached
  },

  /** Synchronous — for the request header. Null before hydrate() or when signed out. */
  peek(): string | null {
    return cached
  },

  isHydrated(): boolean {
    return hydrated
  },

  async set(app: AppKind, token: string): Promise<void> {
    cached = token
    if (!isWeb) await SecureStore.setItemAsync(keyFor(app), token, OPTIONS)
  },

  async clear(app: AppKind): Promise<void> {
    cached = null
    if (!isWeb) await SecureStore.deleteItemAsync(keyFor(app), OPTIONS)
  },
}
