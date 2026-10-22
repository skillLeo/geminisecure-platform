/**
 * "FACE ID / FINGERPRINT" SIGNS THIS PHONE BACK IN (M49, M50 §3) — the phone's half of the server's device key.
 *
 * WHY IT DID NOT WORK BEFORE, IN ONE LINE: the switch only set an app-lock flag in the app's own storage, which a sign-out
 * wipes, and "Log in with biometrics" read nothing at all — with no password, a signed-out phone had nothing that could
 * prove who held it. Now:
 *
 *   ON      the server gives THIS install a key (me.device_key.store). It is kept in the phone's secure store behind the
 *           phone's OWN biometric prompt (`requireAuthentication`) — the app never sees a fingerprint, and the key cannot
 *           be read without the owner's finger or face. Beside it, not secret and with no prompt: which estate and
 *           account it signs in to, so Log in knows, before asking anything, that fingerprint sign-in is set up here.
 *   LOG IN  the prompt opens the store; the key, with this install's id, signs the phone in exactly as a proven code
 *           does (auth.device_key → openSession). A cancelled or failed prompt leaves the email and code, unchanged.
 *   OFF     the key stops working on the server (me.device_key.destroy) and is deleted here.
 *
 * NEITHER LIVES IN THE APP'S OWN STORAGE, so a sign-out (which wipes that) does not undo the setting on this phone
 * (§3.2e). An uninstall does — the phone's secure store goes with the app — and the next sign-in is by email once.
 * New fingerprints enrolled on the phone make the key unreadable: that too falls back to email, and the key is forgotten.
 */

import { Platform } from 'react-native'
import * as Application from 'expo-application'
import * as Device from 'expo-device'
import * as SecureStore from 'expo-secure-store'
import { isFidelity, isWeb } from '@gs/core'

const KEY = 'gs.resident.fingerprint_key'
const META = 'gs.resident.fingerprint_meta'
/** The key's own options: readable only after the phone's biometric prompt, and only on this device. */
const GUARDED: SecureStore.SecureStoreOptions = { requireAuthentication: true, keychainAccessible: SecureStore.WHEN_PASSCODE_SET_THIS_DEVICE_ONLY }
const PLAIN: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY }

/** Who and where the key signs in to — what Log in needs before it asks anything. Not a secret. */
export type FingerprintFor = { estate: string; estateName: string | null; accountId: number; accountName: string | null; email: string | null }

/** Why a read gave no key: the person cancelled, or the store could not give one (changed biometrics, no lock). */
export type KeyRead = { key: string } | { why: 'cancelled' | 'unreadable' }

/** Whether this phone can keep a key behind its own biometrics at all: a phone app, a screen lock, an enrolled biometric. */
export const canUseFingerprint = (): boolean => !isWeb && !isFidelity && SecureStore.canUseBiometricAuthentication()

/**
 * The handset's own id — Android's per-app device id, which a reinstall of the same app keeps; iOS's vendor id. Sent with
 * every sign-in so the server ends this handset's earlier sign-ins instead of adding one (M50 §3.2f). Null on the web.
 */
export async function handsetId(): Promise<string | null> {
  try {
    if (Platform.OS === 'android') return Application.getAndroidId() || null
    if (Platform.OS === 'ios') return (await Application.getIosIdForVendorAsync()) ?? null
  } catch {
    /* no id to give: the sign-in goes without one */
  }
  return null
}

/**
 * What the phone calls itself, for the account's list of signed-in phones: its own name ("Galaxy S25 Ultra", or what its
 * owner named it) before its model code ("SM-S938B") — never a build string (M50 §3.2h; the server names the emulator).
 */
export const phoneName = (): string | null => (Device.deviceName || Device.modelName || null)?.slice(0, 80) ?? null

/** Who the key on this phone is for, or null when fingerprint sign-in is not set up here. Never asks for a finger. */
export async function fingerprintFor(): Promise<FingerprintFor | null> {
  if (!canUseFingerprint()) return null
  try {
    const raw = await SecureStore.getItemAsync(META, PLAIN)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<FingerprintFor>
    return typeof parsed.estate === 'string' && typeof parsed.accountId === 'number'
      ? { estate: parsed.estate, estateName: parsed.estateName ?? null, accountId: parsed.accountId, accountName: parsed.accountName ?? null, email: parsed.email ?? null }
      : null
  } catch {
    return null
  }
}

/** Keep the key the server just gave — behind the phone's own prompt, which asks now. False if the person cancelled. */
export async function keepKey(key: string, meta: FingerprintFor): Promise<boolean> {
  try {
    await SecureStore.setItemAsync(KEY, key, { ...GUARDED, authenticationPrompt: 'Confirm it is you to turn on fingerprint sign-in' })
    await SecureStore.setItemAsync(META, JSON.stringify(meta), PLAIN)
    return true
  } catch {
    await forgetKey()
    return false
  }
}

/** Open the store with the phone's own prompt. A cancel is a cancel; anything else is a key this phone can no longer read. */
export async function readKey(): Promise<KeyRead> {
  try {
    const key = await SecureStore.getItemAsync(KEY, { ...GUARDED, authenticationPrompt: 'Log in to GeminiSecure' })
    return key ? { key } : { why: 'unreadable' }
  } catch (e) {
    const text = String((e as { message?: string } | null)?.message ?? e).toLowerCase()
    return { why: /cancel|user|dismiss|abort/.test(text) ? 'cancelled' : 'unreadable' }
  }
}

/** Forget the key and who it was for. Never throws. */
export async function forgetKey(): Promise<void> {
  await SecureStore.deleteItemAsync(KEY, PLAIN).catch(() => undefined)
  await SecureStore.deleteItemAsync(META, PLAIN).catch(() => undefined)
}
