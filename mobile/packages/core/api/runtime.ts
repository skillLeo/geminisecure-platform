/**
 * Runtime helpers the generated API modules call.
 *
 * enforceAllowlist is INVARIANT 3 on the client: "a guard never sees money". Every
 * guard-scoped response is walked against the exact key paths the server's catalogue
 * lists for that endpoint. A key that is not listed is DROPPED before any screen can
 * render it — so even if the server one day leaked a balance, a bucket or a reason,
 * the Guard App could not show it — and the leak is reported loudly in development
 * and to the registered reporter in production. An allowlist, never a denylist: a
 * list of bad words already missed `reason` and `category_exempt` on the web build.
 */

import { RESPONSE_KEYS, type EndpointName } from './generated/endpoints'
import type { UploadFile } from './generated/types'

export function pick(source: Record<string, unknown>, names: readonly string[]): Record<string, unknown> {
  const out: Record<string, unknown> = {}
  for (const n of names) if (source[n] !== undefined) out[n] = source[n]
  return out
}

export function toFormData(fields: Record<string, unknown>): FormData {
  const form = new FormData()
  for (const [k, v] of Object.entries(fields)) {
    if (v === undefined || v === null) continue
    if (typeof v === 'object' && 'uri' in (v as object)) form.append(k, filePart(v as UploadFile))
    else form.append(k, String(v))
  }
  return form
}

/**
 * A PHOTO BOTH fetch IMPLEMENTATIONS CAN SEND (M12 §2.7: "the photo that could never send").
 *
 * Expo SDK 57 replaces the global `fetch` with its own (expo/src/winter/runtime.native.ts), and ITS FormData encoder
 * takes a string, a Blob, or anything with `bytes()` — never React Native's `{ uri, name, type }` file part, on which
 * it throws "Unsupported FormDataPart implementation" before a byte leaves the phone. That throw comes back as a
 * failed fetch, so the upload read as "no connection": the request reached the office, its photo stayed "waiting to
 * upload" for ever, retried with the same key into the same throw. Every upload in both apps came through here —
 * maintenance and report photos, the guard's incident photos, a resident's profile photo.
 *
 * So the part is still React Native's own idiom (its fetch reads `uri`, and spreads the part — `bytes` is not
 * enumerable, so it never reaches the native side), and it ALSO answers `bytes()`, which is what Expo's encoder asks
 * for: the file is read from the phone's storage at the moment it is sent, under the part's own name and type.
 */
function filePart(f: UploadFile): Blob {
  const part = { uri: f.uri, name: f.name, type: f.type }
  Object.defineProperty(part, 'bytes', { enumerable: false, value: () => fileBytes(f.uri) })
  // The cast is the platform's own idiom: React Native's FormData takes this object where the DOM takes a Blob.
  return part as unknown as Blob
}

async function fileBytes(uri: string): Promise<Uint8Array> {
  const { File } = require('expo-file-system') as { File: new (uri: string) => { bytes: () => Promise<Uint8Array> } }
  return new File(uri).bytes()
}

type LeakReporter = (endpoint: string, paths: string[]) => void
let reportLeak: LeakReporter = () => undefined

/** The app registers where a dropped key is reported (Sentry in production). */
export function onAllowlistLeak(reporter: LeakReporter): void {
  reportLeak = reporter
}

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v)

/** Every prefix of every allowed path is itself allowed: "shift.post.name" allows "shift" and "shift.post". */
const prefixCache = new Map<string, Set<string>>()
function allowedPrefixes(endpoint: EndpointName): Set<string> {
  let set = prefixCache.get(endpoint)
  if (!set) {
    set = new Set()
    for (const key of RESPONSE_KEYS[endpoint]) {
      const parts = key.split('.')
      for (let i = 1; i <= parts.length; i++) set.add(parts.slice(0, i).join('.'))
    }
    prefixCache.set(endpoint, set)
  }
  return set
}

export function enforceAllowlist<T>(endpoint: EndpointName, data: T): T {
  const allowed = allowedPrefixes(endpoint)
  const dropped: string[] = []

  function walk(value: unknown, pathSoFar: string): unknown {
    if (Array.isArray(value)) return value.map((item) => walk(item, pathSoFar ? `${pathSoFar}.*` : '*'))
    if (!isRecord(value)) return value
    const out: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(value)) {
      const p = pathSoFar ? `${pathSoFar}.${k}` : k
      if (!allowed.has(p)) {
        dropped.push(p)
        continue
      }
      out[k] = walk(v, p)
    }
    return out
  }

  const clean = walk(data, '') as T
  if (dropped.length) {
    reportLeak(endpoint, dropped)
    if (typeof __DEV__ !== 'undefined' && __DEV__) {
      console.error(`[allowlist] ${endpoint} returned keys the catalogue does not list: dropped: ${dropped.join(', ')}`)
    }
  }
  return clean
}
