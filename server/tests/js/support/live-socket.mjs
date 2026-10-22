/**
 * THE LIVE CLIENT'S WIRE, STOOD IN FOR: the socket, and the HTTP doors behind it.
 *
 * A stand-in for `resources/js/live/socket.js`: the same four exports, with a fake Pusher
 * in place of the real one and nothing else changed.
 *
 * The options are the REAL module's (`pusherOptions()` is imported from it — register.mjs
 * lets this file, and only this file, reach the real socket.js), so the authoriser the fake
 * calls is the real batched one and a test that counts POSTs to /live/auth counts what a
 * browser would send. What is fake is only the wire: the fake connects when a test says so,
 * asks the authoriser for every private channel on subscribe exactly as pusher-js does, and
 * lets a test deliver a message, a pulse, or a drop.
 *
 * `liveSocket` is the test's side of it.
 *
 * `liveServer` is the other half: a `fetch` that answers /live/auth, /live/catch-up,
 * /live/tiles and /broadcasting/auth as the server's contract says (design §3.4, §4.4, §5.1),
 * keeps a log per stream so a catch-up answers with exactly what was published after a
 * cursor, and records every request so a test can count them.
 */
import { pusherOptions as realOptions } from '../../../resources/js/live/socket.js'

class Channel {
    constructor(name) {
        this.name = name
        this.subscribed = false
        this.pending = false
        this.bindings = new Map()
        this.globals = new Set()
    }

    bind(event, callback) {
        if (!this.bindings.has(event)) {
            this.bindings.set(event, new Set())
        }

        this.bindings.get(event).add(callback)

        return this
    }

    unbind(event, callback) {
        if (event === undefined) {
            this.bindings.clear()
        } else if (callback === undefined) {
            this.bindings.delete(event)
        } else {
            this.bindings.get(event)?.delete(callback)
        }

        return this
    }

    bind_global(callback) {
        this.globals.add(callback)

        return this
    }

    unbind_global(callback) {
        if (callback === undefined) {
            this.globals.clear()
        } else {
            this.globals.delete(callback)
        }

        return this
    }

    emit(event, data) {
        for (const callback of [...(this.bindings.get(event) ?? [])]) {
            callback(data)
        }

        for (const callback of [...this.globals]) {
            callback(event, data)
        }
    }
}

class Connection {
    constructor() {
        this.state = 'initialized'
        this.socket_id = null
        this.listeners = new Map()
    }

    bind(event, callback) {
        if (!this.listeners.has(event)) {
            this.listeners.set(event, new Set())
        }

        this.listeners.get(event).add(callback)
    }

    unbind(event, callback) {
        this.listeners.get(event)?.delete(callback)
    }

    setState(next) {
        const previous = this.state

        if (previous === next) {
            return
        }

        this.state = next

        for (const callback of [...(this.listeners.get('state_change') ?? [])]) {
            callback({ previous, current: next })
        }

        for (const callback of [...(this.listeners.get(next) ?? [])]) {
            callback()
        }
    }
}

class FakePusher {
    constructor(key, options) {
        this.key = key
        this.options = options
        this.connection = new Connection()
        this.channels = new Map()
        liveSocket.instances.push(this)

        // As pusher-js does: a new instance starts connecting at once.
        this.connection.setState('connecting')
    }

    channel(name) {
        return this.channels.get(name) ?? null
    }

    allChannels() {
        return [...this.channels.values()]
    }

    subscribe(name) {
        let channel = this.channels.get(name)

        if (!channel) {
            channel = new Channel(name)
            this.channels.set(name, channel)
        }

        if (this.connection.state === 'connected' && !channel.subscribed && !channel.pending) {
            this.send(channel)
        }

        return channel
    }

    unsubscribe(name) {
        if (this.channels.delete(name)) {
            liveSocket.unsubscribed.push(name)
        }
    }

    connect() {
        if (this.connection.state !== 'connected') {
            this.connection.setState('connecting')
        }
    }

    disconnect() {
        this.dropChannels()
        this.connection.setState('disconnected')
    }

    dropChannels() {
        for (const channel of this.channels.values()) {
            channel.subscribed = false
            channel.pending = false
        }
    }

    /** pusher-js's subscribe: authorise a private channel, then tell the server. */
    send(channel) {
        const succeed = () => {
            liveSocket.sent.push(channel.name)

            if (liveSocket.autoSucceed) {
                channel.subscribed = true
                channel.emit('pusher:subscription_succeeded', {})
            }
        }

        if (!channel.name.startsWith('private-')) {
            succeed()

            return
        }

        channel.pending = true

        this.options.channelAuthorization.customHandler(
            { socketId: this.connection.socket_id, channelName: channel.name },
            (error, data) => {
                channel.pending = false

                if (error) {
                    channel.emit('pusher:subscription_error', { type: 'AuthError', error: error.message })

                    return
                }

                channel.auth = data
                succeed()
            },
        )
    }
}

export const liveSocket = {
    /** Every Pusher made since the last reset. The sign-in page must leave this empty. */
    instances: [],

    /** Every channel whose subscribe reached the server (after its authorisation), in order. */
    sent: [],

    /** Every channel unsubscribed, in order. */
    unsubscribed: [],

    /** Whether the server acknowledges a subscribe at once. A test turns this off to hold one. */
    autoSucceed: true,

    current() {
        return this.instances[this.instances.length - 1] ?? null
    },

    /** The connection reaches `connected`, and every channel asked for is subscribed. */
    open(socketId = '1234.5678') {
        const pusher = this.current()
        pusher.connection.socket_id = socketId
        pusher.connection.setState('connected')

        for (const channel of pusher.channels.values()) {
            if (!channel.subscribed && !channel.pending) {
                pusher.send(channel)
            }
        }
    },

    /** The network drops: pusher-js goes back to connecting and every channel is unsubscribed. */
    drop() {
        const pusher = this.current()
        pusher.dropChannels()
        pusher.connection.setState('connecting')
    },

    /** The server acknowledges a held subscribe. `name` is the full wire name. */
    succeed(name) {
        const channel = this.current()?.channel(name)
        channel.subscribed = true
        channel.emit('pusher:subscription_succeeded', {})
    },

    /** A `live` message arrives on a live channel (`name` without its prefix). */
    deliver(name, envelope) {
        const channel = this.current()?.channel(`private-live.${name}`)

        if (!channel) {
            return false
        }

        channel.emit('live', envelope)

        return true
    },

    /** The relay's pulse on the public channel. */
    pulse(data = {}) {
        this.current()?.channel('live-pulse')?.emit('pulse', { at: new Date().toISOString(), ...data })
    },

    /** Whether a channel is subscribed right now, by its full wire name. */
    isSubscribed(name) {
        return this.current()?.channel(name)?.subscribed === true
    },

    reset() {
        this.instances = []
        this.sent = []
        this.unsubscribed = []
        this.autoSucceed = true
        instance = null
        created.clear()
    },
}

let instance = null
const created = new Set()

export const pusherOptions = realOptions

export function sharedPusher() {
    if (instance === null) {
        instance = new FakePusher('test-key', pusherOptions())

        for (const callback of [...created]) {
            created.delete(callback)
            callback(instance)
        }
    }

    return instance
}

export function currentPusher() {
    return instance
}

export function onceSocketCreated(callback) {
    if (instance !== null) {
        callback(instance)

        return () => {}
    }

    created.add(callback)

    return () => created.delete(callback)
}

/* ---- the HTTP doors ------------------------------------------------------------------ */

const json = (status, body) => ({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
})

const bare = (name) => (name.startsWith('private-live.') ? name.slice('private-live.'.length) : name)

/** The streams a channel carries, as the registry would say: platform channels are central only. */
const streamsOf = (name) => (/^(user|gemini)\./.test(name) ? ['c'] : ['c', 'e'])

export const liveServer = {
    /** Every request, in order: {url, method, headers, body}. */
    requests: [],

    /** Every event published, per stream, as the log holds it. */
    log: [],

    heads: { c: 0, e: 0 },
    epochs: { c: 'EPOCH001', e: 'EPOCH002' },

    /** Names /live/auth and /live/catch-up refuse. */
    refused: new Set(),

    /** Names /live/catch-up answers `reset` for. */
    resets: new Set(),

    /** A status every /live/auth answers with instead of granting (e.g. 419). */
    authStatus: null,

    /** A status every /live/catch-up answers with (e.g. 500, 419). */
    catchUpStatus: null,

    /**
     * While set, catch-up answers wait until `releaseCatchUps()` (or `releaseCatchUp()`, the
     * oldest one). A held request the client aborts is dropped, as a browser drops it.
     */
    holdCatchUps: false,
    held: [],

    /** Catch-up answers take this long (on the test's clock). */
    catchUpDelay: 0,

    /** While set, /live/auth never answers (until the client gives up on it). */
    holdAuth: false,

    /** While set, /broadcasting/auth waits until `releaseLegacy()`. */
    holdLegacy: false,
    heldLegacy: [],

    relay: { ok: true, lag_ms: 40 },
    build: 'build-1',

    /** Other routes: path → (url) => [status, body]. */
    routes: new Map(),

    /**
     * The relay sequencing an event: the next seq in its stream, and `p` the last seq on
     * this channel in this stream. Returns the envelope, to deliver on the socket or not.
     */
    publish(channel, fields = {}) {
        const s = fields.s ?? streamsOf(channel)[streamsOf(channel).length - 1]
        const q = fields.q ?? ++this.heads[s]
        this.heads[s] = Math.max(this.heads[s], q)
        const previous = this.log.filter((entry) => entry.channel === channel && entry.env.s === s).pop()
        const env = {
            v: 1,
            s,
            ep: (this.epochs[s] ?? '').slice(0, 8),
            q,
            p: previous ? previous.env.q : null,
            t: 'ticket.updated',
            e: 'ticket',
            i: '1',
            m: 'ref',
            sum: {},
            o: null,
            at: new Date().toISOString(),
            sim: false,
            ...fields,
        }
        env.s = s
        env.q = q

        if (!('p' in fields)) {
            env.p = previous ? previous.env.q : null
        }

        this.log.push({ channel, env })

        return env
    },

    count(path) {
        return this.requests.filter((request) => request.url.split('?')[0] === path).length
    },

    last(path) {
        return this.requests.filter((request) => request.url.split('?')[0] === path).pop() ?? null
    },

    releaseCatchUps() {
        const held = this.held
        this.held = []
        held.forEach((entry) => entry.resolve())
    },

    /** Answer the oldest held catch-up only. */
    releaseCatchUp() {
        this.held.shift()?.resolve()
    },

    releaseLegacy() {
        const held = this.heldLegacy
        this.heldLegacy = []
        held.forEach((entry) => entry.resolve())
    },

    /**
     * Wait until released (`queue` given) or for `ms` on the test's clock, or until the
     * request's signal aborts, which rejects as a browser's fetch does.
     */
    wait(request, { queue = null, ms = 0 } = {}) {
        return new Promise((resolve, reject) => {
            const entry = { resolve, request }
            let timer = null

            if (queue) {
                this[queue].push(entry)
            } else if (ms > 0) {
                timer = setTimeout(resolve, ms)
            } else if (!request.signal) {
                return
            }

            request.signal?.addEventListener('abort', () => {
                clearTimeout(timer)

                if (queue) {
                    this[queue] = this[queue].filter((each) => each !== entry)
                }

                const error = new Error('The operation was aborted.')
                error.name = 'AbortError'
                reject(error)
            }, { once: true })
        })
    },

    /*
     * As f-web-auth's LiveAuthController reads it: the batch is `channel_names`, and a body
     * without that key is the single form, which needs `channel_name` — so a batch sent under
     * any other key is a 422, as the real door answers it. The answer is keyed by the wire
     * name exactly as sent, and `refused` lists wire names.
     */
    answerAuth(body) {
        if (this.authStatus !== null) {
            return json(this.authStatus, { message: 'no' })
        }

        if (typeof body?.socket_id !== 'string' || !/^\d{1,15}\.\d{1,15}$/.test(body.socket_id)) {
            return json(422, { message: 'The socket id field format is invalid.' })
        }

        if (!Array.isArray(body.channel_names)) {
            if (typeof body.channel_name !== 'string') {
                return json(422, { message: 'The channel name field is required.' })
            }

            return this.refused.has(bare(body.channel_name))
                ? json(403, { error: { code: 'channel_refused' } })
                : json(200, { auth: `test-key:${bare(body.channel_name)}`, channel_data: JSON.stringify({ user_id: 'u-7' }) })
        }

        if (body.channel_names.length === 0 || body.channel_names.length > 200) {
            return json(422, { message: 'The channel names field must have between 1 and 200 items.' })
        }

        const channels = {}
        const refused = []

        for (const name of body.channel_names) {
            if (this.refused.has(bare(name))) {
                refused.push(name)
            } else {
                channels[name] = {
                    auth: `test-key:${bare(name)}`,
                    channel_data: JSON.stringify({ user_id: 'u-7' }),
                    streams: streamsOf(bare(name)),
                }
            }
        }

        return json(200, { channels, refused })
    },

    answerCatchUp(body) {
        if (this.catchUpStatus !== null) {
            return json(this.catchUpStatus, { message: 'no' })
        }

        const channels = []

        for (const asked of body.channels ?? []) {
            const name = asked.name

            if (this.refused.has(name)) {
                channels.push({ name, status: 'refused' })

                continue
            }

            const head = {}

            for (const s of streamsOf(name)) {
                head[s] = this.heads[s]
            }

            if (this.resets.has(name)) {
                channels.push({ name, status: 'reset', head })

                continue
            }

            const events = []

            for (const s of streamsOf(name)) {
                const cursor = asked[s]

                if (cursor === null || cursor === undefined) {
                    continue
                }

                for (const entry of this.log) {
                    if (entry.channel === name && entry.env.s === s && entry.env.q > cursor) {
                        events.push(entry.env)
                    }
                }
            }

            channels.push({ name, status: 'ok', events, head, more: false })
        }

        return json(200, { channels, relay: this.relay, build: this.build })
    },

    async fetch(url, init = {}) {
        const method = (init.method ?? 'GET').toUpperCase()
        let body = init.body

        if (typeof body === 'string' && (init.headers?.['Content-Type'] ?? '').includes('json')) {
            body = JSON.parse(body)
        }

        const request = { url: String(url), method, headers: { ...(init.headers ?? {}) }, body, signal: init.signal ?? null }
        this.requests.push(request)
        const path = request.url.split('?')[0]

        if (path === '/live/auth') {
            if (this.holdAuth) {
                await this.wait(request)
            }

            return this.answerAuth(body)
        }

        if (path === '/live/catch-up') {
            if (this.holdCatchUps) {
                await this.wait(request, { queue: 'held' })
            }

            if (this.catchUpDelay > 0) {
                await this.wait(request, { ms: this.catchUpDelay })
            }

            return this.answerCatchUp(body)
        }

        if (path === '/broadcasting/auth') {
            if (this.holdLegacy) {
                await this.wait(request, { queue: 'heldLegacy' })
            }

            return json(200, { auth: 'test-key:legacy' })
        }

        const route = this.routes.get(path)

        if (route) {
            const [status, answer] = await route(request.url, request)

            return json(status, answer)
        }

        return json(404, { message: 'no route' })
    },

    install() {
        globalThis.fetch = (url, init) => this.fetch(url, init)
    },

    reset() {
        this.requests = []
        this.log = []
        this.heads = { c: 0, e: 0 }
        this.epochs = { c: 'EPOCH001', e: 'EPOCH002' }
        this.refused = new Set()
        this.resets = new Set()
        this.authStatus = null
        this.catchUpStatus = null
        this.holdCatchUps = false
        this.held = []
        this.catchUpDelay = 0
        this.holdAuth = false
        this.holdLegacy = false
        this.heldLegacy = []
        this.relay = { ok: true, lag_ms: 40 }
        this.build = 'build-1'
        this.routes = new Map()
    },
}
