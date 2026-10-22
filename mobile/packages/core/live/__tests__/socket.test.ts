/**
 * THE SOCKET ADAPTER (DESIGN §8.1, §8.2, §3.4) against a stand-in for pusher-js that behaves as its source does where
 * it matters: a channel whose subscription is pending stays in its map when unsubscribed and is handed back by the
 * next subscribe, and every private channel's authorisation goes through `channelAuthorization.customHandler`.
 */

import { AUTH_BATCH_MS, MAX_CHANNELS_PER_AUTH, pusherSocket, type ChannelGrant, type PusherConstructor, type SocketEvents } from '../socket'

type Handler = (data: unknown) => void

class FakeChannel {
  handlers = new Map<string, Handler[]>()
  globals: Array<(event: string, data: unknown) => void> = []
  pending = true
  cancelled = false
  constructor(readonly name: string) {}
  bind(event: string, fn: Handler) {
    this.handlers.set(event, [...(this.handlers.get(event) ?? []), fn])
    return this
  }
  bind_global(fn: (event: string, data: unknown) => void) {
    this.globals.push(fn)
    return this
  }
  unbind_all() {
    this.handlers.clear()
    this.globals = []
    return this
  }
  emit(event: string, data?: unknown) {
    for (const fn of this.handlers.get(event) ?? []) fn(data)
    for (const fn of this.globals) fn(event, data)
  }
}

class FakePusher {
  static made: FakePusher[] = []
  channels = new Map<string, FakeChannel>()
  stateHandlers: Handler[] = []
  disconnects = 0
  connects = 0
  connection = {
    state: 'connecting',
    socket_id: '1234.5678',
    bind: (event: string, fn: Handler) => {
      if (event === 'state_change') this.stateHandlers.push(fn)
    },
  }
  constructor(
    readonly key: string,
    readonly options: Record<string, unknown>,
  ) {
    FakePusher.made.push(this)
  }
  connect() {
    this.connects += 1
  }
  disconnect() {
    this.disconnects += 1
    this.state('disconnected')
  }
  subscribe(name: string) {
    let channel = this.channels.get(name)
    if (!channel) this.channels.set(name, (channel = new FakeChannel(name)))
    channel.cancelled = false
    return channel
  }
  unsubscribe(name: string) {
    const channel = this.channels.get(name)
    if (channel?.pending) channel.cancelled = true // pusher-js keeps it, and hands it back to the next subscribe
    else this.channels.delete(name)
  }
  state(current: string) {
    const previous = this.connection.state
    this.connection.state = current
    for (const fn of this.stateHandlers) fn({ previous, current })
  }
  /** pusher-js asking for a private channel's authorisation. */
  authorize(wire: string): Promise<{ error: Error | null; grant: ChannelGrant | null }> {
    const handler = (this.options.channelAuthorization as { customHandler: (p: unknown, cb: (e: Error | null, d: ChannelGrant | null) => void) => void }).customHandler
    return new Promise((resolve) => handler({ socketId: this.connection.socket_id, channelName: wire }, (error, grant) => resolve({ error, grant })))
  }
}

const SETTINGS = { key: 'app-key', host: '192.168.18.119', port: 8080, tls: false, path: '', pulseChannel: 'live-pulse' }

function events() {
  const log: string[] = []
  const e: SocketEvents = {
    opened: () => void log.push('opened'),
    closed: () => void log.push('closed'),
    subscribed: (n) => void log.push(`subscribed ${n}`),
    refused: (n) => void log.push(`refused ${n}`),
    authFailed: (n) => void log.push(`authFailed ${n}`),
    message: (n, d) => void log.push(`message ${n} ${String(d)}`),
    pulse: (d) => void log.push(`pulse ${JSON.stringify(d)}`),
  }
  return { log, e }
}

const make = (authorize: Parameters<ReturnType<typeof pusherSocket>>[1] = async (_id, names) => new Map(names.map((n) => [n, { auth: 'k:sig' }]))) => {
  const ev = events()
  const socket = pusherSocket(FakePusher as unknown as PusherConstructor)(SETTINGS, authorize, ev.e)
  return { socket, ...ev, pusher: () => FakePusher.made.at(-1)! }
}

beforeEach(() => {
  jest.useFakeTimers()
  FakePusher.made = []
})

afterEach(() => {
  jest.useRealTimers()
})

describe('the client it makes', () => {
  it('is made at the first connect, not before: nothing runs for an app that never goes live', () => {
    const { socket } = make()
    socket.subscribe('estate.pp.passes')
    expect(FakePusher.made).toHaveLength(0)
    socket.connect()
    expect(FakePusher.made).toHaveLength(1)
  })

  it('points at the API’s own host, with live.config’s port and key, and pusher-js’s ping timing', () => {
    const { socket, pusher } = make()
    socket.connect()
    expect(pusher().key).toBe('app-key')
    expect(pusher().options).toMatchObject({ wsHost: '192.168.18.119', wsPort: 8080, wssPort: 8080, forceTLS: false, enabledTransports: ['ws', 'wss'], activityTimeout: 30_000, pongTimeout: 10_000, enableStats: false })
  })

  it('subscribes the pulse, and every channel it was asked for, under the transport prefix', () => {
    const { socket, pusher } = make()
    socket.subscribe('estate.pp.passes')
    socket.connect()
    expect([...pusher().channels.keys()]).toEqual(['live-pulse', 'private-live.estate.pp.passes'])
  })
})

describe('authorisation is batched (§3.4)', () => {
  it('every ask within 20 ms goes in ONE live.auth, by bare name; each channel gets its own grant', async () => {
    const asked: string[][] = []
    const { socket, pusher } = make(async (id, names) => {
      asked.push([id, ...names])
      return new Map(names.map((n) => [n, { auth: `k:${n}` }]))
    })
    socket.connect()
    const a = pusher().authorize('private-live.estate.pp.passes')
    await jest.advanceTimersByTimeAsync(AUTH_BATCH_MS - 5)
    const b = pusher().authorize('private-live.estate.pp.alerts')
    await jest.advanceTimersByTimeAsync(AUTH_BATCH_MS)
    expect(asked).toEqual([['1234.5678', 'estate.pp.passes', 'estate.pp.alerts']])
    expect((await a).grant).toEqual({ auth: 'k:estate.pp.passes' })
    expect((await b).grant).toEqual({ auth: 'k:estate.pp.alerts' })
  })

  it('at most 200 channels a request', async () => {
    const sizes: number[] = []
    const { socket, pusher } = make(async (_id, names) => {
      sizes.push(names.length)
      return new Map(names.map((n) => [n, { auth: 'k:s' }]))
    })
    socket.connect()
    for (let i = 0; i < MAX_CHANNELS_PER_AUTH + 3; i++) void pusher().authorize(`private-live.estate.pp.c${i}`)
    await jest.advanceTimersByTimeAsync(AUTH_BATCH_MS)
    expect(sizes).toEqual([MAX_CHANNELS_PER_AUTH, 3])
  })

  it('a channel that is not a live channel is never sent to live.auth', async () => {
    const asked: string[] = []
    const { socket, pusher } = make(async (_id, names) => (asked.push(...names), new Map()))
    socket.connect()
    const r = await pusher().authorize('private-encrypted-live.user.7')
    await jest.advanceTimersByTimeAsync(AUTH_BATCH_MS)
    expect(r.error).toBeInstanceOf(Error)
    expect(asked).toEqual([])
  })

  it('a refusal is reported as refused; a failed request as authFailed, so it can be asked again', async () => {
    let fail = false
    const { socket, pusher, log } = make(async (_id, names) => {
      if (fail) throw new Error('503')
      return new Map(names.map((n) => [n, 'refused' as const]))
    })
    socket.subscribe('estate.pp.passes')
    socket.connect()
    const channel = pusher().channels.get('private-live.estate.pp.passes')!
    void pusher().authorize('private-live.estate.pp.passes')
    await jest.advanceTimersByTimeAsync(AUTH_BATCH_MS)
    channel.emit('pusher:subscription_error')
    fail = true
    void pusher().authorize('private-live.estate.pp.passes')
    await jest.advanceTimersByTimeAsync(AUTH_BATCH_MS)
    channel.emit('pusher:subscription_error')
    expect(log).toEqual(['refused estate.pp.passes', 'authFailed estate.pp.passes'])
  })
})

describe('the library does not reconnect by itself (§8.3)', () => {
  it('a drop is reported once, and pusher-js is told to stop its own retry', () => {
    const { socket, pusher, log } = make()
    socket.connect()
    pusher().state('connected')
    pusher().state('connecting') // pusher-js starting its own reconnect
    expect(log).toEqual(['opened', 'closed'])
    expect(pusher().disconnects).toBe(1)
  })

  it('a disconnect it asked for is not reported', () => {
    const { socket, pusher, log } = make()
    socket.connect()
    pusher().state('connected')
    socket.disconnect()
    expect(log).toEqual(['opened'])
  })

  it('an attempt that fails is reported, and a later connect reuses the client', () => {
    const { socket, pusher, log } = make()
    socket.connect()
    pusher().state('unavailable')
    expect(log).toEqual(['closed'])
    socket.connect()
    expect(FakePusher.made).toHaveLength(1)
    expect(pusher().connects).toBe(1)
  })
})

describe('channels', () => {
  it('a message on a channel is handed over once, as the name live.config gave', () => {
    const { socket, pusher, log } = make()
    socket.subscribe('estate.pp.passes')
    socket.connect()
    pusher().channels.get('private-live.estate.pp.passes')!.emit('live', '{"q":1}')
    expect(log).toEqual(['message estate.pp.passes {"q":1}'])
  })

  it('dropped and re-added while its subscription is pending (its streams changed), it still says everything ONCE', () => {
    const { socket, pusher, log } = make()
    socket.subscribe('estate.pp.passes')
    socket.connect()
    socket.unsubscribe('estate.pp.passes') // pending: pusher-js keeps the channel object
    socket.subscribe('estate.pp.passes') // …and hands the same one back
    const channel = pusher().channels.get('private-live.estate.pp.passes')!
    channel.emit('pusher:subscription_succeeded')
    channel.emit('live', 'x')
    expect(log).toEqual(['subscribed estate.pp.passes', 'message estate.pp.passes x'])
  })

  it('nothing arrives from a channel after it was dropped', () => {
    const { socket, pusher, log } = make()
    socket.subscribe('estate.pp.passes')
    socket.connect()
    const channel = pusher().channels.get('private-live.estate.pp.passes')!
    socket.unsubscribe('estate.pp.passes')
    channel.emit('live', 'late')
    expect(log).toEqual([])
  })

  it('the pulse is the relay’s heartbeat; pusher’s own events on it are not', () => {
    const { socket, pusher, log } = make()
    socket.connect()
    const pulse = pusher().channels.get('live-pulse')!
    pulse.emit('pusher:subscription_succeeded')
    pulse.emit('pulse', { at: 1 })
    expect(log).toEqual(['pulse {"at":1}'])
  })

  it('destroyed, it lets go of everything and says nothing more', () => {
    const { socket, pusher, log } = make()
    socket.subscribe('estate.pp.passes')
    socket.connect()
    const channel = pusher().channels.get('private-live.estate.pp.passes')!
    socket.destroy()
    channel.emit('live', 'late')
    socket.connect()
    expect(log).toEqual([])
    expect(FakePusher.made).toHaveLength(1)
  })
})
