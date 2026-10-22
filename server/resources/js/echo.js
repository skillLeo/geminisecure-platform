import Echo from 'laravel-echo'
import { sharedPusher } from './live/socket'

/**
 * Echo, on the one socket.
 *
 * Reverb speaks the Pusher protocol, which is why pusher-js is the transport
 * here despite there being no Pusher account involved. The socket itself — its
 * host, port, scheme, timeouts (a silent death noticed within forty seconds) and
 * channel authorisation — is made in live/socket.js, once per page load, and
 * shared: the live client and Echo use the same instance (`client`), so the panic
 * composables that still listen through Echo (`estate.{t}.alerts`) keep working,
 * on the same connection, until W1 moves them onto the live channels (M11 Part C,
 * design §6.1 and §6.6).
 *
 * THE SOCKET OPENS WHEN THIS MODULE IS IMPORTED — `new Echo()` asks for the shared
 * Pusher client, which connects as soon as the document is ready — so WHERE it is
 * imported decides who holds a socket. Only the three composables that listen
 * import it, and they do it lazily: `useEstatePanic` (the estate console's shell,
 * every estate page), `useConsolePanic` (the Gemini console's shell, when the
 * reader has estates to watch) and `useAlertStream` (the dispatch screens). app.js
 * imported it statically until M11 A3, which put laravel-echo and pusher-js in the
 * one bundle every page downloaded and opened a socket on the sign-in screen,
 * where there is nothing to hear. It is its own chunk now, requested the moment
 * one of those composables runs, as its screen mounts, and app.js imports nothing
 * of the live client either.
 *
 * Nothing importing it at all is how this socket once never opened, and the alert
 * queue ran on its 3-second poll alone without anybody noticing. The three imports
 * are pinned by tests/Feature/ConsoleBundleSplitTest.php for that reason.
 */
export const echo = new Echo({
    broadcaster: 'reverb',
    client: sharedPusher(),
})

/*
 * Exposed on window because a WebSocket is not something you can assert from
 * PHP: proving it works means opening a browser, watching the connection reach
 * 'connected', subscribing to the private channel and seeing a real broadcast
 * land. tests/Fidelity/echo-check.mjs does exactly that, and needs a handle to do
 * it. It is set here rather than in app.js since M11 A3, so it exists from the
 * moment a screen that listens has loaded the socket — and not on screens that
 * never do.
 */
window.Echo = echo

/**
 * Whether the socket is currently usable.
 *
 * Exposed so a life-safety surface can tell the difference between "quiet
 * because nothing happened" and "quiet because the connection dropped" — the
 * second must never look like the first.
 */
export function isConnected() {
    return echo.connector?.pusher?.connection?.state === 'connected'
}

/**
 * Subscribe to connection state changes.
 *
 * @param {(state: string) => void} handler
 * @returns {() => void} unsubscribe
 */
export function onConnectionState(handler) {
    const connection = echo.connector?.pusher?.connection

    if (!connection) {
        return () => {}
    }

    const listener = ({ current }) => handler(current)

    connection.bind('state_change', listener)
    handler(connection.state)

    return () => connection.unbind('state_change', listener)
}
