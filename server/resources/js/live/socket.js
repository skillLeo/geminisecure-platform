/**
 * THE ONE SOCKET (M11 Part C, design §6.1).
 *
 * One `Pusher` instance per page load, pointed at Reverb, made the first time something
 * asks for it and never before. The live store asks for it when a signed-in shell calls
 * `startLive(page.props.live)`; `echo.js` asks for the same instance and hands it to Echo
 * as `client`, so the panic composables that still use Echo keep working on this socket
 * until W1 moves them. The sign-in page asks for neither, so it opens no socket.
 *
 * The values are the ones `echo.js` carried (the Reverb host, port and scheme from the
 * build, the 30 s activity timeout and the 10 s pong timeout that decide how fast a dead
 * socket is noticed), plus the one thing Echo used to supply: `cluster: ''`, which Reverb
 * needs and pusher-js insists on. Authorisation goes through `authorizeChannel`, which
 * batches the live channels and sends the legacy ones where they always went.
 *
 * The instance is never replaced. `stopLive()` disconnects it and `startLive()` connects it
 * again, so Echo, which holds a reference to it, never ends up holding a dead one.
 */
import Pusher from 'pusher-js'
import { authorizeChannel } from './authorize.js'

const env = import.meta.env ?? {}

/** The options the socket is made with. */
export function pusherOptions() {
    return {
        cluster: '',
        wsHost: env.VITE_REVERB_HOST,
        wsPort: env.VITE_REVERB_PORT ?? 8080,
        wssPort: env.VITE_REVERB_PORT ?? 8080,
        forceTLS: (env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],

        /*
         * HOW FAST A DEAD SOCKET IS NOTICED. After `activityTimeout` of silence the client
         * pings; with no pong inside `pongTimeout` it declares the connection lost. On a
         * return from sleep or from offline the store does not wait for this: it forces a
         * reconnect and catches up at once (§5.4).
         */
        activityTimeout: 30000,
        pongTimeout: 10000,

        channelAuthorization: { customHandler: authorizeChannel },
    }
}

let instance = null
const created = new Set()

/** The shared socket, made now if it does not exist yet. */
export function sharedPusher() {
    if (instance === null) {
        instance = new Pusher(env.VITE_REVERB_APP_KEY, pusherOptions())

        for (const callback of [...created]) {
            created.delete(callback)
            callback(instance)
        }
    }

    return instance
}

/** The shared socket if it has been made, or null. Never makes it. */
export function currentPusher() {
    return instance
}

/** Run `callback` once the socket exists: now if it already does. */
export function onceSocketCreated(callback) {
    if (instance !== null) {
        callback(instance)

        return () => {}
    }

    created.add(callback)

    return () => created.delete(callback)
}
