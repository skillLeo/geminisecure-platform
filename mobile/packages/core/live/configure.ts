/**
 * HOW AN APP PLUGS INTO LIVE (DESIGN §8, §12.1) — its store, its allowlist, its session paths and its handlers.
 *
 *   configureLive({ store, app, allowlist, onAccessChanged, headers, handlers })   once, at start-up
 *   onLiveEvent('pass.cancelled', handler)                                        a handler per event type
 *
 * THE ALLOWLIST COMES FIRST. Every socket message is wrapped as a catch-up answer of one and passed through the
 * app's allowlist (`(a) => enforceAllowlist('live.catch_up', a)`) before any handler sees it, exactly as every
 * catch-up answer is (§4.1 rule 4, §8.4). A key the catalogue does not list never reaches a handler.
 *
 * WHAT A HANDLER GETS. The envelope (§2.2) and where it came from. It is called ONCE per event however many of
 * this principal's channels carried it (deduplicated by stream, epoch and seq), in order within a channel,
 * and the channel's cursor moves only after it has finished: a handler that must save something durably
 * (the gate's revoked list) awaits the save; one that refetches a record does not await the network.
 *
 * THE PHONE'S OWN WRITES. An event whose origin nonce this install sent is its own echo (origin.ts). Handlers
 * do not get it unless they ask (`includeOwn`), because the outbox's confirmation is the only path for the
 * phone's own acts (§8.7). `access.changed` always reaches `onAccessChanged`, own or not.
 *
 * LIVE NEVER RAISES A BANNER. Push and the guard's digest stay the notification path (§8.4); nothing in
 * packages/core/live imports packages/core/notify (boundaries.test).
 *
 * `headers` is the frozen contract's name (§12.1) for extra request headers on live's own calls — the headless
 * proof harness passes its token that way. `handlers` (the unit brief's name) registers handlers up front,
 * exactly as `onLiveEvent` does. `socket`, `http` and `environment` replace the transport, the HTTP calls and
 * AppState — for tests and for the headless harness; an app passes none of them.
 */

import type { ThunkDispatch, UnknownAction } from '@reduxjs/toolkit'
import { envelopeOnly, type LiveAllowlist, type LiveEnvelope } from './envelope'
import { isOwnOrigin } from './origin'

export type LiveApp = 'guard' | 'resident'

/** The parts of an app's store live uses. Both apps' stores have them (plus `network`, which live reads). */
export type LiveStore = {
  dispatch: ThunkDispatch<unknown, unknown, UnknownAction>
  getState: () => unknown
  subscribe: (listener: () => void) => () => void
}

export type AccessEffect = 'ended' | 'reduced' | 'changed'
export type AccessChange = { effect: AccessEffect; event: LiveEnvelope; channel: string }

export type LiveEventContext = {
  /** The channel it arrived on (the first one, when several carried it). */
  channel: string
  via: 'socket' | 'catch-up'
  /** This install's own write caused it. */
  own: boolean
}

export type LiveHandler = (event: LiveEnvelope, context: LiveEventContext) => void | Promise<void>
export type HandlerOptions = { includeOwn?: boolean }

/** The socket, the HTTP calls and AppState, replaceable for tests and the headless harness (see socket.ts, client.ts, lifecycle.ts). */
export type LiveTransportOverrides = {
  socket?: import('./socket').SocketFactory
  http?: import('./client').LiveHttp
  environment?: import('./lifecycle').LifecycleEnvironment
}

export type LiveOptions = {
  store: LiveStore
  app: LiveApp
  /** `(answer) => enforceAllowlist('live.catch_up', answer)`. Until given: the envelope's own keys, never `d`. */
  allowlist?: LiveAllowlist
  /** The app's session path for a permission change (§7.3). `reduced` and `changed` also refetch live.config. */
  onAccessChanged?: (change: AccessChange) => void | Promise<void>
  /** Extra headers on live's own requests (live/config, live/auth, live/catch-up). */
  headers?: () => Record<string, string>
  /** Handlers by event type, as `onLiveEvent` registers them. */
  handlers?: Record<string, LiveHandler | LiveHandler[]>
  /** `GSLIVE applied q=… t=… at=…` on the device log (§11.4). Default: development builds, never under Jest. */
  trace?: boolean
} & LiveTransportOverrides

let options: LiveOptions | null = null
const configured = new Set<() => void>()

type Entry = { handler: LiveHandler; includeOwn: boolean }
const registry = new Map<string, Set<Entry>>()
/** The entries configureLive registered, so configuring again replaces them rather than adding twice. */
let fromOptions: Array<() => void> = []

const TYPE_PATTERN = /^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$|^[a-z][a-z0-9_]*\.\*$/

/**
 * Handlers for `type`: an exact type (`pass.cancelled`) or every verb of one entity (`alert.*`).
 * Returns the unsubscribe.
 */
export function onLiveEvent(type: string, handler: LiveHandler, opts: HandlerOptions = {}): () => void {
  if (!TYPE_PATTERN.test(type)) throw new Error(`onLiveEvent: "${type}" is not an event type ({entity}.{verb}, or {entity}.*)`)
  let set = registry.get(type)
  if (!set) registry.set(type, (set = new Set()))
  const entry: Entry = { handler, includeOwn: opts.includeOwn === true }
  set.add(entry)
  return () => {
    set!.delete(entry)
    if (set!.size === 0) registry.delete(type)
  }
}

export function configureLive(next: LiveOptions): void {
  for (const off of fromOptions) off()
  fromOptions = []
  options = next
  for (const [type, given] of Object.entries(next.handlers ?? {})) {
    for (const h of Array.isArray(given) ? given : [given]) fromOptions.push(onLiveEvent(type, h))
  }
  configured.forEach((fn) => fn())
}

export const liveOptions = (): LiveOptions | null => options

/** Run `fn` whenever the app configures live (the client rebinds its store). */
export function onConfigured(fn: () => void): () => void {
  configured.add(fn)
  return () => configured.delete(fn)
}

export const allowlistOf = (): LiveAllowlist => options?.allowlist ?? envelopeOnly

/** Every type a handler is registered for — the handler-type contract test reads this (§8.4). */
export const registeredTypes = (): string[] => [...registry.keys()].sort()

const handlersFor = (type: string): Entry[] => {
  const entity = type.slice(0, type.indexOf('.'))
  return [...(registry.get(type) ?? []), ...(registry.get(`${entity}.*`) ?? [])]
}

/** Hands one event to its handlers, in registration order. A handler that throws does not stop the others. */
export async function dispatchLiveEvent(event: LiveEnvelope, context: Omit<LiveEventContext, 'own'>): Promise<void> {
  const own = isOwnOrigin(event.o)
  for (const entry of handlersFor(event.t)) {
    if (own && !entry.includeOwn) continue
    try {
      await entry.handler(event, { ...context, own })
    } catch {
      // Reported by type alone — not the event, not the error's message: a type is not personal, a payload can be.
      if (typeof __DEV__ !== 'undefined' && __DEV__) console.error(`[live] a handler for ${event.t} failed`)
    }
  }
}

/** For tests: no handlers, no options. */
export function resetLiveConfiguration(): void {
  registry.clear()
  fromOptions = []
  options = null
}
