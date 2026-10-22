/**
 * One error shape for the whole app, whatever the server (or the network) did.
 *
 * The API answers `{"error": {"code": "snake_case", "message": "A sentence to show."}}`
 * and a 422 validation_failed adds a TOP-LEVEL `errors: {field: [messages]}`
 * (confirmed against the live server, 2026-09-18). Branch on `code`, never on the
 * status alone; show `message`, which is written for the person holding the phone.
 *
 * NEVER SHOW A RAW ERROR. A 5xx from a server in debug mode carries an exception
 * class, a SQL string and a stack trace. Anything that is not the contract's shape
 * is replaced by a plain sentence here, and the original is dropped, not logged —
 * a ballot's error body must not reach a log either.
 */

import type { FetchBaseQueryError } from '@reduxjs/toolkit/query'

export type ApiError = {
  status: number | 'network' | 'timeout'
  code: string
  message: string
  /** 422 only: field → messages, for react-hook-form's setError. */
  fieldErrors: Record<string, string[]>
  /** 429 only: seconds to wait, from Retry-After. */
  retryAfter: number | null
  /**
   * What the app needs to act on a refusal beyond its sentence — `error.details`, sent only by the few that carry any
   * (`account_deleted`: the dates, the office's contact and the restore ticket; M18H). Absent otherwise.
   */
  details?: Record<string, unknown>
}

const SENTENCES = {
  network: "You're offline or the server can't be reached. Anything you save is kept on this phone.",
  timeout: 'That took too long. Check your signal and try again.',
  server: 'Something went wrong on our side. Please try again in a moment.',
  unknown: 'Something unexpected happened. Please try again.',
} as const

/**
 * THE SERVER'S CATCH-ALL FOR AN ADDRESS IT DOES NOT HAVE (its bootstrap/app.php, on any 404 a controller did not word
 * itself): "Nothing at this address, or nothing this handset may see." It reached residents three times on 8 October —
 * the Security screen, the Notifications sheet and the panic message box — because the server they reached was older
 * than the app. M57 §5.3: it never reaches a person. Every server error passes through `normalizeError` (the API layer,
 * downloads, the offline queue and a panic's own send all take its answer), so it is replaced here, once, with a plain
 * line and what to do; a screen that knows more says more (`notFoundLine`).
 */
export const SERVER_NOT_FOUND = /^Nothing at this address, or nothing this handset may see\.?$/
export const NOT_FOUND_SENTENCE = "This is not on the estate's server, or not yet. Go back and try again; if it keeps happening, the estate office can help."

/** A screen's own words for a 404, where it knows what was missing; every other refusal as the server worded it. */
export const notFoundLine = (e: Pick<ApiError, 'code' | 'message'>, line: string): string => (e.code === 'not_found' ? line : e.message)

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v)

function fieldErrorsOf(body: Record<string, unknown>): Record<string, string[]> {
  if (!isRecord(body.errors)) return {}
  const out: Record<string, string[]> = {}
  for (const [field, messages] of Object.entries(body.errors)) {
    if (Array.isArray(messages)) out[field] = messages.filter((m): m is string => typeof m === 'string')
  }
  return out
}

export function normalizeError(error: FetchBaseQueryError, retryAfterHeader?: string | null): ApiError {
  const base = { fieldErrors: {}, retryAfter: null }

  if (error.status === 'TIMEOUT_ERROR') return { ...base, status: 'timeout', code: 'timeout', message: SENTENCES.timeout }
  if (error.status === 'FETCH_ERROR') return { ...base, status: 'network', code: 'network_unreachable', message: SENTENCES.network }
  if (error.status === 'PARSING_ERROR' || error.status === 'CUSTOM_ERROR') {
    return { ...base, status: 'network', code: 'unreadable_response', message: SENTENCES.unknown }
  }

  const status = error.status
  const body = isRecord(error.data) ? error.data : {}
  const contract = isRecord(body.error) ? body.error : null
  const code = contract && typeof contract.code === 'string' ? contract.code : null
  const message = contract && typeof contract.message === 'string' ? contract.message : null

  // Not the contract's shape — a debug exception page, a proxy error, HTML. Say something plain.
  if (code === null || message === null) {
    return { ...base, status, code: status >= 500 ? 'server_error' : 'unexpected_response', message: status >= 500 ? SENTENCES.server : SENTENCES.unknown }
  }

  const retryAfter = status === 429 && retryAfterHeader ? Number.parseInt(retryAfterHeader, 10) : null

  return {
    status,
    code,
    // The server's catch-all 404 is never shown (M57 §5.3): a plain line, and what to do, in its place.
    message: SERVER_NOT_FOUND.test(message.trim()) ? NOT_FOUND_SENTENCE : message,
    fieldErrors: status === 422 ? fieldErrorsOf(body) : {},
    retryAfter: Number.isFinite(retryAfter) ? retryAfter : null,
    ...(contract && isRecord(contract.details) ? { details: contract.details } : {}),
  }
}

export const isApiError = (e: unknown): e is ApiError => isRecord(e) && typeof e.code === 'string' && typeof e.message === 'string' && 'status' in e

/**
 * THE SERVER BEGAN AN ACT AND NEVER FINISHED IT (B062, B135). A first attempt that died on the server — a PHP
 * fatal, a killed worker, a reload mid-request — leaves its idempotency row "in progress", and every retry
 * with the same key is answered `409 request_in_progress` for as long as the server keeps the row (thirty
 * days). Retrying the same key can never get past it, so once the answer has persisted past the outbox's bound
 * the act is recorded as refused with THIS code, and the sentence below says what is and is not known: the act
 * may or may not have happened. A server that recognises an abandoned row answers `idempotent_answer_missing`
 * instead, which is a refusal already; this is the phone's own backstop for a server that does not.
 */
export const REQUEST_STUCK = 'request_stuck'
export const REQUEST_STUCK_SENTENCE = 'The server started on this but never finished, so this phone cannot tell whether it was saved. It will not be sent again by itself: check whether it arrived, and do it again if it did not.'

/**
 * A FINAL NO from the server: a 4xx about the ACT, which the same key will be answered again for ever. Three 4xx
 * are not — they are about getting there: a 401 (the session, not the act), a 429 (wait) and `request_in_progress`
 * (the first attempt is still running). The queue and an alert's direct send ask this same question (B126): they
 * used to disagree, so a 429 on a duress read "refused" on its screen while the queue went on sending it.
 */
export function isRefusal(e: Pick<ApiError, 'status' | 'code'>): boolean {
  return typeof e.status === 'number' && e.status >= 400 && e.status < 500 && e.code !== 'request_in_progress' && e.code !== 'rate_limited' && e.status !== 401
}

/** True when retrying the SAME request (same Idempotency-Key) can succeed. A 4xx refusal is an answer, not a failure. */
export function isRetryable(e: ApiError): boolean {
  if (e.status === 'network' || e.status === 'timeout') return true
  if (typeof e.status === 'number' && e.status >= 500) return true
  return e.code === 'request_in_progress' || e.code === 'rate_limited'
}
