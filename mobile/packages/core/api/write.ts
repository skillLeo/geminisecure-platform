/**
 * Builds a WRITE request — every POST/PUT/PATCH/DELETE in both apps goes through here.
 *
 * Two headers make a write safe to retry and honest about when it happened:
 *
 *   Idempotency-Key   a UUID chosen when the ACT happens. The server stores its
 *                     first answer against the key and replays it byte for byte,
 *                     so a retry can never do the thing twice.
 *   X-Device-Time     the handset's own clock at that moment, with its offset.
 *
 * Both are fixed HERE, once, when the mutation is started — not inside the base
 * query, which runs again on every retry and would mint a fresh key each time and
 * turn one act into several. An act captured offline passes the key and time it
 * was captured with (`stamp`), so the outbox replays the original, not a copy.
 */

import type { FetchArgs } from '@reduxjs/toolkit/query'
import { deviceTimeIso } from '../lib/time'
import { newUuid } from '../lib/uuid'

export type WriteStamp = { idempotencyKey: string; deviceTime: string }

export const newStamp = (): WriteStamp => ({ idempotencyKey: newUuid(), deviceTime: deviceTimeIso() })

export function write(
  url: string,
  body?: unknown,
  opts: { method?: 'POST' | 'PUT' | 'PATCH' | 'DELETE'; stamp?: WriteStamp } = {},
): FetchArgs {
  const stamp = opts.stamp ?? newStamp()
  return {
    url,
    method: opts.method ?? 'POST',
    body,
    headers: {
      'Idempotency-Key': stamp.idempotencyKey,
      'X-Device-Time': stamp.deviceTime,
    },
  }
}
