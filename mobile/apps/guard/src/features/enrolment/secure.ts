/**
 * The pending rebind's claim secret is a credential — whoever holds it collects the
 * guard's token once a supervisor approves. It lives in the secure store, never in the
 * key-value store. On web (development only) it is kept in memory alone.
 */

import * as SecureStore from 'expo-secure-store'
import { isWeb } from '@gs/core'

const KEY = 'gs.guard.pending_rebind'
const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY }

export type PendingRebind = {
  rebindId: number | string
  claimSecret: string
  guard: { id: number | string; name: string; employee_number: string }
  site: { id: number | string; name: string }
}

let memory: string | null = null

export const pendingRebind = {
  async get(): Promise<PendingRebind | null> {
    const raw = isWeb ? memory : await SecureStore.getItemAsync(KEY, OPTIONS)
    if (!raw) return null
    try {
      return JSON.parse(raw) as PendingRebind
    } catch {
      return null
    }
  },
  async set(value: PendingRebind): Promise<void> {
    const raw = JSON.stringify(value)
    if (isWeb) memory = raw
    else await SecureStore.setItemAsync(KEY, raw, OPTIONS)
  },
  async clear(): Promise<void> {
    memory = null
    if (!isWeb) await SecureStore.deleteItemAsync(KEY, OPTIONS)
  },
}
