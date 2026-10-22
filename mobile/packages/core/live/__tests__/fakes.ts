/**
 * Test support for packages/core/live — NOT a test file (jest runs only *.test.ts). A real Redux store with the
 * core reducers, and fakes for the three things live talks to: the socket, the HTTP calls and AppState.
 * Everything the client decides is real.
 */

import { configureStore, type UnknownAction } from '@reduxjs/toolkit'
import { coreMiddleware, coreReducers } from '../../api/store'
import { sessionRestored, signedOut } from '../../auth/sessionSlice'
import { startLive, stopLive, type LiveHttp } from '../client'
import { BATCH_MS, type CatchUpRequest } from '../catchUp'
import { configureLive, resetLiveConfiguration, type LiveApp, type LiveOptions } from '../configure'
import { forgetHeads, savedCursors } from '../cursors'
import { forgetDurableStores } from '../durable'
import { forgetSafetyScreens } from '../fallback'
import type { LifecycleEnvironment } from '../lifecycle'
import { forgetOrigins } from '../origin'
import type { Authorize, LiveSocket, SocketEvents, SocketFactory, SocketSettings } from '../socket'

// The socket's host is the API's own (socket.ts): readConfig needs the one address to derive it from.
process.env.EXPO_PUBLIC_API_URL ??= 'http://192.168.18.119:8000/api/v1/'

/* ── the store ─────────────────────────────────────────────────────────────────────────────────── */

const ONLINE = 'test/online'
export const setOnline = (online: boolean): UnknownAction => ({ type: ONLINE, payload: online })

/** The apps' `network` slice, as live reads it (the real one lives in packages/core/offline). */
const network = (state: { online: boolean } = { online: true }, action: UnknownAction) =>
  action.type === ONLINE ? { online: (action as unknown as { payload: boolean }).payload } : state

export function makeStore(status: 'active' | 'pending' | 'signedOut' = 'active') {
  const store = configureStore({
    reducer: { ...coreReducers, network },
    middleware: (getDefault) => getDefault({ serializableCheck: false, immutableCheck: false }).concat(coreMiddleware),
  })
  if (status !== 'signedOut') store.dispatch(sessionRestored({ principal: { id: 5, name: 'Test', siteName: null, reference: null }, pending: status === 'pending' }))
  return store
}
export type TestStore = ReturnType<typeof makeStore>
export const signOut = (store: TestStore) => store.dispatch(signedOut())

/* ── envelopes ─────────────────────────────────────────────────────────────────────────────────── */

export const EP = '01J9ZC3K'

export function env(q: number | null, p: number | null, over: Record<string, unknown> = {}): Record<string, unknown> {
  return { v: 1, s: 'e', ep: EP, q, p, t: 'pass.cancelled', e: 'pass', i: String(q ?? 0), m: 'ref', sum: {}, o: null, at: '2026-10-02T14:03:11.221Z', sim: false, ...over }
}

/* ── the socket ────────────────────────────────────────────────────────────────────────────────── */

export type FakeSocket = LiveSocket & {
  settings: SocketSettings
  authorize: Authorize
  events: SocketEvents
  connects: number
  disconnects: number
  subscribed: Set<string>
  log: string[]
}

export function fakeSocketFactory() {
  const made: FakeSocket[] = []
  const factory: SocketFactory = (settings, authorize, events) => {
    const socket: FakeSocket = {
      settings,
      authorize,
      events,
      connects: 0,
      disconnects: 0,
      subscribed: new Set(),
      log: [],
      connect() {
        socket.connects += 1
        socket.log.push('connect')
      },
      disconnect() {
        socket.disconnects += 1
        socket.log.push('disconnect')
      },
      subscribe(name) {
        socket.subscribed.add(name)
        socket.log.push(`subscribe ${name}`)
      },
      unsubscribe(name) {
        socket.subscribed.delete(name)
        socket.log.push(`unsubscribe ${name}`)
      },
      destroy() {
        socket.log.push('destroy')
      },
    }
    made.push(socket)
    return socket
  }
  return {
    factory,
    made,
    get last(): FakeSocket {
      const s = made[made.length - 1]
      if (!s) throw new Error('no socket was made')
      return s
    },
  }
}

/* ── HTTP ──────────────────────────────────────────────────────────────────────────────────────── */

export type CatchUpAnswerChannel = { name: string; status: 'ok' | 'refused' | 'reset'; events?: unknown[]; head?: Record<string, number>; ep?: Record<string, string>; more?: boolean }

export function liveConfig(channels: Array<{ name: string; streams?: string[] }>, over: Record<string, unknown> = {}) {
  return { ws: { key: 'app-key', port: 8080, scheme: 'ws', path: '' }, channels: channels.map((c) => ({ streams: ['e'], ...c })), pulse: 'live-pulse', build: 'test', principal: 'g-5', ...over }
}

export function fakeHttp(config: unknown) {
  const catchUps: CatchUpRequest[] = []
  const auths: Array<{ socket_id: string; channel_names: string[] }> = []
  let configs = 0
  /** Answers the next catch-up; by default every channel ok with no events at head e=… of its cursor, in epoch EP. */
  let answer: (body: CatchUpRequest) => unknown | Promise<unknown> = (body) => ({
    channels: body.channels.map((c) => ({ name: c.name, status: 'ok', events: [], head: { e: typeof c.e === 'number' ? c.e : 0 }, ep: { e: EP } })),
    relay: { ok: true, lag_ms: 10 },
  })
  let currentConfig: unknown = config
  const http: LiveHttp = {
    config: async () => {
      configs += 1
      if (currentConfig instanceof Error) throw currentConfig
      return currentConfig
    },
    auth: async (body) => {
      auths.push(body)
      return { channels: body.channel_names.map((name) => ({ name, auth: 'app-key:signature', channel_data: '{"user_id":"g-5"}' })) }
    },
    catchUp: async (body) => {
      catchUps.push(JSON.parse(JSON.stringify(body)) as CatchUpRequest)
      return answer(body)
    },
  }
  return {
    http,
    catchUps,
    auths,
    get configs() {
      return configs
    },
    answerWith(fn: (body: CatchUpRequest) => unknown | Promise<unknown>) {
      answer = fn
    },
    setConfig(next: unknown) {
      currentConfig = next
    },
  }
}

/* ── AppState ──────────────────────────────────────────────────────────────────────────────────── */

export function fakeEnvironment(random = 0.5) {
  let state = 'active'
  const listeners = new Set<(s: string) => void>()
  const environment: LifecycleEnvironment = {
    appState: () => state,
    onAppStateChange(fn) {
      listeners.add(fn)
      return () => listeners.delete(fn)
    },
    random: () => random,
  }
  return {
    environment,
    set(next: string) {
      state = next
      listeners.forEach((fn) => fn(next))
    },
    setRandom(r: number) {
      random = r
    },
  }
}

/* ── timing ────────────────────────────────────────────────────────────────────────────────────── */

/** Lets every pending promise chain settle (fake timers leave microtasks alone). */
export async function settle(rounds = 60): Promise<void> {
  for (let i = 0; i < rounds; i++) await Promise.resolve()
}

/** Advances the fake clock and settles what it woke. */
export async function advance(ms: number): Promise<void> {
  await jest.advanceTimersByTimeAsync(ms)
  await settle()
}

export type Harness = {
  store: TestStore
  sockets: ReturnType<typeof fakeSocketFactory>
  http: ReturnType<typeof fakeHttp>
  env: ReturnType<typeof fakeEnvironment>
  app: LiveApp
}

export const PASSES = 'estate.pp.passes'

/**
 * The real client against the fakes: configured, started, and settled until live.config has been read and the
 * socket asked to connect. Nothing is opened or subscribed yet — `open()` does that, as the socket would.
 */
export async function startHarness(
  over: { app?: LiveApp; status?: 'active' | 'pending' | 'signedOut'; config?: unknown; options?: Partial<LiveOptions>; online?: boolean } = {},
): Promise<Harness> {
  const store = makeStore(over.status ?? 'active')
  if (over.online === false) store.dispatch(setOnline(false))
  const sockets = fakeSocketFactory()
  const http = fakeHttp(over.config ?? liveConfig([{ name: PASSES }]))
  const env = fakeEnvironment()
  const app = over.app ?? 'guard'
  configureLive({ store, app, socket: sockets.factory, http: http.http, environment: env.environment, trace: false, ...over.options })
  startLive()
  await settle()
  return { store, sockets, http, env, app }
}

/** The socket opened and confirmed every channel it was asked for; the catch-up that starts is answered. */
export async function open(h: Harness, names?: string[]): Promise<void> {
  const socket = h.sockets.last
  socket.events.opened()
  for (const name of names ?? [...socket.subscribed]) socket.events.subscribed(name)
  await advance(BATCH_MS)
}

/** A message on `channel`, as pusher-js hands it over: the data as a JSON string. */
export function deliver(h: Harness, channel: string, message: Record<string, unknown>): void {
  h.sockets.last.events.message(channel, JSON.stringify(message))
}

/** Everything live keeps in module state, forgotten between tests. */
export function resetLive(): void {
  stopLive()
  resetLiveConfiguration()
  forgetDurableStores()
  forgetHeads()
  forgetOrigins()
  forgetSafetyScreens()
  savedCursors.clear()
}
