/**
 * Where this handset's own identifiers are kept: the secure store, and on web (development only)
 * memory — the SECRET key is held there alone; the install UID is not a secret, so it may sit in
 * localStorage, which makes a reload the same install and lets the flow checks enrol the same
 * "handset" twice without raising a rebind.
 *
 * Split out of deviceKey.ts (M11 A4) so deviceUid.ts can use it without loading tweetnacl.
 */

import * as SecureStore from 'expo-secure-store'
import { isWeb } from '../lib/env'

export const SECRET_KEY = 'gs.guard.device_secret'
export const UID_KEY = 'gs.guard.device_uid'
const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY }

const memory = new Map<string, string>()
const webLocal = (): Storage | null => {
  try {
    return (globalThis as { localStorage?: Storage }).localStorage ?? null
  } catch {
    return null
  }
}

export const read = async (k: string) => (isWeb ? (memory.get(k) ?? (k === UID_KEY ? (webLocal()?.getItem(k) ?? null) : null)) : SecureStore.getItemAsync(k, OPTIONS))

export const save = async (k: string, v: string) => {
  if (!isWeb) return SecureStore.setItemAsync(k, v, OPTIONS)
  memory.set(k, v)
  if (k === UID_KEY) webLocal()?.setItem(k, v)
}
