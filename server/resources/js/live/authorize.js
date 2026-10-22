/**
 * CHANNEL AUTHORISATION FOR THE ONE SOCKET (M11 Part C, design §3.4 and §6.1).
 *
 * pusher-js asks its authoriser once per private channel. This is that authoriser
 * (`channelAuthorization.customHandler`), and it sends each name to one of two doors:
 *
 *   private-live.*   collected for 20 ms and sent as ONE POST to /live/auth, so a page
 *                    that subscribes five channels in one tick costs one request and one
 *                    throttle hit (§3.4 "Batching"). At most 200 names go in one request.
 *   anything else    the legacy /broadcasting/auth, one channel per request, form-encoded
 *                    as pusher-js itself would send it, for the panic composables that
 *                    still listen on `estate.{t}.alerts` until W1 moves them (§3.8).
 *
 * CSRF. Both doors send X-XSRF-TOKEN, read from the XSRF-TOKEN cookie at the moment of
 * the request — the same cookie Inertia's own client reads. Nothing here reads the
 * <meta name="csrf-token"> tag: a token captured once from the page goes stale when the
 * session turns over, which is the 419 the maps suspected (§3.4).
 *
 * WHAT THE STORE LEARNS. The live door's answer says, per channel, whether it was granted
 * (and which streams it carries) or refused; a 401 or 419 means the session ended. The
 * store registers for those through `setAuthListener`, because pusher-js reports a failed
 * authorisation only as an error message, and "refused" (stop asking) and "failed" (ask
 * again later) must not look the same.
 *
 * Request:  POST /live/auth  {"socket_id":"1234.5678","channel_names":["private-live.user.7", …]}
 * Answer:   {"channels":{"private-live.user.7":{"auth":"…","channel_data":"…","streams":["c"]}},"refused":["…"]}
 * `channel_names` is the key f-web-auth's LiveAuthController reads (and the API door's
 * live.auth): without it the door takes the body for Pusher's single form and answers 422
 * for the missing `channel_name`. §3.4 froze only the answers; this is the request that
 * pairs with them. A name in the answer is read with or without its `private-live.` prefix.
 *
 * A 422 is the door saying it could not read the request at all — a bug on one side or the
 * other, never a passing failure — so it is reported, loudly, as well as asked again later.
 */

export const LIVE_PREFIX = 'private-live.'
export const LIVE_AUTH_URL = '/live/auth'
export const LEGACY_AUTH_URL = '/broadcasting/auth'

/** How long names are collected before the batch goes (§3.4). */
export const AUTH_BATCH_MS = 20

/** The most names one request carries; f-web-auth accepts a batch of 200 as one request. */
export const AUTH_BATCH_MAX = 200

/** How long a live request may take, the reading of its answer included, before it has failed. */
export const REQUEST_TIMEOUT_MS = 10_000

/**
 * Run `work(signal)` — a fetch and the reading of its answer — and stop waiting for it after
 * `timeoutMs`, or as soon as `signal` aborts. The promise then rejects with an error named
 * `TimeoutError` or `AbortError`, and the fetch is told to stop (the signal `work` was
 * handed aborts), so the browser drops the request too.
 *
 * Without it, a request on a connection that died without saying so — a network switch, a
 * laptop lid — is waited for as long as the browser waits, which can be minutes, and
 * everything queued behind it waits as well.
 */
export function withDeadline(work, { timeoutMs = REQUEST_TIMEOUT_MS, signal = null } = {}) {
    const stopWith = (name, message) => Object.assign(new Error(message), { name })

    if (signal?.aborted) {
        return Promise.reject(stopWith('AbortError', 'The live request was superseded.'))
    }

    const controller = typeof AbortController === 'function' ? new AbortController() : null
    let timer = null
    let onAbort = null

    const stopped = new Promise((_, reject) => {
        const stop = (error) => {
            controller?.abort()
            reject(error)
        }

        timer = setTimeout(() => stop(stopWith('TimeoutError', `The live request had no answer after ${timeoutMs} ms.`)), timeoutMs)

        if (signal) {
            onAbort = () => stop(stopWith('AbortError', 'The live request was superseded.'))
            signal.addEventListener('abort', onAbort, { once: true })
        }
    })

    const running = Promise.resolve().then(() => work(controller?.signal))

    return Promise.race([running, stopped]).finally(() => {
        clearTimeout(timer)

        if (onAbort) {
            signal.removeEventListener('abort', onAbort)
        }
    })
}

/** The XSRF-TOKEN cookie as it stands now, or null. */
export function xsrfToken() {
    const cookie = typeof document !== 'undefined' && typeof document.cookie === 'string' ? document.cookie : ''
    const match = cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

    if (!match) {
        return null
    }

    try {
        return decodeURIComponent(match[1])
    } catch {
        return match[1]
    }
}

/** The headers every live request sends. */
export function requestHeaders(extra = {}) {
    const token = xsrfToken()

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        ...extra,
    }
}

const quiet = () => {}

let listener = { granted: quiet, refused: quiet, failed: quiet, signedOut: quiet }

/**
 * Where the answers go. `granted(name, streams)`, `refused(name)`, `failed(name, status)`,
 * `signedOut()` — `name` without its prefix.
 */
export function setAuthListener(next) {
    listener = { granted: quiet, refused: quiet, failed: quiet, signedOut: quiet, ...next }
}

let queue = []
let timer = null

const bare = (fullName) => (fullName.startsWith(LIVE_PREFIX) ? fullName.slice(LIVE_PREFIX.length) : fullName)

class RefusedError extends Error {
    constructor(name, status) {
        super(`Live channel ${name} was refused.`)
        this.status = status
    }
}

const readJson = async (response) => {
    try {
        return await response.json()
    } catch {
        return null
    }
}

const inList = (list, fullName) => Array.isArray(list) && (list.includes(fullName) || list.includes(bare(fullName)))

/** The store hearing of an answer must never stop pusher-js hearing of it. */
function tell(fn, ...args) {
    try {
        fn(...args)
    } catch (error) {
        if (typeof console !== 'undefined') {
            console.error(error)
        }
    }
}

/**
 * Each queued authorisation is answered exactly once, and one channel's subscriber throwing
 * (pusher-js runs the subscription handlers inside this callback) does not leave the rest of
 * its batch unanswered.
 */
function answer(entry, error, data) {
    if (entry.answered) {
        return
    }

    entry.answered = true
    tell(entry.callback, error, data)
}

/** One batch: every queued name for one socket id, in requests of at most 200 names. */
async function sendBatch(socketId, entries) {
    const names = [...new Set(entries.map((entry) => entry.channelName))]

    for (let at = 0; at < names.length; at += AUTH_BATCH_MAX) {
        const chunk = names.slice(at, at + AUTH_BATCH_MAX)
        const waiting = entries.filter((entry) => chunk.includes(entry.channelName))

        let response = null
        let body = null

        try {
            // Given up on after 10 s, it is a failure like any other: the store asks again.
            ({ response, body } = await withDeadline(async (signal) => {
                const answered = await fetch(LIVE_AUTH_URL, {
                    method: 'POST',
                    headers: requestHeaders({ 'Content-Type': 'application/json' }),
                    credentials: 'same-origin',
                    cache: 'no-store',
                    body: JSON.stringify({ socket_id: socketId, channel_names: chunk }),
                    ...(signal ? { signal } : {}),
                })

                return { response: answered, body: answered.ok ? await readJson(answered) : null }
            }))
        } catch {
            response = null
            body = null
        }

        const status = response ? response.status : 0

        if (status === 401 || status === 419) {
            tell(listener.signedOut)
        }

        if (status === 422) {
            tell(() => {
                throw new Error(`Live channel authorisation: ${LIVE_AUTH_URL} could not read the request (422) for ${chunk.length} channel(s).`)
            })
        }

        const granted = body && body.channels && typeof body.channels === 'object' ? body.channels : null

        for (const entry of waiting) {
            const name = entry.channelName
            const grant = granted ? (granted[name] ?? granted[bare(name)] ?? null) : null

            if (grant && typeof grant.auth === 'string') {
                tell(listener.granted, bare(name), Array.isArray(grant.streams) ? grant.streams : [])
                answer(entry, null, {
                    auth: grant.auth,
                    ...(typeof grant.channel_data === 'string' ? { channel_data: grant.channel_data } : {}),
                })

                continue
            }

            /*
             * Refused: listed as refused, the whole batch answered 403, or — failing closed —
             * a 200 that does not mention the name at all. Anything else (a network error, a
             * 5xx, a 422, a throttle) is a failure the store asks again about later.
             */
            if (status === 403 || (body && (inList(body.refused, name) || granted !== null))) {
                tell(listener.refused, bare(name))
                answer(entry, new RefusedError(name, 403), null)

                continue
            }

            tell(listener.failed, bare(name), status)
            answer(entry, new RefusedError(name, status), null)
        }
    }
}

function flush() {
    timer = null
    const batch = queue
    queue = []

    const bySocket = new Map()

    for (const entry of batch) {
        if (!bySocket.has(entry.socketId)) {
            bySocket.set(entry.socketId, [])
        }

        bySocket.get(entry.socketId).push(entry)
    }

    for (const [socketId, entries] of bySocket) {
        sendBatch(socketId, entries).catch((error) => {
            // Whatever broke, no channel is left waiting: each one not yet answered is told it failed.
            for (const entry of entries) {
                answer(entry, new RefusedError(entry.channelName, 0), null)
            }

            tell(() => {
                throw error
            })
        })
    }
}

/**
 * The legacy door, exactly one channel per request, as pusher-js's own authoriser sends it.
 *
 * NO DEADLINE HERE, on purpose. This is the panic composables' `estate.{t}.alerts`, and
 * nothing asks for it again after a failure: pusher-js does not retry a subscription, and
 * the composables mark the feed down and poll (useEstatePanic.js `channel.error`, as in
 * useConsolePanic and useAlertStream). pusher-js's own authoriser, which this replaces, never
 * gave up on a slow answer either. A deadline would turn a slow success into a failure that
 * lasts until the next reconnect, and the panic path must never be less reliable than it was.
 */
async function authorizeLegacy({ socketId, channelName }, callback) {
    let response = null

    try {
        response = await fetch(LEGACY_AUTH_URL, {
            method: 'POST',
            headers: requestHeaders({ 'Content-Type': 'application/x-www-form-urlencoded' }),
            credentials: 'same-origin',
            cache: 'no-store',
            body: `socket_id=${encodeURIComponent(socketId)}&channel_name=${encodeURIComponent(channelName)}`,
        })
    } catch {
        callback(new RefusedError(channelName, 0), null)

        return
    }

    if (response.status === 401 || response.status === 419) {
        tell(listener.signedOut)
    }

    const body = response.ok ? await readJson(response) : null

    if (body && typeof body.auth === 'string') {
        callback(null, body)

        return
    }

    callback(new RefusedError(channelName, response.status), null)
}

/**
 * pusher-js's `channelAuthorization.customHandler`.
 *
 * @param {{socketId: string, channelName: string}} params
 * @param {(error: Error|null, data: {auth: string, channel_data?: string}|null) => void} callback
 */
export function authorizeChannel(params, callback) {
    if (!String(params?.channelName ?? '').startsWith(LIVE_PREFIX)) {
        authorizeLegacy(params, callback)

        return
    }

    queue.push({ socketId: params.socketId, channelName: params.channelName, callback })

    if (timer === null) {
        timer = setTimeout(flush, AUTH_BATCH_MS)
    }
}

/**
 * Forget anything queued: the store is stopping and disconnecting the socket, whose id the
 * queued names were for. pusher-js clears each channel's pending state on disconnect, and
 * asks again, with the new socket id, when it next connects.
 */
export function dropQueuedAuthorizations() {
    clearTimeout(timer)
    timer = null
    queue = []
}
