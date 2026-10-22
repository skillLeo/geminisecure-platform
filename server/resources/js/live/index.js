/**
 * THE WEB CONSOLES' LIVE CLIENT (M11 Part C, design §6).
 *
 *   startLive(page.props.live)   called by a signed-in shell. Makes the one socket (the first
 *                                time), holds the shell's channels (`live.shell`, computed by
 *                                the server for this viewer: `user.{u}`, the panic feed, the
 *                                bell-feeding module channels, `estate.{t}.console`), and
 *                                from then on listens for `access.changed`. Calling it again
 *                                with new props diff-subscribes: channels the viewer lost are
 *                                let go, new ones held; and, the server having just served a
 *                                signed-in page, a 401 or 419 seen earlier is taken as a
 *                                session renewed, not ended. No props — the sign-in page — and
 *                                it does nothing at all: no socket is made.
 *   stopLive()                   everything let go and the socket disconnected (kept, since
 *                                Echo holds it). Done by itself when the app moves to a page
 *                                with no live prop (signing out), and when a different
 *                                person's props arrive.
 *   useLive, liveList, liveRecord, liveCounter, liveTiles    what a screen binds (§6.2).
 *   whenLiveStarts(callback)     once the socket exists; app.js sets window.Echo with it.
 *   liveFetch(url, init)         fetch, with a fresh X-Live-Origin nonce on any write.
 *
 * THE ONE RELOAD. On `access.changed` on the viewer's own `user.{u}` channel, the page is
 * reloaded — `router.reload({preserveScroll: true})` — because what the viewer may see has
 * changed: a page they may no longer open answers with the 403 page at once, one they may
 * still open comes back with its new flags, nav and channels, and a suspended account is
 * signed out by the server on that same request (§7.2). That is not a data refresh, and it
 * is the only `router.reload` live code makes on its own; every data update is a
 * `replaceProp` or a row, record or tile fetch (§6.5).
 *
 * ORIGIN (§6.1). Every Inertia write carries `X-Live-Origin: <16 random hex>`, a new nonce
 * each time, through `http.onRequest`; the change it causes comes back on the socket with
 * that nonce, and this tab does not flash its own change. A nonce is never a stable id.
 */
import { http, router } from '@inertiajs/vue3'
import { useLive } from '../composables/useLive.js'
import { liveCounter, liveList, liveRecord, liveTiles } from './bindings.js'
import { onceSocketCreated } from './socket.js'
import {
    acquire,
    boot,
    mintOrigin,
    refresh,
    register,
    release,
    resume,
    setBuild,
    startedFor,
    state,
    statusDetail,
    statusFor,
    stop,
} from './store.js'

export { liveCounter, liveList, liveRecord, liveTiles, refresh as refreshLive, state as liveState, statusDetail, statusFor, useLive }

/** Run `callback` once the one socket exists (now, if it does). */
export const whenLiveStarts = onceSocketCreated

let shell = new Set()
let unregisterAccess = null
let removeNavigate = null
let originInstalled = false

const isWrite = (method) => !['GET', 'HEAD', 'OPTIONS'].includes(String(method ?? 'GET').toUpperCase())

function installOrigin() {
    if (originInstalled || typeof http?.onRequest !== 'function') {
        return
    }

    originInstalled = true

    http.onRequest((config) => {
        if (!isWrite(config?.method)) {
            return config
        }

        return { ...config, headers: { ...(config.headers ?? {}), 'X-Live-Origin': mintOrigin() } }
    })
}

/** fetch, with a fresh origin nonce on a write, for a screen that writes without Inertia. */
export function liveFetch(input, init = {}) {
    if (!isWrite(init.method)) {
        return fetch(input, init)
    }

    const headers = init.headers instanceof Headers ? Object.fromEntries(init.headers.entries()) : { ...(init.headers ?? {}) }

    return fetch(input, { ...init, headers: { ...headers, 'X-Live-Origin': mintOrigin() } })
}

let reloading = false
let reloadAgain = false

function reloadForAccess() {
    if (reloading) {
        // A second change while the first reload is out: its answer may predate this one.
        reloadAgain = true

        return
    }

    reloading = true

    router.reload({
        preserveScroll: true,
        onSuccess: (next) => {
            const props = next?.props

            if (props && typeof props === 'object') {
                startLive(props.live)
            }
        },
        onFinish: () => {
            reloading = false

            if (reloadAgain) {
                reloadAgain = false
                reloadForAccess()
            }
        },
    })
}

const accessHandler = {
    page: false,
    signals: false,
    channels: { has: (name) => name.startsWith('user.') },
    accepts: (env) => env.t === 'access.changed',
    apply: () => reloadForAccess(),
    resync() {},
}

const entriesOf = (list) =>
    (Array.isArray(list) ? list : [])
        .map((entry) => (typeof entry === 'string' ? { name: entry, streams: [] } : entry))
        .filter((entry) => entry && typeof entry.name === 'string')

function diffShell(entries, heads) {
    const next = new Set()

    for (const entry of entries) {
        if (next.has(entry.name)) {
            continue
        }

        if (shell.has(entry.name)) {
            // Still held: one reference either way, and the new heads wind it back if need be.
            acquire(entry.name, heads, entry.streams ?? [])
            release(entry.name)
            next.add(entry.name)
        } else if (acquire(entry.name, heads, entry.streams ?? [])) {
            next.add(entry.name)
        }
    }

    for (const name of shell) {
        if (!next.has(name)) {
            release(name)
        }
    }

    shell = next
}

/**
 * Start the live client for a signed-in shell, or bring it in line with new props.
 *
 * @param {{shell?: Array<string|{name: string, streams?: string[]}>, heads?: object, principal?: string, build?: string}|null|undefined} liveProps
 */
export function startLive(liveProps) {
    if (!liveProps || typeof liveProps !== 'object') {
        return null
    }

    const principal = typeof liveProps.principal === 'string' && liveProps.principal !== '' ? liveProps.principal : null

    if (state.started && principal !== null && startedFor() !== null && principal !== startedFor()) {
        stopLive()
    }

    if (!state.started) {
        boot({ principal, build: liveProps.build })
        installOrigin()
        unregisterAccess = register(accessHandler)

        if (typeof router.on === 'function') {
            // Signing out lands on a page with no live prop: nothing live survives it.
            removeNavigate = router.on('navigate', (event) => {
                const props = event?.detail?.page?.props

                if (props && typeof props === 'object' && !props.live) {
                    stopLive()
                }
            })
        }
    } else {
        setBuild(liveProps.build)
        // Served signed in just now: a 401 or 419 seen earlier did not end this session.
        resume()
    }

    diffShell(entriesOf(liveProps.shell), liveProps.heads ?? {})

    return { refresh, stop: stopLive }
}

/** Let everything go and disconnect the socket. */
export function stopLive() {
    for (const name of shell) {
        release(name)
    }

    shell = new Set()
    unregisterAccess?.()
    unregisterAccess = null
    removeNavigate?.()
    removeNavigate = null
    reloading = false
    reloadAgain = false
    stop()
}
