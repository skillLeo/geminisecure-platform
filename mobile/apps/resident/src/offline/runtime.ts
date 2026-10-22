/**
 * The Resident App's one write path for anything that may be done with no signal:
 * profile edits, household members, vehicles, contacts, pass edits and cancellations,
 * maintenance tickets and their photos, notice reads, RSVPs, booking cancellations.
 *
 *   const op = await submit({ endpoint: 'tickets.store', body, label: 'Maintenance request · Plumbing', kind: 'ticket' })
 *
 * The act is captured durably FIRST, with its Idempotency-Key and the time it happened,
 * then sent. There is no batch endpoint for residents: each act is replayed on its own,
 * in order, through the same generated endpoint a live call uses.
 *
 * WHAT CAN NEVER COME THROUGH HERE — the outbox itself refuses them (`canQueue`):
 * a BALLOT (a queued vote is a vote nobody can verify was cast once), a PAYMENT, a new
 * visitor pass (a pass is signed by the server — an unsigned one admits nobody), a booking
 * (a slot is first come), an approval answer (a late answer is no answer). Those screens
 * say plainly that they need a connection; nothing is faked.
 *
 * A pass written with no signal is kept as a DRAFT instead, labelled as one wherever it appears, and
 * `drain` sends the drafts after the queue — once each, whatever the number of tries
 * (features/visitors/draftStore.ts). Until the server has signed one, it admits nobody and says so.
 */

import type { ApiError, EndpointName } from '@gs/core/api'
import { residentApi } from '@gs/core/api/resident'
import { createOutbox, discardUpload, filePartOf, isAlertAct, keepBody, outboxChanged, scheduleOutboxTask, watchConnectivity, withMedia, type OutboxOp, type Sender } from '@gs/core/offline'
import { keepOffering } from '@gs/core/safety'
import { DEMO_CANNOT_WRITE } from '@/features/auth/demoWrites'
import { settleHeldPanic } from '@/features/panic/panicRecord'
import { adoptDrafts, sendDrafts, type IssuePass } from '@/features/visitors/draftStore'
import { store } from '@/store'
import { scheduleWarm } from './warmCaches'

export const outbox = createOutbox({
  onChange: (ops) => {
    store.dispatch(outboxChanged(ops))
    // The panic's record learns the queue's answer whatever screen is open — not only while the panic screen is (B117).
    settleHeldPanic(ops)
  },
  // A photo kept for an act that will never be sent (refused with its report, dismissed, somebody else's) goes with it.
  onGone: (op) => { const file = filePartOf(op.body); if (file) void discardUpload(file.uri) },
})

const camel = (name: string) => name.replace(/[._-]+([a-z0-9])/gi, (_, c: string) => c.toUpperCase())

type Initiator = { initiate: (arg: Record<string, unknown>, opts: { track: boolean }) => never }

/**
 * THE SESSION ENDED WHILE THE QUEUE WAS GOING (the idle window, a revocation): nothing more is sent until the same
 * person is back in. Answered as the 401 it is, so the act simply waits — it was tried with no token, failed on the
 * phone, and was written off as "could not prepare it" (the M11 §1.7 airplane test, 26 Sep).
 */
const SIGNED_OUT: ApiError = { status: 401, code: 'unauthenticated', message: 'Signed out: this is sent once you are back in.', fieldErrors: {}, retryAfter: null }

const send: Sender = async (op, params) => {
  if (store.getState().session.status !== 'active') return { error: SIGNED_OUT }
  const endpoint = (residentApi.endpoints as unknown as Record<string, Initiator | undefined>)[camel(op.endpoint)]
  if (!endpoint) return { error: { status: 404, code: 'unknown_endpoint', message: 'This action is not available in this version of the app.', fieldErrors: {}, retryAfter: null } }
  const result = (await store.dispatch(endpoint.initiate({ ...params, ...(op.body ?? {}), __stamp: op.stamp }, { track: false }))) as { data?: unknown; error?: ApiError }
  return result.error ? { error: result.error } : { data: result.data }
}

/** Nothing is sent without an ACTIVE session: a pending account's token reaches only `/me` and the unit claim. */
// A queued photo whose file has gone is REFUSED with a plain sentence instead of wedging the queue (core/offline/media.ts).
const sendWithMedia = withMedia(send) as Sender

/** A visitor pass from a draft, through the same generated endpoint the invite form calls. */
const issuePass: IssuePass = async (body) => {
  const endpoint = (residentApi.endpoints as unknown as Record<string, Initiator>).visitorPassesStore!
  return (await store.dispatch(endpoint.initiate(body, { track: false }))) as { data?: unknown; error?: ApiError }
}

export const drain = async (options: { force?: boolean } = {}): Promise<void> => {
  const session = store.getState().session
  if (session.status !== 'active') return
  await outbox.drain(sendWithMedia, options)
  // Then the invitations written with no signal: this person's, each sent by itself, once (features/visitors/draftStore.ts).
  if (!session.demo) await sendDrafts(issuePass, ownerOf())
}

/** A person asked ("Try now"), or the connection just came back: go at once, whatever happened a moment ago. */
export const drainNow = (): Promise<void> => drain({ force: true })

/** ONE held act, sent now and on its own — a held panic, which never waits behind the queue (B125). */
export const offer = async (id: string): Promise<void> => {
  if (store.getState().session.status !== 'active') return
  await outbox.offer(id, sendWithMedia)
}

/**
 * EVERY ALERT STILL HELD ON THIS PHONE: sent now, each on its own, and then OFFERED AGAIN EVERY FEW SECONDS
 * until it is answered — from the runtime, not from a screen (the Guard App's B128, which this app shared).
 * The retry lives in memory, so a killed app lost it, and after a restart only the panic screen re-armed it;
 * a resident who reopened the app on Home had an alert that was tried once at launch and then never again.
 * Called at every session start and every reconnect; one timer per alert however often.
 */
export function offerHeldAlerts(): void {
  for (const op of outbox.all()) {
    if (!isAlertAct(op) || op.state !== 'pending') continue
    const id = op.id
    keepOffering(id, { state: (held) => outbox.all().find((o) => o.id === held)?.state, resend: () => offer(id) })
    void offer(id).catch(() => undefined)
  }
}

const ownerOf = (): string | null => {
  const id = store.getState().session.principal?.id
  return id === undefined ? null : `account:${id}`
}

/**
 * THE BACKSTOP: A DEMONSTRATION SESSION QUEUES NOTHING, EVER.
 *
 * Every write screen asks `useDemoRefusal()` first and stops there, so this should be
 * unreachable. It is here because of what happens if one of them ever forgets: the act would be
 * captured durably, the screen would say "saved", the send would come back `403 missing_ability`
 * and the person would be told "not accepted" over the top of it. A reviewer would have been
 * told their report was saved when nothing could ever come of it, and that is the one failure
 * this app does not permit itself. A thrown error stops the act where it stands; nothing
 * is written to disk, and `DemoWriteRefused` is what the screen shows instead of "saved".
 */
export class DemoWriteRefused extends Error {
  constructor() {
    super(DEMO_CANNOT_WRITE)
    this.name = 'DemoWriteRefused'
  }
}

export async function submit(input: { endpoint: EndpointName; params?: OutboxOp['params']; body?: Record<string, unknown> | null; label: string; kind: string; after?: string | null }): Promise<OutboxOp> {
  if (store.getState().session.demo) throw new DemoWriteRefused()

  // A photo is copied out of the cache (which the OS may sweep) BEFORE it is queued: it may wait hours for a signal.
  const op = await outbox.enqueue({ ...input, body: await keepBody(input.body ?? null), owner: ownerOf() ?? 'unknown' })
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

/** From launch, session or not: the sign-in screens read connectivity before anyone is signed in. */
export function watchNetwork(): void {
  stop ??= watchConnectivity(store.dispatch, () => {
    offerHeldAlerts()
    void drainNow()
    // Back online: what the screens will draw next time there is none is saved again (warmCaches.ts).
    scheduleWarm()
  })
}

/**
 * Call when a session becomes active — and only after storage is hydrated (the queue is read
 * from disk once). Adopts the queue for THIS account: what they saved before being signed out
 * is theirs again; anyone else's is discarded.
 */
export async function startOfflineRuntime(): Promise<void> {
  watchNetwork()
  const owner = ownerOf()
  if (owner) await outbox.adopt(owner)
  // The invitation drafts the same way: this account's again after a sign-out nobody chose, anyone else's deleted.
  adoptDrafts(owner)
  store.dispatch(outboxChanged(outbox.all()))
  // A panic held when the app was last killed (or signed out) is this account's again: offered now, and every few seconds after.
  offerHeldAlerts()
  void drainNow()
  // M11 §1.3: and when the app is closed, the OS wakes it to send once a connection is back (offline/backgroundSync).
  void scheduleOutboxTask()
  // M11 §1.1: every screen's reads saved before it is first opened, so none is empty with no signal (warmCaches.ts).
  scheduleWarm()
}
