/**
 * THE WIRE ENVELOPE (DESIGN §2.2) — the one shape every live message has, checked here before anything else
 * in the app can see it.
 *
 *   {"v":1,"s":"c","ep":"01J9ZC3K","q":1042,"p":1038,"t":"alert.resolved","e":"alert","i":"55","m":"inline",
 *    "d":{…},"sum":{"status":"resolved"},"o":"3fa1c09e5b2d7a10","at":"2026-10-02T14:03:11.221Z","sim":false}
 *
 *   s   the stream: `c` central, `e` the estate's own
 *   ep  the first 8 characters of that stream's epoch; a different one means the database was re-provisioned
 *   q   the sequence number, AND the record's version. `null` on an ephemeral message (`live.nudge`, a signal)
 *   p   the previous seq on THIS channel in this stream: `p > cursor` is a gap
 *   m   `ref` (refetch the one record) or `inline` (`d` is the record, hydrated at publish)
 *   o   the origin nonce of the write that caused it (origin.ts)
 *
 * A message that does not have this shape is DROPPED, never guessed at: nothing downstream of here has to
 * wonder whether `q` is a number.
 *
 * CHANNEL NAMES ARE THE SERVER'S. The phone never builds one (§2.1): it takes the names `live.config`
 * gives it and adds or removes only the transport's `private-live.` prefix.
 *
 * THE ALLOWLIST. A socket message is a catch-up answer of one: it is wrapped into the `live.catch_up` shape
 * and passed through the app's allowlist (the generated `enforceAllowlist('live.catch_up', …)`, configured
 * by the app) before any handler sees it (§4.1 rule 4, §8.4). Until an app configures one, the default
 * keeps the envelope's own keys and drops the inline data `d` — an allowlist, never a denylist: a handler
 * then refetches the record instead of reading a field nobody vetted.
 */

export type Stream = 'c' | 'e'
export const STREAMS: readonly Stream[] = ['c', 'e']

export type LiveMode = 'ref' | 'inline'

export type LiveEnvelope = {
  v: 1
  /** `null` only on an ephemeral message that names no stream. */
  s: Stream | null
  ep: string | null
  q: number | null
  p: number | null
  t: string
  e: string
  i: string
  m: LiveMode
  /** Inline data, hydrated at publish. Only present when the app's allowlist lets it through. */
  d?: unknown
  sum: Record<string, unknown>
  o: string | null
  at: string | null
  sim: boolean
}

/** A logged event: it has a stream, an epoch and a sequence number, so it moves a cursor. */
export type SequencedEnvelope = LiveEnvelope & { s: Stream; ep: string; q: number }

export const isSequenced = (env: LiveEnvelope): env is SequencedEnvelope => env.q !== null && env.s !== null && env.ep !== null

/** The relay's control message: "catch this channel up" (§1.5). Never logged, never handed to a handler. */
export const NUDGE = 'live.nudge'
/** An operator's `live:resync {tenant}` (§5.5): logged, and it resyncs the channel like a reset. */
export const RESYNC = 'live.resync'
/** A permission change for this principal (§7). */
export const ACCESS_CHANGED = 'access.changed'

/** The envelope's own keys. Everything else on a message is dropped by the default allowlist. */
export const ENVELOPE_KEYS = ['v', 's', 'ep', 'q', 'p', 't', 'e', 'i', 'm', 'sum', 'o', 'at', 'sim'] as const

const TYPE = /^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/
const NONCE = /^[a-f0-9]{16}$/
const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v)
const isSeq = (v: unknown): v is number => typeof v === 'number' && Number.isSafeInteger(v) && v >= 1
const isPrev = (v: unknown): v is number => typeof v === 'number' && Number.isSafeInteger(v) && v >= 0
const idOf = (v: unknown): string | null => (typeof v === 'string' && v.length > 0 && v.length <= 64 ? v : typeof v === 'number' && Number.isSafeInteger(v) ? String(v) : null)

/**
 * The envelope, or `null` when the message is not one. A socket delivers the data as a JSON string; a
 * catch-up answer as an object — both are accepted.
 */
export function parseEnvelope(raw: unknown): LiveEnvelope | null {
  let value: unknown = raw
  if (typeof raw === 'string') {
    try {
      value = JSON.parse(raw)
    } catch {
      return null
    }
  }
  if (!isRecord(value)) return null
  if (value.v !== undefined && value.v !== 1) return null // a version this build cannot read is not guessed at
  if (typeof value.t !== 'string' || !TYPE.test(value.t) || value.t.length > 64) return null

  const q = value.q === null || value.q === undefined ? null : value.q
  if (q !== null && !isSeq(q)) return null
  const p = value.p === null || value.p === undefined ? null : value.p
  if (p !== null && !isPrev(p)) return null

  const s = value.s === 'c' || value.s === 'e' ? value.s : null
  if (value.s !== undefined && value.s !== null && s === null) return null
  const ep = typeof value.ep === 'string' && value.ep.length > 0 && value.ep.length <= 32 ? value.ep : null
  if (value.ep !== undefined && value.ep !== null && ep === null) return null

  // A logged event must say which stream, which epoch and what it is about — or it could not be caught up.
  const e = typeof value.e === 'string' && value.e.length <= 40 ? value.e : null
  const i = idOf(value.i)
  if (q !== null && (s === null || ep === null || e === null || e === '' || i === null)) return null

  const m: LiveMode = value.m === 'inline' ? 'inline' : 'ref'
  if (value.m !== undefined && value.m !== 'inline' && value.m !== 'ref') return null

  const envelope: LiveEnvelope = {
    v: 1,
    s,
    ep,
    q,
    p,
    t: value.t,
    e: e ?? '',
    i: i ?? '',
    m,
    sum: isRecord(value.sum) ? value.sum : {},
    o: typeof value.o === 'string' && NONCE.test(value.o) ? value.o : null,
    at: typeof value.at === 'string' ? value.at : null,
    sim: value.sim === true,
  }
  if ('d' in value && value.d !== undefined) envelope.d = value.d
  return envelope
}

/* ── channel names ─────────────────────────────────────────────────────────────────────────────── */

/** The transport prefix of every live channel (§3.1). Nothing else is ever subscribed through /live/auth. */
export const WIRE_PREFIX = 'private-live.'

/** The public channel the relay's pulse arrives on (§1.5), unless live.config names another. */
export const PULSE_CHANNEL = 'live-pulse'

const NAME = /^[a-z0-9]+(?:[._-][a-z0-9]+)*$/

/** A name as `live.config` gives it: lower case, dotted, at most 160 characters. Anything else is refused. */
export const isChannelName = (name: unknown): name is string => typeof name === 'string' && name.length <= 160 && NAME.test(name)

/** `estate.pp.passes` → `private-live.estate.pp.passes`. The name itself is the server's. */
export const toWire = (name: string): string => `${WIRE_PREFIX}${name}`

/** `private-live.estate.pp.passes` → `estate.pp.passes`; anything that is not a live channel → `null`. */
export function fromWire(wire: string): string | null {
  if (!wire.startsWith(WIRE_PREFIX)) return null
  const name = wire.slice(WIRE_PREFIX.length)
  return isChannelName(name) ? name : null
}

/* ── the allowlist ─────────────────────────────────────────────────────────────────────────────── */

/**
 * What the app passes every catch-up answer and every socket message through: the generated
 * `(answer) => enforceAllowlist('live.catch_up', answer)`. It gets the whole `live.catch_up` shape.
 */
export type LiveAllowlist = (answer: unknown) => unknown

const pickKeys = (source: Record<string, unknown>, keys: readonly string[]): Record<string, unknown> => {
  const out: Record<string, unknown> = {}
  for (const k of keys) if (source[k] !== undefined) out[k] = source[k]
  return out
}

/**
 * The default until an app configures its own: the catch-up answer's structure and the envelope's own keys,
 * and nothing else — in particular not `d`. Fails SAFE: a handler refetches what it was not given.
 */
export const envelopeOnly: LiveAllowlist = (answer) => {
  if (!isRecord(answer)) return answer
  const out = pickKeys(answer, ['relay', 'build'])
  if (isRecord(out.relay)) out.relay = pickKeys(out.relay, ['ok', 'lag_ms'])
  if (Array.isArray(answer.channels)) {
    out.channels = answer.channels.map((channel) => {
      if (!isRecord(channel)) return channel
      const kept = pickKeys(channel, ['name', 'status', 'head', 'ep', 'more'])
      if (Array.isArray(channel.events)) kept.events = channel.events.map((env) => (isRecord(env) ? pickKeys(env, ENVELOPE_KEYS) : env))
      return kept
    })
  }
  return out
}

/**
 * A socket message, passed through the allowlist exactly as a catch-up answer of one would be: wrapped into
 * `{channels: [{name, status, events: [message]}]}`, allowed, and unwrapped. `null` if nothing survives.
 */
export function allowSocketMessage(allowlist: LiveAllowlist, channel: string, message: unknown): unknown {
  let value: unknown = message
  if (typeof message === 'string') {
    try {
      value = JSON.parse(message)
    } catch {
      return null
    }
  }
  const allowed = allowlist({ channels: [{ name: channel, status: 'ok', events: [value] }] })
  if (!isRecord(allowed) || !Array.isArray(allowed.channels)) return null
  const first = allowed.channels[0]
  if (!isRecord(first) || !Array.isArray(first.events)) return null
  return first.events[0] ?? null
}
