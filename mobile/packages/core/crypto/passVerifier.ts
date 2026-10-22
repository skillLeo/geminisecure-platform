/**
 * INVARIANT 2 — a visitor pass verifies on the handset with NO network and NO database.
 *
 * The handset holds PUBLIC keys only. A pass in a QR code is
 *     base64url(payload) "." base64url(signature)
 * where the payload is canonical JSON with exactly these eight fields in this order:
 *     pass_id · tenant_id · site_id · valid_from · valid_to · single_use · nonce · key_version
 * It names no visitor, unit or household — a QR code is shown to whoever stands near it.
 *
 * This is the server's `OfflinePassVerifier`, step for step:
 *   1 two halves, a 64-byte signature, those eight keys in that order   else malformed
 *   2 site_id is THIS handset's site — decided before any key is touched else wrong_site
 *   3 a cached public key for (site_id, key_version)                      else unknown_key
 *   4 Ed25519 detached signature over the payload SEGMENT'S ASCII BYTES   else bad_signature
 *     (the base64url text exactly as it appears — not the decoded JSON)
 *   5 the window against the handset's clock, 120 s tolerance            else not_yet_valid / expired
 *   6 not in the revoked list learned at the last sync                   else revoked
 *   7 otherwise valid_offline — NEVER "valid": a cancellation, or a single-use pass
 *     already used at another gate, is only knowable online.
 *
 * WRONG SITE NEVER SAYS WHICH SITE. The verdict carries no tenant and no site — a
 * guard at one estate must not learn which other estate a stranger holds a pass for.
 *
 * No clock, no storage and no network are read in here: everything arrives as an
 * argument, which is what makes it testable with the world switched off.
 */

import nacl from 'tweetnacl'

export type PassPayload = {
  pass_id: string
  tenant_id: string | number
  site_id: string | number
  valid_from: string
  valid_to: string
  single_use: boolean
  nonce: string
  key_version: number
}

export type PassKey = { version: number; public_key: string }
export type RevokedPass = { pass_id: string; status: 'cancelled' | 'used' }

export type OfflineVerdict =
  | { verdict: 'valid_offline'; pass: Pick<PassPayload, 'pass_id' | 'valid_from' | 'valid_to' | 'single_use'> }
  | { verdict: 'malformed' | 'wrong_site' | 'unknown_key' | 'bad_signature' }
  | { verdict: 'not_yet_valid' | 'expired'; pass: Pick<PassPayload, 'pass_id' | 'valid_from' | 'valid_to' | 'single_use'> }
  | { verdict: 'revoked'; reason: 'cancelled' | 'used'; pass: Pick<PassPayload, 'pass_id' | 'valid_from' | 'valid_to' | 'single_use'> }

export const PAYLOAD_KEYS = ['pass_id', 'tenant_id', 'site_id', 'valid_from', 'valid_to', 'single_use', 'nonce', 'key_version'] as const
export const CLOCK_TOLERANCE_MS = 120_000

const B64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'

/** base64url → bytes, with no dependency on atob/Buffer, so it behaves identically on Hermes, web and Node. */
export function base64UrlDecode(text: string): Uint8Array | null {
  const clean = text.replace(/=+$/, '')
  if (clean.length === 0 || clean.length % 4 === 1) return null
  const out = new Uint8Array(Math.floor((clean.length * 3) / 4))
  let bits = 0
  let acc = 0
  let o = 0
  for (const ch of clean) {
    const v = B64.indexOf(ch)
    if (v === -1) return null
    acc = (acc << 6) | v
    bits += 6
    if (bits >= 8) {
      bits -= 8
      out[o++] = (acc >> bits) & 0xff
    }
  }
  return out
}

function utf8Decode(bytes: Uint8Array): string {
  let s = ''
  for (let i = 0; i < bytes.length; ) {
    const b = bytes[i++]!
    if (b < 0x80) s += String.fromCharCode(b)
    else if (b < 0xe0) s += String.fromCharCode(((b & 0x1f) << 6) | (bytes[i++]! & 0x3f))
    else if (b < 0xf0) s += String.fromCharCode(((b & 0x0f) << 12) | ((bytes[i++]! & 0x3f) << 6) | (bytes[i++]! & 0x3f))
    else {
      const cp = (((b & 0x07) << 18) | ((bytes[i++]! & 0x3f) << 12) | ((bytes[i++]! & 0x3f) << 6) | (bytes[i++]! & 0x3f)) - 0x10000
      s += String.fromCharCode(0xd800 + (cp >> 10), 0xdc00 + (cp & 0x3ff))
    }
  }
  return s
}

const asciiBytes = (text: string): Uint8Array => Uint8Array.from(text, (c) => c.charCodeAt(0) & 0xff)

function parsePayload(segment: string): PassPayload | null {
  const bytes = base64UrlDecode(segment)
  if (!bytes) return null
  let json: unknown
  try {
    json = JSON.parse(utf8Decode(bytes))
  } catch {
    return null
  }
  if (typeof json !== 'object' || json === null || Array.isArray(json)) return null
  const keys = Object.keys(json)
  if (keys.length !== PAYLOAD_KEYS.length || keys.some((k, i) => k !== PAYLOAD_KEYS[i])) return null
  const p = json as PassPayload
  if (typeof p.pass_id !== 'string' || typeof p.valid_from !== 'string' || typeof p.valid_to !== 'string' || typeof p.single_use !== 'boolean') return null
  if (typeof p.key_version !== 'number' || Number.isNaN(Date.parse(p.valid_from)) || Number.isNaN(Date.parse(p.valid_to))) return null
  return p
}

export function verifyPassOffline(input: {
  token: string
  /** The site this handset is posted to — from the enrolment, never from the pass. */
  siteId: string | number
  /** Public keys cached from GET /sites/{site}/pass-keys and /sync/pull. */
  keys: readonly PassKey[]
  /** Passes cancelled or used since, from /sync/pull. */
  revoked?: readonly RevokedPass[]
  /** The handset's clock. */
  now: Date
}): OfflineVerdict {
  const parts = input.token.trim().split('.')
  if (parts.length !== 2 || !parts[0] || !parts[1]) return { verdict: 'malformed' }
  const [segment, sigText] = parts as [string, string]

  const signature = base64UrlDecode(sigText)
  if (!signature || signature.length !== 64) return { verdict: 'malformed' }
  const payload = parsePayload(segment)
  if (!payload) return { verdict: 'malformed' }

  // Before any key: a pass for somewhere else is refused without learning, or saying, where.
  if (String(payload.site_id) !== String(input.siteId)) return { verdict: 'wrong_site' }

  const key = input.keys.find((k) => k.version === payload.key_version)
  const publicKey = key ? base64UrlDecode(key.public_key) : null
  if (!publicKey || publicKey.length !== 32) return { verdict: 'unknown_key' }

  if (!nacl.sign.detached.verify(asciiBytes(segment), signature, publicKey)) return { verdict: 'bad_signature' }

  const pass = { pass_id: payload.pass_id, valid_from: payload.valid_from, valid_to: payload.valid_to, single_use: payload.single_use }
  const t = input.now.getTime()
  if (t < Date.parse(payload.valid_from) - CLOCK_TOLERANCE_MS) return { verdict: 'not_yet_valid', pass }
  if (t > Date.parse(payload.valid_to) + CLOCK_TOLERANCE_MS) return { verdict: 'expired', pass }

  const gone = input.revoked?.find((r) => r.pass_id === payload.pass_id)
  if (gone) return { verdict: 'revoked', reason: gone.status, pass }

  return { verdict: 'valid_offline', pass }
}
