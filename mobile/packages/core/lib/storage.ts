/**
 * Key-value storage for everything that is NOT a secret: theme, language, the
 * API disk cache, the offline outbox. Tokens never come here — SecureStore only.
 *
 * TWO ENGINES, ONE INTERFACE, CHOSEN AT RUNTIME.
 *   Expo Go / web   AsyncStorage  — MMKV's native half does not exist in Expo Go
 *   dev / prod      MMKV          — fast and synchronous
 * The app must open in Expo Go from day one, so MMKV is `require`d lazily and any
 * failure to load it falls back to AsyncStorage rather than crashing.
 *
 * Reads are synchronous on both engines: AsyncStorage is mirrored in memory by
 * `hydrate()`, which the app awaits once behind the splash. Writes go to memory
 * at once and to disk behind; `setDurable` resolves only when the value is on
 * disk — the offline outbox uses it, because a guard must never be told a report
 * is saved before it is.
 */

import AsyncStorage from '@react-native-async-storage/async-storage'
import { isExpoGo, isWeb } from './env'

export interface KeyValueStorage {
  readonly engine: 'mmkv' | 'async-storage' | 'memory'
  hydrate(): Promise<void>
  getString(key: string): string | null
  set(key: string, value: string): void
  setDurable(key: string, value: string): Promise<void>
  remove(key: string): void
  keys(): string[]
  clearAll(): Promise<void>
  /** Resolves when every write so far is on disk. */
  flush(): Promise<void>
}

const PREFIX = 'gs:'

/** AsyncStorage behind an in-memory mirror. Also the engine used on web. */
export function createAsyncStorageEngine(backend: Pick<typeof AsyncStorage, 'getAllKeys' | 'multiGet' | 'setItem' | 'removeItem' | 'multiRemove'> = AsyncStorage): KeyValueStorage {
  const mirror = new Map<string, string>()
  let pending: Promise<unknown> = Promise.resolve()
  const chain = <T>(work: () => Promise<T>): Promise<T> => {
    const next = pending.then(work, work)
    pending = next.catch(() => undefined)
    return next
  }

  return {
    engine: 'async-storage',
    async hydrate() {
      const all = (await backend.getAllKeys()).filter((k) => k.startsWith(PREFIX))
      for (const [k, v] of await backend.multiGet(all)) if (v !== null) mirror.set(k.slice(PREFIX.length), v)
    },
    getString: (key) => mirror.get(key) ?? null,
    set(key, value) {
      mirror.set(key, value)
      void chain(() => backend.setItem(PREFIX + key, value))
    },
    setDurable(key, value) {
      mirror.set(key, value)
      return chain(() => backend.setItem(PREFIX + key, value))
    },
    remove(key) {
      mirror.delete(key)
      void chain(() => backend.removeItem(PREFIX + key))
    },
    keys: () => [...mirror.keys()],
    clearAll() {
      const doomed = [...mirror.keys()].map((k) => PREFIX + k)
      mirror.clear()
      return chain(() => backend.multiRemove(doomed))
    },
    flush: () => pending.then(() => undefined),
  }
}

type MmkvInstance = {
  getString(key: string): string | undefined
  set(key: string, value: string): void
  remove?(key: string): void
  delete?(key: string): void
  getAllKeys(): string[]
  clearAll(): void
}

function createMmkvEngine(mmkv: MmkvInstance): KeyValueStorage {
  return {
    engine: 'mmkv',
    hydrate: async () => undefined,
    getString: (key) => mmkv.getString(key) ?? null,
    set: (key, value) => mmkv.set(key, value),
    setDurable: async (key, value) => mmkv.set(key, value),
    // v4 names it remove(); v2/v3 named it delete().
    remove: (key) => (mmkv.remove ? mmkv.remove(key) : mmkv.delete?.(key)),
    keys: () => mmkv.getAllKeys(),
    clearAll: async () => mmkv.clearAll(),
    flush: async () => undefined,
  }
}

/*
 * ENCRYPTED AT REST (M11 §1.1; D-176). Everything this store holds — the offline queue, the cached screens, the
 * guard's gate knowledge, on-duty positions — is encrypted on the phone with AES-256, under a key that never
 * leaves the phone's secure hardware store (Keychain / Keystore). The key is readable after the phone's first
 * unlock, so a background sync or the on-duty task can open the store while the screen is locked.
 *
 * AN INSTALL FROM BEFORE THIS is encrypted IN PLACE the first time the new build opens it — nothing in the queue is
 * lost — in two phases, so a crash between them cannot leave data encrypted under a key nobody kept: the key is
 * kept as "pending" first; the store is encrypted; then the key is kept for good. A store opened with a pending key
 * is checked against a plain-text sentinel written just before, to know which side of the encryption it is on.
 *
 * Expo Go and web have no MMKV (and no secure hardware store on web): there the AsyncStorage engine is used, as
 * before, unencrypted — neither is a build a real phone runs.
 */
const KEY = 'gs.storage.key.v1'
const PENDING = 'gs.storage.key.v1.pending'
const SENTINEL = '__gs_plain_sentinel'

type SecureStoreSync = { getItem: (k: string, o?: object) => string | null; setItem: (k: string, v: string, o?: object) => void; deleteItemAsync: (k: string, o?: object) => Promise<void>; AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY?: unknown }
type MmkvModule = { createMMKV?: (o: { id: string; encryptionKey?: string; encryptionType?: 'AES-128' | 'AES-256' }) => MmkvInstance & { encrypt?: (key: string, type?: 'AES-128' | 'AES-256') => void } }

/** A 32-character key for AES-256, from the platform's secure random source. */
function newKey(): string {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  const Crypto = require('expo-crypto') as { randomUUID: () => string }
  return `${Crypto.randomUUID()}${Crypto.randomUUID()}`.replace(/-/g, '').slice(0, 32)
}

/** Opens the store encrypted, encrypting an older plain one in place; null when there is no key store to use. */
export function openEncrypted(mod: MmkvModule, secure: SecureStoreSync, id: string): (MmkvInstance & { encrypt?: (key: string, type?: 'AES-128' | 'AES-256') => void }) | null {
  const create = mod.createMMKV
  if (!create) return null
  const options = { keychainAccessible: secure.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY }
  let key: string | null
  let pending: string | null
  try {
    key = secure.getItem(`${KEY}.${id}`, options)
    pending = secure.getItem(`${PENDING}.${id}`, options)
  } catch {
    return null
  }

  if (key) return create({ id, encryptionKey: key, encryptionType: 'AES-256' })

  if (pending) {
    // A first opening that stopped half-way: the sentinel says whether the store was already encrypted.
    const plain = create({ id })
    if (plain.getString(SENTINEL) !== 'plain') {
      secure.setItem(`${KEY}.${id}`, pending, options)
      return create({ id, encryptionKey: pending, encryptionType: 'AES-256' })
    }
    plain.encrypt?.(pending, 'AES-256')
    secure.setItem(`${KEY}.${id}`, pending, options)
    void secure.deleteItemAsync(`${PENDING}.${id}`, options).catch(() => undefined)
    return plain
  }

  const fresh = newKey()
  const plain = create({ id })
  if (!plain.encrypt) return plain
  plain.set(SENTINEL, 'plain')
  secure.setItem(`${PENDING}.${id}`, fresh, options)
  plain.encrypt(fresh, 'AES-256')
  plain.remove?.(SENTINEL)
  plain.delete?.(SENTINEL)
  secure.setItem(`${KEY}.${id}`, fresh, options)
  void secure.deleteItemAsync(`${PENDING}.${id}`, options).catch(() => undefined)
  return plain
}

function loadMmkv(id: string): KeyValueStorage | null {
  if (isExpoGo || isWeb) return null
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const mod = require('react-native-mmkv') as MmkvModule & { MMKV?: new (o: { id: string }) => MmkvInstance }
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const secure = require('expo-secure-store') as SecureStoreSync
    const encrypted = openEncrypted(mod, secure, id)
    if (encrypted) return createMmkvEngine(encrypted)
    if (mod.createMMKV) return createMmkvEngine(mod.createMMKV({ id }))
    if (mod.MMKV) return createMmkvEngine(new mod.MMKV({ id }))
    return null
  } catch {
    return null
  }
}

let instance: KeyValueStorage | null = null

/**
 * THIS APP'S store, whichever code reaches it first. It used to take its id from the FIRST caller, and the session
 * boot names `gs-guard` / `gs-resident` while every other caller left the id out — so anything that ran before the
 * boot (the on-duty location task, when Android starts the app with no screen; a background sync) opened a second,
 * empty store called `gs-app`. The id is now the app's own (`extra.surface`, set in each app.config.ts) unless a
 * caller names one.
 */
function defaultId(): string {
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const Constants = (require('expo-constants') as { default: { expoConfig?: { extra?: { surface?: unknown } } } }).default
    const surface = Constants.expoConfig?.extra?.surface
    return surface === 'guard' || surface === 'resident' ? `gs-${surface}` : 'gs-app'
  } catch {
    return 'gs-app'
  }
}

/** The app's storage. `id` separates the two apps' data on one phone; it defaults to this app's own. */
export function getStorage(id?: string): KeyValueStorage {
  instance ??= loadMmkv(id ?? defaultId()) ?? createAsyncStorageEngine()
  return instance
}

/** JSON on top of the string store, with a version so an old shape is ignored, not mis-parsed. */
export function jsonStore<T>(storage: KeyValueStorage, key: string, version: string) {
  const fullKey = `${version}:${key}`
  return {
    get(): T | null {
      const raw = storage.getString(fullKey)
      if (raw === null) return null
      try {
        return JSON.parse(raw) as T
      } catch {
        return null
      }
    },
    set: (value: T) => storage.set(fullKey, JSON.stringify(value)),
    setDurable: (value: T) => storage.setDurable(fullKey, JSON.stringify(value)),
    remove: () => storage.remove(fullKey),
  }
}
