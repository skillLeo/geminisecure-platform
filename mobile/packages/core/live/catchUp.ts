/**
 * CATCH-UP (DESIGN §5): the log is the truth and the socket is a hint.
 *
 *   POST live/catch-up  {"channels":[{"name":"estate.pp.passes","e":1042,"ep":{"e":"01J9ZC3K"}}, …]}
 *   → {"channels":[{"name":"estate.pp.passes","status":"ok","events":[…],"head":{"e":1050},"more":false},
 *                  {"name":"gemini.dispatch","status":"refused"},
 *                  {"name":"estate.pp.governance","status":"reset","head":{"e":2201}}],
 *      "relay":{"ok":true,"lag_ms":40},"build":"a342c56"}
 *
 * APPLY BEFORE LIVE, PER CHANNEL (§5.3). Subscribed → CATCHING UP: whatever the socket delivers is BUFFERED;
 * the answer's events are applied in order, the cursor becomes the head, then the buffer is applied where
 * q > cursor, in order, and only then is the channel LIVE. A live message whose `p` is ahead of the cursor
 * is a gap: the channel goes back to catching up, and catches up 300 ms later.
 *
 * A RESET (§5.5) — the cursor is below what the server keeps, above its head, from another epoch, or more
 * than 500 behind — resyncs instead: the mounted screens refetch and the durable stores fed by that channel
 * revalidate (the client's `resync`), and the cursor becomes the head.
 *
 * ONE REQUEST FOR MANY CHANNELS. Every channel asked for within 50 ms goes in one POST (at most 200 per
 * request). A channel is never in two requests at once: asked for again while its answer is on the way, it
 * is asked again once that answer lands (so a subscription made after a request started is still covered).
 * A failed request is retried on the same backoff as the socket; nothing is lost meanwhile, because the
 * cursor only moves when something was applied.
 *
 * Catch-up events are NOT gap-checked: the server merges them by record (§5.1), so their `p` legitimately
 * skips seqs. They are deduplicated against the cursor like everything else.
 */

import { NUDGE, RESYNC, STREAMS, isChannelName, isSequenced, parseEnvelope, type LiveAllowlist, type LiveEnvelope, type SequencedEnvelope, type Stream } from './envelope'
import { judge, type ChannelCursors, type Heads } from './cursors'

export type ChannelSpec = { name: string; streams: Stream[] }
export type SyncState = 'idle' | 'catching' | 'live' | 'refused'
export type ResyncReason = 'reset' | 'epoch' | 'resync' | 'no-cursor'

export type CatchUpRequestChannel = { name: string; c?: number | null; e?: number | null; ep?: Partial<Record<Stream, string>> }
export type CatchUpRequest = { channels: CatchUpRequestChannel[] }
export type CatchUpStatus = 'ok' | 'refused' | 'reset'
/** `ep`: each stream's epoch as the server has it now — what a cursor that has none yet is taken to be in. */
export type CatchUpAnswerChannel = { name: string; status: CatchUpStatus; events: unknown[]; head: Heads; ep: Partial<Record<Stream, string>>; more: boolean }
export type CatchUpAnswer = { channels: CatchUpAnswerChannel[]; relay: { ok: boolean; lagMs: number | null } | null }

/** The server keeps 500 per (channel, stream) and 2,000 per answer; a request names at most 200 channels. */
export const MAX_CHANNELS_PER_REQUEST = 200
/** Asked for within this long → one request. */
export const BATCH_MS = 50
/** A gap or a nudge waits this long, so a burst of messages costs one catch-up. */
export const GAP_DELAY_MS = 300
/** Socket messages held while a channel catches up. Dropping the oldest is safe: the cursor has not passed them. */
export const MAX_BUFFER = 500
/** `more: true` rounds for one channel before it waits for the next reason to catch up. */
export const MAX_MORE_ROUNDS = 10

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v)

function headsOf(value: unknown): Heads {
  const heads: Heads = {}
  if (!isRecord(value)) return heads
  for (const s of STREAMS) {
    const v = value[s]
    if (typeof v === 'number' && Number.isSafeInteger(v) && v >= 0) heads[s] = v
  }
  return heads
}

function epochsOf(value: unknown): Partial<Record<Stream, string>> {
  const epochs: Partial<Record<Stream, string>> = {}
  if (!isRecord(value)) return epochs
  for (const s of STREAMS) {
    const v = value[s]
    if (typeof v === 'string' && v.length > 0 && v.length <= 32) epochs[s] = v
  }
  return epochs
}

/** The answer, read defensively AFTER the allowlist. A channel entry that is not one is left out. */
export function readAnswer(raw: unknown): CatchUpAnswer {
  const answer: CatchUpAnswer = { channels: [], relay: null }
  if (!isRecord(raw)) return answer
  if (Array.isArray(raw.channels)) {
    for (const c of raw.channels) {
      if (!isRecord(c) || !isChannelName(c.name)) continue
      const status: CatchUpStatus | null = c.status === 'ok' || c.status === 'refused' || c.status === 'reset' ? c.status : null
      if (!status) continue
      answer.channels.push({ name: c.name, status, events: Array.isArray(c.events) ? c.events : [], head: headsOf(c.head), ep: epochsOf(c.ep), more: c.more === true })
    }
  }
  if (isRecord(raw.relay) && typeof raw.relay.ok === 'boolean') {
    answer.relay = { ok: raw.relay.ok, lagMs: typeof raw.relay.lag_ms === 'number' ? raw.relay.lag_ms : null }
  }
  return answer
}

export type ChannelHooks = {
  /** Hands one event to the app's handlers. Resolves when they have finished; never rejects. */
  apply(channel: string, env: LiveEnvelope, via: 'socket' | 'catch-up'): Promise<void>
  /** A reset, an epoch change, an operator's resync or a first start with nothing to start from. */
  resync(channel: string, head: Heads, reason: ResyncReason): Promise<void>
  /** The cursors moved: save them. */
  saved(channel: string, cursors: ChannelCursors): void
  /** Catch this channel up after `GAP_DELAY_MS` (a gap, or the relay's nudge). */
  later(channel: string): void
  /** Something a status line depends on changed. */
  changed(): void
}

/**
 * One channel's place and state. Every step for a channel runs in order on its own chain, so a slow handler
 * on one channel never holds up another, and two steps for the same channel never interleave.
 */
export class ChannelSync {
  state: SyncState = 'idle'
  /** Bumped by every subscription: only an answer to a request made after it may take the channel live. */
  generation = 0
  private buffer: SequencedEnvelope[] = []
  private chain: Promise<void> = Promise.resolve()
  private moreRounds = 0

  constructor(
    readonly spec: ChannelSpec,
    public cursors: ChannelCursors,
    private readonly first: () => Heads,
    private readonly hooks: ChannelHooks,
  ) {}

  get name(): string {
    return this.spec.name
  }

  private run(step: () => Promise<void>): Promise<void> {
    const next = this.chain.then(step, step)
    this.chain = next.catch(() => undefined)
    return this.chain
  }

  private set(next: SyncState): void {
    if (this.state === next) return
    this.state = next
    this.hooks.changed()
  }

  /** The state as it is NOW: a method, so a check after a step that may have moved it is never narrowed away. */
  private isLive(): boolean {
    return this.state === 'live'
  }

  /** The socket confirmed the subscription: hold what arrives until the catch-up has been applied. */
  subscribed(): void {
    this.buffer = []
    this.moreRounds = 0
    this.generation += 1
    this.set('catching')
  }

  /** The socket went away or the channel was dropped. Nothing arrives until it is subscribed again. */
  unsubscribed(): void {
    this.buffer = []
    this.set('idle')
  }

  refused(): void {
    this.buffer = []
    this.set('refused')
  }

  /** What the catch-up request says about this channel: per stream, where it is up to — or the first head. */
  request(): CatchUpRequestChannel {
    const out: CatchUpRequestChannel = { name: this.name }
    const first = this.first()
    const ep: Partial<Record<Stream, string>> = {}
    for (const s of this.spec.streams) {
      const cursor = this.cursors[s]
      out[s] = cursor ? cursor.q : first[s] ?? null
      if (cursor?.ep) ep[s] = cursor.ep
    }
    if (Object.keys(ep).length) out.ep = ep
    return out
  }

  /** A message from the socket, already through the allowlist and parsed. */
  receive(env: LiveEnvelope): Promise<void> {
    return this.run(async () => {
      if (!isSequenced(env)) {
        // Ephemeral: the relay's nudge asks for a catch-up; a signal goes straight to the handlers. No cursor moves.
        if (env.t === NUDGE) this.hooks.later(this.name)
        else if (this.state === 'live') await this.hooks.apply(this.name, env, 'socket')
        return
      }
      if (this.state !== 'live') {
        this.hold(env)
        return
      }
      await this.judged(env)
    })
  }

  private hold(env: SequencedEnvelope): void {
    this.buffer.push(env)
    if (this.buffer.length > MAX_BUFFER) this.buffer.shift()
  }

  private async judged(env: SequencedEnvelope): Promise<void> {
    const verdict = judge(this.cursors[env.s], env)
    if (verdict === 'duplicate') return
    if (verdict === 'gap') {
      this.set('catching')
      this.hold(env)
      this.hooks.later(this.name)
      return
    }
    if (verdict === 'epoch') {
      // The database behind this stream is not the one the cursor was about: nothing before this event can
      // be trusted, so the screens resync — once — and the cursor starts again in the new epoch.
      await this.hooks.resync(this.name, { [env.s]: env.q }, 'epoch')
    }
    await this.applied(env, 'socket')
  }

  private async applied(env: SequencedEnvelope, via: 'socket' | 'catch-up'): Promise<void> {
    await this.hooks.apply(this.name, env, via)
    this.cursors = { ...this.cursors, [env.s]: { q: env.q, ep: env.ep } }
    this.hooks.saved(this.name, this.cursors)
    if (env.t === RESYNC) await this.hooks.resync(this.name, { [env.s]: env.q }, 'resync')
  }

  /**
   * This channel's part of a catch-up answer, to the request made at subscription `sentGeneration`. Resolves
   * `true` when the server has more for it (`more`).
   *
   * Applied whatever the channel's state — a channel with no socket (down, or a resume before the reconnect) is
   * still brought up to date by the poll — but only an answer to a request made AFTER the channel's latest
   * subscription takes it live: an older one cannot cover what was published between the two.
   */
  caughtUp(answer: CatchUpAnswerChannel, sentGeneration: number = this.generation): Promise<boolean> {
    let more = false
    return this.run(async () => {
      if (this.state === 'refused') return
      if (answer.status === 'refused') {
        this.refused()
        return
      }
      // Nothing at all to start from (no cursor, no head): take the head, and refetch the screens once (§5.2).
      const started = this.spec.streams.every((s) => this.cursors[s] !== undefined || this.first()[s] !== undefined)

      if (answer.status === 'reset') {
        this.cursors = this.withHead(answer.head, answer.ep, true)
        this.hooks.saved(this.name, this.cursors)
        await this.hooks.resync(this.name, answer.head, 'reset')
      } else {
        const events = answer.events.map(parseEnvelope).filter((e): e is SequencedEnvelope => e !== null && isSequenced(e))
        for (const env of events) {
          const cursor = this.cursors[env.s]
          if (cursor && cursor.ep !== null && cursor.ep !== env.ep) {
            await this.hooks.resync(this.name, { [env.s]: env.q }, 'epoch')
          } else if (cursor && env.q <= cursor.q) {
            continue
          }
          await this.applied(env, 'catch-up')
        }
        more = answer.more && this.moreRounds < MAX_MORE_ROUNDS
        this.moreRounds = more ? this.moreRounds + 1 : 0
        // With more to come, the cursor stays where the last applied event put it; otherwise it is the head.
        if (!more) {
          this.cursors = this.withHead(answer.head, answer.ep, false)
          this.hooks.saved(this.name, this.cursors)
        }
        if (!started) await this.hooks.resync(this.name, answer.head, 'no-cursor')
      }

      // Still more to come, not subscribed, already live, or answering an older subscription: nothing to switch.
      if (more || this.state !== 'catching' || sentGeneration !== this.generation) return
      // What arrived meanwhile, in order: the duplicates fall away, and a new gap sends it back to catching up.
      const held = this.buffer.sort((a, b) => (a.s === b.s ? a.q - b.q : a.s < b.s ? -1 : 1))
      this.buffer = []
      this.set('live')
      for (const env of held) {
        // Read afresh each time: a gap in the held messages sends the channel back to catching up.
        if (!this.isLive()) this.hold(env)
        else await this.judged(env)
      }
    }).then(() => more)
  }

  /**
   * The cursors moved to the answer's head, stream by stream, in the epoch the answer names. A reset takes the
   * answer's epoch outright; otherwise a cursor keeps the epoch it has (the server would have reset it had they
   * differed) and one that has none takes the answer's — so the next request carries it, and a rebuilt database
   * is caught by the server's check rather than only by the next event's.
   */
  private withHead(head: Heads, ep: Partial<Record<Stream, string>>, reset: boolean): ChannelCursors {
    const next: ChannelCursors = { ...this.cursors }
    for (const s of this.spec.streams) {
      const h = head[s]
      if (h === undefined) continue
      const known = next[s]
      next[s] = { q: reset || !known ? h : Math.max(known.q, h), ep: reset ? ep[s] ?? null : known?.ep ?? ep[s] ?? null }
    }
    return next
  }
}

/* ── the scheduler: one request for many channels, never two for one ──────────────────────────────── */

export type CatchUpDeps = {
  post(body: CatchUpRequest): Promise<unknown>
  allowlist(): LiveAllowlist
  channel(name: string): ChannelSync | undefined
  /** After every answer or failure: how the relay is doing, whether this round worked, and for which channels. */
  outcome(result: { ok: boolean; relay: CatchUpAnswer['relay']; names: string[] }): void
  /** The delay before retrying failed round `attempt` (1-based). */
  retryIn(attempt: number): number
  /**
   * An answer's channels are being applied (`true`) and have all been applied (`false`). Several channels reset by
   * ONE answer refetch the screens once (the client's resync waits for the `false`) — the channels are applied one
   * after another, so without it a refetch queued by the first would fire before the second was reached.
   */
  applying?(on: boolean): void
}

export class CatchUpScheduler {
  private wanted = new Set<string>()
  private inFlight = new Set<string>()
  private timer: ReturnType<typeof setTimeout> | null = null
  private dueAt = Infinity
  private later = new Map<string, ReturnType<typeof setTimeout>>()
  private retry: ReturnType<typeof setTimeout> | null = null
  /** Every channel a failed round left behind, until the retry asks for them all — two failures lose neither. */
  private retryNames = new Set<string>()
  private failures = 0
  private stopped = false

  constructor(private readonly deps: CatchUpDeps) {}

  /**
   * Catch these channels up within `delay` ms (batched with anything else asked for in that window). `0` is NOW:
   * everything asked for goes at once, without waiting for a timer — a resume, a Refresh, a retry, a gap's turn.
   */
  request(names: Iterable<string>, delay: number = BATCH_MS): void {
    if (this.stopped) return
    for (const n of names) this.wanted.add(n)
    if (this.wanted.size === 0) return
    if (delay <= 0) {
      if (this.timer) clearTimeout(this.timer)
      this.timer = null
      this.dueAt = Infinity
      void this.flush()
      return
    }
    const due = Date.now() + delay
    if (this.timer && due >= this.dueAt) return
    if (this.timer) clearTimeout(this.timer)
    this.dueAt = due
    this.timer = setTimeout(() => {
      this.timer = null
      this.dueAt = Infinity
      void this.flush()
    }, delay)
  }

  /** A gap or a nudge: this one channel, after `GAP_DELAY_MS`, however many times it is asked meanwhile. */
  requestLater(name: string): void {
    if (this.stopped || this.later.has(name)) return
    this.later.set(
      name,
      setTimeout(() => {
        this.later.delete(name)
        this.request([name], 0)
      }, GAP_DELAY_MS),
    )
  }

  /** Whether any channel is asked for or on its way. */
  get busy(): boolean {
    return this.wanted.size > 0 || this.inFlight.size > 0
  }

  private async flush(): Promise<void> {
    if (this.stopped) return
    // A channel dropped since it was asked for is forgotten, or it would keep `busy` true for ever.
    for (const n of [...this.wanted]) if (this.deps.channel(n) === undefined) this.wanted.delete(n)
    const names = [...this.wanted].filter((n) => !this.inFlight.has(n))
    for (const n of names) this.wanted.delete(n)
    for (let i = 0; i < names.length; i += MAX_CHANNELS_PER_REQUEST) {
      void this.send(names.slice(i, i + MAX_CHANNELS_PER_REQUEST))
    }
  }

  private async send(names: string[]): Promise<void> {
    const syncs = names.map((n) => this.deps.channel(n)).filter((s): s is ChannelSync => s !== undefined && s.state !== 'refused')
    if (syncs.length === 0) return
    for (const s of syncs) this.inFlight.add(s.name)
    // Which subscription each request is about, taken when it is sent (ChannelSync.caughtUp).
    const generations = new Map(syncs.map((s) => [s.name, s.generation]))
    let again: string[] = []
    let ok = false
    let relay: CatchUpAnswer['relay'] = null
    try {
      const raw = await this.deps.post({ channels: syncs.map((s) => s.request()) })
      if (this.stopped) return
      const answer = readAnswer(this.deps.allowlist()(raw))
      relay = answer.relay
      const byName = new Map(answer.channels.map((c) => [c.name, c]))
      this.deps.applying?.(true)
      try {
        for (const s of syncs) {
          const part = byName.get(s.name)
          if (!part) {
            again.push(s.name) // the answer left it out: it is asked for again with the next retry
            continue
          }
          if (await s.caughtUp(part, generations.get(s.name))) this.wanted.add(s.name)
        }
      } finally {
        this.deps.applying?.(false)
      }
      ok = again.length === 0
    } catch {
      again = syncs.map((s) => s.name)
    } finally {
      for (const s of syncs) this.inFlight.delete(s.name)
    }
    if (this.stopped) return
    this.deps.outcome({ ok, relay, names: syncs.map((s) => s.name) })
    if (ok) this.failures = 0
    else this.scheduleRetry(again)
    // Asked for again while this answer was on its way, or the server had more: go now.
    const pending = [...this.wanted].filter((n) => !this.inFlight.has(n))
    if (pending.length) this.request([], 0)
  }

  private scheduleRetry(names: string[]): void {
    this.failures += 1
    if (this.retry) clearTimeout(this.retry)
    for (const n of names) this.retryNames.add(n)
    this.retry = setTimeout(() => {
      this.retry = null
      const due = [...this.retryNames]
      this.retryNames.clear()
      this.request(due, 0)
    }, this.deps.retryIn(this.failures))
  }

  stop(): void {
    this.stopped = true
    if (this.timer) clearTimeout(this.timer)
    if (this.retry) clearTimeout(this.retry)
    for (const t of this.later.values()) clearTimeout(t)
    this.timer = null
    this.retry = null
    this.later.clear()
    this.retryNames.clear()
    this.wanted.clear()
    this.inFlight.clear()
  }
}
