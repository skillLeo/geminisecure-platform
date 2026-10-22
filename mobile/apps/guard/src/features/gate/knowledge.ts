/**
 * What this handset knows about passes when it has no signal (invariant 2).
 *
 *   keys      the site's Ed25519 PUBLIC keys, every version a still-valid pass may carry
 *   revoked   passes cancelled or used since — learned at each pull, never forgotten early
 *   since     the server's `next_since`, so each pull asks only for what changed
 *
 * Public keys and pass ids only — nothing here is a secret, and nothing here names a
 * visitor, a unit or a household. It lives in the key-value store so it survives a restart
 * with no network; a revoked session clears it with everything else.
 *
 * `refresh()` is called when a gate screen opens and on every reconnect BEFORE the outbox
 * drains ("pull first whenever signal returns, then upload"). A failed refresh changes
 * nothing: stale knowledge is still knowledge, and its age is shown to the guard.
 */

import { useSyncExternalStore } from 'react'
import { deviceTimeIso, getStorage, jsonStore } from '@gs/core'
import { guardApi } from '@gs/core/api/guard'
import type { PassKey, RevokedPass } from '@gs/core/crypto'
import { store } from '@/store'

export type GateKnowledge = {
  siteId: string | null
  keys: PassKey[]
  revoked: RevokedPass[]
  since: string | null
  /** When the keys and the revoked list were last confirmed with the server (device time). */
  refreshedAt: string | null
}

/** Bounded: ids of passes that expired long ago are useless, and a guard's phone is not a ledger. */
const MAX_REVOKED = 5000

const EMPTY: GateKnowledge = { siteId: null, keys: [], revoked: [], since: null, refreshedAt: null }
const box = () => jsonStore<GateKnowledge>(getStorage(), 'gate.knowledge', 'v1')

let snapshot: GateKnowledge | null = null
const listeners = new Set<() => void>()
let inFlight: Promise<'ok' | 'failed'> | null = null

// Never memoise "nothing": before storage is hydrated the box reads empty, and caching that
// would hide the real knowledge on disk for the rest of the run.
const read = (): GateKnowledge => (snapshot ??= box().get()) ?? EMPTY

function write(next: GateKnowledge): void {
  snapshot = next
  box().set(next)
  listeners.forEach((fn) => fn())
}

const mergeKeys = (known: PassKey[], fresh: readonly PassKey[]): PassKey[] => {
  const byVersion = new Map(known.map((k) => [k.version, k]))
  for (const k of fresh) byVersion.set(k.version, { version: Number(k.version), public_key: k.public_key })
  return [...byVersion.values()].sort((p, q) => p.version - q.version)
}

/** One row per pass however often a pull repeats it — the server's cursor overlaps on purpose (B056). */
export const mergeRevoked = (known: RevokedPass[], fresh: readonly RevokedPass[]): RevokedPass[] => {
  const byId = new Map(known.map((r) => [r.pass_id, r]))
  for (const r of fresh) byId.set(r.pass_id, { pass_id: r.pass_id, status: r.status })
  return [...byId.values()].slice(-MAX_REVOKED)
}

async function refreshNow(siteId: string): Promise<'ok' | 'failed'> {
  // Another site's keys and revocations are not this site's: a re-posted handset starts clean.
  const base = read().siteId === siteId ? read() : { ...EMPTY, siteId }

  const [keys, pull] = await Promise.all([
    store.dispatch(guardApi.endpoints.passesKeys.initiate({ site: siteId }, { forceRefetch: true, subscribe: false })),
    store.dispatch(guardApi.endpoints.syncPull.initiate(base.since ? { since: base.since } : {}, { forceRefetch: true, subscribe: false })),
  ])

  if (!keys.data && !pull.data) return 'failed'

  const pulledKeys: PassKey[] = (pull.data?.passes?.keys ?? []).map((k) => ({ version: Number(k.version), public_key: k.public_key }))
  const siteKeys: PassKey[] = (keys.data?.keys ?? []).map((k) => ({ version: Number(k.version), public_key: k.public_key }))
  const revoked: RevokedPass[] = (pull.data?.passes?.revoked ?? []).map((r) => ({ pass_id: String(r.pass_id), status: r.status === 'used' ? 'used' : 'cancelled' }))

  /*
   * MERGED INTO THE RECORD AS IT IS NOW, not as it was before the awaits (M11 Part C, DESIGN §5.5). A pass the
   * live channel revoked, or this handset used, while the pull was out would otherwise be written away by the
   * pull's older answer — and the next scan of a cancelled pass would admit.
   */
  // A record with no site yet holds only what this run learnt since it started (a live revocation): it is this site's.
  const now = read()
  const current = now.siteId === siteId || now.siteId === null ? { ...now, siteId } : base

  write({
    siteId,
    keys: mergeKeys(mergeKeys(current.keys, pulledKeys), siteKeys),
    revoked: mergeRevoked(revoked, current.revoked),
    since: pull.data?.next_since ?? current.since,
    refreshedAt: deviceTimeIso(),
  })
  return 'ok'
}

export const gateKnowledge = {
  get: read,
  subscribe(fn: () => void): () => void {
    listeners.add(fn)
    return () => listeners.delete(fn)
  },
  /** One refresh at a time; callers that arrive meanwhile share its answer. */
  refresh(siteId: string | number | null | undefined): Promise<'ok' | 'failed'> {
    if (siteId === null || siteId === undefined) return Promise.resolve('failed')
    inFlight ??= refreshNow(String(siteId))
      .catch(() => 'failed' as const)
      .finally(() => {
        inFlight = null
      })
    return inFlight
  },
  /** A pass admitted or seen used on THIS handset is refused here from now on, signal or not. */
  markUsed(passId: string): void {
    const now = read()
    write({ ...now, revoked: mergeRevoked(now.revoked, [{ pass_id: passId, status: 'used' }]) })
  },
  /**
   * A pass the server says is no longer good — cancelled by its household, or used at another gate — learnt from
   * the live channel (M11 Part C, DESIGN §2.9 I5–I7): refused here from now on, signal or not, before any pull.
   */
  learnRevoked(passId: string, status: 'cancelled' | 'used'): void {
    if (passId === '') return
    const now = read()
    write({ ...now, revoked: mergeRevoked(now.revoked, [{ pass_id: passId, status }]) })
  },
  /** For tests and for a session that ended. */
  forget(): void {
    snapshot = null
  },
}

export const useGateKnowledge = (): GateKnowledge => useSyncExternalStore(gateKnowledge.subscribe, gateKnowledge.get, gateKnowledge.get)
