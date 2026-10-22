/**
 * THE PHONE'S LIVE CLIENT (DESIGN §8): one socket, its channels, their cursors, and what the app is told.
 *
 *   configureLive({ store, app, allowlist, onAccessChanged, … })   configure.ts — once, at start-up
 *   startLive()          a session is active (or a resident's claim is pending): go live whenever the app is in
 *                        the foreground and online. Runs every durable store's revalidator once that holds
 *   stopLive()           the session ended: close everything and forget it (the cursors on disk stay until the
 *                        sign-out clears them — clearEverything does)
 *   refreshLive()        the banner's Refresh: catch up now, and reconnect now if the socket is down
 *   nudgeLive()          a push arrived in the foreground: catch up now (§8.6)
 *   refreshLiveConfig()  the channel list may have changed (a claim was decided, access changed): fetch
 *                        live.config again and subscribe to the difference
 *
 * WHAT IT CALLS. `GET live/config`, `POST live/auth` and `POST live/catch-up`, through the app's one base
 * query with raw paths (so the token, the timeout, the retry rules and the 401 handling are the ones every
 * other request has). w0-mobile switches them to the generated endpoints.
 *
 *   live.config   {ws: {key, port, scheme, path}, channels: [{name, streams}], pulse, build, principal}
 *                 — no host: the phone derives it from its one API address (socket.ts)
 *   live.auth     {socket_id, channel_names: ['private-live.<name>', …]} → {channels: [{name, auth,
 *                 channel_data, streams, refused}]}, as the catalogue's entry names both; readGrants accepts
 *                 the web's batch form of the answer too
 *   live.catch_up catchUp.ts
 *
 * WHAT IT NEVER DOES (C9, §8.7): write a record, confirm, drain or offer an outbox op, touch an idempotency
 * stamp, raise a banner, or log a payload. boundaries.test holds that line.
 */

import type { BaseQueryApi, FetchArgs } from '@reduxjs/toolkit/query'
import { baseQuery } from '../api/baseQuery'
import { isFidelity } from '../fidelity'
import { dataLight } from '../lib/dataLight'
import { CatchUpScheduler, ChannelSync, type CatchUpRequest, type ChannelHooks, type ChannelSpec, type ResyncReason } from './catchUp'
import { allowlistOf, dispatchLiveEvent, liveOptions, type AccessEffect, type LiveOptions } from './configure'
import { firstHeads, forgetHeads, markConnected, savedCursors, type ChannelCursors, type Heads } from './cursors'
import { revalidateDurableStores, durableCursor } from './durable'
import { ACCESS_CHANGED, PULSE_CHANNEL, STREAMS, allowSocketMessage, fromWire, isChannelName, isSequenced, parseEnvelope, toWire, type LiveEnvelope, type Stream } from './envelope'
import { backoffMs, onSafetyScreenChange } from './fallback'
import { Lifecycle, liveStatus, reactNativeEnvironment, type LifecycleDriver, type StatusFacts } from './lifecycle'
import { forgetOrigins } from './origin'
import { bindLiveStore, resyncMounted } from './rtk'
import { defaultSocketFactory, socketHost, type ChannelGrant, type LiveSocket, type SocketEvents, type SocketSettings } from './socket'

/** live.config older than this is fetched again on resume (§8.3). */
export const CONFIG_MAX_AGE_MS = 10 * 60_000
/** A channel whose auth request failed (not a refusal) is asked for again after this long. */
export const AUTH_RETRY_MS = 5_000
/** Events already handed to the handlers, remembered so a second channel carrying one does not run them again. */
const SEEN_LIMIT = 2_000

export type LiveHttp = {
  config(): Promise<unknown>
  /** `channel_names`, as the catalogue's live.auth entry names the field (the web's batch door takes the same). */
  auth(body: { socket_id: string; channel_names: string[] }): Promise<unknown>
  catchUp(body: CatchUpRequest): Promise<unknown>
}

/** A live request the server refused or that could not be made. Carries the status and code, never a body. */
export class LiveHttpError extends Error {
  constructor(
    readonly status: number | string,
    readonly code: string | null,
  ) {
    super(`live request failed (${String(status)})`)
  }
}

export type LiveConfig = {
  settings: SocketSettings
  channels: ChannelSpec[]
  /** `g-5`, `r-9` (or `u-12`): whose cursors these are. `null`: kept in memory only. */
  principal: string | null
  build: string | null
}

const isRecord = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v)

/** live.config, read defensively. `null` when there is no socket to make from it. */
export function readConfig(raw: unknown): LiveConfig | null {
  if (!isRecord(raw) || !isRecord(raw.ws)) return null
  const { key, port, scheme, path } = raw.ws
  if (typeof key !== 'string' || key.length === 0 || key.length > 128) return null
  const portNumber = Number(port)
  if (!Number.isInteger(portNumber) || portNumber < 1 || portNumber > 65535) return null
  const tls = typeof scheme === 'string' && /^(wss|https)$/i.test(scheme)
  const wsPath = typeof path === 'string' && /^(\/[A-Za-z0-9._~-]+)*\/?$/.test(path) ? path.replace(/\/+$/, '') : ''

  const channels: ChannelSpec[] = []
  const names = new Set<string>()
  for (const c of Array.isArray(raw.channels) ? raw.channels : []) {
    if (!isRecord(c) || !isChannelName(c.name) || names.has(c.name)) continue
    names.add(c.name)
    const streams = Array.isArray(c.streams) ? STREAMS.filter((s) => (c.streams as unknown[]).includes(s)) : [...STREAMS]
    channels.push({ name: c.name, streams: streams.length ? streams : [...STREAMS] })
  }

  const pulseName = typeof raw.pulse === 'string' ? raw.pulse : isRecord(raw.pulse) && typeof raw.pulse.channel === 'string' ? raw.pulse.channel : null
  const pulseChannel = pulseName && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(pulseName) ? pulseName : PULSE_CHANNEL
  const principalValue = typeof raw.principal === 'string' ? raw.principal : isRecord(raw.principal) && typeof raw.principal.key === 'string' ? raw.principal.key : null
  const principal = principalValue && /^[ugr]-\d{1,19}$/.test(principalValue) ? principalValue : null

  return {
    settings: { key, host: socketHost(), port: portNumber, tls, path: wsPath, pulseChannel },
    channels,
    principal,
    build: typeof raw.build === 'string' ? raw.build : null,
  }
}

/** live.auth's answer: a grant or 'refused' per channel name (without the transport prefix). */
export function readGrants(raw: unknown): Map<string, ChannelGrant | 'refused'> {
  const out = new Map<string, ChannelGrant | 'refused'>()
  if (!isRecord(raw)) return out
  const nameOf = (n: unknown): string | null => (typeof n !== 'string' ? null : fromWire(n) ?? (isChannelName(n) ? n : null))
  const grantOf = (v: unknown): ChannelGrant | 'refused' => {
    if (!isRecord(v) || v.refused === true || typeof v.auth !== 'string' || v.auth.length === 0) return 'refused'
    return typeof v.channel_data === 'string' ? { auth: v.auth, channel_data: v.channel_data } : { auth: v.auth }
  }
  if (Array.isArray(raw.channels)) {
    // The API's array form.
    for (const entry of raw.channels) {
      const name = isRecord(entry) ? nameOf(entry.name) : null
      if (name) out.set(name, grantOf(entry))
    }
  } else if (isRecord(raw.channels)) {
    // The web's batch form: {channels: {<name>: {auth, channel_data}}, refused: [<name>]}.
    for (const [n, v] of Object.entries(raw.channels)) {
      const name = nameOf(n)
      if (name) out.set(name, grantOf(v))
    }
  }
  if (Array.isArray(raw.refused)) {
    for (const n of raw.refused) {
      const name = nameOf(n)
      if (name) out.set(name, 'refused')
    }
  }
  return out
}

/* ── the default HTTP: the app's own base query, raw paths ─────────────────────────────────────── */

function baseQueryHttp(opts: LiveOptions): LiveHttp {
  const call = async (args: FetchArgs, endpoint: string, type: 'query' | 'mutation'): Promise<unknown> => {
    const extra = opts.headers?.() ?? {}
    const request: FetchArgs = Object.keys(extra).length ? { ...args, headers: { ...extra } } : args
    const controller = new AbortController()
    const api = {
      signal: controller.signal,
      abort: () => controller.abort(),
      dispatch: opts.store.dispatch,
      getState: opts.store.getState,
      extra: undefined,
      endpoint,
      type,
    } as unknown as BaseQueryApi
    const result = await baseQuery(request, api, {})
    if (result.error) throw new LiveHttpError(result.error.status, result.error.code ?? null)
    return result.data
  }
  return {
    config: () => call({ url: '/live/config' }, 'live.config', 'query'),
    auth: (body) => call({ url: '/live/auth', method: 'POST', body }, 'live.auth', 'mutation'),
    catchUp: (body) => call({ url: '/live/catch-up', method: 'POST', body }, 'live.catch_up', 'mutation'),
  }
}

/* ── the running client ────────────────────────────────────────────────────────────────────────── */

type Runtime = {
  opts: LiveOptions
  http: LiveHttp
  lifecycle: Lifecycle
  scheduler: CatchUpScheduler
  syncs: Map<string, ChannelSync>
  config: LiveConfig | null
  configAt: number
  configLoad: Promise<LiveConfig | null> | null
  configAgain: boolean
  socket: LiveSocket | null
  socketKey: string
  principal: string | null
  saved: Record<string, ChannelCursors>
  catchUpFailing: boolean
  relayOk: boolean | null
  lastPulseAt: number | null
  openedAt: number | null
  seen: Set<string>
  seenOrder: string[]
  pendingStart: boolean
  resyncQueued: boolean
  /** Catch-up answers being applied right now: a resync they ask for waits until the last has finished. */
  applying: number
  resyncWanted: boolean
  authRetries: Map<string, ReturnType<typeof setTimeout>>
  off: Array<() => void>
  trace: boolean
}

let rt: Runtime | null = null

const sessionStatus = (store: LiveOptions['store']): string | undefined => (store.getState() as { session?: { status?: string } }).session?.status
/** A resident whose claim is pending is live too: `resident.*` and `claim.*` are theirs, so a decision is never missed (§3.4). */
const sessionLive = (store: LiveOptions['store']): boolean => {
  const status = sessionStatus(store)
  return status === 'active' || status === 'pending'
}
/** The app's own connectivity flag — the same one its offline line reads. A store without one is taken as online. */
const onlineIn = (store: LiveOptions['store']): boolean => (store.getState() as { network?: { online?: boolean } }).network?.online !== false

const fingerprint = (s: SocketSettings): string => [s.key, s.host, s.port, s.tls, s.path, s.pulseChannel].join('|')
const sameStreams = (a: ChannelSpec, b: ChannelSpec): boolean => a.streams.length === b.streams.length && a.streams.every((s) => b.streams.includes(s))

function statusFacts(r: Runtime): StatusFacts {
  return {
    started: r.lifecycle.isStarted,
    fidelity: isFidelity,
    sessionLive: sessionLive(r.opts.store),
    online: onlineIn(r.opts.store),
    foreground: r.lifecycle.foreground,
    socket: r.lifecycle.socket,
    channels: [...r.syncs.values()].map((s) => s.state),
    catchUpFailing: r.catchUpFailing,
    relayOk: r.relayOk,
    lastPulseAt: r.lastPulseAt,
    openedAt: r.openedAt,
  }
}

function persist(r: Runtime): void {
  if (!r.principal) return
  const kept: Record<string, ChannelCursors> = {}
  for (const name of r.syncs.keys()) if (r.saved[name]) kept[name] = r.saved[name]!
  savedCursors.save(r.principal, kept)
}

function remember(r: Runtime, id: string): void {
  r.seen.add(id)
  r.seenOrder.push(id)
  while (r.seenOrder.length > SEEN_LIMIT) r.seen.delete(r.seenOrder.shift()!)
}

function accessChanged(r: Runtime, channel: string, event: LiveEnvelope): void {
  const given = event.sum.effect ?? (isRecord(event.d) ? event.d.effect : undefined)
  const effect: AccessEffect = given === 'ended' || given === 'reduced' ? given : 'changed'
  void Promise.resolve()
    .then(() => r.opts.onAccessChanged?.({ effect, event, channel }))
    .catch(() => undefined)
    .then(() => {
      // Reduced or changed: the channel list is the server's to recompute. Ended: the next request ends the session.
      if (effect !== 'ended' && rt === r) void loadConfig(r, true).catch(() => undefined)
    })
}

async function applyEvent(r: Runtime, channel: string, env: LiveEnvelope, via: 'socket' | 'catch-up'): Promise<void> {
  if (rt !== r) return
  if (isSequenced(env)) {
    const id = `${env.s}:${env.ep}:${env.q}`
    if (r.seen.has(id)) return
    remember(r, id)
  }
  if (env.t.startsWith('live.')) return // the relay's own control messages are the client's, not a handler's
  if (env.t === ACCESS_CHANGED) accessChanged(r, channel, env)
  await dispatchLiveEvent(env, { channel, via })
  // The proof harness reads this off a development build's device log (§11.4). A seq and a type only.
  if (r.trace && env.q !== null) console.log(`GSLIVE applied q=${env.q} t=${env.t} at=${Date.now()}`)
}

/** The mounted screens refetch — once for however many channels asked, and not while an answer is still being applied. */
function flushResync(r: Runtime): void {
  if (!r.resyncWanted || r.applying > 0 || r.resyncQueued) return
  r.resyncQueued = true
  void Promise.resolve().then(() => {
    r.resyncQueued = false
    if (!r.resyncWanted || r.applying > 0) return
    r.resyncWanted = false
    if (rt === r) resyncMounted()
  })
}

function resync(r: Runtime, channel: string, head: Heads, reason: ResyncReason): void {
  // Several channels reset by one answer still refetch the screens once (CatchUpDeps.applying).
  r.resyncWanted = true
  flushResync(r)
  // The durable stores fed by this channel look again — without holding up the channel's next events.
  if (reason !== 'no-cursor') void revalidateDurableStores({ reason: 'reset', channel, head })
}

function hooksFor(r: Runtime): ChannelHooks {
  return {
    apply: (channel, env, via) => applyEvent(r, channel, env, via),
    resync: async (channel, head, reason) => resync(r, channel, head, reason),
    saved: (channel, cursors) => {
      r.saved[channel] = cursors
      persist(r)
    },
    later: (channel) => r.scheduler.requestLater(channel),
    changed: () => liveStatus.recompute(),
  }
}

function applyConfig(r: Runtime, next: LiveConfig): void {
  if (next.principal !== r.principal) {
    // Somebody else's place applies to nothing of theirs.
    for (const name of r.syncs.keys()) r.socket?.unsubscribe(name)
    r.syncs.clear()
    r.principal = next.principal
    r.saved = next.principal ? savedCursors.load(next.principal) : {}
  }
  if (r.socket && fingerprint(next.settings) !== r.socketKey) {
    // The server moved the socket: this one is finished, and the lifecycle reconnects to the new one.
    r.socket.destroy()
    r.socket = null
    for (const s of r.syncs.values()) s.unsubscribed()
    r.openedAt = null
    r.lifecycle.closed()
  }
  const wanted = new Set(next.channels.map((c) => c.name))
  for (const name of [...r.syncs.keys()]) {
    if (wanted.has(name)) continue
    r.socket?.unsubscribe(name)
    r.syncs.delete(name)
  }
  const hooks = hooksFor(r)
  for (const spec of next.channels) {
    const existing = r.syncs.get(spec.name)
    if (existing && sameStreams(existing.spec, spec)) continue
    if (existing) r.socket?.unsubscribe(spec.name)
    r.syncs.set(spec.name, new ChannelSync(spec, durableCursor(spec.name) ?? r.saved[spec.name] ?? {}, firstHeads, hooks))
    r.socket?.subscribe(spec.name)
  }
  r.config = next
  r.configAt = Date.now()
  persist(r)
  liveStatus.recompute()
}

function loadConfig(r: Runtime, force: boolean): Promise<LiveConfig | null> {
  if (!force && r.config && Date.now() - r.configAt < CONFIG_MAX_AGE_MS) return Promise.resolve(r.config)
  if (r.configLoad) {
    // Asked for afresh while one is on its way: that answer may be from before the reason, so ask again after it.
    if (force) r.configAgain = true
    return r.configLoad
  }
  const load = r.http
    .config()
    .then((raw) => {
      if (rt !== r) return null
      const config = readConfig(raw)
      if (!config) throw new LiveHttpError('invalid', 'live_config_unreadable')
      applyConfig(r, config)
      return config
    })
    .finally(() => {
      r.configLoad = null
      if (r.configAgain && rt === r) {
        r.configAgain = false
        void loadConfig(r, true).catch(() => undefined)
      }
    })
  r.configLoad = load
  return load
}

function socketEvents(r: Runtime): SocketEvents {
  return {
    opened() {
      if (rt !== r) return
      markConnected()
      r.openedAt = Date.now()
      r.lifecycle.opened()
    },
    closed() {
      if (rt !== r) return
      for (const s of r.syncs.values()) s.unsubscribed()
      r.openedAt = null
      r.lifecycle.closed()
    },
    subscribed(name) {
      const sync = rt === r ? r.syncs.get(name) : undefined
      if (!sync) return
      sync.subscribed()
      r.scheduler.request([name])
    },
    refused(name) {
      if (rt === r) r.syncs.get(name)?.refused()
    },
    authFailed(name) {
      if (rt !== r || r.authRetries.has(name)) return
      r.authRetries.set(
        name,
        setTimeout(() => {
          r.authRetries.delete(name)
          if (rt === r && r.syncs.get(name)?.state === 'idle' && r.lifecycle.socket === 'open') r.socket?.subscribe(name)
        }, AUTH_RETRY_MS),
      )
    },
    message(name, data) {
      const sync = rt === r ? r.syncs.get(name) : undefined
      if (!sync) return
      // Through the app's allowlist BEFORE anything reads it (§4.1 rule 4).
      const env = parseEnvelope(allowSocketMessage(allowlistOf(), name, data))
      if (env) void sync.receive(env)
    },
    pulse() {
      if (rt !== r) return
      r.lastPulseAt = Date.now()
      liveStatus.recompute()
    },
  }
}

async function authorize(r: Runtime, socketId: string, names: string[]): Promise<Map<string, ChannelGrant | 'refused'>> {
  return readGrants(await r.http.auth({ socket_id: socketId, channel_names: names.map(toWire) }))
}

function ensureSocket(r: Runtime, config: LiveConfig): LiveSocket {
  if (r.socket) return r.socket
  const factory = r.opts.socket ?? defaultSocketFactory
  const socket = factory(config.settings, (socketId, names) => authorize(r, socketId, names), socketEvents(r))
  r.socket = socket
  r.socketKey = fingerprint(config.settings)
  for (const name of r.syncs.keys()) socket.subscribe(name)
  return socket
}

async function openSocket(r: Runtime): Promise<void> {
  try {
    const config = await loadConfig(r, false)
    if (rt !== r || r.lifecycle.socket !== 'connecting') return // stopped, or closed, while live.config was on its way
    if (!config) throw new LiveHttpError('invalid', 'live_config_unreadable')
    ensureSocket(r, config).connect()
  } catch {
    if (rt === r && r.lifecycle.socket === 'connecting') r.lifecycle.closed()
  }
}

function closeSocket(r: Runtime): void {
  r.socket?.disconnect()
  for (const s of r.syncs.values()) s.unsubscribed()
  r.openedAt = null
}

function catchUpAll(r: Runtime): void {
  r.scheduler.request(
    [...r.syncs.values()].filter((s) => s.state !== 'refused').map((s) => s.name),
    0,
  )
}

function driverFor(r: Runtime): LifecycleDriver {
  return {
    open: () => void openSocket(r),
    close: () => closeSocket(r),
    catchUpAll: (reason) => {
      if (reason === 'resume' && Date.now() - r.configAt > CONFIG_MAX_AGE_MS) void loadConfig(r, true).catch(() => undefined)
      catchUpAll(r)
    },
    poll: () => catchUpAll(r),
    changed: () => {
      // On start (the launch with a session, and every sign-in), once the phone is in the foreground and online.
      if (r.pendingStart && r.lifecycle.wanted) {
        r.pendingStart = false
        void revalidateDurableStores({ reason: 'start' })
      }
      liveStatus.recompute()
    },
  }
}

const defaultTrace = (): boolean => typeof __DEV__ !== 'undefined' && __DEV__ && !(typeof process !== 'undefined' && process.env?.JEST_WORKER_ID)

/** Go live for this session whenever the app is in the foreground and online. Idempotent; nothing in fidelity mode. */
export function startLive(): void {
  if (rt || isFidelity) return
  const opts = liveOptions()
  if (!opts) {
    if (typeof __DEV__ !== 'undefined' && __DEV__) console.error('[live] startLive() before configureLive(): nothing started')
    return
  }
  bindLiveStore(opts.store)
  const r = {
    opts,
    http: opts.http ?? baseQueryHttp(opts),
    syncs: new Map(),
    config: null,
    configAt: 0,
    configLoad: null,
    configAgain: false,
    socket: null,
    socketKey: '',
    principal: null,
    saved: {},
    catchUpFailing: false,
    relayOk: null,
    lastPulseAt: null,
    openedAt: null,
    seen: new Set(),
    seenOrder: [],
    pendingStart: true,
    resyncQueued: false,
    applying: 0,
    resyncWanted: false,
    authRetries: new Map(),
    off: [],
    trace: opts.trace ?? defaultTrace(),
  } as unknown as Runtime
  r.scheduler = new CatchUpScheduler({
    post: (body) => r.http.catchUp(body),
    allowlist: allowlistOf,
    channel: (name) => r.syncs.get(name),
    outcome: ({ ok, relay, names }) => {
      if (rt !== r) return
      r.catchUpFailing = !ok
      if (relay) r.relayOk = relay.ok
      const everyChannel = [...r.syncs.values()].every((s) => s.state === 'refused' || names.includes(s.name))
      if (ok && everyChannel) liveStatus.markCurrent()
      else liveStatus.recompute()
    },
    retryIn: (attempt) => backoffMs(attempt, opts.app),
    applying: (on) => {
      r.applying = Math.max(0, r.applying + (on ? 1 : -1))
      flushResync(r)
    },
  })
  r.lifecycle = new Lifecycle(
    { app: opts.app, fidelity: isFidelity, sessionLive: () => sessionLive(opts.store), online: () => onlineIn(opts.store) },
    opts.environment ?? reactNativeEnvironment,
    driverFor(r),
  )
  rt = r
  liveStatus.attach(() => statusFacts(r))
  r.off.push(opts.store.subscribe(() => r.lifecycle.factsChanged()))
  r.off.push(onSafetyScreenChange(() => r.lifecycle.cadenceChanged()))
  r.off.push(dataLight.subscribe(() => r.lifecycle.cadenceChanged()))
  r.lifecycle.start()
}

/** The session ended: close the socket, stop every timer, and forget everything held in memory. */
export function stopLive(): void {
  const r = rt
  if (!r) return
  rt = null
  for (const off of r.off) off()
  for (const t of r.authRetries.values()) clearTimeout(t)
  r.lifecycle.stop()
  r.scheduler.stop()
  r.socket?.destroy()
  r.socket = null
  for (const s of r.syncs.values()) s.unsubscribed()
  r.syncs.clear()
  liveStatus.reset()
  forgetHeads()
  forgetOrigins()
}

/** The banner's Refresh: catch up now, and reconnect now if the socket is down. */
export function refreshLive(): void {
  rt?.lifecycle.refresh()
}

/** A push arrived while the app is open: catch every channel up now (§8.6). */
export function nudgeLive(): void {
  if (rt && rt.lifecycle.wanted) catchUpAll(rt)
}

/** Fetch live.config again and subscribe to the difference (a claim decided, access changed — §7.3). */
export function refreshLiveConfig(): Promise<void> {
  const r = rt
  if (!r) return Promise.resolve()
  return loadConfig(r, true).then(
    () => undefined,
    () => undefined,
  )
}

/** Whether live is running for a session (for LiveRuntime and tests). */
export const isLiveStarted = (): boolean => rt !== null

/** The channels live holds and where each is, for the proof harness and tests. Names and states only. */
export function liveChannels(): Array<{ name: string; state: ChannelSync['state']; cursors: Partial<Record<Stream, number>> }> {
  if (!rt) return []
  return [...rt.syncs.values()].map((s) => ({
    name: s.name,
    state: s.state,
    cursors: Object.fromEntries(STREAMS.filter((st) => s.cursors[st]).map((st) => [st, s.cursors[st]!.q])) as Partial<Record<Stream, number>>,
  }))
}
