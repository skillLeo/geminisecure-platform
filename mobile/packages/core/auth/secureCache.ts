/**
 * A small cache for what may be kept on the phone but NEVER in shared storage — a guard's own
 * payslips (the server's contract: "Serve the cache from the secure store; never write a payslip
 * to shared storage").
 *
 * SecureStore ONLY — Keychain on iOS, Keystore on Android, this device only, never backed up.
 * Each entry is its own small item (the Keychain is not built for large values), and an index
 * of the names is kept so logout can remove every one of them: clearEverything() calls clear()
 * on a sign-out AND on a revoked session — the next person on this phone sees none of it.
 *
 * Every entry records whose it is, and is only handed back to that same owner.
 * On web (development and the fidelity harness only) it lives in memory alone.
 */

import * as SecureStore from 'expo-secure-store'
import { isWeb } from '../lib/env'

const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY }
const INDEX = 'gs.sc.index'
const keyFor = (name: string): string => `gs.sc.${name.replace(/[^A-Za-z0-9._-]/g, '_')}`

const memory = new Map<string, string>()

const read = async (key: string): Promise<string | null> => (isWeb ? memory.get(key) ?? null : SecureStore.getItemAsync(key, OPTIONS))
const write = async (key: string, raw: string): Promise<void> => { if (isWeb) memory.set(key, raw); else await SecureStore.setItemAsync(key, raw, OPTIONS) }
const remove = async (key: string): Promise<void> => { if (isWeb) memory.delete(key); else await SecureStore.deleteItemAsync(key, OPTIONS) }

async function names(): Promise<string[]> {
  try {
    const parsed: unknown = JSON.parse((await read(INDEX)) ?? '[]')
    return Array.isArray(parsed) ? parsed.filter((n): n is string => typeof n === 'string') : []
  } catch {
    return []
  }
}

export const secureCache = {
  /** Null when there is nothing, when it is unreadable, or when it belongs to somebody else. */
  async get<T>(name: string, owner: string): Promise<{ value: T; savedAt: string } | null> {
    try {
      const raw = await read(keyFor(name))
      if (!raw) return null
      const entry = JSON.parse(raw) as { owner?: string; savedAt?: string; value?: T }
      return entry.owner === owner && typeof entry.savedAt === 'string' && entry.value !== undefined ? { value: entry.value, savedAt: entry.savedAt } : null
    } catch {
      return null
    }
  },

  async set<T>(name: string, owner: string, value: T, savedAt: string): Promise<void> {
    const known = await names()
    // The index first: an entry that exists is always one that clear() knows about.
    if (!known.includes(name)) await write(INDEX, JSON.stringify([...known, name]))
    await write(keyFor(name), JSON.stringify({ owner, savedAt, value }))
  },

  async remove(name: string): Promise<void> {
    await remove(keyFor(name))
    await write(INDEX, JSON.stringify((await names()).filter((n) => n !== name)))
  },

  /** Logout, either kind: every entry and the index. */
  async clear(): Promise<void> {
    for (const name of await names()) await remove(keyFor(name))
    await remove(INDEX)
  },
}
