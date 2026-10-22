/**
 * The one door to the API.
 *
 *   - Bearer token from SecureStore's in-memory copy; `Accept: application/json`.
 *   - 15-second timeout: a guard at a gate does not wait on a dead connection.
 *   - 401 handled globally, and the TWO KINDS are told apart (M6, Q-M42):
 *       `session_expired`  nothing was sent from this phone for thirty minutes and the
 *                          server closed the idle window. Nobody revoked anything.
 *       anything else      the token was revoked — a rebind, a new sign-in on this
 *                          install, or the office.
 *     Either way the token is cleared and the session ends, and the caller's
 *     `onSessionRevoked` clears every cache EXCEPT what was captured offline — which is
 *     why it is told which of the two happened. A 403 `account_revoked` — the
 *     estate office ending a Resident App account (Q-M44) — is treated the same way,
 *     because it is just as final and no screen in the app can recover from it.
 *   - Every error is normalised to `ApiError`; a raw body never reaches a screen.
 *   - Retries: network failures, timeouts and 5xx only, with backoff, and ONLY
 *     because writes carry an Idempotency-Key fixed before the first attempt
 *     (api/write.ts). A 4xx is an answer and is never retried. An endpoint may opt
 *     out with `extraOptions: { maxRetries: 0 }` — sign-in and the ballot do.
 *
 * NOTHING HERE LOGS A REQUEST OR RESPONSE BODY. The web build found a retry log
 * storing a hash of the ballot body, and a ballot's body is its option ids —
 * hashing every combination recovered the vote. There is no body logging to get
 * wrong: not the body, not a hash of it, not on error.
 */

import { fetchBaseQuery, retry, type BaseQueryFn, type FetchArgs } from '@reduxjs/toolkit/query/react'
import { tokenStorage } from '../auth/tokenStorage'
import { sessionExpired, sessionRevoked } from '../auth/sessionSlice'
import { apiUrl } from '../lib/env'
import { adoptSiteZone } from '../lib/siteTime'
import { deviceRun, deviceTimeIso, steadyMs } from '../lib/time'
import { recordLiveHead } from '../live/cursors'
import { ORIGIN_HEADER, newOrigin } from '../live/origin'
import { isRetryable, normalizeError, type ApiError } from './errors'

export const REQUEST_TIMEOUT_MS = 15_000

export type BaseQueryExtra = { maxRetries?: number }

/** The code the server gives a session that simply went quiet, rather than one anybody revoked (M6, Q-M42). */
export const SESSION_EXPIRED = 'session_expired'

/** Why a session ended without the person choosing to. Both keep whatever was captured offline. */
export type SessionEnded = 'revoked' | 'expired'

type Hooks = {
  /**
   * Clear every cache this app keeps: SecureStore, storage, image cache, RTK Query state.
   *
   * It is told WHICH of the two happened, because the answer decides what is kept: an
   * expired session and a revoked one both keep the offline outbox, and a later reason
   * might not — the app that owns the caches makes that call, not this file.
   */
  onSessionRevoked: (reason: SessionEnded) => Promise<void> | void
  /** Milliseconds to wait before retry `attempt` (1-based). */
  backoffMs: (attempt: number) => number
}

const defaultBackoff = (attempt: number): number => Math.min(4000, 400 * 2 ** attempt)

let hooks: Hooks = { onSessionRevoked: () => undefined, backoffMs: defaultBackoff }

/** Each app registers how it clears its caches when the server revokes the token. */
export function configureApi(next: Partial<Hooks>): void {
  hooks = { ...hooks, ...next }
}

const raw = fetchBaseQuery({
  baseUrl: '',
  timeout: REQUEST_TIMEOUT_MS,
  prepareHeaders(headers) {
    headers.set('Accept', 'application/json')
    const token = tokenStorage.peek()
    if (token) headers.set('Authorization', `Bearer ${token}`)
    // The phone's clock as this request leaves it, this run of the app, its steady clock (lib/time.ts; M12 §1.4).
    headers.set('X-Device-Sent-At', deviceTimeIso())
    headers.set('X-Device-Run', deviceRun())
    const steady = steadyMs()
    if (steady !== null) headers.set('X-Device-Mono', String(steady))
    return headers
  },
})

/*
 * LIVE (M11 Part C, DESIGN §5.2, §6.1). Two headers, and neither carries a body or anything about one:
 *
 *   X-Live-Origin  a fresh 16-hex nonce on every WRITE, the same one on each retry of it. The server stores it on
 *                  the event the write causes, so this install knows that event is its own echo (live/origin.ts).
 *                  Not on live's own control calls (/live/auth, /live/catch-up), which write no record.
 *   X-Live-Head    read off EVERY answer: the lowest per stream since the last connect is where a channel with no
 *                  cursor starts catching up, so nothing fetched before the socket existed is skipped (live/cursors.ts).
 */
const origins = new WeakMap<FetchArgs, string>()
const isWrite = (request: FetchArgs): boolean => !!request.method && !/^(GET|HEAD)$/i.test(request.method) && !request.url.startsWith('/live/')

function withOrigin(request: FetchArgs): FetchArgs {
  if (!isWrite(request)) return request
  // Keyed on the request object, which retry() hands back unchanged: one nonce per write, not per attempt.
  let nonce = origins.get(request)
  if (!nonce) origins.set(request, (nonce = newOrigin()))
  const given = request.headers
  if (given instanceof Headers) {
    const headers = new Headers(given)
    if (!headers.has(ORIGIN_HEADER)) headers.set(ORIGIN_HEADER, nonce)
    return { ...request, headers }
  }
  const pairs: [string, string | undefined][] = Array.isArray(given)
    ? given.map((pair): [string, string | undefined] => [String(pair[0] ?? ''), pair[1]])
    : Object.entries(given ?? {})
  if (pairs.some(([name]) => name.toLowerCase() === ORIGIN_HEADER.toLowerCase())) return request
  return { ...request, headers: { ...Object.fromEntries(pairs), [ORIGIN_HEADER]: nonce } }
}

const once: BaseQueryFn<string | FetchArgs, unknown, ApiError, BaseQueryExtra> = async (args, api, extra) => {
  const request: FetchArgs = typeof args === 'string' ? { url: args } : args
  // The token THIS request goes out with — a 401 can only ever be about that one.
  const sentWith = tokenStorage.peek()
  const result = await raw({ ...withOrigin(request), url: `${apiUrl()}${request.url}` }, api, extra ?? {})
  recordLiveHead(result.meta?.response?.headers.get('X-Live-Head'))

  if (!result.error) {
    // THE ONE CLOCK — the SITE's zone is a display fact the server sends on the enrolment, the guard's shift and
    // profile reads, sync.pull and the resident's estate. One reader takes it here, so every time either app
    // renders is in the site's zone whatever the handset's own zone says, and a change at the office lands
    // on the next read. It never decides what is SENT; that is the act's own moment.
    adoptSiteZone(result.data)
    return { data: result.data, meta: result.meta }
  }

  const error = normalizeError(result.error, result.meta?.response?.headers.get('Retry-After'))

  // A 401 revokes the session ONLY when it answers the token the phone still holds.
  //   - sent with NO token (a screen re-asking in the instant after a deliberate sign-out emptied the cache): there
  //     is no session to revoke — and treating it as one reported a chosen sign-out as "revoked";
  //   - sent with a token that has since been REPLACED (it lands, after its backoff, once someone has signed in
  //     again): revoking now would throw the NEW session away. (Found by the profile review, live, 2026-09-20.)
  /*
   * A 403 `account_revoked` IS AS FINAL AS A 401 (Q-M44). The estate office has ended
   * this Resident App account: the token is dead on every endpoint and only the office
   * can give it back, so leaving the app "signed in" would mean a home screen that
   * refuses every panel on it, for ever, with no way out but reinstalling. Held to the
   * same two conditions as the 401 for the same two reasons — nothing is revoked on
   * behalf of a request that carried no token, or one that has since been replaced.
   */
  const ended = error.status === 401 || (error.status === 403 && error.code === 'account_revoked')

  /*
   * THE IDLE WINDOW IS NOT A REVOCATION (M6, Q-M42). Nobody took this handset away: it
   * sent nothing for thirty minutes and the server closed the session by itself. Reported
   * as "revoked" it becomes, on a guard's screen, "the office signed this phone out" —
   * which would send an officer to a supervisor to ask a question nobody can answer. So
   * it gets its own reason all the way through, and the screens say the true thing.
   */
  const reason: SessionEnded = error.status === 401 && error.code === SESSION_EXPIRED ? 'expired' : 'revoked'

  if (ended && sentWith !== null && tokenStorage.peek() === sentWith) {
    await hooks.onSessionRevoked(reason)
    api.dispatch(reason === 'expired' ? sessionExpired() : sessionRevoked())
  }

  // A refusal is final: tell retry() to stop. Only a failure that the same request
  // (same Idempotency-Key) could survive is thrown back for another attempt.
  if (!isRetryable(error)) retry.fail(error)

  return { error, meta: result.meta }
}

export const baseQuery = retry(once, {
  maxRetries: 2,
  backoff: async (attempt) => {
    await new Promise((resolve) => setTimeout(resolve, hooks.backoffMs(attempt)))
  },
})
