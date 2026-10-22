/**
 * A page's code, or the socket client, that fails to arrive — `resources/js/chunkRecovery.js`.
 *
 * Since M11 A3 split the consoles' bundle by route, a page's script is fetched when the page
 * is first opened, and a browser keeps a failed fetch failed for the rest of the tab's life.
 * The console therefore opens the page afresh once, and never twice in a row, never while
 * offline, and never without somewhere to remember that it tried.
 */
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { loadPage, recoverFromMissingChunk, RECOVERY_WINDOW_MS, watchLateChunks } from '../../resources/js/chunkRecovery.js'

const KEY = 'gs:chunk-recovery'

/** A tab: its sessionStorage, where it has been sent, whether it is online, and its clock. */
function tab({ online = true, storageThrows = false, now = 1_000_000 } = {}) {
    const values = new Map()
    const sent = []
    const env = {
        clock: now,
        storage: () => {
            if (storageThrows) {
                throw new Error('SecurityError: sessionStorage is disabled')
            }

            return {
                getItem: (key) => (values.has(key) ? values.get(key) : null),
                setItem: (key, value) => values.set(key, String(value)),
            }
        },
        location: { assign: (url) => sent.push(url) },
        online: () => online,
        now: () => env.clock,
    }

    return { env, sent, values }
}

const settled = (promise) => Promise.race([promise.then(() => 'resolved', () => 'rejected'), new Promise((resolve) => setTimeout(() => resolve('pending'), 20))])

describe('recoverFromMissingChunk', () => {
    it('opens the address afresh the first time, and remembers when', () => {
        const { env, sent, values } = tab()

        assert.equal(recoverFromMissingChunk('/residents', env), true)
        assert.deepEqual(sent, ['/residents'])
        assert.equal(values.get(KEY), String(env.clock))
    })

    it('does not try again inside the window, so a file that is really missing cannot loop', () => {
        const { env, sent } = tab()

        recoverFromMissingChunk('/residents', env)
        env.clock += RECOVERY_WINDOW_MS - 1

        assert.equal(recoverFromMissingChunk('/residents', env), false)
        assert.deepEqual(sent, ['/residents'])
    })

    it('tries again once the window has passed', () => {
        const { env, sent } = tab()

        recoverFromMissingChunk('/residents', env)
        env.clock += RECOVERY_WINDOW_MS

        assert.equal(recoverFromMissingChunk('/finance/arrears', env), true)
        assert.deepEqual(sent, ['/residents', '/finance/arrears'])
    })

    it('does nothing where sessionStorage is refused, because it could not know it had already tried', () => {
        const { env, sent } = tab({ storageThrows: true })

        assert.equal(recoverFromMissingChunk('/residents', env), false)
        assert.deepEqual(sent, [])
    })

    it('does nothing while the browser says it is offline: the page on screen keeps polling', () => {
        const { env, sent, values } = tab({ online: false })

        assert.equal(recoverFromMissingChunk('/residents', env), false)
        assert.deepEqual(sent, [])
        assert.equal(values.size, 0, 'an attempt not made is not remembered')
    })
})

describe('loadPage', () => {
    it('hands Inertia the page as it loaded', async () => {
        const { env, sent } = tab()
        const page = { default: { name: 'Residents' } }

        assert.equal(await loadPage(() => Promise.resolve(page), '/residents', env), page)
        assert.deepEqual(sent, [])
    })

    it('opens the address afresh when the page failed to arrive, and keeps the page on screen until then', async () => {
        const { env, sent } = tab()
        const loading = loadPage(() => Promise.reject(new TypeError('Failed to fetch dynamically imported module')), '/residents?page=2', env)

        assert.equal(await settled(loading), 'pending', 'Inertia is not handed an error for a failure already being put right')
        assert.deepEqual(sent, ['/residents?page=2'])
    })

    it('hands Inertia the original error when a fresh load was tried moments ago', async () => {
        const { env, sent } = tab()
        const error = new TypeError('Failed to fetch dynamically imported module')

        recoverFromMissingChunk('/residents', env)
        env.clock += 1000

        await assert.rejects(loadPage(() => Promise.reject(error), '/residents', env), (thrown) => thrown === error)
        assert.deepEqual(sent, ['/residents'])
    })
})

describe('watchLateChunks', () => {
    /** A window that takes one listener per event, at this address. */
    function onWindow(href, run) {
        const listeners = new Map()
        const saved = { add: globalThis.addEventListener, location: globalThis.location }
        globalThis.addEventListener = (type, handler) => listeners.set(type, handler)
        globalThis.location = { href }

        return run(listeners).finally(() => {
            globalThis.addEventListener = saved.add
            globalThis.location = saved.location
        })
    }

    const aTask = () => new Promise((resolve) => setTimeout(resolve, 5))

    it('opens the page on screen afresh when something it asked for late fails — the socket client', () =>
        onWindow('http://estate.test/security', async (listeners) => {
            const { env, sent } = tab()

            watchLateChunks(env)
            assert.ok(listeners.has('vite:preloadError'), 'it listens for the event Vite fires on a failed late fetch')

            listeners.get('vite:preloadError')({})
            assert.deepEqual(sent, [], 'not before the code that asked has heard of the failure')

            await aTask()
            assert.deepEqual(sent, ['http://estate.test/security'])
        }))

    it('leaves a page\'s own code to loadPage, which opens the address being opened rather than the one on screen', () =>
        onWindow('http://estate.test/', async (listeners) => {
            const { env, sent } = tab()
            const error = new TypeError('Failed to fetch dynamically imported module')

            watchLateChunks(env)

            // As Vite does it: the event first, then the rejection the resolver hears.
            const loading = loadPage(() => {
                listeners.get('vite:preloadError')({ payload: error })

                return Promise.reject(error)
            }, '/residents', env)

            assert.equal(await settled(loading), 'pending')
            await aTask()
            assert.deepEqual(sent, ['/residents'], 'opened once, at Residents, and not again at the dashboard')
        }))
})
