/**
 * THE ORIGIN NONCE (DESIGN §6.1, §8.7) — how this install recognises the live echo of its OWN write.
 *
 * Every write the phone sends carries `X-Live-Origin: <16 random hex>` (api/baseQuery.ts adds it). The server
 * stores it on the event the write causes (`o` in the envelope), and when that event comes back the phone
 * knows it made it: the outbox's confirmation stays the ONLY path for the phone's own acts, so an own event
 * is never inserted into a cache a second time.
 *
 * A NONCE, NEVER AN ID. A fresh value per write, remembered for two minutes and then forgotten, so it can
 * never become a pseudonym for this handset (the security judge's finding). It is minted from the same
 * randomness as an Idempotency-Key (lib/uuid.ts), with the UUID's fixed version and variant digits removed.
 */

import { newUuid } from '../lib/uuid'

export const ORIGIN_HEADER = 'X-Live-Origin'

/** How long a nonce this install sent is recognised: the event is published within seconds of the commit. */
export const ORIGIN_MEMORY_MS = 2 * 60_000

const sent = new Map<string, number>()

function forgetOld(at: number): void {
  for (const [nonce, when] of sent) if (at - when > ORIGIN_MEMORY_MS) sent.delete(nonce)
}

/** 16 hex digits of randomness: a UUID v4 has 30 random ones once its version and variant digits are taken out. */
function randomHex16(): string {
  const hex = newUuid().replace(/-/g, '').toLowerCase()
  const random = hex.slice(0, 12) + hex.slice(13, 16) + hex.slice(17)
  return random.slice(-16).padStart(16, '0')
}

/** A fresh nonce for one write, remembered from this moment (its event can arrive before the HTTP answer does). */
export function newOrigin(at: number = Date.now()): string {
  forgetOld(at)
  const nonce = randomHex16()
  sent.set(nonce, at)
  return nonce
}

/** Whether `origin` is a nonce this install sent in the last two minutes. */
export function isOwnOrigin(origin: string | null | undefined, at: number = Date.now()): boolean {
  if (!origin) return false
  const when = sent.get(origin)
  if (when === undefined) return false
  if (at - when > ORIGIN_MEMORY_MS) {
    sent.delete(origin)
    return false
  }
  return true
}

/** For tests, and for a session that ended: nothing this install sent is recognised any more. */
export function forgetOrigins(): void {
  sent.clear()
}
