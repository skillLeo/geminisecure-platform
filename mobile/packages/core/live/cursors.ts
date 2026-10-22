/**
 * WHERE THE PHONE IS UP TO, PER (CHANNEL, STREAM) (DESIGN §5.2, §5.3).
 *
 *   cursor   the last seq this install APPLIED on that channel in that stream, with the stream's epoch
 *
 * KEPT ON THE PHONE, KEYED BY PRINCIPAL: `jsonStore(getStorage(), 'live.cursors', 'v1')`, so a cold start
 * catches up from where the app stopped instead of from nothing. One principal at a time — writing for one
 * drops any other's (a phone that changed hands has no use for the last person's place). Every kind of
 * sign-out removes it: `clearEverything` keeps only the outbox's keys and the app's own `keep` list, and
 * this is neither (auth/clearEverything.ts; asserted in catchUp.test).
 *
 * THE FIRST CURSOR IS THE LOWEST `X-Live-Head` (§5.2). Every API answer carries `X-Live-Head: c=1042;e=88`,
 * the stream heads read when that request started. Everything at or below a head is already in that
 * answer's data, so the LOWEST head seen since the last connect is a point every screen's data has
 * passed: a channel with no cursor starts its first catch-up there, and nothing fetched before the socket
 * existed can be skipped. (api/baseQuery.ts records the header; this file keeps the minimum.)
 *
 * THE RULE THAT DECIDES EACH MESSAGE (§5.3), in this order:
 *
 *   epoch     a different epoch than the cursor's → the database was re-provisioned: resync
 *   duplicate q <= cursor → already applied: dropped
 *   gap       p > cursor → something on this channel was missed: catch up. (`p ≠ cursor` would catch up
 *             needlessly every time a cursor was seeded from a stream head.)
 *   apply     otherwise
 */

import { getStorage, jsonStore } from '../lib/storage'
import { STREAMS, type SequencedEnvelope, type Stream } from './envelope'

export type StreamCursor = { q: number; ep: string | null }
export type ChannelCursors = Partial<Record<Stream, StreamCursor>>
export type Heads = Partial<Record<Stream, number>>

type Saved = Record<string, Record<string, ChannelCursors>>

/** The full storage key, as `storage.keys()` lists it — for tests and for anyone auditing what sign-out clears. */
export const CURSORS_STORAGE_KEY = 'v1:live.cursors'

const box = () => jsonStore<Saved>(getStorage(), 'live.cursors', 'v1')

const isCursor = (v: unknown): v is StreamCursor =>
  typeof v === 'object' && v !== null && Number.isSafeInteger((v as StreamCursor).q) && (v as StreamCursor).q >= 0 && ((v as StreamCursor).ep === null || typeof (v as StreamCursor).ep === 'string')

export const savedCursors = {
  /** This principal's cursors, as they were last saved. Anything malformed is dropped, never trusted. */
  load(principal: string): Record<string, ChannelCursors> {
    const mine = box().get()?.[principal]
    if (!mine || typeof mine !== 'object') return {}
    const out: Record<string, ChannelCursors> = {}
    for (const [channel, cursors] of Object.entries(mine)) {
      const kept: ChannelCursors = {}
      for (const s of STREAMS) if (isCursor(cursors?.[s])) kept[s] = { q: cursors[s]!.q, ep: cursors[s]!.ep }
      if (Object.keys(kept).length) out[channel] = kept
    }
    return out
  },
  /** Saves this principal's cursors and nobody else's. */
  save(principal: string, channels: Record<string, ChannelCursors>): void {
    box().set({ [principal]: channels })
  },
  clear(): void {
    box().remove()
  },
}

/* ── the first cursor: the lowest X-Live-Head since the last connect ──────────────────────────────── */

let sinceConnect: Heads = {}
let beforeConnect: Heads = {}

const lower = (a: Heads, b: Heads): Heads => {
  const out: Heads = { ...a }
  for (const s of STREAMS) {
    const v = b[s]
    if (v !== undefined && (out[s] === undefined || v < out[s]!)) out[s] = v
  }
  return out
}

/** `c=1042;e=88` → `{c: 1042, e: 88}`. A malformed part is ignored; nothing usable → `null`. */
export function parseLiveHead(header: string | null | undefined): Heads | null {
  if (!header) return null
  const heads: Heads = {}
  for (const part of header.split(';')) {
    const m = /^\s*([ce])\s*=\s*(\d{1,15})\s*$/.exec(part)
    if (m) heads[m[1] as Stream] = Number(m[2])
  }
  return Object.keys(heads).length ? heads : null
}

/** Called by the base query with every answer's `X-Live-Head`. Keeps the LOWEST per stream. */
export function recordLiveHead(header: string | null | undefined): void {
  const heads = parseLiveHead(header)
  if (heads) sinceConnect = lower(sinceConnect, heads)
}

/**
 * The socket connected. What was recorded until now is the data fetched while it was not — kept as the
 * floor for channels that have no cursor yet — and a new window starts.
 */
export function markConnected(): void {
  beforeConnect = sinceConnect
  sinceConnect = {}
}

/** The lowest head per stream across the window before the last connect and everything since. */
export const firstHeads = (): Heads => lower(beforeConnect, sinceConnect)

/** For tests, and for a session that ended. */
export function forgetHeads(): void {
  sinceConnect = {}
  beforeConnect = {}
}

/* ── the rule ──────────────────────────────────────────────────────────────────────────────────── */

export type Verdict = 'apply' | 'duplicate' | 'gap' | 'epoch'

/** What to do with a socket message on a live channel whose cursor for that stream is `cursor`. */
export function judge(cursor: StreamCursor | undefined, env: SequencedEnvelope): Verdict {
  // No cursor for this stream: nothing to compare with, so the channel is caught up first.
  if (!cursor) return 'gap'
  if (cursor.ep !== null && env.ep !== cursor.ep) return 'epoch'
  if (env.q <= cursor.q) return 'duplicate'
  if (env.p !== null && env.p > cursor.q) return 'gap'
  return 'apply'
}
