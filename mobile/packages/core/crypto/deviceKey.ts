/**
 * The handset's own Ed25519 key pair, made at enrolment (POST /devices/enrol takes the
 * public half). The secret half goes straight into the secure store and never leaves
 * the device; nothing else in the app reads it.
 *
 * tweetnacl is pure JavaScript, so this works in Expo Go. Its randomness comes from
 * expo-crypto (the OS generator) — Hermes has no crypto.getRandomValues of its own.
 *
 * The install's id lives in deviceUid.ts and the secure-store helpers in deviceStore.ts
 * (M11 A4), so an app that needs only the id never loads tweetnacl.
 */

import * as Crypto from 'expo-crypto'
import nacl from 'tweetnacl'
import { SECRET_KEY, read, save } from './deviceStore'

nacl.setPRNG((x, n) => {
  const bytes = Crypto.getRandomBytes(n)
  for (let i = 0; i < n; i++) x[i] = bytes[i]!
})

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'
export function base64UrlEncode(bytes: Uint8Array): string {
  let out = ''
  for (let i = 0; i < bytes.length; i += 3) {
    const a = bytes[i]!, b = bytes[i + 1], c = bytes[i + 2]
    out += B64[a >> 2]! + B64[((a & 3) << 4) | ((b ?? 0) >> 4)]!
    if (b !== undefined) out += B64[((b & 15) << 2) | ((c ?? 0) >> 6)]!
    if (c !== undefined) out += B64[c & 63]!
  }
  return out
}

/** The public key to send at enrolment (base64url, 32 bytes). Creates the pair on first use. */
export async function devicePublicKey(): Promise<string> {
  const stored = await read(SECRET_KEY)
  if (stored) {
    const secret = Uint8Array.from(stored.split(',').map(Number))
    return base64UrlEncode(nacl.sign.keyPair.fromSecretKey(secret).publicKey)
  }
  const pair = nacl.sign.keyPair()
  await save(SECRET_KEY, Array.from(pair.secretKey).join(','))
  return base64UrlEncode(pair.publicKey)
}

/** Whether this phone holds a key at all — never makes one. None: never enrolled, or its data was cleared. */
export async function hasDeviceKey(): Promise<boolean> {
  return (await read(SECRET_KEY)) !== null
}

/**
 * What a guard's phone signs to sign back in (M32 F3): the install and the server's one-time challenge. The server
 * builds the same bytes and checks them against the public key it bound at enrolment. ASCII only — an install id and
 * a base64url challenge.
 */
export const resumeMessage = (deviceUid: string, challenge: string): string => `gs-resume-v1|${deviceUid}|${challenge}`

/**
 * Sign with this phone's own key, the one enrolment bound (M32 F3) — the proof that this is the install the server
 * knows, without a code. The secret half never leaves the store. Null when the phone holds no key.
 */
export async function signWithDeviceKey(message: string): Promise<string | null> {
  const stored = await read(SECRET_KEY)
  if (!stored) return null
  const secret = Uint8Array.from(stored.split(',').map(Number))
  const bytes = Uint8Array.from(message, (ch) => ch.charCodeAt(0) & 0x7f)
  return base64UrlEncode(nacl.sign.detached(bytes, secret))
}
