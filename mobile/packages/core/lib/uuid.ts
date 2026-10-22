import * as Crypto from 'expo-crypto'

/**
 * A client-generated UUID. One per ACT, chosen when the act happens, stored with
 * the queued act and reused on every retry until the server answers — that is
 * what makes a retried write safe by construction (Idempotency-Key).
 */
export const newUuid = (): string => Crypto.randomUUID()
