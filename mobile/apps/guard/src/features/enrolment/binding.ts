/**
 * WHAT THIS PHONE KNOWS ABOUT ITS OWN BINDING (M32 F3) — whose phone it is, and, once the server has said so, that it
 * stopped being theirs.
 *
 *   bound    written the moment an enrolment code (or an approved move) binds this install to a guard — and on the
 *            first start of this version on a phone that was already signed in, so every phone enrolled before it is
 *            covered too. It outlives everything that ends a SESSION: the 30-minute sign-out, a 401, a sign-out the
 *            guard chose. Then the phone asks for a fingerprint (or a supervisor's PIN), never a code.
 *   removed  the server's own sentence, with its date, once it has said this install is no longer the guard's —
 *            another phone was approved in its place, or the office took it out of service. The enrolment screen
 *            shows it instead of a generic refusal ("Your supervisor removed this phone on 2 October. …").
 *
 * WHERE: the secure store, beside the phone's own key and install id (`gs.guard.device_secret`, `gs.guard.device_uid`)
 * and with the same option, WHEN_UNLOCKED_THIS_DEVICE_ONLY: values encrypted with an Android Keystore key in the app's
 * own data. An update installed over the top keeps the app's data and its Keystore keys (same package, same signing
 * certificate), so it keeps this. An uninstall or "Clear storage" loses it — rightly — and auto backup is off, so
 * nothing brings it back. Display facts only: it is not a credential, and the device KEY is what proves the phone.
 *
 * Read once at boot (`hydrate`, before the session is), so the splash and the gate can route on it synchronously.
 */

import * as SecureStore from 'expo-secure-store'
import { isWeb } from '@gs/core/lib'

export const BINDING_KEY = 'gs.guard.binding'
export const REMOVED_KEY = 'gs.guard.binding_removed'
const OPTIONS: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY }

/**
 * Whose phone this is: what the unlock screen greets, never anything that opens a door. `reviewer` is the SERVER's
 * flag for the app-store reviewer's demonstration account (enrolment's `reviewer`): on that account alone the phone's
 * passcode may stand in for a fingerprint, as at clock-in. False for every real guard.
 */
export type Binding = { guardId: number; name: string; reference: string | null; siteName: string | null; boundAt: string; reviewer?: boolean }

/** The server's sentence about this install being taken out of service, and when it happened. */
export type Removal = { message: string; at: string | null }

/**
 * What a session that ends by itself (the 30-minute rule, a 401) leaves in the key-value store for the same guard
 * signing back in: their setup answers, the principal, the tour they have seen. Full storage keys (`v1:` + name).
 * A sign-out the guard chose still clears everything.
 */
export const KEEP_FOR_SIGNING_BACK_IN = ['v1:principal', 'v1:onboarding', 'v1:tour'] as const

/** What the enrolment screen says after "Remove this app from this phone" (Closing your account): the guard's own act. */
export const REMOVED_BY_YOU = 'You removed GeminiSecure from this phone. A new enrolment code from your supervisor sets it up again.'

const memory = new Map<string, string>()
let bound: Binding | null = null
let removed: Removal | null = null
let keyLost = false
let hydrated = false

async function read(key: string): Promise<string | null> {
  return isWeb ? (memory.get(key) ?? null) : SecureStore.getItemAsync(key, OPTIONS)
}

async function write(key: string, value: string | null): Promise<void> {
  if (isWeb) {
    if (value === null) memory.delete(key)
    else memory.set(key, value)
    return
  }
  if (value === null) await SecureStore.deleteItemAsync(key, OPTIONS)
  else await SecureStore.setItemAsync(key, value, OPTIONS)
}

function parse<T>(raw: string | null): T | null {
  if (!raw) return null
  try {
    return JSON.parse(raw) as T
  } catch {
    return null
  }
}

export const binding = {
  /** Read both records into memory. Called once at boot, before the session is restored. Never throws. */
  async hydrate(): Promise<void> {
    keyLost = false
    try {
      bound = parse<Binding>(await read(BINDING_KEY))
      removed = parse<Removal>(await read(REMOVED_KEY))
    } catch {
      bound = null
      removed = null
    }
    hydrated = true
  },

  isHydrated: (): boolean => hydrated,

  /** Whose phone this is, or null: never enrolled, its data cleared, or removed. */
  peek: (): Binding | null => bound,

  /** What the server said when it took this install out of service, or null. */
  removal: (): Removal | null => removed,

  /**
   * THE PHONE'S OWN KEY IS GONE, AND ONLY THAT (M46 §1). The binding is kept — only the server's answer ever ends it —
   * and the enrolment screen says the way back: the code this phone was set up with, which works again on this handset.
   * Memory only: the next opening finds out again.
   */
  markKeyLost(): void {
    keyLost = true
  },

  keyLost: (): boolean => keyLost,

  /** This install is bound to this guard: from enrolment, an approved move, a signed-in phone of an earlier version. */
  async set(next: Binding): Promise<void> {
    bound = next
    removed = null
    keyLost = false
    await write(BINDING_KEY, JSON.stringify(next))
    await write(REMOVED_KEY, null)
  },

  /** The server says this install is no longer the guard's: the binding goes, its sentence stays for the enrolment screen. */
  async markRemoved(removal: Removal): Promise<void> {
    bound = null
    removed = removal
    keyLost = false
    await write(BINDING_KEY, null)
    await write(REMOVED_KEY, JSON.stringify(removal))
  },

  /** The server knows no binding for this install at all: nothing to greet, nothing to say about a removal. */
  async forget(): Promise<void> {
    bound = null
    removed = null
    keyLost = false
    await write(BINDING_KEY, null)
    await write(REMOVED_KEY, null)
  },
}
