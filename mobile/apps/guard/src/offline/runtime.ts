/**
 * The Guard App's one write path for anything that must survive having no signal.
 *
 *   const op = await submit({ endpoint: 'shifts.clock_in', params: { shift }, body, label, kind })
 *
 * submit() captures the act durably FIRST (with its Idempotency-Key and device time),
 * then tries to send. The screen never waits on the network to say "saved": the record
 * is already on the phone, and its state — Pending sync · Syncing · Confirmed · Not
 * accepted — is in the store for any screen to show.
 *
 * Replays go through the same generated endpoint a live call uses, with the CAPTURED
 * stamp (`__stamp`), so ability checks, validation, the allowlist and idempotency all
 * apply exactly as they would online.
 */

import type { ApiError, EndpointName } from '@gs/core/api'
import { guardApi } from '@gs/core/api/guard'
import { createOutbox, discardUpload, filePartOf, isAlertAct, keepBody, outboxChanged, scheduleOutboxTask, watchConnectivity, withMedia, type OutboxOp, type Sender } from '@gs/core/offline'
import { keepOffering } from '@gs/core/safety'
import { onboarding } from '@/features/enrolment/onboardingState'
import { gateKnowledge } from '@/features/gate/knowledge'
import { store } from '@/store'
import { scheduleWarm } from './warmCaches'

export const outbox = createOutbox({
  onChange: (ops) => store.dispatch(outboxChanged(ops)),
  // A photo kept for an act that will never be sent (refused with its report, dismissed, somebody else's) goes with it.
  onGone: (op) => { const file = filePartOf(op.body); if (file) void discardUpload(file.uri) },
})

const camel = (name: string) => name.replace(/[._-]+([a-z0-9])/gi, (_, c: string) => c.toUpperCase())

type Initiator = { initiate: (arg: Record<string, unknown>, opts: { track: boolean }) => never }

/**
 * THE SESSION ENDED WHILE THE QUEUE WAS GOING (the idle window, a rebind): nothing more is sent until the same
 * officer is back in. Answered as the 401 it is, so the act simply waits instead of being tried with no token and
 * written off as "could not prepare it" (the resident's M11 §1.7 airplane test, 26 Sep — the same runtime shape).
 */
const SIGNED_OUT: ApiError = { status: 401, code: 'unauthenticated', message: 'Signed out: this is sent once you are back in.', fieldErrors: {}, retryAfter: null }

const send: Sender = async (op, params) => {
  if (store.getState().session.status !== 'active') return { error: SIGNED_OUT }
  const endpoint = (guardApi.endpoints as unknown as Record<string, Initiator | undefined>)[camel(op.endpoint)]
  if (!endpoint) return { error: { status: 404, code: 'unknown_endpoint', message: 'This action is not available in this version of the app.', fieldErrors: {}, retryAfter: null } }
  const result = (await store.dispatch(endpoint.initiate({ ...params, ...(op.body ?? {}), __stamp: op.stamp }, { track: false }))) as { data?: unknown; error?: ApiError }
  return result.error ? { error: result.error } : { data: result.data }
}

/** Nothing is sent without a session: a replay with no token would only earn a 401 and end a session that is not there. */
// A queued photo whose file has gone is REFUSED with a plain sentence instead of wedging the queue (core/offline/media.ts).
const sendWithMedia = withMedia(send) as Sender

export const drain = async (options: { force?: boolean } = {}): Promise<void> => {
  if (store.getState().session.status !== 'active') return
  await outbox.drain(sendWithMedia, options)
}

/** A person asked ("Try now"), or the connection just came back: go at once, whatever happened a moment ago. */
export const drainNow = (): Promise<void> => drain({ force: true })

/** ONE held act, sent now and on its own — a held duress, which never waits behind the queue (B125). */
export const offer = async (id: string): Promise<void> => {
  if (store.getState().session.status !== 'active') return
  await outbox.offer(id, sendWithMedia)
}

/**
 * EVERY ALERT STILL HELD ON THIS PHONE: sent now, each on its own, and then OFFERED AGAIN EVERY FEW SECONDS
 * until it is answered — from the runtime, not from a screen (B128). The retry lives in memory, so a killed
 * app lost it, and after a restart only the Duress screen re-armed it: a coerced guard who stays on Home never
 * opens that screen, a phone that never lost signal never reports a reconnect, and the alert sat unsent. Called
 * at every session start and every reconnect; one timer per alert however often.
 */
export function offerHeldAlerts(): void {
  for (const op of outbox.all()) {
    if (!isAlertAct(op) || op.state !== 'pending') continue
    const id = op.id
    keepOffering(id, { state: (held) => outbox.all().find((o) => o.id === held)?.state, resend: () => offer(id) })
    void offer(id).catch(() => undefined)
  }
}

export async function submit(input: { endpoint: EndpointName; params?: OutboxOp['params']; body?: Record<string, unknown> | null; label: string; kind: string; after?: string | null }): Promise<OutboxOp> {
  const owner = store.getState().session.principal?.reference ?? 'unknown'
  // A photo is copied out of the cache (which the OS may sweep) BEFORE it is queued: it may wait hours for a signal.
  const op = await outbox.enqueue({ ...input, body: await keepBody(input.body ?? null), owner })
  void drain()
  return op
}

/**
 * After a DELIBERATE sign-out. clearEverything() removes the queue from disk, but the queue also lives in memory —
 * and that copy would be adopted and SENT if the same person signed back in without restarting the app, after the
 * sign-out screen had told them it was deleted. (Found by the profile review, 2026-09-20.) Not called on a REVOKED
 * session: there the queue is kept on purpose, for the same person to send after they enrol or sign in again.
 */
export function forgetQueue(): void {
  outbox.reset()
  store.dispatch(outboxChanged([]))
}

let stop: (() => void) | null = null

/**
 * "Pull first whenever signal returns, then upload" (the server's sync contract): the pass
 * keys and the cancelled list are refreshed BEFORE queued gate entries go up, so the very
 * next scan is judged on current knowledge. A failed pull never holds the upload back —
 * and a held duress is not part of the upload at all: it goes first, on its own.
 */
async function pullThenDrain(): Promise<void> {
  if (store.getState().session.status !== 'active') return
  // EXCEPT A HELD DURESS, which goes before the pull and does not wait for it (B125): the pull can take most of a
  // minute on weak signal, and the gate's knowledge has nothing to do with an officer in danger.
  offerHeldAlerts()
  const siteId = gateKnowledge.get().siteId ?? onboarding.get().site?.id ?? null
  await gateKnowledge.refresh(siteId).catch(() => 'failed' as const)
  await drainNow()
  // What the tabs draw next time there is no signal, saved again (warmCaches.ts).
  scheduleWarm()
}

/** From launch, session or not: the enrolment screen reads connectivity before there is anyone signed in. */
export function watchNetwork(): void {
  stop ??= watchConnectivity(store.dispatch, () => void pullThenDrain())
}

/**
 * Call whenever a session becomes active — ONLY after storage has been hydrated (the
 * queue is read from disk once and then held in memory; reading it early would read
 * nothing and later overwrite what is on disk). Every call adopts the queue for THAT
 * guard — what they captured before a rebind is theirs again, anyone else's is
 * discarded — then mirrors it into the store and drains.
 */
export async function startOfflineRuntime(): Promise<void> {
  watchNetwork()
  const owner = store.getState().session.principal?.reference
  if (owner) await outbox.adopt(owner)
  store.dispatch(outboxChanged(outbox.all()))
  // A duress held when the app was last killed is this guard's again: offered now, and every few seconds after.
  offerHeldAlerts()
  // The gate's knowledge (the site's pass keys, the cancelled list) is pulled at every session start as well, not only
  // on reconnect or when a gate screen opens: a sign-out nobody chose wipes it, and an officer who enrolled again and
  // walked into a dead spot could not check a single pass (the M11 §1.7 airplane test, 26 Sep). Then the queue.
  void pullThenDrain()
  // M11 §1.3: and when the app is closed, the OS wakes it to send once a connection is back (offline/backgroundSync).
  void scheduleOutboxTask()
  // M11 §1.1: every tab's reads saved before it is first opened — the tour above all (warmCaches.ts).
  scheduleWarm()
}
