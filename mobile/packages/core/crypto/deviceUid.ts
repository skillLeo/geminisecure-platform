/**
 * A stable identifier for THIS install — what the server binds the account to. Made once, kept in
 * the secure store.
 *
 * Its own module (M11 A4): the Resident App needs this id and nothing else from `@gs/core/crypto`,
 * whose index also loads the pass verifier and tweetnacl (≈ 60 KB of JavaScript, and a PRNG set up
 * at launch) that only the Guard App uses. Import it from `@gs/core/crypto/deviceUid`.
 */

import * as Crypto from 'expo-crypto'
import { UID_KEY, read, save } from './deviceStore'

export async function deviceUid(): Promise<string> {
  const existing = await read(UID_KEY)
  if (existing) return existing
  const uid = Crypto.randomUUID()
  await save(UID_KEY, uid)
  return uid
}
