/**
 * A stand-in for `resources/js/echo.js`, shaped like laravel-echo's private channels:
 * one channel object per name until it is left, `subscribed` / `listen` / `error` to
 * register on it. `socket` is the test's side of it — the connection state, a
 * subscription succeeding or being refused, and an event arriving.
 */

const channels = new Map()
const stateHandlers = new Set()

export const socket = {
    state: 'connecting',

    /** Every channel name that was left, in order. */
    left: [],

    /** Every channel name that was subscribed to, in order. */
    joined: [],

    setState(state) {
        this.state = state

        for (const handler of [...stateHandlers]) {
            handler(state)
        }
    },

    channel(name) {
        return channels.get(name) ?? null
    },

    /** The channel's subscription succeeded. */
    subscribe(name) {
        const channel = channels.get(name)

        if (!channel) {
            throw new Error(`Nothing asked for ${name}.`)
        }

        channel.subscribedNow = true
        channel.onSubscribed.forEach((callback) => callback())
    },

    /** The channel's subscription was refused (a 403 from /broadcasting/auth). */
    refuse(name) {
        channels.get(name)?.onError.forEach((callback) => callback({ status: 403 }))
    },

    /** An event arrives on a channel. `event` is as the script listens for it, e.g. `.alert.raised`. */
    emit(name, event, payload) {
        const channel = channels.get(name)

        if (!channel) {
            return false
        }

        for (const callback of channel.listeners.get(event) ?? []) {
            callback(payload)
        }

        return (channel.listeners.get(event)?.size ?? 0) > 0
    },

    reset() {
        channels.clear()
        stateHandlers.clear()
        this.left = []
        this.joined = []
        this.state = 'connecting'
    },
}

export const echo = {
    private(name) {
        if (!channels.has(name)) {
            const channel = {
                name,
                subscribedNow: false,
                onSubscribed: new Set(),
                onError: new Set(),
                listeners: new Map(),
                subscribed(callback) {
                    this.onSubscribed.add(callback)

                    return this
                },
                error(callback) {
                    this.onError.add(callback)

                    return this
                },
                listen(event, callback) {
                    if (!this.listeners.has(event)) {
                        this.listeners.set(event, new Set())
                    }

                    this.listeners.get(event).add(callback)

                    return this
                },
            }

            channels.set(name, channel)
            socket.joined.push(name)
        }

        return channels.get(name)
    },

    leave(name) {
        socket.left.push(name)
        channels.delete(name)
    },
}

export function isConnected() {
    return socket.state === 'connected'
}

export function onConnectionState(handler) {
    stateHandlers.add(handler)
    handler(socket.state)

    return () => stateHandlers.delete(handler)
}
