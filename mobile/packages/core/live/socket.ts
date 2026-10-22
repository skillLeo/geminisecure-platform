/**
 * THE SOCKET (DESIGN §8.1, §8.2, §3.4): pusher-js against Reverb, and nothing else talks to the transport.
 *
 *   host    `new URL(apiUrl()).hostname` — derived from the ONE configured address, exactly as `webOrigin()`
 *           is (lib/legal.ts), so moving the server moves the socket too. There is no socket URL in this
 *           repository (gate:api-url), and no second EXPO_PUBLIC_ value for it (§13.2)
 *   port, scheme, key, path    from `live.config` (on the laptop `:8080`, a server setting)
 *
 * pusher-js is loaded LAZILY, when the first socket is made: its React Native build starts NetInfo the moment
 * it is imported, and nothing should run for an app that never goes live (the fidelity harness, a signed-out
 * phone). It is pure JS and part of no native module list (gate:expo-go).
 *
 * THE LIBRARY DOES NOT RECONNECT BY ITSELF. Any close this adapter did not ask for is reported once, the
 * library is told to stop, and lifecycle.ts decides when to try again (its backoff, its jitter, its 0.5–3 s
 * herd spread) — two retry loops would fight.
 *
 * AUTHORISATION IS BATCHED. pusher-js asks once per channel; every ask made within 20 ms goes in ONE
 * `POST live/auth` for up to 200 channels (§3.4), so a reconnect with twenty channels is one request, and
 * it counts once for the server's throttle. A channel the server refuses is reported as refused, and is not
 * asked for again until the next connect.
 *
 * iOS ORIGIN (§8.2, risk R3). Android sends the socket's own origin, which the server allows. iOS is NOT
 * VERIFIED: the fallback below makes pusher-js's WebSocket send `Origin: <webOrigin()>`, scoped to pusher-js
 * alone. It is OFF until it has been tried on an iOS simulator or device — this repository is built on
 * Windows, where neither exists. Until then iOS falls back, honestly, to catch-up polling if Reverb refuses.
 */

import { Platform } from 'react-native'
import { apiUrl } from '../lib/env'
import { webOrigin } from '../lib/legal'
import { fromWire, toWire } from './envelope'

export type SocketSettings = {
  key: string
  host: string
  port: number
  tls: boolean
  /** A path in front of `/app/{key}`, when Reverb sits behind one. Usually empty. */
  path: string
  pulseChannel: string
}

export type ChannelGrant = { auth: string; channel_data?: string }
/** Signs `names` for `socketId`: a grant or 'refused' per name. Rejects when the request itself failed. */
export type Authorize = (socketId: string, names: string[]) => Promise<Map<string, ChannelGrant | 'refused'>>

export type SocketEvents = {
  opened(): void
  /** Any close this adapter did not ask for — a drop, or an attempt that failed. */
  closed(): void
  subscribed(name: string): void
  refused(name: string): void
  /** The auth request failed (not a refusal): the channel can be asked for again. */
  authFailed(name: string): void
  message(name: string, data: unknown): void
  pulse(data: unknown): void
}

export interface LiveSocket {
  connect(): void
  disconnect(): void
  subscribe(name: string): void
  unsubscribe(name: string): void
  /** Disconnects and lets go of everything; the socket is not used again. */
  destroy(): void
}

export type SocketFactory = (settings: SocketSettings, authorize: Authorize, events: SocketEvents) => LiveSocket

/** The socket host: the API's own host name, never a value of its own. */
export const socketHost = (): string => new URL(apiUrl()).hostname

/** Off until tried on iOS (see the header). Turning it on is this one line. */
export const IOS_ORIGIN_FALLBACK = false

export const AUTH_BATCH_MS = 20
export const MAX_CHANNELS_PER_AUTH = 200
/** pusher-js's own ping timing, as the web uses it (W:resources/js/echo.js:27, :37). */
export const ACTIVITY_TIMEOUT_MS = 30_000
export const PONG_TIMEOUT_MS = 10_000

/* ── the parts of pusher-js this adapter uses ──────────────────────────────────────────────────── */

type PusherCallback = (error: Error | null, data: ChannelGrant | null) => void
type PusherChannelLike = {
  bind(event: string, fn: (data: unknown) => void): unknown
  bind_global?(fn: (event: string, data: unknown) => void): unknown
  unbind_all?(): unknown
}
type PusherLike = {
  connect(): void
  disconnect(): void
  subscribe(name: string): PusherChannelLike
  unsubscribe(name: string): void
  connection: { state: string; socket_id?: string; bind(event: string, fn: (data: unknown) => void): unknown }
}
export type PusherConstructor = {
  new (key: string, options: Record<string, unknown>): PusherLike
  Runtime?: { getWebSocketAPI?: () => unknown }
}

/* ── the auth batcher ──────────────────────────────────────────────────────────────────────────── */

type Ask = { socketId: string; name: string; callback: PusherCallback }

class AuthBatcher {
  private asks: Ask[] = []
  private timer: ReturnType<typeof setTimeout> | null = null
  readonly outcome = new Map<string, 'refused' | 'failed'>()

  constructor(private readonly authorize: Authorize) {}

  ask(ask: Ask): void {
    this.asks.push(ask)
    this.timer ??= setTimeout(() => {
      this.timer = null
      void this.flush()
    }, AUTH_BATCH_MS)
  }

  private async flush(): Promise<void> {
    const asks = this.asks
    this.asks = []
    const bySocket = new Map<string, Ask[]>()
    for (const a of asks) bySocket.set(a.socketId, [...(bySocket.get(a.socketId) ?? []), a])
    for (const [socketId, group] of bySocket) {
      for (let i = 0; i < group.length; i += MAX_CHANNELS_PER_AUTH) void this.send(socketId, group.slice(i, i + MAX_CHANNELS_PER_AUTH))
    }
  }

  private async send(socketId: string, group: Ask[]): Promise<void> {
    let grants: Map<string, ChannelGrant | 'refused'>
    try {
      grants = await this.authorize(socketId, [...new Set(group.map((a) => a.name))])
    } catch {
      for (const a of group) {
        this.outcome.set(a.name, 'failed')
        a.callback(new Error('live auth failed'), null)
      }
      return
    }
    for (const a of group) {
      const grant = grants.get(a.name)
      if (grant && grant !== 'refused') {
        this.outcome.delete(a.name)
        a.callback(null, grant)
      } else {
        this.outcome.set(a.name, 'refused')
        a.callback(new Error('live auth refused'), null)
      }
    }
  }

  stop(): void {
    if (this.timer) clearTimeout(this.timer)
    this.timer = null
    this.asks = []
  }
}

/* ── the adapter ───────────────────────────────────────────────────────────────────────────────── */

/** The WebSocket pusher-js makes on iOS when the fallback is on: the same socket, sending the web origin. */
function withOrigin(Base: unknown): unknown {
  const WS = Base as new (url: string, protocols?: string | string[] | null, options?: { headers: Record<string, string> } | null) => object
  return class OriginWebSocket extends WS {
    constructor(url: string) {
      super(url, null, { headers: { origin: webOrigin() } })
    }
  }
}

export function pusherSocket(Pusher: PusherConstructor, opts: { iosOrigin?: boolean } = {}): SocketFactory {
  return (settings, authorize, events) => {
    if (opts.iosOrigin && Platform.OS === 'ios' && Pusher.Runtime) {
      const original = typeof WebSocket === 'undefined' ? null : WebSocket
      if (original) Pusher.Runtime.getWebSocketAPI = () => withOrigin(original)
    }

    const batcher = new AuthBatcher(authorize)
    const wanted = new Set<string>()
    /*
     * The channel objects this adapter bound. pusher-js keeps a channel whose subscription is still pending in its
     * map when it is unsubscribed, and hands the SAME object back to the next subscribe — so without unbinding it
     * first, a channel dropped and re-added at once (its streams changed) would say everything twice.
     */
    const bound = new Map<string, PusherChannelLike>()
    let client: PusherLike | null = null
    let asked = false // this adapter asked for the current disconnect
    let open = false
    let reported = false
    let destroyed = false

    const customHandler = (params: { socketId: string; channelName: string }, callback: PusherCallback) => {
      const name = fromWire(params.channelName)
      if (!name) return callback(new Error('not a live channel'), null)
      batcher.ask({ socketId: params.socketId, name, callback })
    }

    const bindChannel = (name: string) => {
      const channel = client!.subscribe(toWire(name))
      bound.set(name, channel)
      channel.bind('pusher:subscription_succeeded', () => events.subscribed(name))
      channel.bind('pusher:subscription_error', () => (batcher.outcome.get(name) === 'failed' ? events.authFailed(name) : events.refused(name)))
      channel.bind('live', (data) => events.message(name, data))
    }

    const reportClosed = () => {
      if (reported || asked || destroyed) return
      reported = true
      open = false
      asked = true
      client?.disconnect()
      events.closed()
    }

    const make = (): PusherLike => {
      const made = new Pusher(settings.key, {
        cluster: '',
        wsHost: settings.host,
        wsPort: settings.port,
        wssPort: settings.port,
        wsPath: settings.path,
        forceTLS: settings.tls,
        enabledTransports: ['ws', 'wss'],
        activityTimeout: ACTIVITY_TIMEOUT_MS,
        pongTimeout: PONG_TIMEOUT_MS,
        enableStats: false,
        channelAuthorization: { customHandler },
      })
      made.connection.bind('state_change', (change) => {
        const { current } = (change ?? {}) as { previous?: string; current?: string }
        if (current === 'connected') {
          open = true
          reported = false
          events.opened()
        } else if (current === 'connecting' && open) {
          reportClosed() // the socket dropped: pusher-js is about to retry by itself; this controller retries instead
        } else if (current === 'unavailable' || current === 'failed' || current === 'disconnected') {
          reportClosed()
        }
      })
      const pulse = made.subscribe(settings.pulseChannel)
      pulse.bind_global?.((event, data) => {
        if (!event.startsWith('pusher:')) events.pulse(data)
      })
      return made
    }

    return {
      connect() {
        if (destroyed) return
        asked = false
        reported = false
        if (!client) {
          // Created on the first connect: the React Native build connects as soon as it is constructed.
          client = make()
          for (const name of wanted) bindChannel(name)
        } else {
          client.connect()
        }
      },
      disconnect() {
        asked = true
        open = false
        client?.disconnect()
      },
      subscribe(name) {
        const again = wanted.has(name)
        wanted.add(name)
        if (!client) return // bound when the first connect makes the client
        if (again) client.subscribe(toWire(name)) // already bound: pusher-js asks for its authorisation again
        else bindChannel(name)
      },
      unsubscribe(name) {
        wanted.delete(name)
        batcher.outcome.delete(name)
        bound.get(name)?.unbind_all?.()
        bound.delete(name)
        client?.unsubscribe(toWire(name))
      },
      destroy() {
        destroyed = true
        asked = true
        batcher.stop()
        for (const channel of bound.values()) channel.unbind_all?.()
        bound.clear()
        client?.disconnect()
        client = null
        wanted.clear()
      },
    }
  }
}

/**
 * The constructor out of whatever shape a pusher-js build exports.
 *
 * THE REACT NATIVE BUILD EXPORTS IT BY NAME, `{ Pusher }` (pusher-js 8.x, `dist/react-native/pusher.js`), where the
 * Node build is the class itself and a bundler's ESM interop gives `{ default }`. Reading only `default` handed the
 * module OBJECT to `new`, which threw "is not a constructor" inside `openSocket`'s catch — so on every phone the
 * socket was never opened, and live updates came only from the fallback poll (found on production, 26 Sep, M13).
 * A shape with no constructor in it throws here, by name, rather than failing as a mystery further on.
 */
export function pusherConstructorOf(mod: unknown): PusherConstructor {
  if (typeof mod === 'function') return mod as PusherConstructor
  if (mod && typeof mod === 'object') {
    const named = (mod as { Pusher?: unknown }).Pusher
    if (typeof named === 'function') return named as PusherConstructor
    const fallback = (mod as { default?: unknown }).default
    if (typeof fallback === 'function') return fallback as PusherConstructor
    if (fallback && typeof fallback === 'object' && typeof (fallback as { Pusher?: unknown }).Pusher === 'function') return (fallback as { Pusher: PusherConstructor }).Pusher
  }
  throw new Error('pusher-js exported no Pusher constructor')
}

/** pusher-js's React Native build, loaded on first use. */
function reactNativePusher(): PusherConstructor {
  // eslint-disable-next-line @typescript-eslint/no-require-imports
  return pusherConstructorOf(require('pusher-js/react-native'))
}

export const defaultSocketFactory: SocketFactory = (settings, authorize, events) => pusherSocket(reactNativePusher(), { iosOrigin: IOS_ORIGIN_FALLBACK })(settings, authorize, events)
