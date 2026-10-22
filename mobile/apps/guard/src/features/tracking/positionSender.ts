/**
 * SENDING WHAT WAS SAMPLED — `POST /shifts/{shift}/positions`, in batches, as a plain request.
 *
 * Not through the store and the generated hooks, on purpose: this also runs in the background task, where Android
 * may have started the app with no screen, no store hydrated and no React at all. It needs three things, and each
 * is read where it lives: the token (SecureStore, via tokenStorage), the server (apiUrl) and the queue
 * (trackingStore). Nothing here logs a body.
 *
 * What each answer means for the queue (trackingPolicy has the rules; __tests__/positionSender.test.ts proves them):
 *   200               every fix the server kept or refused leaves; a refused fix is never resent
 *   403 tracking_off / consent_required
 *                     stop sharing: the server will keep nothing, so sampling on would only flatten the battery
 *   403 not_your_shift, 404
 *                     that shift's fixes can never be kept — they leave
 *   401               the session is gone — nothing leaves, and nothing more is sent from here
 *   anything else     a failure — nothing leaves, and it is sent again later (the same fix ids: stored once)
 */

import { tokenStorage } from '@gs/core/auth'
import { apiUrl, deviceTimeIso, newUuid } from '@gs/core/lib'
import { REQUEST_TIMEOUT_MS } from '@gs/core/api'
import type { Fix } from './trackingPolicy'

export type SendAnswer =
  | { kind: 'answered'; accepted: string[]; refused: { id: string; code: string; message: string }[] }
  | { kind: 'stop'; code: 'tracking_off' | 'consent_required' }
  | { kind: 'not_this_shift' }
  | { kind: 'signed_out' }
  | { kind: 'failed' }

type Deps = { fetch?: typeof fetch; token?: () => Promise<string | null>; baseUrl?: () => string }

async function tokenNow(): Promise<string | null> {
  // In a background start the module is fresh and the in-memory copy is empty: read it from SecureStore once.
  return tokenStorage.peek() ?? (await tokenStorage.hydrate('guard').catch(() => null))
}

export async function sendPositions(shiftId: number | string, fixes: readonly Fix[], deps: Deps = {}): Promise<SendAnswer> {
  const token = await (deps.token ?? tokenNow)()
  if (!token) return { kind: 'signed_out' }

  const controller = typeof AbortController === 'undefined' ? null : new AbortController()
  const timer = controller ? setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS) : null

  try {
    const response = await (deps.fetch ?? fetch)(`${(deps.baseUrl ?? apiUrl)()}/shifts/${encodeURIComponent(String(shiftId))}/positions`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        // A fresh key per batch; the fixes carry their own ids, so a resent fix is stored once whatever the key.
        'Idempotency-Key': newUuid(),
        // The phone's clock NOW, when the batch is sent: the server corrects every capture time by it.
        'X-Device-Time': deviceTimeIso(),
      },
      body: JSON.stringify({ positions: fixes }),
      signal: controller?.signal,
    })

    const body = (await response.json().catch(() => null)) as { accepted?: unknown; refused?: unknown; error?: { code?: unknown } } | null
    const code = typeof body?.error?.code === 'string' ? body.error.code : null

    if (response.ok) {
      const accepted = Array.isArray(body?.accepted) ? body.accepted.map(String) : []
      const refused = Array.isArray(body?.refused)
        ? (body.refused as { id?: unknown; code?: unknown; message?: unknown }[]).map((r) => ({ id: String(r.id), code: String(r.code ?? ''), message: String(r.message ?? '') }))
        : []
      return { kind: 'answered', accepted, refused }
    }
    if (response.status === 401) return { kind: 'signed_out' }
    if (response.status === 403 && (code === 'tracking_off' || code === 'consent_required')) return { kind: 'stop', code }
    if (response.status === 404 || (response.status === 403 && code === 'not_your_shift')) return { kind: 'not_this_shift' }
    return { kind: 'failed' }
  } catch {
    return { kind: 'failed' }
  } finally {
    if (timer) clearTimeout(timer)
  }
}
