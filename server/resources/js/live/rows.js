/**
 * THE READS A LIVE UPDATE MAKES, AND THE ONE RULE THEY KEEP (M11 Part C, design §2.2, §6.2).
 *
 * A `ref` event carries an id and a version, never the record; the screen asks for that one
 * record (or that handful of rows) from an endpoint in its own route group, which re-applies
 * the page's gate and its per-record scope. This file makes those reads:
 *
 *   getJson / postJson   one JSON request, with the live headers, answered as
 *                        {ok, status, body}. A 401 or 419 means the session ended, and
 *                        whoever registered through `onSignedOut` is told. Each is given
 *                        up on after 10 s (`timeoutMs`), or when the caller's `signal`
 *                        aborts, and then answers {ok: false, status: 0} with `timedOut`
 *                        or `aborted` set — so a request on a connection that died silently
 *                        never holds the record, or the catch-up, waiting behind it.
 *   createRefetcher      THE PER-RECORD RULE: at most one request in flight for any one id,
 *                        and a rerun when another event for it lands meanwhile. Ids are
 *                        queued, debounced (100 ms by default) and sent in batches of at most
 *                        200. Because the same id is never asked twice at once, answers for
 *                        it arrive in the order they were asked — so a stale answer can never
 *                        land on top of a newer one.
 */
import { REQUEST_TIMEOUT_MS, requestHeaders, withDeadline } from './authorize.js'

const signedOutListeners = new Set()

/** Called when any live read is answered 401 or 419. Returns the unsubscribe. */
export function onSignedOut(listener) {
    signedOutListeners.add(listener)

    return () => signedOutListeners.delete(listener)
}

const readBody = async (response) => {
    try {
        return await response.json()
    } catch {
        return undefined
    }
}

async function exchange(url, init, signal) {
    let response

    try {
        response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...init, ...(signal ? { signal } : {}) })
    } catch {
        return { ok: false, status: 0, body: undefined }
    }

    if (response.status === 401 || response.status === 419) {
        for (const listener of [...signedOutListeners]) {
            try {
                listener(response.status)
            } catch (error) {
                if (typeof console !== 'undefined') {
                    console.error(error)
                }
            }
        }
    }

    if (!response.ok) {
        return { ok: false, status: response.status, body: undefined }
    }

    const body = await readBody(response)

    return body === undefined ? { ok: false, status: response.status, body } : { ok: true, status: response.status, body }
}

async function request(url, init, { timeoutMs = REQUEST_TIMEOUT_MS, signal = null } = {}) {
    try {
        return await withDeadline((abort) => exchange(url, init, abort), { timeoutMs, signal })
    } catch (error) {
        return { ok: false, status: 0, body: undefined, timedOut: error?.name === 'TimeoutError', aborted: error?.name === 'AbortError' }
    }
}

/** GET `url` as JSON. `options`: `{timeoutMs, signal}`. */
export function getJson(url, options = {}) {
    return request(url, { method: 'GET', headers: requestHeaders() }, options)
}

/** POST `data` to `url` as JSON. `options`: `{timeoutMs, signal}`. */
export function postJson(url, data, options = {}) {
    return request(
        url,
        {
            method: 'POST',
            headers: requestHeaders({ 'Content-Type': 'application/json' }),
            body: JSON.stringify(data),
        },
        options,
    )
}

/**
 * `url` with `query` appended: arrays as `key[]=a&key[]=b` (a key already ending in `[]`
 * keeps it), empty values left out, anything already on the URL kept.
 */
export function withQuery(url, query = {}) {
    const parts = []

    for (const [key, value] of Object.entries(query ?? {})) {
        if (value === undefined || value === null || value === '') {
            continue
        }

        if (Array.isArray(value)) {
            const name = key.endsWith('[]') ? key : `${key}[]`

            for (const item of value) {
                if (item !== undefined && item !== null) {
                    parts.push(`${encodeURIComponent(name)}=${encodeURIComponent(String(item))}`)
                }
            }

            continue
        }

        parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    }

    if (parts.length === 0) {
        return url
    }

    return `${url}${url.includes('?') ? '&' : '?'}${parts.join('&')}`
}

/**
 * The per-record single flight.
 *
 * @param {object} options
 * @param {(ids: string[]) => Promise<any>} options.run        asks the server for these ids
 * @param {(result: any, ids: string[]) => void} options.onResult
 * @param {(error: any, ids: string[]) => void} [options.onError]
 * @param {number} [options.debounceMs=100]
 * @param {number} [options.max=200]
 */
export function createRefetcher({ run, onResult, onError = () => {}, debounceMs = 100, max = 200 }) {
    const queued = new Set()
    const inFlight = new Set()
    const rerun = new Set()
    let timer = null
    let disposed = false

    const arm = () => {
        if (timer === null && !disposed) {
            timer = setTimeout(flush, debounceMs)
        }
    }

    function send(chunk) {
        for (const id of chunk) {
            inFlight.add(id)
        }

        Promise.resolve()
            .then(() => run(chunk))
            .then(
                (result) => {
                    if (!disposed) {
                        onResult(result, chunk)
                    }
                },
                (error) => {
                    if (!disposed) {
                        onError(error, chunk)
                    }
                },
            )
            .finally(() => {
                const again = []

                for (const id of chunk) {
                    inFlight.delete(id)

                    if (rerun.delete(id)) {
                        again.push(id)
                    }
                }

                if (again.length > 0 && !disposed) {
                    for (const id of again) {
                        queued.add(id)
                    }

                    arm()
                }
            })
    }

    function flush() {
        timer = null
        const ids = [...queued]
        queued.clear()

        for (let at = 0; at < ids.length; at += max) {
            send(ids.slice(at, at + max))
        }
    }

    return {
        /** Ask for `id` soon. While it is in flight, the ask becomes one rerun after it. */
        request(id) {
            if (disposed) {
                return
            }

            const key = String(id)

            if (inFlight.has(key)) {
                rerun.add(key)

                return
            }

            queued.add(key)
            arm()
        },

        /** Whether `id` is being asked for right now. */
        isInFlight: (id) => inFlight.has(String(id)),

        dispose() {
            disposed = true
            clearTimeout(timer)
            timer = null
            queued.clear()
            rerun.clear()
        },
    }
}
