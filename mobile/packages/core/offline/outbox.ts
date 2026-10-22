/**
 * THE OUTBOX — offline is the normal state, not an error.
 *
 * Every offline-capable write is captured HERE FIRST, durably, with the
 * Idempotency-Key and device time of the moment it happened — and only then sent.
 * So a guard never wonders whether a report was saved: it is on the phone before
 * the network is even tried, and its state is always one of
 *
 *     pending  →  syncing  →  confirmed
 *                          ↘  refused   (the server ANSWERED no — a 4xx; shown, never resent)
 *                          ↘  pending   (a failure — 5xx, timeout, no signal; resent with the SAME key)
 *                          ↘  refused   (the server said "still in progress" for longer than any live request
 *                                        can run: its first attempt died there — shown, never resent)
 *
 * Rules (each has a test in __tests__/outbox.test.ts):
 *   - APPEND-ONLY and ORDERED: acts replay in the order they happened; an act that
 *     depends on another (a clock-out on its clock-in, media on its report) waits for it.
 *   - EXCEPT AN ALERT, WHICH NEVER WAITS ITS TURN: a duress or a panic is sent before the next
 *     act in the queue however long the queue is, is never held up by another act failing, and
 *     can be sent on its own at any moment (`offer`) — even while a drain is busy with a photo.
 *   - AND A PHOTO THAT HAS FAILED, WHICH GOES TO THE BACK: nothing depends on a photo, so one that
 *     keeps failing is tried after everything else and holds nothing up. A send that never answers
 *     is a timeout (three minutes), and an act the app itself cannot send is said, not retried for ever.
 *   - IDEMPOTENT: the stamp is fixed at capture and reused on every replay, so a retry
 *     after a lost response cannot do the thing twice.
 *   - A REFUSAL IS AN ANSWER: a 4xx is recorded with the server's sentence and never retried.
 *   - NEVER FAKE AN OFFLINE SUCCESS (invariant 9): a ballot or a payment cannot be
 *     queued — enqueue() throws, and the screen says plainly that it needs a connection.
 *   - IT REMEMBERS WHOSE IT IS: a different principal signing in discards the queue;
 *     the same one after a revoked session sends it (auth/clearEverything.ts).
 *   - BOUNDED: past a size or an age the app warns the guard (and dispatch, on sync).
 */

import { ENDPOINTS, type EndpointName } from '../api/generated/endpoints'
import { isRefusal, REQUEST_STUCK, REQUEST_STUCK_SENTENCE, type ApiError } from '../api/errors'
import { newStamp, type WriteStamp } from '../api/write'
import { OUTBOX_PREFIX } from './constants'
import { now } from '../fidelity'
import { getStorage, type KeyValueStorage } from '../lib/storage'
import { deviceTimeIso } from '../lib/time'
import { newUuid } from '../lib/uuid'

export type OutboxState = 'pending' | 'syncing' | 'confirmed' | 'refused'

export type OutboxOp = {
  id: string
  endpoint: EndpointName
  /** Path parameters, e.g. { shift: 812 }. A value of `{ $from: opId, key: 'id' }` is filled from a confirmed parent's answer. */
  params: Record<string, string | number | { $from: string; key: string }>
  body: Record<string, unknown> | null
  stamp: WriteStamp
  /** A sentence for the sync queue: "Clock-in · Main Gate". */
  label: string
  kind: string
  owner: string
  after: string | null
  state: OutboxState
  attempts: number
  capturedAt: string
  lastTriedAt: string | null
  error: { status: ApiError['status']; code: string; message: string } | null
  result: unknown
  /**
   * When the server FIRST answered "still in progress" for this act's key, in a row with nothing else heard in
   * between (device ms). Past `OUTBOX_LIMITS.stuckInProgressMs` the act is abandoned as refused. Optional: an
   * act saved by an earlier build has none.
   */
  inProgressSince?: number | null
  /**
   * How many tries IN A ROW failed on the phone itself, before anything was sent (`isLocalFailure`) — not the act's
   * whole history. Any answer from the server or the network sets it back to 0. Optional: an earlier build's act has none.
   */
  localFailures?: number
}

/** The server's own words decide: "Online only" means exactly that. Plus the two M2 names outright. */
const ONLINE_ONLY = /^\s*online only/i
export function canQueue(endpoint: EndpointName): boolean {
  const e = ENDPOINTS[endpoint]
  if (!e.write) return false
  if (endpoint === 'elections.ballot') return false
  if (e.ability?.startsWith('payments:')) return false
  return !ONLINE_ONLY.test(e.offline)
}

export class OfflineNotAllowedError extends Error {
  constructor(public endpoint: EndpointName) {
    super(
      endpoint === 'elections.ballot'
        ? 'Your vote can only be cast while you are connected. Nothing has been sent: connect and try again.'
        : 'This needs a connection and cannot be saved for later. Nothing has been sent: connect and try again.',
    )
    this.name = 'OfflineNotAllowedError'
  }
}

/**
 * `stuckInProgressMs` — how long the server may go on saying an act is "still in progress" before the phone stops
 * waiting for it (B062, B135). A genuine one — the same key sent twice at once, the direct send of an alert racing
 * the queue — lasts seconds, and PHP kills any request after two minutes (`max_execution_time` 120), so ten
 * minutes is five times the longest a live request could still be running. Past it the row is abandoned.
 */
export const OUTBOX_LIMITS = { warnCount: 100, warnAgeHours: 12, keepConfirmedHours: 24, retryAfterFailureMs: 4000, maxBackoffMs: 5 * 60_000, stuckInProgressMs: 10 * 60_000, stalledSendMs: 3 * 60_000, localFailuresBeforeRefusal: 3 } as const

/*
 * THREE WAYS ONE ACT USED TO HOLD UP EVERY ACT BEHIND IT, FOR EVER (the client's phone, 26 Sep: a report's photo
 * never reached the server, and a maintenance request saved after it sat at "Pending sync" behind it, while a
 * booking made from the same phone went straight through):
 *
 *   A PHOTO THAT HAS FAILED GOES TO THE BACK OF THE LINE. The line is kept in order because an act can depend on
 *     the one before it — and nothing depends on a photo. So a photo that failed last time is tried after
 *     everything else that is waiting, and a request saved after it is not held back by it.
 *   A SEND THAT NEVER ANSWERS IS A TIMEOUT. `stalledSendMs` is three minutes: a live request gives up after
 *     fifteen seconds and three tries, so three minutes of silence means the send itself is stuck — and while it
 *     was, the whole drain was, and nothing else could be sent until the app was killed. It is resent with the
 *     SAME key, so if it did land, the server replays its first answer.
 *   AN ACT THE APP ITSELF CANNOT SEND IS SAID, NOT RETRIED FOR EVER. An answer with no HTTP status and no network
 *     error is the app failing before anything left the phone — the same way every time. After
 *     `localFailuresBeforeRefusal` such failures IN A ROW (`localFailures`) it is marked not sent, with a sentence
 *     saying so, and the line moves on.
 */
const STALLED: ApiError = { status: 'timeout', code: 'send_stalled', message: 'No answer came back, so this will be tried again.', fieldErrors: {}, retryAfter: null }

export const NOT_SENDABLE = 'not_sendable'
export const NOT_SENDABLE_SENTENCE = 'This could not be sent from this phone: the app could not prepare it, so nothing about it reached the estate. Do it again, and if this happens again tell the estate office.'

/** A photo or a file on its way up: the only kind of act nothing else in the line depends on. */
export const isUploadAct = (op: Pick<OutboxOp, 'body'>): boolean => {
  const file = (op.body as { file?: unknown } | null)?.file
  return typeof file === 'object' && file !== null && typeof (file as { uri?: unknown }).uri === 'string'
}

/** An upload that has been tried and failed: it waits at the back of the line. */
const failedUpload = (op: OutboxOp): boolean => isUploadAct(op) && op.attempts > 0 && op.error !== null

/** An answer that is neither the server's nor the network's: the app failed before anything was sent. */
const isLocalFailure = (e: { status?: unknown }): boolean => typeof e.status !== 'number' && e.status !== 'network' && e.status !== 'timeout'

async function withinStall<T>(sending: Promise<T>): Promise<T | { error: ApiError }> {
  let timer: ReturnType<typeof setTimeout> | undefined
  const stalled = new Promise<{ error: ApiError }>((resolve) => {
    timer = setTimeout(() => resolve({ error: STALLED }), OUTBOX_LIMITS.stalledSendMs)
    // Under Node (the tests) a pending watchdog must not keep the process alive; a phone has no such call.
    ;(timer as unknown as { unref?: () => void }).unref?.()
  })
  try {
    return await Promise.race([sending, stalled])
  } finally {
    clearTimeout(timer)
  }
}

/**
 * EXPONENTIAL BACKOFF (M11 §1.3). After a drain that stopped on a network failure the next AUTOMATIC one waits
 * 4 s, then 8, 16, 32… up to five minutes, and any success starts it over: a phone in a dead spot does not burn its
 * battery and a prepaid bundle asking the same question every few seconds. A reconnect, the app coming to the
 * front and a person pressing "Try now" pass `force` and go at once, whatever the backoff says.
 */
export function backoffMs(failures: number): number {
  if (failures <= 0) return 0
  return Math.min(OUTBOX_LIMITS.maxBackoffMs, OUTBOX_LIMITS.retryAfterFailureMs * 2 ** (failures - 1))
}

/**
 * THE ACTS THAT RAISE AN ALERT — a guard's duress, a resident's panic (both `duress.store`), and the legacy
 * `alerts.store`. They are the one exception to first-in-first-out (B125): captured last behind twenty scans
 * and a photo, a duress used to go only after all of them, one by one, and never while one of them kept
 * failing. Now each goes before the next act in the queue, and no other act's failure can hold it back.
 */
export const ALERT_ENDPOINTS: readonly EndpointName[] = ['duress.store', 'alerts.store']
export const isAlertAct = (op: Pick<OutboxOp, 'endpoint'>): boolean => ALERT_ENDPOINTS.includes(op.endpoint)

const KEY = `${OUTBOX_PREFIX}v1`

export type Sender = (op: OutboxOp, params: Record<string, string | number>) => Promise<{ data: unknown } | { error: ApiError }>

export function createOutbox(deps: { storage?: KeyValueStorage; onChange?: (ops: OutboxOp[]) => void; /** An act that will NEVER be sent (now or again) has left, or been refused without reaching the sender: whatever was kept for it — a photo copied out of the cache — can go. */ onGone?: (op: OutboxOp) => void } = {}) {
  const gone = (op: OutboxOp) => { try { deps.onGone?.(op) } catch { /* tidying up never breaks the queue */ } }
  const storage = () => deps.storage ?? getStorage()
  let ops: OutboxOp[] | null = null
  let draining: Promise<void> | null = null
  /** When the last drain stopped on a network failure — cleared by any drain that did not. */
  let failedAt: number | null = null
  /** How many drains in a row have stopped on a failure: the backoff grows with it. */
  let failures = 0

  const load = (): OutboxOp[] => {
    if (ops) return ops
    try {
      const raw = storage().getString(KEY)
      ops = raw ? (JSON.parse(raw) as OutboxOp[]) : []
      // A crash mid-send leaves an op "syncing"; it was never answered, so it is pending again — same key, safe.
      for (const op of ops) if (op.state === 'syncing') op.state = 'pending'
    } catch {
      ops = []
    }
    return ops
  }

  const persist = async (): Promise<void> => {
    await storage().setDurable(KEY, JSON.stringify(load()))
    deps.onChange?.([...load()])
  }

  /** An act's path parameters, with any `{ $from }` filled from its confirmed parent — or null while one cannot be. */
  const paramsOf = (op: OutboxOp): Record<string, string | number> | null => {
    const params: Record<string, string | number> = {}
    for (const [k, v] of Object.entries(op.params)) {
      if (typeof v === 'object') {
        const parent = load().find((o) => o.id === v.$from)
        const value = parent?.result && typeof parent.result === 'object' ? (parent.result as Record<string, unknown>)[v.key] : undefined
        if (typeof value === 'string' || typeof value === 'number') params[k] = value
        else return null
      } else params[k] = v
    }
    return params
  }

  /**
   * Send ONE act and record its answer: confirmed, refused (a 4xx — an answer, never resent) or failed
   * (pending again, resent later with the same key). The act is `syncing` while it is on the wire, which is
   * what stops the drain and `offer` from ever sending the same act twice at once.
   */
  const attempt = async (op: OutboxOp, params: Record<string, string | number>, send: Sender): Promise<'confirmed' | 'refused' | 'failed'> => {
    op.state = 'syncing'
    op.attempts += 1
    op.lastTriedAt = deviceTimeIso()
    await persist()

    const answer = await withinStall(send(op, params))

    // Answered by another route while this was on the wire — an alert's direct send, told to `settle`. Its
    // answer stands: a late failure of this copy must not put a confirmed alert back in the queue.
    // (Read as the wider type: TypeScript narrowed it to `syncing` above and cannot see `settle` behind the await.)
    if ((op.state as OutboxState) === 'confirmed') return 'confirmed'

    if ('data' in answer) {
      op.state = 'confirmed'
      op.result = answer.data
      op.error = null
      op.inProgressSince = null
      op.localFailures = 0
      await persist()
      return 'confirmed'
    }

    const e = answer.error
    const refusal = isRefusal(e)
    op.error = { status: e.status, code: e.code, message: e.message }

    /*
     * "STILL IN PROGRESS" FOR EVER IS AN ANSWER TOO (B062, B135). The clock runs from the first such answer, and
     * only another answer ABOUT THE KEY stops it: no signal, a timeout, a 429 and a 401 are not (the key was never
     * reached), while a success, a refusal or a 5xx mean the request got past the key check, so it was not stuck.
     * Past the bound the act is refused with a sentence that says what is known — it may or may not have
     * happened — and stops holding up the queue. An ALERT included: it is surfaced on its own screen, and the
     * person can raise a new one; resending the same key could never have got it through.
     */
    if (e.code === 'request_in_progress') op.inProgressSince ??= now().getTime()
    else if (typeof e.status === 'number' && e.status !== 401 && e.code !== 'rate_limited') op.inProgressSince = null
    if (e.code === 'request_in_progress' && now().getTime() - (op.inProgressSince ?? now().getTime()) >= OUTBOX_LIMITS.stuckInProgressMs) {
      op.state = 'refused'
      op.error = { status: 409, code: REQUEST_STUCK, message: REQUEST_STUCK_SENTENCE }
      gone(op) // never sent again: whatever was kept for it (a photo) can go
      await persist()
      return 'refused'
    }

    if (refusal) {
      op.state = 'refused'
      await persist()
      return 'refused'
    }
    /*
     * Three failures ON THE PHONE IN A ROW, not three tries of any kind (the M11 §1.7 airplane test, 26 Sep). The test
     * compared `attempts`, the act's whole history: a notice read that had waited through several tries with no
     * signal was written off as "could not prepare it" at its FIRST local failure after the reconnect — a moment the
     * session was ending — though nothing about the act was wrong. The same took request #1002's photo the day before.
     */
    op.localFailures = isLocalFailure(e) ? (op.localFailures ?? 0) + 1 : 0
    if (op.localFailures >= OUTBOX_LIMITS.localFailuresBeforeRefusal) {
      op.state = 'refused'
      op.error = { status: 0, code: NOT_SENDABLE, message: NOT_SENDABLE_SENTENCE }
      gone(op) // never sent again: whatever was kept for it (a photo) can go
      await persist()
      return 'refused'
    }
    op.state = 'pending'
    await persist()
    return 'failed'
  }

  /**
   * The next act IN ITS TURN (alerts excepted: they never wait for one), or null when the queue has to wait —
   * for a parent that has not been confirmed, or for an id a parent has not answered with yet.
   */
  const nextInTurn = async (tried: Set<string>): Promise<{ op: OutboxOp; params: Record<string, string | number> } | null> => {
    for (const op of load()) {
      // A photo that failed last time is at the back of the line (below): it holds nothing up.
      if (op.state !== 'pending' || isAlertAct(op) || tried.has(op.id) || failedUpload(op)) continue
      if (op.after) {
        const parent = load().find((o) => o.id === op.after)
        if (parent && parent.state === 'refused') {
          op.state = 'refused'
          op.error = { status: 409, code: 'parent_refused', message: `Not sent: "${parent.label}" was refused, and this depends on it.` }
          gone(op) // it never reaches the sender, so nothing else would tidy up what was kept for it
          await persist()
          continue
        }
        if (parent && parent.state !== 'confirmed') return null // order is preserved: wait for the parent
      }
      const params = paramsOf(op)
      return params === null ? null : { op, params }
    }
    // Everything else has had its turn: now the photos that failed before, in the order they were taken.
    for (const op of load()) {
      if (op.state !== 'pending' || tried.has(op.id) || !failedUpload(op)) continue
      const params = paramsOf(op)
      if (params !== null) return { op, params }
    }
    return null
  }

  return {
    all: (): OutboxOp[] => [...load()],
    pendingCount: (): number => load().filter((o) => o.state === 'pending' || o.state === 'syncing').length,

    /** Durably capture an act. Resolves only once it is on disk. */
    async enqueue(input: {
      endpoint: EndpointName
      params?: OutboxOp['params']
      body?: Record<string, unknown> | null
      label: string
      kind: string
      owner: string
      after?: string | null
      stamp?: WriteStamp
    }): Promise<OutboxOp> {
      if (!canQueue(input.endpoint)) throw new OfflineNotAllowedError(input.endpoint)
      const op: OutboxOp = {
        id: newUuid(),
        endpoint: input.endpoint,
        params: input.params ?? {},
        body: input.body ?? null,
        stamp: input.stamp ?? newStamp(),
        label: input.label,
        kind: input.kind,
        owner: input.owner,
        after: input.after ?? null,
        state: 'pending',
        attempts: 0,
        capturedAt: deviceTimeIso(),
        lastTriedAt: null,
        error: null,
        result: null,
      }
      load().push(op)
      await persist()
      return op
    },

    /** A different person signed in: their predecessor's unsent acts are not theirs to send. */
    async adopt(owner: string): Promise<number> {
      const before = load().length
      load().filter((o) => o.owner !== owner).forEach(gone)
      ops = load().filter((o) => o.owner === owner)
      if (ops.length !== before) await persist()
      return before - ops.length
    },

    warnings(): { tooMany: boolean; tooOld: boolean; oldestHours: number } {
      const waiting = load().filter((o) => o.state === 'pending' || o.state === 'syncing')
      const oldest = waiting.reduce((min, o) => Math.min(min, new Date(o.capturedAt).getTime()), Number.POSITIVE_INFINITY)
      const oldestHours = waiting.length ? (now().getTime() - oldest) / 3_600_000 : 0
      return { tooMany: waiting.length >= OUTBOX_LIMITS.warnCount, tooOld: oldestHours >= OUTBOX_LIMITS.warnAgeHours, oldestHours }
    },

    /**
     * Send what is waiting, oldest first. Stops at the first FAILURE (the network is
     * the problem; trying the rest would only reorder them) and never at a REFUSAL
     * (that act has its answer; the ones behind it still deserve theirs).
     *
     * AN ALERT IS NOT IN THE LINE AT ALL. Before every act, an alert that is waiting goes first; an alert that
     * fails is tried again on the next drain but stops nothing behind it; and an act that keeps failing stops
     * the acts behind IT, never an alert.
     */
    drain(send: Sender, options: { force?: boolean } = {}): Promise<void> {
      if (draining) return draining
      // Several things ask for a drain at once — a new act, a screen coming into view, two connectivity events in
      // a row. After a NETWORK failure the next automatic try waits a few seconds: the same request four times in
      // three seconds told nobody anything and cost a prepaid phone data. A reconnect, or a person pressing
      // "Try now", passes `force` and goes at once. A refusal or a success never sets this.
      if (!options.force && failedAt !== null && now().getTime() - failedAt < backoffMs(failures)) return Promise.resolve()
      // Assigned right after `run` exists; the body cannot reach its `finally` before then, because it yields first.
      let settled: () => void = () => undefined
      const run = (async () => {
        // An async function runs SYNCHRONOUSLY up to its first await. With nothing to send there is no
        // await at all, so the old `finally { draining = null }` ran BEFORE `draining = …` was assigned —
        // and left `draining` pointing at a finished promise for good: no drain ever ran again, and every
        // act queued afterwards sat on the phone unsent. Every session begins with an empty-queue drain,
        // so this was every session. (Found on the client's phone, 2026-09-19. Test: "an empty drain…".)
        // Yielding first guarantees the marker below is set before any of this body runs.
        await Promise.resolve()
        try {
          let stopped = false // an act in its turn went unanswered: the acts behind it keep their order and wait
          let failed = false // anything went unanswered, an alert included: the next AUTOMATIC drain waits a moment
          const tried = new Set<string>()
          for (;;) {
            // 1 · AN ALERT FIRST — looked for again before EVERY act, so one raised while this drain is half-way
            // through thirty scans goes next, not last. Each is tried once per drain; one that fails holds up
            // nothing (the queue behind it, another alert) and is offered again on the next.
            const alert = load().find((o) => o.state === 'pending' && isAlertAct(o) && !tried.has(o.id))
            if (alert) {
              tried.add(alert.id)
              const params = paramsOf(alert)
              if (params !== null && (await attempt(alert, params, send)) === 'failed') failed = true
              continue
            }
            if (stopped) break

            // 2 · Everything else, in the order it happened.
            const next = await nextInTurn(tried)
            if (next === null) break
            tried.add(next.op.id)
            if ((await attempt(next.op, next.params, send)) === 'failed') {
              failed = true
              stopped = true
            }
          }
          failedAt = failed ? now().getTime() : null
          failures = failed ? failures + 1 : 0

          // Confirmed acts stay visible for a day — proof the post was covered — then leave.
          // A parent stays while a child still has to be SENT (it carries the id the child needs). A child that
          // was refused will never be sent, so it does not hold its parent — it used to, for ever, and the queue
          // only grew. Such a refused child has been visible for the same day; it leaves WITH its parent.
          // (A refused act with no confirmed parent — a report the server said no to — stays until it is read.)
          const cutoff = now().getTime() - OUTBOX_LIMITS.keepConfirmedHours * 3_600_000
          const all = load()
          const waiting = (c: OutboxOp) => c.state === 'pending' || c.state === 'syncing'
          const leaving = new Set(all.filter((o) => o.state === 'confirmed' && new Date(o.capturedAt).getTime() < cutoff && !all.some((c) => c.after === o.id && waiting(c))).map((o) => o.id))
          const kept = all.filter((o) => !leaving.has(o.id) && !(o.state === 'refused' && o.after !== null && leaving.has(o.after)))
          if (kept.length !== load().length) {
            ops = kept
            await persist()
          }
        } finally {
          settled()
        }
      })()
      draining = run
      // Only the run that set the marker clears it — belt and braces with the yield above.
      settled = () => {
        if (draining === run) draining = null
      }
      return run
    },

    /**
     * An act that was ALSO sent outside the queue, and answered. A panic alert does not wait its turn
     * behind ten queued checkpoint scans: it is captured here first (so a killed app cannot lose it) and
     * sent directly at the same moment, with the same key and the same body. When that direct send is
     * answered, the queue is told — so it is shown as Confirmed and never sent again. (If the drain was
     * already sending it, the server replays the first answer: same key, no second alert.)
     */
    async settle(id: string, result: unknown): Promise<void> {
      const op = load().find((o) => o.id === id)
      if (!op || op.state === 'confirmed') return
      op.state = 'confirmed'
      op.result = result
      op.error = null
      await persist()
    },

    /**
     * SEND ONE HELD ACT NOW, ON ITS OWN — the way a held alert is offered again every few seconds
     * (`keepOffering`), so it never waits behind other traffic: not even behind the one act a drain has on
     * the wire at this moment, which for a photo on weak 3G can be most of a minute. The same key, the same
     * body and the same record of its answer as the drain would make. An act already on the wire is left
     * alone (never two copies in flight); one that depends on another act is left to the drain's order.
     */
    async offer(id: string, send: Sender): Promise<void> {
      const op = load().find((o) => o.id === id)
      if (!op || op.state !== 'pending' || op.after) return
      const params = paramsOf(op)
      if (params === null) return
      await attempt(op, params, send)
    },

    /** The guard read the refusal; it leaves the queue. A pending act can never be dismissed. */
    async dismiss(id: string): Promise<void> {
      const op = load().find((o) => o.id === id)
      if (!op || (op.state !== 'refused' && op.state !== 'confirmed')) return
      gone(op)
      ops = load().filter((o) => o.id !== id)
      await persist()
    },

    /** Test/logout hook. */
    reset(): void {
      ops = null
      failedAt = null
      failures = 0
    },
  }
}

export type Outbox = ReturnType<typeof createOutbox>
