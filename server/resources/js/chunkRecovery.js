/**
 * A PIECE OF THE CONSOLE THAT FAILED TO ARRIVE IS FETCHED AGAIN BY LOADING THE PAGE AFRESH (M11 A3).
 *
 * Since the bundle was split by route (see app.js), a page's code and the socket client
 * are fetched the first time they are needed, not all at once when the console opens. A
 * browser remembers a script that failed to arrive: once one fetch of a page's script has
 * failed — a dropped connection, a flaky mobile hotspot — every later attempt in that tab
 * fails the same way without asking the network again. Measured in Chrome 153 on the
 * estate console: the Residents script was refused once, and a second click on Residents,
 * with the network healthy, failed exactly like the first and sent no request at all.
 * Nothing on screen says so. The link just stops working until somebody presses F5, and
 * the socket client never arriving leaves the panic pop-up on its poll, saying the live
 * connection is down, for as long as the tab stays open.
 *
 * So this presses F5 for them, ONCE: it loads the address being opened as a fresh page,
 * which fetches everything again. A page that is being opened is always a GET address —
 * every form the consoles post is answered with a redirect, and the address Inertia hands
 * the resolver is where that redirect landed — so a fresh load never repeats a form.
 *
 * NOT TWICE. The time of the attempt is kept in this tab's sessionStorage, which survives
 * the reload. A second failure within RECOVERY_WINDOW_MS is left to surface exactly as it
 * would have without any of this, so a file that is really missing cannot make a console
 * reload itself forever. Where the browser refuses sessionStorage there is no way to know
 * a reload was already tried, so none is. And none is tried while the browser says it is
 * offline: a reload then would swap a console that is still polling for the browser's own
 * "no internet" page.
 */

/** A second failure this soon after a fresh load is not retried. */
export const RECOVERY_WINDOW_MS = 30000

const KEY = 'gs:chunk-recovery'

/**
 * Load `url` afresh, unless that was already tried moments ago.
 *
 * @param {string} url  the address being opened
 * @param {{ storage?: () => Storage, location?: { assign(url: string): void }, online?: () => boolean, now?: () => number }} [env]
 * @returns {boolean} whether a fresh load was started
 */
export function recoverFromMissingChunk(url, env = {}) {
    const storage = env.storage ?? (() => window.sessionStorage)
    const location = env.location ?? window.location
    const online = env.online ?? (() => typeof navigator === 'undefined' || navigator.onLine !== false)
    const now = (env.now ?? Date.now)()

    if (!online()) {
        return false
    }

    try {
        const store = storage()
        const last = Number(store.getItem(KEY))

        if (Number.isFinite(last) && last > 0 && now - last < RECOVERY_WINDOW_MS) {
            return false
        }

        store.setItem(KEY, String(now))
    } catch {
        return false
    }

    location.assign(url)

    return true
}

/**
 * The resolver's side: a page whose code fails to load is opened afresh instead.
 *
 * Resolves as `load` does, or — once a fresh load has started — never, so Inertia keeps the
 * page that is on screen (with its progress bar) until the new one replaces it, rather than
 * flashing an error for a failure that is already being put right.
 *
 * @param {() => Promise<unknown>} load  the page's loader from import.meta.glob
 * @param {string} url  the address Inertia is opening
 */
export function loadPage(load, url, env) {
    return load().catch((error) => {
        if (recoverFromMissingChunk(url, env)) {
            return new Promise(() => {})
        }

        throw error
    })
}

/**
 * Everything else that is fetched late — today, only the socket client — reloads the page
 * it failed on, once, in the same way.
 *
 * Vite announces every failed late fetch as `vite:preloadError` on window, just BEFORE the
 * code that asked for it hears of the failure. So the decision waits one task: by then a
 * page's own code has been handed to `loadPage`, which has opened the address being opened
 * and recorded the attempt, and this finds it made. Anything else is the page on screen
 * asking for something, and the page on screen is the one to load afresh. (The initial page
 * is resolved twice, once by createInertiaApp and again by the router as it starts, and the
 * socket client is asked for between the two, so "is a page being resolved?" cannot tell a
 * page's failure from the socket's. Measured, not assumed: the second resolve was still in
 * flight when a refused socket client failed.)
 *
 * The code that asked still hears the failure as before: the socket's users fall back to
 * their poll and say the connection is down, for the moment until the fresh page is drawn.
 */
export function watchLateChunks(env) {
    // `globalThis` is the page's window in a browser, and what a test can stand in for.
    globalThis.addEventListener('vite:preloadError', () => {
        setTimeout(() => recoverFromMissingChunk(globalThis.location.href, env), 0)
    })
}
