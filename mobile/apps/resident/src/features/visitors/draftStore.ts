/**
 * Invitations written with no connection — the DRAFTS — and how each one is sent.
 *
 * THE DRAFTS LIVE IN THE PHONE'S STORE AND NOWHERE ELSE (B119). A draft is a guest's name, phone and
 * plate, and it belongs to the resident who wrote it. A module-level copy once outlived the wipe of
 * the store, so the next account to sign in on the same phone was shown the last resident's guests —
 * with a working "Send now" — and wrote them back to disk beside its own.
 *
 * WHOSE IT IS, AND WHEN IT GOES. A chosen sign-out deletes the drafts, as its warning says. A session
 * that ended with nobody choosing it (the thirty-minute idle window, a revoked token) KEEPS them, as it
 * keeps the queue (auth/clearEverything.ts): they are the resident's unsent work, and a phone left in a
 * pocket is no reason to lose it. Each draft records its owner (`account:<id>`, the queue's own form);
 * the next session adopts the same person's and deletes anyone else's (`adoptDrafts`), and nothing is
 * drawn or sent but the signed-in person's own (`draftsOf`).
 *
 * So every read asks the store. The last parse is remembered only against the exact text it was
 * parsed from, which keeps the list stable between renders and can never hand back anything the
 * store no longer holds. Reads are synchronous on both engines (lib/storage.ts).
 *
 * A DRAFT SENDS ITSELF (M11 §1.3, §1.5). It was sent only when the resident tapped "Send now", so a
 * resident who wrote one on no signal had to remember to come back — not the WhatsApp standard, and
 * the M11 §1.7 airplane test (26 Sep) found it waiting after the connection returned. Now the runtime
 * sends every draft after the queue (offline/runtime.ts: at a session's start, on reconnect, and from
 * the background task). Until the server has signed it, it is still a draft and says so.
 *
 * ONCE, HOWEVER OFTEN IT IS SENT. Every send of one draft carries the SAME Idempotency-Key — the
 * draft's own id — and the device time it was written at, so a send whose answer was lost and is sent
 * again is answered with the first pass, never a second one (the server's Idempotent middleware). A
 * draft the server REFUSES (the window has passed, the household is restricted) keeps the refusal in
 * the server's words and is not sent again by itself: the resident reads why, then sends or discards it.
 */

import { getStorage } from '@gs/core/lib'
import type { ApiError, WriteStamp } from '@gs/core/api'
import type { Draft } from './passes'

/** The drafts' storage key, which a sign-out nobody chose keeps with the queue (store/index.ts). */
export const DRAFTS_STORAGE_KEY = 'v1:passes.drafts'
const DRAFTS_KEY = DRAFTS_STORAGE_KEY
const draftListeners = new Set<() => void>()

/** Whose a draft is: the same form the queue records (`account:<id>`), so both are adopted by the same rule. */
export const draftOwner = (principalId: number | string | null | undefined): string | null => (principalId === null || principalId === undefined ? null : `account:${principalId}`)
let parsed: { raw: string | null; drafts: Draft[] } = { raw: null, drafts: [] }

export function readDrafts(): Draft[] {
  const raw = getStorage().getString(DRAFTS_KEY)
  if (raw !== parsed.raw) {
    let drafts: Draft[] = []
    try {
      const value: unknown = raw === null ? [] : JSON.parse(raw)
      drafts = Array.isArray(value) ? (value as Draft[]) : []
    } catch {
      drafts = []
    }
    parsed = { raw, drafts }
  }
  return parsed.drafts
}

export function writeDrafts(next: Draft[]): void {
  void getStorage().setDurable(DRAFTS_KEY, JSON.stringify(next))
  draftListeners.forEach((fn) => fn())
}

export const subscribeDrafts = (fn: () => void): (() => void) => {
  draftListeners.add(fn)
  return () => void draftListeners.delete(fn)
}

const patchDraft = (id: string, patch: Partial<Draft>) => writeDrafts(readDrafts().map((d) => (d.id === id ? { ...d, ...patch } : d)))

/** This person's drafts — and only theirs: after a sign-out nobody chose, the phone may still hold somebody else's. */
export const draftsOf = (owner: string | null, drafts: Draft[] = readDrafts()): Draft[] => (owner === null ? [] : drafts.filter((d) => d.owner === owner))

/**
 * At a session's start (offline/runtime.ts), as the queue is adopted: the drafts are THIS person's again, and anyone
 * else's are deleted. A draft with no owner was written before drafts recorded one — when every session's end wiped
 * them — so it can only be the session that is starting now, and becomes its.
 */
export function adoptDrafts(owner: string | null): void {
  const all = readDrafts()
  if (all.length === 0 || owner === null) return
  const mine = all.flatMap((d) => (d.owner === owner ? [d] : d.owner ? [] : [{ ...d, owner }]))
  if (mine.length !== all.length || mine.some((d, i) => d !== all[i])) writeDrafts(mine)
}

/** What sends one pass: the screen's mutation, or the runtime's dispatch of the same endpoint. */
export type IssuePass = (body: Record<string, unknown>) => Promise<{ data?: unknown; error?: unknown }>

/**
 *   sent     the server signed it: it is a pass now, and the draft is gone.
 *   waiting  no connection, or the server busy or failing: kept, and sent again later by itself.
 *   refused  the server's final answer, kept on the draft in its words; not sent again by itself.
 *   gone     no such draft (discarded, sent a moment ago, or somebody else's).
 */
export type DraftOutcome = { kind: 'sent' } | { kind: 'waiting'; message: string } | { kind: 'refused'; message: string } | { kind: 'gone' }

const inFlight = new Set<string>()

export const STILL_NO_CONNECTION = 'Still no connection. The draft is kept: your guest cannot enter on it yet. It is sent by itself when the phone has signal.'
const STILL_SENDING = 'This draft is already being sent. Wait a moment for the answer.'

const bodyOf = (draft: Draft): Record<string, unknown> => ({
  category: draft.category,
  visitor_name: draft.visitor_name,
  valid_from: draft.valid_from,
  valid_to: draft.valid_to,
  ...(draft.visitor_phone ? { visitor_phone: draft.visitor_phone } : null),
  ...(draft.purpose ? { purpose: draft.purpose } : null),
  ...(draft.vehicle_plate ? { vehicle_plate: draft.vehicle_plate } : draft.no_vehicle ? { no_vehicle: true } : null),
  // Walk in, Drive in or Ride in (M50 §6.3) — only as the plate says it: Drive in with a plate, the others without.
  ...(draft.arrival && (draft.arrival === 'drive') === !!draft.vehicle_plate ? { arrival: draft.arrival } : null),
  // The same key on every send of this draft, and the moment it was written: one pass, however often it is sent.
  __stamp: { idempotencyKey: draft.id, deviceTime: draft.deviceTime ?? draft.createdAt } satisfies WriteStamp,
})

/** An answer that is not the server's last word: sent again later, by itself. */
const transient = (e: ApiError): boolean =>
  e.status === 'network' || e.status === 'timeout' || e.code === 'request_in_progress' || e.status === 429 || (typeof e.status === 'number' && e.status >= 500)

/** One draft, sent now. A refusal is written onto the draft; a success removes it. */
export async function sendDraft(id: string, issue: IssuePass): Promise<DraftOutcome> {
  const draft = readDrafts().find((d) => d.id === id)
  if (!draft) return { kind: 'gone' }
  if (inFlight.has(id)) return { kind: 'waiting', message: STILL_SENDING }
  inFlight.add(id)
  try {
    const answer = await issue(bodyOf(draft))
    if (answer.error) {
      const e = answer.error as ApiError
      if (e.status === 'network' || e.status === 'timeout') return { kind: 'waiting', message: STILL_NO_CONNECTION }
      if (transient(e)) return { kind: 'waiting', message: e.message }
      // The session ended while it was asked: the store is being wiped, and nothing is written back to it.
      if (e.status === 401) return { kind: 'gone' }
      if (readDrafts().some((d) => d.id === id)) patchDraft(id, { problem: e.message })
      return { kind: 'refused', message: e.message }
    }
    writeDrafts(readDrafts().filter((d) => d.id !== id))
    return { kind: 'sent' }
  } finally {
    inFlight.delete(id)
  }
}

/** The drafts that send themselves: this person's, every one the server has not refused. */
export const sendableDrafts = (owner: string | null): Draft[] => draftsOf(owner).filter((d) => !d.problem)

/** Every sendable draft of this person's, oldest first; stops at the first that finds no connection. Answers how many went. */
export async function sendDrafts(issue: IssuePass, owner: string | null): Promise<number> {
  let sent = 0
  for (const draft of sendableDrafts(owner)) {
    const outcome = await sendDraft(draft.id, issue)
    if (outcome.kind === 'sent') sent++
    if (outcome.kind === 'waiting') break
  }
  return sent
}
